![Sentinel by D3 Creative](art/statamic-sentinel-d3creative.jpg)

# Sentinel by D3 Creative

**Security and update alerts for Statamic sites.**

Sentinel surfaces PHP version, Statamic version, and known package vulnerabilities in the Control Panel.

## What it shows

- **Statamic version** - current version vs latest stable release
- **PHP version** - with lifecycle status (Active / Security Only / End of Life)
- **Composer vulnerabilities** - packages in `composer.lock` checked against the [OSV vulnerability database](https://osv.dev)
- **npm vulnerabilities** - packages in `package-lock.json` checked against OSV
- **Update history** - snapshot of versions and counts is recorded whenever any tracked value changes, viewable in the utility's **History** tab. Retained for 365 days. Each snapshot also stores per-package installed versions so update diffs can be reconstructed later.
- **Email status report** - super admins can send the full current audit to up to 10 recipients from the utility's **Status Report** tab.
- **Email update report** - super admins can send a diff between the two most recent snapshots (platform version changes, packages updated/added/removed, vulnerabilities resolved/introduced) from the utility's **Update Report** tab. Run an update, hit **Refresh** to capture a fresh snapshot, then click **Send Update Report** - recipients see exactly what moved. If nothing changed since the last snapshot, you can opt to resend the last meaningful diff via **Send anyway**.
- **Preview before sending** - both report tabs include a **Preview** button that opens the rendered email in a modal so you can see exactly what recipients will get before clicking Send.
- **Scheduled status reports** - the Status Report tab includes schedule controls (daily/weekly/monthly cadence, time, recipient list) below the manual send form. The addon auto-registers the matching Laravel scheduler entry on boot, and each scheduled run does a fresh scan first - so the email is current AND the CP's cached audit + history get updated for free. Update reports aren't scheduled - they're meant to verify a manual update + scan, so they're send-on-demand only. Requires the standard `* * * * * php artisan schedule:run` cron entry on the host.
- Both email send endpoints are rate-limited to 6 requests per minute.

## How scanning works

Sentinel does not scan when you load the Control Panel - that would block the dashboard while it talks to several external APIs.

Instead:

- **First install:** the widget shows a **Scan Now** button. Click it once to run your first scan (10-20 seconds).
- **Manual refresh:** the **Refresh** link in the widget/utility header forces an immediate re-check at any time.
- **CLI:** run `php artisan sentinel:scan` to trigger a scan from the terminal. Wire this into your host app's scheduler (e.g. `$schedule->command('sentinel:scan')->daily()` in your `App\Console\Kernel`) if you want unattended daily scans.

Results are cached using the host's default cache store (`CACHE_STORE`) and persist until the next scan overwrites them.

## Where data lives

Sentinel writes runtime state to the host app's `storage/app/` directory under `statamic-sentinel/`:

- `history.json` - rolling 365-day snapshot history (one entry per change)
- `last-update-report.json` - the most recent meaningful diff, used by **Send anyway**
- `schedule.json` - scheduled status report config (cadence, time, recipients)
- `sent/index.json` + `sent/{id}.html` - log and rendered HTML of every report sent, capped per kind

All of it is per-environment runtime state - regenerable from `composer.lock`, `package-lock.json`, and the live OSV/Packagist/npm APIs. Laravel's default `.gitignore` already covers `storage/app/`, so these files aren't (and shouldn't be) tracked in git. Back them up with the rest of `storage/` if you want to preserve the sent archive across environment moves.

## Installation

```bash
composer require d3creative/statamic-sentinel
```

Then add the widget to your CP dashboard by adding `sentinel` to the widgets array in `config/statamic/cp.php`:

```php
'widgets' => [
    'type' => 'sentinel',
    'width' => 50,
],
```

## Branding (optional)

Sentinel ships unbranded by default - the widget, utility, and report emails just say "Sentinel". If you'd like to attribute the addon to your agency or yourself, set two env vars in the host app's `.env`:

```env
SENTINEL_DEV_NAME='Your Agency'
SENTINEL_DEV_URL='https://your-agency.example/sentinel'
```

When set, the CP widget/utility footers and email footers add a `Sentinel by <name>` link. Setting only `SENTINEL_DEV_NAME` (no URL) renders the name as plain text.

## Requirements

- PHP 8.0+
- Statamic 3.3, 4.x, 5.x, or 6.x

## Support

This addon is maintained by [D3 Creative](https://d3creative.uk). For enquiries about managed Statamic maintenance, visit [d3creative.uk/services/statamic-maintenance](https://d3creative.uk/services/statamic-maintenance).

## License

Released under the [MIT License](LICENSE).
