# Changelog

All notable changes to `d3creative/statamic-sentinel` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

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
