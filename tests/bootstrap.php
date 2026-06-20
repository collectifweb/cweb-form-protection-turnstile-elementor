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
	'options'    => array(),
	'filters'    => array(),
	'doing_ajax' => false,
	'caps'       => array(),
	'hooks'      => array(),
	'wc_notices' => array(),
	'http'       => array(
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

	/**
	 * First error code (empty string when none).
	 *
	 * @return string
	 */
	public function get_error_code() {
		foreach ( $this->errors as $code => $messages ) {
			return $code;
		}
		return '';
	}

	/**
	 * All error messages, flattened.
	 *
	 * @return array
	 */
	public function get_error_messages() {
		$all = array();
		foreach ( $this->errors as $messages ) {
			foreach ( $messages as $message ) {
				$all[] = $message;
			}
		}
		return $all;
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

/**
 * Stub esc_html.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return $text;
}

/**
 * Stub esc_html__.
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Stub sanitize_key.
 *
 * @param string $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Stub wp_doing_ajax (controlled by $GLOBALS['__tf']['doing_ajax']).
 *
 * @return bool
 */
function wp_doing_ajax() {
	return ! empty( $GLOBALS['__tf']['doing_ajax'] );
}

/**
 * Stub current_user_can (capabilities live in $GLOBALS['__tf']['caps']).
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return ! empty( $GLOBALS['__tf']['caps'][ $capability ] );
}

/**
 * Test double thrown by wp_die() so a blocked submission is observable.
 */
class CWebTS_WPDie_Exception extends \Exception {}

/**
 * Stub wp_die: throw instead of halting so tests can assert a block.
 *
 * @param string $message Message.
 * @param string $title   Title.
 * @param array  $args    Args.
 * @return void
 * @throws CWebTS_WPDie_Exception Always, to signal a blocked request.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new CWebTS_WPDie_Exception( is_string( $message ) ? $message : 'wp_die' );
}

/**
 * Stub add_action: record the hook so tests can assert what an integration
 * registers (and, just as importantly, what it does NOT register).
 *
 * @param string   $tag           Hook tag.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted args.
 * @return bool
 */
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__tf']['hooks'][] = array(
		'kind'     => 'action',
		'tag'      => $tag,
		'priority' => $priority,
		'args'     => $accepted_args,
	);
	return true;
}

/**
 * Stub add_filter (same capture as add_action).
 *
 * @param string   $tag           Hook tag.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted args.
 * @return bool
 */
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__tf']['hooks'][] = array(
		'kind'     => 'filter',
		'tag'      => $tag,
		'priority' => $priority,
		'args'     => $accepted_args,
	);
	return true;
}

/**
 * Stub wc_add_notice: capture WooCommerce notices so tests can assert them.
 *
 * @param string $message Notice message.
 * @param string $type    Notice type.
 * @return void
 */
function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['__tf']['wc_notices'][] = array(
		'message' => $message,
		'type'    => $type,
	);
}

$cwebts_plugin_dir = dirname( __DIR__ ) . '/cweb-form-protection-turnstile-elementor';
require_once $cwebts_plugin_dir . '/includes/class-settings.php';
require_once $cwebts_plugin_dir . '/includes/class-verifier.php';
require_once $cwebts_plugin_dir . '/includes/class-widget-renderer.php';
// Pure static helpers exercised below; the parent must be loaded first. Neither is
// instantiated here, so the Elementor Pro type-hints never need to resolve.
require_once $cwebts_plugin_dir . '/includes/integrations/class-abstract-integration.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-elementor-all-forms.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-wp-comments.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-wc-checkout.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-wc-login.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-wc-register.php';
require_once $cwebts_plugin_dir . '/includes/integrations/class-wc-account.php';

/**
 * Reset test state between scenarios.
 *
 * @param array $options Option overrides for cwebts_settings.
 * @return void
 */
function tf_reset( $options = array() ) {
	$GLOBALS['__tf']['filters']    = array();
	$GLOBALS['__tf']['doing_ajax'] = false;
	$GLOBALS['__tf']['caps']       = array();
	$GLOBALS['__tf']['hooks']      = array();
	$GLOBALS['__tf']['wc_notices'] = array();
	unset( $_POST['cf-turnstile-response'], $_REQUEST['action'] );
	$GLOBALS['__tf']['http']       = array(
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
