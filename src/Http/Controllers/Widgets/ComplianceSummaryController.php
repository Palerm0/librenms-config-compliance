<?php

/*
 * ComplianceSummaryController.php
 *
 * Een echte LibreNMS dashboard-widget: toont de compliance-samenvatting
 * (score + tellingen + datum laatste scan) als tegel op een dashboard.
 *
 * LibreNMS ontdekt dashboard-widgets door de route-tabel te scannen op
 * routes met prefix 'ajax/dash' (zie routes/web.php). De widget breidt
 * core's WidgetController uit en levert twee views: de widget zelf
 * (widgets.config-compliance-summary) en een klein instellingenformulier
 * (widgets.settings.config-compliance-summary). Die namen moeten
 * on-namespaced zijn; de provider voegt daarom de views-map als extra
 * zoeklocatie toe.
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance\Http\Controllers\Widgets;

use App\Http\Controllers\Widgets\WidgetController;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Palerm0\LibrenmsConfigCompliance\ComplianceEngine;

class ComplianceSummaryController extends WidgetController
{
    /**
     * De slug van de widget. Wordt het laatste pad-segment van de route
     * (ajax/dash/config-compliance-summary) en de sleutel in users_widgets.
     * NOOIT hernoemen: bestaande dashboard-plaatsingen verwijzen ernaar.
     */
    protected string $name = 'config-compliance-summary';

    /** @var array<string, mixed> */
    protected $defaults = [
        'title' => null,
    ];

    /**
     * Titel in de "Add Widget"-kiezer en bovenaan de tegel. Een eigen titel
     * per plaatsing wint; anders de standaardnaam.
     */
    public function getTitle(): string
    {
        $custom = $this->getSettings()['title'] ?? null;

        if (is_string($custom) && trim($custom) !== '') {
            return trim($custom);
        }

        return 'Config Compliance';
    }

    /**
     * De widget zelf: de samenvattingsbalk met de laatste scanresultaten.
     */
    public function getView(Request $request): View
    {
        $engine = app(ComplianceEngine::class);
        $report = $engine->latestReport() ?? ['summary' => [], 'generated_at' => null];

        return view('widgets.config-compliance-summary', [
            'report' => $report,
            'pageUrl' => route('config-compliance.index'),
        ]);
    }

    /**
     * Het instellingenformulier (alleen een eigen titel). Core toont dit als
     * je op het tandwiel van de widget klikt.
     */
    public function getSettingsView(Request $request): View
    {
        return view('widgets.settings.config-compliance-summary', [
            'id' => $this->getSettings()['id'] ?? null,
            'title' => $this->getSettings(true)['title'] ?? null,
        ]);
    }
}
