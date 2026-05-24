# Better REST API Logs

A free, modern, lightweight WordPress plugin that logs every REST API request and response into dedicated custom database tables — not WordPress posts. Built for developers, site administrators, and integrators who need reliable visibility into what their REST API is doing, without the database bloat and broken filters of the abandoned upstream `wp-rest-api-log` plugin.

For users: see [`readme.txt`](readme.txt) (WP.org format).

## Status

Pre-1.0. Tooling, CI, and lifecycle hooks land first; capture, storage, REST API, admin UI, and CLI follow.

## Tech

- PHP 7.4 → 8.4 (7.4 floor for WP.org install reach)
- WordPress 6.6 → 7.0
- Composer classmap autoload over `includes/`
- WPCS 3.3 + PHPCompatibilityWP 2.1 (testVersion 7.4-)
- PHPStan level 6 (level 8 target in v1.1)
- PHPUnit 9.6 + wp-phpunit 6.4+ + yoast/phpunit-polyfills 4.0
- DDEV for local dev (multi-PHP overlays)

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
