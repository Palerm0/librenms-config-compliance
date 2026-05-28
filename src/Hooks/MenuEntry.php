<?php

/*
 * MenuEntry.php
 *
 * Deze hook vertelt LibreNMS welk menu-item er bij komt. De handle()-methode
 * geeft de naam van de blade-view terug die het menu-item tekent.
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    /**
     * Bepaalt of het menu-item zichtbaar is. true = voor elke ingelogde gebruiker.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Geeft de view voor het menu-item terug.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
