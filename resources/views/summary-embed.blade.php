{{-- Zelfstandige, kale samenvattingsweergave voor in een dashboard-widget
     (via een <iframe> in een LibreNMS Notes-widget). Bewust GEEN LibreNMS-
     layout: eigen, minimale HTML + inline CSS, zodat het los in een iframe
     correct rendert op zowel een licht als donker dashboard.

     Ververst zichzelf elke 5 minuten zodat de tegel actueel blijft. --}}
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Ververs de tegel periodiek zodat hij meeloopt met nieuwe scans. --}}
    <meta http-equiv="refresh" content="300">
    <title>Config Compliance summary</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            background: transparent;
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .cc-embed {
            padding: 10px 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .cc-badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            line-height: 1.3;
            white-space: nowrap;
        }
        .cc-badge.big { font-size: 15px; padding: 5px 11px; }
        .cc-success { background: #5cb85c; }
        .cc-danger  { background: #d9534f; }
        .cc-warning { background: #f0ad4e; }
        .cc-default { background: #777; }
        .cc-scan {
            /* Leesbaar op zowel licht als donker: gedempt grijs. */
            color: #888;
            font-size: 12px;
            margin-left: auto;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="cc-embed">
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
        @if($generatedAt)
            <span class="cc-scan">Last scan: {{ $generatedAt }}</span>
        @else
            <span class="cc-scan">No scan yet</span>
        @endif
    </div>
</body>
</html>
