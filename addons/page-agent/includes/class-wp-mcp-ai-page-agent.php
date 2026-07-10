<?php
/**
 * NV oOS Page Agent Addon — Core Class
 *
 * Handles activation checks, admin notices, script enqueuing,
 * REST route registration, tool registration, and settings
 * for the Page Agent addon.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 *
 * @link    https://github.com/alibaba/page-agent Upstream page-agent library (MIT)
 * @credit  Alibaba — page-agent browser automation library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Page Agent Addon.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Page_Agent {

	/**
	 * Script handle for the page-agent library bundle JS.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SCRIPT_HANDLE_LIB = 'nvoos-page-agent-lib';

	/**
	 * Script handle for the page-agent bridge JS.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SCRIPT_HANDLE_BRIDGE = 'nvoos-page-agent-bridge';

	/**
	 * WordPress option key for addon settings.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_page_agent_settings';

	/**
	 * Option key for whether Page Agent is enabled.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_ENABLED = 'nvoos_page_agent_enabled';

	/**
	 * Option key for the model slug used by Page Agent.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_MODEL = 'nvoos_page_agent_model';

	/**
	 * Option key for the language used by Page Agent.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_LANGUAGE = 'nvoos_page_agent_language';

	/**
	 * Option key for max agent steps per instruction.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_MAX_STEPS = 'nvoos_page_agent_max_steps';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		$instance = new self();
		$instance->register_hooks();
	}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Admin notices.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// Enqueue scripts on frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );

		// Register REST routes under our namespace.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Register tools with the NV oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_tools' ) );

		// Also support the Pro-style lazy loading hook.
		add_action( 'wp_mcp_ai_load_pro_tools', array( $this, 'load_tools' ) );

		// Admin settings and menu — delegating to the dedicated settings class.
		if ( is_admin() ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/admin/class-wp-mcp-ai-page-agent-settings.php';
			new WP_MCP_AI_Page_Agent_Settings();
		}

		// Register the shortcode and Elementor widget.
		require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-widget.php';
		new WP_MCP_AI_Page_Agent_Widget();

		// Load Pro features when Pro is active.
		if ( defined( 'WP_MCP_AI_PRO_ACTIVE' ) && WP_MCP_AI_PRO_ACTIVE ) {
			$this->load_pro_features();
		}
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show activation notice.
		if ( get_transient( 'nvoos_page_agent_activated' ) ) {
			delete_transient( 'nvoos_page_agent_activated' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'NV oOS Page Agent activated — AI-powered page control is now available.', 'nvoos-page-agent' );
			echo '</p></div>';
		}

		// Warn if base plugin is missing.
		if ( ! nvoos_page_agent_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Page Agent requires the NV oOS base plugin to be installed and active.', 'nvoos-page-agent' );
			echo '</p></div>';
		}
	}

	/**
	 * Check whether the addon is enabled in settings.
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
	 * Get addon settings with defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled'   => true,
				'model'     => 'gpt-4o-mini',
				'language'  => 'en-US',
				'max_steps' => 50,
			)
		);
	}

	/**
	 * Conditionally enqueue the Page Agent scripts.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.js' : '.min.js';

		// 1. Page Agent library (IIFE bundle, exposes window.PageAgent).
		wp_enqueue_script(
			self::SCRIPT_HANDLE_LIB,
			NVOOS_PAGE_AGENT_URL . 'assets/js/page-agent.bundle' . $suffix,
			array(),
			NVOOS_PAGE_AGENT_VERSION,
			true
		);

		// 2. NV oOS bridge (depends on the library and the chat widget).
		wp_enqueue_script(
			self::SCRIPT_HANDLE_BRIDGE,
			NVOOS_PAGE_AGENT_URL . 'assets/js/page-agent-bridge' . $suffix,
			array( 'wp-mcp-ai-chat', self::SCRIPT_HANDLE_LIB ),
			NVOOS_PAGE_AGENT_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE_BRIDGE,
			'wpMcpAiPageAgent',
			$this->build_config()
		);
	}

	/**
	 * Build the JavaScript configuration object for Page Agent.
	 *
	 * Leverages the base plugin's language model router to resolve
	 * the configured model's base URL and API key.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private function build_config() {
		$settings    = self::get_settings();
		$model_slug  = $settings['model'];
		$llm_config  = $this->get_llm_config( $model_slug );

		return array(
			'model'    => $llm_config['model'],
			'baseURL'  => $llm_config['base_url'],
			'apiKey'   => $llm_config['api_key'],
			'language' => $settings['language'],
			'maxSteps' => absint( $settings['max_steps'] ),
			'restUrl'  => rest_url( 'nvoos-page-agent/v1' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'tools'    => $this->get_exposed_tool_definitions(),
			'enabled'  => self::is_enabled(),
		);
	}

	/**
	 * Resolve LLM configuration from the base plugin's model router.
	 *
	 * Falls back to the OpenAI API key from options when the model router
	 * is not available or cannot resolve the requested model.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_slug The model slug to look up.
	 * @return array{model: string, base_url: string, api_key: string}
	 */
	private function get_llm_config( $model_slug ) {
		$default = array(
			'model'    => 'gpt-4o-mini',
			'base_url' => 'https://api.openai.com/v1',
			'api_key'  => get_option( 'wp_mcp_ai_openai_api_key', '' ),
		);

		// Try to resolve through the base plugin's model router.
		if ( class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$router = WP_MCP_AI_Language_Model_Router::instance();
			if ( $router && method_exists( $router, 'get_client' ) ) {
				// Resolve the model name through the router's model lists.
				$resolved_model = $this->resolve_model_name( $router, $model_slug );
				$default['model'] = $resolved_model;

				// Resolve API key from the router's provider configuration.
				$provider = $this->resolve_provider_for_model( $model_slug );
				$api_key  = $this->get_api_key_for_provider( $provider );
				if ( ! empty( $api_key ) ) {
					$default['api_key'] = $api_key;
				}

				// Resolve base URL for the provider.
				$base_url = $this->get_base_url_for_provider( $provider );
				if ( ! empty( $base_url ) ) {
					$default['base_url'] = $base_url;
				}
			}
		}

		return $default;
	}

	/**
	 * Resolve the actual model name through the router's draft/verification lists.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_MCP_AI_Language_Model_Router $router     The model router instance.
	 * @param string                           $model_slug The configured model slug.
	 * @return string The resolved model name.
	 */
	private function resolve_model_name( $router, $model_slug ) {
		if ( method_exists( $router, 'get_draft_model_for_provider' ) ) {
			$provider = $this->resolve_provider_for_model( $model_slug );
			$draft    = $router->get_draft_model_for_provider( $provider );
			if ( ! empty( $draft['model'] ) ) {
				return $draft['model'];
			}
		}
		return $model_slug;
	}

	/**
	 * Map a model slug to its provider identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_slug The model slug.
	 * @return string Provider identifier (openai, gemini, anthropic, ollama, etc.).
	 */
	private function resolve_provider_for_model( $model_slug ) {
		if ( false !== strpos( $model_slug, 'gpt-' ) || false !== strpos( $model_slug, 'o1' ) || false !== strpos( $model_slug, 'o3' ) ) {
			return 'openai';
		}
		if ( false !== strpos( $model_slug, 'gemini' ) ) {
			return 'gemini';
		}
		if ( false !== strpos( $model_slug, 'claude' ) ) {
			return 'anthropic';
		}
		if ( false !== strpos( $model_slug, 'deepseek' ) ) {
			return 'deepseek';
		}
		return 'openai'; // Default to OpenAI-compatible.
	}

	/**
	 * Get the API key for a given provider from WordPress options.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider The provider identifier.
	 * @return string The API key, or empty string.
	 */
	private function get_api_key_for_provider( $provider ) {
		$option_keys = array(
			'openai'      => 'wp_mcp_ai_openai_api_key',
			'gemini'      => 'wp_mcp_ai_gemini_api_key',
			'anthropic'   => 'wp_mcp_ai_anthropic_api_key',
			'ollama'      => '',
			'deepseek'    => 'wp_mcp_ai_deepseek_api_key',
			'openrouter'  => 'wp_mcp_ai_openrouter_api_key',
		);

		$key = isset( $option_keys[ $provider ] ) ? $option_keys[ $provider ] : '';
		if ( empty( $key ) ) {
			return '';
		}

		return (string) get_option( $key, '' );
	}

	/**
	 * Get the base URL for a given provider.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider The provider identifier.
	 * @return string The base URL, or empty string.
	 */
	private function get_base_url_for_provider( $provider ) {
		$base_urls = array(
			'openai'      => 'https://api.openai.com/v1',
			'gemini'      => 'https://generativelanguage.googleapis.com/v1beta',
			'anthropic'   => 'https://api.anthropic.com/v1',
			'ollama'      => get_option( 'wp_mcp_ai_ollama_base_url', 'http://localhost:11434/v1' ),
			'deepseek'    => 'https://api.deepseek.com/v1',
			'openrouter'  => 'https://openrouter.ai/api/v1',
		);

		return isset( $base_urls[ $provider ] ) ? $base_urls[ $provider ] : '';
	}

	/**
	 * Get tool definitions for the client-side bridge.
	 *
	 * Returns a filtered list of tool slugs that Page Agent can delegate to
	 * (tools that are safe to expose to the client-side bridge for execution
	 * through the REST endpoint).
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private function get_exposed_tool_definitions() {
		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry || ! method_exists( $registry, 'get_tools' ) ) {
			return array();
		}

		$all_tools   = $registry->get_tools();
		$definitions = array();

		foreach ( $all_tools as $slug => $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			// Only expose tools with 'read-only' capability flag.
			if ( method_exists( $tool, 'get_capability_flags' ) ) {
				$flags = $tool->get_capability_flags();
				if ( ! in_array( 'read-only', (array) $flags, true ) ) {
					continue;
				}
			}

			$definitions[] = array(
				'slug'        => $slug,
				'name'        => $tool->get_name(),
				'description' => $tool->get_description(),
			);
		}

		return $definitions;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-rest.php';
		$rest = new WP_MCP_AI_Page_Agent_REST();
		$rest->register_routes();
	}

	/**
	 * Register Page Agent tools with the oOS tool registry.
	 *
	 * Hooked to 'wp_mcp_ai_register_tools'.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry The tool registry instance.
	 * @return void
	 */
	public function register_tools( $registry ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$classes = self::get_tool_classes();
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Load tools via the Pro-style lazy loading hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_tools() {
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

		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * List of tool class names provided by this addon.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private static function get_tool_classes() {
		return array(
			'WP_MCP_AI_Tool_Page_Agent_Execute',
		);
	}

	/**
	 * Require all tool class files.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$dir = NVOOS_PAGE_AGENT_PATH . 'includes/tools/';
		require_once $dir . 'class-wp-mcp-ai-tool-page-agent-execute.php';
	}

	/**
	 * Load Pro features when the Pro addon is active.
	 *
	 * Gated by the WP_MCP_AI_PRO_ACTIVE constant.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function load_pro_features() {
		$pro_dir = NVOOS_PAGE_AGENT_PATH . 'includes/pro/';

		// Admin copilot (admin bar integration).
		$copilot_file = $pro_dir . 'class-wp-mcp-ai-page-agent-admin-copilot.php';
		if ( file_exists( $copilot_file ) ) {
			require_once $copilot_file;
			if ( class_exists( 'WP_MCP_AI_Page_Agent_Admin_Copilot' ) ) {
				new WP_MCP_AI_Page_Agent_Admin_Copilot();
			}
		}

		// Workflow recorder.
		$workflow_file = $pro_dir . 'class-wp-mcp-ai-page-agent-workflow-recorder.php';
		if ( file_exists( $workflow_file ) ) {
			require_once $workflow_file;
		}
	}
}
