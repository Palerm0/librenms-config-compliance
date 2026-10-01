{{-- Dashboard-widget: compliance-samenvatting. Wordt door core in de tegel
     geplaatst (via AJAX), dus GEEN eigen <html>/<body> hier — alleen de
     inhoud, met een eigen scoped stijl zodat de badges overal (licht/donker)
     kloppen zonder afhankelijk te zijn van Bootstrap-versies. --}}
@php
    $summary = $report['summary'] ?? [];
    $devices = (int) ($summary['devices'] ?? 0);
    $compliant = (int) ($summary['compliant'] ?? 0);
    $nonCompliant = (int) ($summary['non_compliant'] ?? 0);
    $noConfig = (int) ($summary['no_config'] ?? 0);
    $noRules = (int) ($summary['no_rules'] ?? 0);
    $rules = (int) ($summary['rules'] ?? 0);

    $evaluated = $compliant + $nonCompliant;
    $pct = $evaluated > 0 ? (int) round($compliant / $evaluated * 100) : null;

    if ($pct === null) {
        $pctColor = '#777';
    } elseif ($pct >= 90) {
        $pctColor = '#5cb85c';
    } elseif ($pct >= 70) {
        $pctColor = '#f0ad4e';
    } else {
        $pctColor = '#d9534f';
    }

    $generatedAt = $report['generated_at'] ?? null;
@endphp

<style>
    .cc-widget { padding: 8px 10px; }
    .cc-widget .cc-row {
        display: flex; flex-wrap: wrap; gap: 7px; align-items: center;
    }
    .cc-widget .cc-badge {
        display: inline-block; padding: 4px 9px; border-radius: 4px;
        font-size: 13px; font-weight: 600; color: #fff; line-height: 1.3;
        white-space: nowrap;
    }
    .cc-widget .cc-badge.big { font-size: 15px; padding: 5px 11px; }
    .cc-widget .cc-success { background: #5cb85c; }
    .cc-widget .cc-danger  { background: #d9534f; }
    .cc-widget .cc-warning { background: #f0ad4e; }
    .cc-widget .cc-default { background: #777; }
    .cc-widget .cc-scan {
        color: #888; font-size: 12px; margin-left: auto; white-space: nowrap;
    }
    /* De hele balk is een link naar de plugin-pagina. Neutrale opmaak zodat de
       badges hun eigen kleur houden; subtiele hover als klikhint. */
    a.cc-link { display: block; text-decoration: none; color: inherit; }
    a.cc-link:hover, a.cc-link:focus { text-decoration: none; color: inherit; }
    a.cc-link:hover .cc-row { opacity: 0.88; }
</style>

<div class="cc-widget">
    <a class="cc-link" href="{{ $pageUrl }}" title="Open the Config Compliance page">
    <div class="cc-row">
        <span class="cc-badge big" style="background-color: {{ $pctColor }};"
              title="Share of evaluated devices that are compliant. Devices with no rules or no config are not counted.">
            &#9733; @if($pct === null) n/a @else {{ $pct }}% @endif compliant
        </span>
        <span class="cc-badge cc-default">Devices: {{ $devices }}</span>
        <span class="cc-badge cc-success">Compliant: {{ $compliant }}</span>
        <span class="cc-badge cc-danger">Non-compliant: {{ $nonCompliant }}</span>
        <span class="cc-badge cc-warning">No config: {{ $noConfig }}</span>
        <span class="cc-badge cc-default">No rules: {{ $noRules }}</span>
        <span class="cc-badge cc-default">Rules: {{ $rules }}</span>
        <span class="cc-scan">
            @if($generatedAt)
                Last scan: {{ $generatedAt }}
            @else
                No scan yet
            @endif
        </span>
    </div>
    </a>
</div>
