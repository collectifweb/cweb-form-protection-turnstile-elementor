<?php
/**
 * Standalone test runner (no PHPUnit/WordPress needed): `php tests/run-tests.php`.
 *
 * Exercises the Verifier decision matrix + request cache and the Settings
 * sanitization. Exits non-zero if any assertion fails.
 *
 * @package CWebTS
 */

require __DIR__ . '/bootstrap.php';

use CWebTS\Verifier;
use CWebTS\Settings;
use CWebTS\Widget_Renderer;
use CWebTS\Integrations\Elementor_All_Forms;
use CWebTS\Integrations\WP_Comments;
use CWebTS\Integrations\WC_Checkout;
use CWebTS\Integrations\WC_Login;
use CWebTS\Integrations\WC_Register;
use CWebTS\Integrations\WC_Account;

$tests  = 0;
$failed = 0;

/**
 * Assert helper.
 *
 * @param string $name Test name.
 * @param bool   $cond Condition.
 * @return void
 */
function t( $name, $cond ) {
	global $tests, $failed;
	$tests++;
	if ( $cond ) {
		echo "  PASS  $name\n";
	} else {
		$failed++;
		echo "  FAIL  $name\n";
	}
}

/**
 * Build a Verifier with the current option state.
 *
 * @return Verifier
 */
function tf_verifier() {
	return new Verifier( new Settings() );
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

echo "Verifier — case 2 (local rejects)\n";
tf_reset();
t( 'empty token rejected, no HTTP call', false === tf_verifier()->verify( '' ) && 0 === $GLOBALS['__tf']['http']['calls'] );
tf_reset();
t( 'whitespace token rejected, no HTTP call', false === tf_verifier()->verify( "   \n" ) && 0 === $GLOBALS['__tf']['http']['calls'] );
tf_reset();
t( 'oversized token rejected, no HTTP call', false === tf_verifier()->verify( str_repeat( 'a', 2049 ) ) && 0 === $GLOBALS['__tf']['http']['calls'] );
tf_reset();
t( 'token at 2048 limit makes the HTTP call', tf_verifier()->verify( str_repeat( 'a', 2048 ) ) && 1 === $GLOBALS['__tf']['http']['calls'] );

echo "Verifier — missing secret\n";
tf_reset( array( 'secret_key' => '' ) );
t( 'no secret rejects without HTTP call', false === tf_verifier()->verify( 'tok' ) && 0 === $GLOBALS['__tf']['http']['calls'] );

echo "Verifier — case 3 (Cloudflare verdict)\n";
tf_reset();
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
t( 'success:true -> pass', true === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body'] = '{"success":false,"error-codes":["invalid-input-response"]}';
t( 'success:false -> fail', false === tf_verifier()->verify( 'tok' ) );

echo "Verifier — case 4 (transport errors + failure_mode)\n";
tf_reset();
$GLOBALS['__tf']['http']['wp_error'] = true;
t( 'WP_Error + block -> fail', false === tf_verifier()->verify( 'tok' ) );
tf_reset( array( 'failure_mode' => 'allow' ) );
$GLOBALS['__tf']['http']['wp_error'] = true;
t( 'WP_Error + allow -> pass', true === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body'] = '';
t( 'empty body + block -> fail', false === tf_verifier()->verify( 'tok' ) );
tf_reset( array( 'failure_mode' => 'allow' ) );
$GLOBALS['__tf']['http']['body'] = '';
t( 'empty body + allow -> pass', true === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body'] = 'not-json';
t( 'invalid JSON + block -> fail', false === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body'] = '{"foo":"bar"}';
t( 'JSON without success key -> transport error -> fail (block)', false === tf_verifier()->verify( 'tok' ) );

echo "Verifier — verdict honoured regardless of HTTP status\n";
tf_reset( array( 'failure_mode' => 'allow' ) );
$GLOBALS['__tf']['http']['code'] = 403;
$GLOBALS['__tf']['http']['body'] = '{"success":false,"error-codes":["invalid-input-response"]}';
t( 'non-200 + success:false JSON rejects even in allow mode', false === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['code'] = 403;
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
t( 'non-200 + success:true JSON is honoured -> pass', true === tf_verifier()->verify( 'tok' ) );

echo "Verifier — per-request cache (single-use token safety)\n";
tf_reset();
$v = tf_verifier();
$v->verify( 'same-token' );
$v->verify( 'same-token' );
t( 'same token verified twice -> 1 HTTP call', 1 === $GLOBALS['__tf']['http']['calls'] );
tf_reset();
$v = tf_verifier();
$v->verify( 'token-a' );
$v->verify( 'token-b' );
t( 'different tokens -> 2 HTTP calls', 2 === $GLOBALS['__tf']['http']['calls'] );

echo "Verifier — remoteip handling\n";
tf_reset();
tf_verifier()->verify( 'tok' );
t( 'valid REMOTE_ADDR is sent', isset( $GLOBALS['__tf']['http']['last']['body']['remoteip'] ) && '203.0.113.7' === $GLOBALS['__tf']['http']['last']['body']['remoteip'] );
tf_reset();
$GLOBALS['__tf']['filters']['cwebts_remoteip'] = 'not-an-ip';
tf_verifier()->verify( 'tok' );
t( 'invalid remoteip is omitted', ! isset( $GLOBALS['__tf']['http']['last']['body']['remoteip'] ) );
tf_reset();
$GLOBALS['__tf']['filters']['cwebts_remoteip'] = '';
tf_verifier()->verify( 'tok' );
t( 'empty remoteip filter omits it', ! isset( $GLOBALS['__tf']['http']['last']['body']['remoteip'] ) );

echo "Verifier — optional action check (off by default)\n";
tf_reset();
$GLOBALS['__tf']['http']['body'] = '{"success":true,"action":"elementor_form"}';
t( 'action mismatch ignored when filter off', true === tf_verifier()->verify( 'tok', null, 'wp_login' ) );
tf_reset();
$GLOBALS['__tf']['http']['body']                          = '{"success":true,"action":"elementor_form"}';
$GLOBALS['__tf']['filters']['cwebts_verify_action'] = true;
t( 'action match passes when filter on', true === tf_verifier()->verify( 'tok', null, 'elementor_form' ) );
tf_reset();
$GLOBALS['__tf']['http']['body']                          = '{"success":true,"action":"elementor_form"}';
$GLOBALS['__tf']['filters']['cwebts_verify_action'] = true;
t( 'action mismatch fails when filter on', false === tf_verifier()->verify( 'tok', null, 'wp_login' ) );

echo "Verifier — optional hostname check (off by default)\n";
tf_reset();
$GLOBALS['__tf']['http']['body'] = '{"success":true,"hostname":"evil.com"}';
t( 'hostname ignored when filter off', true === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body']                            = '{"success":true,"hostname":"example.com"}';
$GLOBALS['__tf']['filters']['cwebts_verify_hostname'] = true;
t( 'hostname allowed passes when filter on', true === tf_verifier()->verify( 'tok' ) );
tf_reset();
$GLOBALS['__tf']['http']['body']                            = '{"success":true,"hostname":"evil.com"}';
$GLOBALS['__tf']['filters']['cwebts_verify_hostname'] = true;
t( 'hostname not allowed fails when filter on', false === tf_verifier()->verify( 'tok' ) );

echo "Settings — sanitize()\n";
tf_reset();
$s     = new Settings();
$clean = $s->sanitize(
	array(
		'site_key'   => '  abc  ',
		'theme'      => 'rainbow',
		'size'       => 'compact',
		'appearance' => 'interaction-only',
		'language'   => 'fr',
	)
);
t( 'invalid theme falls back to default', 'auto' === $clean['theme'] );
t( 'valid size kept', 'compact' === $clean['size'] );
t( 'valid appearance kept', 'interaction-only' === $clean['appearance'] );
t( 'site_key trimmed', 'abc' === $clean['site_key'] );

// Secret write-only behaviour.
tf_reset( array( 'secret_key' => 'OLD' ) );
$s     = new Settings();
$clean = $s->sanitize( array( 'secret_key' => '' ) );
t( 'empty secret keeps existing', 'OLD' === $clean['secret_key'] );
tf_reset( array( 'secret_key' => 'OLD' ) );
$s     = new Settings();
$clean = $s->sanitize( array( 'secret_key' => 'NEW' ) );
t( 'provided secret replaces existing', 'NEW' === $clean['secret_key'] );
tf_reset( array( 'secret_key' => 'OLD' ) );
$s     = new Settings();
$clean = $s->sanitize(
	array(
		'secret_key'    => '',
		'remove_secret' => '1',
	)
);
t( 'remove_secret clears the key', '' === $clean['secret_key'] );

tf_reset();
$s     = new Settings();
$clean = $s->sanitize( array( 'protect_login' => '1' ) );
t( 'checked toggle -> 1', 1 === $clean['protect_login'] );
t( 'unchecked toggle -> 0', 0 === $clean['protect_comments'] );
$clean = $s->sanitize( array( 'protect_elementor_all_forms' => '1' ) );
t( 'elementor all-forms toggle checked -> 1', 1 === $clean['protect_elementor_all_forms'] );
$clean = $s->sanitize( array() );
t( 'elementor all-forms default -> 0', 0 === $clean['protect_elementor_all_forms'] );
$clean = $s->sanitize( array( 'error_message' => '' ) );
t( 'empty error_message is stored empty', '' === $clean['error_message'] );

echo "Settings — no translation on the plugins_loaded path (WP 6.7+)\n";
tf_reset();
$s = new Settings();
t( 'defaults() carries no translated string', '' === $s->defaults()['error_message'] );
t( 'an empty message still displays the localized default', 'Please confirm you are not a robot.' === $s->get_error_message() );

tf_reset();
$s = new Settings();
$v = new Verifier( $s );
$r = new Widget_Renderer( $s );
$GLOBALS['__tf']['i18n_calls'] = 0;
new WP_Comments( $s, $v, $r );
new WC_Checkout( $s, $v, $r );
new Elementor_All_Forms( $s, $v, $r );
$s->is_configured();
t( 'building the integrations asks for no translation', 0 === $GLOBALS['__tf']['i18n_calls'] );

echo "Settings — Elementor cached markup is dropped when the all-forms toggle changes\n";
tf_reset();
$s = new Settings();
$s->init();
$h = tf_hook( 'update_option_cwebts_settings' );
t( 'settings watch their own option update (action, 2 args)', $h && 'action' === $h['kind'] && 2 === $h['args'] );
t( 'settings also watch the first save (add_option)', null !== tf_hook( 'add_option_cwebts_settings' ) );

$GLOBALS['__tf']['purged'] = array();
$s->flush_elementor_cache_on_toggle( array( 'protect_elementor_all_forms' => 0 ), array( 'protect_elementor_all_forms' => 1 ) );
t( 'turning the toggle on drops Elementor cached markup', array( '_elementor_element_cache' ) === $GLOBALS['__tf']['purged'] );

$GLOBALS['__tf']['purged'] = array();
$s->flush_elementor_cache_on_toggle( array( 'protect_elementor_all_forms' => 1 ), array( 'protect_elementor_all_forms' => 0 ) );
t( 'turning it off drops the markup too', array( '_elementor_element_cache' ) === $GLOBALS['__tf']['purged'] );

$GLOBALS['__tf']['purged'] = array();
$s->flush_elementor_cache_on_toggle( array( 'protect_elementor_all_forms' => 1, 'theme' => 'auto' ), array( 'protect_elementor_all_forms' => 1, 'theme' => 'dark' ) );
t( 'saving other settings leaves the cache alone', array() === $GLOBALS['__tf']['purged'] );

$GLOBALS['__tf']['purged'] = array();
$s->flush_elementor_cache_on_add( 'cwebts_settings', array( 'protect_elementor_all_forms' => 1 ) );
t( 'a first save with the toggle already on drops the markup', array( '_elementor_element_cache' ) === $GLOBALS['__tf']['purged'] );

echo "Settings — import from Simple Cloudflare Turnstile\n";
tf_reset();
$s = new Settings();
t( 'no source detected initially', false === $s->has_import_source() );

tf_reset();
$GLOBALS['__tf']['options']['cfturnstile_key']           = 'ELLIOT_SITE';
$GLOBALS['__tf']['options']['cfturnstile_secret']        = 'ELLIOT_SECRET';
$GLOBALS['__tf']['options']['cfturnstile_theme']         = 'dark';
$GLOBALS['__tf']['options']['cfturnstile_appearance']    = 'execute'; // unsupported -> default.
$GLOBALS['__tf']['options']['cfturnstile_login']         = '1';
$GLOBALS['__tf']['options']['cfturnstile_comment']       = '';
$GLOBALS['__tf']['options']['cfturnstile_reset']         = '1';
$GLOBALS['__tf']['options']['cfturnstile_failover']      = '1';
$GLOBALS['__tf']['options']['cfturnstile_failsafe_type'] = 'pass';
$s     = new Settings();
t( 'source detected when Elliot keys exist', true === $s->has_import_source() );
$count = $s->import_from_simple_turnstile();
$saved = get_option( 'cwebts_settings' );
t( 'import applied several values', $count >= 6 );
t( 'site key imported', 'ELLIOT_SITE' === $saved['site_key'] );
t( 'secret key imported (no regeneration)', 'ELLIOT_SECRET' === $saved['secret_key'] );
t( 'valid theme imported', 'dark' === $saved['theme'] );
t( 'unsupported appearance falls back to default', 'always' === $saved['appearance'] );
t( 'login toggle imported as on', 1 === $saved['protect_login'] );
t( 'empty comment toggle imported as off', 0 === $saved['protect_comments'] );
t( 'lost password (Elliot "reset") imported as on', 1 === $saved['protect_lostpassword'] );
t( 'failover pass maps to allow', 'allow' === $saved['failure_mode'] );

tf_reset();
$GLOBALS['__tf']['options']['cfturnstile_secret']        = 'ONLY_SECRET';
$GLOBALS['__tf']['options']['cfturnstile_failover']      = '1';
$GLOBALS['__tf']['options']['cfturnstile_failsafe_type'] = 'block';
$s = new Settings();
$s->import_from_simple_turnstile();
$saved = get_option( 'cwebts_settings' );
t( 'existing site key preserved when source omits it', 'site' === $saved['site_key'] );
t( 'failover block maps to block', 'block' === $saved['failure_mode'] );

tf_reset();
$GLOBALS['__tf']['options']['cfturnstile_failover']      = '1';
$GLOBALS['__tf']['options']['cfturnstile_failsafe_type'] = '';
$GLOBALS['__tf']['options']['cfturnstile_language']      = 'fr-FR';
$s = new Settings();
$s->import_from_simple_turnstile();
$saved = get_option( 'cwebts_settings' );
t( 'empty failsafe type maps to allow (Elliot semantics)', 'allow' === $saved['failure_mode'] );
t( 'regional language fr-FR normalised to fr', 'fr' === $saved['language'] );

tf_reset();
$GLOBALS['__tf']['options']['cfturnstile_language'] = 'xx-YY';
$s = new Settings();
$s->import_from_simple_turnstile();
$saved = get_option( 'cwebts_settings' );
t( 'unknown language falls back to auto', 'auto' === $saved['language'] );

echo "Elementor all-forms — inject_widget_before_submit()\n";
$widget = '<div class="elementor-field-group elementor-column elementor-col-100 cwebts-elementor-auto-field"><div class="cf-turnstile cwebts-widget" data-sitekey="site"></div></div>';

$form = '<form class="elementor-form"><div class="elementor-form-fields-wrapper">'
	. '<div class="elementor-field-group elementor-column elementor-field-type-email"><input></div>'
	. '<div class="elementor-field-group elementor-column elementor-field-type-submit"><button type="submit">Send</button></div>'
	. '</div></form>';
$out = Elementor_All_Forms::inject_widget_before_submit( $form, $widget );
t( 'widget injected', false !== strpos( $out, 'cwebts-elementor-auto-field' ) );
t( 'widget placed before the submit group', strpos( $out, 'cwebts-elementor-auto-field' ) < strpos( $out, 'elementor-field-type-submit' ) );
t( 'widget stays inside the form', strpos( $out, 'cwebts-elementor-auto-field' ) < strpos( $out, '</form>' ) );

$no_submit = '<form class="elementor-form"><div class="elementor-field-group elementor-field-type-text">x</div></form>';
$out       = Elementor_All_Forms::inject_widget_before_submit( $no_submit, $widget );
t( 'fallback injects the widget', false !== strpos( $out, 'cwebts-elementor-auto-field' ) );
t( 'fallback keeps widget before </form>', strpos( $out, 'cwebts-elementor-auto-field' ) < strpos( $out, '</form>' ) );

$no_form = '<div>nothing to protect here</div>';
t( 'no form -> content unchanged', Elementor_All_Forms::inject_widget_before_submit( $no_form, $widget ) === $no_form );

$already = '<form class="elementor-form"><div class="cf-turnstile cwebts-widget"></div><div class="elementor-field-type-submit"><button>Send</button></div></form>';
t( 'existing cwebts-widget not duplicated', Elementor_All_Forms::inject_widget_before_submit( $already, $widget ) === $already );

// A stray submit marker OUTSIDE the form must not pull the widget out of it.
$stray = '<div class="elementor-field-type-submit">decoy</div>'
	. '<form class="elementor-form"><div class="elementor-field-group elementor-field-type-text">x</div></form>';
$out = Elementor_All_Forms::inject_widget_before_submit( $stray, $widget );
t( 'stray submit before form ignored (widget sits after <form)', strpos( $out, 'cwebts-elementor-auto-field' ) > strpos( $out, '<form' ) );
t( 'widget still lands before </form> despite stray submit', strpos( $out, 'cwebts-elementor-auto-field' ) < strpos( $out, '</form>' ) );

t( 'empty widget html -> content unchanged', Elementor_All_Forms::inject_widget_before_submit( $form, '' ) === $form );

echo "Elementor all-forms — record_has_turnstile_field()\n";
$rec_with    = new class() {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		return 'fields' === $key ? array( array( 'type' => 'text' ), array( 'type' => 'turnstile' ) ) : null;
	}
};
$rec_without = new class() {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		return 'fields' === $key ? array( array( 'type' => 'text' ), array( 'type' => 'email' ) ) : null;
	}
};
t( 'record with turnstile field detected', true === Elementor_All_Forms::record_has_turnstile_field( $rec_with ) );
t( 'record without turnstile field -> false', false === Elementor_All_Forms::record_has_turnstile_field( $rec_without ) );
t( 'non-object record -> false', false === Elementor_All_Forms::record_has_turnstile_field( null ) );

echo "WP_Comments — validate() bypass scoping\n";

/**
 * Build a WP_Comments integration against the current settings.
 *
 * @return WP_Comments
 */
function tf_comments() {
	$settings = new Settings();
	return new WP_Comments( $settings, new Verifier( $settings ), new Widget_Renderer( $settings ) );
}

/**
 * Run validate() and report whether it blocked (wp_die) the submission.
 *
 * @param WP_Comments $integration Integration.
 * @param array       $commentdata Comment data.
 * @return bool True when the submission was blocked.
 */
function tf_comment_blocked( $integration, $commentdata = array() ) {
	try {
		$integration->validate( $commentdata );
		return false;
	} catch ( \CWebTS_WPDie_Exception $e ) {
		return true;
	}
}

// Public comment, no token -> blocked (front-end protection intact).
tf_reset();
t( 'public comment without token is blocked', true === tf_comment_blocked( tf_comments() ) );

// Public comment with a valid token -> accepted.
tf_reset();
$_POST['cf-turnstile-response']  = 'tok';
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
t( 'public comment with valid token passes', false === tf_comment_blocked( tf_comments() ) );

// Pingbacks/trackbacks are never challenged.
tf_reset();
t( 'pingback is never blocked', false === tf_comment_blocked( tf_comments(), array( 'comment_type' => 'pingback' ) ) );

// Moderator reply via the replyto-comment AJAX action -> bypass.
tf_reset();
$GLOBALS['__tf']['doing_ajax']                = true;
$GLOBALS['__tf']['caps']['moderate_comments'] = true;
$_REQUEST['action']                           = 'replyto-comment';
t( 'moderator replyto-comment AJAX bypasses the check', false === tf_comment_blocked( tf_comments() ) );

// Same moderator, DIFFERENT AJAX action -> still checked (bypass is scoped).
tf_reset();
$GLOBALS['__tf']['doing_ajax']                = true;
$GLOBALS['__tf']['caps']['moderate_comments'] = true;
$_REQUEST['action']                           = 'some_other_action';
t( 'moderator on another AJAX action is still blocked', true === tf_comment_blocked( tf_comments() ) );

// replyto-comment action but WITHOUT the capability -> still checked.
tf_reset();
$GLOBALS['__tf']['doing_ajax'] = true;
$_REQUEST['action']            = 'replyto-comment';
t( 'replyto-comment without moderate_comments is still blocked', true === tf_comment_blocked( tf_comments() ) );

echo "WooCommerce — validate() decisions\n";

/**
 * Build a WooCommerce integration of the given class against current settings.
 *
 * @param string $class Fully-qualified integration class name.
 * @return \CWebTS\Integrations\Abstract_Integration
 */
function tf_wc_make( $class ) {
	$settings = new Settings();
	return new $class( $settings, new Verifier( $settings ), new Widget_Renderer( $settings ) );
}

// WC_Login (filter, returns WP_Error, raw message).
tf_reset();
$login = tf_wc_make( WC_Login::class );
$out   = $login->validate( new WP_Error() );
t( 'WC login without token returns a WP_Error', is_wp_error( $out ) && 'cwebts_failed' === $out->get_error_code() );
t( 'WC login message is raw (no "Error:" prefix)', false === strpos( $out->get_error_message(), 'Error:' ) );

tf_reset();
$_POST['cf-turnstile-response']  = 'tok';
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
$login     = tf_wc_make( WC_Login::class );
$untouched = new WP_Error();
$out       = $login->validate( $untouched );
t( 'WC login with valid token leaves validation unchanged', $out === $untouched && '' === $out->get_error_code() );

// WC_Register (filter, 4 args, returns WP_Error).
tf_reset();
$reg = tf_wc_make( WC_Register::class );
$out = $reg->validate( new WP_Error(), 'user', 'pass', 'a@b.c' );
t( 'WC register without token returns a WP_Error', is_wp_error( $out ) && 'cwebts_failed' === $out->get_error_code() );

tf_reset();
$_POST['cf-turnstile-response']  = 'tok';
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
$reg       = tf_wc_make( WC_Register::class );
$untouched = new WP_Error();
$out       = $reg->validate( $untouched, 'user', 'pass', 'a@b.c' );
t( 'WC register with valid token leaves validation unchanged', $out === $untouched && '' === $out->get_error_code() );

// WC_Account (action, 2 args, mutates the WP_Error).
tf_reset();
$acct   = tf_wc_make( WC_Account::class );
$errors = new WP_Error();
$acct->validate( $errors, null );
t( 'WC account without token adds an error', 'cwebts_failed' === $errors->get_error_code() );

tf_reset();
$_POST['cf-turnstile-response']  = 'tok';
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
$acct   = tf_wc_make( WC_Account::class );
$errors = new WP_Error();
$acct->validate( $errors, null );
t( 'WC account with valid token adds no error', '' === $errors->get_error_code() );

// WC_Checkout (action, wc_add_notice, raw message).
tf_reset();
$checkout = tf_wc_make( WC_Checkout::class );
$checkout->validate();
t( 'WC checkout without token adds one error notice', 1 === count( $GLOBALS['__tf']['wc_notices'] ) && 'error' === $GLOBALS['__tf']['wc_notices'][0]['type'] );
t( 'WC checkout notice is raw (no "Error:" prefix)', false === strpos( $GLOBALS['__tf']['wc_notices'][0]['message'], 'Error:' ) );

tf_reset();
$_POST['cf-turnstile-response']  = 'tok';
$GLOBALS['__tf']['http']['body'] = '{"success":true}';
$checkout = tf_wc_make( WC_Checkout::class );
$checkout->validate();
t( 'WC checkout with valid token adds no notice', 0 === count( $GLOBALS['__tf']['wc_notices'] ) );

echo "WooCommerce — context separation (cwebts_verify_action on)\n";
tf_reset();
$_POST['cf-turnstile-response']                     = 'tok';
$GLOBALS['__tf']['http']['body']                    = '{"success":true,"action":"wc_checkout"}';
$GLOBALS['__tf']['filters']['cwebts_verify_action'] = true;
$checkout = tf_wc_make( WC_Checkout::class );
$checkout->validate();
t( 'token issued for wc_checkout passes the checkout', 0 === count( $GLOBALS['__tf']['wc_notices'] ) );
$login = tf_wc_make( WC_Login::class );
$out   = $login->validate( new WP_Error() );
t( 'same token is rejected on the login (action mismatch)', is_wp_error( $out ) && 'cwebts_failed' === $out->get_error_code() );

echo "WooCommerce — hook registration (gating + scoping)\n";

/**
 * Whether the captured hook list contains a given tag.
 *
 * @param string $tag Hook tag.
 * @return bool
 */
function tf_has_hook( $tag ) {
	foreach ( $GLOBALS['__tf']['hooks'] as $hook ) {
		if ( $tag === $hook['tag'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Return the captured hook entry for a tag (kind + priority + args), or null.
 *
 * @param string $tag Hook tag.
 * @return array|null
 */
function tf_hook( $tag ) {
	foreach ( $GLOBALS['__tf']['hooks'] as $hook ) {
		if ( $tag === $hook['tag'] ) {
			return $hook;
		}
	}
	return null;
}

// Toggle OFF -> no hooks.
tf_reset();
tf_wc_make( WC_Checkout::class );
t( 'WC checkout disabled registers no hooks', 0 === count( $GLOBALS['__tf']['hooks'] ) );

// Toggle ON (keys configured) -> render outside the AJAX fragment + validation.
tf_reset( array( 'protect_wc_checkout' => 1 ) );
tf_wc_make( WC_Checkout::class );
$h = tf_hook( 'woocommerce_review_order_before_submit' );
t( 'WC checkout renders on review_order_before_submit (action, next to the button)', $h && 'action' === $h['kind'] );
t( 'WC checkout validates on checkout_process (action)', null !== ( $h = tf_hook( 'woocommerce_checkout_process' ) ) && 'action' === $h['kind'] );

tf_reset( array( 'protect_wc_login' => 1 ) );
tf_wc_make( WC_Login::class );
$h = tf_hook( 'woocommerce_process_login_errors' );
t( 'WC login validates on process_login_errors (filter, 3 args)', $h && 'filter' === $h['kind'] && 3 === $h['args'] );

// Register must use the scoped filter, NOT woocommerce_register_post (which also
// fires for account creation during checkout and programmatic customer creation).
tf_reset( array( 'protect_wc_register' => 1 ) );
tf_wc_make( WC_Register::class );
$h = tf_hook( 'woocommerce_process_registration_errors' );
t( 'WC register validates on scoped process_registration_errors (filter, 4 args)', $h && 'filter' === $h['kind'] && 4 === $h['args'] );
t( 'WC register does NOT hook woocommerce_register_post (no checkout false-block)', false === tf_has_hook( 'woocommerce_register_post' ) );

tf_reset( array( 'protect_wc_account' => 1 ) );
tf_wc_make( WC_Account::class );
$h = tf_hook( 'woocommerce_save_account_details_errors' );
t( 'WC account validates on save_account_details_errors (action, 2 args)', $h && 'action' === $h['kind'] && 2 === $h['args'] );

echo "\n";
echo "$tests run, " . ( $tests - $failed ) . " passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
