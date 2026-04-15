# Contributing to WP Approve User

This guide is aimed at both human contributors and automated/remote coding agents. It documents the canonical commands so you can go from a fresh checkout to a running, testable environment without spelunking through the codebase.

For architecture notes (the `users_can_register` gate, the three-state approval model, the multisite code paths, etc.) see [`CLAUDE.md`](CLAUDE.md).

## Prerequisites

- Docker (for `wp-env`)
- Node.js 24+
- Composer 2.x / PHP 8.x on the host (for local `vendor/bin/phpcs`; CI uses PHP 8.4)

## Setup

```bash
npm ci
composer install
npm run start              # boots wp-env at http://localhost:8888
```

`npm run start` runs `lifecycleScripts.afterStart` from `.wp-env.json`, which enables `users_can_register` on both the development and test sites so the plugin's entry gate passes. Without that option enabled, `wp-approve-user.php` loads `noop.php` instead of the main class.

Admin login: `admin` / `password`.

## Linting

```bash
npm run lint               # runs PHPCS inside wp-env against phpcs.xml.dist
npm run lint:php:fix       # auto-fix what phpcbf can fix
```

Rules live in `phpcs.xml.dist` (WordPress + PHPCompatibilityWP, `testVersion` `7.4-`). CI (`.github/workflows/wpcs.yml`) runs the same ruleset via `composer install` + `./vendor/bin/phpcs`, so local and CI results match.

## PHPUnit

```bash
npm test                   # single-site PHPUnit
npm run test-php-multisite # multisite PHPUnit
```

Both run inside the `tests-cli` container. `tests/bootstrap.php` manually loads the plugin classes (it does not execute `wp-approve-user.php`), so activation and upgrade hooks are skipped in the PHPUnit harness.

## Playwright end-to-end

```bash
npm run test:e2e:install   # one-time: install chromium + system deps
npm run test:e2e           # run headless
npm run test:e2e:ui        # interactive UI mode
```

The harness in `tests/e2e/` drives the real site at `http://localhost:8888`, so wp-env must be started first (`npm run start`). Tests use `wp-env run cli wp …` inside `beforeAll` to create a pending user, then exercise the admin Approve row action.

## Stopping wp-env

```bash
npm run stop
npm run destroy            # fully teardown (DB + containers)
```

## Deployment

Tagged releases ship to wordpress.org via `.github/workflows/deploy.yml`. `.distignore` controls what is excluded from the plugin ZIP — add any new developer-only files there.
