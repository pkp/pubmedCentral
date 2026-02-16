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
use League\Flysystem\Ftp\FtpAdapter;
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
use Throwable;
use ZipArchive;

class PubmedCentralExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    public const JATS_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    public const JATS_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';
    public const JATS_VERSION = '1.2';

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
     *
     * @param bool $ts Whether to include a timestamp in the filename.
     * @param string|null $fileExtension The optional file extension to include in the filename.
     */
    private function buildFileName(
        string $nlmTitle,
        Submission|Publication|null $object = null,
        bool $ts = false,
        ?string $fileExtension = null
    ): string {
        // @todo add setting to select vol/issue naming vs. continuous pub naming?
        // @todo make final decision on article naming - using publication ID for now.
        $publicationId = $object instanceof Submission ? $object->getCurrentPublication()->getId() : $object?->getId();
        $nlmTitle = preg_replace('/[^a-zA-Z0-9]/', '', $nlmTitle);
        $timeStamp = date('YmdHis');
        return strtolower(
            $nlmTitle .
            ($publicationId ? '-' . $publicationId : '') .
            ($ts ? '-' . $timeStamp : '') .
            ($fileExtension ? '.' . $fileExtension : '')
        );
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
        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $resultErrors = [];
            $result = $this->depositXML($objects, $context, $noValidation);
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
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $path = $this->createZipCollection($objects, $context, $noValidation);
            if (!empty($path['error'])) {
                $this->_sendNotification(
                    $request->getUser(),
                    $path['error'][0],
                    Notification::NOTIFICATION_TYPE_ERROR,
                    $path['error'][1]
                );
                $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
            } else {
                $nlmTitle = $this->nlmTitle($context);
                $filename = $this->buildFileName($nlmTitle, null, false, 'zip');
                if (count($objects) == 1) {
                    $object = array_shift($objects);
                    $filename = $this->buildFileName($nlmTitle, $object, true, 'zip');
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
            $returnXml = $this->modifyDefaultJats($xml, $submissionId, $articlePdfFilename, $nlmTitle);
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
        // @todo store last export date/timestamp?
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
     * Exports a zip file with the selected articles to the configured PMC account.
     *
     * @return bool|array True if the deposit was successful, or an array of error messages.
     */
    public function depositXML($objects, $context, $filename = null, ?bool $noValidation = null): bool|array
    {
        // Verify that the credentials are complete
        $settings = $this->getConnectionSettings($context);
        if (
            empty($settings['host']) ||
            empty($settings['username']) ||
            empty($settings['password'])
        ) {
            return ['plugins.importexport.pmc.export.failure.settings'];
        }

        // Perform the deposit
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
                'host' => $settings['host'],
                'port' => (int)$settings['port'] ?: 21,
                'username' => $settings['username'],
                'password' => $settings['password'],
                'root' => $settings['path'],
            ]));
        $fs = new Filesystem($adapter);
        $errors = false;

        foreach ($objects as $object) {
            $packagedObject = $this->createZip($object, $context, $noValidation);
            if (array_key_exists('error', $packagedObject)) {
                $errorMessage = $this->convertErrorMessage($packagedObject['error']);
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                $errors = true;
            } else {
                $fp = fopen($packagedObject['path'], 'r');
                if ($fp) {
                    try {
                        $fs->writeStream($packagedObject['filename'] . '.zip', $fp);
                    } catch (Throwable $e) {
                        $this->updateStatus(
                            $object,
                            PubObjectsExportPlugin::EXPORT_STATUS_ERROR,
                            $e->getMessage()
                        );
                        $errors = true;
                        continue;
                    } finally {
                        fclose($fp);
                    }
                    // Mark the object as registered.
                    $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
                    if (!unlink($packagedObject['path'])) {
                        error_log('Failed to delete zip file after deposit: ' . $packagedObject['path']);
                    }
                } else {
                    $errorMessage = $this->convertErrorMessage(
                        ['plugins.importexport.pmc.export.failure.openingFile', $packagedObject['path']]
                    );
                    $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                    $errors = true;
                }
            }
        }

        if ($errors) {
            return ['plugins.importexport.pmc.export.errors'];
        }

        return true;
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

        $zipPath = tempnam(sys_get_temp_dir(), 'PubmedCentralExport_');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return ['error' => ['plugins.importexport.pmc.export.failure.creatingFile', $zip->getStatusString()]];
        }

        $publication = $object instanceof Submission ? $object->getCurrentPublication() : $object;
        $locale = $object->getData('locale');
        $filename = $this->buildFileName($nlmTitle, $object);

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
            $galleyFilename = $this->buildFileName($nlmTitle, $object, false, $extension);
            $galleyFilePath = $filename . '/' . $galleyFilename;
            $articlePdfFilename = $galleyFilename;

            if ($pdfFilesFound > 0) {
                return ['error' => ['plugins.importexport.pmc.export.failure.multipleArticleFiles']];
            }

            if (
                !$zip->addFromString(
                    $galleyFilePath,
                    $fileService->fs->read($galleyPath)
                )
            ) {
                return ['error' => ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]];
            }
            $pdfFilesFound++;
        }

        // Add article XML to the zip
        $document = $this->exportXML($object, null, $context, $noValidation, $exportErrors, $articlePdfFilename, $genres, $nlmTitle);
        if (is_array($document)) {
            return ['error' => $document];
        } else {
            $articlePathName = $filename . '/' . $this->buildFileName($nlmTitle, $object, false, 'xml');
            if (!$zip->addFromString($articlePathName, $document)) {
                return ['error' => ['plugins.importexport.pmc.export.failure.addingFile', $zip->getStatusString()]];
            }
            $zipDetails['filename'] = $this->buildFileName($nlmTitle, $object, true);
            $zipDetails['path'] = $zipPath;
            $zip->close();
        }
        return $zipDetails;
    }

    /**
     * Create a zip file of collected objects for download.
     *
     * @return array the path of the created zip file or error details, if applicable.
     */
    private function createZipCollection(array $objects, Context $context, ?bool $noValidation = null): array
    {
        $finalZipPath = tempnam(sys_get_temp_dir(), 'PubmedCentralExport_');
        $finalZip = new ZipArchive();
        if ($finalZip->open($finalZipPath, ZipArchive::CREATE) !== true) {
            return ['error' => ['plugins.importexport.pmc.export.failure.creatingFile', $finalZip->getStatusString()]];
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
                return ['error' => ['plugins.importexport.pmc.export.failure.creatingFile', $errorDetails]];
            }
            if (!$finalZip->addFile($zipPackage['path'], $zipPackage['filename'] . '.zip')) {
                unlink($zipPackage['path']);
                return ['error' => [
                    'plugins.importexport.pmc.export.failure.creatingFile',
                    $finalZip->getStatusString()]
                ];
            }
            $createdPaths[] = $zipPackage['path'];
        }
        $finalZip->close();

        // Clean up temporary zip files.
        foreach ($createdPaths as $createdPath) {
            unlink($createdPath);
        }
        return ['path' => $finalZipPath];
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
     */
    protected function modifyDefaultJats(
        string $importedJats,
        int $submissionId,
        ?string $articlePdfFilename,
        string $nlmTitle
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

        if (!$articleMetaNode = $xpath->query("//article/front/article-meta")->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'];
        }

        // change pub-date publication-format from epub to electronic
        $pubDateNode = $xpath->query("pub-date[@publication-format='epub']", $articleMetaNode)->item(0);
        $pubDateNode?->setAttribute('publication-format', 'electronic');

        $articleContribNodes = $xpath->query(
            "contrib-group/contrib[not(@contrib-type='author' or contrib-type='editor')]",
            $articleMetaNode
        );
        foreach ($articleContribNodes as $node) { /** @var DOMNode $node **/
            $node->parentNode->removeChild($node);
        }

        // move name out of name-alternatives if only one name is present for a contrib
        // as name-alternatives must contain more than 1 child element for PMC
        $nameAlternativesNodes = $xpath->query("contrib-group/contrib/name-alternatives", $articleMetaNode);
        foreach ($nameAlternativesNodes as $node) { /** @var DOMNode $node **/
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
        $fpageNode = $xpath->query("fpage", $articleMetaNode)->item(0);
        if (!$fpageNode) {
            $elocationNode = $dom->createElement('elocation-id');
            $elocationNode->appendChild($dom->createTextNode($submissionId));
            $pubHistoryNode = $xpath->query("pub-history", $articleMetaNode)->item(0);
            if ($pubHistoryNode) {
                $articleMetaNode->insertBefore($elocationNode, $pubHistoryNode);
            } else {
                $permissionsNode = $xpath->query("permissions", $articleMetaNode)->item(0);
                if ($permissionsNode) {
                    $articleMetaNode->insertBefore($elocationNode, $permissionsNode);
                } else {
                    return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'permissions'];
                }
            }
        }

        // Remove any existing self-uri PDF links
        $selfUriPdfNodes = $xpath->query(
            "self-uri[@content-type='pdf' or @content-type='application/pdf']",
            $articleMetaNode
        );
        foreach ($selfUriPdfNodes as $selfUriPdfNode) {
            $selfUriPdfNode->parentNode->removeChild($selfUriPdfNode);
        }

        if ($articlePdfFilename) {
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
     * Modify an uploaded JATS document to meet PMC requirements.
     */
    protected function modifyCustomJats(
        string $importedJats,
        ?string $articlePdfFilename
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

        // Remove any existing self-uri PDF links
        $selfUriPdfNodes = $xpath->query(
            "self-uri[@content-type='pdf' or @content-type='application/pdf']",
            $articleMetaNode
        );
        foreach ($selfUriPdfNodes as $selfUriPdfNode) {
            $selfUriPdfNode->parentNode->removeChild($selfUriPdfNode);
        }

        if ($articlePdfFilename) {
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
        }

        return $dom->saveXML();
    }

    /**
     * Validate a JATS XML document against the DTD and the NLM style checker XSL.
     *
     * @return true|string true if valid, or an error message.
     */
    protected function validateJats(DOMDocument $importedJats): true|string
    {
        libxml_use_internal_errors(true);

        // DTD Validation
        if (!$importedJats->validate()) {
            $errors = libxml_get_errors();
            $validationErrors = [];
            foreach ($errors as $error) {
                $validationErrors[] = "DTD Error [line $error->line]: " . trim($error->message);
            }
            libxml_clear_errors();
            return implode(PHP_EOL, $validationErrors);
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

        $warnings = $filteredXml->getElementsByTagName('warning');
        foreach ($warnings as $warning) {
            // @todo decide how to handle warnings - add to errors or continue? Or add validation setting for users?
            error_log('PMC Style Warning: ' . $warning->textContent);
        }
        return !empty($styleCheckErrors) ? implode(PHP_EOL, $styleCheckErrors) : true;
    }

    /**
     * Helper to convert an error array to a string.
     */
    protected function convertErrorMessage(array $errorMessage): string
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
