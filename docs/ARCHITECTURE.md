# CWeb Turnstile for Elementor Forms — Architecture

## Overview

The plugin adds Cloudflare Turnstile to WordPress forms. Its differentiator is
**per-form control for Elementor Pro**: Turnstile is exposed as a *field* you add
to a form (like Elementor's native reCAPTCHA field), so only forms that contain
the field are protected. It also offers opt-in protection for the built-in
WordPress forms (login, registration, lost password, comments) and a one-click
import of keys/settings from the *Simple Cloudflare Turnstile* plugin.

The code is namespaced under `CWebTS\` with a tiny PSR-4-style autoloader. All
global identifiers use the distinctive `cwebts` / `CWEBTS` / `.cwebts-` prefix.
The Cloudflare-mandated `cf-turnstile` class and `cf-turnstile-response` field
name are kept verbatim.

## File layout

```
cweb-turnstile-for-elementor-forms.php   Bootstrap: constants, autoloader, plugins_loaded
includes/
  class-plugin.php                       Wires hooks, instantiates the service graph
  class-settings.php                     Settings API page, write-only secret, Elliot import
  class-verifier.php                     /siteverify wrapper, 4-case matrix, per-request cache
  class-widget-renderer.php              Widget markup + conditional (deferred) asset enqueue
  elementor/class-turnstile-field.php    Per-form Elementor Pro field (render + validation)
  integrations/
    class-abstract-integration.php       Shared base (toggle gating, token read, verify)
    class-wp-login.php                    authenticate filter
    class-wp-register.php                 registration_errors
    class-wp-lost-password.php            lostpassword_post
    class-wp-comments.php                 preprocess_comment
assets/js/turnstile.js                   Explicit render, AJAX reset on failure, expired-callback
assets/css/admin.css                     Settings page styles
languages/cweb-turnstile-for-elementor-forms.pot
```

The service objects (`Settings`, `Verifier`, `Widget_Renderer`) are plain
instances created once in `Plugin::__construct()` and injected where needed — no
global singletons, which keeps `Verifier` unit-testable without WordPress.

## Data model — option `cwebts_settings`

A single `wp_options` row holds an array:

| Key | Values | Notes |
| --- | --- | --- |
| `site_key` | string | Public Cloudflare site key. |
| `secret_key` | string | **Write-only**; never echoed or sent to the browser. |
| `theme` | `auto` \| `light` \| `dark` | Widget theme. |
| `size` | `normal` \| `flexible` \| `compact` | Widget size. |
| `appearance` | `always` \| `interaction-only` | Widget visibility. |
| `language` | `auto` + Turnstile locale codes | Regional codes normalised on import (`fr-FR` → `fr`). |
| `error_message` | string | Shown on a failed challenge. |
| `protect_login` / `protect_register` / `protect_lostpassword` / `protect_comments` | `0` \| `1` | Native form toggles. |
| `failure_mode` | `block` \| `allow` | Applied only when Cloudflare is unreachable. |

No custom database tables are created. Uninstall deletes the option on single and
multisite installs.

## Verification decision matrix (`Verifier::verify()`)

1. **Missing keys** → callers gate on `is_configured()`; no widget is rendered and
   submissions are not blocked (an admin notice prompts configuration).
2. **Empty / oversized token (> 2048 chars)** → rejected locally, no network call.
3. **Cloudflare `success: false`** → rejected (even in `allow` mode). A well-formed
   JSON verdict is honoured regardless of HTTP status code.
4. **Transport failure** (WP_Error, empty body, JSON without `success`) → the
   `failure_mode` setting decides (`block` by default).

A static per-request cache keyed by the token hash prevents a second `/siteverify`
call for the same token (e.g. duplicate fields), which would otherwise return
`timeout-or-duplicate`. The remote IP sent to Cloudflare is strict `REMOTE_ADDR`
(never `X-Forwarded-For`), validated, and filterable.

## Front-end flow (`assets/js/turnstile.js`)

The Cloudflare API loads with `render=explicit&onload=cwebtsOnload`. The helper
renders every `.cf-turnstile` node, stores the returned widget id, and attaches
`expired/timeout/error` callbacks that reset the widget. After a **failed**
Elementor AJAX submission (`admin-ajax.php` response with `success === false`),
the corresponding widget is reset so a retry gets a fresh, single-use token.
Multi-form correlation is FIFO best-effort.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `cwebts_timeout` | `5` | siteverify HTTP timeout (seconds). |
| `cwebts_remoteip` | `REMOTE_ADDR` | Override or disable the IP sent to Cloudflare. |
| `cwebts_verify_action` | `false` | Enforce the Turnstile `action` server-side. |
| `cwebts_verify_hostname` | `false` | Enforce the Turnstile `hostname` server-side. |
| `cwebts_hostname_allowlist` | `[ home_url host ]` | Allowed hostnames when hostname checking is on. |

## Requirements

- WordPress ≥ 5.8 (deferred script via `script_loader_tag`), PHP ≥ 7.4.
- Elementor Pro for the Elementor field; the WordPress integrations work without it.
- A free Cloudflare Turnstile site key and secret key.

## Tests

`tests/run-tests.php` is a standalone runner (no PHPUnit) with WordPress function
stubs in `tests/bootstrap.php`. It covers the Verifier decision matrix, the
per-request cache, remote-IP handling, sanitisation, and the import edge cases.
The `tests/` directory lives at the repository root and is **not** part of the
shipped plugin. `tests/MANUAL-PROTOCOL.md` covers the integration paths that
cannot run headless (Elementor field, native forms).
