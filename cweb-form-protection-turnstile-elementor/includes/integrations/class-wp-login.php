<?php
/**
 * WordPress login form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

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

		// Only enforce on actual login attempts. The WordPress login POST carries
		// no nonce (core adds none), so nonce verification is not applicable here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['log'] ) && empty( $_POST['pwd'] ) ) {
			return $user;
		}

		if ( ! $this->passes() ) {
			return new \WP_Error(
				'cwebts_failed',
				'<strong>' . esc_html__( 'Error:', 'cweb-form-protection-turnstile-elementor' ) . '</strong> ' . esc_html( $this->settings->get_error_message() )
			);
		}

		return $user;
	}
}
