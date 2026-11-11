=== CWeb Form Protection with Turnstile for Elementor Forms ===
Contributors: alexandreminem
Tags: turnstile, captcha, elementor, spam, cloudflare
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: elementor
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Cloudflare Turnstile to your forms — with a per-form field for Elementor Pro so you choose exactly which forms are protected.

== Description ==

This plugin adds [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) — a free, privacy-friendly CAPTCHA alternative — to your WordPress site.

Its key difference from other Turnstile plugins is **per-form control for Elementor Pro**: instead of toggling Turnstile globally for every Elementor form, you add a **"Cloudflare Turnstile" field** to the specific forms you want to protect, exactly like Elementor's built-in reCAPTCHA field. Forms without the field are left untouched. If you prefer the all-at-once approach, an optional setting protects **every** Elementor Pro form automatically.

= Features =

* **Per-form Turnstile field for Elementor Pro Forms** — drag it into the forms you choose.
* **Optional "all Elementor Pro forms" switch** — protect every Elementor Pro form at once, without adding the field to each one (off by default).
* Optional protection for the built-in WordPress forms:
  * Login
  * Registration
  * Lost password
  * Comments
* Optional protection for WooCommerce forms (one toggle each):
  * Checkout (classic shortcode checkout)
  * Login (My account, and the checkout "returning customer" login)
  * Registration (My account)
  * Account details
* One-click import of keys and settings from the "Simple Cloudflare Turnstile" plugin (no need to recreate your Cloudflare keys).
* Global widget appearance settings (theme, size, visibility, language).
* Strict server-side token verification (single-use tokens, 5-minute validity).
* Secure-by-default behaviour: missing/invalid tokens are always blocked; the
  behaviour when Cloudflare itself is unreachable is configurable.
* Write-only secret key (never displayed or sent to the browser).
* Lightweight: in the default per-form mode, the Cloudflare script loads only on pages that actually show a widget. (The optional "all forms" mode loads it across the front end so late-loaded forms are covered.)

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
6. (WooCommerce) Enable the toggles under **WooCommerce forms** for the checkout, login, registration and/or account-details forms.

== Frequently Asked Questions ==

= Does it protect every Elementor form automatically? =

By default, no — and that is the point. You add the Turnstile field only to the forms you want to protect, and forms without the field are left untouched. If you prefer, turn on **Settings → CWeb Form Protection → Elementor Pro forms → "All Elementor Pro forms"** to protect every Elementor Pro form automatically, without adding the field to each one.

= Does it work without Elementor Pro? =

The Elementor field needs Elementor Pro (Forms is a Pro module). The WordPress login, registration, lost password and comment integrations work on any WordPress site.

= Which WooCommerce forms are protected? =

Under **Settings → CWeb Form Protection → WooCommerce forms** you can protect the classic checkout, the login form (My account and the checkout "returning customer" login), the registration form and the account-details form — each with its own toggle. The lost-password form uses the existing "Lost password form" toggle, and product reviews use the "Comment form" toggle.

This version protects the **classic (shortcode) checkout**. The newer **WooCommerce Checkout Block** (the Gutenberg block) is **not** covered yet, and the *Pay for order* and *Add payment method* forms are out of scope. Account creation during checkout is protected by the **Checkout** toggle (not the **Registration** toggle).

= Is the secret key safe? =

Yes. The secret key is stored server-side, is never printed in the page, and is never sent to the browser. The settings screen never displays it back.

= I already use "Simple Cloudflare Turnstile". Do I have to recreate my keys? =

No. If that plugin's settings are present, the **Settings → CWeb Form Protection** page shows an "Import keys & settings" button that copies your site key, secret key, appearance options and form toggles over. Your existing Cloudflare keys keep working — nothing is regenerated, and the other plugin is left untouched.

= What happens if Cloudflare can't be reached? =

Missing or invalid tokens are always rejected. If Cloudflare's verification endpoint is unreachable (network error or timeout), the **"If Cloudflare is unreachable"** setting decides whether to block (default, more secure) or allow (more available) the submission.

== Screenshots ==

1. The settings page (keys, appearance, WordPress form toggles).
2. The "Cloudflare Turnstile" field inside an Elementor Pro form.
3. The Turnstile widget displayed on a protected form.

== Changelog ==

= 1.2.3 =
* Fix: forms carrying the "Cloudflare Turnstile" field showed no widget, and rejected every submission, on visits served from Elementor's element cache. Elementor keeps each page's rendered forms for 24 hours by default. On later visits it asks WordPress again for the scripts the form used the first time, but this plugin only declared its scripts while drawing a widget, so WordPress ignored the request and the widget never appeared. The scripts are now declared on every page and still only loaded where a widget is shown.
* Fix: entering your keys after Elementor had cached a form left that form without a widget for up to 24 hours. Changing the site key, or the widget theme, size, appearance or language, left the old markup in place the same way. Saving any of these settings now clears Elementor's cached markup, as the "All Elementor Pro forms" setting already did.
* Fix: a protected form inside a reused Elementor template, for example one inserted with the [elementor-template] shortcode, could show an empty widget and reject every submission. When the template was first cached on a page that had already loaded the Turnstile scripts, its cached copy did not ask for them, so on a page showing the template alone the widget was never drawn. Forms carrying the field, and every form when the "All Elementor Pro forms" setting is on, now stay out of Elementor's element cache and are rendered on each visit.
* After an update, the plugin now clears Elementor's element cache once, so forms cached by an earlier version get these fixes without waiting up to 24 hours.
* Plugin Check 2.1 and later report the three Cloudflare addresses the plugin uses. They belong to the Turnstile service itself: Cloudflare requires its script to be loaded from its own address, and the service is described in the "Third-party service" section. Those lines now carry an explanation for reviewers.
* Tested with WordPress 7.1.4 and 7.2 beta 5, Elementor 4.4, Elementor Pro 4.2 and WooCommerce 11.3.

= 1.2.2 =
* Fix, for the optional "All Elementor Pro forms" setting only: turning it on could leave forms showing no Turnstile widget while their submissions were already being rejected — visitors were asked to confirm they are not a robot, with nothing to confirm. Elementor 4 keeps the rendered HTML of each widget in a 24-hour cache, and a cached form never passes through the filter this plugin adds its widget with. Saving the setting now clears that cached markup, so the widget shows up right away. Forms carrying the "Cloudflare Turnstile" field were never affected.

= 1.2.1 =
* Tested with WordPress 7.1.
* Fix: the plugin no longer asks WordPress for its translations while plugins load, which WordPress 6.7 and later report as too early. Form protection was never affected; this removes a PHP notice logged on every page load on sites that have debugging enabled.
* When the "Error message" field is left empty, the default message is now translated as it is displayed, instead of the wording being frozen into your settings the moment you saved them.

= 1.2.0 =
* New: protect WooCommerce forms, with one opt-in toggle each (all off by default) under a new "WooCommerce forms" section — Checkout (classic shortcode checkout), Login (My account and the checkout "returning customer" login), Registration (My account), and Account details.
* The checkout widget sits right before the "Place order" button. WooCommerce reloads that area via AJAX when the customer changes shipping or payment, so the widget is re-rendered immediately afterwards to keep a valid token at submit time.
* Registration is validated on the scoped `woocommerce_process_registration_errors` hook; account creation during checkout is covered by the Checkout toggle instead, so enabling Registration alone never blocks a checkout.
* Scope: the classic (shortcode) checkout only. The WooCommerce Checkout Block (Gutenberg) is not protected in this version; the lost-password form keeps its existing toggle and product reviews keep the comment toggle.

= 1.1.1 =
* Fix: replying to a comment from the WordPress admin (the `replyto-comment` AJAX action) was blocked when "Protect comments" was enabled. WordPress builds those replies server-side with no Turnstile widget, so no token is ever sent. Moderators now skip the check in the admin AJAX context; the public comment form stays protected.
* Fix: a widget hitting a persistent render error (for example a site key restricted to another domain) retried forever, flooding Cloudflare with failed requests and flickering. It now retries a couple of times to recover from a transient network glitch, then stops; a successful challenge clears the counter. No change to server-side verification.

= 1.1.0 =
* New: optional "Protect all Elementor Pro forms" setting (off by default). When enabled, the Turnstile widget is added to every Elementor Pro form automatically — including forms loaded in popups or via AJAX — without adding the per-form field. The per-form field remains the default.

= 1.0.1 =
* Fix: the Cloudflare Turnstile widget now renders on the WooCommerce "Lost password" form (/my-account/lost-password/). Previously the widget did not appear and every password reset was rejected.

= 1.0.0 =
* Initial release: per-form Turnstile field for Elementor Pro; WordPress login, registration, lost password and comment integrations; global appearance settings; strict server-side verification.

== Upgrade Notice ==

= 1.2.3 =
Recommended if you add the "Cloudflare Turnstile" field to Elementor Pro forms, directly or through a reused template. With Elementor's element cache on (its default), the widget could stay empty on cached visits and every submission was rejected.

= 1.2.2 =
Recommended if you use the optional "All Elementor Pro forms" setting. Turning it on could leave cached forms without a widget for up to 24 hours while their submissions were rejected. Saving the setting now clears Elementor's cached markup.

= 1.2.1 =
Compatibility update for WordPress 7.1. Removes a translation-loading notice on WordPress 6.7 and later, and lets the default error message follow the site language. Form protection is unchanged.

= 1.2.0 =
Adds optional Turnstile protection for WooCommerce forms (checkout, login, registration, account details), one toggle each. Existing setups are unchanged: every new toggle is off by default. The classic checkout is covered; the Checkout Block is not.

= 1.1.1 =
Fixes comment replies from the WordPress admin being blocked when comment protection is on (the public form is unaffected), and stops a failed widget from retrying Cloudflare indefinitely.

= 1.1.0 =
Adds an optional setting to protect all Elementor Pro forms at once. Existing setups are unchanged: the option is off by default.

= 1.0.0 =
Initial release.
