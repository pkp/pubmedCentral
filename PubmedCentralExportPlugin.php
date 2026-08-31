<?php

/**
 * @file PubmedCentralExportPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralExportPlugin
 * @brief PubMed Central export plugin
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
use APP\plugins\generic\pubmedCentral\jobs\PubmedCentralDeliver;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
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
     * JATS related-article-type values PMC does not accept, mapped to the nearest
     * value its style checker allows.
     */
    protected const PMC_RELATED_ARTICLE_TYPES = [
        'expression-of-concern' => 'object-of-concern',
        'partial-retraction' => 'retracted-article',
    ];

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
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter'),
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
     *  - volumeIssue: nlmTitle-volume-issue-firstPage(-timestamp)
     *  - articleNumber: nlmTitle-collectionYear-articleNumber(-timestamp)
     *
     * PMC organizes its archive by volume, so where a journal publishes by article
     * number and carries no volumes, the collection year takes the volume's place.
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
                $parts[] = $publication->getData('articleNumber');
            } else {
                $issue = Repo::issue()->get($publication->getIssueId());
                $parts[] = $issue->getVolume();
                $parts[] = $issue->getNumber();
                $parts[] = $publication->getStartingPage();
            }
        }

        if ($ts) {
            $parts[] = date('YmdHis');
        }

        // PMC file names cannot contain spaces or special characters (such as ?, %, #, /, or :)
        $parts = array_map(
            fn ($part) => preg_replace('/[^a-zA-Z0-9]/', '', (string) $part),
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
     * version is published in another year, so the year is taken from the issue, and
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
        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            $libXmlErrors = implode(PHP_EOL, $errors);
            return ['plugins.importexport.pmc.export.failure.jatsModification', $libXmlErrors];
        }
        libxml_clear_errors();

        // If the JATS document is system-generated, modify it to ensure it meets PMC requirements.
        if ($document->isDefaultContent) {
            $returnXml = $this->modifyDefaultJats(
                $xml,
                $articlePdfFilename,
                $nlmTitle,
                $this->collectionYear($object)
            );
        } else {
            $returnXml = $this->modifyCustomJats($xml, $articlePdfFilename);
        }

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
     * Whether the FTP account has everything required to deposit to it.
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
     * Whether an FTP account (host/username/password) is fully filled in. The account
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
     * Write a package to the configured PMC FTP account.
     *
     * @throws Exception If the package cannot be read, or the upload fails.
     */
    public function deliverToEndpoint(string $path, string $filename, Context $context): void
    {
        $settings = $this->getConnectionSettings($context);
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
            'host' => $settings['host'],
            'port' => (int) $settings['port'] ?: 21,
            'username' => $settings['username'],
            'password' => $settings['password'],
            'root' => $settings['path'],
        ]));

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

        $filename = $this->buildFileName($nlmTitle, $context, $object);

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

            // @todo make sure files meet 2GB max size requirement?
            $galleyPath = $fileService->get($galleyFile->getData('fileId'))->path;
            $extension = pathinfo($galleyPath, PATHINFO_EXTENSION);
            $galleyFilename = $this->buildFileName($nlmTitle, $context, $object, false, $extension);
            $galleyFilePath = $filename . '/' . $galleyFilename;
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
        // modifyCustomJats(), and reporting the unused
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
            $articlePathName = $filename . '/' . $this->buildFileName($nlmTitle, $context, $object, false, 'xml');
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
     * Modify the JATS XML to meet PMC requirements.
     *
     * @todo High-resolution media files are not supported for system-generated JATS.
     * The generated document contains no <graphic> elements to point at them, and
     * there is no way to determine where in the body each image belongs. Only
     * uploaded JATS carries its own <graphic> elements -- see modifyCustomJats() and
     * the note in createZip(). Revisit if generated JATS gains figure support.
     */
    protected function modifyDefaultJats(
        string $importedJats,
        string $articlePdfFilename,
        string $nlmTitle,
        ?string $collectionYear = null
    ): string|array {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!$dom->loadXML($importedJats)) {
            return ['plugins.importexport.pmc.export.failure.loadJats'];
        }

        $xpath = new DOMXPath($dom);

        if (!($journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0))) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta'];
        }

        // Add Journal identifier for pmc and remove unsupported journal identifiers
        $journalIdNode = $dom->createElement('journal-id', $nlmTitle);
        $journalIdNode->setAttribute('journal-id-type', 'pmc');
        if (!$journalMetaChildElement = $xpath->query('*[1]', $journalMetaNode)->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta[1]'];
        }
        $journalMetaNode->insertBefore($journalIdNode, $journalMetaChildElement);
        $journalIdNodes = $xpath->query(
            "journal-id[@journal-id-type='ojs' or @journal-id-type='publisher']",
            $journalMetaNode
        );
        foreach ($journalIdNodes as $node) { /** @var DOMNode $node **/
            $node->parentNode->removeChild($node);
        }

        // Add NLM title as the abbreviated journal title
        $nlmJournalTitleNode = $dom->createElement('abbrev-journal-title');
        $nlmJournalTitleNode->setAttribute('abbrev-type', 'nlm-ta');
        $nlmJournalTitleNode->appendChild($dom->createTextNode($nlmTitle));
        if (!$journalTitleNode = $xpath->query("journal-title-group", $journalMetaNode)->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-title-group'];
        }
        $journalTitleNode->appendChild($nlmJournalTitleNode);

        // remove contrib in journal-meta if not an editor (only author or editor type is allowed)
        $journalContribNodes = $xpath->query(
            "contrib-group/contrib[not(@contrib-type='editor')]",
            $journalMetaNode
        );
        foreach ($journalContribNodes as $node) { /** @var DOMNode $node **/
            $node->parentNode->removeChild($node);
        }

        // If the journal-meta contrib-group is now empty, remove it
        foreach ($xpath->query('//contrib-group[not(*) and not(normalize-space())]', $journalMetaNode) as $node) {
            $node->parentNode->removeChild($node);
        }

        if (!$articleMetaNode = $xpath->query("//article/front/article-meta")->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'];
        }

        // The style check only accepts author or editor, so drop every other contributor
        // type the contributor roles can produce (translator, reviewer, reader, other...).
        $articleContribNodes = $xpath->query(
            "contrib-group/contrib[not(@contrib-type='author' or @contrib-type='editor')]",
            $articleMetaNode
        );
        foreach ($articleContribNodes as $node) { /** @var DOMNode $node **/
            $node->parentNode->removeChild($node);
        }

        // If the article-meta contrib-group is now empty, remove it
        foreach ($xpath->query('//contrib-group[not(*) and not(normalize-space())]', $articleMetaNode) as $node) {
            $node->parentNode->removeChild($node);
        }

        // The jatsTemplate plugin wraps every personal name in name-alternatives, holding the
        // structured <name> and, where one is recorded, a display <string-name>. PMC rejects
        // string-name, and name-alternatives needs more than one child, so drop the display
        // name and unwrap the structured one. @specific-use only distinguished the two, so it
        // goes with the wrapper. Queried document-wide to cover contributors in sub-article
        // front-stubs as well as article-meta.
        foreach ($xpath->query('//contrib-group/contrib/name-alternatives') as $node) { /** @var DOMNode $node **/
            foreach ($xpath->query('./string-name', $node) as $stringNameNode) {
                $node->removeChild($stringNameNode);
            }
            $names = $xpath->query('./name', $node);
            if ($names->length !== 1) {
                continue;
            }
            $nameNode = $names->item(0); /** @var DOMElement $nameNode */
            $nameNode->removeAttribute('specific-use');
            $node->parentNode->insertBefore($nameNode, $node);
            $node->parentNode->removeChild($node);
        }

        // PMC requires the electronic publication date to be accompanied by the date of
        // the collection the article belongs to, and uses it to organize the archive.
        // Only the year is needed: PMC collects by year where a journal has no volumes.
        if ($collectionYear) {
            $pubDateNode = $xpath->query(
                "pub-date[@date-type='pub' and @publication-format='electronic']",
                $articleMetaNode
            )->item(0);
            if ($pubDateNode) {
                $collectionDateNode = $dom->createElement('pub-date');
                $collectionDateNode->setAttribute('date-type', 'collection');
                $collectionDateNode->setAttribute('publication-format', 'electronic');
                $collectionDateNode->appendChild($dom->createElement('year', $collectionYear));
                // Kept alongside the publication date, before the volume and page elements
                // the JATS content model expects to follow it.
                $articleMetaNode->insertBefore($collectionDateNode, $pubDateNode->nextSibling);
            }
        }

        // The jatsTemplate plugin points supplementary-material at the galley's OJS download
        // URL, but createZip() packages only the article PDF. PMC requires the target to be a
        // packaged file, and the style check rejects an @xlink:href with no file extension
        // outright, so drop these rather than ship a reference that cannot resolve.
        // @todo Point these at packaged files once supplementary files are packaged -- see
        // the note in createZip().
        foreach ($xpath->query('//supplementary-material') as $node) { /** @var DOMNode $node **/
            $node->parentNode->removeChild($node);
        }

        // Replace any self-uri PDF links with one pointing at the PDF packaged
        // alongside this XML. createZip() cannot produce a package without one.
        $selfUriPdfNodes = $xpath->query(
            "self-uri[@content-type='pdf' or @content-type='application/pdf']",
            $articleMetaNode
        );
        foreach ($selfUriPdfNodes as $selfUriPdfNode) {
            $selfUriPdfNode->parentNode->removeChild($selfUriPdfNode);
        }

        $linkElement = $dom->createElement('self-uri');
        $linkElement->setAttribute('content-type', 'pdf');
        $linkElement->setAttribute('xlink:href', $articlePdfFilename);
        $uriNode = $xpath->query("self-uri", $articleMetaNode)->item(0);
        if ($uriNode) {
            $uriNode->parentNode->insertBefore($linkElement, $uriNode);
        } else {
            if (!$abstractNode = $xpath->query("abstract", $articleMetaNode)->item(0)) {
                return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'abstract'];
            }
            $articleMetaNode->insertBefore($linkElement, $abstractNode);
        }

        // PMC accepts a narrower related-article-type vocabulary than JATS, so map the
        // values it rejects onto its nearest supported ones.
        foreach (self::PMC_RELATED_ARTICLE_TYPES as $jatsType => $pmcType) {
            foreach ($xpath->query("//related-article[@related-article-type='{$jatsType}']") as $node) {
                $node->setAttribute('related-article-type', $pmcType);
            }
        }

        // Remove any empty <p> tags, e.g. from line breaks
        foreach ($xpath->query('//p[not(*) and not(normalize-space())]') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Add the article-type to the article element
        $articleNode = $dom->documentElement;
        if ($articleNode instanceof DOMElement) {
            $articleNode->setAttribute('article-type', 'research-article');
        }

        return $dom->saveXML();
    }

    /**
     * Modify an uploaded JATS document to meet PMC requirements.
     *
     * @todo Uploaded JATS may reference figures via <graphic xlink:href="...">, but
     * the referenced files are not packaged -- see the note in createZip(). Once
     * media packaging is reinstated, those hrefs need remapping to the packaged
     * filenames here.
     */
    protected function modifyCustomJats(
        string $importedJats,
        string $articlePdfFilename
    ): string|array {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!$dom->loadXML($importedJats)) {
            return ['plugins.importexport.pmc.export.failure.loadJats'];
        }

        $xpath = new DOMXPath($dom);

        if (!$articleMetaNode = $xpath->query("//article/front/article-meta")->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'];
        }

        // Replace any self-uri PDF links with one pointing at the PDF packaged
        // alongside this XML. createZip() cannot produce a package without one.
        $selfUriPdfNodes = $xpath->query(
            "self-uri[@content-type='pdf' or @content-type='application/pdf']",
            $articleMetaNode
        );
        foreach ($selfUriPdfNodes as $selfUriPdfNode) {
            $selfUriPdfNode->parentNode->removeChild($selfUriPdfNode);
        }

        $linkElement = $dom->createElement('self-uri');
        $linkElement->setAttribute('content-type', 'pdf');
        $linkElement->setAttribute('xlink:href', $articlePdfFilename);
        $uriNode = $xpath->query("self-uri", $articleMetaNode)->item(0);
        if ($uriNode) {
            $uriNode->parentNode->insertBefore($linkElement, $uriNode);
        } else {
            if (!$abstractNode = $xpath->query("abstract", $articleMetaNode)->item(0)) {
                return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'abstract'];
            }
            $articleMetaNode->insertBefore($linkElement, $abstractNode);
        }

        // Remove any empty p tags, e.g. from line breaks
        foreach ($xpath->query('//p[not(*) and not(normalize-space())]') as $node) {
            $node->parentNode->removeChild($node);
        }

        return $dom->saveXML();
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
                    $validationErrors[] = "DTD Error [line $error->line]: " . trim($error->message);
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
