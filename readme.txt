=== WP Approve User ===
Contributors: obenland
Tags: admin, user, login, approve, user management, plugin
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=G65Y5CM3HVRNY
Requires at least: 3.1
Tested up to: 5.0
Stable tag: 3

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


== Installation ==

1. Download WP Approve User.
2. Unzip the folder into the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Make sure user registrations is enabled in 'General Settings'.


== Frequently Asked Questions ==

= Once a new user has been approved, will the plugin send out an email to inform them they have been approved? =

Yes! Under Settings > Approve User, you can choose when to send an email and customize the email content to your needs!


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


== Screenshots ==

1. Error message when user is not yet approved.
2. Row action when user is approved
3. Row action when user is not yet approved
4. Count notification and row highlight for unapproved users


== Changelog ==

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