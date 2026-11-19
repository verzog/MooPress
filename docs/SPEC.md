# MooPress — specification

A WordPress ⇄ Moodle bridge. MooPress lets a WordPress membership site sell and
grant access to Moodle courses, sign members straight into Moodle, and show each
learner their catalogue and progress on the WordPress side.

This document is the plan the code is built against. It is not the code; where the
two disagree, fix one of them deliberately.

- **Status:** draft / pre-implementation
- **Repository:** `verzog/moopress`
- **Sibling plugin:** [`wormiss`](https://github.com/verzog/wormiss) (WordPress ⇄ iMIS).
  MooPress reuses its proven patterns: a REST client, an audit log with credentials
  redacted, settings, and background jobs run through WP‑Cron with a dry‑run default.

---

## 1. Purpose and strategy

MooPress connects WordPress and Moodle **directly** — it is not an iMIS bridge.
But it is built in the knowledge that the organisation already runs iMIS (through
`local_membershipbridge` on the Moodle side and `wormiss` on the WordPress side),
and that a **long‑term goal is to move off iMIS**.

That strategy drives the single most important design decision:

> **Where an entitlement comes from is pluggable.** MooPress asks one question —
> *"which Moodle courses should this person have?"* — and answers it from one or
> more **sources**. iMIS membership is a source today; a WordPress‑native
> membership is a source later. Turning iMIS off is then a configuration change,
> not a rewrite.

If the entitlement abstraction is built from Phase 1, the iMIS‑today /
WP‑native‑tomorrow switch stays cheap. That is the whole point.

---

## 2. Scope

### In scope (this product)

1. **Single sign‑on.** WordPress is the identity provider (front door); Moodle is
   the service provider, using Moodle's existing, maintained auth plugin
   (`auth_oidc` preferred, `auth_saml2` acceptable). **No SSO protocol is written
   from scratch.**
2. **Enrolment from two kinds of entitlement ("both"):**
   - **Per‑course purchase** via WooCommerce (already used on the WordPress side).
   - **Membership‑included** access (a membership grants a bundle of courses).
3. **Content on the WordPress side:** a **course catalogue** block/shortcode, and a
   **learner's progress** ("My courses" / completion) on their WordPress profile.

### Out of scope (for now)

- Gating arbitrary existing WordPress pages behind Moodle enrolment (possible later;
  not in the first build).
- Writing course *content* into Moodle, authoring, grading, or SCORM.
- Replacing iMIS (MooPress is a step toward that, not the migration itself).

---

## 3. Architecture

```
  Entitlement SOURCES                 ENGINE                       TARGET
  ┌──────────────────────────┐
  │ WooCommerce purchase      │─┐
  │ (per course)              │ │
  ├──────────────────────────┤ │   ┌───────────────────────────┐   ┌──────────┐
  │ iMIS membership (NOW)     │─┼──▶│ reconcile: the union of    │──▶│  Moodle  │
  │ (reuses wormiss's client) │ │   │ all sources → enrol /      │   │  (Web    │
  ├──────────────────────────┤ │   │ suspend to match           │   │  Services)│
  │ WP‑native membership      │─┘   └───────────────────────────┘   └──────────┘
  │ (LATER — iMIS switched off)│
  └──────────────────────────┘
```

### 3.1 Entitlement engine

- Each **source** implements a small interface: *"for this WordPress user, which
  course IDs are entitled, and why?"* Return value carries the course id, the
  reason (purchase / membership / …) and the source, for the audit log.
- The **engine** takes the **union** of entitled course ids across all enabled
  sources and reconciles Moodle enrolment to match:
  - entitled and not enrolled → **enrol**;
  - enrolled by MooPress but no longer entitled → **suspend** (never delete — see
    §3.4);
  - enrolled by something other than MooPress → **leave alone**.
- Reconciliation runs **on change** (a purchase completes, a membership flips) and
  on a **daily sweep** (catches lapses and missed webhooks), both as background
  jobs. A **mass‑change guard** holds back a run that would suspend an unusually
  large share of enrolments (as `wormiss`'s member sync already does).

### 3.2 Identity / SSO

- **WordPress is the identity provider.** Members already become WordPress users
  (via `wormiss`'s member sync today), so WordPress is on its way to being the
  durable account store — which is exactly what the move off iMIS needs.
- Moodle logs in against WordPress through **`auth_oidc`** (OpenID Connect; lighter
  for this pairing) or **`auth_saml2`** (SAML). The choice is a configuration of
  existing Moodle plugins; MooPress supplies the WordPress IdP endpoint and the
  user/claim mapping, not a hand‑rolled SSO stack.
- **Decided:** support **both** OIDC and SAML — configure whichever the target
  Moodle runs. The IdP piece is isolated behind its own component.

### 3.3 Moodle Web Services REST client

- One class, modelled on `wormiss`'s iMIS REST client: a single `call( $wsfunction,
  $params )` against `{base}/webservice/rest/server.php` with
  `moodlewsrestformat=json`, a dedicated service‑account **token**, every call
  logged (token redacted), and a `Moopress_Exception` on transport or Moodle
  error. Moodle returns web‑service faults as HTTP 200 with an `exception` key — the
  client must check for that, not just the HTTP status.
- **Moodle side is mostly configuration:** enable Web Services + the REST protocol,
  create a least‑privilege service account (the iMIS *RemoteService* pattern again),
  and an external service whitelisting only the functions below. A custom Moodle
  `local` plugin is only needed if a required operation has no core web‑service
  function; avoid it where core covers the need.

#### Moodle Web Service functions to whitelist (first build)

| Purpose | Function |
|---|---|
| Connection test / site info | `core_webservice_get_site_info` |
| Find/create the Moodle user (matching) | `core_user_get_users_by_field`, `core_user_create_users` |
| Enrol into a course | `enrol_manual_enrol_users` |
| Suspend / unenrol | `enrol_manual_unenrol_users` (or update to suspended status) |
| Course catalogue | `core_course_get_courses_by_field` |
| Learner progress / completion | `core_completion_get_course_completion_status` |
| Learner grades (optional) | `gradereport_user_get_grade_items` |

### 3.4 Key correctness rules (the things most likely to bite)

1. **Account matching key.** Each WordPress user must map to exactly **one** Moodle
   user. The stable key **now** is the iMIS ID held on the WordPress account (set by
   SSO/`wormiss`); store the resolved Moodle user id in user meta once matched so the
   mapping is not re‑derived on every call. Plan a fallback key (email, or a MooPress
   UUID) for the post‑iMIS world. Getting this wrong double‑creates accounts or
   cross‑enrols people — treat it as the first thing to get right in Phase 1.
2. **Suspend, do not unenrol**, on lapse or refund, so grades and completion survive
   if the person returns.
3. **Background, dry‑run, audited.** All enrolment changes run through WP‑Cron with
   retries, default to a **dry run**, and write to the audit log. Lift `wormiss`'s
   batch/retry pattern.
4. **Idempotency.** Reconciliation must be safe to run repeatedly; it computes the
   desired state and makes only the differences.

---

## 4. Phased plan

Each phase ships on its own (one concern per PR), and each builds on the previous.

| Phase | Deliverable | Notes |
|---|---|---|
| **1. Foundation** | Settings + Moodle Web Services REST client + audit log + **user matching** + a connection test. SSO wiring (WP IdP → Moodle SP via the existing auth plugin). | This scaffold is the start of Phase 1. |
| **2. Per‑course** | WooCommerce product ↔ Moodle course mapping; enrol on paid order; **suspend** on refund. | Reuses WooCommerce, already a dependency pattern in `wormiss`. |
| **3. Membership‑included** | Entitlement engine with the iMIS membership source; daily reconcile; mass‑change guard. | The engine interface lands here but is designed in from Phase 1. |
| **4. Content / UX** | Course‑catalogue block + "My courses"/progress on the WordPress profile. | Same block/shortcode pattern as `wormiss`'s iMIS data block. |
| **5. Transition‑ready** | A WordPress‑native entitlement source behind the same interface, so iMIS can be switched off. | Cheap *iff* §3.1 was honoured from the start. |

---

## 5. Data model (first pass)

- `moopress_settings` (option): Moodle base URL, service‑account token, feature
  flags (dry‑run, logging), SSO settings, mapping of WooCommerce product → course,
  and membership → course bundle.
- `{prefix}moopress_log` (table): the audit log of Web Service calls.
- User meta: resolved Moodle user id, and per‑user reconciliation state.
- All credentials redacted in the log; token never written to the page.

---

## 6. Security

Follows WordPress plugin practice (and the spirit of the Moodle rules in the house
guide, adapted to WordPress):

- Capability + nonce checks on every admin write; `manage_options` for configuration.
- The Moodle token is a least‑privilege service account; the external service
  whitelists only the functions in §3.3.
- Output escaped (`esc_html`, `esc_attr`, `wp_kses`); never echo the token.
- The Privacy API: export and erase a person's MooPress log entries and mapping.
- Treat everything from Moodle as untrusted input; validate course ids against the
  configured mapping before acting.

---

## 7. Licensing, naming and standards

- **Licence mode: B — GPLv3‑or‑later**, matching the sibling `wormiss`.
  `@copyright 2026 Vernon Spain`; `@license http://www.gnu.org/copyleft/gpl.html GNU
  GPL v3 or later`. (If this is to sit under *Vernon Spain trading as EduCheckout*
  instead, that is a one‑line change to every header's copyright holder — flag before
  release. One holder per plugin; never mix.)
- **Name: "MooPress".** The `Moo…` prefix is established practice on the Moodle
  plugins directory (MooTyper, MooDuell, MooChat, MooProof, MooWoodle Connect) and
  `…Press` is not a reserved WordPress mark (cf. BuddyPress, bbPress). Low risk, but:
  - keep `moodle`/`wordpress` **out of any domain name**;
  - confirm "MooPress" is not already a registered mark before release;
  - carry a trademark disclaimer (see the README), as `wormiss` does for iMIS.
  - *Not legal advice; clear the final name with a lawyer before it goes on invoices.*
- **CI and coding standards** mirror `wormiss`: WordPress Coding Standards
  (`phpcs`, warnings fail), `php-parallel-lint`, and PHPUnit against the WordPress
  test suite on WordPress 6.6 and latest, PHP 8.1–8.5, MySQL and MariaDB. `TZ` is
  `Australia/Sydney` deliberately (so daylight‑saving bugs surface). AU/UK spelling
  in user‑facing text; `userdate`‑equivalent date formatting; AUD, 2 decimals.

---

## 8. Decisions and open questions

Decided:
- **SSO protocol:** support **both** OIDC and SAML.
- **Copyright holder:** **Vernon Spain** (as `wormiss`) for now; may move to "trading
  as EduCheckout" later (a one-line header change, one holder per plugin).
- **Name:** **MooPress**, with the trademark disclaimer in the README.

Still open:
1. **Membership source of truth for Phase 3:** read membership from iMIS (via a
   shared/`wormiss`‑style client) or from a WordPress membership/role that `wormiss`
   already maintains? The latter is closer to the post‑iMIS end state.
4. **Course mapping UX:** per‑product field on the WooCommerce product, a central
   mapping table, or both?
