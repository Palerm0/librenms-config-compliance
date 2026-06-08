@extends('layouts.librenmsv1')

@section('content')
<div class="container-fluid" style="margin-top: 15px;">

    {{-- Brede, links uitgelijnde tooltip voor de type-uitleg, zodat elk
         type op één regel past in plaats van in een smal kolommetje. --}}
    <style>
        .cc-wide-tip .tooltip-inner {
            max-width: none;
            white-space: nowrap;
            text-align: left;
        }
    </style>

    {{-- Gear button: opens the settings modal at the bottom of this page. --}}
    <button type="button" class="btn btn-default btn-sm pull-right"
            data-toggle="modal" data-target="#cc-settings-modal" style="margin-top:4px;">
        <i class="fa fa-cog"></i> Settings
    </button>

    <h2 style="margin-top:0;">
        <i class="fa fa-check-square-o"></i> Config Compliance
        <small style="font-size:60%;">v{{ $version }}</small>
    </h2>
    <p class="text-muted">
        Checks per device whether certain rules are present or absent in the
        Oxidized backup. Read-only &mdash; this plugin does not change anything
        on your devices.
    </p>

    {{-- Oxidized status: quiet when fine, prominent when something is wrong. --}}
    @php $ox = $oxidized ?? ['state' => 'unconfigured', 'url' => '', 'node_count' => null]; @endphp
    @if($ox['state'] === 'ok')
        @php
            $oxText = 'Oxidized reachable';
            if (! is_null($ox['node_count'])) {
                $oxText .= ' — ' . $ox['node_count'] . ' nodes';
            }
            $oxText .= '.';
        @endphp
        <p class="text-success" style="margin-top:-4px;">
            <i class="fa fa-check-circle"></i> {{ $oxText }}
        </p>
    @elseif($ox['state'] === 'unconfigured')
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle"></i>
            Oxidized is not configured yet. Click the <strong>Settings</strong> gear
            (top right) and enter the Oxidized URL &mdash; without it, scans cannot
            read any device configs.
        </div>
    @else
        <div class="alert alert-danger">
            <i class="fa fa-exclamation-triangle"></i>
            Could not reach Oxidized at <code>{{ $ox['url'] }}</code>. Check that
            Oxidized is running and its REST API is enabled, and that the URL under
            <strong>Settings</strong> is correct.
        </div>
    @endif

    {{-- Status message shown after an action --}}
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- Snelknop: spring naar de regels en klap alle OS-secties in. --}}
    <div style="margin-bottom:10px; text-align:right;">
        <button type="button" class="btn btn-default btn-sm" onclick="goToRules()">
            <i class="fa fa-list-ul"></i> Go to compliance rules
        </button>
    </div>

    {{-- ============ Scan ============ --}}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Scan</strong></div>
        <div class="panel-body">
            <form method="POST" action="{{ route('config-compliance.scan') }}" style="display:inline;">
                @csrf
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-play"></i> Scan now
                </button>
            </form>
            @if($report)
                <span style="margin-left:15px;">
                    Last scan: <strong>{{ $report['generated_at'] }}</strong>
                </span>
            @else
                <span style="margin-left:15px;" class="text-muted">No scan run yet.</span>
            @endif
            <span class="text-muted" style="margin-left:15px;">
                Runs automatically every day via cron (see README).
            </span>
        </div>

        @if($report)
        @php
            // Compliance-score: percentage van de geëvalueerde devices
            // (compliant + non-compliant) dat compliant is. Devices zonder
            // regels of zonder config tellen niet mee — die zijn niet getoetst.
            $ccCompliant = (int) $report['summary']['compliant'];
            $ccNon = (int) $report['summary']['non_compliant'];
            $ccEvaluated = $ccCompliant + $ccNon;
            $ccPct = $ccEvaluated > 0 ? (int) round($ccCompliant / $ccEvaluated * 100) : null;

            // Kleur op basis van de score.
            if ($ccPct === null) {
                $ccPctColor = '#777';
            } elseif ($ccPct >= 90) {
                $ccPctColor = '#5cb85c';
            } elseif ($ccPct >= 70) {
                $ccPctColor = '#f0ad4e';
            } else {
                $ccPctColor = '#d9534f';
            }
        @endphp
        <div class="panel-body" style="border-top:1px solid #ddd;">
            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
            <span class="label" style="font-size:120%; background-color:{{ $ccPctColor }};"
                  title="Share of evaluated devices that are compliant. Devices with no rules or no config are not counted.">
                <i class="fa fa-shield"></i>
                @if($ccPct === null) n/a @else {{ $ccPct }}% @endif compliant
            </span>
            <span class="label label-default" style="font-size:95%;">Devices: {{ $report['summary']['devices'] }}</span>
            <span class="label label-success" style="font-size:95%;">Compliant: {{ $report['summary']['compliant'] }}</span>
            <span class="label label-danger" style="font-size:95%; cursor:pointer;"
                  data-toggle="collapse" data-target="#cc-noncompliant-list"
                  title="Click to list the non-compliant devices">
                Non-compliant: {{ $report['summary']['non_compliant'] }}
                <i class="fa fa-caret-down" id="cc-nc-caret" style="margin-left:3px;"></i>
            </span>
            <span class="label label-warning" style="font-size:95%;">No config: {{ $report['summary']['no_config'] }}</span>
            <span class="label label-default" style="font-size:95%;">No rules: {{ $report['summary']['no_rules'] ?? 0 }}</span>
            <span class="label label-default" style="font-size:95%;">Rules: {{ $report['summary']['rules'] }}</span>
            </div>

            {{-- Uitklapbaar overzicht van de niet-conforme devices. --}}
            @php
                $ccNonList = array_values(array_filter(
                    $report['devices'],
                    fn ($d) => ($d['status'] ?? '') === 'non_compliant'
                ));

                // Map groepsnaam -> tab-index, zodat we per device naar de
                // juiste resultaten-tab kunnen springen.
                $ccGroupIndex = [];
                foreach ($groups as $gi => $g) {
                    $ccGroupIndex[$g] = $gi;
                }
            @endphp
            <div class="collapse" id="cc-noncompliant-list" style="margin-top:12px;">
                @if(count($ccNonList) > 0)
                    <table class="table table-condensed table-hover" style="margin-bottom:0;">
                        <thead>
                            <tr><th>Device</th><th>OS</th><th>Failed rules</th></tr>
                        </thead>
                        <tbody>
                        @foreach($ccNonList as $d)
                            @php
                                // Bepaal de doel-tab: de eerste groep van dit
                                // device die een tab heeft, anders de All-tab.
                                $ccTabHref = '#cc-tab-all';
                                $ccCollId = 'cc-devs-all';
                                foreach ($d['groups'] ?? [] as $gname) {
                                    if (isset($ccGroupIndex[$gname])) {
                                        $ccTabHref = '#cc-tab-' . $ccGroupIndex[$gname];
                                        $ccCollId = 'cc-devs-g' . $ccGroupIndex[$gname];
                                        break;
                                    }
                                }
                            @endphp
                            <tr>
                                <td>
                                    @if(!empty($d['device_id']))
                                        <a href="{{ url('device/' . $d['device_id']) }}">{{ $d['hostname'] }}</a>
                                    @else
                                        {{ $d['hostname'] }}
                                    @endif
                                    @if(!empty($d['down']))
                                        <span class="label" style="background-color:#777;"
                                              title="Device is down in LibreNMS">Down</span>
                                    @endif
                                </td>
                                <td><code>{{ $d['os'] ?? '' }}</code></td>
                                <td>
                                    @foreach($d['failed_rules'] ?? [] as $fr)
                                        <a href="javascript:void(0)" class="label label-danger"
                                           style="margin-right:3px; cursor:pointer;"
                                           title="Go to this device's group in the results"
                                           onclick="ccGotoGroup('{{ $ccTabHref }}','{{ $ccCollId }}')">{{ $fr['name'] }}</a>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <span class="text-muted">No non-compliant devices &mdash; nice.</span>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- ============ Results ============ --}}
    @if($report && count($report['devices']))
    @php
        // Split devices per group for the tabs.
        $allDevices = $report['devices'];
        $byGroup = [];
        foreach ($groups as $g) {
            $byGroup[$g] = [];
        }
        $ungrouped = [];
        foreach ($allDevices as $d) {
            $dg = $d['groups'] ?? [];
            if (empty($dg)) {
                $ungrouped[] = $d;
            }
            foreach ($dg as $g) {
                if (isset($byGroup[$g])) {
                    $byGroup[$g][] = $d;
                }
            }
        }

        // General rules (group '*') apply to every group.
        $generalRules = array_values(array_filter($rules, fn ($r) => ($r['group'] ?? '*') === '*'));

        // Sums the failed checks across a list of devices (for the tab markers).
        $sumChecksFailed = function ($devs) {
            $n = 0;
            foreach ($devs as $d) {
                $n += (int) ($d['checks_failed'] ?? 0);
            }
            return $n;
        };

        // Traffic-light colour: 0 = green, 1-5 = orange, more than 5 = red.
        $tabBadgeColor = function ($n) {
            if ($n <= 0) {
                return '#5cb85c';
            }
            if ($n <= 5) {
                return '#f0ad4e';
            }
            return '#d9534f';
        };
    @endphp
    <div class="panel panel-default" id="cc-results-panel">
        <div class="panel-heading"><strong>Results</strong></div>

        {{-- The tabs look normal; a thin coloured line at the bottom shows the
             health of the group (green/orange/red based on the total failed
             checks). The number is the device count in that tab; the exact
             failed-check count is in the tooltip. --}}
        <ul class="nav nav-tabs" style="padding:8px 8px 0 8px;">
            @php $cfAll = $sumChecksFailed($allDevices); @endphp
            <li class="active">
                <a href="#cc-tab-all" data-toggle="tab"
                   title="Failed checks in this tab: {{ $cfAll }}"
                   style="box-shadow: inset 0 -3px 0 {{ $tabBadgeColor($cfAll) }};">All
                    <span class="badge">{{ count($allDevices) }}</span></a>
            </li>
            @foreach($groups as $gi => $g)
                @php $cfGroup = $sumChecksFailed($byGroup[$g]); @endphp
                <li>
                    <a href="#cc-tab-{{ $gi }}" data-toggle="tab"
                       title="Failed checks in this group: {{ $cfGroup }}"
                       style="box-shadow: inset 0 -3px 0 {{ $tabBadgeColor($cfGroup) }};">{{ $g }}
                        <span class="badge">{{ count($byGroup[$g]) }}</span></a>
                </li>
            @endforeach
            @if(!empty($groups) && !empty($ungrouped))
                @php $cfNone = $sumChecksFailed($ungrouped); @endphp
                <li>
                    <a href="#cc-tab-none" data-toggle="tab"
                       title="Failed checks ungrouped: {{ $cfNone }}"
                       style="box-shadow: inset 0 -3px 0 {{ $tabBadgeColor($cfNone) }};">Ungrouped
                        <span class="badge">{{ count($ungrouped) }}</span></a>
                </li>
            @endif
        </ul>

        <div class="tab-content">
            <div class="tab-pane active" id="cc-tab-all">
                @include('config-compliance::results-table', ['devices' => $allDevices, 'tabKey' => 'all'])
            </div>
            @foreach($groups as $gi => $g)
                @php
                    $ownRules = array_values(array_filter($rules, fn ($r) => ($r['group'] ?? '*') === $g));
                @endphp
                <div class="tab-pane" id="cc-tab-{{ $gi }}">
                    @include('config-compliance::rules-overview', ['ownRules' => $ownRules, 'generalRules' => $generalRules])
                    @include('config-compliance::results-table', ['devices' => $byGroup[$g], 'tabKey' => 'g' . $gi])
                </div>
            @endforeach
            @if(!empty($groups) && !empty($ungrouped))
                <div class="tab-pane" id="cc-tab-none">
                    @include('config-compliance::rules-overview', ['ownRules' => [], 'generalRules' => $generalRules])
                    @include('config-compliance::results-table', ['devices' => $ungrouped, 'tabKey' => 'none'])
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Pijltje van de device-lijsten laten meedraaien bij open/dicht. --}}
    <script>
        // Springt naar de resultaten-tab van een groep, klapt de devicelijst
        // open en scrollt ernaartoe. Aangeroepen vanuit de non-compliant-lijst.
        function ccGotoGroup(tabHref, collapseId) {
            if (window.jQuery) {
                jQuery('a[href="' + tabHref + '"]').tab('show');
                jQuery('#' + collapseId).collapse('show');
            }
            var res = document.getElementById('cc-results-panel');
            if (res) {
                res.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        (function () {
            if (!window.jQuery) {
                return;
            }
            jQuery('.cc-dev-collapse')
                .on('show.bs.collapse', function () {
                    var c = document.getElementById(this.id + '-caret');
                    if (c) { c.className = 'fa fa-caret-down'; }
                })
                .on('hide.bs.collapse', function () {
                    var c = document.getElementById(this.id + '-caret');
                    if (c) { c.className = 'fa fa-caret-right'; }
                });

            // Pijltje naast "Non-compliant" in de scanbalk.
            var ncCaret = document.getElementById('cc-nc-caret');
            jQuery('#cc-noncompliant-list')
                .on('show.bs.collapse', function () {
                    if (ncCaret) { ncCaret.className = 'fa fa-caret-up'; }
                })
                .on('hide.bs.collapse', function () {
                    if (ncCaret) { ncCaret.className = 'fa fa-caret-down'; }
                });
        })();
    </script>

    {{-- ============ Rules ============ --}}
    {{-- De heading is klikbaar en vouwt het regels-paneel open/dicht.
         Standaard ingeklapt, zodat het scherm rustig blijft. --}}
    <div class="panel panel-default" id="cc-rules-panel">
        <div class="panel-heading" style="cursor:pointer;"
             data-toggle="collapse" data-target="#cc-rules-body">
            <i class="fa fa-caret-right" id="cc-rules-caret"></i>
            <strong>Compliance rules</strong>
        </div>
        <div class="panel-body collapse" id="cc-rules-body">
            <p class="text-muted">
                A rule applies to a device when both <strong>Group</strong> and
                <strong>OS</strong> match; <code>*</code> means "all" in each case.
                Every rule has one or more <strong>checks</strong>. For each check
                you pick <strong>Contains</strong> (passes if the pattern is in the
                config) or <strong>Does not contain</strong> (passes if the pattern
                is absent). The rule as a whole passes only if <strong>all</strong>
                checks pass. The grey circle shows the number of checks.
            </p>

            <div id="rules-container"></div>

            <button type="button" class="btn btn-default btn-sm" onclick="addRule()">
                <i class="fa fa-plus"></i> Add rule
            </button>

            <form method="POST" action="{{ route('config-compliance.rules') }}"
                  style="display:inline;" id="rules-form">
                @csrf
                <input type="hidden" name="rules" id="rules-json">
                <button type="submit" class="btn btn-primary btn-sm" onclick="rememberRulesView(); serializeRules();">
                    <i class="fa fa-save"></i> Save rules
                </button>
            </form>
        </div>
    </div>

    {{-- Pijltje meedraaien met open/dicht van het regels-paneel. --}}
    <script>
        (function () {
            var body = document.getElementById('cc-rules-body');
            var caret = document.getElementById('cc-rules-caret');
            if (!body || !caret) {
                return;
            }
            // Bootstrap 3 collapse-events: wissel het Font Awesome-icoon.
            $(body).on('show.bs.collapse', function () {
                caret.className = 'fa fa-caret-down';
            });
            $(body).on('hide.bs.collapse', function () {
                caret.className = 'fa fa-caret-right';
            });
        })();
    </script>

</div>

{{-- ============ Settings modal (opened by the gear button) ============ --}}
<div class="modal fade" id="cc-settings-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('config-compliance.settings') }}">
                @csrf
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title"><i class="fa fa-cog"></i> Settings</h4>
                </div>
                <div class="modal-body">
                    <label for="cc-oxidized-url">Oxidized URL</label>
                    <input type="text" id="cc-oxidized-url" name="oxidized_url" class="form-control"
                           placeholder="http://127.0.0.1:8888"
                           value="{{ $settings['oxidized_url'] ?? '' }}">
                    <p class="text-muted" style="margin-top:8px;">
                        The same URL as under <em>Global Settings &raquo; External Settings
                        &raquo; Oxidized</em>, without a trailing slash.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Rules are managed client-side and posted as JSON on "save".
    // Rule shape: { name, group, os, checks: [ { type, pattern }, ... ] }
    let rules = @json($rules);

    // Safety: make sure every rule has a checks array.
    rules.forEach(function (rule) {
        if (!Array.isArray(rule.checks)) {
            rule.checks = [];
        }
    });

    // Options for the dropdowns, '*' always first.
    const groupOptions = ['*'].concat(@json($groups));
    const osOptions = ['*'].concat(@json($osList));

    function escapeAttr(value) {
        return String(value || '').replace(/"/g, '&quot;');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Builds a <select> with all options. 'assignExpr' is the JavaScript run
    // on change (e.g. "rules[0].group = this.value"). An unknown current
    // value (e.g. a deleted group) is kept so it does not silently disappear.
    function buildSelect(options, current, assignExpr) {
        const value = current || '*';
        let opts = options.slice();
        if (opts.indexOf(value) === -1) {
            opts.unshift(value);
        }
        let html = '<select class="form-control input-sm" onchange="' + assignExpr + '">';
        opts.forEach(function (option) {
            html += '<option value="' + escapeAttr(option) + '"' +
                (option === value ? ' selected' : '') + '>' + escapeHtml(option) + '</option>';
        });
        return html + '</select>';
    }

    // The type dropdown for a single check. Changing it re-renders so the
    // pattern field can switch between a single line and a multi-line box.
    function buildTypeSelect(current, ri, ci) {
        function opt(val, label) {
            return '<option value="' + val + '"' +
                (current === val ? ' selected' : '') + '>' + label + '</option>';
        }
        return '<select class="form-control input-sm"' +
            ' onchange="onCheckTypeChange(' + ri + ',' + ci + ',this.value)">' +
            opt('contains', 'Contains') +
            opt('not_contains', 'Does not contain') +
            opt('contains_any', 'Contains any of') +
            opt('contains_none', 'Contains none of') +
            opt('matches', 'Matches regex') +
            opt('not_matches', 'Does not match regex') +
            '</select>';
    }

    // True for the multi-pattern types (one pattern per line).
    function isMultiType(type) {
        return type === 'contains_any' || type === 'contains_none';
    }

    // True for the regular-expression types.
    function isRegexType(type) {
        return type === 'matches' || type === 'not_matches';
    }

    // Live check that a regex compiles; flags the field red if it doesn't.
    // (JavaScript regex is not identical to PCRE, but catches the common
    //  syntax errors so the user gets feedback before saving.)
    function validateRegexField(el) {
        let ok = true;
        if (el.value !== '') {
            try { new RegExp(el.value); } catch (e) { ok = false; }
        }
        el.style.borderColor = ok ? '' : '#d9534f';
        const hint = el.parentNode.querySelector('.cc-regex-hint');
        if (hint) { hint.style.display = ok ? 'none' : 'block'; }
    }

    function onCheckTypeChange(ri, ci, value) {
        rules[ri].checks[ci].type = value;
        // Hertekenen zodat het invoerveld meedraait (enkel <-> meerregelig).
        renderRules();
    }

    // Builds the check rows of a single rule.
    function renderChecks(rule, ri) {
        if (rule.checks.length === 0) {
            return '<tr><td colspan="3" class="text-muted">' +
                'No checks yet. Click "Add check".</td></tr>';
        }

        let html = '';
        rule.checks.forEach(function (check, ci) {
            const assign = 'rules[' + ri + '].checks[' + ci + '].pattern = this.value';
            let patternCell;
            if (isMultiType(check.type)) {
                // Meerregelig: één patroon per regel; slaagt op één match (any)
                // of faalt op één match (none). Het vak groeit mee met de
                // hoeveelheid regels (zie autoGrow hieronder).
                patternCell =
                    '<textarea class="form-control input-sm cc-grow" rows="2"' +
                    ' placeholder="One pattern per line — any of these"' +
                    ' oninput="autoGrow(this)"' +
                    ' onchange="' + assign + '">' + escapeHtml(check.pattern) + '</textarea>';
            } else if (isRegexType(check.type)) {
                patternCell =
                    '<input class="form-control input-sm cc-regex" value="' + escapeAttr(check.pattern) + '"' +
                    ' placeholder="Regular expression, e.g. spanning-tree \\d+ bpdu-protection"' +
                    ' oninput="validateRegexField(this)"' +
                    ' onchange="' + assign + '">' +
                    '<span class="cc-regex-hint text-danger" style="display:none; font-size:11px;">' +
                    'Invalid regular expression</span>';
            } else {
                patternCell =
                    '<input class="form-control input-sm" value="' + escapeAttr(check.pattern) + '"' +
                    ' placeholder="Text to search for in the config"' +
                    ' onchange="' + assign + '">';
            }
            html +=
                '<tr>' +
                '<td>' + buildTypeSelect(check.type, ri, ci) + '</td>' +
                '<td>' + patternCell + '</td>' +
                '<td><button type="button" class="btn btn-danger btn-xs"' +
                    ' onclick="removeCheck(' + ri + ',' + ci + ')"><i class="fa fa-trash"></i></button></td>' +
                '</tr>';
        });
        return html;
    }

    // Houdt bij welke OS-secties ingeklapt zijn (op OS-waarde), zodat
    // openen/sluiten bewaard blijft als de lijst opnieuw getekend wordt.
    let collapsedOs = {};

    // Idem voor de groep-subsecties binnen een OS. Sleutel = os + scheiding +
    // groep. Een null-teken als scheiding, want dat komt nooit in een naam voor.
    let collapsedGroup = {};
    function groupKey(os, group) {
        return os + '\u0000' + group;
    }

    // Bouwt het paneel van één regel. 'ri' is de index in de globale rules-array.
    function buildRulePanel(rule, ri) {
        const panel = document.createElement('div');
        panel.className = 'panel panel-default';
        panel.style.marginBottom = '10px';
        panel.innerHTML =
            '<div class="panel-heading" style="padding:8px 10px;">' +
              '<div class="row">' +
                '<div class="col-sm-4">' +
                  '<input class="form-control input-sm" placeholder="Rule name"' +
                    ' value="' + escapeAttr(rule.name) + '"' +
                    ' onchange="rules[' + ri + '].name = this.value"></div>' +
                '<div class="col-sm-3">' +
                  buildSelect(groupOptions, rule.group, 'onRuleFieldChange(' + ri + ', \'group\', this.value)') + '</div>' +
                '<div class="col-sm-3">' +
                  buildSelect(osOptions, rule.os, 'onRuleFieldChange(' + ri + ', \'os\', this.value)') + '</div>' +
                '<div class="col-sm-2" style="text-align:right; padding-top:4px;">' +
                  '<span class="badge" title="Number of checks in this rule">' +
                    rule.checks.length + '</span> ' +
                  '<button type="button" class="btn btn-danger btn-xs"' +
                    ' title="Delete rule"' +
                    ' onclick="removeRule(' + ri + ')"><i class="fa fa-trash"></i></button>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div class="panel-body" style="padding:8px 10px;">' +
              '<table class="table" style="margin-bottom:6px;">' +
                '<thead><tr>' +
                  '<th style="width:16%;">Type ' +
                    '<i class="fa fa-question-circle text-muted" style="cursor:help;"' +
                    ' data-toggle="tooltip" data-html="true" data-placement="top"' +
                    ' title="' +
                      '<b>Contains</b> &mdash; pattern must be in the config<br>' +
                      '<b>Does not contain</b> &mdash; pattern must be absent<br>' +
                      '<b>Contains any of</b> &mdash; one pattern per line; passes if at least one is present<br>' +
                      '<b>Contains none of</b> &mdash; one pattern per line; passes if none are present<br>' +
                      '<b>Matches regex</b> &mdash; passes if the regular expression matches the config<br>' +
                      '<b>Does not match regex</b> &mdash; passes if the regular expression does not match' +
                    '"></i>' +
                  '</th>' +
                  '<th>Pattern</th>' +
                  '<th style="width:34px;"></th>' +
                '</tr></thead>' +
                '<tbody>' + renderChecks(rule, ri) + '</tbody>' +
              '</table>' +
              '<button type="button" class="btn btn-default btn-xs" onclick="addCheck(' + ri + ')">' +
                '<i class="fa fa-plus"></i> Add check</button>' +
            '</div>';
        return panel;
    }

    // Wijzigt OS of groep van een regel. Bij beide hertekenen we, zodat de
    // regel meteen onder de juiste OS- én groep-sectie springt.
    function onRuleFieldChange(ri, field, value) {
        rules[ri][field] = value;
        if (field === 'os' || field === 'group') {
            renderRules();
        }
    }

    function toggleOsSection(osValue) {
        collapsedOs[osValue] = !collapsedOs[osValue];
        renderRules();
    }

    function toggleGroupSection(os, group) {
        const k = groupKey(os, group);
        collapsedGroup[k] = !collapsedGroup[k];
        renderRules();
    }

    function renderRules() {
        const container = document.getElementById('rules-container');
        container.innerHTML = '';

        if (rules.length === 0) {
            container.innerHTML =
                '<p class="text-muted">No rules yet. Click "Add rule".</p>';
            return;
        }

        // Groepeer de regel-indexen per OS-waarde.
        const byOs = {};
        rules.forEach(function (rule, ri) {
            const os = (rule.os || '*');
            if (!byOs[os]) {
                byOs[os] = [];
            }
            byOs[os].push(ri);
        });

        // OS-secties op alfabet, maar '*' (alle OS) altijd onderaan.
        const osKeys = Object.keys(byOs).sort(function (a, b) {
            if (a === '*') return 1;
            if (b === '*') return -1;
            return a.localeCompare(b);
        });

        osKeys.forEach(function (os) {
            const indexes = byOs[os];
            const isCollapsed = !!collapsedOs[os];
            const caret = isCollapsed ? 'fa-caret-right' : 'fa-caret-down';
            const label = (os === '*') ? 'All OS' : os;
            const count = indexes.length + (indexes.length === 1 ? ' rule' : ' rules');

            const section = document.createElement('div');
            section.className = 'panel panel-default';
            section.style.marginBottom = '10px';

            // Kop van de OS-sectie: klikbaar in-/uitklappen, met een eigen
            // "+"-knop om meteen een regel mét dit OS toe te voegen.
            const head = document.createElement('div');
            head.className = 'panel-heading';
            head.style.cssText = 'cursor:pointer; display:flex; align-items:center; gap:8px;';
            head.onclick = function (e) {
                // Klik op de +- of save-knop niet als in-/uitklappen behandelen.
                if (e.target.closest('.cc-add-here') || e.target.closest('.cc-save-here')) {
                    return;
                }
                toggleOsSection(os);
            };
            head.innerHTML =
                '<i class="fa ' + caret + '"></i>' +
                '<code>' + escapeHtml(os) + '</code>' +
                '<span class="text-muted">' + escapeHtml(label === os ? '' : label) + '</span>' +
                '<span class="badge" style="margin-left:auto;">' + indexes.length + '</span>' +
                '<button type="button" class="btn btn-default btn-xs cc-add-here"' +
                  ' style="margin-left:8px;" title="Add a rule for ' + escapeAttr(os) + '"' +
                  ' onclick="addRuleForOs(\'' + escapeAttr(os) + '\')">' +
                  '<i class="fa fa-plus"></i></button>' +
                '<button type="button" class="btn btn-primary btn-xs cc-save-here"' +
                  ' style="margin-left:4px;" title="Save all rules"' +
                  ' onclick="saveAllRules()">' +
                  '<i class="fa fa-save"></i></button>';
            section.appendChild(head);

            // Body: per OS de regels nóg eens groeperen per GROEP, elk als
            // inklapbare subsectie (tweede niveau).
            if (!isCollapsed) {
                const body = document.createElement('div');
                body.className = 'panel-body';
                body.style.padding = '10px';

                const byGroup = {};
                indexes.forEach(function (ri) {
                    const g = (rules[ri].group || '*');
                    if (!byGroup[g]) {
                        byGroup[g] = [];
                    }
                    byGroup[g].push(ri);
                });

                // Groepen op alfabet, '*' (alle groepen) onderaan.
                const groupKeys = Object.keys(byGroup).sort(function (a, b) {
                    if (a === '*') return 1;
                    if (b === '*') return -1;
                    return a.localeCompare(b);
                });

                groupKeys.forEach(function (group) {
                    const gIndexes = byGroup[group];
                    const gCollapsed = !!collapsedGroup[groupKey(os, group)];
                    const gCaret = gCollapsed ? 'fa-caret-right' : 'fa-caret-down';
                    const gLabel = (group === '*') ? 'All groups' : group;

                    const sub = document.createElement('div');
                    sub.className = 'panel panel-default';
                    sub.style.margin = '0 0 8px 0';

                    const gHead = document.createElement('div');
                    gHead.className = 'panel-heading';
                    gHead.style.cssText =
                        'cursor:pointer; padding:6px 10px; display:flex; align-items:center; gap:8px;';
                    gHead.onclick = function (e) {
                        if (e.target.closest('.cc-add-grp')) {
                            return;
                        }
                        toggleGroupSection(os, group);
                    };
                    gHead.innerHTML =
                        '<i class="fa ' + gCaret + '"></i>' +
                        '<i class="fa fa-users text-muted"></i>' +
                        '<span>' + escapeHtml(gLabel) + '</span>' +
                        '<span class="badge" style="margin-left:auto;">' + gIndexes.length + '</span>' +
                        '<button type="button" class="btn btn-default btn-xs cc-add-grp"' +
                          ' style="margin-left:8px;"' +
                          ' title="Add a rule for ' + escapeAttr(os) + ' / ' + escapeAttr(gLabel) + '"' +
                          ' onclick="addRuleForOsGroup(\'' + escapeAttr(os) + '\',\'' + escapeAttr(group) + '\')">' +
                          '<i class="fa fa-plus"></i></button>';
                    sub.appendChild(gHead);

                    if (!gCollapsed) {
                        const gBody = document.createElement('div');
                        gBody.className = 'panel-body';
                        gBody.style.padding = '8px';
                        gIndexes.forEach(function (ri) {
                            gBody.appendChild(buildRulePanel(rules[ri], ri));
                        });
                        sub.appendChild(gBody);
                    }

                    body.appendChild(sub);
                });

                section.appendChild(body);
            }

            container.appendChild(section);
        });

        // Uitleg-wolkjes (tooltips) activeren op de zojuist getekende rijen.
        if (window.jQuery && jQuery.fn.tooltip) {
            jQuery('#rules-container [data-toggle="tooltip"]').tooltip({
                template: '<div class="tooltip cc-wide-tip" role="tooltip">' +
                    '<div class="tooltip-arrow"></div>' +
                    '<div class="tooltip-inner"></div></div>'
            });
        }

        // Meerregelige patroon-vakken laten groeien op basis van hun inhoud.
        document.querySelectorAll('#rules-container textarea.cc-grow').forEach(autoGrow);

        // Regex-velden meteen valideren (rode rand bij ongeldige regex).
        document.querySelectorAll('#rules-container input.cc-regex').forEach(validateRegexField);
    }

    // Past de hoogte van een textarea aan op z'n inhoud, zodat alle ingevoerde
    // patronen zichtbaar zijn zonder dat je in het vak hoeft te scrollen.
    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }

    function addRule() {
        rules.push({
            name: '',
            group: '*',
            os: '*',
            checks: [{ type: 'contains', pattern: '' }]
        });
        // Nieuwe regel krijgt OS '*', dus die sectie even openklappen zodat
        // de gebruiker de zojuist toegevoegde regel meteen ziet.
        collapsedOs['*'] = false;
        renderRules();
    }

    // Voegt meteen een regel toe met een vast OS al ingevuld, vanuit de
    // "+"-knop in een OS-sectiekop. Scheelt het OS achteraf opzoeken.
    function addRuleForOs(os) {
        rules.push({
            name: '',
            group: '*',
            os: os,
            checks: [{ type: 'contains', pattern: '' }]
        });
        // De betreffende sectie openklappen zodat de nieuwe regel zichtbaar is.
        collapsedOs[os] = false;
        renderRules();
    }

    // Voegt een regel toe met OS én groep al ingevuld, vanuit de "+"-knop in
    // een groep-subsectie. Beide secties worden opengeklapt.
    function addRuleForOsGroup(os, group) {
        rules.push({
            name: '',
            group: group,
            os: os,
            checks: [{ type: 'contains', pattern: '' }]
        });
        collapsedOs[os] = false;
        collapsedGroup[groupKey(os, group)] = false;
        renderRules();
    }

    function removeRule(ri) {
        rules.splice(ri, 1);
        renderRules();
    }

    function addCheck(ri) {
        rules[ri].checks.push({ type: 'contains', pattern: '' });
        renderRules();
    }

    function removeCheck(ri, ci) {
        rules[ri].checks.splice(ci, 1);
        renderRules();
    }

    function serializeRules() {
        document.getElementById('rules-json').value = JSON.stringify(rules);
    }

    // Onthoudt vlak vóór het opslaan dat we bij de regels waren, inclusief
    // welke OS-/groep-secties open of dicht stonden. Na het herladen (opslaan
    // herlaadt de pagina) springen we daar weer naartoe in plaats van bovenaan.
    function rememberRulesView() {
        try {
            sessionStorage.setItem('cc-return-to-rules', '1');
            sessionStorage.setItem('cc-collapsed-os', JSON.stringify(collapsedOs));
            sessionStorage.setItem('cc-collapsed-group', JSON.stringify(collapsedGroup));
        } catch (e) {}
    }

    // Slaat alle regels op vanuit een knop buiten het formulier (de save-knop
    // in een OS-sectiekop). Vult het verborgen veld en dient het form in.
    function saveAllRules() {
        rememberRulesView();
        serializeRules();
        document.getElementById('rules-form').submit();
    }

    // Snelknop bovenaan: klap alle OS-secties in, open het regels-paneel
    // en scroll ernaartoe — zodat je met één klik een schoon overzicht hebt.
    function goToRules() {
        rules.forEach(function (r) {
            collapsedOs[r.os || '*'] = true;
            collapsedGroup[groupKey(r.os || '*', r.group || '*')] = true;
        });
        renderRules();
        $('#cc-rules-body').collapse('show');
        const panel = document.getElementById('cc-rules-panel');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Zet alle OS- en groep-secties op ingeklapt. Wordt bij het laden
    // aangeroepen zodat de regel-editor standaard volledig dichtgeklapt opent.
    function collapseAllSections() {
        rules.forEach(function (r) {
            const os = r.os || '*';
            collapsedOs[os] = true;
            collapsedGroup[groupKey(os, r.group || '*')] = true;
        });
    }

    collapseAllSections();
    renderRules();

    // Na een save (de pagina is dan herladen) terugspringen naar de regels,
    // met de secties zoals ze stonden — in plaats van bovenaan beginnen.
    (function restoreRulesView() {
        try {
            if (sessionStorage.getItem('cc-return-to-rules') !== '1') {
                return;
            }
            sessionStorage.removeItem('cc-return-to-rules');

            const co = sessionStorage.getItem('cc-collapsed-os');
            const cg = sessionStorage.getItem('cc-collapsed-group');
            if (co) { collapsedOs = JSON.parse(co); }
            if (cg) { collapsedGroup = JSON.parse(cg); }
            renderRules();

            if (window.jQuery) {
                jQuery('#cc-rules-body').collapse('show');
            }
            setTimeout(function () {
                const panel = document.getElementById('cc-rules-panel');
                if (panel) {
                    panel.scrollIntoView({ block: 'start' });
                }
            }, 350);
        } catch (e) {}
    })();

</script>
@endsection
