# Changelog

All notable changes to **CWeb Form Protection with Turnstile for Elementor Forms** are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.2.3] - 2026-11-11

### Fixed
- **The per-form "Cloudflare Turnstile" field showed no widget on visits served
  from Elementor's element cache, so every submission was rejected.** Measured
  on WP 7.1.4 and 7.2-beta5 with Elementor 4.2.2 and 4.4.0 + Elementor Pro 4.2.1:
  the first visit after the cache is emptied prints the widget and both scripts;
  every later visit (up to 24 hours, Elementor's default) prints the widget
  placeholder with neither script. Elementor's cache does record the enqueued
  handles (`cwebts`, `cwebts-api`) and re-enqueues them on a cache hit
  (`Document::print_elements()`), but the plugin only registered them lazily,
  while rendering a widget, so WordPress dropped the unknown handles.
  `Widget_Renderer::init()` now registers (never enqueues) both scripts on
  `wp_enqueue_scripts` when the keys are set. Pages without a widget still load
  no Turnstile script. The 1.2.2 check of the all-forms mode only covered the
  first render; that mode was not affected, since it enqueues on every page.
- **Keys entered, or the site key changed, after Elementor had cached a form left
  it without a widget (or with the old key) for up to 24 hours** while
  submissions were already checked. The 1.2.2 purge of `_elementor_element_cache`
  now runs when any setting that shapes the widget markup changes
  (`site_key`, `secret_key`, `theme`, `size`, `appearance`, `language`) as well as
  `protect_elementor_all_forms`. `flush_elementor_cache_on_toggle()` is renamed
  `flush_elementor_cache_on_change()`; a missing key compares equal to its
  default, so an option saved by an older version does not trigger a purge. Only
  the presence of `secret_key` is compared: replacing one secret with another
  does not change the markup and does not purge.
- **A protected form inside a reused Elementor template could stay empty on
  pages showing that template alone.** Found by an independent review (Codex),
  then reproduced on WP 7.1.4 + Elementor 4.4.0 + Elementor Pro 4.2.1: page A
  shows a protected form then template T through `[elementor-template]`; page C
  shows T alone. `Document::print_elements()` caches only the handles a
  document adds to the queue, and when T is first rendered on A the Turnstile
  handles are already queued, so T's cache records none. Page C then printed the
  widget with neither script. Theme Builder headers and footers and popups go
  through the same method (not reproduced). `Plugin::keep_turnstile_forms_dynamic()`
  now answers `elementor/element/is_dynamic_content` with `true` for a `form`
  widget carrying a `turnstile` field, and for every form when
  `protect_elementor_all_forms` is on. Elementor then stores a placeholder for
  that element and renders it on each request, so the field (or the all-forms
  injection) enqueues the scripts wherever the form appears. Forms without the
  field stay cached. A form whose own "Cache Settings" control (Advanced tab) is
  set to Inactive was already rendered on each request; one forced to Active
  overrides the filter and stays cached (Elementor 4.4.0, `should_render_shortcode()`).
- **Pages cached by an earlier version kept the old markup after the update.** A
  cache hit runs neither the field nor the filter above, so a template cached
  without the scripts stayed empty for up to 24 hours after updating (pointed
  out by the second Codex review). `Settings::flush_elementor_cache_after_update()`
  runs on `init`: when the new `cwebts_version` option differs from
  `CWEBTS_VERSION`, it stores the running version and deletes
  `_elementor_element_cache` once (not Elementor's generated CSS).
  `uninstall.php` removes the option. Measured on WP 7.1.4: a template cached
  the 1.2.2 way stayed without scripts under the new code until the stored
  version differed; the next request purged it and the page got its script.

### Changed
- Plugin Check 2.1.0 (2026-08-16) widened its `OffloadedContent` sniff from HTML
  strings to every string, and flags anything containing `cloudflare.com`. The
  three hits are the Turnstile service itself (Cloudflare requires `api.js` from
  its own URL: "Proxying or caching this file will cause Turnstile to fail"), the
  server-side `siteverify` endpoint and a link to the Cloudflare dashboard, all
  disclosed in the readme. They now carry a targeted, justified `phpcs:ignore`.
  Plugin Check 2.2.0: "No errors found" on WP 7.1.4 and 7.2-beta5.
- Suite grows from 101 to 129 tests. They call the callbacks the plugin actually
  hooks (the WordPress hook stubs now keep them, and `Plugin::init()` boots under
  test): the one-time purge after an update; each of the seven
  widget-shaping settings triggers the purge on its own, the secret only when it
  appears or disappears, unrelated settings and missing keys do not; the renderer
  registers both handles on `wp_enqueue_scripts` without enqueuing them; the
  `is_dynamic_content` filter is hooked with two arguments and covers forms with
  and without the field, other widgets, malformed data and the all-forms mode.
  Fourteen deliberate defects (wrong callback names, wrong argument count, a
  forgotten setting, the secret compared in clear, a purge on every request...)
  each make the suite fail.

### Verified
- WordPress 7.1.4 (PHP 8.3) and 7.2-beta5 (PHP 8.5), Elementor 4.4.0, Elementor
  Pro 4.2.1, WooCommerce 11.3.0: a 28-step browser scenario with the Cloudflare
  test keys passes on both (native login, registration, lost password, comments,
  admin replies, block-editor notes, Elementor per-form field including a cached
  visit and a reused template shown alone, all-forms mode, the four WooCommerce forms with a real order, and a
  rejecting secret key). With the template fix cancelled by a temporary
  must-use plugin, the reused-template step fails and only that one (27/28).
  Unit tests pass on PHP 7.4, 8.3 and 8.5. Plugin Check 2.2.0: "No errors
  found" on both. `debug.log`: no line from the plugin.
- A read of the WordPress 7.1.4 to 7.2-beta5 core diff found no change to any hook
  the plugin uses.

## [1.2.2] — 2026-08-13

### Fixed
- **Elementor 4's element cache defeated the "protect all Elementor Pro forms"
  injection.** Elementor 4 stores each widget's rendered HTML in the
  `_elementor_element_cache` post meta for 24 hours — no longer an experiment,
  it is the standard behaviour. On a cache hit the
  `elementor/widget/render_content` filter is **not applied at all** (measured
  with a probe on WP 7.1-RC3 + Elementor Pro 4.2.1: 0 calls), so
  `Elementor_All_Forms::inject()` never ran. Meanwhile
  `elementor_pro/forms/validation` kept rejecting submissions: a form with no
  widget, refusing every submission, with nothing for the visitor to solve.
  `Settings::init()` now watches its own option (`update_option_cwebts_settings`
  and `add_option_cwebts_settings`) and, when `protect_elementor_all_forms`
  actually changes, drops the cached markup with `delete_post_meta_by_key()`
  plus Elementor's own `files_manager->clear_cache()` when it is available.
  - Exposure was bounded: up to 24 hours after switching the option on, and only
    on pages already rendered. Once the cache was rebuilt it contained the
    widget and the mode worked.
  - The default per-form mode was never affected — Elementor drops the cache when
    the page is saved, which is exactly when the Turnstile field is added.
- Suite grows from 95 to 101 tests, covering both hooks, the purge on enable and
  on disable, no purge when other settings change, and the first-save path.

## [1.2.1] — 2026-08-13

### Fixed
- **Translations were requested too early.** `Settings::defaults()` carried a
  `__()` call, and every integration reaches `defaults()` from its constructor on
  `plugins_loaded` (via `is_enabled()`). WordPress 6.7+ raises a
  `_doing_it_wrong` notice — *"Translation loading for the … domain was triggered
  too early"* — for any translation requested before `after_setup_theme`. The
  default message is now resolved by `Settings::default_error_message()`, called
  only while rendering or validating a form. Nothing about form protection
  changes.
  - Measured on WP 7.1-RC3, not deduced: one notice per page load, **on every
    site**, translated or not. `WP_Textdomain_Registry::get_path_from_lang_dir()`
    falls back to the domain's custom path, and the `Domain Path: /languages`
    header registers `wp-content/plugins/<slug>/languages/` as that path — so the
    registry always returns a directory and the notice is never skipped. With
    `WP_DEBUG` off it is silent, which is why it went unnoticed.
- Side effect, on purpose: an empty **Error message** field is now stored empty
  and translated at display time. It used to store the default resolved at save
  time, freezing whichever language was active then.

### Notes — WordPress 7.1 compatibility
- `Tested up to: 7.1`. Reviewed against the 7.1 field guide; none of the changes
  reach this plugin: the iframed post editor (no editor asset — the admin CSS is
  gated on the `settings_page_cwebts` hook), client-side media processing (no
  media handling), `@wordpress/components` (classic Settings API screen, no
  React), the persistent toolbar (no toolbar item), the SVG Icon API (new
  capability), the Abilities API, and jQuery UI 1.14.2 — the front-end helper
  only uses jQuery **core** events (`updated_checkout`, `checkout_error`,
  `ajaxComplete`), behind a `window.jQuery` guard.
- Unit coverage grew from 92 to 95 tests: the translation stub now counts calls,
  so building the whole integration graph is asserted to request zero
  translations.

## [1.2.0] — 2026-06-19

### Added
- **WooCommerce form protection** (opt-in, one toggle per form, all off by
  default). New **Settings → CWeb Form Protection → WooCommerce forms** section
  with four toggles:
  - **Checkout (classic)** — renders right before the "Place order" button
    (`woocommerce_review_order_before_submit`) and verifies on
    `woocommerce_checkout_process`.
  - **Login** — renders on `woocommerce_login_form` (covers both the "My account"
    login and the checkout "returning customer" login) and verifies on the scoped
    `woocommerce_process_login_errors` filter.
  - **Registration** — renders on `woocommerce_register_form` and verifies on
    `woocommerce_process_registration_errors`.
  - **Account details** — renders on `woocommerce_edit_account_form` and verifies
    on `woocommerce_save_account_details_errors`.

### Notes
- The checkout widget is placed **right before the "Place order" button**, where
  shoppers expect it. That area is inside the block WooCommerce reloads via AJAX
  (`update_order_review`), so the front-end helper re-renders the widget immediately
  on the `updated_checkout` event (with the `MutationObserver` as a backstop) to
  keep a token present at submit time. It also resets the widget on `checkout_error`
  so that, when a checkout fails after the captcha was solved (an unchecked terms
  box, a declined payment…), a corrected resubmit gets a fresh token instead of
  failing Cloudflare with `timeout-or-duplicate`.
- Registration uses `woocommerce_process_registration_errors` (the "My account"
  register POST only) rather than `woocommerce_register_post`, which also fires for
  account creation during checkout and for programmatic customer creation. Account
  creation during checkout is therefore covered by the **Checkout** toggle, not the
  **Registration** toggle.
- WooCommerce error messages use the raw configured message (no extra "Error:"
  prefix), because WooCommerce already prefixes its own form errors.
- Scope: covers the **classic (shortcode) checkout**. The **WooCommerce Checkout
  Block** (Gutenberg) is **not** protected in this version, and the *Pay for order*
  and *Add payment method* forms are out of scope. Lost password keeps the existing
  "Lost password form" toggle; product reviews keep the "Comment form" toggle.
- New code lives in `includes/integrations/class-wc-checkout.php`,
  `class-wc-login.php`, `class-wc-register.php`, `class-wc-account.php`, built on
  the same `Abstract_Integration` base as the WordPress integrations. The hooks
  stay inert when WooCommerce is absent. Unit coverage grew from 73 to 92 tests.

## [1.1.1] — 2026-06-10

### Fixed
- **Comment replies from the WordPress admin.** With "Protect comments" enabled,
  replying to a comment from the dashboard or admin bar (the `replyto-comment`
  AJAX action) was rejected with the challenge error: WordPress builds those
  replies server-side without rendering a Turnstile widget, so no token is ever
  sent, yet `wp_new_comment()` still runs `preprocess_comment`. Moderators
  (`current_user_can( 'moderate_comments' )`) now skip the check in the admin
  AJAX context (`wp_doing_ajax()`). The public comment form still renders the
  widget and is verified exactly as before.
- **Unbounded widget auto-retry.** On a persistent render error (e.g. a site key
  locked to another domain), the Turnstile widget retried indefinitely — a flood
  of 400s to Cloudflare and a widget flickering roughly twice a second. The widget
  now renders with `retry: 'never'` so the plugin owns the retry budget: the
  `error-callback` resets the widget at most twice to ride out a transient network
  hiccup, then stops (no reset = no re-challenge) and Turnstile shows its default
  error state. A successful challenge resets the counter, so errors spread across a
  long session never lock out a legitimate visitor. No change to server-side
  verification.

## [1.1.0] — 2026-06-10

### Added
- **Optional "Protect all Elementor Pro forms" setting** (off by default). When
  enabled, the Turnstile widget is injected into every Elementor Pro form
  (server-side, just before the submit button, always inside the `<form>`) and
  every submission is verified through the global `elementor_pro/forms/validation`
  hook — without adding the per-form field. Forms that already carry the
  per-form field are skipped (no double widget, no double check). The per-form
  field remains the default and the product's differentiator.
- Late-rendered forms (Elementor popups, lazy-load, AJAX) are covered by a
  debounced `MutationObserver` plus a front-end-wide asset enqueue while the
  option is active, so a widget never goes missing while validation still runs.

### Notes
- New code lives in `includes/integrations/class-elementor-all-forms.php` with
  pure, unit-tested helpers (`inject_widget_before_submit()`,
  `record_has_turnstile_field()`). No hard Elementor Pro dependency: the hooks
  stay inert without Elementor Pro.

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
