<?php
/**
 * WordPress lost password form integration.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_Lost_Password
 */
class WP_Lost_Password extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_lostpassword';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wp_lostpassword';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'lostpassword_form', array( $this, 'render_widget' ) );
		add_action( 'lostpassword_post', array( $this, 'validate' ) );
	}

	/**
	 * Add a lost-password error when the challenge fails.
	 *
	 * @param \WP_Error $errors Errors collector (WP 5.4+).
	 * @return void
	 */
	public function validate( $errors ) {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}

		if ( ! $this->passes() ) {
			$errors->add( 'turnstile_forms_failed', '<strong>' . esc_html__( 'Error:', 'captcha-field-for-turnstile' ) . '</strong> ' . esc_html( $this->settings->get_error_message() ) );
		}
	}
}
