# Changelog

All notable changes to `d3creative/statamic-sentinel` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

### Added

- **Content Freeze: optional "Freeze ends at" and "Expected duration" fields.** The schedule form
  (and `sentinel:freeze:start`) now accept an optional end time for the maintenance window and an
  expected duration entered in minutes, hours, or days. Setting "Freeze ends at" before "Freeze
  starts at" shows an inline warning and is rejected server-side. Both are informational - they do
  not auto-end the freeze (that still happens on **Mark complete**) - and drive a new sentence in
  the notification email, e.g. "The update should only take up to 30 minutes, however a 3 hour
  window has been allowed in case of any unforeseen circumstances." Freezes scheduled without the
  new fields render exactly as before.

### Changed (breaking)

- **Config key renamed `sentinel` -> `statamic-sentinel`**, and the config file renamed
  `config/sentinel.php` -> `config/statamic-sentinel.php`. This aligns Sentinel with Statamic's
  native addon-slug convention, so Statamic auto-wires the config merge and a
  `statamic-sentinel-config` publish tag (the addon's hand-rolled `mergeConfigFrom` in
  `register()` has been removed).
  - **Env var names are unchanged** (`SENTINEL_DEV_NAME`, `SENTINEL_DEV_URL`,
    `SENTINEL_DEV_EMAIL`, `SENTINEL_FREEZE_TIMEZONE`, `SENTINEL_VENDOR_SECURITY_CHECK`) - no
    `.env` edits are required.
  - Any host code reading `config('sentinel...')` directly must switch to
    `config('statamic-sentinel...')`.

### Changed

- Content Freeze times now show timezone letters (e.g. `BST`) **only when `SENTINEL_FREEZE_TIMEZONE`
  is explicitly set**. Unset (the default), times render in the app timezone without the
  abbreviation, e.g. `4 Jul 2026, 08:00`. Setting the var restores the abbreviation and the
  side-by-side dual-time display when it differs from the server timezone. (The config now defaults
  to `null` rather than the app timezone; behaviour when set is unchanged.)

- The Content Freeze time fields (notification, freeze start, freeze end) now use a date input plus
  a `type="time"` field that steps in 15-minute blocks (`step="900"`), instead of a free datetime
  input; the pre-filled defaults snap to the next 15-minute boundary. Times are still parsed with
  full precision server-side, so this is purely a picker convenience.

- The Content Freeze heads-up email's eyebrow label now reads **"Notification of Planned Work"**
  instead of "Heads up".

- **Branded for D3 Creative by default.** With no `SENTINEL_DEV_*` vars set, the widget,
  utility, and report emails now attribute to D3 Creative, link to the managed-maintenance
  service, and show the `Need help with your website?` button - no configuration required.
  Previously an empty config rendered unbranded.
  - White-labelling is unchanged: set `SENTINEL_DEV_NAME` / `SENTINEL_DEV_URL` /
    `SENTINEL_DEV_EMAIL` to swap in your own agency details.
  - New `SENTINEL_BRANDING=false` (config `statamic-sentinel.branding.enabled`) renders
    Sentinel fully unbranded ("Sentinel for Statamic"), restoring the previous default for
    hosts that want no attribution.

### Fixed

- The Content Freeze heads-up email now shows a "Statamic maintenance ends" box (the "Freeze ends
  at" time, in the same style as the "maintenance starts" box) beneath the timeframe sentence, when
  an end time is set.

- The Content Freeze **Preview heads-up email** now reflects the times and expected duration
  currently entered in the schedule form, instead of a fixed placeholder. Previously the preview
  always showed "now / +3 hours / 30 minutes" regardless of the form; it now renders the actual
  Freeze-starts time, the real window (freeze start -> end), and the entered expected duration.

- On a multisite install, the Status and Update report emails, the Content Freeze heads-up /
  all-clear emails, and their CP previews now reference **every** site the install serves rather
  than only the primary. Headers and subjects list each site's host, comma-separated (e.g.
  `a.test, b.test updates`); the audit data itself is unchanged, as it is shared across all sites
  in one codebase. A single-site install renders exactly as before. Site hosts come from
  Statamic's Sites system with a silent fallback to `config('app.url')` when it is unavailable
  (e.g. an older Statamic), so scheduled/CLI sends are unaffected.

- The Sent Emails log no longer reports a report as **Sent** when the send actually failed.
  Previously the outcome was recorded the instant the mail was handed to the queue, so a job
  that later failed in the worker (bad SMTP config, rejected envelope) still showed green.
  The send now runs inside a dedicated queued job (`SendSentinelMail`): a record starts life
  as **Queued** and the job flips it to **Sent** only after the transport accepts the message,
  or to **Failed** (with the transport error) when it does not - mirroring the `queue:work`
  DONE / FAIL outcome. On the `sync` queue this resolves inline; on a real queue the row shows
  **Queued** until a worker processes it. (Detection stops at transport acceptance - inbox
  delivery/bounce tracking needs provider webhooks and is out of scope.)

- A refresh/scan no longer crashes the utility with a `500` when an upstream API (Packagist,
  the npm registry, or OSV) is briefly unreachable. `Http::pool()` returns a
  `ConnectionException` object (not a `Response`) in the failed slot rather than throwing, so the
  surrounding `try/catch` never fired and the old `$response->ok()` guard hit
  `Call to undefined method ...ConnectionException::ok()`. All pool consumers now type-check the
  slot via a shared `isOkResponse()` helper before touching it, so an unreachable endpoint fails
  silently as intended.

- Non-super users with the `access sentinel utility` permission no longer hit a `403 Forbidden`
  in the report preview (and on the Send / Schedule / Content Freeze controls). Those actions
  drive super-only endpoints, but the utility itself renders for anyone granted access, so the
  buttons were visible yet broken. Non-supers now see only the **Current** tab plus **Refresh** -
  the same audit data a report would email - while the tabs that drive super-only endpoints are
  hidden. The controller super-admin checks are unchanged (defense-in-depth).

- Developer attribution no longer disappears on hosts that run `php artisan config:cache` in
  production. Hosts can now publish and commit the config so the values survive caching:
  ```bash
  php artisan vendor:publish --tag=statamic-sentinel-config
  git add config/statamic-sentinel.php
  ```

  Note: this resolves the *config build* (the value is now reliably baked into the cache). It does
  not change the fact that, on production with OPcache (`opcache.validate_timestamps=0`), FPM
  workers keep serving the old compiled `bootstrap/cache/config.php` until OPcache is reset. After
  any `config:cache`, reload FPM (e.g. `sudo service php8.x-fpm reload`) for the new values to
  reach web requests. A normal deploy that reloads FPM handles this; a manual `.env` + `config:cache`
  outside a deploy does not. When verifying, don't trust `php artisan tinker` alone (fresh CLI
  process, own OPcache) - reload FPM, then check the CP widget.

> Note: because the config key changed, this is a breaking change. Per SemVer it should ship as
> the next major (the latest release is `v1.1.4`, so `v2.0.0`).
