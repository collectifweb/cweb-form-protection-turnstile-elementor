<?php
/**
 * Settings service: option storage, admin page, sanitization.
 *
 * @package CWebTS
 */

namespace CWebTS;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPTION        = 'cwebts_settings';
	const GROUP         = 'cwebts_group';
	const PAGE          = 'cwebts';
	const CAP           = 'manage_options';
	const IMPORT_ACTION = 'cwebts_import';

	/**
	 * Cached options array.
	 *
	 * @var array|null
	 */
	private $options = null;

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'site_key'             => '',
			'secret_key'           => '',
			'theme'                => 'auto',
			'size'                 => 'flexible',
			'appearance'           => 'always',
			'language'             => 'auto',
			'error_message'        => __( 'Please confirm you are not a robot.', 'cweb-form-protection-turnstile-elementor' ),
			'protect_elementor_all_forms' => 0,
			'protect_login'        => 0,
			'protect_register'     => 0,
			'protect_lostpassword' => 0,
			'protect_comments'     => 0,
			'failure_mode'         => 'block',
		);
	}

	/**
	 * Allowed values for select fields.
	 *
	 * @return array
	 */
	private function choices() {
		return array(
			'theme'        => array( 'auto', 'light', 'dark' ),
			'size'         => array( 'normal', 'flexible', 'compact' ),
			'appearance'   => array( 'always', 'interaction-only' ),
			'failure_mode' => array( 'block', 'allow' ),
			'language'     => array( 'auto', 'ar', 'de', 'en', 'es', 'fa', 'fr', 'id', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh', 'zh-cn', 'zh-tw' ),
		);
	}

	/**
	 * Lazy-load and cache the merged options.
	 *
	 * @return array
	 */
	private function all() {
		if ( null === $this->options ) {
			$stored        = get_option( self::OPTION, array() );
			$this->options = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
		}

		return $this->options;
	}

	/**
	 * Get a single option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback when absent.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Public site key.
	 *
	 * @return string
	 */
	public function get_site_key() {
		return (string) $this->get( 'site_key', '' );
	}

	/**
	 * Secret key (server-side only).
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return (string) $this->get( 'secret_key', '' );
	}

	/**
	 * Whether both keys are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_site_key() && '' !== $this->get_secret_key();
	}

	/**
	 * Error message shown on a failed challenge.
	 *
	 * @return string
	 */
	public function get_error_message() {
		$message = (string) $this->get( 'error_message', '' );

		return '' !== $message ? $message : $this->defaults()['error_message'];
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'CWeb Form Protection with Turnstile for Elementor Forms', 'cweb-form-protection-turnstile-elementor' ),
			__( 'CWeb Form Protection', 'cweb-form-protection-turnstile-elementor' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting and its fields.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);

		add_settings_section(
			'cwebts_keys',
			__( 'Cloudflare Turnstile keys', 'cweb-form-protection-turnstile-elementor' ),
			array( $this, 'section_keys_intro' ),
			self::PAGE
		);

		add_settings_field( 'site_key', __( 'Site key', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_site_key' ), self::PAGE, 'cwebts_keys' );
		add_settings_field( 'secret_key', __( 'Secret key', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_secret_key' ), self::PAGE, 'cwebts_keys' );

		add_settings_section(
			'cwebts_appearance',
			__( 'Widget appearance', 'cweb-form-protection-turnstile-elementor' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field( 'theme', __( 'Theme', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_theme' ), self::PAGE, 'cwebts_appearance' );
		add_settings_field( 'size', __( 'Size', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_size' ), self::PAGE, 'cwebts_appearance' );
		add_settings_field( 'appearance', __( 'Visibility', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_appearance' ), self::PAGE, 'cwebts_appearance' );
		add_settings_field( 'language', __( 'Language', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_language' ), self::PAGE, 'cwebts_appearance' );
		add_settings_field( 'error_message', __( 'Error message', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_error_message' ), self::PAGE, 'cwebts_appearance' );

		add_settings_section(
			'cwebts_elementor',
			__( 'Elementor Pro forms', 'cweb-form-protection-turnstile-elementor' ),
			array( $this, 'section_elementor_intro' ),
			self::PAGE
		);

		add_settings_field( 'protect_elementor_all_forms', __( 'All Elementor Pro forms', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_protect_elementor_all_forms' ), self::PAGE, 'cwebts_elementor' );

		add_settings_section(
			'cwebts_native',
			__( 'WordPress forms', 'cweb-form-protection-turnstile-elementor' ),
			array( $this, 'section_native_intro' ),
			self::PAGE
		);

		add_settings_field( 'protect_login', __( 'Login form', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_protect_login' ), self::PAGE, 'cwebts_native' );
		add_settings_field( 'protect_register', __( 'Registration form', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_protect_register' ), self::PAGE, 'cwebts_native' );
		add_settings_field( 'protect_lostpassword', __( 'Lost password form', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_protect_lostpassword' ), self::PAGE, 'cwebts_native' );
		add_settings_field( 'protect_comments', __( 'Comment form', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_protect_comments' ), self::PAGE, 'cwebts_native' );

		add_settings_section(
			'cwebts_advanced',
			__( 'Advanced', 'cweb-form-protection-turnstile-elementor' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field( 'failure_mode', __( 'If Cloudflare is unreachable', 'cweb-form-protection-turnstile-elementor' ), array( $this, 'field_failure_mode' ), self::PAGE, 'cwebts_advanced' );
	}

	/**
	 * Sanitize the whole option array.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = $this->all();
		$choices  = $this->choices();
		$clean    = $this->defaults();

		$clean['site_key'] = isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '';

		// Secret key: write-only handling.
		if ( ! empty( $input['remove_secret'] ) ) {
			$clean['secret_key'] = '';
		} elseif ( isset( $input['secret_key'] ) && '' !== trim( (string) $input['secret_key'] ) ) {
			$clean['secret_key'] = sanitize_text_field( $input['secret_key'] );
		} else {
			$clean['secret_key'] = isset( $existing['secret_key'] ) ? (string) $existing['secret_key'] : '';
		}

		foreach ( array( 'theme', 'size', 'appearance', 'language', 'failure_mode' ) as $key ) {
			$value           = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
			$clean[ $key ]   = in_array( $value, $choices[ $key ], true ) ? $value : $this->defaults()[ $key ];
		}

		$message                 = isset( $input['error_message'] ) ? sanitize_text_field( $input['error_message'] ) : '';
		$clean['error_message']  = '' !== $message ? $message : $this->defaults()['error_message'];

		foreach ( array( 'protect_elementor_all_forms', 'protect_login', 'protect_register', 'protect_lostpassword', 'protect_comments' ) as $toggle ) {
			$clean[ $toggle ] = empty( $input[ $toggle ] ) ? 0 : 1;
		}

		return $clean;
	}

	/* --------------------------------------------------------------------- *
	 * Import from "Simple Cloudflare Turnstile" (Elliot Sowersby).
	 * --------------------------------------------------------------------- */

	/**
	 * Whether importable data from Simple Cloudflare Turnstile is present.
	 *
	 * @return bool
	 */
	public function has_import_source() {
		$key    = get_option( 'cfturnstile_key', '' );
		$secret = get_option( 'cfturnstile_secret', '' );

		return ( is_string( $key ) && '' !== $key ) || ( is_string( $secret ) && '' !== $secret );
	}

	/**
	 * Interpret a stored toggle value as a boolean.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );

		return '' !== $value && ! in_array( $value, array( '0', 'off', 'no', 'false' ), true );
	}

	/**
	 * Copy keys and settings from Simple Cloudflare Turnstile into this plugin.
	 *
	 * Values are passed through sanitize() so unsupported choices fall back to
	 * defaults; missing source options are skipped.
	 *
	 * @return int Number of source values applied.
	 */
	public function import_from_simple_turnstile() {
		$raw   = $this->all();
		$count = 0;

		$direct = array(
			'site_key'      => 'cfturnstile_key',
			'secret_key'    => 'cfturnstile_secret',
			'theme'         => 'cfturnstile_theme',
			'size'          => 'cfturnstile_size',
			'appearance'    => 'cfturnstile_appearance',
			'language'      => 'cfturnstile_language',
			'error_message' => 'cfturnstile_error_message',
		);

		foreach ( $direct as $ours => $source ) {
			$value = get_option( $source, null );
			if ( null === $value || '' === $value ) {
				continue;
			}

			$value = is_string( $value ) ? $value : (string) $value;

			// Normalise regional language codes (e.g. "fr-FR" -> "fr") so they
			// survive sanitize() instead of falling back to "auto".
			if ( 'language' === $ours ) {
				$lang = strtolower( $value );
				if ( ! in_array( $lang, $this->choices()['language'], true ) && false !== strpos( $lang, '-' ) ) {
					$base = substr( $lang, 0, strpos( $lang, '-' ) );
					if ( in_array( $base, $this->choices()['language'], true ) ) {
						$lang = $base;
					}
				}
				$value = $lang;
			}

			$raw[ $ours ] = $value;
			$count++;
		}

		$toggles = array(
			'protect_login'        => 'cfturnstile_login',
			'protect_register'     => 'cfturnstile_register',
			'protect_lostpassword' => 'cfturnstile_reset',
			'protect_comments'     => 'cfturnstile_comment',
		);

		foreach ( $toggles as $ours => $source ) {
			$value = get_option( $source, null );
			if ( null !== $value ) {
				$raw[ $ours ] = $this->is_truthy( $value ) ? 1 : 0;
				$count++;
			}
		}

		// Map Elliot's failover/failsafe to our failure_mode.
		$failover = get_option( 'cfturnstile_failover', null );
		if ( null !== $failover ) {
			$type = strtolower( trim( (string) get_option( 'cfturnstile_failsafe_type', '' ) ) );
			// Mirror Simple Cloudflare Turnstile: an empty failsafe type means "allow".
			$is_allow            = $this->is_truthy( $failover ) && ( '' === $type || in_array( $type, array( 'pass', 'allow', 'open', 'available' ), true ) );
			$raw['failure_mode'] = $is_allow ? 'allow' : 'block';
			$count++;
		}

		$clean = $this->sanitize( $raw );
		update_option( self::OPTION, $clean );
		$this->options = null;

		return $count;
	}

	/**
	 * Handle the import admin-post request.
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cweb-form-protection-turnstile-elementor' ) );
		}

		check_admin_referer( self::IMPORT_ACTION );

		$count = $this->import_from_simple_turnstile();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'cwebts_imported'  => (int) $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the import call-to-action when source data exists.
	 *
	 * @return void
	 */
	private function render_import_box() {
		if ( ! $this->has_import_source() ) {
			return;
		}
		?>
		<div class="notice notice-info inline cwebts-import">
			<p><strong><?php esc_html_e( 'Migrating from Simple Cloudflare Turnstile?', 'cweb-form-protection-turnstile-elementor' ); ?></strong></p>
			<p><?php esc_html_e( 'We detected keys and settings from "Simple Cloudflare Turnstile". You can copy them here so you do not have to recreate your Cloudflare keys. This does not change or deactivate the other plugin.', 'cweb-form-protection-turnstile-elementor' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
				<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
				<?php submit_button( __( 'Import keys & settings', 'cweb-form-protection-turnstile-elementor' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 * Field + section renderers.
	 * --------------------------------------------------------------------- */

	/**
	 * Keys section intro.
	 *
	 * @return void
	 */
	public function section_keys_intro() {
		printf(
			'<p>%s</p>',
			wp_kses(
				sprintf(
					/* translators: %s: Cloudflare dashboard URL. */
					__( 'Create a free widget and get your keys from the <a href="%s" target="_blank" rel="noopener noreferrer">Cloudflare dashboard</a>.', 'cweb-form-protection-turnstile-elementor' ),
					'https://dash.cloudflare.com/?to=/:account/turnstile'
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);
	}

	/**
	 * Native section intro.
	 *
	 * @return void
	 */
	public function section_native_intro() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Enable Turnstile on the built-in WordPress forms (login, registration, lost password, comments).', 'cweb-form-protection-turnstile-elementor' )
		);
	}

	/**
	 * Elementor Pro section intro.
	 *
	 * @return void
	 */
	public function section_elementor_intro() {
		printf(
			'<p>%s</p>',
			esc_html__( 'By default you protect Elementor Pro forms one by one by adding the "Cloudflare Turnstile" field to the forms you choose. Turn the option below on to protect every Elementor Pro form automatically.', 'cweb-form-protection-turnstile-elementor' )
		);
	}

	/**
	 * "All Elementor Pro forms" toggle.
	 *
	 * @return void
	 */
	public function field_protect_elementor_all_forms() {
		$this->render_toggle( 'protect_elementor_all_forms', __( 'Protect every Elementor Pro form automatically.', 'cweb-form-protection-turnstile-elementor' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'When off, only the forms that contain the "Cloudflare Turnstile" field are protected. When on, the widget is added to every Elementor Pro form, just before its submit button. Requires Elementor Pro.', 'cweb-form-protection-turnstile-elementor' )
		);
	}

	/**
	 * Site key field.
	 *
	 * @return void
	 */
	public function field_site_key() {
		printf(
			'<input type="text" class="regular-text" name="%1$s[site_key]" value="%2$s" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( $this->get_site_key() )
		);
	}

	/**
	 * Secret key field (write-only).
	 *
	 * @return void
	 */
	public function field_secret_key() {
		$has_secret = '' !== $this->get_secret_key();

		printf(
			'<input type="password" class="regular-text" name="%1$s[secret_key]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $has_secret ? __( '••••••••  (a secret key is saved)', 'cweb-form-protection-turnstile-elementor' ) : __( 'Enter your secret key', 'cweb-form-protection-turnstile-elementor' ) )
		);

		if ( $has_secret ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[remove_secret]" value="1" /> %2$s</label></p><p class="description">%3$s</p>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove the saved secret key', 'cweb-form-protection-turnstile-elementor' ),
				esc_html__( 'Leave the field empty to keep the saved key. The secret key is never displayed.', 'cweb-form-protection-turnstile-elementor' )
			);
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'The secret key is stored server-side and never sent to the browser.', 'cweb-form-protection-turnstile-elementor' )
			);
		}
	}

	/**
	 * Generic select renderer.
	 *
	 * @param string $key     Option key.
	 * @param array  $labels  value => label map (optional).
	 * @return void
	 */
	private function render_select( $key, $labels = array() ) {
		$choices = $this->choices();
		$current = (string) $this->get( $key );

		printf( '<select name="%1$s[%2$s]">', esc_attr( self::OPTION ), esc_attr( $key ) );

		foreach ( $choices[ $key ] as $value ) {
			$label = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Theme field.
	 *
	 * @return void
	 */
	public function field_theme() {
		$this->render_select(
			'theme',
			array(
				'auto'  => __( 'Auto', 'cweb-form-protection-turnstile-elementor' ),
				'light' => __( 'Light', 'cweb-form-protection-turnstile-elementor' ),
				'dark'  => __( 'Dark', 'cweb-form-protection-turnstile-elementor' ),
			)
		);
	}

	/**
	 * Size field.
	 *
	 * @return void
	 */
	public function field_size() {
		$this->render_select(
			'size',
			array(
				'normal'   => __( 'Normal', 'cweb-form-protection-turnstile-elementor' ),
				'flexible' => __( 'Flexible', 'cweb-form-protection-turnstile-elementor' ),
				'compact'  => __( 'Compact', 'cweb-form-protection-turnstile-elementor' ),
			)
		);
	}

	/**
	 * Appearance field.
	 *
	 * @return void
	 */
	public function field_appearance() {
		$this->render_select(
			'appearance',
			array(
				'always'           => __( 'Always visible', 'cweb-form-protection-turnstile-elementor' ),
				'interaction-only' => __( 'Only when interaction is required', 'cweb-form-protection-turnstile-elementor' ),
			)
		);
	}

	/**
	 * Language field.
	 *
	 * @return void
	 */
	public function field_language() {
		$this->render_select( 'language' );
		printf( '<p class="description">%s</p>', esc_html__( '"Auto" follows the visitor browser language.', 'cweb-form-protection-turnstile-elementor' ) );
	}

	/**
	 * Error message field.
	 *
	 * @return void
	 */
	public function field_error_message() {
		printf(
			'<input type="text" class="large-text" name="%1$s[error_message]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $this->get_error_message() )
		);
	}

	/**
	 * Render a single checkbox toggle.
	 *
	 * @param string $key         Option key.
	 * @param string $description Field description.
	 * @return void
	 */
	private function render_toggle( $key, $description ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( 1, (int) $this->get( $key ), false ),
			esc_html( $description )
		);
	}

	/**
	 * Login toggle.
	 *
	 * @return void
	 */
	public function field_protect_login() {
		$this->render_toggle( 'protect_login', __( 'Protect the WordPress login form.', 'cweb-form-protection-turnstile-elementor' ) );
	}

	/**
	 * Register toggle.
	 *
	 * @return void
	 */
	public function field_protect_register() {
		$this->render_toggle( 'protect_register', __( 'Protect the registration form.', 'cweb-form-protection-turnstile-elementor' ) );
	}

	/**
	 * Lost password toggle.
	 *
	 * @return void
	 */
	public function field_protect_lostpassword() {
		$this->render_toggle( 'protect_lostpassword', __( 'Protect the lost password form.', 'cweb-form-protection-turnstile-elementor' ) );
	}

	/**
	 * Comments toggle.
	 *
	 * @return void
	 */
	public function field_protect_comments() {
		$this->render_toggle( 'protect_comments', __( 'Protect the comment form.', 'cweb-form-protection-turnstile-elementor' ) );
	}

	/**
	 * Failure mode field.
	 *
	 * @return void
	 */
	public function field_failure_mode() {
		$this->render_select(
			'failure_mode',
			array(
				'block' => __( 'Block the submission (more secure, default)', 'cweb-form-protection-turnstile-elementor' ),
				'allow' => __( 'Allow the submission (more available)', 'cweb-form-protection-turnstile-elementor' ),
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Only applies when Cloudflare cannot be reached (network error or timeout). Invalid or missing tokens are always blocked.', 'cweb-form-protection-turnstile-elementor' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		?>
		<div class="wrap cwebts-settings">
			<h1><?php echo esc_html__( 'CWeb Form Protection with Turnstile for Elementor Forms', 'cweb-form-protection-turnstile-elementor' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-checked redirect.
			if ( isset( $_GET['cwebts_imported'] ) ) {
				$imported = (int) $_GET['cwebts_imported']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of imported settings. */
							_n( '%d setting was imported from Simple Cloudflare Turnstile.', '%d settings were imported from Simple Cloudflare Turnstile.', $imported, 'cweb-form-protection-turnstile-elementor' ),
							$imported
						)
					)
				);
			}
			$this->render_import_box();
			?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show an admin notice while the keys are missing.
	 *
	 * @return void
	 */
	public function configuration_notice() {
		if ( ! current_user_can( self::CAP ) || $this->is_configured() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'CWeb Form Protection with Turnstile for Elementor Forms is active but no keys are configured yet, so no form is protected.', 'cweb-form-protection-turnstile-elementor' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Add your keys', 'cweb-form-protection-turnstile-elementor' )
		);
	}

	/**
	 * Enqueue admin CSS on the settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cwebts-admin',
			CWEBTS_URL . 'assets/css/admin.css',
			array(),
			CWEBTS_VERSION
		);
	}
}
