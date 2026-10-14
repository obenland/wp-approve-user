# AGENTS.md

Guidance for coding agents working in this repository. `CLAUDE.md` imports this file, so keep all shared instructions here.

## Overview

WP Approve User is a WordPress plugin that gates new user registrations behind an admin approval step. Unapproved users cannot log in (password or Application Password), and the plugin adds approve/unapprove row and bulk actions to the users list, a pending-approvals dashboard widget, rule-based auto-approval, and WP Abilities for MCP clients. The plugin is published on wordpress.org; the `trunk` branch is the release source and tags trigger `.github/workflows/deploy.yml`.

## Commands

Use Node 22 (`.nvmrc`). Node 24 breaks `wp-env start` and the `patch-package` postinstall step.

Three `@wordpress/env` configs exist, each with its own containers and port. All three enable `users_can_register` in an `afterStart` hook — without that option the plugin entry file loads `noop.php` and nothing else registers.

| Config | Port | Start | Used for |
| --- | --- | --- | --- |
| `.wp-env.json` | 8888 | `npm run start` | manual testing, `npm run lint:php`, Playwright single-site, `npm run screenshot` |
| `.wp-env.tests.json` | 8889 | `npm run start:tests` | PHPUnit (`npm test`, `npm run test-php-multisite`, coverage) |
| `.wp-env.multisite.json` | 8890 | `npm run start:multisite` | Playwright multisite (`npm run test:e2e:multisite`); mounts `tests/fixtures/mu-plugins/` |

If a port is taken by another project's wp-env, override it: `WP_ENV_PORT=8899 npm run start:tests`. Dev and multisite envs log in with `admin` / `password`.

```bash
npm ci && composer install  # wp-env, wp-scripts, Playwright; PHPUnit + WPCS into vendor/ (mounted into containers)
npm run start:tests
npm test                    # single-site PHPUnit
npm run test-php-multisite  # multisite PHPUnit (same DB, run sequentially)
npm run test-php-coverage   # writes coverage.xml inside the container
npm run get-coverage        # copy coverage.xml to the host
```

Run a single test method (wp-env drops a trailing `--`, so call PHPUnit directly):

```bash
npx wp-env run cli --config .wp-env.tests.json \
  --env-cwd=/var/www/html/wp-content/plugins/wp-approve-user \
  ./vendor/bin/phpunit -c phpunit.xml --filter test_name
```

`npm run lint` runs five linters; CI (`.github/workflows/wpcs.yml`) runs the same set, with PHPCS on the host via `composer install`.

- `lint:php` — PHPCS inside the default `cli` container (dev env must be running) against `phpcs.xml.dist`: WordPress + PHPCompatibilityWP, `testVersion 7.4-`, excludes the base class and `/lang/*`.
- `lint:js`, `lint:css` (`css/**/*.css` minus `*.min.css`), `lint:md`, `lint:pkg-json` — `wp-scripts`. `eslint.config.js` allows `no-console` in `scripts/` and `tests/e2e/` and allowlists `wp_approve_user` and `user_id` for camelcase.

### Playwright e2e

`tests/e2e/*.spec.js` drive `http://localhost:8888` (start `npm run start` first); `tests/e2e/multisite/` drives `http://localhost:8890`. `globalSetup` re-enables `users_can_register` via wp-cli, and specs seed users with `npx wp-env run cli wp …`. The multisite suite captures outgoing mail through the `wpau-mail-capture` mu-plugin fixture. Reports and traces land in `playwright-report/` and `test-results/`, both gitignored and distignored.

```bash
npm run test:e2e:install   # one-time chromium install
npm run test:e2e           # single-site
npm run test:e2e:multisite # needs npm run start:multisite
```

### Visual verification for agents

**Verify UI-affecting changes in a real browser, not just by passing tests.** `scripts/screenshot.js` (`npm run screenshot`) logs in as admin and captures any wp-admin route against the running dev env:

```bash
npm run start
npm run screenshot -- /wp-admin/users.php?role=wpau_pending pending.png
npm run screenshot -- /wp-admin/options-general.php?page=wp-approve-user settings.png
npm run screenshot -- /wp-login.php login.png -- --no-login   # skip auto-login
```

Read the generated PNGs before claiming a UI change is done. For multi-step flows, write a throwaway Node script that imports `@playwright/test`'s `chromium` and follows the login pattern in `scripts/screenshot.js`; delete it when done. Long-lived coverage belongs in `tests/e2e/`.

### Quickstart from zero

```bash
npm ci && composer install
npm run start && npm run start:tests
npm run lint
npm test && npm run test-php-multisite
npm run test:e2e:install && npm run test:e2e
```

## Architecture

### Bootstrap and the `users_can_register` gate

`wp-approve-user.php` is the plugin entry. Before anything else it checks `get_option( 'users_can_register' )`:

- **Registration disabled** → loads `noop.php` and returns. `noop.php` only shows an admin notice and hooks `pre_update_option_users_can_register` to mass-approve every existing user the moment registration gets turned on.
- **Registration enabled** → defines `$wpau_db_version` and loads the base class, the main class, `class-wpau-dashboard-widget.php`, `class-wpau-settings.php`, `cron-events.php`, `upgrade.php`, and `abilities.php`.

Any new top-level code in `wp-approve-user.php` must respect the gate or it will fatal on sites without registration (changelog entries 5, 8, 10). Minimum WordPress is 6.4, minimum PHP is 7.4.

### Class layering

- `class-obenland-wp-plugins-v5.php` — vendored base class shared across the author's plugins. Provides `hook()` (binds a method to the action/filter of the same name), textdomain handling, and settings plumbing. Treat as an external dependency; do not edit.
- `class-obenland-wp-approve-user.php` — `Obenland_Wp_Approve_User`, singleton created on `plugins_loaded` priority 0. Owns row/bulk actions, user views and `pre_user_query`, the admin menu bubble, the login gate (`wp_authenticate_user` for password logins, `wp_authenticate_application_password_errors` for REST/XML-RPC), registration handling, and emails. Register new hooks inside `plugins_loaded()` with `$this->hook()`.
- **Approval state changes only through** the static `mark_approved()` / `mark_unapproved()` helpers. They write the meta, destroy sessions on unapproval, and fire `wpau_approve` / `wpau_unapprove`. Route every new approval path through them.
- `class-wpau-dashboard-widget.php` — `WPAU_Dashboard_Widget`, the pending-approvals widget with `wp_ajax_wpau_dashboard_*` handlers; delegates to the mark helpers.
- `class-wpau-settings.php` — `WPAU_Settings`, the Settings API page (emails, auto-approval rules) under `options-general.php?page=wp-approve-user`, or the network settings menu on multisite.
- `abilities.php` — registers `wp-approve-user/approve` and `wp-approve-user/unapprove` via the WP Abilities API.

### Three-state approval model

`wp-approve-user` user meta holds one of `approved`, `unapproved`, `pending`. Missing meta means the user predates the plugin and is treated as approved; an empty value is not. Legacy boolean writes from third-party integrations are coerced to the strings by the `register_meta()` sanitize callback `sanitize_status_meta()`. `upgrade.php` runs `wpau_upgrade_to_N()` branches on `admin_init` while the `wpau_db_version` site option is behind `$wpau_db_version` (currently 13). Bump the constant and add a branch for schema changes.

User meta owned by the plugin (all cleared by `uninstall.php`): `wp-approve-user` (status), `wp-approve-user-mail-sent`, `wp-approve-user-new-registration` (distinguishes a fresh registration from a later unapproval for the rejection email), `wp-approve-user-ip` (captured at registration for IP-range auto-approval rules), `wp-approve-user-from-address-hint-dismissed`.

The main class computes `$pending_count` and `$unapproved_count` in its constructor via `get_users()` for the admin menu bubble and the `views_users` links (`?role=wpau_pending`, `?role=wpau_unapproved`); `pre_user_query()` rewrites those pseudo-roles into meta lookups.

### Activation and bulk approval cron

`register_activation_hook` calls `wpau_allowlist_users()` (`cron-events.php`), which approves up to 100 users per run and re-schedules `wpau_allowlist_users_cron` (+5s) until `count_users()['total_users']` is reached, to avoid timeouts on large sites. Keep the batch-and-reschedule pattern.

### Multisite

Multisite branches exist throughout: `network_admin_menu` replaces `admin_menu`, `ms_user_row_actions` mirrors `user_row_actions`, queries use `blog_id` 0 in network admin, and super admins and the site admin email user skip the approval gate. When adding admin-screen behavior, add both the single-site and the `-network` / `site-users-network` variants.

### Tests

`tests/bootstrap.php` loads the plugin classes, then loads `wp-approve-user.php` with `pre_option_users_can_register` forced true so coverage includes the entry file, `cron-events.php`, and `upgrade.php`. It then unhooks `wp_approve_user_instantiate`, so no main-class hooks are registered globally: each test creates its own instance and calls `plugins_loaded()` when it needs the hooks. `WP_UnitTestCase` restores `$wp_filter` after every test, so filters added inside a test need no manual removal. Activation hooks still do not run in the harness.

PHPUnit discovers `test-*.php` anywhere under the repo; keep tests in `tests/`, one file per class or concern, named after what they cover. CI (`.github/workflows/phpunit.yml`) runs PHP 7.4 and 8.4 against latest WordPress, plus PHP 7.4/WP 6.4 and PHP 8.4/WP trunk, single-site and multisite — avoid syntax that breaks 7.4.

## Project-specific rules

- **Three-state meta**: never assume boolean. Compare against `'approved'` / `'unapproved'` / `'pending'`.
- **`wpau_` prefix** for all global functions, hooks, and options. Textdomain is `wp-approve-user`.
- Public actions: `wpau_approve`, `wpau_unapprove`. Public filters: `wpau_default_options`, `wpau_update_message_handler`, `wpau_message_placeholders`, `wpau_auto_approve_rules` (the last is not yet in `README.md`). Document new hooks in `README.md`; don't rename without a deprecation path.
- **No JS/CSS build step.** Each file in `js/` and `css/` has a hand-maintained `.min` sibling. Classes enqueue the `.min` variant unless `SCRIPT_DEBUG` is on, so update the minified file in the same commit as the source.
- `README.md` is the wordpress.org readme (changelog, upgrade notice, hook docs). Bump the version in the plugin header, `Stable tag`, and `package.json` together.
- `.distignore` controls what ships to wordpress.org — add new dev-only files there (`AGENTS.md`, `CLAUDE.md`, `tests/`, `scripts/` are already excluded).
