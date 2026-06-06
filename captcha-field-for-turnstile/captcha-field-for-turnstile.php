<?php
/**
 * Plugin Name:       Captcha Field for Turnstile — Elementor & WordPress Forms
 * Plugin URI:        https://github.com/collectifweb/captcha-field-for-turnstile
 * Description:       Cloudflare Turnstile for your forms, with a per-form field for Elementor Pro so you choose exactly which forms are protected.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Collectif Web
 * Author URI:        https://collectifweb.ca
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       captcha-field-for-turnstile
 * Domain Path:       /languages
 *
 * Not affiliated with Cloudflare, Inc. or Elementor Ltd. "Cloudflare" and
 * "Turnstile" are trademarks of Cloudflare, Inc. "Elementor" is a trademark of
 * Elementor Ltd. They are used here only to describe compatibility.
 *
 * @package TurnstileForms
 */

defined( 'ABSPATH' ) || exit;

define( 'TURNSTILE_FORMS_VERSION', '1.0.0' );
define( 'TURNSTILE_FORMS_FILE', __FILE__ );
define( 'TURNSTILE_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TURNSTILE_FORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'TURNSTILE_FORMS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader scoped to the TurnstileForms namespace.
 *
 * Maps TurnstileForms\Sub\Some_Class to includes/sub/class-some-class.php.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'TurnstileForms\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$subpath  = '';

		if ( ! empty( $parts ) ) {
			$subpath = strtolower( implode( '/', $parts ) ) . '/';
		}

		$file = TURNSTILE_FORMS_DIR . 'includes/' . $subpath . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded (so Elementor Pro detection works).
 *
 * @return void
 */
function turnstile_forms_bootstrap() {
	$plugin = new \TurnstileForms\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'turnstile_forms_bootstrap' );
