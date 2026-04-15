<?php
/**
 * NV oOS Extended Cognition — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * asset enqueuing, and shortcode/hook registration for the
 * Extended Cognition Toolkit addon.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Extended Cognition Toolkit.
 *
 * @since 1.0.0
 */
class NV_oOS_Ext_Cognition {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_ext_cog_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin and register tools.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_ext_cog_is_base_active() ) {
			return;
		}

		// Register REST controller.
		NV_oOS_Ext_Cognition_REST::init();

		// Register tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );

		// Also support the Pro-style lazy loading hook.
		add_action( 'wp_mcp_ai_load_pro_tools', array( __CLASS__, 'load_tools' ) );
	}

	/**
	 * Check whether the addon is enabled in settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get addon settings with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled'             => true,
				'sensor_camera'       => true,
				'sensor_microphone'   => true,
				'sensor_screen'       => true,
				'sensor_motion'       => true,
				'guest_access'        => false,
				'store_captures'      => false,
				'retention_days'      => 7,
				'rate_limit'          => 10,
				'max_capture_size_kb' => 2048,
				'vision_model'        => 'auto',
				'allowed_roles'       => array( 'administrator', 'editor' ),
				'gdpr_consent'        => true,
			)
		);
	}

	/**
	 * Register tools with the oOS tool registry.
	 *
	 * @since 1.0.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$tools = array(
			'NV_oOS_Ext_Cog_Tool_Manage_Sensor_Permissions',
			'NV_oOS_Ext_Cog_Tool_Capture_Visual',
			'NV_oOS_Ext_Cog_Tool_Capture_Audio',
			'NV_oOS_Ext_Cog_Tool_Capture_Screen',
			'NV_oOS_Ext_Cog_Tool_Get_Motion_Context',
			'NV_oOS_Ext_Cog_Tool_Analyze_Sensory_Input',
			'NV_oOS_Ext_Cog_Tool_Remember_Sensory_Context',
		);

		foreach ( $tools as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Load tools via the Pro-style lazy loading hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_tools() {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry ) {
			return;
		}

		$tools = array(
			'NV_oOS_Ext_Cog_Tool_Manage_Sensor_Permissions',
			'NV_oOS_Ext_Cog_Tool_Capture_Visual',
			'NV_oOS_Ext_Cog_Tool_Capture_Audio',
			'NV_oOS_Ext_Cog_Tool_Capture_Screen',
			'NV_oOS_Ext_Cog_Tool_Get_Motion_Context',
			'NV_oOS_Ext_Cog_Tool_Analyze_Sensory_Input',
			'NV_oOS_Ext_Cog_Tool_Remember_Sensory_Context',
		);

		foreach ( $tools as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Require all tool class files.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$tools_dir = NVOOS_EXT_COG_PATH . 'includes/tools/';
		$files     = array(
			'class-nvoos-ext-cog-tool-manage-sensor-permissions.php',
			'class-nvoos-ext-cog-tool-capture-visual.php',
			'class-nvoos-ext-cog-tool-capture-audio.php',
			'class-nvoos-ext-cog-tool-capture-screen.php',
			'class-nvoos-ext-cog-tool-get-motion-context.php',
			'class-nvoos-ext-cog-tool-analyze-sensory-input.php',
			'class-nvoos-ext-cog-tool-remember-sensory-context.php',
		);

		foreach ( $files as $file ) {
			require_once $tools_dir . $file;
		}
	}

	/**
	 * Enqueue frontend assets when the oOS chat shortcode is present.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		global $post;

		if ( ! self::is_enabled() ) {
			return;
		}

		// Enqueue on any page that has the oOS chat shortcode.
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'wp_mcp_ai_chat' )
			&& ! has_shortcode( $post->post_content, 'mcp_ai_chat' )
			&& ! has_shortcode( $post->post_content, 'oOS_chat' ) ) {
			return;
		}

		self::enqueue_sensor_assets();
	}

	/**
	 * Enqueue assets in admin when the oOS chat interface may be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Only load on pages likely to embed the chat interface.
		$allowed_hooks = array( 'toplevel_page_mcp-ai', 'post.php', 'post-new.php' );
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		self::enqueue_sensor_assets();
	}

	/**
	 * Register and enqueue all sensor bridge JS/CSS assets.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_sensor_assets() {
		$settings = self::get_settings();

		wp_register_style(
			'nvoos-ext-cognition',
			NVOOS_EXT_COG_URL . 'assets/css/ext-cognition.css',
			array(),
			NVOOS_EXT_COG_VERSION
		);
		wp_enqueue_style( 'nvoos-ext-cognition' );

		// Modular JS files: motion → audio → camera → screen → bridge (depends on all).
		wp_register_script(
			'nvoos-ext-cog-motion',
			NVOOS_EXT_COG_URL . 'assets/js/ext-cognition-motion.js',
			array(),
			NVOOS_EXT_COG_VERSION,
			true
		);
		wp_register_script(
			'nvoos-ext-cog-audio',
			NVOOS_EXT_COG_URL . 'assets/js/ext-cognition-audio.js',
			array(),
			NVOOS_EXT_COG_VERSION,
			true
		);
		wp_register_script(
			'nvoos-ext-cog-camera',
			NVOOS_EXT_COG_URL . 'assets/js/ext-cognition-camera.js',
			array(),
			NVOOS_EXT_COG_VERSION,
			true
		);
		wp_register_script(
			'nvoos-ext-cog-screen',
			NVOOS_EXT_COG_URL . 'assets/js/ext-cognition-screen.js',
			array(),
			NVOOS_EXT_COG_VERSION,
			true
		);
		wp_register_script(
			'nvoos-ext-cog-bridge',
			NVOOS_EXT_COG_URL . 'assets/js/ext-cognition-sensor-bridge.js',
			array( 'nvoos-ext-cog-motion', 'nvoos-ext-cog-audio', 'nvoos-ext-cog-camera', 'nvoos-ext-cog-screen' ),
			NVOOS_EXT_COG_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-ext-cog-bridge',
			'nvOosExtCog',
			array(
				'restUrl'          => esc_url_raw( rest_url( 'mcp-ai/v1/ext-cog/' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'sensorCamera'     => ! empty( $settings['sensor_camera'] ),
				'sensorMicrophone' => ! empty( $settings['sensor_microphone'] ),
				'sensorScreen'     => ! empty( $settings['sensor_screen'] ),
				'sensorMotion'     => ! empty( $settings['sensor_motion'] ),
				'gdprConsent'      => ! empty( $settings['gdpr_consent'] ),
				'maxCaptureSizeKb' => absint( $settings['max_capture_size_kb'] ),
				'i18n'             => array(
					'permissionDenied' => __( 'Browser permission denied for this sensor.', 'nvoos-ext-cognition' ),
					'notSupported'     => __( 'This sensor is not supported in your browser.', 'nvoos-ext-cognition' ),
					'consentRequired'  => __( 'Sensory access requires your consent. The AI will use your camera/microphone/screen only when explicitly requested. Allow?', 'nvoos-ext-cognition' ),
					'captureFailed'    => __( 'Sensor capture failed.', 'nvoos-ext-cognition' ),
					'httpsRequired'    => __( 'Sensory tools require a secure (HTTPS) connection.', 'nvoos-ext-cognition' ),
				),
			)
		);

		wp_enqueue_script( 'nvoos-ext-cog-bridge' );
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! nvoos_ext_cog_is_base_active() ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'NV oOS Extended Cognition Toolkit requires the NV oOS base plugin to be active.', 'nvoos-ext-cognition' );
			echo '</p></div>';
		}
	}
}
