# Captcha Field for Turnstile — Elementor & WordPress Forms

Add [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) — a free, privacy-friendly CAPTCHA alternative — to your WordPress forms.

The differentiator: **per-form control for Elementor Pro**. Instead of switching Turnstile on for *every* Elementor form, you add a **“Cloudflare Turnstile” field** to the specific forms you want to protect — just like Elementor’s built-in reCAPTCHA field. Forms without the field are left untouched.

## Features

- **Per-form Turnstile field for Elementor Pro Forms.**
- Optional protection for built-in WordPress forms: **login, registration, lost password, comments**.
- **One-click import** of keys + settings from the *Simple Cloudflare Turnstile* plugin (no need to recreate Cloudflare keys).
- Global widget appearance (theme, size, visibility, language).
- Strict server-side verification (single-use tokens, 5-minute validity, request-level cache to avoid double verification).
- Secure by default: missing/invalid tokens are always blocked; the behaviour when Cloudflare is unreachable is configurable (`block` by default).
- Write-only secret key (never displayed, never sent to the browser).
- Loads the Cloudflare script only on pages that actually render a widget (with `defer`).

## Requirements

- WordPress ≥ 5.8, PHP ≥ 7.4.
- **Elementor Pro** for the Elementor field (the Forms widget is a Pro feature). The WordPress form integrations work without it.
- A free Cloudflare Turnstile **site key** and **secret key**.

## Installation

1. Copy the `captcha-field-for-turnstile` folder into `wp-content/plugins/` and activate it.
2. **Settings → Turnstile Forms** → enter your site key and secret key.
3. Elementor: edit a form → add the **Cloudflare Turnstile** field → save.
4. WordPress forms: enable the toggles you need.

## Architecture

```
captcha-field-for-turnstile.php   Bootstrap + autoloader
includes/
  class-plugin.php                Wires hooks, instantiates services
  class-settings.php              Settings API, admin page, write-only secret
  class-verifier.php              siteverify wrapper, 4-case matrix, per-request cache
  class-widget-renderer.php       Widget HTML + conditional asset enqueue (defer)
  elementor/class-turnstile-field.php   Per-form Elementor Pro field
  integrations/                   WP login / register / lost password / comments
assets/js/turnstile.js            Explicit render + AJAX reset + expired-callback
assets/css/admin.css
tests/                            Verifier + sanitization unit tests, manual protocol
```

### Verification decision matrix

1. **Missing keys** → widget is not rendered, submissions are not blocked, an admin notice is shown.
2. **Empty / oversized token (> 2048 chars)** → rejected locally, no network call.
3. **Cloudflare `success: false`** → rejected.
4. **Network error / timeout / invalid JSON** → `failure_mode` (`block` by default).

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `turnstile_forms_timeout` | `5` | siteverify HTTP timeout (seconds). |
| `turnstile_forms_remoteip` | `REMOTE_ADDR` | Override/disable the IP sent to Cloudflare (never uses `X-Forwarded-For`). |
| `turnstile_forms_verify_action` | `false` | Enforce the Turnstile `action` server-side. |
| `turnstile_forms_verify_hostname` | `false` | Enforce the Turnstile `hostname` server-side. |
| `turnstile_forms_hostname_allowlist` | `[ home_url host ]` | Allowed hostnames when hostname checking is on. |

## License

GPL-2.0-or-later. Not affiliated with Cloudflare, Inc. or Elementor Ltd.
