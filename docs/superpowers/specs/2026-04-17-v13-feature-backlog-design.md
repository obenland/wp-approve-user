# v13 feature backlog — design

Source: [GitHub issue #60](https://github.com/obenland/wp-approve-user/issues/60).

## Strategic direction

Free and focused. Close painful gaps without chasing feature parity with paid membership plugins.

v13 ships four independent PRs. Each has full PHPUnit coverage and Playwright e2e coverage for both single-site and multisite where relevant.

## Scope

1. **PR 1 — Fix silent-lockout edge case** (bug fix, prerequisite)
2. **PR 2 — Abilities API integration** (modernize, unlock integrations)
3. **PR 3 — Dashboard widget + pending-user admin email** (make the queue visible)
4. **PR 4 — Rule-based auto-approval** (reduce admin workload)

Deferred to v14+: user notes, registration reason field, auto-purge, WooCommerce compatibility, audit log, public rejection status.

## PR 1 — Fix silent lockout

### Problem

`Obenland_Wp_Approve_User::wp_authenticate_user()` only returns the user object when the `wp-approve-user` meta is exactly `'approved'`. Users with **no** meta at all (installed the plugin on a site with pre-existing users, or uninstalled/reinstalled) fail that check and see the generic "account has to be confirmed" error forever. They do not appear in the Pending or Unapproved views either — both of those query for specific meta values.

### Fix

1. `wp_authenticate_user()` treats an empty string (the value `get_user_meta` returns for missing meta) as approved. A user who has never been touched by this plugin is not a user the plugin has anything to say about.
2. New upgrade routine `wpau_upgrade_to_13()` stamps every meta-less user with `'approved'` so they surface in admin views going forward.
3. Bump `$wpau_db_version` to 13 in `wp-approve-user.php`.

The upgrade runs via the existing `admin_init` → `wpau_upgrade_all()` flow. `wpau_upgrade_to_13()` uses a single `wpdb` query with a `NOT EXISTS` subquery — no batching needed (each user row is touched at most once, the query is indexed on `user_id`/`meta_key`).

### PR 1 tests

- PHPUnit `test-user-meta.php`: user with no meta authenticates successfully (single + multisite), user with `'unapproved'` meta is still blocked (regression).
- PHPUnit `test-upgrade.php`: `wpau_upgrade_to_13()` stamps meta-less users as approved, leaves existing meta untouched, `wpau_upgrade_all()` invokes it when `wpau_db_version < 13`.
- Playwright `login-gate.spec.js`: create a user via wp-cli *without* setting `wp-approve-user` meta, assert login succeeds.

## PR 2 — Abilities API integration

### Design

Register two abilities guarded by `function_exists( 'wp_register_ability' )`:

- `wp-approve-user/approve` — input `{ user_id: integer }`, permission `promote_users`, execute calls `do_action( 'wpau_approve', $user_id )` and updates the meta.
- `wp-approve-user/unapprove` — same shape, permission `promote_users`, executes the unapprove logic.

New file `abilities.php`, required from `wp-approve-user.php` inside the `users_can_register` gate. Hooks into `wp_abilities_api_init`. Loaded only when `wp_register_ability` exists, so the plugin remains compatible with WordPress < 6.9.

The abilities reuse the existing `wpau_approve` / `wpau_unapprove` action hooks as integration points — the ability's execute callback simply mirrors what the admin action handlers already do.

REST exposure comes automatically via `show_in_rest => true` on each ability.

### PR 2 tests

- PHPUnit `test-abilities.php`: abilities registered when `wp_register_ability` exists, skipped when it doesn't (mock/stub at test level). Permission callback rejects users without `promote_users`. Execute callback flips the meta and fires the action hook. Input schema rejects non-integer `user_id`.
- Playwright `abilities.spec.js`: hit `/wp-json/wp-abilities/v1/…` with a logged-in admin cookie, assert the user's meta changes. Skip the spec entirely when running against WP < 6.9 (detect by probing the REST route).

## PR 3 — Dashboard widget + pending admin email

### Dashboard widget

- Registered on `wp_dashboard_setup` and `wp_network_dashboard_setup` (multisite).
- Title: "Pending User Approvals".
- Body: the pending count + a button linking to `users.php?role=wpau_pending`. If the count is zero, shows "No users awaiting approval." and a link to the settings page. Widget only renders for users with `promote_users`.
- Reuses the `pending_count` the main class already computes — the widget callback pulls from the singleton.

### Admin email

- On `user_register`, when the new status is `'pending'`, send a single email to the admin (`get_option( 'admin_email' )` or the network admin on multisite).
- Subject: "New user awaiting approval on {site name}".
- Body: username, email, link to the Pending view.
- Gated by a new setting `wpau_notify_admin` (default `true`) on the existing settings page. Setting is stored in the existing `wp-approve-user` option array (merged via `default_options()`).
- Body filterable via a new `wpau_pending_notification_message` filter (matches the existing `wpau_message_placeholders` pattern).

### PR 3 tests

- PHPUnit `test-dashboard-widget.php`: widget registered, callback renders count correctly for zero/many, respects `promote_users`.
- PHPUnit `test-pending-notification.php`: email sent on pending registration (capture via `MockPHPMailer`), not sent when setting is off, not sent for admin-created users (who are auto-approved).
- Playwright `dashboard-widget.spec.js`: seed a pending user via wp-cli, load dashboard, assert the widget shows the count with the link.
- Playwright `settings.spec.js` update: toggle the new setting, register a user, assert no email was sent (intercepted via wp-cli or by reading the wp_mail log).

## PR 4 — Rule-based auto-approval

### Storage

Rules are an array within the existing `wp-approve-user` option:

```php
'auto_approve_rules' => [
    [ 'type' => 'email_domain', 'value' => 'mycompany.com' ],
    [ 'type' => 'email_domain', 'value' => 'contractor.com' ],
],
```

Single rule type in v13: `email_domain`. The structure is built to accept more types later (e.g., `email_regex`, `ip_address`) without a data migration.

### Settings UI

New section on the existing settings page: a repeatable list of `type`/`value` rows with add/remove buttons. Rules are validated server-side in the settings save handler — invalid domains (containing `@`, whitespace, bad TLD) are dropped with a `settings_error` notice. Empty rows are dropped.

### Execution

Hook into `user_register` at a later priority than the existing handler (which sets `'pending'`). The new handler reads the user's email, evaluates each configured rule in order, and on first match:

1. Updates meta to `'approved'`.
2. Calls `do_action( 'wpau_approve', $user_id )` so downstream handlers (email, logging, etc.) fire just as if an admin clicked Approve.

Rules run through a `wpau_auto_approve_rules` filter so developers can add rules programmatically without touching the settings UI.

### PR 4 tests

- PHPUnit `test-auto-approval.php`: matching domain auto-approves, non-matching stays pending, filter-added rule runs, first-match semantics, disabled (empty) rules list leaves user pending, admin-created user still bypasses auto-approval logic (because they're already approved).
- Playwright `auto-approval.spec.js`: add a domain rule via the settings UI, register a user with a matching email on the front-end, assert the user is approved on the Users screen.

## Cross-cutting

- **PHP compatibility:** `testVersion 7.4-` remains. No typed property promotion, no enums, no `readonly`, no `match`.
- **WordPress coding standards:** PHPCS + PHPCompatibilityWP must pass cleanly.
- **Minified assets:** if a PR touches `js/wp-approve-user.js` or `css/settings-page.css`, the `.min` sibling must be updated in the same commit.
- **Textdomain:** `wp-approve-user`. All new strings need `i18n`.
- **Prefix:** `wpau_` for new globals/hooks/options. The option key stays `wp-approve-user`.
- **`.distignore`:** add any new dev-only paths (e.g. `/.worktrees`, this spec directory if kept out of the release).

## Implementation order

1. PR prep (this spec + `.gitignore`/`.distignore` for `/.worktrees`).
2. PR 1 — silent lockout.
3. PR 2 — abilities API.
4. PR 3 — dashboard widget + email.
5. PR 4 — auto-approval.

Each branch starts from `trunk`. PRs do not depend on each other's code; conflicts, if any, are small (settings page edits in PRs 3 and 4).
