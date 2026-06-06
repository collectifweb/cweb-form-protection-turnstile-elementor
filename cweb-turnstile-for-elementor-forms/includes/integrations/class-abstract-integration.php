<?php
/**
 * Base class for native WordPress form integrations.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

use CWebTS\Settings;
use CWebTS\Verifier;
use CWebTS\Widget_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abstract_Integration
 */
abstract class Abstract_Integration {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Verifier service.
	 *
	 * @var Verifier
	 */
	protected $verifier;

	/**
	 * Widget renderer service.
	 *
	 * @var Widget_Renderer
	 */
	protected $renderer;

	/**
	 * Constructor. Registers hooks only when enabled and configured.
	 *
	 * @param Settings        $settings Settings service.
	 * @param Verifier        $verifier Verifier service.
	 * @param Widget_Renderer $renderer Renderer service.
	 */
	public function __construct( Settings $settings, Verifier $verifier, Widget_Renderer $renderer ) {
		$this->settings = $settings;
		$this->verifier = $verifier;
		$this->renderer = $renderer;

		if ( $this->is_enabled() ) {
			$this->register();
		}
	}

	/**
	 * Settings toggle key for this integration.
	 *
	 * @return string
	 */
	abstract protected function toggle();

	/**
	 * Cloudflare action name for this context.
	 *
	 * @return string
	 */
	abstract protected function action();

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	abstract protected function register();

	/**
	 * Whether this integration should run.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return 1 === (int) $this->settings->get( $this->toggle() ) && $this->settings->is_configured();
	}

	/**
	 * Enqueue assets and echo the widget.
	 *
	 * @return void
	 */
	public function render_widget() {
		$this->renderer->enqueue();
		$this->renderer->render( array( 'action' => $this->action() ) );
	}

	/**
	 * Read and sanitize the submitted token.
	 *
	 * @return string
	 */
	protected function get_token() {
		// Each native form carries its own nonce; the captcha token is a separate
		// field that Cloudflare itself verifies, so nonce verification is N/A here.
		if ( ! isset( $_POST['cf-turnstile-response'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) );
	}

	/**
	 * Verify the current request token for this context.
	 *
	 * @return bool
	 */
	protected function passes() {
		return $this->verifier->verify( $this->get_token(), null, $this->action() );
	}

	/**
	 * Whether the current request is a POST.
	 *
	 * @return bool
	 */
	protected function is_post() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}
}
