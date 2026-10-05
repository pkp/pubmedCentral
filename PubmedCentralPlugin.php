<?php

/**
 * @file PubmedCentralPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubmedCentralPlugin
 *
 * @brief Plugin to export articles to PubMed Central.
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;

class PubmedCentralPlugin extends PubObjectsExportGenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        return parent::register($category, $path, $mainContextId);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.pmc.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.pmc.description');
    }

    /**
     * @copydoc PubObjectsExportGenericPlugin::handleIdentityRestamped()
     *
     * Overridden to do nothing: the deposited journal-meta uses the journal's current identity,
     * so a re-stamp must not mark an already-delivered article stale and re-send it to PMC.
     */
    public function handleIdentityRestamped($hookName, $params): bool
    {
        return Hook::CONTINUE;
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register('importexport', new PubmedCentralExportPlugin(), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'PubmedCentralExportPlugin');
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
