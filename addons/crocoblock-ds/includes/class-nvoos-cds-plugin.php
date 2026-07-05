<?php
/**
 * NV oOS Crocoblock DS — Core Plugin Class
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the Crocoblock Design System addon.
 *
 * Wires together the token registry, CSS generator, admin page, asset
 * enqueuing, and Crocoblock plugin integrations. All subsystems are
 * loaded lazily on first access.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Plugin {

	/**
	 * WordPress option key for serialised design tokens.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_cds_settings';

	/**
	 * Transient key used to cache the compiled CSS block.
	 *
	 * @var string
	 */
	const CSS_CACHE_KEY = 'nvoos_cds_compiled_css';

	/**
	 * Option key for the @property toggle.
	 *
	 * @var string
	 */
	const TYPED_PROPERTY_KEY = 'nvoos_cds_use_typed_properties';

	/**
	 * Whether the plugin has been initialised.
	 *
	 * @var bool
	 */
	private static $initialised = false;

	/**
	 * Lazy-loaded token registry instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_Token_Registry|null
	 */
	private static $token_registry;

	/**
	 * Lazy-loaded CSS generator instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_CSS_Generator|null
	 */
	private static $css_generator;

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

		// Front-end CSS injection.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Crocoblock_DS_Assets', 'enqueue_components' ), 25 );

		// Admin.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 20 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'admin_post_nvoos_cds_export_dtcg', array( __CLASS__, 'handle_dtcg_export' ) );
		}

		// Bust CSS cache when tokens change.
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'bust_css_cache' ), 10, 2 );

		// Bust CSS cache when @property toggle changes.
		add_action( 'update_option_' . self::TYPED_PROPERTY_KEY, array( __CLASS__, 'bust_css_cache' ), 10, 2 );

		// Crocoblock integrations — safe to call even if plugins aren't active.
		add_action( 'init', array( 'NV_oOS_Crocoblock_DS_Integration_JSF', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Crocoblock_DS_Integration_JetEngine', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Crocoblock_DS_Integration_JFB', 'init' ), 20 );
		add_action( 'init', array( 'NV_oOS_Crocoblock_DS_Integration_Elementor', 'init' ), 20 );

		// Activation / deactivation.
		register_activation_hook( NVOOS_CROCOBLOCK_DS_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( NVOOS_CROCOBLOCK_DS_FILE, array( __CLASS__, 'deactivate' ) );
	}

	// -----------------------------------------------------------------------
	// Subsystem accessors (lazy-loaded).
	// -----------------------------------------------------------------------

	/**
	 * Get the token registry singleton.
	 *
	 * @return NV_oOS_Crocoblock_DS_Token_Registry
	 */
	public static function token_registry() {
		if ( null === self::$token_registry ) {
			self::$token_registry = new NV_oOS_Crocoblock_DS_Token_Registry();
		}
		return self::$token_registry;
	}

	/**
	 * Get the CSS generator singleton.
	 *
	 * Respects the @property toggle.
	 *
	 * @return NV_oOS_Crocoblock_DS_CSS_Generator
	 */
	public static function css_generator() {
		if ( null === self::$css_generator ) {
			$use_typed = (bool) get_option( self::TYPED_PROPERTY_KEY, false );
			self::$css_generator = new NV_oOS_Crocoblock_DS_CSS_Generator(
				self::token_registry(),
				$use_typed
			);
		}
		return self::$css_generator;
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
	 * Reset the CSS generator so it picks up the latest toggle value.
	 *
	 * @return void
	 */
	public static function reset_css_generator() {
		self::$css_generator = null;
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

		wp_register_style( 'nvoos-cds-tokens', false, array(), NVOOS_CROCOBLOCK_DS_VERSION );
		wp_enqueue_style( 'nvoos-cds-tokens' );
		wp_add_inline_style( 'nvoos-cds-tokens', $css );
	}

	/**
	 * Register the admin settings page.
	 *
	 * @return void
	 */
	public static function register_admin_page() {
		$page = new NV_oOS_Crocoblock_DS_Admin_Page( self::token_registry() );
		$page->register();
	}

	/**
	 * Enqueue admin assets on the CDS settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'nvoos-cds' ) ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-cds-admin',
			NVOOS_CROCOBLOCK_DS_URL . 'assets/css/admin.css',
			array(),
			NVOOS_CROCOBLOCK_DS_VERSION
		);

		wp_enqueue_script(
			'nvoos-cds-admin',
			NVOOS_CROCOBLOCK_DS_URL . 'assets/js/token-preview.js',
			array(),
			NVOOS_CROCOBLOCK_DS_VERSION,
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
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nvoos-crocoblock-ds' ) );
		}

		check_admin_referer( 'nvoos_cds_dtcg_export', 'nvoos_cds_dtcg_nonce' );

		$exporter = new NV_oOS_Crocoblock_DS_DTCG_Exporter( self::token_registry() );
		$json     = $exporter->export( true );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="crocoblock-ds-tokens.dtcg.json"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON is safe to output in a download context.
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
	 * Activation: seed default tokens if no settings exist.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			$preset = new NV_oOS_Crocoblock_DS_Preset_Minimal();
			update_option( self::OPTION_KEY, $preset->token_values(), false );
		}
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
