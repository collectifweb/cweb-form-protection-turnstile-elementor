# CWeb Turnstile for Elementor Forms

> Add Cloudflare Turnstile to your forms — with a **per-form field for Elementor Pro** so you choose exactly which forms are protected. Plus optional protection for the built-in WordPress login, registration, lost-password and comment forms.

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
![PHP >= 7.4](https://img.shields.io/badge/PHP-%3E%3D%207.4-777BB4)
![WordPress >= 5.8](https://img.shields.io/badge/WordPress-%3E%3D%205.8-21759B)

**CWeb Turnstile for Elementor Forms** brings [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) — a free, privacy-friendly CAPTCHA alternative — to WordPress.

> This plugin is a third-party companion for **Elementor** (Elementor Ltd) and **Cloudflare Turnstile** (Cloudflare, Inc.). It is not affiliated with, sponsored by, or endorsed by either. The `CWeb` prefix refers to **Collectif WEB**, the agency that maintains the plugin.

## Why?

Most Turnstile plugins flip a single global switch: Turnstile is on for **every** Elementor form or none. That is blunt — you often want the captcha on your public contact form but not on a gated internal form, or vice-versa.

This plugin takes Elementor's own approach to reCAPTCHA: Turnstile is a **field you drag into a form**. Only forms that contain the field are protected; everything else is untouched. You get per-form control without code.

- 🎯 **Per-form** — add the *Cloudflare Turnstile* field only where you want it
- 🔒 **Secure by default** — missing/invalid tokens always blocked; behaviour on a Cloudflare outage is configurable
- 🔑 **Write-only secret** — the secret key is never printed in a page or sent to the browser
- 🪶 **Lightweight** — the Cloudflare script loads (deferred) only on pages that actually render a widget
- 📥 **Migration-friendly** — one-click import of keys & settings from *Simple Cloudflare Turnstile* (no key regeneration)

## How it works

```
Form renders  →  <div class="cf-turnstile" data-*>  +  Cloudflare api.js (explicit render)
     │
     ▼
Visitor solves the challenge  →  cf-turnstile-response token in the form POST
     │
     ▼
Server  →  Verifier::verify()  →  Cloudflare /siteverify  →  4-case decision matrix
     │                                                         (block by default on transport failure)
     ▼
Elementor field validation / WP form hook  →  accept or reject the submission
```

## Features

- **Per-form Turnstile field for Elementor Pro Forms.**
- Optional protection for built-in WordPress forms: **login, registration, lost password, comments**.
- **One-click import** of keys + settings from the *Simple Cloudflare Turnstile* plugin.
- Global widget appearance (theme, size, visibility, language).
- Strict server-side verification (single-use tokens, 5-minute validity, request-level cache to avoid double verification).
- Configurable failure mode (`block` by default) for when Cloudflare is unreachable.

## Requirements

- WordPress ≥ 5.8, PHP ≥ 7.4.
- **Elementor Pro** for the Elementor field (the Forms widget is a Pro feature). The WordPress form integrations work without it.
- A free Cloudflare Turnstile **site key** and **secret key**.

## Installation

1. Copy the `cweb-turnstile-for-elementor-forms` folder into `wp-content/plugins/` and activate it.
2. **Settings → CWeb Turnstile** → enter your site key and secret key.
3. Elementor: edit a form → add the **Cloudflare Turnstile** field → save.
4. WordPress forms: enable the toggles you need.

## Architecture

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full layout, data model, verification matrix and filters.

```
cweb-turnstile-for-elementor-forms/      ← the shippable plugin (this becomes SVN trunk)
  cweb-turnstile-for-elementor-forms.php Bootstrap + autoloader (namespace CWebTS)
  includes/                              Settings, Verifier, Widget_Renderer, Elementor field, WP integrations
  assets/js/turnstile.js                 Explicit render + AJAX reset + expired-callback
  languages/                             .pot
tests/                                   Verifier + import unit tests, manual protocol (dev-only, not shipped)
docs/                                    Architecture, plan, status, screenshots, WP.org assets
```

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `cwebts_timeout` | `5` | siteverify HTTP timeout (seconds). |
| `cwebts_remoteip` | `REMOTE_ADDR` | Override/disable the IP sent to Cloudflare (never uses `X-Forwarded-For`). |
| `cwebts_verify_action` | `false` | Enforce the Turnstile `action` server-side. |
| `cwebts_verify_hostname` | `false` | Enforce the Turnstile `hostname` server-side. |
| `cwebts_hostname_allowlist` | `[ home_url host ]` | Allowed hostnames when hostname checking is on. |

## Development

```bash
php tests/run-tests.php   # 52 assertions, no PHPUnit needed
```

## License

[GPL-2.0-or-later](LICENSE). Not affiliated with Cloudflare, Inc. or Elementor Ltd.
