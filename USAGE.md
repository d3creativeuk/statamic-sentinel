# Sentinel Usage

Operational notes for running Sentinel in a Statamic project. For installation, requirements, and a feature overview, see the [README](README.md).

## How scanning works

Sentinel does not scan when you load the Control Panel - that would block the dashboard while it talks to several external APIs.

Instead:

- **First install:** the widget shows a **Scan Now** button. Click it once to run your first scan (10-20 seconds).
- **Manual refresh:** the **Refresh** link in the widget/utility header forces an immediate re-check at any time.
- **CLI:** run `php artisan sentinel:scan` to trigger a scan from the terminal. Wire this into your host app's scheduler (e.g. `$schedule->command('sentinel:scan')->daily()` in your `App\Console\Kernel`) if you want unattended daily scans.

Results are cached using the host's default cache store (`CACHE_STORE`) and mirrored to `storage/app/statamic-sentinel/audit.json`. They persist until the next scan overwrites them, and the disk mirror means a `cache:clear` (common after `composer update`) won't wipe your last scan - on the next read, the cache is rehydrated from disk.

> **After updating dependencies:** Sentinel does not watch `composer.lock` or `package-lock.json` for changes, so a fresh `composer update` or `npm install` won't be reflected until the next scan. Hit **Refresh** in the widget/utility header (or run `php artisan sentinel:scan`) to re-read the lockfiles and overwrite the cached audit. Until then, the CP will keep reporting the versions captured by the previous scan.

## What gets scanned vs. what gets shown

Sentinel scans **every package in your lockfile** (direct + transitive) for known vulnerabilities via OSV - so a CVE in something deep in the dependency tree like `axios` (pulled in by `laravel-precognition-alpine`, for example) will still surface under **Security issues**.

The **Updates available** list, however, only shows your **direct dependencies** - the packages you've added to `composer.json` / `package.json` yourself. Transitives are filtered out for two reasons:

- The list would otherwise explode to hundreds of entries and bury the actionable signal.
- You can't update a transitive directly anyway; it'll move when its parent package releases a new version.

So if you don't see a transitive package in the updates list, it's not being ignored - it's being scanned, just not surfaced as actionable until either it has a known vulnerability or its parent gets an update.

## Where data lives

Sentinel writes runtime state to the host app's `storage/app/` directory under `statamic-sentinel/`:

- `audit.json` - disk mirror of the last scan, so a `cache:clear` doesn't wipe it
- `history.json` - rolling 365-day snapshot history (one entry per change)
- `last-update-report.json` - the most recent meaningful diff, used by **Send anyway**
- `schedule.json` - scheduled status report config (cadence, time, recipients)
- `sent/index.json` + `sent/{id}.html` - log and rendered HTML of every report sent, capped per kind

All of it is per-environment runtime state - regenerable from `composer.lock`, `package-lock.json`, and the live OSV/Packagist/npm APIs. Laravel's default `.gitignore` already covers `storage/app/`, so these files aren't (and shouldn't be) tracked in git. Back them up with the rest of `storage/` if you want to preserve the sent archive across environment moves.
