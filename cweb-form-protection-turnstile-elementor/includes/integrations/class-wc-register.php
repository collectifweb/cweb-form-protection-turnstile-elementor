<?php
/**
 * WooCommerce registration form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Register
 *
 * Validates on woocommerce_process_registration_errors (the "My account"
 * register POST only), NOT woocommerce_register_post: the latter also fires
 * for account creation during checkout and for programmatic customer creation,
 * which would block flows that never rendered this widget.
 */
class WC_Register extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_wc_register';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wc_register';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'woocommerce_register_form', array( $this, 'render_widget' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate' ), 10, 4 );
	}

	/**
	 * Add a registration error when the challenge fails.
	 *
	 * @param \WP_Error|mixed $validation_error Current validation error.
	 * @param string          $username         Submitted username (unused).
	 * @param string          $password         Submitted password (unused).
	 * @param string          $email            Submitted email (unused).
	 * @return \WP_Error|mixed
	 */
	public function validate( $validation_error, $username = '', $password = '', $email = '' ) {
		if ( $this->passes() ) {
			return $validation_error;
		}

		if ( ! ( $validation_error instanceof \WP_Error ) ) {
			$validation_error = new \WP_Error();
		}

		// Raw message: WooCommerce already prefixes the registration error.
		$validation_error->add( 'cwebts_failed', $this->settings->get_error_message() );

		return $validation_error;
	}
}
