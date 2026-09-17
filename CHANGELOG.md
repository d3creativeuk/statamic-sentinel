# Changelog

All notable changes to `d3creative/statamic-sentinel` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

### Added

- **Scheduled scans (opt-in).** Set `SENTINEL_SCAN_SCHEDULE` to `daily`, `weekly` or a cron
  expression to have Sentinel scan unattended through Laravel's scheduler. Off by default.

### Changed

- **The status email's PHP row shows the update within your PHP version first.** It used to show
  only the newest PHP release on any branch (e.g. 8.4.20 → 8.5.10), which hid that 8.4.25, with
  its bug and security fixes, was available without a major upgrade. The row now reads
  "8.4.20 → 8.4.25" with an "Update available" pill, adds "PHP 8.5.10 is also available" underneath,
  and shows "Security only" or "End of life" once the installed branch reaches those stages. Needs a
  fresh scan to pick up the branch data.
- **Clearer security counts in the status email.** The Composer and npm rows lead with the security
  issues pill, with a grey line underneath such as "72 security issues across 15 packages". The
  issue count is per advisory across every installed package, so next to a smaller "updates
  available" count it looked like the numbers didn't add up. Rows with no issues still show the
  updates count as their pill.
- **Desktop / Phone toggle on email previews.** Every preview in the utility (status, update and
  Plan Summary reports, sent emails, and the Notify emails) can switch to a 375px phone width,
  which applies the emails' own phone layout, so you can check it without sending a test.
- **Report emails stack each row.** In the status, update and Plan Summary emails, the status pills
  now sit under each row's description at every screen width, instead of in a right-hand column
  that squeezed the description onto several lines when a row had two pills. Statamic, Laravel and
  PHP show their version beside the title, and "Major version behind" is always their first pill.
  Package lists in the update report stay on one line.
- **Tab links match their names:** Notify is now `#notify` (was `#content-freeze`) and Plan Summary
  is `#plan-summary` (was `#maintenance-report`).
- **Lighter scans.** Every registry request now asks for gzip (Packagist's laravel/framework
  feed drops from about 1 MB to 100 KB). The Statamic and Laravel Packagist feeds are downloaded
  once per scan instead of twice. Statamic marketplace security lookups now only run for
  `statamic/cms` and Statamic addons, instead of one request per outdated Composer package that
  was never going to be on the marketplace.
- **Vulnerability details are cached between scans.** Sentinel keeps a small summary of each
  OSV advisory and only refetches it when OSV reports it as new or changed. On a stale test
  site that cut a repeat scan from 270 requests to 41, and from about 6s to under 4s.
- **Major version gaps get their own pill in the report email.** Statamic, Laravel and PHP rows
  a major version behind now show a solid red "Major version behind" pill (replacing "Outdated").
  It sits alongside "Security update" / "End of life" instead of being hidden by them, so a
  security flag no longer implies the fix needs the major upgrade.

### Security

- **Scans could be triggered by anyone who could see the dashboard, from any link.** Scan Now and
  Refresh ran a full scan (up to a few hundred outbound requests, inside the page load) for any
  request with `?d3_refresh`, including one started from another site while you were logged in,
  as often as it was loaded. A manual scan now needs Sentinel access and a link tied to your
  session, and runs at most once a minute with no two at the same time.
- **The dashboard widget now requires Sentinel access.** It shows the site's vulnerable packages,
  so users without the `access sentinel utility` permission (or super admin) no longer see it.
- **Hardening.** Sentinel's delete endpoints only accept super admins and their own delete action,
  rather than any registered action. The freeze banner, freeze check and activity tracking only run
  for users with Control Panel access, not any signed-in user (such as a front-end member).
- **Script injection through a CP user's name.** Statamic compiles the Control Panel's
  server-rendered utility and widget HTML as a Vue template (3.3-5 through the page shell, 6 through
  its dynamic HTML renderer), and Blade's escaping doesn't touch Vue's `{{ }}` syntax. A CP user
  could set their own name to a Vue expression and have it run in the browser of any super admin
  who opened Sentinel (the Users tab lists every user's name). The utility and widget now opt out
  of Vue compilation with `v-pre`.

### Fixed

- **Report emails clipped text on phones.** On narrow screens each row cell became full width plus
  its padding, so the right edge of descriptions (e.g. "runs everythi") was cut off by the rounded
  card. The cells now include their padding in that width.
- **Blocked npm updates missed on large packages.** The publish-time lookup fetched each
  package's full registry document, which for vite (about 39 MB) and tailwindcss (about 11 MB)
  timed out, so fresh releases showed as installable while npm's `min-release-age` guard was
  still refusing them. Sentinel now reads the publish time from the `/latest` manifest it
  already fetches, and only falls back to the full document when that's missing. If neither
  lookup works, the update is still listed but marked **Unchecked**, rather than looking
  installable.
- **Saved data stopped updating on Laravel 8 (Statamic 3.3).** Laravel 8's filesystem refuses to
  move a file over an existing one, so after each file's first write, the scan history, sent log,
  schedule, plan settings, user activity and Content Freeze state all silently stopped saving. The
  worst effect: a scheduled freeze never advanced past its heads-up, so the heads-up email was sent
  again every minute. Files are now replaced with a native atomic rename, and each write uses its
  own temp file so two overlapping saves can no longer wipe the sent log.
- **Content Freeze races that duplicated emails or revived a finished freeze.** Clicking Mark
  complete or Cancel while the heads-up email was sending could bring the freeze back, so its
  banner came on later and a second completion sent a second all-clear. The every-minute commands
  also skipped the lock the CP-request tick used, so both could send the heads-up email, and two
  admins completing at once each sent an all-clear. Every freeze state change now runs under one
  lock; Complete and Cancel wait briefly for an in-flight send and then re-check the freeze.
- **A vulnerability database outage looked like a clean site.** If OSV rate-limited or errored
  (429 / 5xx), the scan reported no vulnerabilities and cached that result. It now reports the
  check as failed. A failed check also no longer writes zeros into history, which made the update
  and Plan Summary reports claim every issue was resolved and then re-introduced on the next good
  scan; history keeps the previous figures for that ecosystem instead.
- **Nested npm packages were never checked for vulnerabilities.** A package installed inside
  another package's `node_modules` was sent to the vulnerability database under its whole install
  path, which never matches, so an older vulnerable copy deep in the tree went unreported (on one
  test site, a nested `qs` with its own advisory). npm aliases are now checked under the real
  package name, workspace folders are no longer sent as packages, v1 lockfiles are walked in full,
  and an advisory affecting two installed versions of a package is counted once.
- **Back button showed the wrong page after switching Sentinel tabs on Statamic 6.** Switching tabs
  cleared the browser history state that Statamic 6 uses to restore pages, so going to another CP
  page and pressing Back changed the URL but left that other page on screen.
- **Advisories without a GitHub severity were always "Unknown".** The fallback looked for a
  numeric score that OSV's CVSS vectors never contain. Sentinel now computes the CVSS 3.x (or 2.0)
  base score from the vector to place those advisories in Critical / High / Medium / Low. CVSS 4.0
  vectors are still reported as Unknown.
- **Stale security flags after a partial update.** Between scans Sentinel checks the installed
  versions, but a security flag, "N behind" count and support status only cleared when you
  updated all the way to the latest release. Updating past the security fix (but not to the
  newest version), or upgrading PHP or Laravel to a supported branch, kept the old warning until
  the next scan. Scans now store the version each fix arrived in, the newer releases and PHP's
  branch dates, so those details update as soon as the lockfile or runtime changes.
- **Branch installs were always outdated, with false security warnings.** A Composer package
  installed from a branch (`dev-main`, `2.x-dev`) always showed as outdated and picked up a vendor
  "security update" from every marketplace release. The vulnerability database also matched it
  against every old advisory (`laravel/framework` at `dev-master` returns 12). Branch installs are
  now left out of the update, vendor and vulnerability checks, since none of them can be compared
  with a release.
- **End-of-life versions didn't say so.** The version rows checked for an available update before
  end of life, and PHP always has a newer release, so an EOL PHP only ever showed as outdated. The
  widget also printed just the newest version, in red, so it looked like the site was already on
  it. Both now show the installed version, the latest version and "(EOL)" together.
- **The widget and full report disagreed on security issues.** The widget counted vendor-flagged
  security releases, but the report only listed them when the vulnerability database found
  nothing. With both present, the widget might say 3, the report 2, and the vendor release wasn't
  listed anywhere. The report now includes them in its count and lists them under the advisories.
- **Plan Summary figures.** A routine update to a package with an unfixed advisory counted as a
  security update; it now only counts when the update resolved at least one advisory. A plan start
  date after the latest scan no longer prints a date range that runs backwards. When a plan
  started before Sentinel's oldest kept record (history is kept for a year), the email now says
  where the records start instead of silently undercounting.
- **"Too Many Attempts" after a few clicks.** All of Sentinel's rate-limited actions shared one
  counter per user, each checked against its own limit, so opening a few previews and saving the
  schedule could block the next Send. Each action now has its own counter.
- **Page loads could hang on a Content Freeze email.** The freeze check ran inside every Control
  Panel request, including the login page, so on a site sending mail synchronously the request that
  reached the notification time waited for the email to send. It now runs after the page has been
  delivered, only for signed-in users, and skips the check entirely when no freeze is due.
- **Small layout glitches in the Control Panel.** Sending spinners, the schedule's day pickers and
  the "Send anyway" notice lost their spacing and alignment once shown. On Statamic 6, each visit to
  Sentinel also added another URL-change listener that was never removed. A sent-email record
  missing its id no longer breaks the whole page.
- **Accessibility and small screens.** Keyboard focus is visible again on inputs and help buttons,
  email fields have labels for screen readers, tabs and dialogs are properly announced, and the
  tab bar and send forms wrap instead of overflowing on phones.
- **Content Freeze details.** The schedule form's default notification time was usually too soon
  to pass the 5-minute minimum, so a first attempt with the defaults failed. The green "update
  complete" banner now only appears for 7 days after completion instead of indefinitely (it came
  back every 30 days). Expected duration is capped at 60 days, where an absurdly large value
  previously broke the heads-up email. The README and USAGE descriptions of the banners are
  corrected.
- **Scan edge cases.** Composer packages with a dot in their name (like `mtdowling/jmespath.php`)
  are now checked for updates. npm aliases (`"vue2": "npm:vue@^2"`) are checked against the real
  package, and local, workspace and git dependencies are no longer looked up on the registry. If
  the cache backend is unavailable, the Control Panel reads the last scan from disk instead of
  erroring. `php artisan sentinel:scan` now reports a failed vulnerability or update check and
  exits non-zero.
- **Report sending details.** A forced resend of the update report is dated by the scan it
  describes rather than the day it's sent. The "Invalid address" message now always names the
  address it rejected. Malformed form data returns a validation message instead of a server error.
  Scheduled status reports are sent once even when several servers run the scheduler, and a
  failed send-log write no longer leaves an orphaned email snapshot on disk.

## [2.1.1] - 2026-07-27

### Added

- **Users tab (who's online).** A new super-only tab in the utility lists every CP user with a live
  status (green dot + "Active ..." when seen within the online window, else "Last seen ...") and
  their last login. Statamic has no built-in "online users" concept, so a lightweight CP middleware
  records each authenticated user's last-active time (throttled to ~1 write/min per user, persisted
  so it survives `cache:clear`). Disable with `SENTINEL_TRACK_ACTIVITY=false`; tune the window with
  `SENTINEL_ONLINE_WINDOW` (minutes, default 5). Only timestamps are stored - no IP/user-agent.

### Fixed

- Plan Summary email header now dates from the plan start rather than the earliest recorded scan,
  so the "5 May 2026 to ..." range matches the "since your plan started on ..." intro line.

## [2.1.0] - 2026-07-24

### Added

- **Statamic License Status** on the dashboard widget, the utility page, and the status and
  update emails - a colour-coded pill reading Licensed / Renewal due / Not licensed / Trial /
  Free edition / Unverified, read from Statamic's cached Outpost data (Statamic 3.3-6, offline in
  the normal case), with a deep link to the statamic.com account when a renewal is due. Statamic
  exposes a needs-renewal signal rather than a calendar date, so the raw licensed version range is
  not shown to clients.
- **Blocked npm updates.** Reads `min-release-age` from `.npmrc` and flags updates npm is holding
  back with a "Blocked" pill and an "available in N days" countdown on the utility page. Pure PHP
  (never shells out to npm) and fails open, so a genuine update is never hidden.
- **Plan Summary report.** A new utility tab and on-demand email summarising the maintenance
  delivered since a plan's start date: how many times Statamic, Laravel and PHP were updated, the
  total Composer and npm package updates, and how many were security updates (with a critical/high
  breakdown). Plan name, start date and expiry date are saved settings; preview and send on demand.

### Changed

- History snapshots now also record each vulnerable package's highest severity (additive,
  forward-only) to power the Plan Summary's critical/high breakdown.

## [2.0.9] - 2026-07-02

### Added

- "N behind" release counts on the Statamic, Laravel and PHP version cards, mirroring the core
  Updater. Counts stable releases newer than what's installed (across majors); hidden when up to
  date.

## [2.0.8] - 2026-07-02

### Changed

- Security Issues rework: transitive (indirect) packages are nested under the direct dependency
  that pulls them in; one row per package with inline CVE/GHSA codes linking to the OSV record
  (High/Critical highlighted red, the rest grey); icons and severity pills removed; pills reserved
  for alert states only (security/EOL); remaining blue update indicators recoloured to neutral.

### Fixed

- CVE hover underline now applies under Statamic 6 (style injected into `<head>` to survive CP
  content extraction).

## [2.0.7] - 2026-07-01

### Changed

- Content Freeze renamed to **Notify** in the CP: the tab, schedule heading/button ("Schedule
  update"), window fields ("Update starts" / "Update ends"), history list ("Past notifications"),
  and all dialog/toast copy. User-facing wording only - routes, the `ContentFreezeService`, the
  `sentinel:freeze:*` commands, the `#content-freeze` anchor, stored keys and config are unchanged.

## [2.0.6] - 2026-07-01

### Added

- Heads-up email shows a "Statamic maintenance ends" box (the end time, styled like the "starts"
  box) beneath the timeframe sentence, when an end time is set.

### Changed

- Freeze times are picked with a date field plus a `type="time"` input that steps in 15-minute
  blocks, replacing the free datetime input; defaults snap to the next 15-minute boundary (times are
  still parsed at full precision server-side).
- Freeze times show timezone letters (e.g. `BST`) only when `SENTINEL_FREEZE_TIMEZONE` is set;
  unset (the default), they render in the app timezone without the abbreviation.

## [2.0.5] - 2026-07-01

### Fixed

- The Content Freeze **Preview heads-up email** now reflects the times and expected duration
  currently entered in the schedule form, instead of a fixed "now / +3 hours / 30 minutes"
  placeholder.

## [2.0.4] - 2026-07-01

### Added

- Content Freeze: optional "Freeze ends at" time and an "Expected duration" (minutes, hours, or
  days), surfaced in the heads-up email (e.g. "The update should only take up to 30 minutes, however
  a 3 hour window has been allowed..."). An end time before the start is warned about inline and
  rejected server-side. Both are informational and do not auto-end the freeze. The email eyebrow is
  renamed to "Notification of Planned Work".

## [2.0.3] - 2026-07-01

### Fixed

- On multisite installs, the Status/Update report emails, the Content Freeze heads-up / all-clear
  emails, and their CP previews reference **every** site rather than only the primary (hosts listed
  comma-separated in headers and subjects). Single-site installs render exactly as before.

## [2.0.2] - 2026-07-01

### Fixed

- The Sent Emails log no longer reports a report as **Sent** when the send actually failed. The send
  now runs inside a queued job (`SendSentinelMail`): a record starts as **Queued** and flips to
  **Sent** only after the transport accepts the message, or **Failed** (with the error) if not.

## [2.0.1] - 2026-07-01

### Fixed

- A refresh/scan no longer 500s when an upstream API (Packagist, the npm registry, or OSV) is
  briefly unreachable. `Http::pool()` returns a `ConnectionException` (not a `Response`) in a failed
  slot, which the old `->ok()` guard called into; all pool consumers now type-check the slot via a
  shared `isOkResponse()` helper. Adds regression coverage.

## [2.0.0] - 2026-06-30

### Changed (breaking)

- Config renamed `sentinel` -> `statamic-sentinel` (and `config/sentinel.php` ->
  `config/statamic-sentinel.php`) to match Statamic's addon-slug convention. **Env var names are
  unchanged**, so no `.env` edits are required; host code reading `config('sentinel...')` must
  switch to `config('statamic-sentinel...')`.

### Changed

- Branded for D3 Creative by default - the widget, utility and report emails attribute to D3
  Creative with no config. White-label via `SENTINEL_DEV_*`, or disable entirely with
  `SENTINEL_BRANDING=false`.
- One-off report fields pre-fill with the recipients used last.
- Status and update report emails stack their rows on mobile.

### Fixed

- Non-super users with the `access sentinel utility` permission no longer hit a `403` on the report
  preview; they now see a read-only Current view plus Refresh, with the super-only tabs hidden.
- Developer attribution survives `php artisan config:cache` when the config is published.

## [1.1.4] - 2026-05-26

### Changed

- The cached audit reconciles against live platform versions and lockfiles on every read, so the
  widget/utility stop showing a red "security update available" pill for a release already installed
  via `composer update`; outdated lists are pruned the same way (no HTTP).

### Fixed

- Freeze banner: a dismissed banner no longer flashes on page load, and hiding it clears the CP
  header/main/nav offsets instead of leaving them stuck.

## [1.1.3] - 2026-05-20

### Fixed

- Freeze notification email copy and header.

## [1.1.2] - 2026-05-20

### Changed

- The vendor security badge expands into a package list.

## [1.1.1] - 2026-05-20

### Changed

- The widget "View Report" button now respects the `access sentinel utility` permission, so editors
  without utility access no longer see a link that 403s.
- Trimmed the redundant preamble from the freeze notification email and the CP "learn more" modal.

## [1.1.0] - 2026-05-20

### Added

- **Content Freeze workflow**: coordinate Statamic update windows from a new utility tab - schedule
  a heads-up email, show a CP-wide banner through the work (upcoming/active/complete), and send an
  all-clear when done. State machine driven by an every-minute scheduler plus a request-time
  fallback; banner injection works across Statamic 3.3-6; CLI parity via `sentinel:freeze:start` /
  `:complete`; configurable display timezone via `SENTINEL_FREEZE_TIMEZONE`.
- **Vendor security check**: cross-references installed Statamic addons against the Statamic
  marketplace API for known advisories, alongside the existing OSV results.

### Changed

- Update report lists per-package vulnerability names so recipients see what resolved between
  snapshots.
- Email status reports queue rather than block the request, with hardened dispatch.
- Email report headlines split into a title and detail subline.
- Row deletion across History, Sent and Freeze History routes through Statamic's Action endpoints.

### Fixed

- Audit refresh is F5-safe: a stuck or partial scan no longer serves a half-built audit to the next
  CP load.

## [1.0.7] - 2026-05-07

### Fixed

- PHP version-bump tier fix; scan-scope documentation.

## [1.0.6] - 2026-05-07

### Changed

- Replaced the support email with a contact-page URL.

## [1.0.5] - 2026-05-07

### Added

- Surface major-version drift for PHP in the status email.

## [1.0.4] - 2026-05-07

### Changed

- Persist the audit to disk so scans survive `cache:clear`.

## [1.0.3] - 2026-05-07

### Added

- Maintenance CTA in the email report.

### Changed

- Faster scans and further email polish.

## [1.0.2] - 2026-05-06

### Changed

- Email report polish.

## [1.0.1] - 2026-05-05

### Changed

- Refined the addon description and README intro.

## [1.0.0] - 2026-05-05

### Added

- First public release: status and update reports, scheduled sends, OSV vulnerability scanning, and
  configurable branding.

## [0.1.0] - 2026-05-01

### Added

- Initial release.
