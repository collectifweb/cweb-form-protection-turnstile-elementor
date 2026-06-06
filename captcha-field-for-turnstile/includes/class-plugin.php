<?php
/**
 * Main plugin orchestrator.
 *
 * Wires WordPress hooks and instantiates the (testable) service objects. No
 * heavy global singleton: services are plain instances passed where needed.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms;

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
		load_plugin_textdomain( 'captcha-field-for-turnstile', false, dirname( TURNSTILE_FORMS_BASENAME ) . '/languages' );

		$this->settings->init();
		$this->renderer->init();

		add_filter(
			'plugin_action_links_' . TURNSTILE_FORMS_BASENAME,
			array( $this, 'add_settings_link' )
		);

		$this->register_elementor_field();
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
				require_once TURNSTILE_FORMS_DIR . 'includes/elementor/class-turnstile-field.php';
				$registrar->register( new Elementor\Turnstile_Field( $settings, $verifier, $renderer ) );
			}
		);
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
		$url  = admin_url( 'options-general.php?page=turnstile-forms' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'captcha-field-for-turnstile' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}
}
