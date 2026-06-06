<?php
/**
 * WordPress registration form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_Register
 */
class WP_Register extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_register';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wp_register';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'register_form', array( $this, 'render_widget' ) );
		add_filter( 'registration_errors', array( $this, 'validate' ), 10, 1 );
	}

	/**
	 * Add a registration error when the challenge fails.
	 *
	 * @param \WP_Error $errors Registration errors.
	 * @return \WP_Error
	 */
	public function validate( $errors ) {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return $errors;
		}

		if ( ! $this->passes() ) {
			$errors->add( 'cwebts_failed', '<strong>' . esc_html__( 'Error:', 'cweb-turnstile-for-elementor-forms' ) . '</strong> ' . esc_html( $this->settings->get_error_message() ) );
		}

		return $errors;
	}
}
