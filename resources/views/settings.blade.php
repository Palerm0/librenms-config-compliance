{{--
    settings.blade.php

    Instellingenformulier voor de "Settings"-knop op de pluginbeheer-pagina.
    Toont hetzelfde instellingenformulier als op de plugin-pagina zelf
    (de Oxidized-URL). Als de plugin uitgeschakeld is, zijn de routes niet
    geladen; dan tonen we een nette melding in plaats van een fout.

    GPL-3.0-or-later
--}}

<div style="padding: 1.5em;">
    <h4 style="margin-top: 0;">Config Compliance &mdash; settings</h4>

    @if (Route::has('config-compliance.settings'))
        <form method="POST" action="{{ route('config-compliance.settings') }}" style="max-width: 540px;">
            @csrf
            <div class="form-group">
                <label for="cc-settings-oxidized-url">Oxidized API URL</label>
                <input type="text"
                       id="cc-settings-oxidized-url"
                       name="oxidized_url"
                       class="form-control"
                       placeholder="http://127.0.0.1:8888"
                       value="{{ $cc['oxidized_url'] ?? '' }}">
                <p class="help-block" style="margin-bottom: 0;">
                    The REST API of your Oxidized instance, reachable from the
                    LibreNMS server. Leave empty to use the default
                    <code>http://127.0.0.1:8888</code>.
                </p>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="{{ route('config-compliance.index') }}" class="btn btn-default btn-sm">
                Open plugin page
            </a>
        </form>
    @else
        <p class="text-muted">
            Enable the plugin first (button on this page), then reload to edit
            its settings here. Rules and scan results live on the plugin page
            itself.
        </p>
    @endif
</div>
