# LibreNMS Config Compliance

A LibreNMS plugin that checks per device whether certain rules are present or
absent in the configuration. Configurations are read from **Oxidized** &mdash;
the plugin does not connect to your devices and does not change anything.

* **Read-only** &mdash; only checks, never fixes
* **Daily scan** via a cron job
* **Own storage** in JSON files (`storage/app/config-compliance/`)
* **LibreNMS style** &mdash; uses the standard LibreNMS layout

Version: **v1.11.0** &middot; License: **GPL-3.0-or-later**

**New to the plugin?** Read the step-by-step
[Getting started guide](docs/getting-started.md) — from zero to your first
compliance alert.

---

## Requirements

* LibreNMS with plugin system v2 (recent version)
* A working **Oxidized** integration &mdash; check that the **Configs** tab of
  a device shows the current config
* PHP 8.2 or higher

## Installation

The recommended way is `lnms plugin:add`, which installs the plugin from
[Packagist](https://packagist.org/packages/palerm0/librenms-config-compliance)
without touching LibreNMS' own dependencies. Run as the `librenms` user:

```bash
sudo -u librenms /opt/librenms/lnms plugin:add palerm0/librenms-config-compliance
```

Then enable it from the LibreNMS web UI under **Overview &raquo; Plugins**, or
from the command line:

```bash
sudo -u librenms /opt/librenms/lnms plugin:enable config-compliance
```

A menu item **Config Compliance** now appears under the plugin menu.

### Updating

When a new version is released on Packagist, run the same command again to
pick it up:

```bash
sudo -u librenms /opt/librenms/lnms plugin:add palerm0/librenms-config-compliance
sudo -u librenms /opt/librenms/lnms view:clear
```

`plugin:add` is idempotent — if the latest version is already installed it
does nothing, otherwise it fetches the new release from Packagist.

### Troubleshooting

**Web UI crashes (500) after enabling the plugin** &mdash; some LibreNMS
installations keep stale cached routes or views from before the plugin was
added. Clearing them solves it:

```bash
cd /opt/librenms
sudo -u librenms php artisan cache:clear
sudo -u librenms php artisan view:clear
sudo -u librenms php artisan route:clear
```

Then reload the page.

## Configuration

1. Open the plugin page, click the **gear button** (top right) and fill in the
   **Oxidized URL** (e.g. `http://127.0.0.1:8888`) &mdash; the same URL as under
   *Global Settings &raquo; External Settings &raquo; Oxidized*. The page shows
   a status line that confirms whether Oxidized is reachable, or warns if it is
   not configured or cannot be reached.

2. Add your rules under **Compliance rules**. Per rule:

   | Field  | Meaning                                                          |
   |--------|------------------------------------------------------------------|
   | Name   | Short description, e.g. "NTP configured"                         |
   | Group  | Which LibreNMS device group the rule applies to (`*` = all)      |
   | OS     | Which device OS the rule applies to (`*` = all)                  |
   | Checks | One or more checks &mdash; see below                             |

   Every rule has one or more **checks**. For each check you pick a **Type**
   and a **Pattern**:

   | Type | Passes when |
   |------|-------------|
   | Contains | the pattern is present in the config |
   | Does not contain | the pattern is absent |
   | Contains any of | at least one of the listed patterns is present (one per line) |
   | Contains none of | none of the listed patterns is present (one per line) |
   | Matches regex | the regular expression matches the config |
   | Does not match regex | the regular expression does not match the config |

   The rule as a whole passes only if **all** checks pass. The "any of" /
   "none of" types take one pattern per line and are handy when the same
   thing looks slightly different per device or location (e.g. a firewall
   object name that varies between sites).

   A rule applies to a device when both **Group** and **OS** match. Tip: the
   exact OS name (such as `ios`, `vrp`, `fortigate`) is shown in the **OS**
   column of the results table after the first scan. In the editor the rules
   are grouped into collapsible sections per OS, so a long list stays tidy;
   rules with OS `*` sit in an "All OS" section at the bottom.

   In the results table two coloured badges show the state: *Failed checks*
   per device (green 0 / orange 1&ndash;5 / red more than 5) and, per failed
   rule, the number of failed checks (orange = partly, red = all failed).
   Click a failed rule name to expand it and see exactly which checks failed.
   Device names link straight to the device page in LibreNMS.

3. Click **Scan now** for an immediate scan.

## Daily scan via cron

Add a line to `/etc/cron.d/librenms`:

```cron
# Config compliance scan, every day at 06:00
0 6 * * *   librenms   /opt/librenms/lnms config-compliance:scan >> /opt/librenms/logs/config-compliance.log 2>&1
```

Running it manually also works:

```bash
/opt/librenms/lnms config-compliance:scan
```

## Statuses

| Status         | Meaning                                                     |
|----------------|-------------------------------------------------------------|
| Compliant      | At least one rule applies and they all pass                 |
| Non-compliant  | One or more rules do not pass                               |
| No rules       | No rule applies to this device                              |
| No config      | No config in Oxidized &mdash; check the Oxidized backup     |

A device that is down in LibreNMS is still scanned against its last known
config, and gets an extra grey **Down** label next to its status.

## Alerting

After every scan the plugin writes a LibreNMS **component** per scanned
device (type `config-compliance`) with the compliance status, so you can use
the normal LibreNMS alerting system &mdash; including your existing
transports such as e-mail or Microsoft Teams.

Component status codes:

| status | Meaning                          |
|--------|----------------------------------|
| 0      | Compliant (or no rules apply)    |
| 1      | No config found in Oxidized      |
| 2      | Non-compliant                    |

The failed rule names are stored in the component's `error` field, so they
show up in the alert details.

Example alert rule (Alerts &raquo; Alert Rules &raquo; Create):

- `component.type` equals `config-compliance`
- AND `component.status` equals `2`

Or, using LibreNMS' built-in component macros (this variant also honours the
component's `ignore` flag, so you can exclude individual devices from
alerting):

- `macros.component_critical` equals `1`
- AND `component.type` equals `config-compliance`

Set the severity to your liking. Use `>= 1` instead of `= 2` if missing
Oxidized configs should also alert. Note that alerts follow the **scan**
schedule: with a daily cron scan, a device that drifts out of compliance
during the day raises the alert after the next scan.

Two practical tips:

- **Attach a transport to the rule** (or have a default transport set up),
  otherwise the alert shows in the LibreNMS UI but no notification is sent.
- Consider severity **Warning** rather than Critical: compliance drift is
  important but rarely urgent, and this keeps it visually distinct from
  device-down alerts.

## LibreNMS updates

Because `lnms plugin:add` registers the plugin through LibreNMS' own plugin
machinery, you do **not** need any custom steps after `./daily.sh` &mdash;
the plugin stays linked across LibreNMS updates.

## For contributors / local development

If you want to run the plugin from a local clone (to develop or hack on it
rather than installing from Packagist), link the folder directly:

```bash
git clone https://github.com/Palerm0/librenms-config-compliance \
  /opt/librenms/plugins-src/librenms-config-compliance

cd /opt/librenms

composer config repositories.config-compliance \
  '{"type": "path", "url": "plugins-src/librenms-config-compliance", "symlink": true}'

composer require palerm0/librenms-config-compliance @dev
```

With the symlink in place you can edit the files in `plugins-src` and the
changes take effect immediately (run `./lnms view:clear` after Blade edits).

When using this path the local link can be reset by `./daily.sh`; in that
case re-run the two `composer` commands above (e.g. from a `post-update.sh`).

## Files

```
librenms-config-compliance/
├── composer.json
├── routes/web.php
├── resources/views/
│   ├── menu.blade.php          Menu item
│   └── page.blade.php          The plugin page
└── src/
    ├── ConfigComplianceProvider.php   Bootstrap file (registers everything)
    ├── ComplianceEngine.php           Core logic: rules, scan, storage
    ├── Console/ScanCommand.php        The 'lnms config-compliance:scan' command
    ├── Controllers/CompliancePageController.php
    └── Hooks/MenuEntry.php            Menu hook
```

The plugin keeps its data in `storage/app/config-compliance/`:
`settings.json`, `rules.json`, `results.json` and `history.json`.
