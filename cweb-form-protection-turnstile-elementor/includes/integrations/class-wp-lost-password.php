<?php
/**
 * WordPress lost password form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

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
		// WooCommerce swaps the core form for its own and fires its own display
		// hook, but reuses lostpassword_post for validation. Without this the
		// widget never renders on /my-account/lost-password/ while validate()
		// still runs and rejects every reset.
		add_action( 'woocommerce_lostpassword_form', array( $this, 'render_widget' ) );
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
			$errors->add( 'cwebts_failed', '<strong>' . esc_html__( 'Error:', 'cweb-form-protection-turnstile-elementor' ) . '</strong> ' . esc_html( $this->settings->get_error_message() ) );
		}
	}
}
