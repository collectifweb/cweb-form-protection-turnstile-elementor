<?php
/**
 * WordPress login form integration.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_Login
 */
class WP_Login extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_login';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wp_login';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'login_form', array( $this, 'render_widget' ) );
		add_filter( 'authenticate', array( $this, 'authenticate' ), 30 );
	}

	/**
	 * Verify Turnstile during authentication.
	 *
	 * @param \WP_User|\WP_Error|null $user Current auth result.
	 * @return \WP_User|\WP_Error|null
	 */
	public function authenticate( $user ) {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $user;
		}

		if ( ! $this->is_post() ) {
			return $user;
		}

		// Only enforce on actual login attempts. phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['log'] ) && empty( $_POST['pwd'] ) ) {
			return $user;
		}

		if ( ! $this->passes() ) {
			return new \WP_Error(
				'turnstile_forms_failed',
				'<strong>' . esc_html__( 'Error:', 'captcha-field-for-turnstile' ) . '</strong> ' . esc_html( $this->settings->get_error_message() )
			);
		}

		return $user;
	}
}
