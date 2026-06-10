<?php
/**
 * "Protect all Elementor Pro forms" integration (opt-in).
 *
 * When the toggle is on, the Turnstile widget is injected server-side into every
 * Elementor Pro form (before its submit button) and every submission is verified
 * through the global Forms validation hook — without requiring the per-form
 * Turnstile field. The per-form field remains the default; this mode is the
 * opt-in shortcut.
 *
 * No hard Elementor Pro type-hints: the hooks below only fire when Elementor Pro
 * is loaded, so the class is inert (and safe to instantiate) without it.
 *
 * @package CWebTS
 */

namespace CWebTS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_All_Forms
 */
class Elementor_All_Forms extends Abstract_Integration {

	const ACTION = 'elementor_form';

	/**
	 * Toggle key.
	 *
	 * @return string
	 */
	protected function toggle() {
		return 'protect_elementor_all_forms';
	}

	/**
	 * Action name.
	 *
	 * @return string
	 */
	protected function action() {
		return self::ACTION;
	}

	/**
	 * Register hooks (only reached when the toggle is on and keys are present).
	 *
	 * @return void
	 */
	protected function register() {
		// Inject the widget into the rendered Form widget HTML.
		add_filter( 'elementor/widget/render_content', array( $this, 'inject' ), 10, 2 );
		// Verify every Elementor form submission (skips forms that carry the field).
		add_action( 'elementor_pro/forms/validation', array( $this, 'validate' ), 10, 2 );
		// In global mode the assets must be present even on pages whose forms are
		// loaded late (popups/AJAX), so the MutationObserver can render them.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Inject the Turnstile widget into a rendered Elementor Form widget.
	 *
	 * @param string $content Rendered widget HTML.
	 * @param mixed  $widget  Elementor widget instance.
	 * @return string
	 */
	public function inject( $content, $widget ) {
		if ( ! is_object( $widget ) || ! is_callable( array( $widget, 'get_name' ) ) || 'form' !== $widget->get_name() ) {
			return $content;
		}

		// get_html() returns '' when keys are missing — never inject an empty wrapper.
		$html = $this->renderer->get_html( array( 'action' => self::ACTION ) );
		if ( '' === $html ) {
			return $content;
		}

		// Wrap in an Elementor field group so spacing/alignment match real fields.
		$field = '<div class="elementor-field-group elementor-column elementor-col-100 cwebts-elementor-auto-field">' . $html . '</div>';

		return self::inject_widget_before_submit( $content, $field );
	}

	/**
	 * Insert the widget HTML before the submit button, strictly inside the form.
	 *
	 * Pure and side-effect free so it can be unit-tested without Elementor Pro.
	 * Never inserts outside the <form>: a widget outside the form would not submit
	 * the cf-turnstile-response field, and the global validation would then block
	 * every submission.
	 *
	 * @param string $content     Rendered form HTML.
	 * @param string $widget_html Widget markup to insert.
	 * @return string
	 */
	public static function inject_widget_before_submit( $content, $widget_html ) {
		if ( '' === $widget_html ) {
			return $content;
		}

		// Our widget is already there (per-form field or an earlier injection).
		if ( false !== strpos( $content, 'cwebts-widget' ) ) {
			return $content;
		}

		// Preferred anchor: just before the submit field group — but only when that
		// point is provably inside a <form>. A stray "elementor-field-type-submit"
		// in a wrapper, template or cache fragment must never pull the widget out
		// of the form: outside, cf-turnstile-response is not submitted and the
		// global validation would block the form.
		$pos = strpos( $content, 'elementor-field-type-submit' );
		if ( false !== $pos ) {
			$div_pos = strrpos( substr( $content, 0, $pos ), '<div' );
			if ( false !== $div_pos && self::position_inside_form( $content, $div_pos ) ) {
				return substr( $content, 0, $div_pos ) . $widget_html . substr( $content, $div_pos );
			}
		}

		// Fallback: just before the closing </form> of an actual form.
		$form_close = strripos( $content, '</form>' );
		if ( false !== $form_close && false !== strripos( substr( $content, 0, $form_close ), '<form' ) ) {
			return substr( $content, 0, $form_close ) . $widget_html . substr( $content, $form_close );
		}

		// No safe insertion point inside a form: leave the markup untouched.
		return $content;
	}

	/**
	 * Whether a byte offset sits inside an open <form> ... </form> pair.
	 *
	 * @param string $content HTML.
	 * @param int    $pos     Offset to test.
	 * @return bool
	 */
	private static function position_inside_form( $content, $pos ) {
		$before    = substr( $content, 0, $pos );
		$form_open = strripos( $before, '<form' );
		if ( false === $form_open ) {
			return false;
		}

		// A </form> between the nearest <form> and $pos means that form already closed.
		$form_close_before = strripos( $before, '</form>' );
		if ( false !== $form_close_before && $form_close_before > $form_open ) {
			return false;
		}

		// The form must also close after $pos.
		return false !== stripos( $content, '</form>', $pos );
	}

	/**
	 * Verify a submission for forms that do not already carry the Turnstile field.
	 *
	 * @param mixed $record       Elementor Form_Record instance.
	 * @param mixed $ajax_handler Elementor Ajax_Handler instance.
	 * @return void
	 */
	public function validate( $record, $ajax_handler ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		// Forms that carry the field are handled by Turnstile_Field; skip here to
		// avoid a duplicate check and a duplicate error message.
		if ( self::record_has_turnstile_field( $record ) ) {
			return;
		}

		if ( ! is_object( $ajax_handler ) || $this->passes() ) {
			return;
		}

		$message = $this->settings->get_error_message();

		// Form-level error. Older/other Elementor Pro builds may lack
		// add_error_message(); fall back to a keyed field error to avoid a fatal.
		if ( method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( $message );
		} elseif ( is_callable( array( $ajax_handler, 'add_error' ) ) ) {
			$ajax_handler->add_error( 'cwebts_turnstile', $message );
		}
	}

	/**
	 * Whether the submitted record already contains the Turnstile field.
	 *
	 * Pure and side-effect free so it can be unit-tested. Reads the record's
	 * fields rather than the rendered HTML.
	 *
	 * @param mixed $record Elementor Form_Record instance.
	 * @return bool
	 */
	public static function record_has_turnstile_field( $record ) {
		if ( ! is_object( $record ) || ! is_callable( array( $record, 'get' ) ) ) {
			return false;
		}

		$fields = (array) $record->get( 'fields' );
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'turnstile' === $field['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue the widget assets across the front end while global mode is active.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		$this->renderer->enqueue();
	}
}
