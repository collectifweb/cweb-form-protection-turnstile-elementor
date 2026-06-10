# Changelog

All notable changes to **CWeb Form Protection with Turnstile for Elementor Forms** are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.1] — 2026-06-10

### Fixed
- **WooCommerce "Lost password" form.** The Turnstile widget now renders on
  `/my-account/lost-password/`. WooCommerce replaces the core form with its own
  (firing `woocommerce_lostpassword_form` for display) while still validating
  through `lostpassword_post`; without the display hook the widget never
  appeared and every reset was rejected. Added `woocommerce_lostpassword_form`
  to the lost-password integration. No change to validation.

## [1.0.0] — 2026-06-06

First public release, prepared for submission to the WordPress.org Plugin Directory.

### Added
- **Per-form Cloudflare Turnstile field for Elementor Pro Forms.** Drag the
  *Cloudflare Turnstile* field into a form to protect it; forms without the field
  are untouched — the same UX as Elementor's native reCAPTCHA field.
- **WordPress native form integrations** (opt-in toggles): login, registration,
  lost password, comments.
- **Server-side verification** through Cloudflare `/siteverify` with a 4-case
  decision matrix, a per-request token cache (avoids `timeout-or-duplicate` on
  duplicate fields), single-use token handling and a configurable failure mode
  (`block` by default) for when Cloudflare is unreachable.
- **Write-only secret key**: stored server-side, never printed in a page or sent
  to the browser.
- **Global widget appearance** settings: theme, size, visibility, language.
- **One-click import** of keys and settings from *Simple Cloudflare Turnstile*
  (Elliot Sowersby) — no Cloudflare key regeneration, the other plugin is left
  untouched.
- Front-end helper using Cloudflare explicit rendering, with widget reset after a
  failed Elementor AJAX submission and `expired/timeout/error` callbacks.
- Public filters: `cwebts_timeout`, `cwebts_remoteip`, `cwebts_verify_action`,
  `cwebts_verify_hostname`, `cwebts_hostname_allowlist`.
- Internationalisation: `.pot` template, all strings translatable under the
  `cweb-form-protection-turnstile-elementor` text domain.

### Notes — WordPress.org compliance
- Plugin named **CWeb Form Protection with Turnstile for Elementor Forms** (distinctive `CWeb`
  identifier first, then the `<feature> for <brand>` pattern), slug
  `cweb-form-protection-turnstile-elementor`.
- All global identifiers carry a distinctive prefix: `CWEBTS_` constants,
  `cwebts_` functions / hooks / option, `.cwebts-` CSS, `cwebtsOnload` JS global,
  `CWebTS\` PHP namespace. The Cloudflare-required `cf-turnstile` class and
  `cf-turnstile-response` field are kept verbatim.
- Third-party service (Cloudflare Turnstile) and the exact data sent to it are
  disclosed in `readme.txt`. No external code is executed; only the official
  Cloudflare widget script is loaded.
