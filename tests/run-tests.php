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
$clean = $s->sanitize( array( 'error_message' => '' ) );
t( 'empty error_message -> default', '' !== $clean['error_message'] );

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

echo "\n";
echo "$tests run, " . ( $tests - $failed ) . " passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
