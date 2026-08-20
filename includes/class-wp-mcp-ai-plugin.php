<?php
/**
 * Main Plugin Class
 *
 * Acts as the plugin's kernel: initialises all sub-systems through the DI
 * container, sets up Elementor compatibility, and exposes the singleton used
 * by the bootstrap function.
 *
 * Extracted from mcp-ai-wpoos.php (Phase 1 refactor) while maintaining full
 * backward-compatibility — the class name `WP_MCP_AI` is preserved so that all
 * existing code referencing it continues to work.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName, Squiz.Commenting.FileComment, Universal.Files.SeparateFunctionsFromOO, PSR1.Files.SideEffects.FoundWithSymbols

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI' ) ) {
	/**
	 * Main plugin container class.
	 *
	 * @since 1.0.0
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
		 * Admin token manager instance.
		 *
		 * @var WP_MCP_AI_Admin_Token_Manager
		 */
		public $admin_token_manager;

		/**
		 * Admin Crawl4AI monitor instance.
		 *
		 * @var WP_MCP_AI_Admin_Crawl4AI_Monitor
		 */
		public $admin_crawl4ai_monitor;

		/**
		 * Admin Dead Letter Queue manager instance.
		 *
		 * @var WP_MCP_AI_Admin_DLQ_Manager
		 */
		public $admin_dlq_manager;

		/**
		 * Admin conversation import instance.
		 *
		 * @var WP_MCP_AI_Conversation_Import_Admin
		 */
		public $admin_conversation_import;

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
		 *
		 * Initialises all plugin sub-systems through the DI container. Called by
		 * wp_mcp_ai_bootstrap() on the `plugins_loaded` action.
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

			// Get container for dependency management.
			$container = wp_mcp_ai_container();

			// Initialize language model clients and router through container.
			$router = $container->get( 'router' );

			$this->resource_manager = WP_MCP_AI_Resource_Manager::instance();

			// Initialize core components through container.
			$this->assistant_cpt      = $container->get( 'assistant_cpt' );
			$this->crawl4ai_local_api = $container->get( 'crawl4ai_local_api' );
			$this->rest_controller    = $container->get( 'rest_controller' );
			$this->shortcodes         = $container->get( 'shortcodes' );
			$this->federation         = $container->get( 'federation' );

			if ( is_admin() ) {
				$this->admin_cron_manager     = $container->get( 'admin.cron_manager' );
				$this->admin_dlq_manager      = $container->get( 'admin.dlq_manager' );
				$this->admin_token_manager    = $container->get( 'admin.token_manager' );
				$this->admin_crawl4ai_monitor = $container->get( 'admin.crawl4ai_monitor' );

				if ( class_exists( 'WP_MCP_AI_Conversation_Import_Admin' ) ) {
					$this->admin_conversation_import = $container->get( 'admin.conversation_import' );
				}
			}

			// Maintain backward compatibility with code that accesses $GLOBALS directly.
			// Deprecated: new code should use wp_mcp_ai_container()->get(...).
			// Gate behind WP_MCP_AI_LEGACY_GLOBALS to allow gradual migration;
			// will default to false in v1.4.0 and be removed in v1.5.0.
			if ( defined( 'WP_MCP_AI_LEGACY_GLOBALS' ) && WP_MCP_AI_LEGACY_GLOBALS ) {
				$GLOBALS['wp_mcp_ai_resource_manager']   = $this->resource_manager;
				$GLOBALS['wp_mcp_ai_assistant_cpt']      = $this->assistant_cpt;
				$GLOBALS['wp_mcp_ai_crawl4ai_local_api'] = $this->crawl4ai_local_api;
				$GLOBALS['wp_mcp_ai_rest_controller']    = $this->rest_controller;
				$GLOBALS['wp_mcp_ai_shortcodes']         = $this->shortcodes;

				if ( is_admin() ) {
					$GLOBALS['wp_mcp_ai_admin_cron_manager']     = $this->admin_cron_manager;
					$GLOBALS['wp_mcp_ai_admin_dlq_manager']      = $this->admin_dlq_manager;
					$GLOBALS['wp_mcp_ai_admin_token_manager']    = $this->admin_token_manager;
					$GLOBALS['wp_mcp_ai_admin_crawl4ai_monitor'] = $this->admin_crawl4ai_monitor;
				}
			}

			WP_MCP_AI_Crawler::init();

			WP_MCP_AI_Usage_Tracker::init();
			WP_MCP_AI_Tool_Token_Limits::init();

			// Initialize database optimizations for token management.
			if ( class_exists( 'WP_MCP_AI_Token_DB_Optimizer' ) ) {
				WP_MCP_AI_Token_DB_Optimizer::init();
			}

			// Initialize enhanced token tracking with real-time cost attribution.
			if ( class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
				WP_MCP_AI_Enhanced_Token_Tracking::init();
			}

			// Initialize DSpark efficiency data-collection hooks.
			if ( class_exists( 'WP_MCP_AI_DSpark_Hooks' ) ) {
				WP_MCP_AI_DSpark_Hooks::register();
			}

			// Initialize Elementor integration on 'init' to avoid early translation loading.
			// WordPress 6.7.0+ requires translations to be loaded at init or later.
			// This prevents "_load_textdomain_just_in_time was called incorrectly" warnings.
			add_action( 'init', array( $this, 'init_elementor_integration' ) );

			// Initialize Gutenberg blocks for AI Assistant Builder.
			if ( class_exists( 'WP_MCP_AI_Assistant_Builder_Blocks' ) ) {
				WP_MCP_AI_Assistant_Builder_Blocks::init();
			}

			// Initialize the global chat bubble frontend (settings-driven, no widget needed).
			if ( class_exists( 'WP_MCP_AI_Chat_Bubble_Frontend' ) ) {
				$chat_bubble_frontend = new WP_MCP_AI_Chat_Bubble_Frontend();
				$chat_bubble_frontend->init();
			}

			// Initialize WordPress integration enhancements (Privacy API and Site Health).
			if ( class_exists( 'WP_MCP_AI_Privacy' ) ) {
				new WP_MCP_AI_Privacy();
			}
			if ( class_exists( 'WP_MCP_AI_Site_Health' ) ) {
				new WP_MCP_AI_Site_Health();
			}
			if ( class_exists( 'WP_MCP_AI_Site_Health_Connection_Pool' ) ) {
				WP_MCP_AI_Site_Health_Connection_Pool::register();
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
			$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

			if ( ! $is_ajax ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			if ( strpos( $action, 'elementor' ) === 0 ) {
				$this->elementor_buffer_level = ob_get_level();
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			if ( strpos( $action, 'elementor' ) !== 0 ) {
				return;
			}

			if ( null === $this->elementor_buffer_level ) {
				return;
			}

			$current_level = ob_get_level();

			while ( $current_level > 0 && $current_level > $this->elementor_buffer_level ) {
				ob_end_clean();
				$current_level = ob_get_level();
			}

			$this->elementor_buffer_level = null;
		}

		/**
		 * Initialize Elementor integration on 'init' hook.
		 *
		 * Called on the 'init' action to ensure translations are loaded at the
		 * correct time (WordPress 6.7+ JIT requirement).
		 *
		 * @since 1.1.0
		 */
		public function init_elementor_integration() {
			if ( ! class_exists( 'WP_MCP_AI_Elementor_Integration' ) ) {
				return;
			}

			// Check if Elementor widgets are enabled in settings.
			// Defaults to true for backward compatibility.
			$settings        = get_option( 'wp_mcp_ai_settings', array() );
			$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

			if ( $widgets_enabled ) {
				WP_MCP_AI_Elementor_Integration::maybe_init();
			}
		}

		/**
		 * Disable wp-auth-check heartbeat in Elementor editor.
		 *
		 * Prevents JavaScript errors related to missing DOM elements.
		 */
		public function disable_auth_check_in_elementor() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_GET['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );

			if ( 'elementor' === $action && current_user_can( 'edit_posts' ) ) {
				remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_bootstrap' ) ) {
	/**
	 * Bootstrap the plugin once all dependencies are loaded.
	 *
	 * Instantiates the main plugin singleton and calls its bootstrap method
	 * to initialize all core components (REST API, tool registry, assistants, etc.).
	 * Fires `wp_mcp_ai_bootstrapped` on completion — the Pro addon hooks into this.
	 */
			/**
			 * Bootstrap the plugin.
			 *
			 * Instantiates the main plugin singleton and calls its bootstrap method.
			 *
			 * @since 1.0.0
			 */
	function wp_mcp_ai_bootstrap() {
		$plugin = WP_MCP_AI::instance();
		$plugin->bootstrap();

		/**
		 * Fires after Open Operator System has completed its bootstrap process.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wp_mcp_ai_bootstrapped' );
	}
}

if ( ! function_exists( 'wp_mcp_ai_maybe_load_pro_addon' ) ) {
	/**
	 * Auto-load pro addon if present and not already loaded as separate plugin.
	 *
	 * This allows the combined plugin to include pro addon functionality
	 * when the pro addon is bundled in the addons/pro directory.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_maybe_load_pro_addon() {
		// Check if pro addon is already loaded as a separate plugin.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if pro addon exists in the addons directory.
		$pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
		if ( ! file_exists( $pro_addon_file ) ) {
			return;
		}

		// Load the pro addon.
		require_once $pro_addon_file;

		// Initialize pro addon if it has an init function.
		if ( function_exists( 'wp_mcp_ai_pro_init' ) ) {
			wp_mcp_ai_pro_init();
		}
	}
}
