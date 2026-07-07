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

    /**
     * Controleert of een patroon een geldige reguliere expressie is volgens
     * PCRE (dezelfde engine die de scan gebruikt). Zo klopt de validatie in
     * de UI exact met wat er bij het scannen gebeurt.
     */
    public function isValidRegex(string $pattern): bool
    {
        $regex = '~' . str_replace('~', '\~', $pattern) . '~m';

        return @preg_match($regex, '') !== false;
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

            // Meerdere OS'en (os_list) en meerdere groepen (group_ids) worden
            // ondersteund. Oudere regels met één 'os' / 'group' worden hier
            // automatisch naar de lijst-vorm omgezet. Groepen worden op id
            // bijgehouden (stabiel bij hernoemen); de namen tonen we actueel.
            $osList = $this->normalizeOsList($rule);
            [$groupIds, $groupNames] = $this->normalizeGroups($rule);

            $out[] = [
                'name' => (string) ($rule['name'] ?? ''),
                'os_list' => $osList,
                'group_ids' => $groupIds,
                'group_names' => $groupNames,
                // Enkelvoudige, afgeleide velden voor sectie-indeling in de
                // editor en voor backward-compat met oudere plugin-versies.
                'os' => $osList === [] ? '*' : implode(', ', $osList),
                'group' => $groupNames === [] ? '*' : implode(', ', $groupNames),
                'group_id' => $groupIds[0] ?? 0,
                'checks' => $this->normalizeChecks($rule),
            ];
        }

        return $out;
    }

    /**
     * Haalt de OS-lijst uit een regel. Ondersteunt de nieuwe 'os_list' (array)
     * en valt terug op het oude enkele 'os' (of komma-gescheiden). Een lege
     * lijst betekent "alle OS'en" ('*').
     *
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    private function normalizeOsList(array $rule): array
    {
        $list = [];

        if (isset($rule['os_list']) && is_array($rule['os_list'])) {
            $list = $rule['os_list'];
        } elseif (isset($rule['os'])) {
            $list = explode(',', (string) $rule['os']);
        }

        $list = array_filter(
            array_map(fn ($v) => trim((string) $v), $list),
            fn ($v) => $v !== '' && $v !== '*'
        );

        return array_values(array_unique($list));
    }

    /**
     * Haalt de groepen uit een regel als [ids, namen]. Voorkeur voor de
     * nieuwe 'group_ids' (array, rename-safe); anders 'group_names' of het
     * oude enkele 'group'. Namen worden waar mogelijk naar de actuele naam
     * (via id) omgezet. Lege lijsten betekenen "alle groepen".
     *
     * @param  array<string, mixed>  $rule
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function normalizeGroups(array $rule): array
    {
        $maps = $this->groupMaps();
        $ids = [];
        $names = [];

        if (isset($rule['group_ids']) && is_array($rule['group_ids']) && $rule['group_ids'] !== []) {
            foreach ($rule['group_ids'] as $gid) {
                $gid = (int) $gid;
                if ($gid > 0) {
                    $ids[] = $gid;
                    if (isset($maps['byId'][$gid])) {
                        $names[] = $maps['byId'][$gid];
                    }
                }
            }
        } else {
            $rawNames = [];

            if (isset($rule['group_names']) && is_array($rule['group_names'])) {
                $rawNames = $rule['group_names'];
            } elseif (isset($rule['group'])) {
                $rawNames = explode(',', (string) $rule['group']);
            }

            foreach ($rawNames as $n) {
                $n = trim((string) $n);
                if ($n === '' || $n === '*') {
                    continue;
                }

                if (isset($maps['byName'][$n])) {
                    $ids[] = $maps['byName'][$n];
                }
                $names[] = $n;
            }
        }

        return [
            array_values(array_unique(array_map('intval', $ids))),
            array_values(array_unique($names)),
        ];
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
        $valid = ['contains', 'not_contains', 'contains_any', 'contains_none', 'matches', 'not_matches'];

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

        if ($type === 'matches' || $type === 'not_matches') {
            // Regex-check met multiline-modus ('m'): ^ en $ ankeren per regel,
            // wat intuïtief is voor regel-georiënteerde netwerkconfigs. Wie het
            // absolute begin/einde van de config wil, gebruikt \A en \z.
            // Een ongeldige regex laten we de check altijd laten FALEN, zodat
            // het opvalt (device wordt non-compliant) en de scan nooit crasht.
            $regex = '~' . str_replace('~', '\~', $pattern) . '~m';
            $result = @preg_match($regex, $config);

            if ($result === false) {
                Log::warning('config-compliance: invalid regex skipped: ' . $pattern);

                return false;
            }

            $found = $result === 1;

            return $type === 'matches' ? $found : ! $found;
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

            // De editor stuurt os_list (namen) en group_names. We normaliseren
            // beide met dezelfde helpers als bij het lezen: OS als lijst, en
            // groepen naar id's (rename-safe) plus de bijbehorende namen.
            $osList = $this->normalizeOsList($rule);
            [$groupIds, $groupNames] = $this->normalizeGroups($rule);

            $clean[] = [
                'name' => $name !== '' ? $name : $checks[0]['pattern'],
                'os_list' => $osList,
                'group_ids' => $groupIds,
                'group_names' => $groupNames,
                // Afgeleide enkelvoudige velden voor backward-compat.
                'os' => $osList === [] ? '*' : implode(', ', $osList),
                'group' => $groupNames === [] ? '*' : implode(', ', $groupNames),
                'group_id' => $groupIds[0] ?? 0,
                'checks' => $checks,
            ];
        }

        $this->writeJson('rules.json', $clean);
    }

    /**
     * Eenmalige, idempotente migratie: vult bij bestaande regels de group_id
     * aan op basis van hun huidige groepsnaam, en ververst de opgeslagen naam
     * als een groep inmiddels is hernoemd. Wordt aangeroepen bij het openen
     * van de plugin-pagina. Schrijft alleen weg als er echt iets verandert.
     */
    public function migrateGroupIds(): void
    {
        $data = $this->readJson('rules.json');

        if (! is_array($data) || $data === []) {
            return;
        }

        $changed = false;

        foreach ($data as $i => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            // Normaliseer naar de lijst-vorm (os_list / group_ids / group_names)
            // met de actuele groepsnamen. Voor oudere regels vult dit de
            // ontbrekende velden aan; bij hernoemde groepen ververst het de naam.
            $osList = $this->normalizeOsList($rule);
            [$groupIds, $groupNames] = $this->normalizeGroups($rule);

            $normalized = [
                'os_list' => $osList,
                'group_ids' => $groupIds,
                'group_names' => $groupNames,
                'os' => $osList === [] ? '*' : implode(', ', $osList),
                'group' => $groupNames === [] ? '*' : implode(', ', $groupNames),
                'group_id' => $groupIds[0] ?? 0,
            ];

            foreach ($normalized as $key => $value) {
                if (! array_key_exists($key, $rule) || $rule[$key] !== $value) {
                    $data[$i][$key] = $value;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->writeJson('rules.json', $data);
        }
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
     * Bouwt een vertaaltabel tussen device-groep-id's en hun huidige naam.
     * Hiermee kunnen regels naar id verwijzen (stabiel) terwijl we voor de
     * weergave altijd de actuele naam tonen — ook nadat een groep hernoemd is.
     *
     * @return array{byId: array<int, string>, byName: array<string, int>}
     */
    private function groupMaps(): array
    {
        static $maps = null;

        if ($maps !== null) {
            return $maps;
        }

        $byId = [];
        $byName = [];

        foreach (DeviceGroup::get(['id', 'name']) as $g) {
            $byId[(int) $g->id] = (string) $g->name;
            $byName[(string) $g->name] = (int) $g->id;
        }

        return $maps = ['byId' => $byId, 'byName' => $byName];
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
    public const VERSION = '1.12.0';

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
            // De groepen waar dit device lid van is, op naam én op id.
            $deviceGroups = $device->groups->pluck('name')->all();
            $deviceGroupIds = $device->groups->pluck('id')->map(fn ($v) => (int) $v)->all();

            // Een regel telt mee als zowel het OS-filter als het groep-filter
            // past. Beide zijn nu lijsten: een lege lijst betekent "alles".
            // OS matcht als het device-OS in os_list zit (OR). Groepen matchen
            // als het device in minstens één van de group_ids zit (OR, op id =
            // rename-safe), met terugval op namen voor oudere regels. Regels
            // zonder checks tellen niet mee.
            $matched = array_values(array_filter($rules, function ($rule) use ($device, $deviceGroups, $deviceGroupIds): bool {
                if (empty($rule['checks'])) {
                    return false;
                }

                $osList = $rule['os_list'] ?? [];
                $groupIds = $rule['group_ids'] ?? [];
                $groupNames = $rule['group_names'] ?? [];

                $osOk = $osList === [] || in_array($device->os, $osList, true);

                if ($groupIds === [] && $groupNames === []) {
                    $groupOk = true;
                } elseif ($groupIds !== []) {
                    $groupOk = array_intersect($groupIds, $deviceGroupIds) !== [];
                } else {
                    $groupOk = array_intersect($groupNames, $deviceGroups) !== [];
                }

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
        $this->syncComponents($results);

        return $report;
    }

    /**
     * Schrijft per device een LibreNMS-component met de compliance-status,
     * zodat er gewone LibreNMS alert rules op gemaakt kunnen worden
     * (components.type = "config-compliance").
     *
     * Statuscodes: 0 = compliant / geen regels, 1 = geen config in Oxidized
     * (waarschuwing), 2 = non-compliant (kritiek). Defensief opgezet: een
     * fout hier mag nooit de scan zelf laten mislukken.
     */
    private function syncComponents(array $results): void
    {
        try {
            if (! class_exists(\App\Models\Component::class)) {
                return; // oudere LibreNMS zonder Component-model: stilletjes overslaan
            }

            $deviceIds = [];

            foreach ($results as $r) {
                if (empty($r['device_id'])) {
                    continue;
                }

                $deviceIds[] = (int) $r['device_id'];

                $status = 0;
                $error = '';

                if (($r['status'] ?? '') === 'non_compliant') {
                    $status = 2;
                    $failed = array_map(
                        fn ($f) => (string) ($f['name'] ?? ''),
                        $r['failed_rules'] ?? []
                    );
                    $error = 'Failed rules: ' . implode(', ', array_filter($failed));
                } elseif (($r['status'] ?? '') === 'no_config') {
                    $status = 1;
                    $error = 'No config found in Oxidized';
                }

                $component = \App\Models\Component::query()
                    ->where('device_id', $r['device_id'])
                    ->where('type', 'config-compliance')
                    ->first();

                if (! $component) {
                    $component = new \App\Models\Component;
                    $component->device_id = (int) $r['device_id'];
                    $component->type = 'config-compliance';
                }

                $component->label = 'Config compliance';
                $component->status = $status;
                $component->error = $error;
                $component->save();
            }

            // Componenten opruimen van devices die niet meer in de scan zitten.
            if ($deviceIds !== []) {
                \App\Models\Component::query()
                    ->where('type', 'config-compliance')
                    ->whereNotIn('device_id', $deviceIds)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('config-compliance: component sync failed: ' . $e->getMessage());
        }
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
