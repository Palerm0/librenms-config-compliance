{{-- Read-only rule overview at the top of a group tab.
     Expects: $ownRules and $generalRules (arrays of rules).
     A rule with more than one check gets the check count appended
     (e.g. "NTP, 3 checks"). All logic is kept in separate php blocks,
     so there are no collapsed conditional directives inside the HTML. --}}
<div class="panel panel-default" style="margin:8px 8px 12px 8px;">
    <div class="panel-body" style="padding:8px 12px;">
        @if(empty($ownRules) && empty($generalRules))
            <span class="text-muted">No rules for this group.</span>
        @else
            @if(!empty($ownRules))
                <div>
                    <strong>Own rules:</strong>
                    @foreach($ownRules as $r)
                        @php
                            $cc = count($r['checks'] ?? []);
                            $label = $r['name'];
                            if (($r['os'] ?? '*') !== '*') {
                                $label .= ' (' . $r['os'] . ')';
                            }
                            if ($cc > 1) {
                                $label .= ' - ' . $cc . ' checks';
                            }
                        @endphp
                        <span class="label label-info" style="margin-right:3px;">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
            @if(!empty($generalRules))
                @php $marginTop = !empty($ownRules) ? 'margin-top:4px;' : ''; @endphp
                <div style="{{ $marginTop }}">
                    <strong>General rules:</strong>
                    @foreach($generalRules as $r)
                        @php
                            $cc = count($r['checks'] ?? []);
                            $label = $r['name'];
                            if (($r['os'] ?? '*') !== '*') {
                                $label .= ' (' . $r['os'] . ')';
                            }
                            if ($cc > 1) {
                                $label .= ' - ' . $cc . ' checks';
                            }
                        @endphp
                        <span class="label label-default" style="margin-right:3px;">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
