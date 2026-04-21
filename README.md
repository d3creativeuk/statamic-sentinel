# Sentinel by D3 Creative

**Daily dependency audits for Statamic sites.**

Sentinel surfaces PHP version, Statamic version, and known package vulnerabilities in the Control Panel.

## What it shows

- **Statamic version** — current version vs latest stable release
- **PHP version** — with lifecycle status (Active / Security Only / End of Life)
- **Composer vulnerabilities** — packages in `composer.lock` checked against the [OSV vulnerability database](https://osv.dev)
- **npm vulnerabilities** — packages in `package-lock.json` checked against OSV

Results are cached for 6 hours to avoid unnecessary external API calls. A **Refresh** link in the widget header forces an immediate re-check.

## Installation

```bash
composer require d3creative/sentinel
```

Then add the widget to your CP dashboard by adding `sentinel` to the widgets array in `config/statamic/cp.php`:

```php
'widgets' => [
    'sentinel',
    'getting_started',
    'collection',
],
```

## Privacy

On first boot, the addon sends a one-time notification to D3 Creative containing the site domain, Statamic version, and PHP version. This fires once and is never repeated. No personal data is transmitted.

## Requirements

- PHP 8.0+
- Statamic 3.3, 4.x, 5.x, or 6.x

## Support

This addon is maintained by [D3 Creative](https://d3creative.uk). For enquiries about managed Statamic maintenance, visit [d3creative.uk/services/support-and-maintenance](https://d3creative.uk/services/support-and-maintenance).
