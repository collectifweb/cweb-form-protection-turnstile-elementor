<?php
/**
 * WooCommerce edit-account form integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Account
 *
 * Protects the "My account > Account details" edit form.
 */
class WC_Account extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_wc_account';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wc_account';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_widget' ) );
		// Passed via do_action_ref_array( array( &$errors, &$user ) ): mutate the
		// WP_Error, no return value.
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate' ), 10, 2 );
	}

	/**
	 * Add an account-update error when the challenge fails.
	 *
	 * @param \WP_Error $errors Errors collector.
	 * @param mixed     $user   Current user (unused).
	 * @return void
	 */
	public function validate( $errors, $user = null ) {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}

		// Raw message: WooCommerce renders these WP_Error messages as notices.
		if ( ! $this->passes() ) {
			$errors->add( 'cwebts_failed', $this->settings->get_error_message() );
		}
	}
}
