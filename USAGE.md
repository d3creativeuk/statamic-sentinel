# Sentinel Usage

Operational notes for running Sentinel in a Statamic project. For installation, requirements, and a feature overview, see the [README](README.md).

## How scanning works

Sentinel does not scan when you load the Control Panel - that would block the dashboard while it talks to several external APIs.

Instead:

- **First install:** the widget shows a **Scan Now** button. Click it once to run your first scan (10-20 seconds).
- **Manual refresh:** the **Refresh** link in the widget/utility header forces an immediate re-check at any time.
- **CLI:** run `php artisan sentinel:scan` to trigger a scan from the terminal. It exits non-zero if the vulnerability or update check failed.
- **Scheduled:** set `SENTINEL_SCAN_SCHEDULE` to `daily`, `weekly` or a cron expression and Sentinel registers the scan with Laravel's scheduler (the host needs the standard `schedule:run` cron entry). A scheduled status report also scans before it sends.

Results are cached using the host's default cache store (`CACHE_STORE`) and mirrored to `storage/app/statamic-sentinel/audit.json`. They persist until the next scan overwrites them, and the disk mirror means a `cache:clear` (common after `composer update`) won't wipe your last scan - on the next read, the cache is rehydrated from disk.

> **After updating dependencies:** on every CP load Sentinel compares the cached scan with the live `composer.lock`, `package-lock.json`, Statamic, Laravel and PHP versions. Installed versions, the updates list, "N behind" counts, support status and security flags follow a `composer update` or `npm install` straight away. What can't change without a scan is anything that needs the network: newly published releases and advisories, and vulnerabilities in packages that weren't installed before. Hit **Refresh** (or run `php artisan sentinel:scan`) for those.

## What gets scanned vs. what gets shown

Sentinel scans **every package in your lockfile** (direct + transitive) for known vulnerabilities via OSV - so a CVE in something deep in the dependency tree like `axios` (pulled in by `laravel-precognition-alpine`, for example) will still surface under **Security issues**.

The **Updates available** list, however, only shows your **direct dependencies** - the packages you've added to `composer.json` / `package.json` yourself. Transitives are filtered out for two reasons:

- The list would otherwise explode to hundreds of entries and bury the actionable signal.
- You can't update a transitive directly anyway; it'll move when its parent package releases a new version.

So if you don't see a transitive package in the updates list, it's not being ignored - it's being scanned, just not surfaced as actionable until either it has a known vulnerability or its parent gets an update.

## Package notes

Sometimes a vulnerable package can't be updated straight away, for example `braces`, which comes in through Tailwind v3 and only moves when the site does. To explain that to whoever receives the update report, open **Security issues** in the utility and use **Add note** on the package. Only super admins can add or edit notes.

- A note on a direct dependency (`tailwindcss`) also covers the packages it pulls in (`braces`, `postcss-selector-parser`).
- The update report's **Vulnerabilities** section lists issues that are resolved, new or still open since the previous scan. Each package, or group under the package that pulls it in, has its note underneath while the issue is new or still open, so the note keeps appearing for as long as the issue stays open. Resolved packages never show a note. Notes don't change any counts, and they don't appear in the status report or Plan Summary.
- Notes are read when the email is rendered, so a preview or **Send anyway** always shows the current text. Remove a note once it no longer applies.

## Content Freeze

Content Freeze is a coordinated update-window workflow. The lifecycle has four states, driven by two timestamps the admin sets at schedule time:

1. **Scheduled** - waiting for the notification time. CP users already see a blue "Notification of planned work" banner with the start time, and a modal with the details once per browser session.
2. **Notified** - heads-up email has been sent. The blue banner stays up until the freeze start time.
3. **Active** - the amber banner is up. Editors see an amber "update in progress" strip at the top of every CP page, and a one-shot modal the first time they load any CP page during the window.
4. **Complete** - the all-clear email has been sent and the banner switches to a green dismissible "update complete" message, shown for 7 days or until it's dismissed (a per-browser, per-freeze cookie, so it shows again in another browser).

### What triggers each transition

- `scheduled` -> `notified`: the every-minute scheduler command `sentinel:freeze:tick-notifications` fires the heads-up email when `notify_at` is reached.
- `notified` -> `active`: the every-minute `sentinel:freeze:tick-activations` flips the banner on when `freeze_at` is reached. No email is sent at this step.
- `active` -> `complete`: a super-admin clicks **Mark as complete** in the CP, or runs `php please sentinel:freeze:complete`. The all-clear email goes to the recipients captured at schedule time, and the record moves from the current-freeze file to the history file.

Both tick commands are no-ops when there's nothing to do. Both use `withoutOverlapping` and check the freeze's current status before transitioning, so duplicate runs are safe.

### Dismissal state

The CP-wide injector remembers dismissals per freeze ID:

- **Upcoming and active modals** - marked as seen in `sessionStorage`, keyed by the freeze ID and the user's session, so each modal opens once per browser session and again after the user signs back in.
- **Complete banner** - `sentinel_freeze_dismissed_{id}` cookie, set when the user closes the green banner (30-day expiry). The banner itself only shows for 7 days after the freeze completes.

Because the keys include the freeze ID, the next freeze prompts every user again.

### Validation rules

- `notify_at` must be at least 5 minutes from now.
- `freeze_at` must be strictly after `notify_at`.
- At least one recipient, max 10, each a valid email.
- Expected duration, if given, is at most 60 days.
- Only one freeze can be scheduled or active at a time.

All four are checked server-side by `ContentFreezeService::schedule()` whether you come in via the CP form or the CLI command - the validation rules are not duplicated.

## Where data lives

Sentinel writes runtime state to the host app's `storage/app/` directory under `statamic-sentinel/`:

- `audit.json` - disk mirror of the last scan, so a `cache:clear` doesn't wipe it
- `history.json` - rolling 365-day snapshot history (one entry per change)
- `last-update-report.json` - the most recent meaningful diff, used by **Send anyway**
- `schedule.json` - scheduled status report config (cadence, time, recipients)
- `sent/index.json` + `sent/{id}.html` - log and rendered HTML of every report sent, capped per kind
- `content-freeze.json` - the current freeze record (if one is scheduled / notified / active)
- `content-freeze-history.json` - completed freeze history, newest first, capped at 50
- `content-freeze-last-cancel.json` - when the last freeze was cancelled, so an older completed freeze's banner doesn't come back
- `maintenance-plan.json` - Plan Summary settings (plan name, start and expiry dates)
- `package-notes.json` - notes on packages, shown in the update report
- `last-active.json` - each CP user's last-active time for the Users tab (timestamps only, kept 30 days)

Two things live in the host's cache store rather than on disk: the audit itself (`d3creative_sentinel_audit`, mirrored to `audit.json` above) and a summary of each OSV advisory (`d3creative_sentinel_osv_summaries`), which lets repeat scans skip re-downloading advisories that haven't changed. Losing either to `cache:clear` only costs a slower next scan.

All of it is per-environment runtime state - regenerable from `composer.lock`, `package-lock.json`, and the live OSV/Packagist/npm APIs. Laravel's default `.gitignore` already covers `storage/app/`, so these files aren't (and shouldn't be) tracked in git. Back them up with the rest of `storage/` if you want to preserve the sent archive across environment moves.
