=== WP Approve User ===
Contributors: obenland
Tags: admin, user, login, approve, user management
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=G65Y5CM3HVRNY
Requires at least: 4.7
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds action links to user table to approve or unapprove user registrations.

== Description ==

This plugin lets you approve or reject user registrations.
While a user is unapproved, they can't access the WordPress Admin.

On activation of the plugin, all existing users will automatically be flagged Approved. The site admin will never experience restricted access and does not need approval.
This plugin is probably not compatible with WooCommerce.

= Translations =

I will be more than happy to update the plugin with new locales, as soon as I receive them!
Currently available in:

* Dutch
* Deutsch
* English
* Hebrew
* Persian
* Russian


= Plugin Hooks =

== Actions ==

**wpau_approve** (*int*)
> User-ID of approved user.

**wpau_unapprove** (*int*)
> User-ID of unapproved user.

== Filter ==

**wpau_default_options** (*array*)
> Default options.

**wpau_update_message_handler** (*string*)
> Allows to return custom update messages.

**wpau_message_placeholders** (*array*)
> Filters the placeholders in approve/unapprove emails.


== Installation ==

1. Download WP Approve User.
2. Unzip the folder into the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Make sure user registrations is enabled in 'General Settings'.


== Frequently Asked Questions ==

= Once a new user has been approved, will the plugin send out an email to inform them they have been approved? =

Yes! Under Settings > Approve User, you can choose when to send an email and customize the email content to your needs!


== Screenshots ==

1. Error message when user is not yet approved.
2. Row action when user is approved
3. Row action when user is not yet approved
4. Count notification and row highlight for unapproved users


== Upgrade Notice ==

= 13 =
Adds a richer Pending User Approvals dashboard widget with inline approve/reject actions and rule-based auto-approval for trusted email domains, suffixes, and IP ranges.

= 12 =
Migrates user approval data to a three-state system and adds a RESETLINK email placeholder. Back up before upgrading large installs.


== Changelog ==

= 13 =
* Pending User Approvals dashboard widget now lists up to five pending users with inline Approve and Reject buttons that act via AJAX without leaving the dashboard.
* Adds a rule-based auto-approval feature so admins can allow registrations from trusted email domains, suffixes, and IP ranges.
* Extends the core "New User Registration" admin email with a link to the pending users screen when a registration needs approval.
* Registers the `wp-approve-user/approve` and `wp-approve-user/unapprove` abilities via the WP Abilities API so MCP clients and other integrations can drive the approval flow.
* Fixes a silent lockout where users with no `wp-approve-user` meta (e.g. pre-existing users on sites that enabled registration after install) were blocked from wp-admin without any feedback.
* Restores compatibility with third-party integrations (e.g. Restrict Content Pro) that still call `update_user_meta( $id, 'wp-approve-user', true|false )` by coercing legacy boolean writes to the V12 three-state strings.

= 12 =
* Bumped minimum required WordPress version to 4.7.
* Requires PHP 7.4 or later.
* Switches to a three-state approval system: approved, unapproved, and pending.
* When a user is unapproved, they now get immediately logged out from all active sessions.
* Uses a cron job to auto-approve more than 100 users asynchronously after plugin activation.
* Adds a `RESETLINK` email placeholder that sends users a one-time set/reset-password URL. Props @helgatheviking.
* Pending count (not unapproved count) now drives the admin menu update bubble.
* After approving or unapproving the last user in a filtered view, the redirect now lands on All Users instead of an empty list.
* Migrates legacy boolean approval meta on upgrade: `true` becomes `approved` and `false` becomes `pending`.
* Fixes an issue where the upgrade routine re-ran on every admin page load due to a strict type comparison.
* Corrected text domain on the "Pending" and "Unapproved" view labels so they can be translated.
* Updated the login error and post-registration messages to be shorter and clearer.

= 11 =
* Replaced image files with inline SVGs.
* Fixes a race condition with registering sidebar boxes between plugins I authored.

= 10 =
* Fixes a bug with the activation hook creating class instances before it should. See https://wordpress.org/support/topic/fatal-error-4281/

= 9 =
* No longer checks approval status on log in for super admins in multisite installations. See https://wordpress.org/support/topic/super-admin-not-approved-on-multisite/
* Fixes an incompatibility with WordPress 6.1 where the plugin would set up too early. See https://wordpress.org/support/topic/fatal-error-4281/

= 8 =
* Does no longer overwrite approval status after plugin re-activation. Props @zadro, @idearius, @howdy_mcgee.

= 7 =
* Added a filter to manipulate placeholders and their replacement values. See https://wordpress.org/support/topic/customize-email-templates-2/
* Only sends out rejection email if it's a new registration and the user is not approved. See https://wordpress.org/support/topic/deleting-user-generates-user-not-approved-email-possible-to-disable-feature/
* Various multisite improvements and bug fixes. The unapproved filter works now! See https://wordpress.org/support/topic/multisite-issues-with-user-lists-and-unapproved-filter/

= 6 =
* Improved approval flow, waiting with password email until after approval.
* Fixed a bug where the approval email had some stray whitespace surrounding it.
* Tested for WordPress 5.2.

= 5 =
* Fixed a bug where user registration couldn't be activated with the plugin active.

= 4 =
* For easier on-boarding, it now displays a notice if user registration is disabled.

= 3 =
* Maintenance release.
* Better multisite compatibility.
* Now maintains role selection on batch modification.
* Added some more sanitization.
* Updated code to adhere to WordPress Coding Standards.
* Tested for WordPress 5.0.

= 2.3 =
* Added French translation. Props Clovis Darrigan.
* Added Arabic translation. Props Mehdi Bounya.

= 2.2.3 =
* Fixes a bug where administrators where locked out of their site if user registration was enabled after the plugin was.

= 2.2.2 =
* Adds backwards compatibility for WordPress versions pre-3.5 for the user list filter.
* Removes unused development versions of scripts and styles.

= 2.2.1 =
* Updated utility class.

= 2.2.0 =
* Added a way to filter for unapproved users in the admin user list.
* Fixed a bug where currently active users would not be flagged as approved on activation if user registration was disabled.
* Added Dutch translation. Props Jos Wolbers.
* Minor coding convention updates to be closer to core coding guidelines.
* Tested with the beta version of 3.6.

= 2.1.1 =
* Fixed a bug, where new settings were not saved.

= 2.1.0 =
* Added Russian translation. Props Mick Levin.
* Email bodies can now be edited even when email notification is not activated.
* Fixed a bug, where admin notices by the Settings API were not displayed.

= 2.0.0 =
* Added the ability to send an email on approval/unapproval. Email text can be customized.
* Optimized alteration of Users menu item. Props Rd.
* Added Hebrew translation. Props asafche.

= 1.1.1 =
* Fixed a bug, where the call to action bubble didn't account for newly registered.

= 1.1.0 =
* Added bulk action for approving and unapproving users.
* Added notification of unapproved users in admin menu item (WordPress 3.2+).
* Added highlight of unapproved users.
* Added action hooks on (un-)approval. See hook reference.
* Users created by an Administrator will automatically be approved.
* Updated utilities class.
* Now an instance of the Obenland_Wp_Approve_User object ist stored in a static property to make deregistration of hooks easier.

= 1.0 =
* Initial Release.


== Upgrade Notice ==
Updated registration flow, now sending out Core's password-creation email only after a registration was approved.
With this change, the minimum required version is now WordPress 4.3.
