# Changelog

All notable changes to `d3creative/statamic-sentinel` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [2.1.1] - 2026-07-27

### Added

- **Users tab (who's online).** A new super-only tab in the utility lists every CP user with a live
  status (green dot + "Active ..." when seen within the online window, else "Last seen ...") and
  their last login. Statamic has no built-in "online users" concept, so a lightweight CP middleware
  records each authenticated user's last-active time (throttled to ~1 write/min per user, persisted
  so it survives `cache:clear`). Disable with `SENTINEL_TRACK_ACTIVITY=false`; tune the window with
  `SENTINEL_ONLINE_WINDOW` (minutes, default 5). Only timestamps are stored - no IP/user-agent.

### Fixed

- Plan Summary email header now dates from the plan start rather than the earliest recorded scan,
  so the "5 May 2026 to ..." range matches the "since your plan started on ..." intro line.

## [2.1.0] - 2026-07-24

### Added

- **Statamic License Status** on the dashboard widget, the utility page, and the status and
  update emails - a colour-coded pill reading Licensed / Renewal due / Not licensed / Trial /
  Free edition / Unverified, read from Statamic's cached Outpost data (Statamic 3.3-6, offline in
  the normal case), with a deep link to the statamic.com account when a renewal is due. Statamic
  exposes a needs-renewal signal rather than a calendar date, so the raw licensed version range is
  not shown to clients.
- **Blocked npm updates.** Reads `min-release-age` from `.npmrc` and flags updates npm is holding
  back with a "Blocked" pill and an "available in N days" countdown on the utility page. Pure PHP
  (never shells out to npm) and fails open, so a genuine update is never hidden.
- **Plan Summary report.** A new utility tab and on-demand email summarising the maintenance
  delivered since a plan's start date: how many times Statamic, Laravel and PHP were updated, the
  total Composer and npm package updates, and how many were security updates (with a critical/high
  breakdown). Plan name, start date and expiry date are saved settings; preview and send on demand.

### Changed

- History snapshots now also record each vulnerable package's highest severity (additive,
  forward-only) to power the Plan Summary's critical/high breakdown.

## [2.0.9] - 2026-07-02

### Added

- "N behind" release counts on the Statamic, Laravel and PHP version cards, mirroring the core
  Updater. Counts stable releases newer than what's installed (across majors); hidden when up to
  date.

## [2.0.8] - 2026-07-02

### Changed

- Security Issues rework: transitive (indirect) packages are nested under the direct dependency
  that pulls them in; one row per package with inline CVE/GHSA codes linking to the OSV record
  (High/Critical highlighted red, the rest grey); icons and severity pills removed; pills reserved
  for alert states only (security/EOL); remaining blue update indicators recoloured to neutral.

### Fixed

- CVE hover underline now applies under Statamic 6 (style injected into `<head>` to survive CP
  content extraction).

## [2.0.7] - 2026-07-01

### Changed

- Content Freeze renamed to **Notify** in the CP: the tab, schedule heading/button ("Schedule
  update"), window fields ("Update starts" / "Update ends"), history list ("Past notifications"),
  and all dialog/toast copy. User-facing wording only - routes, the `ContentFreezeService`, the
  `sentinel:freeze:*` commands, the `#content-freeze` anchor, stored keys and config are unchanged.

## [2.0.6] - 2026-07-01

### Added

- Heads-up email shows a "Statamic maintenance ends" box (the end time, styled like the "starts"
  box) beneath the timeframe sentence, when an end time is set.

### Changed

- Freeze times are picked with a date field plus a `type="time"` input that steps in 15-minute
  blocks, replacing the free datetime input; defaults snap to the next 15-minute boundary (times are
  still parsed at full precision server-side).
- Freeze times show timezone letters (e.g. `BST`) only when `SENTINEL_FREEZE_TIMEZONE` is set;
  unset (the default), they render in the app timezone without the abbreviation.

## [2.0.5] - 2026-07-01

### Fixed

- The Content Freeze **Preview heads-up email** now reflects the times and expected duration
  currently entered in the schedule form, instead of a fixed "now / +3 hours / 30 minutes"
  placeholder.

## [2.0.4] - 2026-07-01

### Added

- Content Freeze: optional "Freeze ends at" time and an "Expected duration" (minutes, hours, or
  days), surfaced in the heads-up email (e.g. "The update should only take up to 30 minutes, however
  a 3 hour window has been allowed..."). An end time before the start is warned about inline and
  rejected server-side. Both are informational and do not auto-end the freeze. The email eyebrow is
  renamed to "Notification of Planned Work".

## [2.0.3] - 2026-07-01

### Fixed

- On multisite installs, the Status/Update report emails, the Content Freeze heads-up / all-clear
  emails, and their CP previews reference **every** site rather than only the primary (hosts listed
  comma-separated in headers and subjects). Single-site installs render exactly as before.

## [2.0.2] - 2026-07-01

### Fixed

- The Sent Emails log no longer reports a report as **Sent** when the send actually failed. The send
  now runs inside a queued job (`SendSentinelMail`): a record starts as **Queued** and flips to
  **Sent** only after the transport accepts the message, or **Failed** (with the error) if not.

## [2.0.1] - 2026-07-01

### Fixed

- A refresh/scan no longer 500s when an upstream API (Packagist, the npm registry, or OSV) is
  briefly unreachable. `Http::pool()` returns a `ConnectionException` (not a `Response`) in a failed
  slot, which the old `->ok()` guard called into; all pool consumers now type-check the slot via a
  shared `isOkResponse()` helper. Adds regression coverage.

## [2.0.0] - 2026-06-30

### Changed (breaking)

- Config renamed `sentinel` -> `statamic-sentinel` (and `config/sentinel.php` ->
  `config/statamic-sentinel.php`) to match Statamic's addon-slug convention. **Env var names are
  unchanged**, so no `.env` edits are required; host code reading `config('sentinel...')` must
  switch to `config('statamic-sentinel...')`.

### Changed

- Branded for D3 Creative by default - the widget, utility and report emails attribute to D3
  Creative with no config. White-label via `SENTINEL_DEV_*`, or disable entirely with
  `SENTINEL_BRANDING=false`.
- One-off report fields pre-fill with the recipients used last.
- Status and update report emails stack their rows on mobile.

### Fixed

- Non-super users with the `access sentinel utility` permission no longer hit a `403` on the report
  preview; they now see a read-only Current view plus Refresh, with the super-only tabs hidden.
- Developer attribution survives `php artisan config:cache` when the config is published.

## [1.1.4] - 2026-05-26

### Changed

- The cached audit reconciles against live platform versions and lockfiles on every read, so the
  widget/utility stop showing a red "security update available" pill for a release already installed
  via `composer update`; outdated lists are pruned the same way (no HTTP).

### Fixed

- Freeze banner: a dismissed banner no longer flashes on page load, and hiding it clears the CP
  header/main/nav offsets instead of leaving them stuck.

## [1.1.3] - 2026-05-20

### Fixed

- Freeze notification email copy and header.

## [1.1.2] - 2026-05-20

### Changed

- The vendor security badge expands into a package list.

## [1.1.1] - 2026-05-20

### Changed

- The widget "View Report" button now respects the `access sentinel utility` permission, so editors
  without utility access no longer see a link that 403s.
- Trimmed the redundant preamble from the freeze notification email and the CP "learn more" modal.

## [1.1.0] - 2026-05-20

### Added

- **Content Freeze workflow**: coordinate Statamic update windows from a new utility tab - schedule
  a heads-up email, show a CP-wide banner through the work (upcoming/active/complete), and send an
  all-clear when done. State machine driven by an every-minute scheduler plus a request-time
  fallback; banner injection works across Statamic 3.3-6; CLI parity via `sentinel:freeze:start` /
  `:complete`; configurable display timezone via `SENTINEL_FREEZE_TIMEZONE`.
- **Vendor security check**: cross-references installed Statamic addons against the Statamic
  marketplace API for known advisories, alongside the existing OSV results.

### Changed

- Update report lists per-package vulnerability names so recipients see what resolved between
  snapshots.
- Email status reports queue rather than block the request, with hardened dispatch.
- Email report headlines split into a title and detail subline.
- Row deletion across History, Sent and Freeze History routes through Statamic's Action endpoints.

### Fixed

- Audit refresh is F5-safe: a stuck or partial scan no longer serves a half-built audit to the next
  CP load.

## [1.0.7] - 2026-05-07

### Fixed

- PHP version-bump tier fix; scan-scope documentation.

## [1.0.6] - 2026-05-07

### Changed

- Replaced the support email with a contact-page URL.

## [1.0.5] - 2026-05-07

### Added

- Surface major-version drift for PHP in the status email.

## [1.0.4] - 2026-05-07

### Changed

- Persist the audit to disk so scans survive `cache:clear`.

## [1.0.3] - 2026-05-07

### Added

- Maintenance CTA in the email report.

### Changed

- Faster scans and further email polish.

## [1.0.2] - 2026-05-06

### Changed

- Email report polish.

## [1.0.1] - 2026-05-05

### Changed

- Refined the addon description and README intro.

## [1.0.0] - 2026-05-05

### Added

- First public release: status and update reports, scheduled sends, OSV vulnerability scanning, and
  configurable branding.

## [0.1.0] - 2026-05-01

### Added

- Initial release.
