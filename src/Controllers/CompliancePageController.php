<?php

/*
 * CompliancePageController.php
 *
 * Verzorgt de plugin-pagina en de acties erachter:
 *   - index()        toont de pagina met het laatste scanresultaat
 *   - scan()         voert direct een scan uit ("Scan nu"-knop)
 *   - saveRules()    bewaart de compliance-regels
 *   - saveSettings() bewaart de instellingen (Oxidized-URL)
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Palerm0\LibrenmsConfigCompliance\ComplianceEngine;

class CompliancePageController extends Controller
{
    public function index(ComplianceEngine $engine): View
    {
        // Eenmalig bestaande regels voorzien van een group_id (en hernoemde
        // groepen bijwerken). Idempotent: schrijft alleen bij wijzigingen.
        $engine->migrateGroupIds();

        return view('config-compliance::page', [
            'report' => $engine->latestReport(),
            'rules' => $engine->rules(),
            'settings' => $engine->settings(),
            'history' => $engine->history(),
            'groups' => $engine->availableGroups(),
            'osList' => $engine->availableOsList(),
            'version' => $engine->version(),
            'oxidized' => $engine->checkOxidized(),
        ]);
    }

    public function scan(ComplianceEngine $engine): RedirectResponse
    {
        $report = $engine->run();

        $message = sprintf(
            'Scan complete: %d devices, %d compliant, %d non-compliant, %d without config.',
            $report['summary']['devices'],
            $report['summary']['compliant'],
            $report['summary']['non_compliant'],
            $report['summary']['no_config'],
        );

        return redirect()->route('config-compliance.index')->with('status', $message);
    }

    public function saveRules(Request $request, ComplianceEngine $engine): RedirectResponse
    {
        // De regels komen binnen als JSON-string vanuit de regel-editor.
        $rules = json_decode((string) $request->input('rules', '[]'), true);

        if (! is_array($rules)) {
            $rules = [];
        }

        $engine->saveRules($rules);

        return redirect()->route('config-compliance.index')->with('status', 'Rules saved.');
    }

    public function saveSettings(Request $request, ComplianceEngine $engine): RedirectResponse
    {
        $engine->saveSettings((string) $request->input('oxidized_url', ''));

        return redirect()->route('config-compliance.index')->with('status', 'Settings saved.');
    }

    /**
     * Valideert een regex-patroon met de echte PCRE-engine en geeft JSON terug.
     * Wordt door de regelbewerker aangeroepen om live te tonen of een
     * reguliere expressie geldig is (leeg patroon = geldig).
     */
    public function validateRegex(Request $request, ComplianceEngine $engine): JsonResponse
    {
        $pattern = (string) $request->input('pattern', '');

        return response()->json([
            'valid' => $pattern === '' || $engine->isValidRegex($pattern),
        ]);
    }
}
