# Security Policy

## Reporting a vulnerability

If you believe you have found a security vulnerability in Sentinel, please report it privately. **Do not open a public GitHub issue for security problems.**

Email: **support@d3creative.uk**

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
- The Control Panel widget and utility page rendered by Sentinel.
- The CP routes registered by Sentinel (status report send, update report send, schedule config, history).
- The artisan command `sentinel:scan`.

Out of scope:

- Vulnerabilities in Statamic, Laravel, PHP, or third-party packages themselves. Report those upstream.
- Vulnerabilities in the external services Sentinel queries (Packagist, npm registry, endoflife.date, OSV) - report those to the relevant project.
- Issues that require an attacker to already have super admin access in the host Statamic CP (Sentinel's send/schedule actions are intentionally gated to super admins).
- Findings produced by automated scanners with no demonstrated exploit path.

## Supported versions

Security fixes are issued for the latest minor release on each supported Statamic major:

| Statamic | PHP    | Supported |
| -------- | ------ | --------- |
| 6.x      | 8.2+   | Yes       |
| 5.x      | 8.2+   | Yes       |
| 4.x      | 8.1+   | Yes       |
| 3.3+     | 8.0+   | Yes       |

Older Statamic majors and PHP versions outside the matrix above will not receive backported fixes.
