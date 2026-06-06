<?php
/**
 * Widget rendering and asset enqueuing.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms;

defined( 'ABSPATH' ) || exit;

/**
 * Class Widget_Renderer
 */
class Widget_Renderer {

	const API_HANDLE = 'turnstile-forms-api';
	const JS_HANDLE  = 'turnstile-forms';
	const API_URL    = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the script_loader_tag filter (defer, compatible with WP 5.8+).
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 2 );
	}

	/**
	 * Register the Cloudflare API and helper scripts.
	 *
	 * Explicit rendering is used (render=explicit + onload) so the helper can
	 * attach callbacks (expired/error) and reset widgets after Elementor AJAX.
	 *
	 * @return void
	 */
	private function register_scripts() {
		if ( wp_script_is( self::JS_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::JS_HANDLE,
			TURNSTILE_FORMS_URL . 'assets/js/turnstile.js',
			array(),
			TURNSTILE_FORMS_VERSION,
			true
		);

		$api_url = add_query_arg(
			array(
				'render' => 'explicit',
				'onload' => 'turnstileFormsOnload',
			),
			self::API_URL
		);

		// The API depends on our helper so the onload global is defined first.
		wp_register_script( self::API_HANDLE, $api_url, array( self::JS_HANDLE ), null, true );
	}

	/**
	 * Enqueue the scripts (idempotent).
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$this->register_scripts();
		wp_enqueue_script( self::JS_HANDLE );
		wp_enqueue_script( self::API_HANDLE );
	}

	/**
	 * Build the widget markup.
	 *
	 * @param array $overrides Optional per-instance overrides:
	 *                         action, theme, size, appearance, language, class, id.
	 * @return string Escaped HTML, or '' when not configured.
	 */
	public function get_html( $overrides = array() ) {
		if ( ! $this->settings->is_configured() ) {
			return '';
		}

		$defaults = array(
			'action'     => '',
			'theme'      => $this->settings->get( 'theme' ),
			'size'       => $this->settings->get( 'size' ),
			'appearance' => $this->settings->get( 'appearance' ),
			'language'   => $this->settings->get( 'language' ),
			'class'      => '',
			'id'         => '',
		);

		$args = wp_parse_args( $overrides, $defaults );

		$attributes = array(
			'class'           => trim( 'cf-turnstile turnstile-forms-widget ' . $args['class'] ),
			'data-sitekey'    => $this->settings->get_site_key(),
			'data-theme'      => $args['theme'],
			'data-size'       => $args['size'],
			'data-appearance' => $args['appearance'],
			'data-language'   => $args['language'],
		);

		if ( '' !== $args['action'] ) {
			$attributes['data-action'] = $args['action'];
		}

		if ( '' !== $args['id'] ) {
			$attributes['id'] = $args['id'];
		}

		$html = '<div';
		foreach ( $attributes as $name => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		$html .= '></div>';

		return $html;
	}

	/**
	 * Echo the widget markup.
	 *
	 * @param array $overrides See get_html().
	 * @return void
	 */
	public function render( $overrides = array() ) {
		echo $this->get_html( $overrides ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_html() escapes every attribute.
	}

	/**
	 * Add async/defer (and the Cloudflare-recommended attributes) to the API tag.
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script handle.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle ) {
		if ( self::API_HANDLE !== $handle ) {
			return $tag;
		}

		if ( false === strpos( $tag, ' defer' ) ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}

		return $tag;
	}
}
