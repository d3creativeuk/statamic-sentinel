# Security Policy

## Reporting a vulnerability

If you believe you have found a security vulnerability in Sentinel, please report it privately. **Do not open a public GitHub issue for security problems.**

Report it via **[d3creative.uk/contact](https://d3creative.uk/contact?utm_source=sentinel&utm_medium=security-policy&utm_campaign=vulnerability-report)**.

Please include:

- A description of the issue and the impact you believe it has.
- Steps to reproduce (proof-of-concept, affected endpoint or view, request payload, etc.).
- The Sentinel version, Statamic version, PHP version, and host OS where you observed the issue.
- Any relevant logs, stack traces, or screenshots.


## What to expect

- Sentinel is a free addon maintained on a best-effort basis, so I cannot commit to a fixed response time.
- I will keep you updated on progress while I investigate, validate, and prepare a fix.
- Once a fix is released, I will credit reporters in the release notes unless you ask to remain anonymous.
- There is no paid bug bounty.

## Scope

In scope:

- The addon source in this repository (PHP, Blade views, JS in views, Composer manifest).
- The Control Panel widget and utility page, including the `?d3_refresh` manual scan.
- Every CP route under `cp/d3-sentinel/*`: report sends, email previews (including stored sent reports), schedule, maintenance plan and package note saves, history actions, the freeze banner, and Content Freeze schedule, complete and cancel.
- The three middlewares Sentinel adds to Statamic's `statamic.cp` group (freeze state, freeze banner, last-active tracking), which also run on the CP login screens.
- The artisan commands `sentinel:scan`, `sentinel:send-status-report`, `sentinel:freeze:start`, `sentinel:freeze:complete`, `sentinel:freeze:tick-notifications` and `sentinel:freeze:tick-activations`, and the scheduled tasks that run them.
- The emails Sentinel sends, and the files it stores under `storage/app/statamic-sentinel`.

Sentinel's permission model: reporting, history, scheduling, previews and Content Freeze are limited to super admins. A user with the `access sentinel utility` permission sees the read-only Current audit, the widget and Refresh. A way round either is in scope.

Out of scope:

- Vulnerabilities in Statamic, Laravel, PHP, or third-party packages themselves. Report those upstream.
- Vulnerabilities in the external services Sentinel queries (Packagist, npm registry, endoflife.date, OSV, statamic.com) - report those to the relevant project.
- Issues that require an attacker to already have super admin access in the host Statamic CP (Sentinel's send/schedule actions are intentionally gated to super admins).
- Findings produced by automated scanners with no demonstrated exploit path.

## Supported versions

Security fixes are issued for the latest minor release on each supported Statamic major:

| Statamic | PHP    | Supported |
| -------- | ------ | --------- |
| 6.x      | 8.2+   | Yes       |
| 5.x      | 8.2+   | Yes       |
| 4.x      | -      | No        |
| 3.3+     | -      | No        |

Sentinel 3.0 dropped Statamic 3.3 and 4.x, and PHP 8.0 and 8.1. Sites on those versions stay on Sentinel 2.x, which gets no further releases. Statamic 3 and 4 receive no security fixes upstream, so upgrade Statamic itself.
