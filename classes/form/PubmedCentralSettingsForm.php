<?php

/**
 * @file PubmedCentralSettingsForm.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralSettingsForm
 *
 * @brief Form for journal managers to modify PubMed Central plugin settings.
 */

namespace APP\plugins\generic\pubmedCentral\classes\form;

use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use APP\template\TemplateManager;
use Exception;

class PubmedCentralSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private PubmedCentralExportPlugin $plugin, private int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));
    }

    //
    // Implement template methods from Form.
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $contextId = $this->contextId;
        $plugin = $this->plugin;
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydata Form::fetch()
     *
     * @param null|mixed $template
     * @throws Exception
     */
    public function fetch($request, $template = null, $display = false)
    {
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    public function getFormFields(): array
    {
        return [
            'jatsImported' => 'bool',
            'nlmTitle' => 'string',
            'type' => 'string',
            'host' => 'string',
            'port' => 'string', // @todo check
            'path' => 'string',
            'username' => 'string',
            'password' => 'string'
        ];
    }

    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'jatsImported',
            'nlmTitle',
            'type',
            'host',
            'port',
            'path',
            'username',
            'password'
        ]);
    }
}
