# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WP Approve User is a WordPress plugin that gates new user registrations behind an admin approval step. Unapproved users cannot access `wp-admin`, and the plugin adds approve/unapprove row and bulk actions to the users list. The plugin is published on wordpress.org; the `trunk` branch is the release source.

## Commands

Tests run inside a `@wordpress/env` container — the `npm run test-php*` scripts shell into the `tests-cli` container and call Composer inside the mounted plugin path. Start the env before running tests.

```bash
npm ci                      # install @wordpress/env
npm run wp-env start        # boot the WordPress test environment
npm run test-php            # single-site PHPUnit
npm run test-php-multisite  # multisite PHPUnit
npm run test-php-coverage   # coverage run; writes coverage.xml inside container
npm run get-coverage        # copy coverage.xml from container to host
```

Run a single test method inside the container:

```bash
npm run wp-env run tests-cli --env-cwd=/var/www/html/wp-content/plugins/wp-approve-user -- \
  composer test -- --filter test_name
```

PHPCS (WordPress coding standards, warnings treated as errors) runs in CI via 10up/wpcs-action — there is no local composer script for it. Match CI by running `phpcs --standard=WordPress` against changed files.

## Architecture

### Bootstrap and the `users_can_register` gate

`wp-approve-user.php` is the plugin entry. Before doing anything else it checks `get_option( 'users_can_register' )`:

- **Registration disabled** → loads `noop.php` and returns. `noop.php` only shows an admin notice and hooks `pre_update_option_users_can_register` to mass-approve every existing user the moment registration gets turned on (so enabling registration later doesn't retroactively lock everyone out).
- **Registration enabled** → loads the base class, the main class, `cron-events.php`, and `upgrade.php`.

This gate is the reason many past bugs existed (see changelog entries 5, 8, 10) — any new top-level code in `wp-approve-user.php` must respect it or it will fatal on sites without registration.

### Class layering

- `class-obenland-wp-plugins-v5.php` — vendored base class (`Obenland_Wp_Plugins_V5`) shared across the author's plugins. Provides `hook()` (auto-binds methods to actions/filters by name), textdomain handling, and settings plumbing. Treat as an external dependency; avoid editing it.
- `class-obenland-wp-approve-user.php` — `Obenland_Wp_Approve_User extends Obenland_Wp_Plugins_V5`. Singleton instantiated on `plugins_loaded` priority 0. Registers all row actions, bulk actions, views, pre_user_query filters, admin menu entries, settings page, and email handling. When adding a hook, register it inside `plugins_loaded()` using `$this->hook()` so the method name maps to the hook name.

### Three-state approval model

As of v12, `wp-approve-user` user meta holds one of three strings: `approved`, `unapproved`, `pending`. Older installs stored booleans — `upgrade.php` (`wpau_upgrade_to_12`) migrates `true → 'approved'` and `false → 'pending'` on `admin_init` when `wpau_db_version` site option is behind `$wpau_db_version` (defined in `wp-approve-user.php`). Bump `$wpau_db_version` and add a `wpau_upgrade_to_N()` branch for future schema changes.

Persistent user meta owned by the plugin (all cleared by `uninstall.php`):
- `wp-approve-user` — the three-state status.
- `wp-approve-user-mail-sent` — tracks whether the approval/unapproval email has been dispatched so it isn't sent twice.
- `wp-approve-user-new-registration` — flag used to distinguish a fresh registration from later unapprovals (drives the "only email rejection on new registration" logic from changelog entry 7).

The main class maintains two counts (`$pending_count`, `$unapproved_count`) by running `get_users()` queries in the constructor — both feed the admin menu bubble and the `views_users` filter links (`?role=wpau_pending`, `?role=wpau_unapproved`). `pre_user_query()` rewrites the query to a meta lookup when those pseudo-roles are requested.

### Activation and bulk approval cron

`register_activation_hook` calls `wpau_allowlist_users()` (in `cron-events.php`), which marks up to 100 users as approved per run and re-schedules `wpau_allowlist_users_cron` (single event, +5s) until `count_users()['total_users']` is reached. This exists to avoid timing out on large sites during activation. If you touch activation logic, keep the batch-and-reschedule pattern — do not inline-loop over all users.

### Multisite

Multisite branches exist throughout the main class: `network_admin_menu` replaces `admin_menu`, `ms_user_row_actions` mirrors `user_row_actions`, queries set `blog_id` to `0` in network admin vs. current blog otherwise, and super admins skip the approval check entirely (changelog entry 9). When adding admin-screen behavior, add both the single-site and the `-network` / `site-users-network` variants.

### Tests

`tests/bootstrap.php` loads `noop.php`, the base class, and the main class directly — it does **not** run `wp-approve-user.php`, so activation/upgrade hooks are skipped in the test harness. Tests live alongside code (phpunit `<directory>` is `.` with `prefix="test-"`) but currently only `tests/test-user-meta.php` exists. CI runs on PHP 7.4/8.1/8.2 against latest WordPress, plus PHP 7.4/WP 6.3 and PHP 8.3/WP trunk — avoid syntax that breaks 7.4.

## Project-specific rules

- **Three-state meta**: never assume boolean. Compare against the string `'approved'` / `'unapproved'` / `'pending'`.
- **`wpau_` prefix** for all global functions, hooks, and options. The plugin's textdomain is `wp-approve-user`.
- Action hooks exposed to third parties: `wpau_approve`, `wpau_unapprove`. Filters: `wpau_default_options`, `wpau_update_message_handler`, `wpau_message_placeholders`. Don't rename these without a deprecation path — they're documented in `readme.txt`.
- **No JS/CSS build step.** `js/wp-approve-user.js` and `css/settings-page.css` are checked in alongside hand-maintained `.min.js` / `.min.css` siblings. The main class enqueues the `.min` variant unless `SCRIPT_DEBUG` is defined, so when editing the source you must update the minified file in the same commit.
- `.distignore` controls what ships to wordpress.org via the deploy workflow — add new dev-only files there.
