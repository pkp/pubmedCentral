<?php

/**
 * @file jobs/PubmedCentralDeliver.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralDeliver
 *
 * @ingroup jobs
 *
 * @brief Build an object's package and deposit it to the configured PMC SFTP account.
 */

namespace APP\plugins\generic\pubmedCentral\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class PubmedCentralDeliver extends BaseJob
{
    /**
     * Building a package reads the JATS and the galley files from disk, runs the
     * style checker and may validate against the DTD, so allow more than the
     * default minute for it and the upload.
     */
    public int $timeout = 180;

    public function __construct(
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId,
        protected ?bool $noValidation = null
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     * @throws JobException
     */
    public function handle(): void
    {
        $plugin = $this->registerPlugin();
        $object = $this->getObject();

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        // Queuing a delivery marks the object submitted, so a status still reading
        // "registered" here means an earlier attempt of this job already delivered it.
        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        // The journal can be removed while deliveries for it are still queued. Like a
        // package that cannot be built below, this will fail the same way on every
        // attempt, so record the failure for the status column and stop: throwing would
        // only have the queue retry it and log a stack trace for each attempt.
        $context = Application::getContextDAO()->getById($this->contextId);
        if (!$context) {
            $errorMessage = $plugin->convertErrorMessage(
                ['plugins.importexport.pmc.export.failure.journalNotFound']
            );
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return;
        }

        // Missing metadata, an unreadable galley or invalid JATS are content problems for
        // an editor to fix, and the recorded message is the whole report
        $package = $plugin->createZip($object, $context, $this->noValidation);
        if (isset($package['error'])) {
            $errorMessage = $plugin->convertErrorMessage($package['error']);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return;
        }

        try {
            $plugin->deliverToEndpoint($package['path'], $package['filename'] . '.zip', $context);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        } catch (Throwable $e) {
            // A refused connection or a dropped transfer may well succeed on a later
            // attempt, so this one is thrown for the queue to retry
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $e->getMessage());
            throw new JobException($e->getMessage());
        } finally {
            $plugin->deleteTempFile($package['path']);
        }
    }

    /**
     * Record a delivery that failed for a reason handle() could not report itself --
     * a galley file that could not be read, an unfinished package, a timeout -- once
     * no attempts remain. Without this the object would keep the "submitted" status
     * it was queued with, which the automatic deposit task does not pick up again.
     */
    public function failed(Throwable $exception): void
    {
        $plugin = $this->registerPlugin();
        $object = $this->getObject();

        if (
            !$object ||
            $object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED
        ) {
            return;
        }

        $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $exception->getMessage());
    }

    /**
     * Register the export plugin, and hand back whichever instance the registry holds.
     *
     * This has to happen before anything loads a submission or publication: registering
     * is what adds the plugin's deposit status fields to the publication schema, and the
     * schema is only built once per process.
     */
    protected function registerPlugin(): PubmedCentralExportPlugin
    {
        PluginRegistry::register(
            'importexport',
            new PubmedCentralExportPlugin(),
            'plugins/generic/pubmedCentral',
            $this->contextId
        );

        /** @var PubmedCentralExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'PubmedCentralExportPlugin');

        return $plugin;
    }

    /**
     * Load the object this delivery was queued for.
     */
    protected function getObject(): Submission|Publication|null
    {
        return $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);
    }
}
