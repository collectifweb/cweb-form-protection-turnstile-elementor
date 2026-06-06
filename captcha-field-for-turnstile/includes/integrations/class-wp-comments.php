<?php
/**
 * WordPress comment form integration.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_Comments
 */
class WP_Comments extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_comments';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wp_comment';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		// Logged-out visitors see the author/email fields; logged-in users do not.
		add_action( 'comment_form_after_fields', array( $this, 'render_widget' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'render_widget' ) );
		add_filter( 'preprocess_comment', array( $this, 'validate' ) );
	}

	/**
	 * Validate the comment submission.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public function validate( $commentdata ) {
		// Never block pingbacks/trackbacks (no browser, no token).
		$type = isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '';
		if ( in_array( $type, array( 'pingback', 'trackback' ), true ) ) {
			return $commentdata;
		}

		if ( ! $this->passes() ) {
			wp_die(
				esc_html( $this->settings->get_error_message() ),
				esc_html__( 'Comment Submission Failure', 'captcha-field-for-turnstile' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $commentdata;
	}
}
