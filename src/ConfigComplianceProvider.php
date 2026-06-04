<?php

/*
 * ConfigComplianceProvider.php
 *
 * Dit is het "opstartbestand" van de plugin. LibreNMS (Laravel) laadt deze
 * provider automatisch via composer en roept boot() aan. Hier registreren we:
 *   - de menu-hook (zet "Config Compliance" in het plugin-menu)
 *   - de routes (de webadressen van de plugin-pagina)
 *   - de views (de blade-templates)
 *   - het scan-commando (voor de dagelijkse cron-run)
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance;

use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Palerm0\LibrenmsConfigCompliance\Console\ScanCommand;
use Palerm0\LibrenmsConfigCompliance\Hooks\MenuEntry;
use Palerm0\LibrenmsConfigCompliance\Hooks\Settings;

class ConfigComplianceProvider extends ServiceProvider
{
    /**
     * De interne naam van de plugin. Deze wordt gebruikt als view-namespace
     * (config-compliance::page) en bij het registreren van hooks.
     */
    private const PLUGIN_NAME = 'config-compliance';

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Menu-hook altijd registreren, zodat LibreNMS de plugin in de UI toont.
        $pluginManager->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, MenuEntry::class);

        // Settings-hook voor de "Settings"-knop op de pluginbeheer-pagina.
        $pluginManager->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);

        // Het scan-commando altijd beschikbaar maken (ook voor cron via lnms).
        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class]);
        }

        // Views altijd laden: de settings-view moet ook renderen als de
        // plugin (nog) uitgeschakeld is, anders toont LibreNMS "Missing view."
        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::PLUGIN_NAME);

        // De rest alleen laden als de plugin in LibreNMS is ingeschakeld.
        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}
