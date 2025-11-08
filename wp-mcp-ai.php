<?php
/**
 * Plugin Name: WP Open Operator System
 * Plugin URI: https://github.com/nvdigitalsolutions/wp-mcp-ai
 * Description: Core AI Assistant framework for WordPress and JetEngine, using OpenAI GPT models.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * Text Domain: wp-mcp-ai
 * Network: true
 *
 * @package WP_MCP_AI
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check PHP version compatibility before loading any classes.
 * 
 * This plugin requires PHP 7.4 or later. On older PHP versions, class files
 * will fail to parse with syntax errors like "unexpected token 'private'".
 * We check the version early to provide a clear error message instead of
 * cryptic parse errors.
 */
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	/**
	 * Display admin notice for PHP version incompatibility.
	 */
	function wp_mcp_ai_php_version_notice() {
		$message = sprintf(
			/* translators: 1: Current PHP version, 2: Required PHP version */
			__( '<strong>WP Open Operator System</strong> requires PHP version %2$s or higher. You are running PHP version %1$s. Please contact your hosting provider to upgrade PHP.', 'wp-mcp-ai' ),
			PHP_VERSION,
			'7.4.0'
		);
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}
	add_action( 'admin_notices', 'wp_mcp_ai_php_version_notice' );
	
	/**
	 * Prevent plugin activation on incompatible PHP versions.
	 */
	function wp_mcp_ai_deactivate_self() {
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
	add_action( 'admin_init', 'wp_mcp_ai_deactivate_self' );
	
	// Stop execution - don't load any class files that will cause parse errors.
	return;
}

if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
	define( 'WP_MCP_AI_VERSION', '1.0.0' );
}
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WP_MCP_AI_URL' ) ) {
	define( 'WP_MCP_AI_URL', plugin_dir_url( __FILE__ ) );
}

// Load Composer dependencies when available.
if ( file_exists( WP_MCP_AI_PATH . 'vendor/autoload.php' ) ) {
	require_once WP_MCP_AI_PATH . 'vendor/autoload.php';
}

if ( ! function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
	/**
	 * Retrieve the capability required to access the chat interface.
	 *
	 * Site owners can filter the returned capability to relax access controls.
	 * For example, allow subscribers (with the `read` capability) or even
	 * unauthenticated visitors by returning `'public'` or an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID, when known.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_required_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );
		$context      = $context ? sanitize_key( $context ) : 'general';

		/**
		 * Filters the capability required to use the front-end chat interface.
		 *
		 * Returning `'public'`, `false`, or an empty string disables the capability
		 * check, making the chat available to all visitors who satisfy the
		 * authentication requirements.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability  Capability required to access the chat. Defaults to `edit_posts`.
		 * @param int    $assistant_id Assistant post ID, when available.
		 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
		 */
		$capability = apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id, $context );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return $capability;
	}
}

if ( ! function_exists( 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	/**
	 * Provide a fallback Crawl4AI base URL from the environment when available.
	 *
	 * @param string $base_url Base URL stored in the plugin settings.
	 * @param array  $settings Entire plugin settings array.
	 * @param array  $context  Execution context passed to the tool.
	 * @return string
	 */
	function wp_mcp_ai_filter_crawl4ai_base_url( $base_url, $settings, $context ) {
		if ( ! empty( $base_url ) ) {
			return $base_url;
		}

		if ( defined( 'WP_MCP_AI_CRAWL4AI_BASE_URL' ) && WP_MCP_AI_CRAWL4AI_BASE_URL ) {
			return WP_MCP_AI_CRAWL4AI_BASE_URL;
		}

		$environment_candidates = array(
			'WP_MCP_AI_CRAWL4AI_BASE_URL',
			'CRAWL4AI_BASE_URL',
		);

		foreach ( $environment_candidates as $env_key ) {
			$candidate = getenv( $env_key );
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return $candidate;
			}
		}

		return $base_url;
	}
}

if ( ! has_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	add_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url', 5, 3 );
}

if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
	/**
	 * Check if base version mode is enabled.
	 *
	 * Base version is enabled by default. To disable it and use the full version,
	 * add this to wp-config.php: define( 'WP_MCP_AI_BASE_VERSION', false );
	 *
	 * @return bool Whether base version mode is active.
	 */
	function wp_mcp_ai_is_base_version() {
		return ! defined( 'WP_MCP_AI_BASE_VERSION' ) || WP_MCP_AI_BASE_VERSION;
	}
}

// Start output buffering early to catch any warnings/notices from includes.
// Suppress any output that could break JSON responses later.
// Skip buffering during Elementor AJAX requests and editor page loads to avoid interfering with Elementor operations.
$is_ajax_request = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
	|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );
$is_elementor_ajax = false;
$is_elementor_editor = false;

// Check for Elementor AJAX requests.
if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking action name, not processing data.
	$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
	$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
}

// Check for Elementor editor page loads.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
	$get_action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
	$is_elementor_editor = ( 'elementor' === $get_action );
}

$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

if ( ! $skip_buffering ) {
	if ( ! @ob_start() ) {
		ob_start(); // Fallback without error suppression.
	}
}

// Load admin settings component classes.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-renderer.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-validator.php';

require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-resource-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nefarious-usage-monitor.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-proxy-utils.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rate-limit-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-budget-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-selector.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-router.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-queue-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-lm-studio-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-request-context.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-usage-tracker.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-transcript-recorder.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-crawl4ai-local-api.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-response-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php';
require_once WP_MCP_AI_PATH . 'includes/job-notifier-init.php';
require_once WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php';
require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcodes.php';
require_once WP_MCP_AI_PATH . 'includes/tools-init.php';
require_once WP_MCP_AI_PATH . 'includes/tools/remove-background.php';

// Load federation system components.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-wellknown.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ai-peer-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-peer-verifier.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-directory-rest.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';

// Load third-party plugin integrations only when not in base version mode.
if ( ! wp_mcp_ai_is_base_version() ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-endpoint-report.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-assistants-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-ai-peers-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-rate-limits-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chatkit-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-simple-jwt-login-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-auth0-github.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php';
}

// Clean any output that may have been generated during includes.
// Only clean the buffer if we started it (i.e., not during Elementor AJAX requests or editor page loads).
if ( ! $skip_buffering ) {
	ob_end_clean();
}

if ( is_admin() ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-cron-manager.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-monitor-admin.php';
	WP_MCP_AI_Security_Monitor_Admin::init();

	// Load diagnostic pages (always available under Tools menu).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-dashboard-diagnostic.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';

	// Load new modular settings dashboard system by default.
	// Set WP_MCP_AI_USE_OLD_SETTINGS to true in wp-config.php to use legacy settings.
	if ( ! defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) ) {
		define( 'WP_MCP_AI_USE_OLD_SETTINGS', false );
	}

	if ( ! WP_MCP_AI_USE_OLD_SETTINGS ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/settings-dashboard-init.php';
		// Load Auth0 Setup wizard only when using new dashboard (it's a submenu of wp-mcp-ai-dashboard).
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-auth0-setup.php';
		new WP_MCP_AI_Auth0_Setup();
	}

	/**
	 * Add plugin action links in the plugins list.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	function wp_mcp_ai_add_plugin_action_links( $links ) {
		$settings_link = '';
		
		// Link to the appropriate settings page based on configuration.
		if ( defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) && WP_MCP_AI_USE_OLD_SETTINGS ) {
			$settings_link = admin_url( 'admin.php?page=wp-mcp-ai-settings' );
		} else {
			$settings_link = admin_url( 'admin.php?page=wp-mcp-ai-dashboard' );
		}

		$diagnostic_link = admin_url( 'admin.php?page=wp-mcp-ai-diagnostic' );

		$plugin_links = array(
			'settings'   => '<a href="' . esc_url( $settings_link ) . '">' . esc_html__( 'Settings', 'wp-mcp-ai' ) . '</a>',
			'diagnostic' => '<a href="' . esc_url( $diagnostic_link ) . '">' . esc_html__( 'Diagnostic', 'wp-mcp-ai' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wp_mcp_ai_add_plugin_action_links' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cli-command.php';
}

WP_MCP_AI_Message_Attachments::init();
WP_MCP_AI_Response_Attachments::init();

WP_MCP_AI_HTTP::bootstrap();

// Initialize third-party plugin integrations only when not in base version mode.
if ( ! wp_mcp_ai_is_base_version() ) {
	if ( class_exists( 'WP_MCP_AI_JetEngine_Tool_Handlers' ) ) {
		WP_MCP_AI_JetEngine_Tool_Handlers::bootstrap();
	}
	if ( class_exists( 'WP_MCP_AI_JetFormBuilder_Tool_Handlers' ) ) {
		WP_MCP_AI_JetFormBuilder_Tool_Handlers::bootstrap();
	}
	if ( class_exists( 'WP_MCP_AI_ChatKit_Integration' ) ) {
		WP_MCP_AI_ChatKit_Integration::init();
	}
	if ( class_exists( 'WP_MCP_AI_Simple_JWT_Login_Integration' ) ) {
		WP_MCP_AI_Simple_JWT_Login_Integration::init();
	}
	if ( class_exists( 'WP_MCP_AI_Integration_Auth0_Github' ) ) {
		WP_MCP_AI_Integration_Auth0_Github::init();
	}
	if ( class_exists( 'WP_MCP_AI_Integration_WordPress_Gravatar' ) ) {
		WP_MCP_AI_Integration_WordPress_Gravatar::init();
	}
}

if ( ! function_exists( 'wp_mcp_ai_load_textdomain' ) ) {
	/**
	 * Load the plugin textdomain for localisation support.
	 */
	function wp_mcp_ai_load_textdomain() {
		load_plugin_textdomain( 'wp-mcp-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
}

if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_load_textdomain' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_load_textdomain', 1 );
}

if ( ! class_exists( 'WP_MCP_AI' ) ) {
	/**
	 * Main plugin container class.
	 */
	final class WP_MCP_AI {
		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI
		 */
		private static $instance;

		/**
		 * Admin settings instance.
		 *
		 * @var WP_MCP_AI_Admin_Settings
		 */
		public $admin_settings;

		/**
		 * Assistant CPT instance.
		 *
		 * @var WP_MCP_AI_Assistant_CPT
		 */
		public $assistant_cpt;

		/**
		 * Crawl4AI Local API instance.
		 *
		 * @var WP_MCP_AI_Crawl4AI_Local_API
		 */
		public $crawl4ai_local_api;

		/**
		 * REST controller instance.
		 *
		 * @var WP_MCP_AI_REST
		 */
		public $rest_controller;

		/**
		 * Shortcodes instance.
		 *
		 * @var WP_MCP_AI_Shortcodes
		 */
		public $shortcodes;

		/**
		 * Admin cron manager instance.
		 *
		 * @var WP_MCP_AI_Admin_Cron_Manager
		 */
		public $admin_cron_manager;

		/**
		 * Resource manager instance.
		 *
		 * @var WP_MCP_AI_Resource_Manager
		 */
		public $resource_manager;

		/**
		 * Federation system instance.
		 *
		 * @var WP_MCP_AI_Federation
		 */
		public $federation;

		/**
		 * Output buffer level when starting Elementor AJAX buffering.
		 *
		 * @var int|null
		 */
		private $elementor_buffer_level = null;

		/**
		 * Returns the singleton instance.
		 *
		 * @return WP_MCP_AI
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor to prevent direct instantiation.
		 */
		private function __construct() {
			// Bootstrap happens via the bootstrap() method.
		}

		/**
		 * Bootstrap the plugin.
		 */
		public function bootstrap() {
			// Check root security key first if required.
			$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
			if ( ! $security_key->can_initialize() ) {
				// Block initialization when security key is required but not verified.
				// Admin interface will still load to allow key verification.
				return;
			}

			// Initialize nefarious usage monitor first to protect all operations.
			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->init();

			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$registry->init();

			$openai_client    = new WP_MCP_AI_OpenAI_Client();
			$gemini_client    = new WP_MCP_AI_Gemini_Client();
			$ollama_client    = new WP_MCP_AI_Ollama_Client();
			$lm_studio_client = new WP_MCP_AI_LM_Studio_Client();
			$anthropic_client = new WP_MCP_AI_Anthropic_Client();
			$router           = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client, $anthropic_client );

			$this->resource_manager   = WP_MCP_AI_Resource_Manager::instance();
			
			// Only instantiate old admin settings if legacy mode is enabled.
			if ( defined( 'WP_MCP_AI_USE_OLD_SETTINGS' ) && WP_MCP_AI_USE_OLD_SETTINGS ) {
				$this->admin_settings = new WP_MCP_AI_Admin_Settings();
			}
			
			$this->assistant_cpt      = new WP_MCP_AI_Assistant_CPT( $registry );
			$this->crawl4ai_local_api = new WP_MCP_AI_Crawl4AI_Local_API();
			$this->rest_controller    = new WP_MCP_AI_REST( $registry, $router );
			$this->shortcodes         = new WP_MCP_AI_Shortcodes();
			$this->federation         = new WP_MCP_AI_Federation( $registry );

			if ( is_admin() ) {
				$this->admin_cron_manager = new WP_MCP_AI_Admin_Cron_Manager();
			}

			// Maintain backward compatibility with code that accesses $GLOBALS directly.
			$GLOBALS['wp_mcp_ai_resource_manager']   = $this->resource_manager;
			
			// Only set admin_settings global if using old settings.
			if ( isset( $this->admin_settings ) ) {
				$GLOBALS['wp_mcp_ai_admin_settings'] = $this->admin_settings;
			}
			
			$GLOBALS['wp_mcp_ai_assistant_cpt']      = $this->assistant_cpt;
			$GLOBALS['wp_mcp_ai_crawl4ai_local_api'] = $this->crawl4ai_local_api;
			$GLOBALS['wp_mcp_ai_rest_controller']    = $this->rest_controller;
			$GLOBALS['wp_mcp_ai_shortcodes']         = $this->shortcodes;

			if ( is_admin() ) {
				$GLOBALS['wp_mcp_ai_admin_cron_manager'] = $this->admin_cron_manager;
			}

			WP_MCP_AI_Crawler::init();

			WP_MCP_AI_Usage_Tracker::init();
			WP_MCP_AI_Tool_Token_Limits::init();

			if ( class_exists( 'WP_MCP_AI_Elementor_Integration' ) ) {
				WP_MCP_AI_Elementor_Integration::maybe_init();
			}

			// Disable wp-auth-check in Elementor editor to prevent JavaScript errors.
			add_action( 'admin_enqueue_scripts', array( $this, 'disable_auth_check_in_elementor' ), 20 );

			// Suppress debug output during Elementor AJAX requests.
			add_action( 'admin_init', array( $this, 'suppress_debug_in_elementor_ajax' ), 1 );
		}

		/**
		 * Suppress debug output during Elementor AJAX requests.
		 *
		 * Prevents PHP warnings, notices, and deprecation messages from breaking
		 * Elementor's JSON responses when WP_DEBUG is enabled.
		 */
		public function suppress_debug_in_elementor_ajax() {
			// Only apply to AJAX requests.
			// Use function_exists check for backwards compatibility with WordPress < 4.7.0.
			$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

			if ( ! $is_ajax ) {
				return;
			}

			// Check if this is an Elementor-related AJAX request.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			// Check if this is an Elementor action.
			if ( strpos( $action, 'elementor' ) === 0 ) {
				// Suppress display_errors to prevent debug output from breaking JSON responses.
				// Error suppression is intentional: some hosts disable ini_set changes,
				// and we prefer graceful degradation over throwing warnings.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					@ini_set( 'display_errors', '0' );
				}

				// Track the current buffer level before starting our buffer.
				// This allows us to clean only the buffer(s) we create.
				$this->elementor_buffer_level = ob_get_level();

				// Start output buffering to catch any stray output that could break JSON responses.
				// This protects against any echoed content, warnings, or notices that occur
				// during the Elementor save process.
				ob_start();

				// Register a shutdown function to clean the buffer before Elementor sends its response.
				// We use priority 0 to run before most other shutdown handlers.
				add_action( 'shutdown', array( $this, 'clean_elementor_output_buffer' ), 0 );
			}
		}

		/**
		 * Clean the output buffer during Elementor AJAX requests.
		 *
		 * This runs during shutdown to ensure any stray output is discarded
		 * before Elementor sends its JSON response.
		 */
		public function clean_elementor_output_buffer() {
			// Check if this is an Elementor AJAX request by verifying the action parameter.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			// Only clean buffer for Elementor actions to avoid interfering with other code.
			if ( strpos( $action, 'elementor' ) !== 0 ) {
				return;
			}

			// Only clean the buffer if we created one.
			if ( null === $this->elementor_buffer_level ) {
				return;
			}

			// Get the current output buffer level.
			$current_level = ob_get_level();

			// Clean buffers down to the level we recorded before starting buffering.
			// This ensures we only clean the buffer(s) we created, not existing ones.
			// We use > (not >=) because elementor_buffer_level is the level BEFORE we started.
			while ( $current_level > 0 && $current_level > $this->elementor_buffer_level ) {
				ob_end_clean();
				$current_level = ob_get_level();
			}

			// Reset the tracked level.
			$this->elementor_buffer_level = null;
		}

		/**
		 * Disable wp-auth-check heartbeat in Elementor editor.
		 *
		 * Prevents JavaScript errors related to missing DOM elements.
		 * Also prevents debug output from breaking JSON responses when WP_DEBUG is enabled.
		 * Elementor uses a query parameter to identify editor mode, which is
		 * validated by Elementor's own nonce verification in its editor loader.
		 */
		public function disable_auth_check_in_elementor() {
			// Check if Elementor is active and in editor mode.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_GET['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );

			// Elementor editor uses 'elementor' action parameter.
			// This is a safe check as Elementor's editor loader validates capabilities and nonces.
			if ( 'elementor' === $action && current_user_can( 'edit_posts' ) ) {
				remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

				// Prevent debug output from breaking Elementor's JSON responses.
				// When WP_DEBUG is enabled, PHP warnings/notices can break the editor.
				// Error suppression is intentional: some hosts disable ini_set changes,
				// and we prefer graceful degradation over throwing warnings.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					@ini_set( 'display_errors', '0' );
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai' ) ) {
	/**
	 * Returns the main instance of WP_MCP_AI.
	 *
	 * @return WP_MCP_AI
	 */
	function wp_mcp_ai() {
		return WP_MCP_AI::instance();
	}
}

if ( ! function_exists( 'wp_mcp_ai_bootstrap' ) ) {
	/**
	 * Bootstrap the plugin once all dependencies are loaded.
	 */
	function wp_mcp_ai_bootstrap() {
		wp_mcp_ai()->bootstrap();
	}
}

if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap', 20 );
}

if ( ! function_exists( 'wp_mcp_ai_iterate_network_sites' ) ) {
	/**
	 * Helper function to iterate through all sites in a multisite network.
	 *
	 * @param callable $callback Callback function to execute for each site.
	 * @param string   $action   Action name for error logging (e.g., 'activation', 'deactivation').
	 * @return void
	 */
	function wp_mcp_ai_iterate_network_sites( $callback, $action = 'operation' ) {
		if ( ! is_multisite() || ! is_callable( $callback ) ) {
			return;
		}

		/**
		 * Filters the arguments for get_sites() when iterating network sites.
		 *
		 * Allows customization of site retrieval, including pagination for large networks.
		 *
		 * @param array $args Arguments passed to get_sites(). Default: array( 'number' => 0 ).
		 */
		$get_sites_args = apply_filters(
			'wp_mcp_ai_iterate_network_sites_args',
			array( 'number' => 0 )
		);

		// Get sites in the network.
		$sites = get_sites( $get_sites_args );

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			try {
				call_user_func( $callback );
			} catch ( Exception $e ) {
				// Log the error and continue with remaining sites.
				error_log( sprintf( 'WP oOS %s failed for site %d: %s', $action, $site->blog_id, $e->getMessage() ) );
			}
			restore_current_blog();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_new_site_activation' ) ) {
	/**
	 * Activate plugin on a newly created site in a multisite network.
	 *
	 * @param int|WP_Site $blog WordPress 5.1+ passes a WP_Site object, earlier versions pass blog ID.
	 * @return void
	 */
	function wp_mcp_ai_new_site_activation( $blog ) {
		if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			return;
		}

		// Handle both WP_Site object (WP 5.1+) and blog ID (earlier versions).
		if ( is_object( $blog ) && isset( $blog->blog_id ) ) {
			$blog_id = (int) $blog->blog_id;
		} elseif ( is_numeric( $blog ) ) {
			$blog_id = (int) $blog;
		} else {
			// Invalid parameter, log error and return.
			error_log( 'WP oOS: Invalid blog parameter passed to new_site_activation' );
			return;
		}

		switch_to_blog( $blog_id );
		try {
			wp_mcp_ai_activate_single_site();
		} catch ( Exception $e ) {
			// Log the error but don't break the site creation process.
			error_log( sprintf( 'WP oOS activation failed for site %d: %s', $blog_id, $e->getMessage() ) );
		}
		restore_current_blog();
	}
}

if ( ! has_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' ) ) {
	add_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' );
	add_action( 'wpmu_new_blog', 'wp_mcp_ai_new_site_activation' );
}

if ( ! function_exists( 'wp_mcp_ai_check_activation_security' ) ) {
	/**
	 * Check site security during plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_check_activation_security() {
		// Allow users to bypass security check with a constant.
		if ( defined( 'WP_MCP_AI_SKIP_SECURITY_CHECK' ) && WP_MCP_AI_SKIP_SECURITY_CHECK ) {
			return;
		}

		// Load the security check tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php';

		$security_tool = new WP_MCP_AI_Tool_Check_Site_Security();
		$result        = $security_tool->execute( array(), array( 'user_id' => get_current_user_id() ) );

		// Store result for admin notice display.
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'wp_mcp_ai_activation_security_check', $result, HOUR_IN_SECONDS );
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activation_security_notice' ) ) {
	/**
	 * Display security warning notice after plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activation_security_notice() {
		$security_check = get_transient( 'wp_mcp_ai_activation_security_check' );

		if ( ! $security_check || ! is_array( $security_check ) ) {
			return;
		}

		// Delete transient so notice only shows once.
		delete_transient( 'wp_mcp_ai_activation_security_check' );

		$risk_level = isset( $security_check['risk_level'] ) ? $security_check['risk_level'] : 'unknown';
		$is_safe    = isset( $security_check['is_safe_to_use'] ) ? $security_check['is_safe_to_use'] : false;

		// Only show notice for high and critical risk levels.
		if ( 'critical' !== $risk_level && 'high' !== $risk_level ) {
			return;
		}

		$recommendation = isset( $security_check['recommendation'] ) ? $security_check['recommendation'] : '';
		$summary        = isset( $security_check['summary'] ) ? $security_check['summary'] : array();
		$checks         = isset( $security_check['checks'] ) ? $security_check['checks'] : array();

		$notice_class = 'critical' === $risk_level ? 'notice-error' : 'notice-warning';

		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<h3><?php esc_html_e( 'WP oOS Security Warning', 'wp-mcp-ai' ); ?></h3>
			<p><strong><?php echo esc_html( $recommendation ); ?></strong></p>
			
			<?php if ( ! empty( $summary ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: Number of critical issues, 2: Number of warnings */
						esc_html__( 'Security Check Results: %1$d critical issue(s), %2$d warning(s)', 'wp-mcp-ai' ),
						isset( $summary['critical'] ) ? absint( $summary['critical'] ) : 0,
						isset( $summary['warning'] ) ? absint( $summary['warning'] ) : 0
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $checks ) ) : ?>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<?php foreach ( $checks as $check ) : ?>
						<?php if ( isset( $check['severity'] ) && in_array( $check['severity'], array( 'critical', 'warning' ), true ) ) : ?>
							<li>
								<strong><?php echo esc_html( isset( $check['name'] ) ? $check['name'] : '' ); ?>:</strong>
								<?php echo esc_html( isset( $check['message'] ) ? $check['message'] : '' ); ?>
								<?php if ( ! empty( $check['action'] ) ) : ?>
									<br><em><?php echo esc_html( $check['action'] ); ?></em>
								<?php endif; ?>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p>
				<?php
				esc_html_e( 'This plugin handles sensitive AI API keys and data. Using it on an insecure site puts your API keys and user data at risk.', 'wp-mcp-ai' );
				?>
			</p>
			<p>
				<em>
					<?php
					printf(
						/* translators: %s: Code snippet */
						wp_kses(
							__( 'To bypass this security check, add %s to your wp-config.php file. Only do this if you understand the risks.', 'wp-mcp-ai' ),
							array( 'code' => array() )
						),
						'<code>define( \'WP_MCP_AI_SKIP_SECURITY_CHECK\', true );</code>'
					);
					?>
				</em>
			</p>
		</div>
		<?php
	}
}

// Hook the activation security notice to admin_notices.
add_action( 'admin_notices', 'wp_mcp_ai_activation_security_notice' );

if ( ! function_exists( 'wp_mcp_ai_activate' ) ) {
	/**
	 * Plugin activation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_activate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_activate_single_site', 'activation' );
		} else {
			wp_mcp_ai_activate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activate_single_site' ) ) {
	/**
	 * Activate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activate_single_site() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Check site security and store result for display in admin notice.
		wp_mcp_ai_check_activation_security();

		// Note: We intentionally do not call WP_MCP_AI_Assistant_CPT::register_post_type() here
		// to avoid triggering translation loading before the init action (WordPress 6.7+ requirement).
		// The post type will be registered on the next page load via the init hook.
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, 'wp_mcp_ai_activate' );

if ( ! function_exists( 'wp_mcp_ai_deactivate' ) ) {
	/**
	 * Plugin deactivation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_deactivate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_deactivate_single_site', 'deactivation' );
		} else {
			wp_mcp_ai_deactivate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_deactivate_single_site' ) ) {
	/**
	 * Deactivate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_deactivate_single_site() {
		flush_rewrite_rules();
	}
}

register_deactivation_hook( __FILE__, 'wp_mcp_ai_deactivate' );

if ( ! function_exists( 'wp_mcp_ai_uninstall' ) ) {
	/**
	 * Plugin uninstall handler.
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall() {
		if ( is_multisite() ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_uninstall_single_site', 'uninstall' );
		} else {
			wp_mcp_ai_uninstall_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_uninstall_single_site' ) ) {
	/**
	 * Uninstall the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall_single_site() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, WP_MCP_AI_Admin_Settings::get_default_settings() );

		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		/**
		 * Fires before WP oOS performs its uninstall cleanup routines.
		 */
		do_action( 'wp_mcp_ai_before_uninstall_cleanup' );

		$assistant_ids = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $assistant_ids ) ) {
			foreach ( $assistant_ids as $assistant_id ) {
				wp_delete_post( $assistant_id, true );
			}
		}

		$settings_deleted = delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		/**
		 * Fires after WP oOS completes its uninstall cleanup routines.
		 *
		 * @param array $summary Summary of cleanup actions performed.
		 */
		do_action(
			'wp_mcp_ai_after_uninstall_cleanup',
			array(
				'assistants_deleted' => is_array( $assistant_ids ) ? count( $assistant_ids ) : 0,
				'settings_deleted'   => (bool) $settings_deleted,
			)
		);
	}
}

register_uninstall_hook( __FILE__, 'wp_mcp_ai_uninstall' );

if ( ! function_exists( 'wp_mcp_ai_extend_upload_mimes' ) ) {
	/**
	 * Ensure additional file-search formats can be uploaded when enabled.
	 *
	 * @param array|string $mimes Allowed mime types keyed by file extension.
	 * @return array
	 */
	function wp_mcp_ai_extend_upload_mimes( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			return $mimes;
		}

		$allowed_sets = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
		$file_mimes   = isset( $allowed_sets['file'] ) ? (array) $allowed_sets['file'] : array();

		$jsonl_candidates = array(
			'application/jsonl',
			'application/x-ndjson',
		);

		$selected_jsonl_mime = '';

		foreach ( $jsonl_candidates as $candidate ) {
			if ( in_array( $candidate, $file_mimes, true ) ) {
				$selected_jsonl_mime = $candidate;
				break;
			}
		}

		if ( '' !== $selected_jsonl_mime ) {
			$mimes['jsonl'] = $selected_jsonl_mime;
		}

		if ( in_array( 'application/x-ndjson', $file_mimes, true ) ) {
			$mimes['ndjson'] = 'application/x-ndjson';
		} elseif ( '' !== $selected_jsonl_mime ) {
			$mimes['ndjson'] = $selected_jsonl_mime;
		}

		if ( in_array( 'text/markdown', $file_mimes, true ) ) {
			$mimes['md']       = 'text/markdown';
			$mimes['markdown'] = 'text/markdown';
		}

		return $mimes;
	}
}

if ( ! has_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' ) ) {
	add_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' );
}
