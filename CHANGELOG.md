# Changelog

All notable changes to this project are documented here. The project follows
semantic-ish versioning (`MAJOR.MINOR.PATCH`).

## v1.9.x
- Compliance score in the scan bar: percentage of evaluated devices that are
  compliant, with a colour indicator (green / orange / red). Devices with no
  rules or no config are not counted.
- Clickable **Non-compliant** summary that expands a list of the failing
  devices, with their OS, a **Down** label when the device is down, and
  clickable failed-rule names that jump to the device's group in the results.
- Device list under each results tab is collapsible and collapsed by default,
  with a per-tab summary line, to reduce scrolling.

## v1.8.x
- Rules editor groups rules into collapsible sections per **OS**, and within
  each OS into collapsible sub-sections per **group** (two levels).
- Per-section "add rule" and "save" buttons so you do not have to scroll.
- After saving, the page returns to the rules and restores the open/closed
  sections instead of jumping back to the top.

## v1.7.x
- Two new check types: **Contains any of** and **Contains none of**
  (one pattern per line). Useful when the same requirement looks slightly
  different per device or location.
- Help tooltip explaining the check types.

## v1.6.x
- Collapsible **Compliance rules** panel.
- A **Down** label for devices that are down in LibreNMS (still scanned
  against their last known config).
- Various fixes, including the device status being read correctly.

## v1.5.x
- Oxidized status banner (reachable / not configured / unreachable).

## v1.4.x
- Settings modal for the Oxidized URL.
- Device names link to the LibreNMS device page.
- Per-rule drill-down showing exactly which checks failed.

## v1.3.x
- Multi-check rules: a rule passes only when all of its checks pass.

## v1.2.x
- Tabbed results per device group, with a rules overview.

## v1.0.x
- First working version: rules with Contains / Does not contain checks,
  reading configs from Oxidized, a results page, JSON storage, and an
  `lnms config-compliance:scan` command for scheduled scans.
