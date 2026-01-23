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
use DOMNode;
use DOMXPath;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionException;
use League\Flysystem\Ftp\FtpConnectionOptions;
use PKP\context\Context;
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
    public const JATS_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    public const JATS_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';
    public const JATS_VERSION = '1.2';

    /**
     * @copydoc ImportExportPlugin::display()
     * @throws Exception
     */
    public function display($args, $request): void
    {
        $context = $request->getContext();
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
     *
     * @param string|null $articleId The article ID to include in the filename.
     * @param bool $ts Whether to include a timestamp in the filename.
     * @param string|null $fileExtension The optional file extension to include in the filename.
     */
    private function buildFileName(Context $context, ?string $articleId = null, bool $ts = false, ?string $fileExtension = null): string
    {
        // @todo add setting to select vol/issue naming vs. continuous pub naming?
        $nlmTitle = preg_replace('/[^a-zA-Z0-9]/', '', $this->nlmTitle($context));
        $timeStamp = date('YmdHis');
        return strtolower(
            $nlmTitle .
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
            $result = $this->depositXML($objects, $context);
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
            // Redirect back to the right tab
            $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $path = $this->createZipCollection($objects, $context);
            $fileManager = new FileManager();
            $fileManager->downloadByPath(
                $path,
                'application/zip',
                false,
                $this->buildFileName($context, true, false, 'zip')
            );
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
    public function exportXML(
        $object,
        $filter,
        $context,
        $noValidation = null,
        &$outputErrors = null,
        ?array $articleFilenames = null
    ) {
        libxml_use_internal_errors(true); // @todo remove?

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();

        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($context->getId());

        $document = Repo::jats()
            ->getJatsFile($publication->getId(), $submissionId, $genres->toArray());

        // If this setting is enabled, only export user-uploaded JATS files and
        // do not generate our own JATS.
        $jatsImportedOnly = $this->jatsImportedOnly($context);

        // Check if the JATS file was found and that it was not generated.
        if (
            !$document ||
            !$document->jatsContent ||
            ($jatsImportedOnly && $document->isDefaultContent) ||
            $document->loadingContentError
        ) {
            $outputErrors[] = __('plugins.importexport.pmc.export.failure.jatsFileNotFound');
        }

        $xml = $document->jatsContent;

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
            $modifiedXml = $this->modifyJats($xml, $context, $object, $submissionId, $articleFilenames);
            if (is_array($modifiedXml)) {
                $errorMessage = implode(PHP_EOL, $modifiedXml); // @todo add error message key
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                // @todo handle error properly
                throw new Exception('JATS errors encountered while modifying XML');
            }
            $returnXml = $modifiedXml;
        } else {
            $returnXml = $xml;
        }

        // Validate the XML document.
        $dom = new DOMDocument();
        $dom->loadXML($returnXml);
        $validation = $this->validateJats($dom);
        if (is_array($validation)) {
            $validationMessage = implode(PHP_EOL, $validation);
            $errorMessage = __('plugins.importexport.pmc.export.failure.jatsValidation', ['param' => $validationMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            error_log('JATS Validation Failed: ' . $errorMessage);
            return [['plugins.importexport.pmc.export.failure.jatsValidation', ['param' => $validationMessage]]];
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
        // @todo store last export date?
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
        $connectionSettings['host'] = $this->getSetting($context->getId(), 'host');
        $connectionSettings['port'] = $this->getSetting($context->getId(), 'port');
        $connectionSettings['username'] = $this->getSetting($context->getId(), 'username');
        $connectionSettings['password'] = $this->getSetting($context->getId(), 'password');
        $connectionSettings['path'] = $this->getSetting($context->getId(), 'path');
        return $connectionSettings;
    }

    /**
     * Exports a zip file with the selected articles to the configured PMC account.
     *
     * @throws Exception|FilesystemException
     */
    public function depositXML($objects, $context, $filename = null): bool|array
    {
        // Get connection settings
        $settings = $this->getConnectionSettings($context);

        // Verify that the credentials are complete
        if (
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
        $fs = new Filesystem($adapter); // @todo test this, was in loop below before

        $packagedObjects = $this->createZip($objects, $context);
        // @todo catch exception e.g. for authentication errors
        foreach ($packagedObjects as $pubId => $objectDetails) {
            error_log(print_r($objectDetails, true));
            $fp = fopen($objectDetails['path'], 'r');
            if ($fp == false) {
                // @todo returns false on error
            }
            try {
                $fs->writeStream($objectDetails['filename'] . '.zip', $fp);
            } catch (FtpConnectionException $e) {
                error_log('caught ftp connection exception: ' . $e->getMessage());
                throw $e;
            }

            // @todo need to update status of object for success or errors above, e.g.
            // $object->setData($this->getDepositStatusSettingName(), PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
            // $this->updateObject($object);
            if (fclose($fp) == false) {
                // @todo returns false on error
            }
            if (unlink($objectDetails['path']) == false) {
                // @todo returns false on error
            }
        }

        return true;
    }

    /**
     * Creates a zip file with the given publications.
     *
     * @return array|false the paths of the created zip files
     * @throws Exception
     */
    public function createZip(array $objects, Context $context, $isDownload = false): array|false
    {
        $paths = [];
        // @todo check if objects is empty
        foreach ($objects as $object) {
            $path = tempnam(sys_get_temp_dir(), 'tmp');
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE) !== true) {
                if (!$isDownload) {
                    $errorMessage = __('plugins.importexport.pmc.export.failure.creatingFile', [
                        'param' => $zip->getStatusString()
                    ]);
                    $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                }
                return false; // @todo return array that start with error key or something similar?
                //return [['plugins.importexport.pmc.export.failure.creatingFile']];
            }

            $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
            $pubId = $publication->getId();
            $filename = $this->buildFileName($context, $pubId);

            // Add an article galley file
            $fileService = app()->get('file');
            $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
            $articleFilenames = [];
            foreach ($publication->getData('galleys') ?? [] as $galley) { /** @var Galley $galley */
                // Ignore remote galleys
                if ($galley->getData('urlRemote')) {
                    continue;
                }

                $submissionFileId = $galley->getData('submissionFileId');
                $galleyFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
                if (!$galleyFile) {
                    continue;
                }
                $genre = $genreDao->getById($galleyFile->getData('genreId'));
                if (
                    $genre->getCategory() == Genre::GENRE_CATEGORY_DOCUMENT &&
                    !$genre->getSupplementary() &&
                    !$genre->getDependent()
                ) {
                    $filePath = $fileService->get($galleyFile->getData('fileId'))->path;
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $galleyFilename = $filename . '/' . $this->buildFileName($context, $pubId, false, $extension);
                    $articleFilenames[] = $this->buildFileName($context, $pubId, false, $extension);
                    // @todo make sure files meet 2GB max size requirement?

                    if (
                        !$zip->addFromString(
                            $galleyFilename,
                            $fileService->fs->read($filePath)
                        )
                    ) {
                        if (!$isDownload) {
                            $errorMessage = __('plugins.importexport.pmc.export.failure.addingFile', [
                                'filePath' => $filePath,
                                'param' => $zip->getStatusString()
                            ]);
                            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                        }
                        return false;
                    }
                }
            }

            if (count($articleFilenames) > 1) {
                if (!$isDownload) {
                    $this->updateStatus(
                        $object,
                        PubObjectsExportPlugin::EXPORT_STATUS_ERROR,
                        __('plugins.importexport.pmc.export.failure.multipleArticleFiles')
                    );
                }
                return false;
            }
            $paths[$pubId]['filename'] = $this->buildFileName($context, $pubId, true);
            $paths[$pubId]['path'] = $path;

            // Add article XML to zip
            $errors = [];
            $document = $this->exportXML($object, null, $context, null, $errors, $articleFilenames);
            if ($errors && !is_string($document)) {
                error_log(print_r($errors, true)); // @todo
                return false;
            }

            $articlePathName = $filename . '/' . $this->buildFileName($context, $pubId, false, 'xml');

            if (!$zip->addFromString($articlePathName, $document)) {
                if (!$isDownload) {
                    $errorMessage = __('plugins.importexport.pmc.export.failure.addingFile', [
                        'filePath' => $articlePathName,
                        'param' => $zip->getStatusString()
                    ]);
                    $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                }
                return false;
            }
            if (!$zip->close()) {
                return false;
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

        $paths = $this->createZip($objects, $context, true);
        foreach ($paths as $key => $filePath) {
            if (!$finalZip->addFile($filePath['path'], $filePath['filename'] . '.zip')) {
                $returnMessage = $finalZip->getStatusString() . '(' . $filePath['filename'] . ')';
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
     * @copydoc Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'password',
        ];
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
        if (
            !empty($this->getSetting($context->getId(), 'host')) &&
            !empty($this->getSetting($context->getId(), 'username')) &&
            !empty($this->getSetting($context->getId(), 'password'))
        ) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Modify the JATS XML to meet PMC requirements.
     * @throws Exception
     */
    public function modifyJats(
        string $importedJats,
        Context $context,
        Submission|Publication $object,
        int $submissionId,
        ?array $articleFilenames
    ): string|array {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($importedJats);
        $xpath = new DOMXPath($dom);

        // @todo check if this is caught later in validation
        $bodyNode = $xpath->query('//article/body')->item(0);
        if (!$bodyNode) {
            return [['plugins.importexport.pmc.export.failure.jatsValidationBody']];
        }

        // Add Journal identifier for pmc and remove unsupported journal identifiers
        $journalId = $this->nlmTitle($context);
        $journalIdNode = $dom->createElement('journal-id', $journalId);
        $journalIdNode->setAttribute('journal-id-type', 'pmc');
        $journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
        $journalMetaChildElement = $xpath->query('//article/front/journal-meta/*[1]')->item(0);
        $journalMetaNode->insertBefore($journalIdNode, $journalMetaChildElement);
        $journalIdNodes = $xpath->query(
            "//article/front/journal-meta/journal-id[@journal-id-type='ojs' or @journal-id-type='publisher']"
        );
        foreach ($journalIdNodes as $node) { /** @var $node DOMNode **/
            $node->parentNode->removeChild($node);
        }

        // Add NLM title as the abbreviated journal title
        $nlmJournalTitleNode = $dom->createElement('abbrev-journal-title');
        $nlmJournalTitleNode->setAttribute('abbrev-type', 'nlm-ta');
        $nlmJournalTitleNode->appendChild($dom->createTextNode($journalId));
        $journalTitleNode =  $xpath->query("//article/front/journal-meta/journal-title-group")->item(0);
        $journalTitleNode->appendChild($nlmJournalTitleNode);

        // remove contrib in journal-meta if not an editor (only author or editor type is allowed)
        $journalContribNodes = $xpath->query(
            "//article/front/journal-meta/contrib-group/contrib[not(@contrib-type='editor')]"
        );
        foreach ($journalContribNodes as $node) { /** @var $node DOMNode **/
            $node->parentNode->removeChild($node);
        }

        // set author contrib-type on contrib nodes
        $articleContribNodes = $xpath->query("//article/front/article-meta/contrib-group/contrib");
        foreach ($articleContribNodes as $node) {
            $node->setAttribute('contrib-type', 'author');
        }

        // change pub-date publication-format from epub to electronic
        $pubDateNode = $xpath->query("//article/front/article-meta/pub-date[@publication-format='epub']")->item(0);
        $pubDateNode?->setAttribute('publication-format', 'electronic');

        // move name out of name-alternatives if only one name is present for a contrib
        // as name-alternatives must contain more than 1 child element for PMC
        $nameAlternativesNodes = $xpath->query("//article/front/article-meta/contrib-group/contrib/name-alternatives");
        foreach ($nameAlternativesNodes as $node) { /** @var $node DOMNode **/
            $names = $xpath->query('./name', $node);
            if ($names->length > 1) {
                continue;
            }
            if ($names->length === 1) {
                $stringName = $xpath->query('./string-name', $node);
                if ($stringName->length === 1) {
                    $stringNameNode = $stringName->item(0);
                    $stringNameNode->setAttribute('name-style', 'western');
                    $node->parentNode->insertBefore($stringNameNode, $node);
                }

                $nameNode = $names->item(0);
                // Move the name node before the name-alternatives node
                $node->parentNode->insertBefore($nameNode, $node);
                // Remove the now-redundant name-alternatives node
                $node->parentNode->removeChild($node);
            }
        }

        // generate an elocation id from submission id for now as either elocation id or fpage are required by PMC
        // @todo consider in relation to https://github.com/pkp/pkp-lib/issues/4695 and the change in number
        // from previous ORE deposits to PMC under f1000
        $fpageNode = $xpath->query("//article/front/article-meta/fpage")->item(0);
        if (!$fpageNode) {
            $elocationNode = $dom->createElement('elocation-id');
            $elocationNode->appendChild($dom->createTextNode($submissionId));
            $articleMetaNode = $xpath->query("//article/front/article-meta")->item(0);
            $pubHistoryNode = $xpath->query("//article/front/article-meta/pub-history")->item(0);
            if ($pubHistoryNode) {
                $articleMetaNode->insertBefore($elocationNode, $pubHistoryNode);
            } else {
                $permissionsNode = $xpath->query("//article/front/article-meta/permissions")->item(0);
                $articleMetaNode->insertBefore($elocationNode, $permissionsNode);
            }
        }

        foreach ($articleFilenames ?? [] as $filename) {
            $linkElement = $dom->createElement('self-uri');
            if (str_contains($filename, '.pdf')) {
                $linkElement->setAttribute('content-type', 'pdf');
            } elseif (str_contains($filename, '.xml')) {
                $linkElement->setAttribute('content-type', 'xml');
            }
            $linkElement->setAttribute('xlink:href', $filename);
            $uriNode = $xpath->query("//article/front/article-meta/self-uri")->item(0);
            if ($uriNode) {
                $uriNode->parentNode->insertBefore($linkElement, $uriNode);
            } else {
                $abstractNode = $xpath->query("//article/front/article-meta/abstract")->item(0);
                $articleMetaNode = $xpath->query("//article/front/article-meta")->item(0);
                $articleMetaNode->insertBefore($linkElement, $abstractNode);
            }
        }

        // Add the JATS 1.2 DTD declaration
        // @todo move to JATS plugin?
        $impl = new DOMImplementation();
        $dtd = $impl->createDocumentType(
            'article',
            self::JATS_PUBLIC_ID,
            self::JATS_SYSTEM_ID
        );
        $newJatsDoc = $impl->createDocument(null, '', $dtd);
        $newJatsDoc->encoding = 'UTF-8';

        $articleNode = $dom->documentElement;
        if ($articleNode instanceof DOMElement) {
            $articleNode->setAttribute('dtd-version', self::JATS_VERSION);
            $articleNode->setAttribute('article-type', 'research-article');
            $newJatsDoc->appendChild($newJatsDoc->importNode($articleNode, true));
        }
        return $newJatsDoc->saveXML();
    }

    /**
     * Validate a JATS XML document against the DTD and the NLM style checker XSL.
     */
    public function validateJats(DOMDocument $importedJats): bool|array
    {
        // DTD Validation
        $xmlString = $importedJats->saveXML();
        $validatingDom = new DOMDocument();

        // Enable external entity loading and validation
        $validatingDom->resolveExternals = true;
        $validatingDom->validateOnParse = true;

        libxml_use_internal_errors(true);
        $validatingDom->loadXML($xmlString);

        if (!$validatingDom->validate()) {
            $errors = libxml_get_errors();
            $errorMsgs = [];
            foreach ($errors as $error) {
                $errorMsgs[] = "DTD Error [line $error->line]: " . trim($error->message);
            }
            libxml_clear_errors();
            error_log(print_r($errorMsgs, true));
            return $errorMsgs;
        }

        // @todo not working properly yet - may require an external xsl processor for full implementation?
        // NLM style checker
        $xslFile = 'plugins/generic/pubmedCentral/xsl/nlm-stylechecker.xsl';
        $xslTransformer = new XSLTransformer();
        $filteredXml = $xslTransformer->transform(
            $importedJats,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_DOM,
            $xslFile,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_FILE,
            XSLTransformer::XSL_TRANSFORMER_DOCTYPE_DOM
        );

        // if root node is <ERR>, the style checker found issues
        if ($filteredXml->documentElement->nodeName == 'ERR') {
            $errors = $filteredXml->getElementsByTagName('error');
            $warnings = $filteredXml->getElementsByTagName('warning');

            //$details = [];
            foreach ($errors as $error) {
                error_log('Style Error: ' . $error->textContent);
                //$details[] = 'Style Error: ' . $error->textContent;
            }
            foreach ($warnings as $warning) {
                // @todo decide how to handle warnings
                error_log('Style Warning: ' . $warning->textContent);
            }
            //return !empty($details) ? $details : ['Unknown style validation error.'];
        }

        return true;
    }
}
