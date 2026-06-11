<?php
/**
 * WordPress comment form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

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

		// Replies posted from the dashboard or admin bar go through the
		// replyto-comment AJAX action: WordPress builds the comment server-side
		// without ever rendering a Turnstile widget, so no token is sent. Yet
		// wp_new_comment() still runs preprocess_comment, so without this bypass
		// every moderator reply is rejected with the challenge error. The skip is
		// scoped as tightly as the bug: the replyto-comment AJAX action performed
		// by a user who can moderate comments (the core handler has already checked
		// its own nonce by the time we run). The public comment form, which does
		// render a widget, is unaffected.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Action read only to scope the bypass; the core replyto-comment handler verifies the nonce.
		$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( wp_doing_ajax() && 'replyto-comment' === $ajax_action && current_user_can( 'moderate_comments' ) ) {
			return $commentdata;
		}

		if ( ! $this->passes() ) {
			wp_die(
				esc_html( $this->settings->get_error_message() ),
				esc_html__( 'Comment Submission Failure', 'cweb-form-protection-turnstile-elementor' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $commentdata;
	}
}
