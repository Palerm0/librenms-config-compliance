<?php

/*
 * Hooks/Settings.php
 *
 * De settings-hook voor de "Settings"-knop op de pluginbeheer-pagina
 * (Overview -> Plugins). Zonder deze hook toont LibreNMS daar
 * "Missing view." — met deze hook krijg je een net instellingenformulier.
 *
 * Patroon volgt het officiële voorbeeld:
 * https://github.com/murrant/librenms-example-plugin
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance\Hooks;

use Illuminate\Foundation\Auth\User;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use Palerm0\LibrenmsConfigCompliance\ComplianceEngine;

class Settings implements SettingsHook
{
    public function authorize(User $user): bool
    {
        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => $settings,
            'cc' => app(ComplianceEngine::class)->settings(),
        ];
    }
}
