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
- [ ] **Cached visit (Elementor element cache, on by default):** open the page in a
  second private window, after a first visit has filled the cache (the
  `_elementor_element_cache` post meta exists). Form A must still show the widget
  and submit. A first visit alone proves nothing: up to 1.2.2 the widget showed
  on the first visit and vanished on every cached one.
- [ ] **Keys entered after caching:** with the page already cached, remove then
  re-enter the site key and save. The next visit shows the widget (the save
  clears Elementor's cached markup).
- [ ] **Reused template:** save form A's container as a template T. Page P1 shows
  another protected form, then T through `[elementor-template id="…"]`; page P2
  shows T alone. Visit P1 first (it caches T while the scripts are already
  loaded), then P2 in a private window: T's form must show the widget and submit.
  Up to 1.2.2 it stayed empty on P2.

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

- [ ] Deactivate + delete the plugin → `cwebts_settings` and `cwebts_version` options are removed (check the options table).
