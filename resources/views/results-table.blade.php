{{-- Reusable results table. Expects an array $devices and a string $tabKey
     (used to make collapse IDs unique, since a device can appear in several
     tabs).

     Badge colours:
       - "Failed checks" per device: green 0 / orange 1-5 / red more than 5
       - per failed rule: orange = partly failed / red = all checks failed

     The device name links to the device page in LibreNMS. Each failed rule
     can be expanded to show exactly which checks failed. --}}
@php $tabKey = $tabKey ?? 'x'; @endphp
@php
    // Samenvatting van deze tab: tel de statussen, zodat we boven de
    // (ingeklapte) lijst meteen zien hoe het ervoor staat.
    $sumC = 0; $sumN = 0; $sumNoCfg = 0; $sumNoRules = 0;
    foreach ($devices as $d) {
        $st = $d['status'] ?? '';
        if ($st === 'compliant') {
            $sumC++;
        } elseif ($st === 'non_compliant') {
            $sumN++;
        } elseif ($st === 'no_config') {
            $sumNoCfg++;
        } else {
            $sumNoRules++;
        }
    }
    $devCount = count($devices);
    $devCollapseId = 'cc-devs-' . $tabKey;
@endphp

{{-- Samenvattingsregel: altijd zichtbaar, klikbaar om de lijst te tonen. --}}
<div class="cc-dev-toggle" data-toggle="collapse" data-target="#{{ $devCollapseId }}"
     style="cursor:pointer; padding:10px 12px; display:flex; align-items:center;
            gap:8px; flex-wrap:wrap;">
    <i class="fa fa-caret-right" id="{{ $devCollapseId }}-caret"></i>
    <strong>{{ $devCount }} {{ $devCount === 1 ? 'device' : 'devices' }}</strong>
    <span class="label label-success">{{ $sumC }} compliant</span>
    <span class="label label-danger">{{ $sumN }} non-compliant</span>
    @if($sumNoCfg > 0)
        <span class="label label-warning">{{ $sumNoCfg }} no config</span>
    @endif
    @if($sumNoRules > 0)
        <span class="label label-default">{{ $sumNoRules }} no rules</span>
    @endif
    <span class="text-muted" style="margin-left:auto; font-size:90%;">click to show / hide</span>
</div>

{{-- De volledige lijst, standaard ingeklapt om scrollen te verminderen. --}}
<div class="collapse cc-dev-collapse" id="{{ $devCollapseId }}">
<table class="table table-hover" style="margin-bottom:0;">
    <thead>
        <tr>
            <th>Device</th>
            <th>Group</th>
            <th>OS</th>
            <th>Status</th>
            <th style="text-align:center;">Rules</th>
            <th style="text-align:center;">Failed checks</th>
            <th>Not passed</th>
        </tr>
    </thead>
    <tbody>
    @forelse($devices as $device)
        @php
            $checksFailed = (int) ($device['checks_failed'] ?? 0);
            $checked = in_array($device['status'], ['compliant', 'non_compliant'], true);

            // Traffic light for the device badge: 0 = green, 1-5 = orange, >5 = red.
            if ($checksFailed === 0) {
                $deviceBadgeColor = '#5cb85c';
            } elseif ($checksFailed <= 5) {
                $deviceBadgeColor = '#f0ad4e';
            } else {
                $deviceBadgeColor = '#d9534f';
            }
        @endphp
        <tr>
            <td>
                @if(!empty($device['device_id']))
                    <a href="{{ url('device/' . $device['device_id']) }}">{{ $device['hostname'] }}</a>
                @else
                    {{ $device['hostname'] }}
                @endif
            </td>
            <td>
                @forelse($device['groups'] ?? [] as $groupName)
                    <span class="label label-default" style="margin-right:3px;">{{ $groupName }}</span>
                @empty
                    <span class="text-muted">&mdash;</span>
                @endforelse
            </td>
            <td><code>{{ $device['os'] }}</code></td>
            <td>
                @if($device['status'] === 'compliant')
                    <span class="label label-success">Compliant</span>
                @elseif($device['status'] === 'non_compliant')
                    <span class="label label-danger">Non-compliant</span>
                @elseif($device['status'] === 'no_config')
                    <span class="label label-warning">No config</span>
                @else
                    <span class="label label-default">No rules</span>
                @endif
                @if(!empty($device['down']))
                    <span class="label" style="background-color:#777;"
                          title="Device is down in LibreNMS">Down</span>
                @endif
            </td>
            <td style="text-align:center;">{{ $device['passed'] }} / {{ $device['total'] }}</td>
            <td style="text-align:center;">
                @if($checked)
                    <span class="badge" title="Number of failed checks across all rules"
                          style="background-color:{{ $deviceBadgeColor }};">{{ $checksFailed }}</span>
                @else
                    <span class="text-muted">&mdash;</span>
                @endif
            </td>
            <td>
                @if(!empty($device['failed_rules']))
                    <ul style="margin:0; padding-left:18px;">
                        @foreach($device['failed_rules'] as $ri => $failed)
                            @php
                                // Fallback for old results (before v1.3.0):
                                // back then failed_rules was a list of names only.
                                $isObj = is_array($failed);
                                $fName = $isObj ? ($failed['name'] ?? '') : $failed;
                                $cTotal = $isObj ? (int) ($failed['checks_total'] ?? 1) : 1;
                                $cFailed = $isObj ? (int) ($failed['checks_failed'] ?? 1) : 1;
                                // Red = all checks failed, orange = partly failed.
                                $ruleBadgeColor = $cFailed >= $cTotal ? '#d9534f' : '#f0ad4e';
                                // Only the failed checks - that is what is "missing".
                                $allChecks = ($isObj && is_array($failed['checks'] ?? null)) ? $failed['checks'] : [];
                                $failedChecks = array_values(array_filter($allChecks, fn ($c) => empty($c['passed'])));
                                $detailId = 'cc-d-' . $tabKey . '-' . ($device['device_id'] ?? 'x') . '-' . $ri;
                            @endphp
                            <li>
                                @if(!empty($failedChecks))
                                    <a role="button" data-toggle="collapse" href="#{{ $detailId }}"
                                       style="text-decoration:none;">
                                        <i class="fa fa-caret-right text-muted"></i> {{ $fName }}</a>
                                @else
                                    {{ $fName }}
                                @endif
                                @if($cTotal > 1)
                                    <span class="badge" title="{{ $cFailed }} of {{ $cTotal }} checks failed"
                                          style="background-color:{{ $ruleBadgeColor }};">{{ $cFailed }}/{{ $cTotal }}</span>
                                @endif
                                @if(!empty($failedChecks))
                                    <div class="collapse" id="{{ $detailId }}">
                                        <ul style="margin:3px 0 6px 0; padding-left:14px; list-style:none;">
                                            @foreach($failedChecks as $c)
                                                @php
                                                    $cType = $c['type'] ?? 'contains';
                                                    if ($cType === 'not_contains') {
                                                        $cLabel = 'not allowed, but present:';
                                                    } elseif ($cType === 'contains_any') {
                                                        $cLabel = 'none of these present:';
                                                    } elseif ($cType === 'contains_none') {
                                                        $cLabel = 'one of these present (not allowed):';
                                                    } else {
                                                        $cLabel = 'missing:';
                                                    }
                                                    // Meerregelige patronen netjes splitsen voor weergave.
                                                    $cPatterns = preg_split('/\r\n|\r|\n/', trim((string) $c['pattern']));
                                                    $cPatterns = array_filter(array_map('trim', $cPatterns), fn ($p) => $p !== '');
                                                @endphp
                                                <li style="color:#d9534f;">
                                                    <i class="fa fa-times"></i>
                                                    <span class="text-muted">{{ $cLabel }}</span>
                                                    @foreach($cPatterns as $p)
                                                        <code>{{ $p }}</code>@if(!$loop->last) <span class="text-muted">/</span> @endif
                                                    @endforeach
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <span class="text-muted">&mdash;</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-muted">No devices in this tab.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
