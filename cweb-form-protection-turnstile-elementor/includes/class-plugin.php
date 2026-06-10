<?php
/**
 * Main plugin orchestrator.
 *
 * Wires WordPress hooks and instantiates the (testable) service objects. No
 * heavy global singleton: services are plain instances passed where needed.
 *
 * @package CWebTS
 */

namespace CWebTS;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Verifier service.
	 *
	 * @var Verifier
	 */
	private $verifier;

	/**
	 * Widget renderer service.
	 *
	 * @var Widget_Renderer
	 */
	private $renderer;

	/**
	 * Build the service graph.
	 */
	public function __construct() {
		$this->settings = new Settings();
		$this->verifier = new Verifier( $this->settings );
		$this->renderer = new Widget_Renderer( $this->settings );
	}

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Translations for WordPress.org-hosted plugins are loaded automatically
		// since WP 4.6, so load_plugin_textdomain() is intentionally not called.
		$this->settings->init();
		$this->renderer->init();

		add_filter(
			'plugin_action_links_' . CWEBTS_BASENAME,
			array( $this, 'add_settings_link' )
		);

		$this->register_elementor_field();
		$this->register_elementor_integrations();
		$this->register_native_integrations();
	}

	/**
	 * Register the per-form Turnstile field with Elementor Pro Forms.
	 *
	 * The callback only runs when the Forms module is loaded, so Field_Base is
	 * guaranteed to exist before we reference it.
	 *
	 * @return void
	 */
	private function register_elementor_field() {
		$settings = $this->settings;
		$verifier = $this->verifier;
		$renderer = $this->renderer;

		add_action(
			'elementor_pro/forms/fields/register',
			static function ( $registrar ) use ( $settings, $verifier, $renderer ) {
				require_once CWEBTS_DIR . 'includes/elementor/class-turnstile-field.php';
				$registrar->register( new Elementor\Turnstile_Field( $settings, $verifier, $renderer ) );
			}
		);
	}

	/**
	 * Instantiate the optional "protect all Elementor Pro forms" integration.
	 *
	 * Self-registers its hooks only when the toggle is on and keys are configured;
	 * inert (and safe) when Elementor Pro is absent.
	 *
	 * @return void
	 */
	private function register_elementor_integrations() {
		new Integrations\Elementor_All_Forms( $this->settings, $this->verifier, $this->renderer );
	}

	/**
	 * Instantiate the native WordPress form integrations.
	 *
	 * Each integration self-registers its hooks and short-circuits unless its
	 * toggle is enabled and keys are configured.
	 *
	 * @return void
	 */
	private function register_native_integrations() {
		new Integrations\WP_Login( $this->settings, $this->verifier, $this->renderer );
		new Integrations\WP_Register( $this->settings, $this->verifier, $this->renderer );
		new Integrations\WP_Lost_Password( $this->settings, $this->verifier, $this->renderer );
		new Integrations\WP_Comments( $this->settings, $this->verifier, $this->renderer );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url  = admin_url( 'options-general.php?page=cwebts' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cweb-form-protection-turnstile-elementor' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}
}
