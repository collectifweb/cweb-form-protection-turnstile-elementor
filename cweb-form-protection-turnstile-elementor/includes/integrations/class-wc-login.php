<?php
/**
 * WooCommerce login form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Login
 *
 * Covers the WooCommerce "My account" login and the checkout "returning
 * customer" login (both render through woocommerce_login_form).
 */
class WC_Login extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_wc_login';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wc_login';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'woocommerce_login_form', array( $this, 'render_widget' ) );
		// Scoped to the WooCommerce login POST (after its own nonce, before
		// wp_signon). WP_Login::authenticate stays inert here because the WC
		// form posts username/password, not log/pwd.
		add_filter( 'woocommerce_process_login_errors', array( $this, 'validate' ), 10, 3 );
	}

	/**
	 * Add a login error when the challenge fails.
	 *
	 * @param \WP_Error|mixed $validation_error Current validation error.
	 * @param string          $username         Submitted username (unused).
	 * @param string          $password         Submitted password (unused).
	 * @return \WP_Error|mixed
	 */
	public function validate( $validation_error, $username = '', $password = '' ) {
		if ( $this->passes() ) {
			return $validation_error;
		}

		if ( ! ( $validation_error instanceof \WP_Error ) ) {
			$validation_error = new \WP_Error();
		}

		// Raw message: WooCommerce already prefixes login errors with "Error:".
		$validation_error->add( 'cwebts_failed', $this->settings->get_error_message() );

		return $validation_error;
	}
}
