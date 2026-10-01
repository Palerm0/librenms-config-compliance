<?php

/*
 * routes/web.php
 *
 * De webadressen van de plugin. Alles zit achter de 'auth'-middleware,
 * dus je moet ingelogd zijn in LibreNMS om het te bereiken.
 *
 * Let op: het pad is bewust 'plugin/config-compliance-page' en NIET
 * 'plugin/config-compliance'. Dat laatste is door LibreNMS gereserveerd
 * voor het ingebouwde plugin-paginasysteem.
 *
 * GPL-3.0-or-later
 */

use Illuminate\Support\Facades\Route;
use Palerm0\LibrenmsConfigCompliance\Controllers\CompliancePageController;
use Palerm0\LibrenmsConfigCompliance\Http\Controllers\Widgets\ComplianceSummaryController;

Route::middleware(['web', 'auth'])->group(function (): void {
    // Dashboard-widget. LibreNMS bouwt de "Add Widget"-kiezer door de
    // route-tabel te scannen op routes met prefix EXACT 'ajax/dash'. Geneste
    // prefix()-aanroepen plakken aan elkaar, dus 'ajax' + 'dash' levert de
    // vereiste waarde. Het laatste pad-segment wordt de widget-sleutel.
    // Daarom is 'php artisan route:clear' verplicht na installatie.
    Route::prefix('ajax')->group(function (): void {
        Route::prefix('dash')->group(function (): void {
            Route::post('config-compliance-summary', ComplianceSummaryController::class);
        });
    });

    // De hoofdpagina van de plugin.
    Route::get('plugin/config-compliance-page', [CompliancePageController::class, 'index'])
        ->name('config-compliance.index');

    // Kale samenvattingsweergave voor in een dashboard-widget (iframe).
    Route::get('plugin/config-compliance-page/summary', [CompliancePageController::class, 'summary'])
        ->name('config-compliance.summary');

    // Knop "Scan nu" - voert direct een scan uit.
    Route::post('plugin/config-compliance-page/scan', [CompliancePageController::class, 'scan'])
        ->name('config-compliance.scan');

    // Opslaan van de compliance-regels.
    Route::post('plugin/config-compliance-page/rules', [CompliancePageController::class, 'saveRules'])
        ->name('config-compliance.rules');

    // Opslaan van de instellingen (o.a. de Oxidized-URL).
    Route::post('plugin/config-compliance-page/settings', [CompliancePageController::class, 'saveSettings'])
        ->name('config-compliance.settings');

    // Live regex-validatie voor de regelbewerker (PCRE via de server).
    Route::post('plugin/config-compliance-page/validate-regex', [CompliancePageController::class, 'validateRegex'])
        ->name('config-compliance.validate-regex');
});
