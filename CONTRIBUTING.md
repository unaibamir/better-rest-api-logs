# Contributing to Better REST API Logs

## Local dev setup

1. Clone the repo: `git clone https://github.com/unaibamir/better-rest-api-logs.git`
2. `composer install` (installs dev dependencies and generates the classmap autoloader).
3. `ddev start && ddev wp core install --url='https://better-rest-api-logs.ddev.site' --title='Dev' --admin_user=admin --admin_password=admin --admin_email=admin@example.test`
4. Symlink the plugin into the DDEV WordPress install. Phase 1 ships the bare DDEV config; a future phase may add a `post-start` hook to automate the symlink.

## Pre-commit hooks (optional but recommended)

Activate the repo's hooks with one command:

```bash
git config core.hooksPath .githooks
```

This runs PHPCS on staged PHP files and the banlist scan before every commit. CI runs the same checks unconditionally — local hooks are a faster-feedback convenience, not a gate.

## Switching PHP versions locally

```bash
ddev config --php-version=7.4 && ddev restart
ddev config --php-version=8.4 && ddev restart
```

The four overlay files in `.ddev/` (`config.php74.yaml`, `config.php82.yaml`, `config.php83.yaml`, `config.php84.yaml`) mirror the CI matrix exactly. Copy one over `.ddev/config.yaml` as an alternative to the `ddev config` command above.

## Running tests

```bash
composer test
composer test:unit
composer test:integration
```

## Style + static analysis

```bash
composer phpcs
composer phpcs:fix
composer phpstan
composer ci
```

## CI matrix

Pull requests trigger a 16-cell PHPUnit test matrix PHP {7.4, 8.2, 8.3, 8.4} × WP {6.6, 6.7, 6.8, 6.9}, plus separate jobs for PHPCS lint, PHPStan, plugin-check (against the dist zip), and banlist — total ~20 jobs. All must pass.

## Banlist

The plugin forbids `eval()`, `create_function()`, `base64_decode(...)` adjacent to `eval()`, and `__return_true` as a value for REST `permission_callback`. The single source of truth is `bin/check-banlist.sh` — the same script is invoked by the pre-commit hook (`SCAN_MODE=staged`) and by CI (`SCAN_MODE=full`).
