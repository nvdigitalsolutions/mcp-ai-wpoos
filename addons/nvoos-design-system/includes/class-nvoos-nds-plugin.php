<?php
/**
 * NV oOS Design System — Core Plugin Class
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Design System addon.
 *
 * Wires together the token registry, CSS generator, email template module,
 * admin pages, asset enqueuing, AI tools, and the Crocoblock/Elementor
 * integrations. All subsystems are loaded lazily on first access.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Plugin {

	/**
	 * WordPress option key for serialised design tokens.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_nds_settings';

	/**
	 * Transient key used to cache the compiled CSS block.
	 *
	 * @var string
	 */
	const CSS_CACHE_KEY = 'nvoos_nds_compiled_css';

	/**
	 * Option key for the @property toggle.
	 *
	 * @var string
	 */
	const TYPED_PROPERTY_KEY = 'nvoos_nds_use_typed_properties';

	/**
	 * Option key for the legacy `--cds-*` / `.cds-*` alias toggle.
	 *
	 * Defaults to enabled through the v0.x transition releases so existing
	 * Crocoblock builds keep working after the rename.
	 *
	 * @var string
	 */
	const LEGACY_ALIAS_KEY = 'nvoos_nds_legacy_aliases';

	/**
	 * Option key for the active email template slug.
	 *
	 * @var string
	 */
	const EMAIL_ACTIVE_TEMPLATE_KEY = 'nvoos_nds_active_email_template';

	/**
	 * Option key for global email settings (admin email override).
	 *
	 * @var string
	 */
	const EMAIL_SETTINGS_KEY = 'nvoos_nds_email_settings';

	/**
	 * Option flag marking built-in email templates as seeded.
	 *
	 * @var string
	 */
	const EMAIL_SEEDED_KEY = 'nvoos_nds_email_seeded';

	/**
	 * Transient key prefix for the pending DTCG import report.
	 *
	 * The full key is suffixed with the current user ID so reports never
	 * leak between admins.
	 *
	 * @var string
	 */
	const IMPORT_REPORT_KEY = 'nvoos_nds_dtcg_import_report';

	/**
	 * Transient key prefix for admin notices carried across a redirect.
	 *
	 * The full key is suffixed with the current user ID so notices never
	 * leak between admins.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const ADMIN_NOTICE_KEY = 'nvoos_nds_admin_notices';

	/**
	 * Legacy option keys migrated on first load.
	 *
	 * @var array<string, string>
	 */
	const LEGACY_OPTION_MAP = array(
		'nvoos_cds_settings'             => self::OPTION_KEY,
		'nvoos_cds_use_typed_properties' => self::TYPED_PROPERTY_KEY,
		'nvoos_cds_elementor_sync'       => 'nvoos_nds_elementor_sync',
	);

	/**
	 * Option key for the WooCommerce email rebrand toggle.
	 *
	 * @var string
	 */
	const WC_REBRAND_KEY = 'nvoos_nds_wc_rebrand';

	/**
	 * Option key for the email wrapper mode.
	 *
	 * @var string
	 */
	const WRAP_MODE_KEY = 'nvoos_nds_wrap_mode';

	/**
	 * Option key for the recent email send log.
	 *
	 * @var string
	 */
	const EMAIL_LOG_KEY = 'nvoos_nds_email_log';

	/**
	 * Whether the plugin has been initialised.
	 *
	 * @var bool
	 */
	private static $initialised = false;

	/**
	 * Lazy-loaded token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry|null
	 */
	private static $token_registry;

	/**
	 * Lazy-loaded CSS generator instance.
	 *
	 * @var NV_oOS_Design_System_CSS_Generator|null
	 */
	private static $css_generator;

	/**
	 * Lazy-loaded email auditor instance.
	 *
	 * @var NV_oOS_Design_System_Email_Auditor|null
	 */
	private static $email_auditor;

	/**
	 * Lazy-loaded email generator instance.
	 *
	 * @var NV_oOS_Design_System_Email_Generator|null
	 */
	private static $email_generator;

	/**
	 * Register all WordPress hooks.
	 *
	 * Called once on `plugins_loaded` at priority 5.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialised ) {
			return;
		}
		self::$initialised = true;

		// One-way migration from the old Crocoblock DS option keys.
		self::maybe_migrate_options();

		// Front-end CSS injection.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Design_System_Assets', 'enqueue_components' ), 25 );

		// Email template wrapper — global, works on any site.
		add_filter( 'wp_mail', array( 'NV_oOS_Design_System_Email_Wrapper', 'wrap' ), 99 );

		// Admin.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 20 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'admin_post_nvoos_nds_export_dtcg', array( __CLASS__, 'handle_dtcg_export' ) );
			add_action( 'admin_post_nvoos_nds_import_dtcg', array( __CLASS__, 'handle_dtcg_import' ) );
			add_action( 'admin_post_nvoos_nds_email', array( __CLASS__, 'handle_email_admin_post' ) );
			add_action( 'admin_post_nvoos_nds_brand', array( __CLASS__, 'handle_brand_post' ) );
			add_action( 'admin_post_nvoos_nds_export_brand', array( __CLASS__, 'handle_export_brand' ) );
			add_action( 'admin_post_nvoos_nds_export_guide', array( __CLASS__, 'handle_export_guide' ) );
			add_action( 'admin_post_nvoos_nds_export_theme_json', array( __CLASS__, 'handle_export_theme_json' ) );

			// Legacy export action from the Crocoblock DS addon.
			add_action( 'admin_post_nvoos_cds_export_dtcg', array( __CLASS__, 'handle_dtcg_export' ) );
		}

		// Bust CSS cache when tokens change.
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'bust_css_cache' ), 10, 2 );

		// Bust CSS cache when @property toggle changes.
		add_action( 'update_option_' . self::TYPED_PROPERTY_KEY, array( __CLASS__, 'bust_css_cache' ), 10, 2 );

		// Global Styles bridge (runtime `theme.json` merge; no file writes).
		add_action( 'init', array( 'NV_oOS_Design_System_Global_Styles', 'init' ), 1 );

		// Email template CPT + built-in seeding.
		add_action( 'init', array( 'NV_oOS_Design_System_Email_Template_CPT', 'register' ), 5 );
		add_action( 'init', array( 'NV_oOS_Design_System_Email_Template_CPT', 'migrate_legacy' ), 20 );
		add_action( 'init', array( 'NV_oOS_Design_System_Email_Seeder', 'maybe_seed' ), 10 );

		// Crocoblock integrations — safe to call even if plugins aren't active.
		add_action( 'init', array( 'NV_oOS_Design_System_Integration_JSF', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Design_System_Integration_JetEngine', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Design_System_Integration_JFB', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Design_System_Integration_Elementor', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Design_System_Integration_WooCommerce', 'init' ), 20 );

		// AI email-template tools — registered when the NV oOS core fires
		// its tool-registration action (the addon itself stays standalone).
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );

		// Activation / deactivation.
		register_activation_hook( NVOOS_DESIGN_SYSTEM_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( NVOOS_DESIGN_SYSTEM_FILE, array( __CLASS__, 'deactivate' ) );
	}

	// -----------------------------------------------------------------------
	// Subsystem accessors (lazy-loaded).
	// -----------------------------------------------------------------------

	/**
	 * Get the token registry singleton.
	 *
	 * @return NV_oOS_Design_System_Token_Registry
	 */
	public static function token_registry() {
		if ( null === self::$token_registry ) {
			self::$token_registry = new NV_oOS_Design_System_Token_Registry();
		}
		return self::$token_registry;
	}

	/**
	 * Get the CSS generator singleton.
	 *
	 * Respects the @property and legacy-alias toggles.
	 *
	 * @return NV_oOS_Design_System_CSS_Generator
	 */
	public static function css_generator() {
		if ( null === self::$css_generator ) {
			$use_typed           = (bool) get_option( self::TYPED_PROPERTY_KEY, false );
			self::$css_generator = new NV_oOS_Design_System_CSS_Generator(
				self::token_registry(),
				$use_typed,
				false
			);
		}
		return self::$css_generator;
	}

	/**
	 * Get the email auditor singleton.
	 *
	 * @return NV_oOS_Design_System_Email_Auditor
	 */
	public static function email_auditor() {
		if ( null === self::$email_auditor ) {
			self::$email_auditor = new NV_oOS_Design_System_Email_Auditor();
		}
		return self::$email_auditor;
	}

	/**
	 * Get the email generator singleton.
	 *
	 * @return NV_oOS_Design_System_Email_Generator
	 */
	public static function email_generator() {
		if ( null === self::$email_generator ) {
			self::$email_generator = new NV_oOS_Design_System_Email_Generator();
		}
		return self::$email_generator;
	}

	/**
	 * Check whether typed @property output is enabled.
	 *
	 * @return bool
	 */
	public static function is_typed_properties_enabled() {
		return (bool) get_option( self::TYPED_PROPERTY_KEY, false );
	}

	/**
	 * Check whether the legacy `--cds-*` / `.cds-*` aliases are enabled.
	 *
	 * @deprecated 1.0.1 The transition aliases were removed. Retained for
	 *                   third-party callers; always returns false.
	 *
	 * @return bool
	 */
	public static function is_legacy_aliases_enabled() {
		return false;
	}

	/**
	 * Reset the CSS generator so it picks up the latest toggle values.
	 *
	 * @return void
	 */
	public static function reset_css_generator() {
		self::$css_generator = null;
	}

	// -----------------------------------------------------------------------
	// Migration.
	// -----------------------------------------------------------------------

	/**
	 * Migrate legacy Crocoblock DS options on first load.
	 *
	 * One-way and non-destructive: the new key is only written when it is
	 * still empty and the legacy key exists.
	 *
	 * @return void
	 */
	private static function maybe_migrate_options() {
		foreach ( self::LEGACY_OPTION_MAP as $legacy_key => $new_key ) {
			if ( false !== get_option( $new_key, false ) ) {
				continue;
			}

			$legacy = get_option( $legacy_key, false );
			if ( false !== $legacy ) {
				update_option( $new_key, $legacy, false );
			}
		}

		// Wipe the legacy compiled-CSS transient so the alias-aware generator
		// regenerates the block on the next request.
		delete_transient( 'nvoos_cds_compiled_css' );
	}

	// -----------------------------------------------------------------------
	// Hook callbacks.
	// -----------------------------------------------------------------------

	/**
	 * Enqueue the compiled CSS custom properties on the front end.
	 *
	 * Output is injected as an inline style so that CSS variables are
	 * available as early as possible in the cascade.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_styles() {
		$css = self::get_compiled_css();
		if ( '' === $css ) {
			return;
		}

		wp_register_style( 'nvoos-nds-tokens', false, array(), NVOOS_DESIGN_SYSTEM_VERSION );
		wp_enqueue_style( 'nvoos-nds-tokens' );
		wp_add_inline_style( 'nvoos-nds-tokens', $css );
	}

	/**
	 * Register the admin settings page.
	 *
	 * @return void
	 */
	public static function register_admin_page() {
		$page = new NV_oOS_Design_System_Admin_Page( self::token_registry() );
		$page->register();
	}

	/**
	 * Enqueue admin assets on the NDS settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'nvoos-nds' ) ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-nds-admin',
			NVOOS_DESIGN_SYSTEM_URL . 'assets/css/admin.css',
			array(),
			NVOOS_DESIGN_SYSTEM_VERSION
		);

		wp_enqueue_script(
			'nvoos-nds-admin',
			NVOOS_DESIGN_SYSTEM_URL . 'assets/js/token-preview.js',
			array(),
			NVOOS_DESIGN_SYSTEM_VERSION,
			true
		);

		wp_enqueue_script(
			'nvoos-nds-email-picker',
			NVOOS_DESIGN_SYSTEM_URL . 'assets/js/email-picker.js',
			array(),
			NVOOS_DESIGN_SYSTEM_VERSION,
			true
		);
	}

	/**
	 * Handle DTCG JSON export download.
	 *
	 * Triggered via admin-post.php when the user clicks "Export DTCG".
	 *
	 * @return void
	 */
	public static function handle_dtcg_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_dtcg_export', 'nvoos_nds_dtcg_nonce' );

		$exporter = new NV_oOS_Design_System_DTCG_Exporter( self::token_registry() );
		$json     = $exporter->export( true );

		header( 'Content-Type: application/design-tokens+json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $exporter->get_filename() . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is safe to output in a download context.
		echo $json;
		exit;
	}

	/**
	 * Handle DTCG JSON import submission.
	 *
	 * Triggered via admin-post.php when the user submits the "Import DTCG"
	 * form on the Tokens tab. The importer's report is stored in a
	 * short-lived, user-scoped transient and rendered on the next page load.
	 *
	 * @return void
	 */
	public static function handle_dtcg_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_dtcg_import', 'nvoos_nds_dtcg_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- raw JSON, parsed by the importer; nonce verified above.
		$json = isset( $_POST['nds_dtcg_json'] ) ? wp_unslash( $_POST['nds_dtcg_json'] ) : '';

		$importer = new NV_oOS_Design_System_DTCG_Importer( self::token_registry() );
		$report   = $importer->import( $json );

		set_transient( self::IMPORT_REPORT_KEY . '_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

		$redirect = isset( $_REQUEST['_wp_http_referer'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['_wp_http_referer'] ) )
			: admin_url( 'options-general.php?page=nvoos-nds-settings&tab=tokens' );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Read (and clear) the pending DTCG import report for the current user.
	 *
	 * Used by the admin page to render the import result after the
	 * admin-post redirect.
	 *
	 * @return array|null Import report, or null when none is pending.
	 */
	public static function consume_import_report() {
		$key    = self::IMPORT_REPORT_KEY . '_' . get_current_user_id();
		$report = get_transient( $key );
		delete_transient( $key );

		return is_array( $report ) ? $report : null;
	}

	/**
	 * Handle the brand-kit form submission.
	 *
	 * @return void
	 */
	public static function handle_brand_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_brand', 'nvoos_nds_brand_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field-by-field in Brand_Kit::save().
		$data = isset( $_POST['nvoos_nds_brand'] ) ? wp_unslash( $_POST['nvoos_nds_brand'] ) : array();
		NV_oOS_Design_System_Brand_Kit::save( is_array( $data ) ? $data : array() );

		wp_safe_redirect( admin_url( 'options-general.php?page=nvoos-nds-settings&tab=brand&nds_brand_saved=1' ) );
		exit;
	}

	/**
	 * Download the portable brand kit (identity + tokens + theme.json).
	 *
	 * @return void
	 */
	public static function handle_export_brand() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_export_brand', 'nvoos_nds_brand_nonce' );

		$json = wp_json_encode(
			NV_oOS_Design_System_Brand_Kit::export(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="nvoos-brand-kit.json"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		echo $json;
		exit;
	}

	/**
	 * Download a printable brand guide.
	 *
	 * @return void
	 */
	public static function handle_export_guide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_export_guide', 'nvoos_nds_brand_nonce' );

		$html = NV_oOS_Design_System_Brand_Kit::export_guide_html();

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="nvoos-brand-guide.html"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained, escaped HTML download.
		echo $html;
		exit;
	}

	/**
	 * Download the generated `theme.json` partial.
	 *
	 * @return void
	 */
	public static function handle_export_theme_json() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_export_theme_json', 'nvoos_nds_theme_json_nonce' );

		$json = NV_oOS_Design_System_Global_Styles::export_json();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="nvoos-theme.json"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		echo $json;
		exit;
	}

	/**
	 * Handle email-tab admin actions (apply, settings, test send, generate,
	 * delete, import).
	 *
	 * @return void
	 */
	public static function handle_email_admin_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-design-system' ) );
		}

		check_admin_referer( 'nvoos_nds_email', 'nvoos_nds_email_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$action = isset( $_POST['nds_action'] ) ? sanitize_key( wp_unslash( $_POST['nds_action'] ) ) : '';

		$admin = new NV_oOS_Design_System_Email_Admin();

		switch ( $action ) {
			case 'apply':
				$admin->handle_apply();
				break;

			case 'settings':
				$admin->handle_settings();
				break;

			case 'test_send':
				$admin->handle_test_send();
				break;

			case 'generate':
				$admin->handle_generate();
				break;

			case 'delete':
				$admin->handle_delete();
				break;

			case 'import':
				$admin->handle_import();
				break;

			case 'wc_rebrand':
				$admin->handle_wc_rebrand();
				break;

			case 'paper_mirror':
				$admin->handle_paper_mirror();
				break;

			case 'paper_import':
				$admin->handle_paper_import();
				break;

			case 'wrap_mode':
				$admin->handle_wrap_mode();
				break;

			case 'clear_log':
				$admin->handle_clear_log();
				break;

			default:
				break;
		}

		$redirect = isset( $_REQUEST['_wp_http_referer'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['_wp_http_referer'] ) )
			: admin_url( 'options-general.php?page=nvoos-nds-settings&tab=emails' );

		self::redirect_with_settings_errors( $redirect );
	}

	/**
	 * Redirect after an admin-post action, carrying notices across the request.
	 *
	 * `add_settings_error()` only appends to the in-request global, and core
	 * only replays the `settings_errors` transient when the URL carries
	 * `settings-updated`, so notices are stored under a plugin-scoped key and
	 * rendered by the admin page on the next load.
	 *
	 * @since 1.0.1
	 *
	 * @param string $redirect Destination URL.
	 * @return void
	 */
	private static function redirect_with_settings_errors( $redirect ) {
		if ( function_exists( 'get_settings_errors' ) ) {
			$errors = get_settings_errors();
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				set_transient( self::ADMIN_NOTICE_KEY . '_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Read (and clear) the pending admin notices for the current user.
	 *
	 * @since 1.0.1
	 *
	 * @return array<int, array{setting?: string, code?: string, message?: string, type?: string}>
	 */
	public static function consume_admin_notices() {
		$key     = self::ADMIN_NOTICE_KEY . '_' . get_current_user_id();
		$notices = get_transient( $key );
		delete_transient( $key );

		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Register the addon's AI tools with the NV oOS tool registry.
	 *
	 * Hooked to `wp_mcp_ai_register_tools`. Guarded so the addon still works
	 * standalone (without the NV oOS base plugin active).
	 *
	 * @param object $registry Tool registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register_tool' ) ) {
			return;
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			return;
		}

		$tools = array(
			'NV_oOS_Design_System_Tool_List_Email_Templates',
			'NV_oOS_Design_System_Tool_Preview_Email_Template',
			'NV_oOS_Design_System_Tool_Audit_Email_Template',
			'NV_oOS_Design_System_Tool_Set_Active_Email_Template',
			'NV_oOS_Design_System_Tool_Test_Send_Email',
			'NV_oOS_Design_System_Tool_Generate_Email_Template',
			'NV_oOS_Design_System_Tool_Export_Email_Template',
			'NV_oOS_Design_System_Tool_Import_Email_Template',
		);

		foreach ( $tools as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Delete the CSS transient when token values are saved.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public static function bust_css_cache( $old_value, $new_value ) {
		delete_transient( self::CSS_CACHE_KEY );
		self::reset_css_generator();
	}

	// -----------------------------------------------------------------------
	// Lifecycle.
	// -----------------------------------------------------------------------

	/**
	 * Activation: seed default tokens and built-in email templates.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			$preset = new NV_oOS_Design_System_Preset_Minimal();
			update_option( self::OPTION_KEY, $preset->token_values(), false );
		}

		NV_oOS_Design_System_Email_Seeder::seed();
	}

	/**
	 * Deactivation: clean up transients. (Options are preserved.)
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( self::CSS_CACHE_KEY );
	}

	// -----------------------------------------------------------------------
	// Internal helpers.
	// -----------------------------------------------------------------------

	/**
	 * Return the compiled `:root {}` CSS block, from cache if available.
	 *
	 * @return string
	 */
	private static function get_compiled_css() {
		$css = get_transient( self::CSS_CACHE_KEY );
		if ( false !== $css ) {
			return $css;
		}

		$css = self::css_generator()->generate();
		set_transient( self::CSS_CACHE_KEY, $css, DAY_IN_SECONDS );

		return $css;
	}
}
