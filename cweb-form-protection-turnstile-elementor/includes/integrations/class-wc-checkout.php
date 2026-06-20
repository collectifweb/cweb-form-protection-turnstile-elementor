<?php
/**
 * WooCommerce classic (shortcode) checkout integration.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Checkout
 *
 * Protects the classic shortcode checkout. The Checkout Block
 * (woocommerce/checkout) is not covered: it does not fire these hooks.
 */
class WC_Checkout extends Abstract_Integration {

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_wc_checkout';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return 'wc_checkout';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function register() {
		// Render right before the "Place order" button (inside #payment), where
		// shoppers expect the challenge — not at the top of the order summary.
		// #payment is replaced by update_order_review on every recalculation, so
		// the front-end helper re-renders the widget immediately on the
		// updated_checkout event (and the MutationObserver is a backstop). The
		// hidden cf-turnstile-response field is serialised with the checkout POST.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_widget' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
	}

	/**
	 * Reject the checkout when the challenge fails.
	 *
	 * Runs on woocommerce_checkout_process, before the order is created. A
	 * notice stops the checkout through WooCommerce's error-notice counter.
	 *
	 * @return void
	 */
	public function validate() {
		// WooCommerce does not prefix notices added here, so we add the raw
		// configured message (no "Error:" prefix) for consistency with WC.
		// woocommerce_checkout_process only fires inside WooCommerce's own checkout
		// processing, so wc_add_notice() is always loaded here; no guard needed
		// (a guard would only create a fail-open path that never triggers).
		if ( ! $this->passes() ) {
			wc_add_notice( $this->settings->get_error_message(), 'error' );
		}
	}
}
