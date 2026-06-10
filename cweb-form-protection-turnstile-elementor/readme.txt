=== CWeb Form Protection with Turnstile for Elementor Forms ===
Contributors: alexandreminem
Tags: turnstile, captcha, elementor, spam, cloudflare
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: elementor
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Cloudflare Turnstile to your forms — with a per-form field for Elementor Pro so you choose exactly which forms are protected.

== Description ==

This plugin adds [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) — a free, privacy-friendly CAPTCHA alternative — to your WordPress site.

Its key difference from other Turnstile plugins is **per-form control for Elementor Pro**: instead of toggling Turnstile globally for every Elementor form, you add a **"Cloudflare Turnstile" field** to the specific forms you want to protect, exactly like Elementor's built-in reCAPTCHA field. Forms without the field are left untouched.

= Features =

* **Per-form Turnstile field for Elementor Pro Forms** — drag it into the forms you choose.
* Optional protection for the built-in WordPress forms:
  * Login
  * Registration
  * Lost password
  * Comments
* One-click import of keys and settings from the "Simple Cloudflare Turnstile" plugin (no need to recreate your Cloudflare keys).
* Global widget appearance settings (theme, size, visibility, language).
* Strict server-side token verification (single-use tokens, 5-minute validity).
* Secure-by-default behaviour: missing/invalid tokens are always blocked; the
  behaviour when Cloudflare itself is unreachable is configurable.
* Write-only secret key (never displayed or sent to the browser).
* Lightweight: the Cloudflare script loads only on pages that actually show a widget.

= Requirements =

* The Elementor field requires **Elementor Pro** (the Forms widget is a Pro feature). Without Elementor Pro, the WordPress form integrations still work.
* A free Cloudflare Turnstile site key and secret key.

= Third-party service =

This plugin renders the official Cloudflare Turnstile widget and verifies tokens with Cloudflare.

* When a protected form is displayed, the visitor's browser loads `https://challenges.cloudflare.com/turnstile/v0/api.js` from Cloudflare.
* When a protected form is submitted, your server sends a request to `https://challenges.cloudflare.com/turnstile/v0/siteverify` containing: the Turnstile token from the widget (`cf-turnstile-response`), your secret key, and — unless disabled with the `cwebts_remoteip` filter — the visitor's IP address (`REMOTE_ADDR`). The secret key is never sent to the browser.

* Cloudflare Turnstile: https://www.cloudflare.com/products/turnstile/
* Terms of Service: https://www.cloudflare.com/website-terms/
* Privacy Policy: https://www.cloudflare.com/privacypolicy/

= About the name =

The distinctive part of the name is **CWeb Form Protection**, after **Collectif WEB**, the agency that maintains this plugin. The trailing "with Turnstile for Elementor Forms" only describes what the plugin integrates with: "Turnstile" is the Cloudflare service it uses, and "for Elementor Forms" signals compatibility with Elementor's Forms widget. This plugin is not affiliated with, sponsored by, or endorsed by Cloudflare, Inc. or Elementor Ltd.

= Trademarks =

Not affiliated with Cloudflare, Inc. or Elementor Ltd. "Cloudflare" and "Turnstile" are trademarks of Cloudflare, Inc. "Elementor" is a trademark of Elementor Ltd. These names are used only to describe compatibility.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it.
3. Go to **Settings → CWeb Form Protection** and enter your Cloudflare **site key** and **secret key**.
4. (Elementor) Edit a form, add the **Cloudflare Turnstile** field where you want the widget, then save.
5. (WordPress forms) Enable the toggles for login, registration, lost password and/or comments.

== Frequently Asked Questions ==

= Does it protect every Elementor form automatically? =

No — and that is the point. You add the Turnstile field only to the forms you want to protect. Forms without the field are not affected. (An automatic "all forms" mode may be added in a future version.)

= Does it work without Elementor Pro? =

The Elementor field needs Elementor Pro (Forms is a Pro module). The WordPress login, registration, lost password and comment integrations work on any WordPress site.

= Is the secret key safe? =

Yes. The secret key is stored server-side, is never printed in the page, and is never sent to the browser. The settings screen never displays it back.

= I already use "Simple Cloudflare Turnstile". Do I have to recreate my keys? =

No. If that plugin's settings are present, the **Settings → CWeb Form Protection** page shows an "Import keys & settings" button that copies your site key, secret key, appearance options and form toggles over. Your existing Cloudflare keys keep working — nothing is regenerated, and the other plugin is left untouched.

= What happens if Cloudflare can't be reached? =

Missing or invalid tokens are always rejected. If Cloudflare's verification endpoint is unreachable (network error or timeout), the **"If Cloudflare is unreachable"** setting decides whether to block (default, more secure) or allow (more available) the submission.

== Screenshots ==

1. The settings page (keys, appearance, WordPress form toggles).
2. The "Cloudflare Turnstile" field inside an Elementor Pro form.

== Changelog ==

= 1.0.1 =
* Fix: the Cloudflare Turnstile widget now renders on the WooCommerce "Lost password" form (/my-account/lost-password/). Previously the widget did not appear and every password reset was rejected.

= 1.0.0 =
* Initial release: per-form Turnstile field for Elementor Pro; WordPress login, registration, lost password and comment integrations; global appearance settings; strict server-side verification.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
