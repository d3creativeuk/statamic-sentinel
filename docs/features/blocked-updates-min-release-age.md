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
2. `npmOutdated()` finds outdated packages as it does today.
3. `annotateReleaseAge()` fetches each outdated package's full registry document, reads
   the publish time of its `latest` version, and sets `blocked`, `blocked_until`, and
   `available_in_days`.
4. The blade view renders a **Blocked** pill and an "available in N days" line for any
   package where `blocked === true`.

Note: the `/{name}/latest` endpoint Sentinel already calls returns the version manifest
**without** publish times. The `time` map lives only on the full document at
`registry.npmjs.org/{name}`, so the annotate step does a second, smaller pool over just
the outdated subset (usually a handful of packages, not the whole tree).

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

```php
/**
 * Flag outdated npm packages whose latest release is younger than the
 * project's `min-release-age` guard. npm refuses to install these until they
 * age past the window, so `npm update` no-ops and Sentinel would otherwise
 * look wrong. We read each latest version's publish time from the full
 * registry document (the `/latest` manifest omits it) and mark the package
 * blocked, with the date it becomes installable.
 *
 * Fails open: a disabled guard or any registry error leaves every package
 * unblocked, so a genuine update is never hidden.
 */
protected function annotateReleaseAge(array $packages): array
{
    // Default everything to "not blocked" first.
    foreach ($packages as &$pkg) {
        $pkg['blocked']           = false;
        $pkg['blocked_until']     = null;
        $pkg['available_in_days'] = null;
    }
    unset($pkg);

    $days = $this->npmMinReleaseAgeDays();

    if ($days < 1 || empty($packages)) {
        return $packages;
    }

    $names = array_column($packages, 'name');

    try {
        $docs = Http::pool(fn ($pool) => array_map(
            fn ($name) => $pool->as($name)->timeout(5)->get("https://registry.npmjs.org/{$name}"),
            $names
        ));
    } catch (\Throwable $e) {
        return $packages;
    }

    $now    = CarbonImmutable::now('UTC');
    $cutoff = $now->subDays($days);

    foreach ($packages as &$pkg) {
        $doc = $docs[$pkg['name']] ?? null;

        if (! $this->isOkResponse($doc)) {
            continue;
        }

        // Version keys contain dots, so index the array directly rather than
        // using dot-notation data_get, which would treat "4.3.3" as a path.
        $time        = $doc->json('time') ?? [];
        $publishedAt = $time[$pkg['latest']] ?? null;

        if (! $publishedAt) {
            continue;
        }

        $published = CarbonImmutable::parse($publishedAt)->utc();

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

Each package in `outdated.packages` now carries three extra keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `blocked` | bool | npm will not install this yet because of `min-release-age` |
| `blocked_until` | string\|null | date the version becomes installable, `YYYY-MM-DD` |
| `available_in_days` | int\|null | whole days until it unblocks, minimum 1 |

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
- **Registry document missing the `time` entry or the request failing:** that package is
  left unblocked. We never hide a real update because of a lookup failure.
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

## Rollout notes

- Purely additive: existing keys on each package are untouched, so the widget, the email
  reports, and the update report keep working if they ignore the new fields.
- Worth mirroring the pill into `resources/views/emails/update-report.blade.php` and the
  widget later, so a blocked update reads consistently everywhere, but the CP utility view
  is the primary surface and a good first step.
- Ties directly into D3's own supply-chain-guard positioning: Sentinel now demonstrates
  the guard working rather than contradicting it.
