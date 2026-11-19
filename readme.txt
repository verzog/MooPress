=== MooPress – a WordPress and Moodle bridge ===
Contributors: vernonspain
Tags: moodle, lms, woocommerce, sso, enrolment
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connects a WordPress membership site to Moodle: single sign-on, course enrolment from purchases and membership, and course progress on WordPress.

== Description ==

MooPress is a direct WordPress and Moodle bridge. It lets a WordPress membership
site sell and grant access to Moodle courses, sign members straight into Moodle,
and show each learner their catalogue and progress on the WordPress side.

Entitlements come from pluggable sources, and membership is read from a WordPress
role, so MooPress never depends on iMIS directly and an organisation can move off
iMIS over time without rewriting the bridge.

It enrols buyers and members in Moodle courses, reconciles each learner's enrolments
to what they are entitled to (with a daily sweep and safety limits), and shows a
course catalogue and a signed-in member's courses and progress through the
[moopress_catalogue] and [moopress_my_courses] shortcodes. A settings page provides a
connection test, an audit log with credentials redacted, and a dry-run default.

Moodle is a trademark of the Moodle Trust. WordPress is a trademark of the WordPress
Foundation. MooPress is an independent project and is not made, sponsored or
endorsed by either.

== Installation ==

1. Upload the plugin to wp-content/plugins/moopress, or install the release zip
   through Plugins, Add New Plugin, Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Configure the Moodle site URL and service-account token under the MooPress
   settings (added in a later phase).

== Frequently Asked Questions ==

= Does this need a Moodle site? =

Yes, for live use: a Moodle 5.1 or later site with Web Services and the REST
protocol enabled, and a dedicated least-privilege service account with a token. The
automated tests fake Moodle, so no Moodle site is needed to run them.

== Changelog ==

= 0.1.0 =
* Initial plugin skeleton: settings, Moodle Web Services REST client, audit log,
  CI and the project specification.
* Admin settings page (Moodle site URL, service-account token, dry run and
  logging options) with a Test connection button.
* Course enrolment from WooCommerce purchases and from a membership role, with a
  reconcile engine, daily sweep and safety limit.
* Front-end shortcodes: [moopress_catalogue] lists courses, [moopress_my_courses]
  shows a signed-in member their enrolled courses and progress.
