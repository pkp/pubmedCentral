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

use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
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
     * PMC organizes its archive by volume, so where a journal publishes by article
     * number and carries no volumes, the collection year takes the volume's place. The
     * article number or first page is PMC's "uid", the last part before any timestamp.
     *
     * The version identifies which version of an article a package holds, and separates
     * one version's files from another's: every name here is derived from the publication,
     * so without it two versions of the same article produce the same package name and the
     * same names inside it. It sits inside the uid rather than beside it, because PMC reads
     * the uid as a single part. A publication carrying no version number is named as before.
     *
     * @see https://pmc.ncbi.nlm.nih.gov/pub/filespec-delivery/
     *
     * @param bool $ts Whether to include a timestamp in the filename.
     * @param string|null $fileExtension The optional file extension to include in the filename.
     */
    protected function buildFileName(
        string $nlmTitle,
        Context $context,
        Submission|Publication|null $object = null,
        bool $ts = false,
        ?string $fileExtension = null
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
            if ($uid !== '' && ($version = $publication->getData('versionMajor'))) {
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
                $filename = $this->buildFileName($nlmTitle, $context, null, false, 'zip');
                if (count($objects) == 1) {
                    $object = array_shift($objects);
                    $filename = $this->buildFileName($nlmTitle, $context, $object, true, 'zip');
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
        ?string $nlmTitle = null
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

        // If this setting is enabled, only export user-uploaded JATS files and
        // do not generate our own JATS.
        $jatsImportedOnly = $this->jatsImportedOnly($context);

        // Check if the JATS file was found and that it was not generated if the setting is enabled.
        if (
            !$document ||
            !$document->jatsContent ||
            ($jatsImportedOnly && $document->isDefaultContent) ||
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

        // Prepare the document to meet PMC requirements
        $returnXml = (new JatsDocument($xml, $articlePdfFilename))
            ->prepare($nlmTitle ?? $this->nlmTitle($context), $this->collectionYear($object));

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
            $this->getDepositStatusSettingName()
        ]);
    }

    /**
     * Get the JATS import setting value.
     */
    public function jatsImportedOnly(Context $context): bool
    {
        return ($this->getSetting($context->getId(), 'jatsImported') == 1);
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
        $zipDetails = [];
        $fileService = app()->get('file');
        $nlmTitle = $this->nlmTitle($context);
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($context->getId());

        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        $locale = $object->getData('locale');

        // Ensure the metadata required by the configured naming type is present
        if ($metadataError = $this->validateNamingMetadata($publication, $context)) {
            return ['error' => $metadataError];
        }

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
            $galleyFilename = $this->buildFileName($nlmTitle, $context, $object, false, $extension);
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

        // @todo High-resolution media files are not packaged. PMC requires every file
        // in a package to be referenced from the XML, and nothing references them
        // today: generated JATS contains no <graphic> elements for them, and uploaded
        // JATS references the depositor's own filenames. Reinstating this needs, at a
        // minimum: a per-file component in the packaged name (buildFileName() is
        // derived from the publication, so every media file would otherwise collide
        // and ZipArchive would silently keep only the last), a flat
        // [sourceName => packagedName] map, rewriting //graphic/@xlink:href in
        // JatsDocument, and reporting the unused
        // plugins.importexport.pmc.export.failure.missingMediaFile error when the XML
        // references a file that was not uploaded.

        // Add article XML to the zip
        $document = $this->exportXML(
            $object,
            null,
            $context,
            $noValidation,
            $exportErrors,
            $articlePdfFilename,
            $genres,
            $nlmTitle
        );
        if (is_array($document)) {
            return $this->discardZip($zip, $zipPath, $document);
        } else {
            $articlePathName = $this->buildFileName($nlmTitle, $context, $object, false, 'xml');
            if (!$zip->addFromString($articlePathName, $document)) {
                return $this->discardZip(
                    $zip,
                    $zipPath,
                    ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]
                );
            }
            $zipDetails['filename'] = $this->buildFileName($nlmTitle, $context, $object, true);
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
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
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

        // @todo Remove before release. Style check warnings are logged for development
        // purposes only; they do not block an export and are invisible to users. Decide
        // whether to surface them (as errors, or behind a setting) instead of logging.
        $warnings = $filteredXml->getElementsByTagName('warning');
        foreach ($warnings as $warning) {
            error_log('PMC Style Warning: ' . $warning->textContent);
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
