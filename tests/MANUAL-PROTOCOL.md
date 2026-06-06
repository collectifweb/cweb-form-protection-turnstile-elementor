# Manual test protocol

The automated suite (`php tests/run-tests.php`) covers the `Verifier` matrix,
the request cache and settings sanitization without WordPress. The integration
behaviour (Elementor field, native forms, JS reset) must be checked on a real
WordPress install. This file is the versioned checklist.

## Cloudflare test keys (deterministic)

From <https://developers.cloudflare.com/turnstile/troubleshooting/testing/>:

| Purpose | Site key | Secret key |
| --- | --- | --- |
| Always passes (visible) | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` |
| Always blocks | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AA` |
| Forces an interactive challenge | `3x00000000000000000000FF` | — |
| Force "token already spent" | — | `3x0000000000000000000000000000000AA` |

## Setup

1. Fresh WordPress (latest) + Elementor + Elementor Pro.
2. Activate this plugin.
3. **Settings → CWeb Turnstile**: enter the *always passes* site/secret keys.

## A. Configuration & safety

- [ ] With no keys saved: an admin notice appears; no widget renders; forms still submit.
- [ ] Save keys: notice disappears.
- [ ] Secret field never shows the stored value; saving with it empty keeps the key; "Remove the saved secret key" clears it.
- [ ] View source of a protected page: the secret key is **not** present anywhere.

## A2. Import from Simple Cloudflare Turnstile

- [ ] Install + configure *Simple Cloudflare Turnstile* with real keys and a couple of toggles.
- [ ] On our settings page, the "Import keys & settings" box appears.
- [ ] Click import → site key, secret key, appearance and toggles are copied; success notice shows the count.
- [ ] The other plugin is still active and unchanged; Cloudflare keys were not regenerated.
- [ ] With *Simple Cloudflare Turnstile* absent, the import box does not appear.

## B. Elementor (the differentiator)

- [ ] Create form A **with** the Cloudflare Turnstile field; form B **without** it, on the same page.
- [ ] Form A shows the widget; form B does not.
- [ ] Submit form B → succeeds (not affected).
- [ ] Submit form A with *always passes* keys → succeeds.
- [ ] Switch to *always blocks* keys → form A submission shows the error on the field; form B still works.
- [ ] **Single-use token:** add a required text field to form A, leave it empty, solve Turnstile, submit → validation error. Fix the field and submit again → **succeeds** (no `timeout-or-duplicate`; the widget was reset).
- [ ] Two forms with the field on one page: each submits independently and validates.

## C. WordPress native forms

For each, enable its toggle in settings.

- [ ] **Login**: widget shows on `wp-login.php`; wrong/blocked token → login refused even with valid credentials; passing token + valid credentials → login works.
- [ ] **Registration** (`wp-login.php?action=register`): blocked token → registration error; passing → proceeds.
- [ ] **Lost password** (`wp-login.php?action=lostpassword`): blocked → error; passing → proceeds.
- [ ] **Comments**: widget shows for logged-out **and** logged-in users; blocked token → 403 with back link; passing → comment posts. Pingback/trackback still work (not blocked).

## D. Failure mode

- [ ] Temporarily block outbound access to `challenges.cloudflare.com` (hosts file / firewall).
- [ ] `failure_mode = block` (default): protected submissions are refused.
- [ ] `failure_mode = allow`: protected submissions go through.
- [ ] Restore access.

## E. Assets

- [ ] On a page with no protected form, `api.js` is **not** loaded.
- [ ] On a protected page, `api.js` loads once, with `defer`.
- [ ] No JS console errors; widget renders once (no duplicates).

## F. Cleanup

- [ ] Deactivate + delete the plugin → `cwebts_settings` option is removed (check the options table).
