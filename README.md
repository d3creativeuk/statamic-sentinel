![Sentinel by D3 Creative](art/sentinel-hero.jpg)

# Sentinel by D3 Creative

**Security and update alerts for Statamic sites.**

Sentinel surfaces PHP version, Statamic version, and known package vulnerabilities in the Control Panel.

## What it shows

- **Statamic version** — current version vs latest stable release
- **PHP version** — with lifecycle status (Active / Security Only / End of Life)
- **Composer vulnerabilities** — packages in `composer.lock` checked against the [OSV vulnerability database](https://osv.dev)
- **npm vulnerabilities** — packages in `package-lock.json` checked against OSV

## How scanning works

Sentinel does not scan when you load the Control Panel — that would block the dashboard while it talks to several external APIs.

Instead:

- **First install:** the widget shows a **Scan Now** button. Click it once to run your first scan (10–20 seconds).
- **Ongoing:** Sentinel registers a daily scheduled scan at **10:00** (host's `app.timezone`). This requires `php artisan schedule:run` to be wired into cron — the standard Laravel/Statamic setup.
- **Manual refresh:** the **Refresh** link in the widget/utility header forces an immediate re-check at any time.
- **CLI:** run `php artisan sentinel:scan` to trigger a scan from the terminal.

Results are cached using the host's default cache store (`CACHE_STORE`) and persist until the next scan overwrites them.

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

## Requirements

- PHP 8.0+
- Statamic 3.3, 4.x, 5.x, or 6.x

## Support

This addon is maintained by [D3 Creative](https://d3creative.uk). For enquiries about managed Statamic maintenance, visit [d3creative.uk/services/support-and-maintenance](https://d3creative.uk/services/support-and-maintenance).
