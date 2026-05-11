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

## Content Freeze

Content Freeze is a coordinated update-window workflow. The lifecycle has four states, driven by two timestamps the admin sets at schedule time:

1. **Scheduled** - waiting for the notification time. Nothing visible to other CP users yet.
2. **Notified** - heads-up email has been sent. Still no banner. Waiting for the freeze start time.
3. **Active** - the banner is up. Editors see an amber "update in progress" strip at the top of every CP page, and a one-shot modal the first time they load any CP page during the window.
4. **Complete** - the all-clear email has been sent and the banner switches to a green dismissible "update complete" message until each user dismisses it (per-user, per-freeze cookie).

### What triggers each transition

- `scheduled` -> `notified`: the every-minute scheduler command `sentinel:freeze:tick-notifications` fires the heads-up email when `notify_at` is reached.
- `notified` -> `active`: the every-minute `sentinel:freeze:tick-activations` flips the banner on when `freeze_at` is reached. No email is sent at this step.
- `active` -> `complete`: a super-admin clicks **Mark as complete** in the CP, or runs `php please sentinel:freeze:complete`. The all-clear email goes to the recipients captured at schedule time, and the record moves from the current-freeze file to the history file.

Both tick commands are no-ops when there's nothing to do. Both use `withoutOverlapping` and check the freeze's current status before transitioning, so duplicate runs are safe.

### Cookies

The CP-wide injector reads two cookies to scope dismissal state per freeze ID:

- `sentinel_freeze_modal_seen_{id}` - set when the user dismisses the active-phase modal. 30-day expiry. Prevents the modal from reappearing on subsequent loads.
- `sentinel_freeze_dismissed_{id}` - set when the user closes the green "update complete" banner. 30-day expiry. Hides the banner without a page reload.

Because cookie names include the freeze ID, the next freeze re-prompts every user cleanly. Clearing cookies during a freeze re-shows the modal once - harmless.

### Validation rules

- `notify_at` must be at least 5 minutes from now.
- `freeze_at` must be strictly after `notify_at`.
- At least one recipient, max 10, each a valid email.
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

All of it is per-environment runtime state - regenerable from `composer.lock`, `package-lock.json`, and the live OSV/Packagist/npm APIs. Laravel's default `.gitignore` already covers `storage/app/`, so these files aren't (and shouldn't be) tracked in git. Back them up with the rest of `storage/` if you want to preserve the sent archive across environment moves.
