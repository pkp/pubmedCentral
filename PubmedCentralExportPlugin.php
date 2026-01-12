<?php

/**
 * @file PubmedCentralExportPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralExportPlugin
 * @brief Pubmed Central export plugin
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\facades\Repo;
use APP\journal\Journal;
use APP\notification\NotificationManager;
use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use DOMDocument;
use DOMElement;
use DOMImplementation;
use DOMXPath;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\GenreDAO;
use ZipArchive;

class PubmedCentralExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    public const JATS_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    public const JATS_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';
    public const JATS_VERSION = '1.2';

    private Context $context;

    /**
     * @copydoc ImportExportPlugin::display()
     * @throws Exception
     */
    public function display($args, $request): void
    {
        $this->context = $request->getContext();
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter'),
            'issn' => ($this->context->getData('onlineIssn') || $this->context->getData('printIssn')),
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
     */
    private function buildFileName(?string $articleId = null, bool $ts = false, ?string $fileExtension = null): string
    {
        // @todo add setting to select vol/issue naming vs. continuous pub naming?
        $locale = $this->context->getData('primaryLocale');
        $acronym = preg_replace('/[^a-zA-Z0-9]/', '', $this->context->getData('acronym', $locale));
        $timeStamp = date('YmdHis');
        return strtolower(
            $acronym .
            ($articleId ? '-' . $articleId : '') .
            ($ts ? '-' . $timeStamp : '') .
            ($fileExtension ? '.' . $fileExtension : '')
        );
    }

    /**
     * @throws FilesystemException
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
    ) {
        $context = $request->getContext();
        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $resultErrors = [];
            $paths = $this->createZip($objects, $context);
            // @todo move creation of zip into deposit? easier to track objects.
            $result = $this->depositXML($objects, $context, $paths);
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
                foreach ($resultErrors as $errors) {
                    foreach ($errors as $error) {
                        if (!is_array($error) || !count($error) > 0) {
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
            }
            foreach ($paths as $path) {
                unlink($path);
            }
            // Redirect back to the right tab
            $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $path = $this->createZipCollection($objects, $context);
            $fileManager = new FileManager();
            $fileManager->downloadByPath($path, 'application/zip', false, $this->buildFileName(null, true, 'zip'));
            $fileManager->deleteByPath($path);
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
     * @param Submission $object single published submission, publication, issue or galley
     * @param string $filter
     * @param Journal $context
     * @param bool $noValidation If set to true, no XML validation will be done
     * @param null|mixed $outputErrors
     *
     * @return string|array XML document or error message.
     * @throws Exception
     */
    public function exportXML($object, $filter, $context, $noValidation = null, &$outputErrors = null)
    {
        libxml_use_internal_errors(true); // @todo remove?

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();

        // @todo probably need to update for genredao refactor
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($this->context->getId());

        $document = Repo::jats()
            ->getJatsFile($publication->getId(), $submissionId, $genres->toArray());

        // If this setting is enabled, only export user-uploaded JATS files and
        // do not generate our own JATS.
        $jatsImportedOnly = $this->jatsImportedOnly($this->context);

        // Check if the JATS file was found and that it was not generated.
        if (
            !$document ||
            !$document->jatsContent ||
            ($jatsImportedOnly == $document->isDefaultContent)
        ) {
            error_log("No suitable JATS XML file was found for export.");
            $outputErrors[] = __('plugins.importexport.pmc.export.failure.creatingFile');
        }

        $xml = $document->jatsContent;

        // @todo add nlm title to the JATS if set if we are generating it, e.g.
        // <abbrev-journal-title abbrev-type="nlm-ta">Proc Natl Acad Sci USA</abbrev-journal-title>
        // AND
        // <journal-id journal-id-type="pmc">BMJ</journal-id>

        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            if ($outputErrors === null) {
                $this->displayXMLValidationErrors($errors, $xml);
            } else {
                $outputErrors[] = $errors;
            }
        }

        // If the JATS document is system-generated, modify it to ensure it meets PMC requirements.
        if ($document->isDefaultContent) {
            return $this->modifyJats($xml);
        }

        return $xml;
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
        // @todo maybe add last export date?
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
     * Get the NLM title setting value.
     */
    public function nlmTitle(Context $context): string
    {
        return ($this->getSetting($context->getId(), 'nlmTitle'));
    }

    public function getConnectionSettings(Context $context): array
    {
        $connectionSettings = [];
        $connectionSettings['type'] = $this->getSetting($context->getId(), 'type');
        $connectionSettings['host'] = $this->getSetting($context->getId(), 'host');
        $connectionSettings['port'] = $this->getSetting($context->getId(), 'port');
        $connectionSettings['username'] = $this->getSetting($context->getId(), 'username');
        $connectionSettings['password'] = $this->getSetting($context->getId(), 'password');
        $connectionSettings['path'] = $this->getSetting($context->getId(), 'path');
        return $connectionSettings;
    }

    /**
     * Exports a zip file with the selected issues to the configured PMC account.
     *
     * @param array $filenames the filenames and path(s) of the zip file(s)
     * @throws Exception|FilesystemException
     */
    public function depositXml($objects, $context, $filenames): bool|array
    {
        // Get connection settings
        $settings = $this->getConnectionSettings($context);

        // Verify that the credentials are complete
        if (
            empty($settings['type']) ||
            empty($settings['host']) ||
            empty($settings['username']) ||
            empty($settings['password'])
        ) {
            return [['plugins.importexport.pmc.export.failure.settings']];
        }

        // Perform the deposit
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
                'host' => $settings['host'],
                'port' => (int)$settings['port'] ?: 21,
                'username' => $settings['username'],
                'password' => $settings['password'],
                'root' => $settings['path'],
            ]));

        foreach ($filenames as $filename => $filepath) {
            $fs = new Filesystem($adapter);
            $fp = fopen($filepath, 'r');
            $fs->writeStream($filename . '.zip', $fp);
            fclose($fp);
            // @todo check if file is deleted
        }

        return true;
    }

    /**
     * Creates a zip file with the given publications.
     *
     * @return array the paths of the created zip files
     * @throws Exception
     */
    public function createZip(array $objects, Context $context): array
    {
        // @todo replace with filemanager?
        $paths = [];
        try {
            foreach ($objects as $object) {
                $path = tempnam(sys_get_temp_dir(), 'tmp');
                $zip = new ZipArchive();
                if ($zip->open($path, ZipArchive::CREATE) !== true) {
                    error_log('Unable to create PMC ZIP: ' . $zip->getStatusString()); // @todo integrate into error
                    return [['plugins.importexport.pmc.export.failure.creatingFile']];
                }

                $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
                $pubId = $publication->getId();
                $document = $this->exportXML($object, null, $this->context, null, $errors);
                $filename = $this->buildFileName($pubId);
                $articlePathName = $filename . '/' . $this->buildFileName($pubId, false, 'xml');

                if (!$zip->addFromString($articlePathName, $document)) {
                    $errorMessage = 'Unable to add file to PMC ZIP'; //@todo add file info to error message
                    $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                    return [['plugins.importexport.pmc.export.failure.creatingFile', $errorMessage]];
                }

                // add galleys
                $fileService = app()->get('file');
                foreach ($publication->getData('galleys') ?? [] as $galley) {
                    $submissionFileId = $galley->getData('submissionFileId');
                    $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
                    if (!$submissionFile) {
                        continue;
                    }

                    // @todo check for filename in the JATS and add or replace it with the new filename to meet pmc requirements
                    $filePath = $fileService->get($submissionFile->getData('fileId'))->path;
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $galleyFilename = $filename . '/' . $this->buildFileName($pubId, false, $extension);
                    // @todo make sure files meet 2GB max size requirement?

                    if (
                        !$zip->addFromString(
                            $galleyFilename,
                            $fileService->fs->read($filePath)
                        )
                    ) {
                        error_log("Unable to add file {$filePath} to PMC ZIP");
                        $errorMessage = ''; //@todo
                        $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                        return [['plugins.importexport.pmc.export.failure.creatingFile', $errorMessage]];
                    }
                }
                $paths[$this->buildFileName($pubId, true)] = $path;
            }
        } finally {
            if (!$zip->close()) {
                return [['plugins.importexport.pmc.export.failure.creatingFile', $zip->getStatusString()]];
            }
        }
        return $paths;
    }

    /**
     * Creates a zip file of collected publications for download.
     *
     * @throws Exception
     */
    private function createZipCollection(array $objects, Context $context): string|array
    {
        $finalZipPath = tempnam(sys_get_temp_dir(), 'tmp');

        $finalZip = new ZipArchive();
        if ($finalZip->open($finalZipPath, ZipArchive::CREATE) !== true) {
            return [['plugins.importexport.pmc.export.failure.creatingFile', $finalZip->getStatusString()]];
        }

        $paths = $this->createZip($objects, $context);
        foreach ($paths as $filename => $path) {
            if (!$finalZip->addFile($path, $filename . '.zip')) {
                $returnMessage = $finalZip->getStatusString() . '(' . $filename . ')';
                return [[
                    'plugins.importexport.pmc.export.failure.creatingFile',
                    $returnMessage
                ]];
            }
        }
        return $finalZipPath;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
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
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $isRegistered = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $isRegistered;
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
        if ($this->getSetting($context->getId(), 'host') != '') { // @todo add username/pw checks
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Modify the JATS XML to meet PMC requirements.
     */
    public function modifyJats(string $importedJats): string
    {
        //@todo could imported jats be empty?

        // Add the JATS 1.2 DTD declaration.
        $impl = new DOMImplementation();
        $dtd = $impl->createDocumentType(
            'article',
            self::JATS_PUBLIC_ID,
            self::JATS_SYSTEM_ID
        );
        $newJatsDoc = $impl->createDocument(null, '', $dtd);
        $newJatsDoc->encoding = 'UTF-8';

        $dom = new DOMDocument();
        $dom->loadXML($importedJats);
        $xpath = new DOMXPath($dom);
        $articleNode = $xpath->query('//article')->item(0);

        if ($articleNode instanceof DOMElement) {
            $articleNode->setAttribute('dtd-version', self::JATS_VERSION);
            $newJatsDoc->appendChild($newJatsDoc->importNode($articleNode, true));
        }

        return $newJatsDoc->saveXML();
    }
}
