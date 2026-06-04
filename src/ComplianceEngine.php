<?php

/*
 * ComplianceEngine.php
 *
 * Het hart van de plugin. Deze klasse:
 *   - leest/schrijft de instellingen (Oxidized-URL) en de regels (JSON-bestanden)
 *   - haalt per device de config op bij Oxidized
 *   - controleert elke regel ("config bevat" / "config bevat niet")
 *   - schrijft het resultaat weg zodat de pagina en de historie het kunnen tonen
 *
 * Alle bestanden staan in storage/app/config-compliance/ binnen LibreNMS.
 *
 * GPL-3.0-or-later
 */

namespace Palerm0\LibrenmsConfigCompliance;

use App\Models\Device;
use App\Models\DeviceGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ComplianceEngine
{
    /** Map waar de plugin zijn JSON-bestanden bewaart. */
    private string $dir;

    public function __construct()
    {
        $this->dir = storage_path('app/config-compliance');

        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /* ---------- Instellingen ---------- */

    /**
     * @return array{oxidized_url: string}
     */
    public function settings(): array
    {
        $data = $this->readJson('settings.json');

        return [
            'oxidized_url' => (string) ($data['oxidized_url'] ?? ''),
        ];
    }

    public function saveSettings(string $oxidizedUrl): void
    {
        $this->writeJson('settings.json', [
            'oxidized_url' => trim($oxidizedUrl),
        ]);

        // De Oxidized-statuscheck wordt kort gecached; na het wijzigen van de
        // URL moet die meteen opnieuw bepaald worden.
        Cache::forget(self::OXIDIZED_CACHE_KEY);
    }

    /* ---------- Oxidized-controle ---------- */

    /** Cache-sleutel voor de uitkomst van de Oxidized-bereikbaarheidscheck. */
    private const OXIDIZED_CACHE_KEY = 'config-compliance:oxidized-status';

    /**
     * Controleert of Oxidized geconfigureerd én bereikbaar is. Het resultaat
     * wordt 60 seconden gecached, zodat niet elke paginalading een HTTP-call
     * doet (en bij een trage/dode Oxidized de pagina niet telkens hangt).
     *
     * state is 'unconfigured' (geen URL), 'ok' (REST-API antwoordt) of
     * 'error' (URL ingevuld maar niet bereikbaar).
     *
     * @return array{state: string, url: string, node_count: int|null}
     */
    public function checkOxidized(): array
    {
        return Cache::remember(self::OXIDIZED_CACHE_KEY, 60, function (): array {
            $url = rtrim($this->settings()['oxidized_url'], '/');

            if ($url === '') {
                return ['state' => 'unconfigured', 'url' => '', 'node_count' => null];
            }

            try {
                // /nodes.json is het standaard REST-endpoint van oxidized-web
                // en geeft meteen het aantal bekende nodes terug.
                $response = Http::timeout(4)->get($url . '/nodes.json');

                if ($response->successful()) {
                    $nodes = $response->json();

                    return [
                        'state' => 'ok',
                        'url' => $url,
                        'node_count' => is_array($nodes) ? count($nodes) : null,
                    ];
                }

                return ['state' => 'error', 'url' => $url, 'node_count' => null];
            } catch (\Throwable $e) {
                Log::warning('config-compliance: Oxidized-controle mislukt: ' . $e->getMessage());

                return ['state' => 'error', 'url' => $url, 'node_count' => null];
            }
        });
    }

    /* ---------- Regels ---------- */

    /**
     * Elke regel is een array: name, group, os en een lijst 'checks'.
     * Elke check is zelf een array: type (contains|not_contains) en pattern.
     * Een regel slaagt pas als ALLE checks slagen.
     *
     * Oudere regels (van vóór v1.3.0) hadden 'type' en 'pattern' rechtstreeks
     * op de regel staan. Die worden hier automatisch omgezet naar één check,
     * zodat bestaande rules.json-bestanden gewoon blijven werken.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rules(): array
    {
        $data = $this->readJson('rules.json');

        if (! is_array($data)) {
            return [];
        }

        $out = [];

        foreach ($data as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $out[] = [
                'name' => (string) ($rule['name'] ?? ''),
                'group' => trim((string) ($rule['group'] ?? '*')) ?: '*',
                'os' => trim((string) ($rule['os'] ?? '*')) ?: '*',
                'checks' => $this->normalizeChecks($rule),
            ];
        }

        return $out;
    }

    /**
     * Zet de checks van een regel om naar een nette lijst. Vangt zowel de
     * nieuwe vorm (regel met 'checks') als de oude platte vorm op.
     *
     * @param  array<string, mixed>  $rule
     * @return array<int, array{type: string, pattern: string}>
     */
    private function normalizeChecks(array $rule): array
    {
        $checks = [];

        if (isset($rule['checks']) && is_array($rule['checks'])) {
            // Nieuwe vorm: een regel met een lijst checks.
            foreach ($rule['checks'] as $check) {
                if (! is_array($check)) {
                    continue;
                }

                $pattern = (string) ($check['pattern'] ?? '');

                if ($pattern === '') {
                    continue;
                }

                $checks[] = [
                    'type' => $this->normalizeType($check['type'] ?? 'contains'),
                    'pattern' => $pattern,
                ];
            }
        } elseif (isset($rule['pattern'])) {
            // Oude vorm (vóór v1.3.0): één type + patroon op de regel zelf.
            $pattern = (string) $rule['pattern'];

            if ($pattern !== '') {
                $checks[] = [
                    'type' => $this->normalizeType($rule['type'] ?? 'contains'),
                    'pattern' => $pattern,
                ];
            }
        }

        return $checks;
    }

    /**
     * Geldige check-types:
     *  - contains       : patroon moet in de config staan
     *  - not_contains   : patroon mag NIET in de config staan
     *  - contains_any   : minstens één van de patronen (regels) moet erin staan
     *  - contains_none  : géén van de patronen (regels) mag erin staan
     * Onbekende waarden vallen terug op 'contains'.
     */
    private function normalizeType(mixed $type): string
    {
        $type = (string) $type;
        $valid = ['contains', 'not_contains', 'contains_any', 'contains_none'];

        return in_array($type, $valid, true) ? $type : 'contains';
    }

    /**
     * Voert één check uit tegen de config en geeft true (geslaagd) of false.
     * Voor de "any"/"none"-types wordt het patroon per regel gesplitst en geldt
     * elke regel als een los alternatief.
     */
    private function evalCheck(string $config, string $type, string $pattern): bool
    {
        if ($type === 'contains_any' || $type === 'contains_none') {
            $alternatives = array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $pattern)),
                fn ($line) => $line !== ''
            );

            $anyFound = false;
            foreach ($alternatives as $alt) {
                if (stripos($config, $alt) !== false) {
                    $anyFound = true;
                    break;
                }
            }

            return $type === 'contains_any' ? $anyFound : ! $anyFound;
        }

        $found = stripos($config, $pattern) !== false;

        return $type === 'contains' ? $found : ! $found;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function saveRules(array $rules): void
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $checks = $this->normalizeChecks($rule);

            // Een regel zonder geldige checks slaan we over.
            if (empty($checks)) {
                continue;
            }

            $name = trim((string) ($rule['name'] ?? ''));

            $clean[] = [
                'name' => $name !== '' ? $name : $checks[0]['pattern'],
                'group' => trim((string) ($rule['group'] ?? '*')) ?: '*',
                'os' => trim((string) ($rule['os'] ?? '*')) ?: '*',
                'checks' => $checks,
            ];
        }

        $this->writeJson('rules.json', $clean);
    }

    /**
     * De namen van alle device-groepen in LibreNMS, voor de keuzelijst
     * in de regel-editor.
     *
     * @return array<int, string>
     */
    public function availableGroups(): array
    {
        return DeviceGroup::orderBy('name')->pluck('name')->all();
    }

    /**
     * De OS-namen van alle bestaande devices, voor de keuzelijst in de
     * regel-editor.
     *
     * @return array<int, string>
     */
    public function availableOsList(): array
    {
        return Device::query()
            ->whereNotNull('os')
            ->where('os', '!=', '')
            ->distinct()
            ->orderBy('os')
            ->pluck('os')
            ->all();
    }

    /**
     * Versienummer van de plugin. Eén plek om te updaten bij een release;
     * Packagist leidt zelf de versie af uit de bijbehorende git-tag.
     */
    public const VERSION = '1.9.7';

    public function version(): string
    {
        return self::VERSION;
    }

    /* ---------- Resultaten ---------- */

    /**
     * Het laatste scanrapport, of null als er nog niet gescand is.
     *
     * @return array<string, mixed>|null
     */
    public function latestReport(): ?array
    {
        $data = $this->readJson('results.json');

        return ! empty($data) ? $data : null;
    }

    /**
     * De historie: per scan een korte samenvatting (datum + aantallen).
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(): array
    {
        $data = $this->readJson('history.json');

        return is_array($data) ? $data : [];
    }

    /* ---------- De scan ---------- */

    /**
     * Voert de volledige compliance-scan uit en bewaart het resultaat.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $rules = $this->rules();
        $oxidizedUrl = rtrim($this->settings()['oxidized_url'], '/');

        $devices = Device::where('disabled', 0)
            ->with('groups')
            ->orderBy('hostname')
            ->get(['device_id', 'hostname', 'sysName', 'os', 'status']);

        $results = [];
        $compliant = 0;
        $nonCompliant = 0;
        $noConfig = 0;
        $noRules = 0;

        foreach ($devices as $device) {
            // De groepsnamen waar dit device lid van is.
            $deviceGroups = $device->groups->pluck('name')->all();

            // Een regel telt mee als zowel het OS-filter als het groep-filter
            // past ('*' betekent telkens: geldt voor alles). Regels zonder
            // checks tellen niet mee.
            $matched = array_values(array_filter($rules, function ($rule) use ($device, $deviceGroups): bool {
                if (empty($rule['checks'])) {
                    return false;
                }

                $ruleOs = $rule['os'] ?? '*';
                $ruleGroup = $rule['group'] ?? '*';

                $osOk = $ruleOs === '*' || $ruleOs === $device->os;
                $groupOk = $ruleGroup === '*' || in_array($ruleGroup, $deviceGroups, true);

                return $osOk && $groupOk;
            }));

            // Geen enkele regel van toepassing op dit device: niet getoetst.
            if (empty($matched)) {
                $results[] = [
                    'device_id' => $device->device_id,
                    'hostname' => $device->hostname,
                    'os' => $device->os,
                    'down' => (int) $device->status === 0,
                    'groups' => $deviceGroups,
                    'status' => 'no_rules',
                    'passed' => 0,
                    'total' => 0,
                    'checks_failed' => 0,
                    'checks_total' => 0,
                    'failed_rules' => [],
                ];
                $noRules++;
                continue;
            }

            $config = $this->fetchConfig($oxidizedUrl, (string) $device->hostname);

            if ($config === null) {
                $results[] = [
                    'device_id' => $device->device_id,
                    'hostname' => $device->hostname,
                    'os' => $device->os,
                    'down' => (int) $device->status === 0,
                    'groups' => $deviceGroups,
                    'status' => 'no_config',
                    'passed' => 0,
                    'total' => count($matched),
                    'checks_failed' => 0,
                    'checks_total' => 0,
                    'failed_rules' => [],
                ];
                $noConfig++;
                continue;
            }

            $passed = 0;              // aantal regels dat volledig slaagt
            $deviceChecksFailed = 0;  // totaal mislukte checks over alle regels
            $deviceChecksTotal = 0;   // totaal aantal checks over alle regels
            $failedRules = [];

            foreach ($matched as $rule) {
                $checks = $rule['checks'] ?? [];
                $ruleChecksFailed = 0;
                $checkResults = [];

                foreach ($checks as $check) {
                    $type = $this->normalizeType($check['type'] ?? 'contains');
                    $pattern = (string) $check['pattern'];
                    $ok = $this->evalCheck($config, $type, $pattern);

                    if (! $ok) {
                        $ruleChecksFailed++;
                    }

                    // Per check de uitslag bewaren, zodat de pagina later
                    // kan tonen wélke checks precies misgingen.
                    $checkResults[] = [
                        'type' => $type,
                        'pattern' => $pattern,
                        'passed' => $ok,
                    ];
                }

                $deviceChecksTotal += count($checks);
                $deviceChecksFailed += $ruleChecksFailed;

                // Een regel slaagt alleen als ELKE check slaagt.
                if ($ruleChecksFailed === 0) {
                    $passed++;
                } else {
                    $failedRules[] = [
                        'name' => $rule['name'],
                        'checks_total' => count($checks),
                        'checks_failed' => $ruleChecksFailed,
                        'checks' => $checkResults,
                    ];
                }
            }

            $status = empty($failedRules) ? 'compliant' : 'non_compliant';
            $status === 'compliant' ? $compliant++ : $nonCompliant++;

            $results[] = [
                'device_id' => $device->device_id,
                'hostname' => $device->hostname,
                'os' => $device->os,
                'down' => (int) $device->status === 0,
                'groups' => $deviceGroups,
                'status' => $status,
                'passed' => $passed,
                'total' => count($matched),
                'checks_failed' => $deviceChecksFailed,
                'checks_total' => $deviceChecksTotal,
                'failed_rules' => $failedRules,
            ];
        }

        $report = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'summary' => [
                'devices' => count($results),
                'compliant' => $compliant,
                'non_compliant' => $nonCompliant,
                'no_config' => $noConfig,
                'no_rules' => $noRules,
                'rules' => count($rules),
            ],
            'devices' => $results,
        ];

        $this->writeJson('results.json', $report);
        $this->appendHistory($report);

        return $report;
    }

    /* ---------- Hulpfuncties ---------- */

    /**
     * Haalt de actuele config van een device op bij Oxidized.
     * Geeft null terug als er geen config beschikbaar is.
     */
    private function fetchConfig(string $oxidizedUrl, string $hostname): ?string
    {
        if ($oxidizedUrl === '' || $hostname === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->get($oxidizedUrl . '/node/fetch/' . rawurlencode($hostname));

            if ($response->successful() && trim($response->body()) !== '') {
                return $response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('config-compliance: ophalen config mislukt voor ' . $hostname . ': ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Voegt een samenvatting toe aan de historie (laatste 90 scans).
     *
     * @param  array<string, mixed>  $report
     */
    private function appendHistory(array $report): void
    {
        $history = $this->history();

        $history[] = [
            'generated_at' => $report['generated_at'],
            'summary' => $report['summary'],
        ];

        // Alleen de laatste 90 scans bewaren.
        if (count($history) > 90) {
            $history = array_slice($history, -90);
        }

        $this->writeJson('history.json', $history);
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $file): array
    {
        $path = $this->dir . '/' . $file;

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function writeJson(string $file, array $data): void
    {
        file_put_contents(
            $this->dir . '/' . $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
