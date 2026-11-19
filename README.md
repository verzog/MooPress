# MooPress – a WordPress ⇄ Moodle bridge

Connects a WordPress membership site to Moodle: sell and grant access to Moodle
courses, sign members straight into Moodle, and show each learner their catalogue
and progress on the WordPress side.

MooPress is the direct WordPress ⇄ Moodle counterpart to
[`wormiss`](https://github.com/verzog/wormiss) (WordPress ⇄ iMIS), and reuses its
proven patterns: a REST client, an audit log with credentials redacted, settings,
and background jobs run through WP‑Cron with a dry‑run default.

The design and plan are in [`docs/SPEC.md`](docs/SPEC.md). Single sign-on is a
configuration of Moodle's existing `auth_oidc`/`auth_saml2` against this WordPress
as the identity provider; it is not shipped as plugin code.

## What it does

* **Enrolment from purchases and membership:** a WooCommerce purchase, or a current
  membership, grants access to the mapped Moodle course(s). Access is **suspended**
  (never deleted) when it lapses, so grades and completion survive.
* **An entitlement-reconcile engine:** each learner's Moodle enrolments are
  reconciled to the union of everything they are entitled to, with a daily sweep, a
  mass-change guard, and protection against suspending on a source outage.
* **Course catalogue and progress on WordPress:** the `[moopress_catalogue]`
  shortcode lists the site's courses, and `[moopress_my_courses]` shows a signed-in
  member their enrolled courses and completion. (A shortcode drops into any block.)
* **Configuration and safety:** a settings page with a Moodle connection test, an
  audit log with credentials always redacted, a dry-run default, and the Privacy API
  (export and erase a person's MooPress data).

Entitlements come from **pluggable sources**, and membership is read from a
**WordPress role** (the role a system such as `wormiss` assigns to current members) —
so MooPress never depends on iMIS directly, and the organisation can move off iMIS
over time without rewriting the bridge.

## Requirements

* WordPress 6.6 or later. CI tests 6.6 and the latest release.
* PHP 8.1 or later. CI tests 8.1 to 8.5.
* A Moodle site (5.1+) with Web Services and the REST protocol enabled, and a
  dedicated least‑privilege service account with a token.
* Moodle's `auth_oidc` or `auth_saml2` for single sign‑on.

## Install via ZIP

1. Download `moopress-v<version>.zip` from the latest GitHub release (not the
   "Source code" zip, which has the wrong folder name).
2. In WordPress: *Plugins → Add New Plugin → Upload Plugin*, choose the zip, then
   *Activate*.

## Install manually

Copy this folder to `wp-content/plugins/moopress` and activate it under *Plugins*.

## Development

```bash
composer install
composer lint
composer phpcs
```

CI (`.github/workflows/ci.yml`) runs the static checks, then PHPUnit against the
WordPress test suite on WordPress 6.6 and latest, PHP 8.1 to 8.5. Tests fake Moodle
by intercepting WordPress HTTP requests, so no Moodle site is needed.

### Releasing

1. Bump `Version:` and `MOOPRESS_VERSION` in `moopress.php` and `Stable tag:` in
   `readme.txt` (CI checks they match); merge to `main` and wait for CI.
2. Publish a GitHub release tagged `v` + that version.
3. The *Release zip* workflow checks the tag and attaches `moopress-v<version>.zip`
   with a `moopress/` folder.

## Licence

GNU GPL v3 or later. See `LICENSE`. © 2026 Vernon Spain.

Moodle™ is a trademark of the Moodle Trust. WordPress® is a trademark of the
WordPress Foundation. MooPress is an independent project and is not made,
sponsored or endorsed by either.
