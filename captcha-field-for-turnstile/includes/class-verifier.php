<?php
/**
 * Server-side Turnstile verification.
 *
 * @package TurnstileForms
 */

namespace TurnstileForms;

defined( 'ABSPATH' ) || exit;

/**
 * Class Verifier
 *
 * Implements the agreed 4-case decision matrix:
 *   1. Missing keys           -> handled upstream (widget not rendered).
 *   2. Empty / oversized token -> reject locally, no network call.
 *   3. Cloudflare success:false -> reject.
 *   4. Network/timeout/WP_Error/invalid JSON -> apply failure_mode.
 */
class Verifier {

	const ENDPOINT         = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const MAX_TOKEN_LENGTH = 2048;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Per-request cache of raw siteverify responses, keyed by token hash.
	 *
	 * A token is single-use: caching the raw response prevents a second
	 * siteverify call for the same token in one request (e.g. two Turnstile
	 * fields in a form), which would otherwise return "timeout-or-duplicate".
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Verify a Turnstile token.
	 *
	 * @param string      $token           The cf-turnstile-response token.
	 * @param string|null $remoteip        Override remote IP (null = REMOTE_ADDR).
	 * @param string|null $expected_action Expected action for optional checking.
	 * @return bool True if the visitor passed.
	 */
	public function verify( $token, $remoteip = null, $expected_action = null ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		// Case 2: empty or oversized token -> reject without a network call.
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			return false;
		}

		// No secret means we cannot verify; fail closed (callers gate on is_configured()).
		if ( '' === $this->settings->get_secret_key() ) {
			return false;
		}

		$raw = $this->siteverify( $token, $remoteip );

		// Case 4: transport failure -> failure_mode decides.
		if ( isset( $raw['__transport_error'] ) ) {
			return 'allow' === $this->settings->get( 'failure_mode' );
		}

		// Case 3: Cloudflare rejected the token.
		if ( empty( $raw['success'] ) ) {
			return false;
		}

		// Optional local control: action (off by default).
		if ( null !== $expected_action && apply_filters( 'turnstile_forms_verify_action', false ) ) {
			$action = isset( $raw['action'] ) ? (string) $raw['action'] : '';
			if ( $action !== (string) $expected_action ) {
				return false;
			}
		}

		// Optional local control: hostname (off by default).
		if ( apply_filters( 'turnstile_forms_verify_hostname', false ) ) {
			$default_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$allowlist    = (array) apply_filters( 'turnstile_forms_hostname_allowlist', array( $default_host ) );
			$hostname     = isset( $raw['hostname'] ) ? (string) $raw['hostname'] : '';
			if ( ! in_array( $hostname, $allowlist, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Perform (or replay from cache) the siteverify request.
	 *
	 * @param string      $token    Token.
	 * @param string|null $remoteip Override remote IP.
	 * @return array Raw decoded response, or array with __transport_error => true.
	 */
	private function siteverify( $token, $remoteip ) {
		$cache_key = hash( 'sha256', $token );

		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$body = array(
			'secret'   => $this->settings->get_secret_key(),
			'response' => $token,
		);

		$remoteip = $this->resolve_remote_ip( $remoteip );
		if ( '' !== $remoteip ) {
			$body['remoteip'] = $remoteip;
		}

		$timeout = (int) apply_filters( 'turnstile_forms_timeout', 5 );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $timeout,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array( '__transport_error' => true );
		} else {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );

			// Honour a well-formed verdict regardless of HTTP status code: a JSON
			// body carrying "success" is a real Cloudflare answer (success:false
			// must always reject). Only a missing/invalid body is a transport
			// error subject to failure_mode.
			if ( is_array( $json ) && array_key_exists( 'success', $json ) ) {
				$result = $json;
			} else {
				$result = array( '__transport_error' => true );
			}
		}

		self::$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Resolve the remote IP to send (strict REMOTE_ADDR, never X-Forwarded-For).
	 *
	 * @param string|null $override Explicit override (null = use REMOTE_ADDR).
	 * @return string Valid IP, or '' to omit.
	 */
	private function resolve_remote_ip( $override ) {
		if ( null === $override ) {
			$override = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		/**
		 * Filter the remote IP sent to Cloudflare.
		 *
		 * Return '' (or null) to omit it, or a trusted proxy-resolved IP.
		 *
		 * @param string $override Candidate IP.
		 */
		$ip = apply_filters( 'turnstile_forms_remoteip', $override );

		if ( ! is_string( $ip ) || '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $ip;
	}

	/**
	 * Reset the per-request cache (test helper).
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$cache = array();
	}
}
