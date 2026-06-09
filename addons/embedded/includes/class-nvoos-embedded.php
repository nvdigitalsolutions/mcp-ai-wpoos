<?php
/**
 * NV oOS Embedded AI Addon — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * provider bridge, asset enqueuing, and WebChat integration
 * for the embedded AI addon.
 *
 * @package NV_oOS_Embedded
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Embedded AI Addon.
 *
 * @since 0.1.0
 *
 * phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Addon class follows NV_oOS_ naming convention.
 * phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid -- Matches NV_oOS_Algorave convention.
 */
class NV_oOS_Embedded {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_embedded_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin and register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_embedded_is_base_active() ) {
			return;
		}

		// Register embedded provider bridge with the language model router.
		add_filter( 'wp_mcp_ai_embedded_chat_completion', array( __CLASS__, 'handle_embedded_chat_completion' ), 10, 3 );

		// Register embedded script enqueue hook.
		add_action( 'wp_mcp_ai_enqueue_embedded_scripts', array( __CLASS__, 'enqueue_embedded_scripts' ), 10, 4 );

		// Register embedded provider availability check.
		add_filter( 'wp_mcp_ai_is_embedded_provider_available', array( __CLASS__, 'is_embedded_provider_available' ) );

		// Register embedded server model check.
		add_filter( 'wp_mcp_ai_is_embedded_server_model', array( __CLASS__, 'is_embedded_server_model' ), 10, 2 );

		// Register embedded config endpoint filter.
		add_filter( 'wp_mcp_ai_embedded_client_config', array( __CLASS__, 'get_embedded_client_config' ), 10, 2 );

		// Register WebLLM script registration.
		add_action( 'wp_mcp_ai_register_embedded_scripts', array( __CLASS__, 'register_embedded_scripts' ) );

		// Load webchat if enabled.
		self::maybe_load_webchat();

		// Register webchat tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_webchat_tools' ) );
	}

	/**
	 * Show admin notices when base plugin is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( nvoos_embedded_is_base_active() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'NV oOS Embedded AI addon requires the NV oOS (Open Operator System) base plugin to be active.', 'nvoos-embedded' )
		);
	}

	/**
	 * Check whether the embedded addon is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Handle embedded provider chat completion via filter.
	 *
	 * Bridges the language model router's `wp_mcp_ai_embedded_chat_completion` filter
	 * to the actual embedded client implementation.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result   Current result (null if not yet handled).
	 * @param array $messages Chat messages array.
	 * @param array $options  Request options.
	 * @return array|WP_Error Chat completion result.
	 */
	public static function handle_embedded_chat_completion( $result, $messages, $options ) {
		// If another handler already returned a result, don't override.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			return new WP_Error(
				'embedded_client_unavailable',
				__( 'Embedded LLM client class is not available.', 'nvoos-embedded' )
			);
		}

		$client = new WP_MCP_AI_Embedded_Client();
		return $client->create_chat_completion( $messages, $options );
	}

	/**
	 * Check if embedded provider is available.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $available Current availability status.
	 * @return bool True if embedded provider is available.
	 */
	public static function is_embedded_provider_available( $available ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress filter callback.
		return true;
	}

	/**
	 * Check if a model slug is a server-side embedded model.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $is_server Current server model status.
	 * @param string $model     Model slug to check.
	 * @return bool True if model is a server-side GGUF model.
	 */
	public static function is_embedded_server_model( $is_server, $model ) {
		if ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			return WP_MCP_AI_Embedded_Client::is_server_model_slug( $model );
		}
		return $is_server;
	}

	/**
	 * Get embedded client configuration for REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param array $config       Current config array.
	 * @param int   $assistant_id Assistant ID.
	 * @return array Configuration array.
	 */
	public static function get_embedded_client_config( $config, $assistant_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter callback.
		// Config is already built by the REST chat controller.
		return $config;
	}

	/**
	 * Register embedded LLM scripts (WebLLM loader, client, tool adapter, function calling).
	 *
	 * Hooked into wp_mcp_ai_register_embedded_scripts to let the addon register
	 * scripts from its own assets directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_embedded_scripts() {
		$version = NVOOS_EMBEDDED_VERSION;

		// Use minified assets in production, source files when SCRIPT_DEBUG is on.
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

		// Core WebLLM loader.
		if ( ! wp_script_is( 'webllm-loader', 'registered' ) ) {
			wp_register_script(
				'webllm-loader',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-loader' . $suffix,
				array(),
				$version,
				true
			);
		}

		// Embedded LLM client.
		if ( ! wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-embedded-llm-client',
				NVOOS_EMBEDDED_URL . 'assets/js/embedded-llm-client' . $suffix,
				array( 'webllm-loader' ),
				$version,
				true
			);
		}

		// Tool adapter.
		if ( ! wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-webllm-tool-adapter',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-tool-adapter' . $suffix,
				array(),
				$version,
				true
			);
		}

		// Function calling client.
		if ( ! wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-webllm-function-calling',
				NVOOS_EMBEDDED_URL . 'assets/js/webllm-function-calling-client' . $suffix,
				array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-webllm-tool-adapter' ),
				$version,
				true
			);
		}
	}

	/**
	 * Enqueue embedded LLM scripts when needed.
	 *
	 * Called via the wp_mcp_ai_enqueue_embedded_scripts action from the shortcode.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $needs_embedded Whether embedded provider scripts are needed.
	 * @param bool $has_tools      Whether the assistant has tools configured.
	 * @param bool $has_system_prompt Whether the assistant has a system prompt.
	 * @param bool $has_knowledge  Whether the assistant has knowledge files.
	 * @return void
	 */
	public static function enqueue_embedded_scripts( $needs_embedded, $has_tools, $has_system_prompt, $has_knowledge ) {
		if ( ! $needs_embedded ) {
			return;
		}

		wp_enqueue_script( 'webllm-loader' );
		wp_enqueue_script( 'wp-mcp-ai-embedded-llm-client' );

		if ( $has_tools || $has_system_prompt || $has_knowledge ) {
			wp_enqueue_script( 'wp-mcp-ai-webllm-tool-adapter' );
			wp_enqueue_script( 'wp-mcp-ai-webllm-function-calling' );
		}
	}

	/**
	 * Conditionally load webchat integration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function maybe_load_webchat() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_webchat_integration'] ) ) {
			return;
		}

		// Load webchat CPT.
		require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-cpt.php';
		WP_MCP_AI_WebChat_CPT::init();

		// Load JetEngine WebChat Messages CCT if JetEngine is active.
		if ( function_exists( 'jet_engine' ) ) {
			require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-jetengine-webchat-messages-cct.php';
			WP_MCP_AI_JetEngine_WebChat_Messages_CCT::bootstrap();
		}

		// Load WebChat Signaling REST Controller.
		require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-signaling-rest-controller.php';
		add_action(
			'rest_api_init',
			function () {
				$controller = new WP_MCP_AI_WebChat_Signaling_REST_Controller();
				$controller->register_routes();
			}
		);

		// Load WebChat Settings page.
		if ( is_admin() ) {
			$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
			if ( ! $is_base ) {
				require_once NVOOS_EMBEDDED_PATH . 'includes/webchat/class-wp-mcp-ai-webchat-settings-page.php';
			}
		}
	}

	/**
	 * Register webchat tools with the oOS tool registry.
	 *
	 * @since 0.1.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_webchat_tools( $registry ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_webchat_integration'] ) ) {
			return;
		}

		$tool_dir = NVOOS_EMBEDDED_PATH . 'includes/webchat/tools/';

		$tool_files = array(
			'class-wp-mcp-ai-tool-create-webchat-room.php',
			'class-wp-mcp-ai-tool-get-webchat-room.php',
			'class-wp-mcp-ai-tool-list-webchat-rooms.php',
			'class-wp-mcp-ai-tool-get-webchat-status.php',
			'class-wp-mcp-ai-tool-get-webchat-messages.php',
			'class-wp-mcp-ai-tool-save-webchat-message.php',
			'class-wp-mcp-ai-tool-send-webchat-message.php',
		);

		foreach ( $tool_files as $file ) {
			$path = $tool_dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		$tool_classes = array(
			'WP_MCP_AI_Tool_Create_WebChat_Room',
			'WP_MCP_AI_Tool_Get_WebChat_Room',
			'WP_MCP_AI_Tool_List_WebChat_Rooms',
			'WP_MCP_AI_Tool_Get_WebChat_Status',
			'WP_MCP_AI_Tool_Get_WebChat_Messages',
			'WP_MCP_AI_Tool_Save_WebChat_Message',
			'WP_MCP_AI_Pro_Tool_Send_WebChat_Message',
		);

		foreach ( $tool_classes as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$tool = new $class_name();
				$registry->register_tool( $tool );
			}
		}
	}
}
