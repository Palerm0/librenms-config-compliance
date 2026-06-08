# Getting started — from zero to your first compliance alert

This guide walks you through the Config Compliance plugin step by step,
assuming no prior knowledge. For the compact reference, see the
[README](../README.md).

## 1. What does this plugin do?

[Oxidized](https://github.com/ytti/oxidized) backs up the configuration of
your network devices. This plugin reads those backups and checks them
against **rules you define** — for example *"every Cisco switch must have
`no ip http server`"* or *"every Fortigate must have our NTP server
configured"*.

The result is a per-device verdict: **Compliant** or **Non-compliant**,
with a drill-down showing exactly which rule failed. Combined with a daily
scan and a LibreNMS alert rule, you get a message (mail, Teams, ...) when a
device drifts out of compliance.

The plugin is **read-only**: it never connects to or changes anything on
your devices. It only reads the backups Oxidized already made.

## 2. Before you start

You need:

- A recent LibreNMS (plugin system v2 — any install from late 2024 onwards).
- A working Oxidized with its REST API reachable from the LibreNMS server
  (usually `http://127.0.0.1:8888`). Quick test from the LibreNMS server:

  ```bash
  curl -s http://127.0.0.1:8888/nodes.json | head -c 200
  ```

  If you see JSON, you're good. If not, fix Oxidized first — the plugin
  can't check configs that aren't being backed up.

## 3. Install the plugin

Two commands, as the `librenms` user:

```bash
sudo -u librenms /opt/librenms/lnms plugin:add palerm0/librenms-config-compliance
sudo -u librenms /opt/librenms/lnms plugin:enable config-compliance
```

Reload LibreNMS — a **Config Compliance** item appears in the plugin menu.

> Web UI broken (500) right after enabling? Clear the Laravel caches —
> see [Troubleshooting](../README.md#troubleshooting) in the README.

## 4. Point it at Oxidized

Open the plugin page. At the top it shows whether Oxidized is reachable.
If it isn't, set the Oxidized URL in the plugin settings to wherever your
Oxidized API lives. The status line should turn green:
`Oxidized reachable — N nodes`.

## 5. Create your first rule

Open the **Compliance rules** panel on the plugin page. Rules are grouped
by OS; pick the OS your devices run (for example `ios` for Cisco IOS) and
click **+** to add a rule. A rule has:

- **Name** — what you'll see in results and alerts, e.g. `No http server`.
- **Group** — the LibreNMS **device group** this rule applies to. Leave it
  empty (or `*`) to apply to all groups — that's the right choice for most
  rules. Pick a specific group only when the policy genuinely differs per
  group (e.g. stricter rules for a DMZ group). A device in multiple groups
  matches when *any* of its groups matches; the verdict is per device, so
  you never need to duplicate a rule across groups.
- **One or more checks** — all checks must pass (AND).

Each check has a **type** and a **pattern**:

| Type                  | Passes when...                                    |
|-----------------------|---------------------------------------------------|
| Contains              | the pattern appears somewhere in the config       |
| Does not contain      | the pattern appears nowhere in the config         |
| Contains any of       | at least one of the lines appears (one per line)  |
| Contains none of      | none of the lines appear (one per line)           |
| Matches regex         | the regular expression matches the config         |
| Does not match regex  | the regular expression does not match the config  |

A classic first rule for Cisco IOS:

- Name: `No http server`
- Check: **Contains** → `no ip http server`

Tip: when the same setting can be written in more than one way (different
sites, different syntax variants), use **Contains any of** with one variant
per line. The check passes if any variant is present.

Click the blue save button for the OS section.

## 6. Run a scan and read the results

Click **Scan now**. After a few seconds the results table fills:

| Status         | Meaning                                                  |
|----------------|----------------------------------------------------------|
| Compliant      | All applicable rules pass                                |
| Non-compliant  | At least one rule fails — click it to see which          |
| No rules       | No rule applies to this device's OS/group               |
| No config      | Oxidized has no backup for this device — fix that first |

The bar at the top shows a compliance score (devices passing / devices
with rules). Click the **Non-compliant** label for a list of failing
devices with their failed rules.

## 7. Schedule the daily scan

Add one line to `/etc/cron.d/librenms`:

```cron
0 6 * * *   librenms   /opt/librenms/lnms config-compliance:scan >> /opt/librenms/logs/config-compliance.log 2>&1
```

From now on, results refresh every morning without you doing anything.

## 8. Get alerted on compliance drift

After every scan the plugin writes a LibreNMS *component* per device with
the compliance status. Create a normal LibreNMS alert rule on top of that:

1. Go to **Alerts → Alert Rules → Create new alert rule**.
2. Build: `component.type` *equal* `config-compliance`
   **AND** `component.status` *equal* `2`.
3. Pick a severity (Warning is a good fit for compliance drift).
4. **Attach your transport** (mail, Microsoft Teams, ...) to the rule —
   without a transport the alert only shows in the UI.
5. Save.

The failed rule names are included in the alert details, so the
notification tells you exactly what is wrong on which device.

Remember: alerts follow the **scan** schedule. With a daily 06:00 scan, a
device that drifts during the day alerts the next morning. Want faster?
Run the cron more often — the scan is light.

## 9. Example rules to steal

| Goal                              | OS        | Check(s)                                          |
|-----------------------------------|-----------|---------------------------------------------------|
| HTTP server disabled              | ios       | Contains → `no ip http server`                    |
| Our NTP server configured         | ios       | Contains → `ntp server 10.0.0.123`               |
| No default SNMP community         | any       | Contains none of → `snmp-server community public` + `snmp-server community private` |
| Management ACL present (variants) | vrp       | Contains any of → one accepted variant per line   |
| SSH v2 enforced                   | ios       | Contains → `ip ssh version 2`                     |
| BPDU protection on every port     | procurve  | Matches regex → `spanning-tree \d+ bpdu-protection` |

Start small: one or two rules, verify the results match reality, then grow
your rule set. A rule that's wrong is worse than no rule — it teaches
people to ignore the compliance page.

## 10. Where is my data?

Everything lives in `/opt/librenms/storage/app/config-compliance/` (rules,
results, settings) — it survives plugin updates and LibreNMS updates.
