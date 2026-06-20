<?php
/**
 * Plugin Name:       CWeb Form Protection with Turnstile for Elementor Forms
 * Plugin URI:        https://github.com/collectifweb/cweb-form-protection-turnstile-elementor
 * Description:       Cloudflare Turnstile for your forms, with a per-form field for Elementor Pro so you choose exactly which forms are protected.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            Collectif WEB
 * Author URI:        https://collectif-web.ca
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cweb-form-protection-turnstile-elementor
 * Domain Path:       /languages
 *
 * Not affiliated with Cloudflare, Inc. or Elementor Ltd. "Cloudflare" and
 * "Turnstile" are trademarks of Cloudflare, Inc. "Elementor" is a trademark of
 * Elementor Ltd. They are used here only to describe compatibility.
 *
 * @package CWebTS
 */

defined( 'ABSPATH' ) || exit;

define( 'CWEBTS_VERSION', '1.2.0' );
define( 'CWEBTS_FILE', __FILE__ );
define( 'CWEBTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CWEBTS_URL', plugin_dir_url( __FILE__ ) );
define( 'CWEBTS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader scoped to the CWebTS namespace.
 *
 * Maps CWebTS\Sub\Some_Class to includes/sub/class-some-class.php.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'CWebTS\\';

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

		$file = CWEBTS_DIR . 'includes/' . $subpath . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

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
function cwebts_bootstrap() {
	$plugin = new \CWebTS\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'cwebts_bootstrap' );
