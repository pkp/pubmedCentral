<?php

/**
 * @file PubmedCentralExportPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralExportPlugin
 *
 * @brief PubMed Central export plugin
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\notification\NotificationManager;
use APP\plugins\generic\pubmedCentral\classes\DeliveryHistoryImporter;
use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
use APP\plugins\generic\pubmedCentral\classes\ImportedHistory;
use APP\plugins\generic\pubmedCentral\classes\JatsDocument;
use APP\plugins\generic\pubmedCentral\jobs\PubmedCentralDeliver;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use DOMDocument;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\galley\Galley;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\enums\MediaVariantType;
use PKP\submissionFile\SubmissionFile;
use PKP\xslt\XSLTransformer;
use ZipArchive;

class PubmedCentralExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    /**
     * The JATS 1.2 Journal Publishing DTD bundled with the application, and the
     * identifiers a document uses to name it.
     */
    protected const JATS_12_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    protected const JATS_12_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';
    protected const JATS_12_DTD_PATH = '/dtd/jats/1.2/JATS-journalpublishing1.dtd';

    /**
     * Message keys for conditions that do not stop an export but should still be
     * reported, collected across every object in the export and de-duplicated.
     */
    protected array $validationWarnings = [];

    /**
     * The media files the document being exported refers to, as
     * [packaged file name => path in the file store]. Taken from the prepared document,
     * and read by createZip() to add each file to the package.
     */
    protected array $packagedMedia = [];

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'sftpLibraryMissing' => !class_exists('\League\Flysystem\PhpseclibV3\SftpAdapter'),
        ]);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * Create a filename for files created in the plugin, removing any invalid characters.
     * The naming scheme is determined by the journal's "namingType" setting:
     *  - volumeIssue: nlmTitle-volume-issue-firstPage(.vVersion)(-timestamp)
     *  - articleNumber: nlmTitle-collectionYear-articleNumber(.vVersion)(-timestamp)
     *
     * PMC organizes its archive by volume, so if a journal publishes by article
     * number and does not use volumes, the collection year takes the volume's place. The
     * article number or first page is PMC's "uid", the last part before any timestamp.
     *
     * The version identifies which version of an article a package holds, and separates
     * one version's files from another's: every name here is derived from the publication,
     * so without it two versions of the same article produce the same package name and the
     * same names inside it. It sits inside the uid rather than beside it, because PMC reads
     * the uid as a single part. A publication with no version number is named as before.
     *
     * @see https://pmc.ncbi.nlm.nih.gov/pub/filespec-delivery/
     *
     * @param bool $ts Whether to include a timestamp in the filename.
     * @param string|null $fileExtension The optional file extension to include in the filename.
     * @param int|null $version The version number PMC indexes the publication under, if not its major version.
     */
    protected function buildFileName(
        string $nlmTitle,
        Context $context,
        Submission|Publication|null $object = null,
        bool $ts = false,
        ?string $fileExtension = null,
        ?int $version = null
    ): string {
        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        $parts = [$nlmTitle];

        if ($publication) {
            $namingType = $this->getSetting($context->getId(), 'namingType') ?: 'volumeIssue';
            if ($namingType === 'articleNumber') {
                $parts[] = $this->collectionYear($object);
                $uid = (string) $publication->getData('articleNumber');
            } else {
                $issue = Repo::issue()->get($publication->getIssueId());
                $parts[] = $issue->getVolume();
                $parts[] = $issue->getNumber();
                $uid = (string) $publication->getStartingPage();
            }

            // PMC reads the uid as a single part of the name (jour-vol-uid-timestamp), so an
            // article's version belongs inside it rather than beside it: "82.v2", not "82-v2".
            $version ??= (int) $publication->getData('versionMajor') ?: null;
            if ($uid !== '' && $version) {
                $uid .= '.v' . $version;
            }
            $parts[] = $uid;
        }

        if ($ts) {
            $parts[] = date('YmdHis');
        }

        // PMC file names cannot contain spaces or special characters (such as ?, %, #, /, or :).
        // A dot is not among them, and PMC's own example uids carry one (e.g. "bt.12345").
        $parts = array_map(
            fn ($part) => trim(preg_replace('/[^a-zA-Z0-9.]/', '', (string) $part), '.'),
            $parts
        );

        return strtolower(
            implode('-', array_filter($parts, fn ($part) => $part !== ''))
            . ($fileExtension ? '.' . $fileExtension : '')
        );
    }

    /**
     * The four-digit year of the collection an article belongs to.
     *
     * An article stays in the collection it was first published in, even when a later
     * version is published in another year. The year is taken from the issue, or
     * from the first published version of the submission where there is no issue.
     */
    protected function collectionYear(Submission|Publication|null $object): ?string
    {
        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        if (!$publication) {
            return null;
        }

        $issueId = $publication->getIssueId();
        $issue = $issueId ? Repo::issue()->get($issueId) : null;
        $datePublished = $issue?->getDatePublished();

        if (!$datePublished) {
            $submissionId = (int) $publication->getData('submissionId');
            $submission = $object instanceof Submission
                ? $object
                : ($submissionId ? Repo::submission()->get($submissionId) : null);
            $datePublished = $submission?->getOriginalPublication()?->getData('datePublished')
                ?? $publication->getData('datePublished');
        }

        $timestamp = $datePublished ? strtotime($datePublished) : false;

        return $timestamp ? date('Y', $timestamp) : null;
    }

    /**
     * The names a publication is delivered to PMC under: the package, the stem every file
     * inside it is named from, and the revision of its version it is.
     *
     * An article another platform delivered continues the names it established, without
     * the timestamp it never used, so a package correcting a version indexed in PMC is
     * named as its next revision (".v2.r1"), as PMC asks. Any other article is named from
     * its metadata, and its timestamp tells one delivery of a version from the next.
     *
     * @return array{package: string, stem: string, revision: ?int}
     */
    public function packageNames(Submission|Publication $object, Context $context, ?Submission $submission = null): array
    {
        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        $submission ??= $object instanceof Submission ? $object : $this->submissionOf($publication);
        $version = $this->pmcVersion($publication, $submission);

        if (!$prefix = ImportedHistory::packagePrefix($submission)) {
            $nlmTitle = $this->nlmTitle($context);
            return [
                'package' => $this->buildFileName($nlmTitle, $context, $object, true, null, $version),
                'stem' => $this->buildFileName($nlmTitle, $context, $object, false, null, $version),
                'revision' => null,
            ];
        }

        $name = $prefix . ($version ? '.v' . $version : '');
        $revision = $this->nextRevision($publication, $submission);
        return [
            'package' => $name . ($revision ? '.r' . $revision : ''),
            'stem' => ImportedHistory::fileStem($publication) ?? $name,
            'revision' => $revision,
        ];
    }

    /**
     * The version number a publication is, or will be, indexed under in PMC: its major version.
     *
     * With an imported history, an imported version keeps the number the other platform
     * delivered it as, and a new version is offset from its major version by as many
     * versions as PMC indexes beyond the imported ones (see ImportedHistory::versionOffset).
     */
    protected function pmcVersion(Publication $publication, ?Submission $submission): ?int
    {
        if ($version = ImportedHistory::version($publication)) {
            return $version;
        }

        $versionMajor = (int) $publication->getData('versionMajor');
        return $versionMajor ? $versionMajor + ImportedHistory::versionOffset($submission) : null;
    }

    /**
     * The revision a package of this publication is delivered as: none for a version
     * not yet indexed in PMC, and otherwise one past the highest delivered of that
     * version by any of its publications, since a minor version corrects the version
     * it was made from.
     *
     * For articles with imported history only.
     */
    protected function nextRevision(Publication $publication, ?Submission $submission): ?int
    {
        $version = $this->pmcVersion($publication, $submission);
        $revisions = [];
        foreach ([$publication, ...($submission?->getData('publications') ?? [])] as $candidate) { /** @var Publication $candidate */
            $revision = $this->deliveredRevision($candidate);
            if ($revision !== null && $this->pmcVersion($candidate, $submission) === $version) {
                $revisions[] = $revision;
            }
        }

        return $revisions ? max($revisions) + 1 : null;
    }

    /**
     * The highest revision delivered of a publication, if it has been delivered. One
     * delivered without the revision being recorded, such as one marked registered, is
     * recognized by its deposit status, and was its version's first package.
     *
     * For articles with imported history only.
     */
    protected function deliveredRevision(Publication $publication): ?int
    {
        if (($publication->getData(ImportedHistory::SETTING_REVISION) ?? '') !== '') {
            return (int) $publication->getData(ImportedHistory::SETTING_REVISION);
        }

        return in_array($publication->getData($this->getDepositStatusSettingName()), [
            PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED,
            PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED,
            PubObjectsExportPlugin::EXPORT_STATUS_STALE,
        ]) ? 0 : null;
    }

    /**
     * Before a publication's delivery is queued, record a delivery only its deposit status
     * shows, which a failed delivery would replace.
     *
     * For articles with imported history only.
     */
    public function prepareDelivery(Submission|Publication $object): void
    {
        if (
            $this->continuesHistory($object) &&
            ($object->getData(ImportedHistory::SETTING_REVISION) ?? '') === '' &&
            $this->deliveredRevision($object) === 0
        ) {
            $this->updateObject($object, [ImportedHistory::SETTING_REVISION => '0']);
        }
    }

    /**
     * Record the revision a package was delivered as, so that the next package of the
     * same version is named as the one after it.
     *
     * For articles with imported history only.
     */
    public function recordDelivery(Submission|Publication $object, ?int $revision): void
    {
        if ($this->continuesHistory($object)) {
            $this->updateObject($object, [ImportedHistory::SETTING_REVISION => (string) ($revision ?? 0)]);
        }
    }

    /**
     * Record the revision of a correction delivered by hand. A version not yet in PMC
     * records nothing, since marking it registered may only mean another platform delivered
     * it, and its history can then still be imported.
     *
     * For articles with imported history only.
     */
    public function markRegistered($objects)
    {
        foreach ($objects as $object) {
            $revision = $this->continuesHistory($object) ? $this->nextRevision($object, $this->submissionOf($object)) : null;
            parent::markRegistered([$object]);
            if ($revision) {
                $this->recordDelivery($object, $revision);
            }
        }
    }

    /**
     * Whether a publication's packages continue the names of an imported history, the only
     * names that count revisions.
     */
    protected function continuesHistory(Submission|Publication $object): bool
    {
        return $object instanceof Publication && ImportedHistory::packagePrefix($this->submissionOf($object)) !== null;
    }

    /**
     * Also set the saved values on the object, since a save writes every value the object
     * holds, so a later save from the same object would otherwise undo this one.
     */
    protected function updateObject(Submission|Publication|Issue|Galley $object, ?array $editParams = [])
    {
        parent::updateObject($object, $editParams);
        foreach ($editParams ?? [] as $name => $value) {
            $object->setData($name, $value);
        }
    }

    protected function submissionOf(Publication $publication): ?Submission
    {
        $submissionId = (int) $publication->getData('submissionId');
        return $submissionId ? Repo::submission()->get($submissionId) : null;
    }

    /**
     * The publication's media files, indexed by the file names a document would refer to
     * them by.
     *
     * Where a media file is linked to a high-resolution variant, that variant is what
     * gets packaged: PMC asks for the highest resolution available, and both names
     * resolve to it so the document may refer to either one.
     *
     * @return array<string, string> Paths in the file store, keyed by lowercased file name
     */
    protected function getMediaFiles(Publication $publication): array
    {
        $fileService = app()->get('file');

        // Collected rather than left lazy: the files are walked twice, and re-iterating
        // a lazy collection would run the query again
        $mediaFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$publication->getData('submissionId')])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_MEDIA])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION, [$publication->getId()])
            ->getMany()
            ->collect();

        // Only a file linked to a variant group answers for its counterpart. An unlinked
        // one stands for itself: grouping those together would key them all as one.
        $highResolution = $mediaFiles
            ->filter(fn (SubmissionFile $file) => $file->getData('variantGroupId')
                && $file->getData('variantType') === MediaVariantType::HIGH_RESOLUTION->value)
            ->keyBy(fn (SubmissionFile $file) => $file->getData('variantGroupId'));

        $files = [];
        foreach ($mediaFiles as $mediaFile) { /** @var SubmissionFile $mediaFile */
            $packagedFile = $highResolution->get($mediaFile->getData('variantGroupId')) ?? $mediaFile;
            $path = $fileService->get($packagedFile->getData('fileId'))->path;

            // The name is the only record of the file name the document was written
            // against: what is stored on disk is a generated name.
            foreach ((array) $mediaFile->getData('name') as $name) {
                $files[strtolower($name)] = $path;
            }
        }

        return $files;
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     *
     * @param null|mixed $noValidation
     *
     * @throws Exception
     */
    public function executeExportAction(
        $request,
        $objects,
        $filter,
        $tab,
        $objectsFileNamePart,
        $noValidation = null,
        $shouldRedirect = true
    ): void {
        $context = $request->getContext();
        if ($this->_checkForExportAction(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $resultErrors = [];
            $result = $this->depositXML($objects, $context, null, $noValidation);
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            // send notifications
            if (empty($resultErrors)) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ($resultErrors as $error) {
                    if (!is_array($error) || count($error) === 0) {
                        throw new Exception('Invalid error message');
                    }
                    $this->_sendNotification(
                        $request->getUser(),
                        $error[0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($error[1] ?? null)
                    );
                }
            }

            // Redirect back to the right tab
            $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
        } elseif ($this->_checkForExportAction(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $path = $this->createZipCollection($objects, $context, $noValidation);
            $this->sendValidationWarnings($request);
            if (!empty($path['error'])) {
                $this->_sendNotification(
                    $request->getUser(),
                    $path['error'][0],
                    Notification::NOTIFICATION_TYPE_ERROR,
                    $path['error'][1] ?? null
                );
                $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
            } else {
                $nlmTitle = $this->nlmTitle($context);
                $filename = $this->buildFileName($nlmTitle, $context, null, true, 'zip');
                if (count($objects) == 1) {
                    $object = array_shift($objects);
                    $filename = $this->packageNames($object, $context)['package'] . '.zip';
                }
                $fileManager = new FileManager();
                $fileManager->downloadByPath(
                    $path['path'],
                    'application/zip',
                    false,
                    $filename
                );
                $fileManager->deleteByPath($path['path']);
            }
        } else {
            parent::executeExportAction(
                $request,
                $objects,
                $filter,
                $tab,
                $objectsFileNamePart,
                $noValidation,
                $shouldRedirect
            );
        }
    }

    /**
     * Get the XML for selected objects.
     *
     * @param null|mixed $noValidation
     * @param null|mixed $outputErrors
     * @param null|mixed $genres
     *
     * @return array|string array of error message, or XML document.
     */
    public function exportXML(
        $object,
        $filter,
        $context,
        $noValidation = null,
        &$outputErrors = null,
        ?string $articlePdfFilename = null,
        $genres = null,
        ?string $nlmTitle = null,
        ?string $fileStem = null
    ): array|string {
        // PMC requires a PDF corresponding to each article XML file, so a package
        // without one is never valid. See https://pmc.ncbi.nlm.nih.gov/pub/filespec/
        if ($articlePdfFilename === null) {
            return ['plugins.importexport.pmc.export.failure.missingArticleFile'];
        }

        libxml_use_internal_errors(true);

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();
        if ($genres == null) {
            $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
            $genres = $genreDao->getEnabledByContextId($context->getId());
        }

        $document = Repo::jats()
            ->getJatsFile($publication->getId(), $submissionId, $genres->toArray());

        // Only uploaded JATS is exported for now: generated JATS has no usable body or media.
        if (
            !$document ||
            !$document->jatsContent ||
            $document->isDefaultContent ||
            $document->loadingContentError
        ) {
            return ['plugins.importexport.pmc.export.failure.jatsFileNotFound'];
        }

        $xml = $document->jatsContent;

        // Building the document leaves its own parse errors behind: the JATS Template
        // plugin recovers from malformed author markup itself, and a document that could
        // not be loaded at all is reported above. Cleared so that the DTD validation
        // reports only what it found.
        libxml_clear_errors();

        // Prepare the document to meet PMC requirements, which is also what decides
        // which of the publication's media files are packaged, and under what names.
        // @todo Warn the depositor when a media file is left out because nothing refers to
        // it, or when one marked as the web version is packaged because no high-resolution
        // version is linked to it. Warnings raised during an export only appear once the
        // page is reloaded, as the export responds with the download rather than a page,
        // and a deposit's queued job does not report warnings at all.
        $nlmTitle ??= $this->nlmTitle($context);
        $jatsDocument = new JatsDocument(
            $xml,
            $articlePdfFilename,
            $this->getMediaFiles($publication),
            $fileStem ?? $this->packageNames($object, $context)['stem']
        );
        $returnXml = $jatsDocument->prepare($nlmTitle, $this->collectionYear($object));
        $this->packagedMedia = $jatsDocument->getPackagedMedia();

        if (is_array($returnXml)) {
            return $returnXml;
        }

        // Validate the XML document.
        $dom = new DOMDocument();
        $dom->loadXML($returnXml);
        if (!$noValidation) {
            $validation = $this->validateJats($dom);
            if (is_string($validation)) {
                return ['plugins.importexport.pmc.export.failure.jatsValidation', $validation];
            }
        }
        return $returnXml;
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'pubmedCentral';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getPluginSettingsPrefix()
     */
    public function getObjectAdditionalSettings(): array
    {
        return array_merge(parent::getObjectAdditionalSettings(), [
            $this->getDepositStatusSettingName(),
            ...ImportedHistory::SETTINGS,
        ]);
    }

    /**
     * Get the NLM title setting value, or an empty string when it has not been set.
     */
    public function nlmTitle(Context $context): string
    {
        return $this->getSetting($context->getId(), 'nlmTitle') ?? '';
    }

    /**
     * Get the connection settings values.
     */
    public function getConnectionSettings(Context $context): array
    {
        $connectionSettings = [];
        $connectionSettings['host'] = $this->getSetting($context->getId(), 'host');
        $connectionSettings['port'] = $this->getSetting($context->getId(), 'port');
        $connectionSettings['username'] = $this->getSetting($context->getId(), 'username');
        $connectionSettings['password'] = $this->getSetting($context->getId(), 'password');
        $connectionSettings['path'] = $this->getSetting($context->getId(), 'path');
        return $connectionSettings;
    }

    /**
     * Whether the SFTP account has everything required to deposit to it.
     */
    public function hasCompleteConnectionSettings(int $contextId): bool
    {
        return $this->isAccountComplete([
            'host' => $this->getSetting($contextId, 'host'),
            'username' => $this->getSetting($contextId, 'username'),
            'password' => $this->getSetting($contextId, 'password'),
        ]);
    }

    /**
     * Whether an SFTP account (host/username/password) is fully filled in. The account
     * is optional -- a journal may use the plugin for Export only and deliver packages
     * to PMC by hand -- but if any of the three is set, all three must be.
     */
    public function isAccountComplete(array $account): bool
    {
        return !empty($account['host']) && !empty($account['username']) && !empty($account['password']);
    }

    /**
     * Queue a delivery job per selected object, so that building the package and
     * uploading it cannot block the request that triggered the deposit.
     *
     * @copydoc PubObjectsExportPlugin::depositXML()
     *
     * @param Submission[]|Publication[] $objects
     * @param null|mixed $filename
     *
     * @return bool|array True once the deliveries are queued, or an array of error message details.
     */
    public function depositXML($objects, $context, $filename = null, ?bool $noValidation = null): bool|array
    {
        if (!$this->hasCompleteConnectionSettings($context->getId())) {
            return ['plugins.importexport.pmc.export.failure.settings'];
        }

        foreach ($objects as $object) {
            $this->prepareDelivery($object);
            dispatch(new PubmedCentralDeliver(
                $object->getId(),
                $object instanceof Publication,
                $context->getId(),
                $noValidation
            ));
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);
        }

        return true;
    }

    /**
     * Write a package to the configured PMC SFTP account.
     *
     * @throws Exception If the package cannot be read, or the upload fails.
     */
    public function deliverToEndpoint(string $path, string $filename, Context $context): void
    {
        $settings = $this->getConnectionSettings($context);
        $adapter = new SftpAdapter(
            new SftpConnectionProvider(
                $settings['host'],
                $settings['username'],
                $settings['password'],
                null,
                null,
                (int) $settings['port'] ?: 22
            ),
            $settings['path'] ?: '/'
        );

        $fp = fopen($path, 'r');
        if (!$fp) {
            throw new Exception(
                $this->convertErrorMessage(['plugins.importexport.pmc.export.failure.openingFile', $path])
            );
        }

        try {
            (new Filesystem($adapter))->writeStream($filename, $fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Create a zip file with the given publications.
     *
     * @return array the paths of the created zip files and any error messages.
     */
    public function createZip(Submission|Publication $object, Context $context, ?bool $noValidation = null): array
    {
        $fileService = app()->get('file');
        $nlmTitle = $this->nlmTitle($context);
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($context->getId());

        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        $submission = $object instanceof Submission ? $object : $this->submissionOf($publication);
        $locale = $object->getData('locale');

        if (ImportedHistory::isIncomplete($publication)) {
            return ['error' => ['plugins.importexport.pmc.export.failure.incompleteHistory']];
        }

        // Ensure the metadata required by the configured naming type is present
        if (
            !ImportedHistory::packagePrefix($submission) &&
            ($metadataError = $this->validateNamingMetadata($publication, $context))
        ) {
            return ['error' => $metadataError];
        }
        $names = $this->packageNames($object, $context, $submission);

        $zipPath = tempnam(sys_get_temp_dir(), 'PubmedCentralExport_');
        $zip = new ZipArchive();
        // OVERWRITE avoids the "Using empty file as ZipArchive" deprecation that is
        // raised when opening the empty file tempnam() has already created.
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $error = ['plugins.importexport.pmc.export.failure.creatingFile', $zip->getStatusString()];
            $this->deleteTempFile($zipPath);
            return ['error' => $error];
        }

        // Add a PDF article galley file
        $pdfFilesFound = 0;
        $articlePdfFilename = null;
        foreach ($publication->getData('galleys') ?? [] as $galley) { /** @var Galley $galley */
            // Ignore remote galleys
            if ($galley->getData('urlRemote')) {
                continue;
            }

            // Ignore galleys with locales other than the submission locale
            if ($galley->getData('locale') !== $locale) {
                continue;
            }

            $submissionFileId = $galley->getData('submissionFileId');
            $galleyFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;

            if (!$galleyFile || $galleyFile->getData('mimetype') !== 'application/pdf') {
                continue;
            }

            $genre = $genreDao->getById($galleyFile->getData('genreId'));

            $isPrimaryDocument =
                ($genre->getCategory() == Genre::GENRE_CATEGORY_DOCUMENT) &&
                !$genre->getSupplementary() &&
                !$genre->getDependent();

            if (!$isPrimaryDocument) {
                continue;
            }

            $galleyPath = $fileService->get($galleyFile->getData('fileId'))->path;
            $extension = pathinfo($galleyPath, PATHINFO_EXTENSION);
            $galleyFilename = $names['stem'] . '.' . strtolower($extension);
            $galleyFilePath = $galleyFilename;
            $articlePdfFilename = $galleyFilename;

            if ($pdfFilesFound > 0) {
                return $this->discardZip(
                    $zip,
                    $zipPath,
                    ['plugins.importexport.pmc.export.failure.multipleArticleFiles']
                );
            }

            if (
                !$zip->addFromString(
                    $galleyFilePath,
                    $fileService->fs->read($galleyPath)
                )
            ) {
                return $this->discardZip(
                    $zip,
                    $zipPath,
                    ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]
                );
            }
            $pdfFilesFound++;
        }

        // Add article XML to the zip. Modifying the document is what decides which of
        // the publication's media files are packaged, and under what names, so the
        // media files are added afterwards.
        $document = $this->exportXML(
            $object,
            null,
            $context,
            $noValidation,
            $exportErrors,
            $articlePdfFilename,
            $genres,
            $nlmTitle,
            $names['stem']
        );
        if (is_array($document)) {
            return $this->discardZip($zip, $zipPath, $document);
        } else {
            $articlePathName = $names['stem'] . '.xml';
            if (!$zip->addFromString($articlePathName, $document)) {
                return $this->discardZip(
                    $zip,
                    $zipPath,
                    ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]
                );
            }
            foreach ($this->packagedMedia as $packagedName => $mediaPath) {
                if (!$zip->addFromString($packagedName, $fileService->fs->read($mediaPath))) {
                    return $this->discardZip(
                        $zip,
                        $zipPath,
                        ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]
                    );
                }
            }

            $zipDetails = $names;
            $zipDetails['filename'] = $names['package'];
            $zipDetails['path'] = $zipPath;
            $zip->close();
        }
        return $zipDetails;
    }

    /**
     * Create a zip file of collected objects for download.
     *
     * A single object is downloaded as its own package. Only several objects are
     * gathered into a collection, because PMC takes one article per zip.
     *
     * @return array the path of the created zip file or error details, if applicable.
     */
    private function createZipCollection(array $objects, Context $context, ?bool $noValidation = null): array
    {
        if (count($objects) === 1) {
            $zipPackage = $this->createZip(reset($objects), $context, $noValidation);
            return empty($zipPackage['path'])
                ? ['error' => $zipPackage['error']]
                : ['path' => $zipPackage['path']];
        }

        $finalZipPath = tempnam(sys_get_temp_dir(), 'PubmedCentralExport_');
        $finalZip = new ZipArchive();
        // OVERWRITE avoids the "Using empty file as ZipArchive" deprecation that is
        // raised when opening the empty file tempnam() has already created.
        if ($finalZip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $error = ['plugins.importexport.pmc.export.failure.creatingFile', $finalZip->getStatusString()];
            $this->deleteTempFile($finalZipPath);
            return ['error' => $error];
        }

        $createdPaths = [];
        foreach ($objects as $object) {
            $zipPackage = $this->createZip($object, $context, $noValidation);
            if (empty($zipPackage['path']) || empty($zipPackage['filename'])) {
                $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();
                $versionString = $object instanceof Publication ?
                    $object->getData('versionString') :
                    $object->getCurrentPublication()->getData('versionString');
                $errorDetails = __('plugins.importexport.pmc.export.failure.submissionVersion', [
                    'version' => $versionString,
                    'submissionId' => $submissionId,
                    'error' => $this->convertErrorMessage($zipPackage['error'])
                ]);
                return $this->discardZip(
                    $finalZip,
                    $finalZipPath,
                    ['plugins.importexport.pmc.export.failure.creatingFile', $errorDetails],
                    $createdPaths
                );
            }
            // Track the package before adding it so that it is cleaned up either way.
            $createdPaths[] = $zipPackage['path'];
            if (!$finalZip->addFile($zipPackage['path'], $zipPackage['filename'] . '.zip')) {
                return $this->discardZip(
                    $finalZip,
                    $finalZipPath,
                    ['plugins.importexport.pmc.export.failure.creatingFile', $finalZip->getStatusString()],
                    $createdPaths
                );
            }
        }
        // The added files are only read when the archive is closed, so the per-article
        // packages cannot be removed before this point.
        $finalZip->close();

        foreach ($createdPaths as $createdPath) {
            $this->deleteTempFile($createdPath);
        }
        return ['path' => $finalZipPath];
    }

    /**
     * Discard a partially built zip file and any temporary files collected for it,
     * returning the error for the caller.
     *
     * @param array $collectedPaths Additional temporary files to remove.
     */
    private function discardZip(ZipArchive $zip, string $zipPath, array $error, array $collectedPaths = []): array
    {
        $zip->close();
        $this->deleteTempFile($zipPath);
        foreach ($collectedPaths as $collectedPath) {
            $this->deleteTempFile($collectedPath);
        }
        return ['error' => $error];
    }

    /**
     * Remove a temporary file created during an export.
     */
    public function deleteTempFile(string $path): void
    {
        if (file_exists($path) && !unlink($path)) {
            error_log('Failed to delete temporary export file: ' . $path);
        }
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') == 'settings') {
            $user = $request->getUser();
            $this->addLocaleData();
            $form = new PubmedCentralSettingsForm($this, $request->getContext()->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId());
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     *
     * Imports the history of articles indexed in PMC that another platform delivered.
     *
     * @see DeliveryHistoryImporter
     */
    public function executeCLI($scriptName, &$args)
    {
        $command = array_shift($args);
        $dryRun = in_array('--dry-run', $args);
        [$contextPath, $file] = array_values(array_diff($args, ['--dry-run'])) + [null, null];

        if ($command !== 'importHistory' || !$contextPath || !$file) {
            $this->usage($scriptName);
            return;
        }

        $context = Application::getContextDAO()->getByPath($contextPath);
        if (!$context) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.common.error.unknownContext', ['contextPath' => $contextPath]) . "\n";
            return;
        }

        if ($this->isRelativePath($file)) {
            $file = PWD . '/' . $file;
        }
        if (!is_readable($file)) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.pmc.cli.importHistory.fileNotReadable', ['file' => $file]) . "\n";
            return;
        }

        $result = (new DeliveryHistoryImporter($this, $context))->import($file, $dryRun);
        if ($result['refused']) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __($result['refused']) . "\n";
            return;
        }

        foreach ($result['report'] as $kind => $entries) {
            echo "\n" . __('plugins.importexport.pmc.cli.importHistory.' . $kind, ['count' => count($entries)]) . "\n";
            foreach ($entries as $entry) {
                $line = __('plugins.importexport.pmc.cli.importHistory.entry', [
                    'line' => $entry['line'],
                    'doi' => $entry['doi'],
                    'message' => $entry['key'] ? __($entry['key'], $entry['params']) : '',
                ]);
                echo '  ' . trim(preg_replace('/ {2,}/', ' ', $line)) . "\n";
            }
        }

        echo "\n" . __(
            $dryRun ? 'plugins.importexport.pmc.cli.importHistory.dryRun' : 'plugins.importexport.pmc.cli.importHistory.done',
            ['publications' => $result['publications'], 'submissions' => $result['submissions']]
        ) . "\n";
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
        echo __('plugins.importexport.pmc.cliUsage', [
            'scriptName' => $scriptName,
            'pluginName' => $this->getName(),
            'columns' => implode(',', DeliveryHistoryImporter::COLUMNS),
        ]) . "\n";
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'PubmedCentralExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.pmc.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.pmc.description.short');
    }

    /**
     * @copydoc Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'password',
        ];
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     *
     * Deliveries are queued rather than performed in the request, so the deposit
     * action reports that the objects were submitted, not that they arrived.
     */
    public function getDepositSuccessNotificationMessageKey(): string
    {
        return 'plugins.importexport.pmc.submit.success';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new PubmedCentralInfoSender())
            ->daily()
            ->name(PubmedCentralInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\pubmedCentral\PubmedCentralExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->hasCompleteConnectionSettings($context->getId())) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Resolve the JATS 1.2 publishing DTD, and the modules it includes, to the copy
     * bundled with the application, so that validation does not depend on a request to
     * jats.nlm.nih.gov. Any other document type is fetched as before.
     */
    protected function resolveJatsEntity(?string $publicId, string $systemId): string
    {
        return $this->isBundledJatsIdentifier($publicId, $systemId)
            ? Core::getBaseDir() . self::JATS_12_DTD_PATH
            : $systemId;
    }

    /**
     * Whether a document declares the JATS version bundled with the application, and can
     * therefore be validated against it. Generated JATS always does; uploaded JATS may
     * declare another version, or no document type at all.
     */
    protected function isBundledJatsVersion(DOMDocument $importedJats): bool
    {
        $doctype = $importedJats->doctype;

        return $doctype !== null && $this->isBundledJatsIdentifier($doctype->publicId, $doctype->systemId);
    }

    /**
     * Whether a public or system identifier names the JATS DTD bundled with the application.
     */
    protected function isBundledJatsIdentifier(?string $publicId, ?string $systemId): bool
    {
        return $publicId === self::JATS_12_PUBLIC_ID
            || ($systemId !== null && str_replace('https://', 'http://', $systemId) === self::JATS_12_SYSTEM_ID);
    }

    /**
     * Record a condition that should be reported to the user without stopping the export.
     */
    protected function addValidationWarning(string $messageKey): void
    {
        $this->validationWarnings[$messageKey] = true;
    }

    /**
     * @return string[] Message keys collected so far.
     */
    protected function getValidationWarnings(): array
    {
        return array_keys($this->validationWarnings);
    }

    /**
     * Report everything collected during an export, then clear it.
     */
    protected function sendValidationWarnings($request): void
    {
        foreach ($this->getValidationWarnings() as $messageKey) {
            $this->_sendNotification($request->getUser(), $messageKey, Notification::NOTIFICATION_TYPE_WARNING);
        }
        $this->validationWarnings = [];
    }

    /**
     * Validate a JATS XML document against the DTD and the NLM style checker XSL.
     *
     * @return true|string true if valid, or an error message.
     */
    protected function validateJats(DOMDocument $importedJats): true|string
    {
        libxml_use_internal_errors(true);

        // DTD validation, against the bundled DTD rather than jats.nlm.nih.gov. Only that
        // one version is available, so a document declaring any other is reported to the
        // user and left to the style check alone.
        if (!$this->isBundledJatsVersion($importedJats)) {
            $this->addValidationWarning('plugins.importexport.pmc.export.warning.jatsVersionUnsupported');
        } else {
            libxml_set_external_entity_loader($this->resolveJatsEntity(...));
            try {
                $isValid = $importedJats->validate();
            } finally {
                libxml_set_external_entity_loader(null);
            }

            if (!$isValid) {
                $errors = libxml_get_errors();
                $validationErrors = [];
                foreach ($errors as $error) {
                    $validationErrors[] = "DTD Error [line {$error->line}]: " . trim($error->message);
                }
                libxml_clear_errors();
                return implode(PHP_EOL, $validationErrors);
            }
        }

        // NLM style checker
        $xslFile = $this->getPluginPath() . '/xsl/nlm-stylechecker.xsl';
        $xslTransformer = new XSLTransformer();
        $filteredXml = $xslTransformer->transform(
            $importedJats,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_DOM,
            $xslFile,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_FILE,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_DOM
        );

        if (!$filteredXml) {
            if (!file_exists($xslFile)) {
                $xslError = __('plugins.importexport.pmc.export.failure.xslFileNotFound');
            } else {
                $xslError = __('plugins.importexport.pmc.export.failure.xslTransform');
            }
            return $xslError;
        }

        $styleCheckErrors = [];
        $errors = $filteredXml->getElementsByTagName('error');
        foreach ($errors as $error) {
            $styleCheckErrors[] = 'PMC Style Check Error: ' . $error->textContent;
        }

        return !empty($styleCheckErrors) ? implode(PHP_EOL, $styleCheckErrors) : true;
    }

    /**
     * Validate that the journal and publication have the metadata required to
     * build the filename based on the "namingType" setting.
     */
    protected function validateNamingMetadata(Publication $publication, Context $context): ?array
    {
        $namingType = $this->getSetting($context->getId(), 'namingType') ?: 'volumeIssue';
        $missing = [];

        // Every generated package and file name begins with the NLM title abbreviation.
        if (!$this->nlmTitle($context)) {
            $missing[] = __('plugins.importexport.pmc.settings.form.nlmTitle');
        }

        if ($namingType === 'articleNumber') {
            if (!$publication->getData('articleNumber')) {
                $missing[] = __('submission.articleNumber');
            }
            if (!$this->collectionYear($publication)) {
                $missing[] = __('plugins.importexport.pmc.export.collectionYear');
            }
        } else {
            $issueId = $publication->getIssueId();
            $issue = $issueId ? Repo::issue()->get($issueId) : null;
            if (!$issue) {
                $missing[] = __('issue.issue');
            } else {
                if (!$issue->getVolume()) {
                    $missing[] = __('issue.volume');
                }
                if (!$issue->getNumber()) {
                    $missing[] = __('issue.number');
                }
            }
            if (!$publication->getStartingPage()) {
                $missing[] = __('editor.issues.pages');
            }
        }

        if (!empty($missing)) {
            return ['plugins.importexport.pmc.export.failure.missingMetadata', implode(', ', $missing)];
        }
        return null;
    }

    /**
     * Helper to convert an error array to a string.
     */
    public function convertErrorMessage(array $errorMessage): string
    {
        $message = $errorMessage[0];
        $param = $errorMessage[1] ?? null;
        if (!$param) {
            return __($message);
        } else {
            return __($message, ['param' => $param]);
        }
    }
}
