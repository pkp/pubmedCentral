<?php

/**
 * @file classes/form/PubmedCentralSettingsForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralSettingsForm
 *
 * @brief Form for journal managers to modify PubMed Central plugin settings.
 */

namespace APP\plugins\generic\pubmedCentral\classes\form;

use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use Exception;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class PubmedCentralSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly PubmedCentralExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        // The NLM title abbreviation names every package and file, and is written
        // into the JATS, so the plugin cannot export anything without it.
        $this->addCheck(
            new FormValidator(
                $this,
                'nlmTitle',
                FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
                'plugins.importexport.pmc.settings.form.nlmTitleRequired'
            )
        );
        // The FTP account is optional (Export-only use is valid), but partially
        // filling it in is not -- either all of host/username/password, or none.
        // The check is attached to each of the three so that whichever ones were
        // filled in are the ones flagged.
        foreach (['host', 'username', 'password'] as $accountField) {
            $this->addCheck(
                new FormValidatorCustom(
                    $this,
                    $accountField,
                    FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                    'plugins.importexport.pmc.settings.form.accountIncomplete',
                    fn () => $this->plugin->isAccountComplete($this->getAccountData())
                )
            );
        }
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'automaticRegistration',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.pmc.settings.form.automaticRegistrationRequiresAccount',
                fn () => $this->plugin->isAccountComplete($this->getAccountData())
            )
        );
    }

    /**
     * The submitted (not yet saved) host/username/password.
     */
    protected function getAccountData(): array
    {
        return [
            'host' => $this->getData('host'),
            'username' => $this->getData('username'),
            'password' => $this->getData('password'),
        ];
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
        // Default to volume/issue naming when no value has been saved.
        if (!$this->getData('namingType')) {
            $this->setData('namingType', 'volumeIssue');
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
    public function fetch($request, $template = null, $display = false): ?string
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
            'automaticRegistration' => 'bool',
            'nlmTitle' => 'string',
            'namingType' => 'string',
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string'
        ];
    }

    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'jatsImported',
            'automaticRegistration',
            'namingType',
            'host',
            'port',
            'path',
            'username',
            'password'
        ]);
    }
}
