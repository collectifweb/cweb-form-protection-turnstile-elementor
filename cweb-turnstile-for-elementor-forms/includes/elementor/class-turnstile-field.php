<?php
/**
 * Elementor Pro Forms custom field: Cloudflare Turnstile.
 *
 * Added per form (like the native reCAPTCHA field), so only the forms that
 * contain this field are protected.
 *
 * @package CWebTS
 */

namespace CWebTS\Elementor;

use ElementorPro\Modules\Forms\Fields\Field_Base;
use CWebTS\Settings;
use CWebTS\Verifier;
use CWebTS\Widget_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Turnstile_Field
 */
class Turnstile_Field extends Field_Base {

	const ACTION = 'elementor_form';

	/**
	 * Guard so a single submission is verified once even if a form mistakenly
	 * contains more than one Turnstile field.
	 *
	 * @var bool
	 */
	private static $validated = false;

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
	 * Constructor.
	 *
	 * @param Settings        $settings Settings service.
	 * @param Verifier        $verifier Verifier service.
	 * @param Widget_Renderer $renderer Renderer service.
	 */
	public function __construct( Settings $settings, Verifier $verifier, Widget_Renderer $renderer ) {
		$this->settings = $settings;
		$this->verifier = $verifier;
		$this->renderer = $renderer;

		parent::__construct();
	}

	/**
	 * Unique field type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'turnstile';
	}

	/**
	 * Field label shown in the editor.
	 *
	 * @return string
	 */
	public function get_name() {
		return esc_html__( 'Cloudflare Turnstile', 'cweb-turnstile-for-elementor-forms' );
	}

	/**
	 * Render the widget on the front end.
	 *
	 * @param array $item       Field settings.
	 * @param int   $item_index Field index.
	 * @param mixed $form       Form widget instance.
	 * @return void
	 */
	public function render( $item, $item_index, $form ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$this->renderer->enqueue();
		$this->renderer->render( array( 'action' => self::ACTION ) );
	}

	/**
	 * Validate the submitted token.
	 *
	 * Elementor calls this for each field of this type in the submitted form,
	 * so only forms that contain the field are checked.
	 *
	 * @param array $field        Field data.
	 * @param mixed $record       Form record.
	 * @param mixed $ajax_handler Ajax handler.
	 * @return void
	 */
	public function validation( $field, $record, $ajax_handler ) {
		// Without keys we cannot verify; do not block submissions.
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		// Only verify once per submission, even with duplicate Turnstile fields.
		if ( self::$validated ) {
			return;
		}
		self::$validated = true;

		// Elementor handles its own form nonce; the captcha token is a separate
		// field that Cloudflare itself verifies, so nonce verification is N/A here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Captcha token, not a nonce-guarded action.
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';

		if ( ! $this->verifier->verify( $token, null, self::ACTION ) ) {
			$ajax_handler->add_error( $field['id'], $this->settings->get_error_message() );
		}
	}
}
