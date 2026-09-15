# Feature: flag npm updates blocked by `min-release-age`

## Summary

When a project sets `min-release-age` in its `.npmrc` (npm's supply-chain guard that
refuses to install any package version published within the last N days), Sentinel
currently reports those fresh releases as available updates. The user then runs
`npm update`, nothing changes, and Sentinel looks wrong even though it is right.

This feature closes that gap. Sentinel reads the project's `min-release-age`, checks the
publish date of each outdated package's latest version, and marks the ones npm is
holding back with a small **Blocked** pill next to the package name, plus the date the
update becomes installable.

The result reframes a confusing contradiction into a feature that visibly reinforces the
guard: "yes, a newer version exists; no, we are not installing it yet, on purpose."

## Background: why this happens

`min-release-age=7` in `.npmrc` tells npm 11.10+ to skip any version published less than
7 days ago. Internally npm converts it to a `before` cutoff (`before = now - 7 days`) and
resolves every install, including `npm update` and `npm install pkg@latest`, against that
cutoff.

So the registry can show a newer `latest` while npm quietly refuses to move:

| Tool | What it reports | Why |
| --- | --- | --- |
| `npm view tailwindcss version` | `4.3.3` | reads raw `dist-tags.latest`, ignores the guard |
| `npm outdated` / `npm update` | nothing to do | applies the `before` cutoff, so `latest` resolves to `4.3.2` |
| Sentinel (today) | `4.3.3` available | queries the registry directly, does not know about the guard |

A real example that triggered this write-up: `tailwindcss 4.3.3`, `@tailwindcss/postcss
4.3.3`, `vite 8.1.5`, and `postcss 8.5.22` all showed as available in Sentinel, but each
was published inside the 7 day window, so `npm update` correctly did nothing and the
lockfile never changed. Sentinel reads the lockfile for "current", so the four kept
showing until the versions aged out.

## Design constraints

- **No shelling out to npm.** Sentinel is pure PHP plus registry HTTP so it keeps working
  under Herd's PHP-FPM, where Node is off the PATH. This feature stays pure PHP: parse
  `.npmrc` directly, do not call `npm config`.
- **Fail open.** A disabled guard, a missing `.npmrc`, or a registry hiccup must never
  hide a genuine update. Every package defaults to "not blocked".
- **Match the existing visual language.** The pill reuses the amber the card already uses
  for the outdated state (`#f59e0b` family), inline styles, no new assets.

## Data flow

1. `npmMinReleaseAgeDays()` reads the guard window (days) from the nearest `.npmrc`.
2. `npmOutdated()` finds outdated packages as it does today, and parses each latest
   version's publish time out of the `/latest` manifest it already fetched (see below) into
   `published_at`.
3. `annotateReleaseAge()` uses `published_at` where it has one. Only packages without it
   fall back to the full registry document's `time` map. It then sets `blocked`,
   `blocked_until`, `available_in_days` and `release_age_unknown`.
4. The blade view renders a **Blocked** pill and an "available in N days" line for any
   package where `blocked === true`, and a neutral **Unchecked** hint where
   `release_age_unknown === true`.

### Where the publish time comes from

The documented place for publish times is the `time` map on the full document at
`registry.npmjs.org/{name}`. That document is far too big for large packages: vite's is
about 39 MB and tailwindcss's about 11 MB, and neither arrives inside the 5s timeout. In
production (lbf-com, Sep 2026) that timeout left vite 8.3.0 unflagged five days into a
7 day window, so Sentinel showed an update npm was refusing to install.

The `/{name}/latest` manifest has no `time` map, but it does carry
`_npmOperationalInternal.tmp`, which ends in the publish time as Unix milliseconds:

```
vite 8.3.0                "tmp/vite_8.3.0_1789039826195_0.15890598475191897"      -> 2026-09-10T11:30:26Z
@alpinejs/collapse 3.17.3 "tmp/collapse_3.17.3_1789410036019_0.655826726596169"  -> 2026-09-14T18:20:36Z
```

These match the full document's `time` map to the second. Scoped packages use the unscoped
basename, so `npmPublishedAtFromManifest()` anchors the parse on the tail
(`/_(\d{13})_[\d.]+$/`), not the package name.

The field is **undocumented and internal to npm**, so it is a fast path only. A value that
doesn't parse, or isn't a plausible timestamp (before 2010, or more than an hour in the
future), is treated as missing and that package falls back to the full document.

Alternatives that were ruled out:

- **gzip on the full document** gets vite down to about 4.6 MB, but PHP still has to
  `json_decode` 39 MB during a scan.
- **Abbreviated metadata** (`Accept: application/vnd.npm.install-v1+json`) is small but
  only carries `modified`, not per-version times.
- **Search API** (`/-/v1/search`) has a date for latest, but matches on text rather than
  exact name, and its index can lag behind publishes.

## Implementation

### 1. Read the guard window (`AuditService`)

```php
use Carbon\CarbonImmutable;

/**
 * Read npm's `min-release-age` guard (in days) from the nearest .npmrc.
 * Project .npmrc wins over the user's ~/.npmrc, mirroring npm's own
 * precedence; an absent key falls through to the next file. Returns 0 when
 * the guard is missing or disabled, in which case nothing is ever blocked.
 *
 * Parsed in pure PHP on purpose: Sentinel never shells out to npm, so it
 * keeps working under Herd's PHP-FPM where Node is off the PATH.
 */
protected function npmMinReleaseAgeDays(): int
{
    $candidates = [
        base_path('.npmrc'),
        rtrim((string) getenv('HOME'), '/').'/.npmrc',
    ];

    foreach ($candidates as $path) {
        if (! is_file($path) || ! is_readable($path)) {
            continue;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if ($key === 'min-release-age') {
                return max(0, (int) $value);
            }
        }
    }

    return 0;
}
```

### 2. Annotate outdated packages (`AuditService`)

Call `annotateReleaseAge()` at the end of `npmOutdated()`, just before returning:

```php
// Annotate each outdated package with npm's min-release-age guard so the
// UI can explain why `npm update` leaves them behind.
$outdated = $this->annotateReleaseAge($outdated);

return ['total' => count($outdated), 'packages' => $outdated];
```

`npmOutdated()` also records each outdated package's publish time from the `/latest`
manifest it already fetched:

```php
$outdated[] = [
    'name'         => $name,
    'current'      => $current,
    'latest'       => $latest,
    'published_at' => $this->npmPublishedAtFromManifest($response->json('_npmOperationalInternal.tmp')),
];
```

```php
protected function npmPublishedAtFromManifest($tmp): ?string
{
    if (! is_string($tmp) || ! preg_match('/_(\d{13})_[\d.]+$/', $tmp, $m)) {
        return null;
    }

    $published = CarbonImmutable::createFromTimestampUTC(intdiv((int) $m[1], 1000));

    if ($published->year < 2010 || $published->greaterThan(CarbonImmutable::now('UTC')->addHour())) {
        return null;
    }

    return $published->toIso8601String();
}
```

```php
/**
 * Flag outdated npm packages whose latest release is younger than the
 * project's `min-release-age` guard. The publish time normally comes from the
 * `/latest` manifest; only packages without one fall back to the full
 * registry document's `time` map.
 *
 * Fails open: a disabled guard or any registry error leaves every package
 * unblocked, so a genuine update is never hidden. When the guard is on but
 * no publish time could be found, the row is marked `release_age_unknown`.
 */
protected function annotateReleaseAge(array $packages): array
{
    // Default everything to "not blocked" first.
    foreach ($packages as &$pkg) {
        $pkg['blocked']             = false;
        $pkg['blocked_until']       = null;
        $pkg['available_in_days']   = null;
        $pkg['release_age_unknown'] = false;
    }
    unset($pkg);

    $days = $this->npmMinReleaseAgeDays();

    if ($days < 1 || empty($packages)) {
        return $packages;
    }

    $needDoc = array_column(
        array_filter($packages, fn ($pkg) => empty($pkg['published_at'])),
        'name'
    );

    $docs = [];

    if (! empty($needDoc)) {
        try {
            $docs = Http::pool(fn ($pool) => array_map(
                fn ($name) => $pool->as($name)->timeout(5)->get("https://registry.npmjs.org/{$name}"),
                $needDoc
            ));
        } catch (\Throwable $e) {
            $docs = [];
        }
    }

    $now    = CarbonImmutable::now('UTC');
    $cutoff = $now->subDays($days);

    foreach ($packages as &$pkg) {
        $publishedAt = $pkg['published_at'] ?? null;

        if (! $publishedAt) {
            $doc = $docs[$pkg['name']] ?? null;

            if ($this->isOkResponse($doc)) {
                // Version keys contain dots, so index the array directly rather than
                // using dot-notation data_get, which would treat "4.3.3" as a path.
                $time        = $doc->json('time');
                $publishedAt = is_array($time) ? ($time[$pkg['latest']] ?? null) : null;
            }
        }

        try {
            $published = $publishedAt ? CarbonImmutable::parse($publishedAt)->utc() : null;
        } catch (\Throwable $e) {
            $published = null;
        }

        if (! $published) {
            $pkg['release_age_unknown'] = true;
            continue;
        }

        $pkg['published_at'] = $published->toIso8601String();

        if ($published->greaterThan($cutoff)) {
            $available   = $published->addDays($days);
            $secondsLeft = $available->getTimestamp() - $now->getTimestamp();

            $pkg['blocked']           = true;
            $pkg['blocked_until']     = $available->toDateString();
            $pkg['available_in_days'] = max(1, (int) ceil($secondsLeft / 86400));
        }
    }
    unset($pkg);

    return $packages;
}
```

Each package in `outdated.packages` now carries these extra keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `published_at` | string\|null | ISO 8601 publish time of `latest`, from the manifest or the fallback |
| `blocked` | bool | npm will not install this yet because of `min-release-age` |
| `blocked_until` | string\|null | date the version becomes installable, `YYYY-MM-DD` |
| `available_in_days` | int\|null | whole days until it unblocks, minimum 1 |
| `release_age_unknown` | bool | the guard is on but no publish time could be found, so the check didn't run |

The audit is cached with `Cache::forever()`, so audits from before a key was added won't
have it until the next scan. Views read these keys with `!empty(...)`.

### 3. Render the pill (`resources/views/utilities/sentinel.blade.php`)

In the npm updates list (the `@foreach($packages as $i => $pkg)` block around line 479),
add the pill beside the package name and the countdown beside the version.

Replace the name block:

```blade
<div style="display:flex; align-items:center; gap:6px; min-width:0;">
    <div style="font-size:13px; font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $pkg['name'] }}</div>
    @if(!empty($pkg['blocked']))
        <span title="Held by npm's min-release-age guard until {{ $pkg['blocked_until'] }}"
              style="display:inline-flex; align-items:center; gap:4px; flex-shrink:0; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:1px 6px; border-radius:4px; color:#92400e; background:#fef3c7; border:1px solid #fcd34d; cursor:default;">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="7" width="10" height="7" rx="1.5" />
                <path d="M5 7V5a3 3 0 0 1 6 0v2" />
            </svg>
            Blocked
        </span>
    @endif
</div>
```

Replace the version span on the right with a stacked version plus countdown:

```blade
<span style="display:flex; flex-direction:column; align-items:flex-end; gap:1px; flex-shrink:0;">
    <span style="font-size:11px; font-weight:500; color:#0f172a; font-variant-numeric:tabular-nums;">{{ $pkg['current'] }} &rarr; {{ $pkg['latest'] }}</span>
    @if(!empty($pkg['blocked']))
        <span style="font-size:10px; color:#b45309; font-variant-numeric:tabular-nums;">available in {{ $pkg['available_in_days'] }} {{ $pkg['available_in_days'] === 1 ? 'day' : 'days' }}</span>
    @endif
</span>
```

When the check couldn't run (`release_age_unknown`), a neutral **Unchecked** hint sits
beside the version instead, in the same slate as the Blocked pill but with a dashed border,
and a tooltip reading "Release age unchecked: registry lookup failed, ...". The row is
still shown as an available update (fail open), and it is not counted in "(N blocked)".

### 4. Optional: count the blocked ones in the toggle label

At the "N updates available" button (around line 472):

```blade
@php $blockedCount = collect($packages)->where('blocked', true)->count(); @endphp
<span>{{ count($packages) }} {{ count($packages) === 1 ? 'update' : 'updates' }} available@if($blockedCount) ({{ $blockedCount }} blocked)@endif</span>
```

## Edge cases

- **No `.npmrc` or no `min-release-age`:** `npmMinReleaseAgeDays()` returns 0, the annotate
  step short-circuits, nothing is ever blocked.
- **`min-release-age=0`:** treated as disabled, no blocking.
- **No usable `tmp` in the manifest:** that package falls back to the full registry
  document's `time` map.
- **Fallback document missing the `time` entry or the request failing** (for example a
  timeout on a very large package): that package is left unblocked, so we never hide a real
  update because of a lookup failure, but it is marked `release_age_unknown` and the view
  shows it as **Unchecked**.
- **Guard disabled:** `release_age_unknown` is never set, because there is nothing to check.
- **Precedence:** project `.npmrc` is checked before the user's `~/.npmrc`, matching npm.
  A project file that lacks the key falls through to the user file.
- **Timezone:** publish times are compared in UTC to match the registry.
- **Scoped packages** (`@tailwindcss/postcss`): handled natively by the registry URL, same
  as the existing `/latest` calls.

## Tests

Add to `tests/Unit/AuditServiceOutdatedTest.php`, following the existing `Http::fake` and
partial-mock pattern:

```php
public function test_outdated_npm_package_is_flagged_when_inside_the_release_age_window(): void
{
    $service = Mockery::mock(AuditService::class)->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $service->shouldReceive('npmInstalledDirect')->andReturn([
        'tailwindcss' => '4.3.2',
    ]);
    $service->shouldReceive('npmMinReleaseAgeDays')->andReturn(7);

    Http::fake([
        'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
        'registry.npmjs.org/tailwindcss' => Http::response([
            'time' => [
                '4.3.2' => '2026-06-29T14:30:01.000Z',
                // Published well inside a 7 day window relative to a "now" the
                // test controls (see note below).
                '4.3.3' => now()->subDays(1)->toIso8601String(),
            ],
        ]),
    ]);

    $method = new ReflectionMethod($service, 'npmOutdated');
    $method->setAccessible(true);

    $result = $method->invoke($service);

    $this->assertSame(1, $result['total']);
    $this->assertTrue($result['packages'][0]['blocked']);
    $this->assertSame(6, $result['packages'][0]['available_in_days']);
}

public function test_outdated_npm_package_is_not_flagged_once_it_ages_out(): void
{
    $service = Mockery::mock(AuditService::class)->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $service->shouldReceive('npmInstalledDirect')->andReturn([
        'tailwindcss' => '4.3.2',
    ]);
    $service->shouldReceive('npmMinReleaseAgeDays')->andReturn(7);

    Http::fake([
        'registry.npmjs.org/tailwindcss/latest' => Http::response(['version' => '4.3.3']),
        'registry.npmjs.org/tailwindcss' => Http::response([
            'time' => ['4.3.3' => now()->subDays(30)->toIso8601String()],
        ]),
    ]);

    $method = new ReflectionMethod($service, 'npmOutdated');
    $method->setAccessible(true);

    $result = $method->invoke($service);

    $this->assertFalse($result['packages'][0]['blocked']);
    $this->assertNull($result['packages'][0]['available_in_days']);
}
```

Because `annotateReleaseAge()` uses the real clock, either freeze time in the test
(`CarbonImmutable::setTestNow(...)`) or assert with a tolerance on `available_in_days`.
Freezing is cleaner and lets you assert the exact `blocked_until` date too.

These two fake `/latest` with only `version`, so they exercise the full-document fallback.
The fast path and the failure marking have their own tests in the same file:

- `/latest` with a `tmp` inside the window: blocked with the exact `blocked_until`, and
  `Http::assertNotSent` confirms the full document was never requested.
- A scoped package (`@alpinejs/collapse`) whose `tmp` uses the basename only.
- `tmp` outside the window: not blocked, still no full-document request.
- Missing or malformed `tmp` (no timestamp, not a string, before 2010, in the future):
  falls back to the full document's `time` map.
- The fallback fails (`ConnectionException` or a 500): `blocked = false`,
  `release_age_unknown = true`.
- Guard disabled: no row gets `release_age_unknown`.

## Rollout notes

- Purely additive: existing keys on each package are untouched, so the widget, the email
  reports, and the update report keep working if they ignore the new fields.
- Worth mirroring the pill into `resources/views/emails/update-report.blade.php` and the
  widget later, so a blocked update reads consistently everywhere, but the CP utility view
  is the primary surface and a good first step.
- Ties directly into D3's own supply-chain-guard positioning: Sentinel now demonstrates
  the guard working rather than contradicting it.
