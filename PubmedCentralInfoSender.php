<?php

/**
 * @file PubmedCentralInfoSender.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubmedCentralInfoSender
 *
 * @brief Scheduled task to send deposits to PubMed Central.
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\core\Application;
use APP\journal\Journal;
use APP\publication\Publication;
use APP\submission\Submission;
use Exception;
use PKP\context\Context;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class PubmedCentralInfoSender extends ScheduledTask
{
    public ?PubmedCentralExportPlugin $plugin = null;

    /**
     * Constructor.
     */
    public function __construct(array $args = [])
    {
        PluginRegistry::loadCategory('importexport');

        /** @var PubmedCentralExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'PubmedCentralExportPlugin');
        $this->plugin = $plugin;

        if ($plugin instanceof PubmedCentralExportPlugin) {
            $plugin->addLocaleData();
        }

        parent::__construct($args);
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.importexport.pmc.senderTask.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     * @throws Exception
     */
    public function executeActions(): bool
    {
        if (!$this->plugin) {
            return false;
        }

        $plugin = $this->plugin;
        $journals = $this->getJournals();

        foreach ($journals as $journal) {
            if ($journal->getData(Context::SETTING_DOI_VERSIONING)) {
                $depositablePublications = $plugin->getAllDepositablePublications($journal);
                if (count($depositablePublications)) {
                    $this->registerObjects($depositablePublications, $journal);
                }
            } else {
                $depositableArticles = $plugin->getAllDepositableArticles($journal);
                if (count($depositableArticles)) {
                    $this->registerObjects($depositableArticles, $journal);
                }
            }
        }

        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles automatically sent to Pubmed Central.
     *
     * @return array<Journal>
     * @throws Exception
     */
    protected function getJournals(): array
    {
        $plugin = $this->plugin;
        $contextDao = Application::getContextDAO();
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) { /** @var Journal $journal */
            $journalId = $journal->getId();
            $connectionSettings = $plugin->getConnectionSettings($journal);
            if (
                empty($connectionSettings['host']) ||
                empty($connectionSettings['username']) ||
                empty($connectionSettings['password']) ||
                !$plugin->getSetting($journalId, 'enabled') ||
                !$plugin->getSetting($journalId, 'nlmTitle') ||
                !$plugin->getSetting($journalId, 'automaticRegistration')
            ) {
                continue;
            }
            $journals[] = $journal;
        }
        return $journals;
    }


    /**
     * Register articles or publications
     *
     * @param array<Submission|Publication> $objects
     * @throws Exception
     */
    protected function registerObjects(array $objects, Journal $journal): void
    {
        $plugin = $this->plugin;
        foreach ($objects as $object) {
            // Deposit the JSON
            $result = $plugin->depositXML([$object], $journal);
            if ($result !== true) {
                $this->addLogEntry($result);
            }
        }
    }

    /**
     * Add execution log entry
     * @throws Exception
     */
    protected function addLogEntry(array $error): void
    {
        if (count($error) === 0) {
            throw new Exception('Invalid error message');
        }
        $this->addExecutionLogEntry(
            __($error[0], ['param' => $error[1] ?? null]),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
        );
    }
}
