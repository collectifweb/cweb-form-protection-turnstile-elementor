<?php
/**
 * Lightweight WordPress stubs so the Verifier and Settings sanitization can be
 * unit-tested without a full WordPress install.
 *
 * Used by tests/run-tests.php (standalone) and adaptable to PHPUnit.
 *
 * @package CWebTS
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'CWEBTS_VERSION', 'test' );
define( 'CWEBTS_URL', 'http://example.test/wp-content/plugins/cft/' );
define( 'CWEBTS_DIR', dirname( __DIR__ ) . '/' );
define( 'CWEBTS_BASENAME', 'cft/cft.php' );

/**
 * Mutable test state.
 *
 * @var array
 */
$GLOBALS['__tf'] = array(
	'options' => array(),
	'filters' => array(),
	'http'    => array(
		'wp_error' => false,
		'code'     => 200,
		'body'     => '{"success":true}',
		'calls'    => 0,
		'last'     => null,
	),
);

/**
 * Minimal WP_Error.
 */
class WP_Error {
	/** @var array */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message );
		}
	}

	/**
	 * Add an error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return void
	 */
	public function add( $code, $message = '' ) {
		$this->errors[ $code ][] = $message;
	}

	/**
	 * First error message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		foreach ( $this->errors as $messages ) {
			return isset( $messages[0] ) ? $messages[0] : '';
		}
		return '';
	}
}

/**
 * Whether a value is a WP_Error.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Apply filters with optional test overrides.
 *
 * @param string $tag   Hook tag.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $tag, $value = null ) {
	if ( isset( $GLOBALS['__tf']['filters'][ $tag ] ) ) {
		$override = $GLOBALS['__tf']['filters'][ $tag ];
		return is_callable( $override ) ? call_user_func( $override, $value ) : $override;
	}
	return $value;
}

/**
 * Mock wp_remote_post.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_post( $url, $args = array() ) {
	$http           = &$GLOBALS['__tf']['http'];
	$http['calls']++;
	$http['last'] = $args;

	if ( $http['wp_error'] ) {
		return new WP_Error( 'http_request_failed', 'mock network error' );
	}

	return array(
		'__code' => $http['code'],
		'__body' => $http['body'],
	);
}

/**
 * Mock response code retriever.
 *
 * @param array $response Response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['__code'] ) ? (int) $response['__code'] : 0;
}

/**
 * Mock body retriever.
 *
 * @param array $response Response.
 * @return string
 */
function wp_remote_retrieve_body( $response ) {
	return isset( $response['__body'] ) ? (string) $response['__body'] : '';
}

/**
 * Stub sanitize_text_field.
 *
 * @param string $str Value.
 * @return string
 */
function sanitize_text_field( $str ) {
	$str = is_string( $str ) ? $str : '';
	$str = wp_strip_all_tags( $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}

/**
 * Stub wp_strip_all_tags.
 *
 * @param string $str Value.
 * @return string
 */
function wp_strip_all_tags( $str ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
}

/**
 * Stub wp_unslash.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Stub wp_parse_url.
 *
 * @param string $url       URL.
 * @param int    $component Component.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Stub home_url.
 *
 * @return string
 */
function home_url() {
	return 'https://example.com';
}

/**
 * Stub wp_parse_args.
 *
 * @param array $args     Args.
 * @param array $defaults Defaults.
 * @return array
 */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * Stub get_option.
 *
 * @param string $name    Name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['__tf']['options'][ $name ] ) ? $GLOBALS['__tf']['options'][ $name ] : $default;
}

/**
 * Stub update_option.
 *
 * @param string $name  Name.
 * @param mixed  $value Value.
 * @return bool
 */
function update_option( $name, $value ) {
	$GLOBALS['__tf']['options'][ $name ] = $value;
	return true;
}

/**
 * Stub translation.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

$cwebts_plugin_dir = dirname( __DIR__ ) . '/cweb-form-protection-turnstile-elementor';
require_once $cwebts_plugin_dir . '/includes/class-settings.php';
require_once $cwebts_plugin_dir . '/includes/class-verifier.php';
// Pure static helpers exercised below; the parent must be loaded first. Neither is
// instantiated here, so the Elementor Pro type-hints never need to resolve.
require_once $cwebts_plugin_dir . '/includes/integrations/class-abstract-integration.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-elementor-all-forms.php';

/**
 * Reset test state between scenarios.
 *
 * @param array $options Option overrides for cwebts_settings.
 * @return void
 */
function tf_reset( $options = array() ) {
	$GLOBALS['__tf']['filters'] = array();
	$GLOBALS['__tf']['http']    = array(
		'wp_error' => false,
		'code'     => 200,
		'body'     => '{"success":true}',
		'calls'    => 0,
		'last'     => null,
	);
	$GLOBALS['__tf']['options'] = array(
		'cwebts_settings' => array_merge(
			array(
				'site_key'     => 'site',
				'secret_key'   => 'secret',
				'failure_mode' => 'block',
			),
			$options
		),
	);
	\CWebTS\Verifier::reset_request_cache();
}
