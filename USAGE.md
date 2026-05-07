# Sentinel Usage

Operational notes for running Sentinel in a Statamic project. For installation, requirements, and a feature overview, see the [README](README.md).

## How scanning works

Sentinel does not scan when you load the Control Panel - that would block the dashboard while it talks to several external APIs.

Instead:

- **First install:** the widget shows a **Scan Now** button. Click it once to run your first scan (10-20 seconds).
- **Manual refresh:** the **Refresh** link in the widget/utility header forces an immediate re-check at any time.
- **CLI:** run `php artisan sentinel:scan` to trigger a scan from the terminal. Wire this into your host app's scheduler (e.g. `$schedule->command('sentinel:scan')->daily()` in your `App\Console\Kernel`) if you want unattended daily scans.

Results are cached using the host's default cache store (`CACHE_STORE`) and persist until the next scan overwrites them.

> **After updating dependencies:** Sentinel does not watch `composer.lock` or `package-lock.json` for changes, so a fresh `composer update` or `npm install` won't be reflected until the next scan. Hit **Refresh** in the widget/utility header (or run `php artisan sentinel:scan`) to re-read the lockfiles and overwrite the cached audit. Until then, the CP will keep reporting the versions captured by the previous scan.

## Where data lives

Sentinel writes runtime state to the host app's `storage/app/` directory under `statamic-sentinel/`:

- `history.json` - rolling 365-day snapshot history (one entry per change)
- `last-update-report.json` - the most recent meaningful diff, used by **Send anyway**
- `schedule.json` - scheduled status report config (cadence, time, recipients)
- `sent/index.json` + `sent/{id}.html` - log and rendered HTML of every report sent, capped per kind

All of it is per-environment runtime state - regenerable from `composer.lock`, `package-lock.json`, and the live OSV/Packagist/npm APIs. Laravel's default `.gitignore` already covers `storage/app/`, so these files aren't (and shouldn't be) tracked in git. Back them up with the rest of `storage/` if you want to preserve the sent archive across environment moves.
