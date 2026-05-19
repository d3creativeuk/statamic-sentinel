![Sentinel by D3 Creative](art/statamic-sentinel-d3creative.jpg)

# Sentinel by D3 Creative

**Platform and dependency audits for Statamic sites.**

Sentinel cross-references your installed versions against the OSV vulnerability database, endoflife.date, Packagist, and the npm registry, then surfaces ranked findings (vulnerabilities by severity, packages past EOL, available updates) directly in the Statamic Control Panel. It tracks a rolling 365-day history so you can diff snapshots and see exactly what moved between updates, and email reports can be sent on demand or on a daily, weekly, or monthly schedule.

## Documentation

- [Usage](USAGE.md) - how scanning works, where data lives
- [Security](SECURITY.md) - reporting vulnerabilities

## What it shows

- **Statamic version** - current version vs latest stable release
- **PHP version** - with lifecycle status (Active / Security Only / End of Life)
- **Composer vulnerabilities** - packages in `composer.lock` checked against the [OSV vulnerability database](https://osv.dev)
- **npm vulnerabilities** - packages in `package-lock.json` checked against OSV
- **Update history** - snapshot of versions and counts is recorded whenever any tracked value changes, viewable in the utility's **History** tab. Retained for 365 days. Each snapshot also stores per-package installed versions so update diffs can be reconstructed later.

## Reporting
- **Email status report** - super admins can send the full current audit to up to 10 recipients from the utility's **Status Report** tab.
- **Email update report** - super admins can send a diff between the two most recent snapshots (platform version changes, packages updated/added/removed, vulnerabilities resolved/introduced) from the utility's **Update Report** tab. Run an update, hit **Refresh** to capture a fresh snapshot, then click **Send Update Report** - recipients see exactly what moved. If nothing changed since the last snapshot, you can opt to resend the last meaningful diff via **Send anyway**.
- **Preview before sending** - both report tabs include a **Preview** button that opens the rendered email in a modal so you can see exactly what recipients will get before clicking Send.
- **Scheduled status reports** - the Status Report tab includes schedule controls (daily/weekly/monthly cadence, time, recipient list) below the manual send form. The addon auto-registers the matching Laravel scheduler entry on boot, and each scheduled run does a fresh scan first - so the email is current AND the CP's cached audit + history get updated for free. Update reports aren't scheduled - they're meant to verify a manual update + scan, so they're send-on-demand only. Requires the standard `* * * * * php artisan schedule:run` cron entry on the host.
- Both email send endpoints are rate-limited to 6 requests per minute.

## Content Freeze

Coordinate update windows with CP users. Schedule a heads-up email, show banners through the lifecycle of the work, and send an all-clear when done. Useful for client sites where editors and developers share the CP.

- **Scheduling** - super admins set two times on the utility's **Content Freeze** tab: when the heads-up email goes out, and when the freeze starts. Recipients are a comma-separated list (max 10).
- **Heads-up email** - sent automatically at the notification time. Tells recipients when the window starts and what to expect.
- **CP banners** - injected below the global header on every authenticated CP page, in normal document flow (no fixed overlay). Three states:
  - **Upcoming** (blue, dismissable) - shows from schedule through to freeze start. Includes a **Learn more** button that opens a modal mirroring the heads-up email. Dismissals are session-scoped, so the banner reappears the next time a user signs in.
  - **Active** (amber, non-dismissible) - shows once the freeze starts. Paired with a first-load modal per user (cookie-scoped to the freeze id, so each new freeze re-prompts).
  - **Complete** (green, dismissable) - briefly shown after the freeze ends.
- **Mark complete** - one-click in the CP (or `php please sentinel:freeze:complete`) sends the all-clear email and switches to the green banner. Available from any pre-complete state, so you can end early if the update finishes faster than scheduled.
- **Cancel freeze** - one-click in the CP. Aborts a scheduled or notified freeze without sending the all-clear email. The confirm prompt adapts: from notified it warns that recipients already received the heads-up. Not available once the freeze is active - use Mark complete instead so the all-clear still goes out.
- **Email previews** - both the heads-up and all-clear emails have **Preview** buttons in the CP that render exactly what recipients will receive.
- **Front-end stays live** - the freeze only affects the CP. Visitors don't see anything.
- **CLI** - `php please sentinel:freeze:start "2026-05-13 08:00" "2026-05-13 09:00" --email=a@b.com` mirrors the CP form. Same validation, same emails.

State transitions are driven by every-minute cron (`sentinel:freeze:tick-notifications`, `sentinel:freeze:tick-activations`) registered by the addon. Production sites should rely on the standard `* * * * * php artisan schedule:run` cron entry. A fallback middleware on the `statamic.cp` group also ticks state on every CP request, so dev environments and shared hosts without scheduler access still see the expected transitions while an editor is in the CP. Both paths are idempotent.

Display timezone is configurable via `SENTINEL_FREEZE_TIMEZONE` (defaults to the Laravel app timezone). When the display tz differs from the server tz, times render in both side-by-side.

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

## Usage

Sentinel scans on demand, not on every CP load. After dependency updates, hit **Refresh** in the widget/utility header to re-read your lockfiles - the cached audit doesn't update on its own. See [USAGE.md](USAGE.md) for scanning, scheduling, and storage details.

## Branding (optional)

Sentinel ships unbranded by default - the widget, utility, and report emails attribute themselves to "Sentinel for Statamic". To rebrand for your agency, set any of these env vars in the host app's `.env`:

```env
SENTINEL_DEV_NAME='Your Agency'
SENTINEL_DEV_URL='https://your-agency.example/sentinel'
SENTINEL_DEV_EMAIL='hello@your-agency.example'
```

- `SENTINEL_DEV_NAME` - replaces the footer attribution with `This report was generated by Your Agency.` (linked to `SENTINEL_DEV_URL` when also set, plain text otherwise).
- `SENTINEL_DEV_EMAIL` - adds a `Need help with your website?` mailto button to the bottom of the status report email, pre-filling the subject with the site host. Hidden when unset.
- `SENTINEL_FREEZE_TIMEZONE` - display timezone for content-freeze times in the CP and freeze emails. Defaults to the Laravel app timezone. When different from the server tz, times render in both. Example: `SENTINEL_FREEZE_TIMEZONE='Europe/London'`.

## Requirements

- PHP 8.0+
- Statamic 3.3, 4.x, 5.x, or 6.x
- If your host app sets a Content Security Policy, the freeze banner and modal use inline `<style>` and `<script>` to position the overlay and the auto-open dialog. Allow `style-src 'unsafe-inline'` and `script-src 'unsafe-inline'` on CP routes, or whitelist Sentinel's inline assets via nonce/hash. With a strict CSP and no allowance, the banner falls back to a normal-flow position and the auto-open modal won't run - the addon's reporting and audit features are unaffected.

## Support

This addon is maintained by [D3 Creative](https://d3creative.uk). For enquiries about managed Statamic maintenance, visit [d3creative.uk/services/statamic-maintenance](https://d3creative.uk/services/statamic-maintenance).

## License

Released under the [MIT License](LICENSE).
