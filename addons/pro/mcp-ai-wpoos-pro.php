<?php
/**
 * NV Digital Open Operator System (oOS) - Pro Add-on Entry Point
 *
 * This file serves as the entry point for the Pro add-on when built as a
 * standalone plugin for distribution. In the repository, it does NOT have a
 * plugin header to prevent WordPress from detecting it as a separate plugin
 * when the repository is cloned for development.
 *
 * The build script (bin/build-plugin-zip.sh) adds the plugin header when
 * creating the Pro add-on distribution for separate installation.
 *
 * When the complete repository is cloned, this file is automatically loaded
 * by mcp-ai-wpoos.php (line 462-465), so the Pro features are available
 * without needing to activate it as a separate plugin.
 *
 * @package WP_MCP_AI_Pro
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 *
 * Patent Pending: This software is the subject of a pending patent application
 * (Application #19/410,504) for "System and Method for Dynamic AI Orchestration
 * Layer with Real-Time Capability Gating and Resource Budgeting."
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pro plugin constants.
if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	define( 'WP_MCP_AI_PRO_VERSION', '1.0.0' );
}
if ( ! defined( 'WP_MCP_AI_PRO_FILE' ) ) {
	define( 'WP_MCP_AI_PRO_FILE', __FILE__ );
}
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', plugin_dir_path( WP_MCP_AI_PRO_FILE ) );
}
if ( ! defined( 'WP_MCP_AI_PRO_URL' ) ) {
	// If loaded as part of main plugin, build URL from main plugin's URL.
	// Check if WP_MCP_AI_URL is defined AND if we're actually bundled (not a separate plugin).
	// We can detect if we're bundled by checking if our path contains 'addons/pro'.
	// Use wp_normalize_path() for cross-platform compatibility (Windows/Unix).
	$is_bundled = defined( 'WP_MCP_AI_URL' ) &&
					defined( 'WP_MCP_AI_PATH' ) &&
					strpos(
						wp_normalize_path( WP_MCP_AI_PRO_PATH ),
						wp_normalize_path( trailingslashit( WP_MCP_AI_PATH ) . 'addons/pro' )
					) !== false;

	if ( $is_bundled ) {
		define( 'WP_MCP_AI_PRO_URL', WP_MCP_AI_URL . 'addons/pro/' );
	} else {
		// If loaded as standalone plugin, use standard plugin_dir_url().
		define( 'WP_MCP_AI_PRO_URL', plugin_dir_url( WP_MCP_AI_PRO_FILE ) );
	}

	// Clean up temporary variable.
	unset( $is_bundled );
}

/**
 * ============================================================================
 * DEPENDENCY CHECK
 *
 * Verify that Open Operator System (NV oOS) Core is active before loading Pro features.
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_pro_check_dependencies' ) ) {
	/**
	 * Check if required dependencies are available.
	 *
	 * Pro addon requires either:
	 * - Open Operator System (NV oOS) Core (separated plugin architecture), OR
	 * - Open Operator System (NV oOS) combined plugin with tool registry
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if all dependencies are met.
	 */
	function wp_mcp_ai_pro_check_dependencies() {
		// Check if Core is loaded (separated architecture).
		if ( function_exists( 'wp_mcp_ai_core_loaded' ) && wp_mcp_ai_core_loaded() ) {
			return true;
		}

		// Check if combined plugin is loaded (tool registry exists).
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_missing_core_notice' ) ) {
	/**
	 * Display admin notice when Core is not active.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_pro_missing_core_notice() {
		$message = sprintf(
			'<strong>Open Operator System Pro</strong> requires either <strong>Open Operator System (NV oOS)</strong> or <strong>Open Operator System</strong> to be installed and activated. Please <a href="%s">install Open Operator System (NV oOS)</a> or <a href="%s">Open Operator System</a> first.',
			esc_url( admin_url( 'plugin-install.php?s=wp-mcp-ai-core&tab=search&type=term' ) ),
			esc_url( admin_url( 'plugin-install.php?s=wp-open-operator-system&tab=search&type=term' ) )
		);
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}
}

/**
 * ============================================================================
 * BOOTSTRAP
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_pro_load_admin_sections' ) ) {
	/**
	 * Load Pro admin sections.
	 *
	 * Loads the Pro-specific admin section class files.
	 * The sections are instantiated and registered via the container system
	 * in settings-dashboard-init.php.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_pro_load_admin_sections() {
		// Load Performance section.
		$performance_section_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		if ( file_exists( $performance_section_file ) ) {
			require_once $performance_section_file;
		}

		// Load Pro Integrations section (Mailjet, Google Analytics, Fantasy Sports).
		$pro_integrations_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-pro-integrations.php';
		if ( file_exists( $pro_integrations_file ) ) {
			require_once $pro_integrations_file;
		}

		// Load Pro Providers section (Embedded LLM).
		$pro_providers_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-pro-providers.php';
		if ( file_exists( $pro_providers_file ) ) {
			require_once $pro_providers_file;
		}

		// Load Pro Packages Settings Page (Node.js package status).
		$pro_packages_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php';
		if ( file_exists( $pro_packages_page ) ) {
			require_once $pro_packages_page;
		}

		// Load LangChain.js enqueue manager (pro-only feature for embedded LLM orchestration).
		$langchain_enqueue_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-langchain-enqueue.php';
		if ( file_exists( $langchain_enqueue_file ) ) {
			require_once $langchain_enqueue_file;
			// Class instantiates itself at end of file.
		}

		// Load Pro Workflow Builder (Phase 2.0.0 - Visual workflow builder with ReactFlow).
		$workflow_builder_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php';
		if ( file_exists( $workflow_builder_file ) ) {
			require_once $workflow_builder_file;
			// Note: Class instantiates itself at the bottom of the file.
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_load_textdomain' ) ) {
	/**
	 * Load Pro addon text domain for translations.
	 *
	 * When the Pro addon is bundled with the base plugin (cloned repository),
	 * it doesn't have a plugin header, so WordPress cannot auto-load its translations.
	 * We manually load the text domain on the init hook to comply with WordPress 6.7+
	 * requirements for translation loading timing.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_pro_load_textdomain() {
		// Only load if Pro is bundled (no plugin header).
		// If Pro is installed as separate plugin, WordPress handles this automatically.
		// Check if the Pro addon is loaded as a separate active plugin by checking
		// if it's in the active_plugins option. This works in all contexts (admin/frontend).
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$pro_plugin     = plugin_basename( WP_MCP_AI_PRO_FILE );
		$is_pro_active  = in_array( $pro_plugin, $active_plugins, true );

		// For multisite, also check network active plugins.
		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			$is_pro_active  = $is_pro_active || isset( $network_active[ $pro_plugin ] );
		}

		if ( ! $is_pro_active ) {
			// Pro is bundled, not a separate plugin - load text domain manually.
			// Use wp_normalize_path for cross-platform compatibility.
			$languages_dir = wp_normalize_path( WP_MCP_AI_PRO_PATH . 'languages' );
			$plugin_dir    = wp_normalize_path( WP_PLUGIN_DIR );

			// Calculate relative path for load_plugin_textdomain.
			$relative_path = str_replace( trailingslashit( $plugin_dir ), '', $languages_dir );

			load_plugin_textdomain(
				'mcp-ai-wpoos-pro',
				false,
				$relative_path
			);
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_is_woocommerce_tools_enabled' ) ) {
	/**
	 * Check if WooCommerce tools are enabled.
	 *
	 * Returns true by default (when setting doesn't exist) to ensure
	 * WooCommerce integration is available on fresh installs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Optional. Settings array. If not provided, will fetch from options.
	 * @return bool True if WooCommerce tools are enabled, false otherwise.
	 */
	function wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
		}

		return isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_init' ) ) {
	/**
	 * Initialize Open Operator System Pro.
	 *
	 * Called after Core has initialized. Registers Pro tools and features.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_pro_init() {
		// Check dependencies.
		if ( ! wp_mcp_ai_pro_check_dependencies() ) {
			// Show admin notice and bail.
			add_action( 'admin_notices', 'wp_mcp_ai_pro_missing_core_notice' );
			return;
		}

		// Load Pro addon vendor autoload (for phpspreadsheet and other PHP 8.1+ dependencies).
		// This is conditional and only loads when PHP 8.1+ is available.
		// PHP 7.4 users will see graceful degradation in tools that require these dependencies.
		if ( version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
			$pro_vendor_autoload = WP_MCP_AI_PRO_PATH . 'vendor/autoload.php';
			if ( file_exists( $pro_vendor_autoload ) ) {
				require_once $pro_vendor_autoload;
			}
		}

		// Register text domain loading on init hook.
		// This ensures translations are loaded at the correct time for WordPress 6.7+.
		add_action( 'init', 'wp_mcp_ai_pro_load_textdomain', 1 );

		// Load NPM integration filter handlers.
		// This enables Node.js microservice integration for Prettier, MJML, and fluent-ffmpeg.
		require_once WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';

		// Load CDN loader for optimized asset delivery.
		// Reduces plugin size by loading popular libraries from CDN with automatic fallback.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-cdn-loader.php';

		// Load utility classes for enhanced features (Phase 2 enhancements - Jan 2026).
		// Product Type Helper: Handles all WooCommerce product types (simple, variable, grouped, external, subscription, bundle, etc.).
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-product-type-helper.php';
		// Remote Connection Manager: Enables REST API connections to remote WordPress/WooCommerce sites.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-remote-connection.php';
		// ERP Connector: Provides interface for ERP integrations (EZuite ERP, custom ERPs).
		require_once WP_MCP_AI_PRO_PATH . 'includes/interface-wp-mcp-ai-erp-connector.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-erp-ezuite.php';

		// Load Pro tool interfaces (extend Core interfaces).
		// Pro tools can implement additional interfaces for advanced features.

		// Get settings for conditional loading.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Load Pro admin sections.
		// Performance section is only loaded in admin context.
		if ( is_admin() ) {
			wp_mcp_ai_pro_load_admin_sections();

			// Load Remote Sites admin interface.
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php';

			// Load Remote Connections metabox for assistants.
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-metabox-remote-connections.php';

			// Load WebLLM Advanced Features settings page (Phase 1).
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-webllm-settings-page.php';

			// Load AI CPT Management Integration if enabled.
			if ( ! empty( $settings['enable_ai_cpt_management'] ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-cpt-ai-integration.php';
				WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

				// Load Research & Add pages for Posts and Pages.
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-post-research-page.php';
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-page-research-page.php';

				// Load Settings pages for Posts and Pages.
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-post-settings-page.php';
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-page-settings-page.php';
			}
		}

		// Load WebChat integration system if enabled.
		if ( ! empty( $settings['enable_webchat_integration'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-webchat-cpt.php';
			WP_MCP_AI_WebChat_CPT::init();
			// Load JetEngine WebChat Messages CCT if JetEngine is active.
			if ( function_exists( 'jet_engine' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-webchat-messages-cct.php';
				WP_MCP_AI_JetEngine_WebChat_Messages_CCT::bootstrap();
			}
			// Load WebChat Self-Hosted Signaling REST Controller.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-webchat-signaling-rest-controller.php';
			add_action( 'rest_api_init', function() {
				$controller = new WP_MCP_AI_WebChat_Signaling_REST_Controller();
				$controller->register_routes();
			} );
			// Load WebChat Settings page.
			if ( is_admin() ) {
				// Check if not in base version.
				$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
				if ( ! $is_base ) {
					require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-webchat-settings-page.php';
				}
			}
		}

		// Load Fantasy Football toolkit if enabled.
		if ( ! empty( $settings['enable_fantasy_football'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/fantasy-football-toolkit-init.php';
		}

		// Load Media Toolkit if enabled (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/media-toolkit-init.php';

		// Load Product Research & Add page if WooCommerce tools enabled.
		if ( wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings ) && is_admin() ) {
			// Check if not in base version and WooCommerce is active.
			$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
			if ( ! $is_base && class_exists( 'WooCommerce' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-research-page.php';
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-settings-page.php';
			}
		}

		// Load Project Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/project-management-init.php';

		// Load Ralph Orchestration CCT schemas (Pro feature).
		if ( function_exists( 'jet_engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-autonomous-sessions-cct.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-plans-cct.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-execution-history-cct.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-templates-cct.php';
		}

		// NOTE: Orchestration Dashboard moved to base plugin (includes/admin/class-wp-mcp-ai-orchestration-dashboard.php)
		// It's accessible at NV oOS → Orchestration for all users.

		// Load Pro Orchestration Dashboard (real-time monitoring with SSE).
		if ( is_admin() ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-orchestration-dashboard.php';
			new WP_MCP_AI_Orchestration_Dashboard();
		}

		// Load Places Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/places-management-init.php';

		// Load ECA Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/eca-management-init.php';

		// Load Quiz Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php';

		// Load Health and Wellness Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/health-wellness-management-init.php';

		// Load Calendar Booking Toolkit CPT registration (Pro feature - Phase 2.6).
		require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking-toolkit-init.php';

		// ========================================================================
		// NEW PRO TOOLKITS (Phase 1 - Foundation)
		// ========================================================================

		// Load E-commerce Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_ecommerce_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/ecommerce-toolkit-init.php';
		}

		// Load Social Media Management Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_social_media_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/social-media-toolkit-init.php';
		}

		// Load Advanced Analytics Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_analytics_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/analytics-toolkit-init.php';
		}

		// Load Multi-language Content Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_multilingual_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/multilingual-toolkit-init.php';
		}

		// Load Video Production Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_video_production_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/video-production-toolkit-init.php';
		}

		// Load Financial Planner Toolkit if enabled (Pro feature - Phase 2.5).
		if ( ! empty( $settings['enable_financial_planner_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/financial-planner-toolkit-init.php';
		}

		// Load DJ Management Toolkit if enabled (Pro feature - Phase 2.7).
		if ( ! empty( $settings['enable_dj_management_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/dj-management-toolkit-init.php';
		}

		// Load Image Production Toolkit if enabled (Pro feature - Phase 2.8).
		if ( ! empty( $settings['enable_image_production_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/image-production-toolkit-init.php';
		}

		// Load AI Tool Builder Toolkit if enabled (Pro feature - Phase 2.9).
		if ( ! empty( $settings['enable_ai_tool_builder_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/ai-tool-builder-toolkit-init.php';
		}

		// Load Architect Agent Toolkit if enabled (Pro feature).
		// Self-editing capabilities with GitHub Copilot CLI parity.
		if ( ! empty( $settings['enable_architect_agent_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/architect-agent-toolkit-init.php';
		}

		// Load Architectural Design Toolkit if enabled (Pro feature - Phase 2.10).
		if ( ! empty( $settings['enable_architectural_design_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/architectural-design-toolkit-init.php';
		}

		// Load Site Creator Toolkit if enabled (Pro feature).
		// Advanced site creation with page/section/widget builders and Architect Agent integration.
		if ( ! empty( $settings['enable_site_creator_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/site-creator-toolkit-init.php';
		}

		// Load Password Vault Manager (Pro feature - Phase 2.11).
		// Always enabled - provides secure password storage with AES-256-GCM encryption.
		require_once WP_MCP_AI_PRO_PATH . 'includes/password-vault-init.php';

		// Load Document Generation Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_document_generation_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/document-generation-toolkit-init.php';
		}

		// Load CRM Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_crm_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/crm-toolkit-init.php';
		}

		// Load Regulatory Registration Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/regulatory-registration-toolkit-init.php';
		}

		// Load Chat Channels Integration Toolkit if enabled (Pro feature).
		if ( ! empty( $settings['enable_chat_channels_toolkit'] ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/chat-channels-toolkit-init.php';

			// Load WhatsApp Webhook Controller for real-time message handling.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';

			// Load Messenger Webhook Controller for Meta Messenger Platform events.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-messenger-webhook-controller.php';

			// Load Telegram Webhook Controller for Telegram Bot API events.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php';

			// Load Telegram Login Controller for Web Login widget callback and shortcode.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-login-controller.php';

			// Load Telegram Mini App Controller for BotFather Web App URL endpoint.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php';

			// Load Slack Event Controller for Slack Events API payloads.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-slack-event-controller.php';

			// Load Discord Interaction Controller for Discord Interactions Endpoint.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-discord-interaction-controller.php';

			// Load Teams Webhook Controller for Microsoft Teams outgoing webhooks.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-teams-webhook-controller.php';

			// Load Google Chat Webhook Controller for Google Chat bot events.
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php';
		}

		// ========================================================================
		// PHASE 6: FRONTEND COMPONENTS INTEGRATION
		// ========================================================================
		// Initialize toolkit shortcodes, Elementor widgets, and Gutenberg blocks.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-toolkit-integration.php';
		WP_MCP_AI_Pro_Toolkit_Integration::get_instance();

		// Register Pro tools when Core fires its registration action.
		add_action( 'wp_mcp_ai_register_tools', 'wp_mcp_ai_pro_register_tools', 20 );

		// Register Pro filters for advanced permissions.
		add_filter( 'wp_mcp_ai_can_run_tool', 'wp_mcp_ai_pro_permission_filter', 20, 4 );

		// Register Pro rate limiting.
		add_filter( 'wp_mcp_ai_rate_limit_allow', 'wp_mcp_ai_pro_rate_limit_filter', 20, 4 );

		// Register Pro tool group mappings.
		add_filter( 'wp_mcp_ai_tool_group_map', 'wp_mcp_ai_pro_tool_group_map', 20 );

		// Register Pro tool categories for recommendations.
		add_filter( 'wp_mcp_ai_tool_categories', 'wp_mcp_ai_pro_tool_categories', 20 );

		/**
		 * Fires after Open Operator System Pro has completed initialization.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wp_mcp_ai_pro_init' );
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_register_tools' ) ) {
	/**
	 * Register Pro tools with the tool registry.
	 *
	 * Works with both Core server (separated architecture) and
	 * combined plugin's tool registry.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_MCP_AI_Core_Server|WP_MCP_AI_Tool_Registry $registry Tool registry or server instance.
	 */
	function wp_mcp_ai_pro_register_tools( $registry ) {
		// Get settings for conditional tool loading.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Load Pro tool files.
		$pro_tools = array(
			// Remote WordPress/WooCommerce Connection tool.
			'WP_MCP_AI_Tool_Remote_WP_Connection'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php',
			// Generic REST API Connection tool.
			'WP_MCP_AI_Tool_Generic_REST_API'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generic-rest-api.php',
			// NPM Package Enhanced Tools (new in 1.1.0).
			'WP_MCP_AI_Tool_Format_Code_Prettier'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-format-code-prettier.php',
			'WP_MCP_AI_Tool_Generate_Email_Template'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-email-template.php',
			'WP_MCP_AI_Tool_Transcode_Video'              => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcode-video.php',
			// EZuite ERP Connection tool.
			'WP_MCP_AI_Tool_EZuite_ERP'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ezuite-erp.php',
			'WP_MCP_AI_Tool_EZuite_ERP_Get_Products'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ezuite-erp-get-products.php',
			// Exec service tools (video, audio, CLI).
			'WP_MCP_AI_Tool_Check_WP_CLI'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php',
			'WP_MCP_AI_Tool_Extract_Video_Frames'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php',
			'WP_MCP_AI_Tool_Get_Video_Metadata'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-video-metadata.php',
			'WP_MCP_AI_Tool_Remove_Background'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php',
			'WP_MCP_AI_Tool_Generate_Jukebox_Music'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php',
			'WP_MCP_AI_Tool_Check_Jukebox_Status'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-jukebox-status.php',
			// Architectural Drawing tool (Pro feature).
			'WP_MCP_AI_Tool_Generate_Architectural_Drawing' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php',
			// Project Management tools (Pro feature).
			'WP_MCP_AI_Tool_Create_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-project.php',
			'WP_MCP_AI_Tool_Update_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-project.php',
			'WP_MCP_AI_Tool_Delete_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-project.php',
			'WP_MCP_AI_Tool_List_Projects'                => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-projects.php',
			'WP_MCP_AI_Tool_Research_Project'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-project.php',
			'WP_MCP_AI_Tool_Create_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-task.php',
			'WP_MCP_AI_Tool_Update_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-task.php',
			'WP_MCP_AI_Tool_Delete_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-task.php',
			'WP_MCP_AI_Tool_List_Tasks'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-tasks.php',
			'WP_MCP_AI_Tool_Create_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-event.php',
			'WP_MCP_AI_Tool_Update_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-event.php',
			'WP_MCP_AI_Tool_Delete_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-event.php',
			'WP_MCP_AI_Tool_List_Events'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-events.php',
			'WP_MCP_AI_Tool_Get_Calendar_View'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-calendar-view.php',
			// NOTE: Core orchestration tools (9 tools) moved to base plugin in includes/orchestration-init.php
			// This includes: create/update/get task plan, manage sessions, detect completion, check exit conditions,
			// analyze loop health, get session status, calculate capacity (Little's Law).
			// Research Enhancement tools (Ralph pattern - Phase 2 - Pro only).
			'WP_MCP_AI_Pro_Tool_Aggregate_Research_Data'  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-aggregate-research-data.php',
			'WP_MCP_AI_Pro_Tool_Extract_Structured_Data'  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-extract-structured-data.php',
			'WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-convert-html-to-markdown.php',
			'WP_MCP_AI_Pro_Tool_Generate_Research_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-generate-research-report.php',
			'WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns'    => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-analyze-data-patterns.php',
			'WP_MCP_AI_Pro_Tool_Verify_Information'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-verify-information.php',
			// Template Management tools (Ralph pattern - Phase 3).
			'WP_MCP_AI_Pro_Tool_Create_Template'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-create-template.php',
			'WP_MCP_AI_Pro_Tool_Instantiate_Template'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-instantiate-template.php',
			'WP_MCP_AI_Pro_Tool_List_Templates'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-list-templates.php',
			'WP_MCP_AI_Pro_Tool_Seed_Template_Library'    => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-seed-template-library.php',
			// ICS calendar export tool (enhanced with NPM package).
			'WP_MCP_AI_Tool_Export_Calendar_ICS'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-export-calendar-ics.php',
			// Product Actualization tool.
			'WP_MCP_AI_Pro_Tool_Product_Actualization'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-product-actualization.php',
			// Product Price Lookup tool.
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-lookup-product-price.php',
			// Social media publishing tools.
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram'  => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'        => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-post-tiktok-video.php',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-post-linkedin-update.php',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-post-google-business-update.php',
			// Social media insights/reporting tools.
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'      => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-business-insights.php',
			// Messaging tools.
			'WP_MCP_AI_Pro_Tool_Send_Whatsapp_Message'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php',
			'WP_MCP_AI_Pro_Tool_Send_Telegram_Message'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php',
			'WP_MCP_AI_Pro_Tool_Schedule_Notify_SMS'      => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-schedule-notify-sms.php',
			// Chat channels tools (Discord, Slack, Teams).
			'WP_MCP_AI_Pro_Tool_Send_Slack_Message'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-slack-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Channels'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-slack-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Messages'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-slack-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Slack_Channel'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-create-slack-channel.php',
			'WP_MCP_AI_Pro_Tool_Send_Discord_Message'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-discord-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Channels'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-discord-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Messages'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-discord-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Discord_Channel'   => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-create-discord-channel.php',
			'WP_MCP_AI_Pro_Tool_Send_Teams_Message'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-teams-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Channels'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-teams-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Messages'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-teams-messages.php',
			'WP_MCP_AI_Pro_Tool_Send_Messenger_Message'   => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-messenger-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-messenger-conversations.php',
			'WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php',
			// Google Chat tools.
			'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-google-chat-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces'   => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-google-chat-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-create-google-chat-space.php',
			// Enhanced Telegram tools.
			'WP_MCP_AI_Pro_Tool_Get_Telegram_Updates'     => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-telegram-updates.php',
			'WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook'  => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php',
			// Enhanced WhatsApp tools.
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template'   => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-send-whatsapp-template.php',
			'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php',
			// Unified broadcast tool.
			'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php',
			// Email and communication tools.
			'WP_MCP_AI_Pro_Tool_Search_Gmail'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php',
			'WP_MCP_AI_Pro_Tool_Search_Drive'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-search-drive.php',
			'WP_MCP_AI_Pro_Tool_Send_Mailjet_Email'       => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-send-mailjet-email.php',
			// Google Workspace tools.
			'WP_MCP_AI_Pro_Tool_Create_Google_Calendar_Event' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-analytics-report.php',
			// Business and accounting tools.
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php',
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty'          => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php',
			// Code and development tools.
			'WP_MCP_AI_Pro_Tool_Create_WPCode_Snippet'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php',
			'WP_MCP_AI_Pro_Tool_Generic_REST'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-generic-rest.php',
			// GitHub tools.
			'WP_MCP_AI_Pro_Tool_Github_Repository_Operations' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-github-repository-operations.php',
			'WP_MCP_AI_Pro_Tool_List_Github_Repositories' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-list-github-repositories.php',
			'WP_MCP_AI_Pro_Tool_Manage_Github_Codespace'  => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-manage-github-codespace.php',
			// Site Creator and related tools.
			'WP_MCP_AI_Pro_Tool_Site_Creator'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-site-creator.php',
			'WP_MCP_AI_Pro_Tool_Install_And_Activate_Plugin' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-install-and-activate-plugin.php',
			'WP_MCP_AI_Pro_Tool_Install_And_Activate_Theme' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-install-and-activate-theme.php',
			'WP_MCP_AI_Pro_Tool_Update_Option'            => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-update-option.php',
			// WP All Import/Export Pro tools.
			'WP_MCP_AI_Pro_Tool_Schedule_All_Export'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-export.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Export'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-export.php',
			'WP_MCP_AI_Pro_Tool_Schedule_All_Import'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-import.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Import'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-import.php',
			// iSAMS School Management System tool.
			'WP_MCP_AI_Tool_ISAMS_Query'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-isams-query.php',
			// Web Browser Automation tool (Playwright-based).
			'WP_MCP_AI_Tool_Web_Browser'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-browser.php',
		);

		// Add AI CPT Management tools if enabled.
		if ( ! empty( $settings['enable_ai_cpt_management'] ) ) {
			$cpt_research_tools = array(
				'WP_MCP_AI_Tool_Research_Post' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-post.php',
				'WP_MCP_AI_Tool_Research_Page' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-page.php',
			);
			$pro_tools          = array_merge( $pro_tools, $cpt_research_tools );
		}

		// Add ECA management tools if enabled.
		if ( ! empty( $settings['enable_eca_management'] ) ) {
			$eca_tools = array(
				// ECA Management (CRUD).
				'WP_MCP_AI_Tool_Create_ECA'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-eca.php',
				'WP_MCP_AI_Tool_List_ECAs'                => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-ecas.php',
				'WP_MCP_AI_Tool_Get_ECA'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-eca.php',
				'WP_MCP_AI_Tool_Update_ECA'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-eca.php',
				'WP_MCP_AI_Tool_Delete_ECA'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-eca.php',
				// Student Management (CRUD).
				'WP_MCP_AI_Tool_Create_Student'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-student.php',
				'WP_MCP_AI_Tool_List_Students'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-students.php',
				'WP_MCP_AI_Tool_Get_Student'              => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-student.php',
				'WP_MCP_AI_Tool_Update_Student'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-student.php',
				'WP_MCP_AI_Tool_Delete_Student'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-student.php',
				// Specialized ECA tools.
				'WP_MCP_AI_Tool_Enroll_Student_ECA'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-enroll-student-eca.php',
				'WP_MCP_AI_Tool_Sync_Students_From_ISAMS' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-sync-students-from-isams.php',
				'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php',
				'WP_MCP_AI_Tool_Research_ECA'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-eca.php',
			);
			$pro_tools = array_merge( $pro_tools, $eca_tools );
		}

		// Add quiz tools if enabled.
		if ( ! empty( $settings['enable_quiz_system'] ) ) {
			$quiz_tools = array(
				// Quiz CRUD.
				'WP_MCP_AI_Tool_Create_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz.php',
				'WP_MCP_AI_Tool_List_Quizzes'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-quizzes.php',
				'WP_MCP_AI_Tool_Update_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-quiz.php',
				'WP_MCP_AI_Tool_Delete_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-quiz.php',
				// Quiz specialized tools.
				'WP_MCP_AI_Tool_Submit_Quiz_Answer'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-quiz-answer.php',
				'WP_MCP_AI_Tool_Grade_Quiz'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-grade-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz_Submissions' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz-submissions.php',
				'WP_MCP_AI_Tool_Get_Quiz_Results'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz-results.php',
				'WP_MCP_AI_Tool_Get_Quiz_Analytics'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz-analytics.php',
				'WP_MCP_AI_Tool_Research_Quiz_Topic'  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-quiz-topic.php',
				// KaTeX math rendering tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Render_Math_Equation' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-render-math-equation.php',
			);
			$pro_tools  = array_merge( $pro_tools, $quiz_tools );
		}

		// Add Fantasy Football tools if enabled.
		if ( ! empty( $settings['enable_fantasy_football'] ) ) {
			$ff_tools = array(
				// Yahoo Fantasy Sports API tools.
				'WP_MCP_AI_Tool_Yahoo_FF_Auth'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-auth.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Leagues'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-leagues.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Roster'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-roster.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Get_Player_Stats'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-get-player-stats.php',
				'WP_MCP_AI_Tool_Yahoo_FF_Trade_Analyzer'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-trade-analyzer.php',
				'WP_MCP_AI_Tool_Yahoo_FF_League_Standings'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-yahoo-ff-league-standings.php',
				// ESPN Fantasy Football API tools.
				'WP_MCP_AI_Tool_ESPN_Fantasy_Get_League'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-get-league.php',
				'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Teams'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-get-teams.php',
				'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Roster'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-get-roster.php',
				'WP_MCP_AI_Tool_ESPN_Fantasy_Get_Standings'  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-get-standings.php',
				'WP_MCP_AI_Tool_ESPN_Fantasy_Analyze_Lineup' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-analyze-lineup.php',
				'WP_MCP_AI_Tool_ESPN_Fantasy_Sync_League'    => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-espn-fantasy-sync-league.php',
				// AI-powered FF tools.
				'WP_MCP_AI_Tool_FF_Generate_Team_Logo'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-generate-team-logo.php',
				'WP_MCP_AI_Tool_FF_Create_League_Report'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-create-league-report.php',
				'WP_MCP_AI_Tool_FF_Player_Research'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-ff-player-research.php',
			);
			$pro_tools  = array_merge( $pro_tools, $ff_tools );
		}

		// Add places management tools if enabled.
		if ( ! empty( $settings['enable_places_management'] ) ) {
			$places_tools = array(
				'WP_MCP_AI_Tool_Create_Place'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-place.php',
				'WP_MCP_AI_Tool_List_Places'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-places.php',
				'WP_MCP_AI_Tool_Update_Place'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-place.php',
				'WP_MCP_AI_Tool_Delete_Place'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-place.php',
				'WP_MCP_AI_Tool_Get_Place'              => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-place.php',
				'WP_MCP_AI_Tool_Search_And_Save_Places' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-and-save-places.php',
				'WP_MCP_AI_Tool_Research_Place'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-place.php',
				// Turf.js geospatial analysis tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Analyze_Geospatial'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-geospatial.php',
			);
			$pro_tools    = array_merge( $pro_tools, $places_tools );
		}

		// Add health and wellness management tools if enabled.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			$health_wellness_tools = array(
				// Member Management (CRUD).
				'WP_MCP_AI_Tool_Create_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-member.php',
				'WP_MCP_AI_Tool_List_Members'              => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-members.php',
				'WP_MCP_AI_Tool_Get_Member'                => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-member.php',
				'WP_MCP_AI_Tool_Update_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-member.php',
				'WP_MCP_AI_Tool_Delete_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-member.php',
				// Policy Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-policy.php',
				'WP_MCP_AI_Tool_List_Policies'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-policies.php',
				'WP_MCP_AI_Tool_Get_Policy'                => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-policy.php',
				'WP_MCP_AI_Tool_Update_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-policy.php',
				'WP_MCP_AI_Tool_Delete_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-policy.php',
				'WP_MCP_AI_Tool_Search_Policies'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-policies.php',
				// Prescription Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-prescription.php',
				'WP_MCP_AI_Tool_List_Prescriptions'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-prescriptions.php',
				'WP_MCP_AI_Tool_Get_Prescription'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-prescription.php',
				'WP_MCP_AI_Tool_Update_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-prescription.php',
				'WP_MCP_AI_Tool_Delete_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-prescription.php',
				'WP_MCP_AI_Tool_Search_Prescriptions'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-prescriptions.php',
				// Medical Record Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-medical-record.php',
				'WP_MCP_AI_Tool_List_Medical_Records'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-medical-records.php',
				'WP_MCP_AI_Tool_Get_Medical_Record'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-medical-record.php',
				'WP_MCP_AI_Tool_Update_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-medical-record.php',
				'WP_MCP_AI_Tool_Delete_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-medical-record.php',
				'WP_MCP_AI_Tool_Search_Medical_Records'    => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-medical-records.php',
				// Checkup/Appointment Management (CRUD + Specialized).
				'WP_MCP_AI_Tool_Create_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-checkup.php',
				'WP_MCP_AI_Tool_List_Checkups'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-checkups.php',
				'WP_MCP_AI_Tool_Get_Checkup'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-checkup.php',
				'WP_MCP_AI_Tool_Update_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-checkup.php',
				'WP_MCP_AI_Tool_Delete_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-checkup.php',
				'WP_MCP_AI_Tool_Get_Upcoming_Checkups'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-upcoming-checkups.php',
				// Allergy Management (CRUD).
				'WP_MCP_AI_Tool_Create_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-allergy.php',
				'WP_MCP_AI_Tool_List_Allergies'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-allergies.php',
				'WP_MCP_AI_Tool_Get_Allergy'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-allergy.php',
				'WP_MCP_AI_Tool_Update_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-allergy.php',
				'WP_MCP_AI_Tool_Delete_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-allergy.php',
				// Specialized Health & Wellness Tools.
				'WP_MCP_AI_Tool_Get_Member_Health_Summary' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-member-health-summary.php',
				'WP_MCP_AI_Tool_Get_Medication_Schedule'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-medication-schedule.php',
				'WP_MCP_AI_Tool_Research_Policy'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-policy.php',
				// Chart.js data visualization tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Generate_Health_Chart'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-health-chart.php',
				// Industry Standards-Based Health Management Tools (FHIR, HIPAA, PHR).
				'WP_MCP_AI_Tool_Create_Health_Reminder'    => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-health-reminder.php',
				'WP_MCP_AI_Tool_Track_Vaccinations'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-track-vaccinations.php',
				'WP_MCP_AI_Tool_Log_Vital_Signs'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-log-vital-signs.php',
				'WP_MCP_AI_Tool_Export_FHIR_Data'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-export-fhir-data.php',
				'WP_MCP_AI_Tool_Manage_Care_Plan'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-manage-care-plan.php',
				// Health Research: compile data from CCT, options, files, and vector store.
				'WP_MCP_AI_Tool_Compile_Health_Research_Data' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-compile-health-research-data.php',
			);
			$pro_tools             = array_merge( $pro_tools, $health_wellness_tools );

			// Auto-include JetEngine CCT tool when health management is enabled and JetEngine is active.
			// This allows the AI to create/update/delete vital_signs CCT items directly without using create_post.
			if ( function_exists( 'jet_engine' ) && ! isset( $pro_tools['WP_MCP_AI_Pro_Tool_JetEngine'] ) ) {
				$pro_tools['WP_MCP_AI_Pro_Tool_JetEngine'] = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine.php';
			}
		}

		// Add WooCommerce tools if enabled.
		if ( wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings ) ) {
			$woo_tools = array(
				'WP_MCP_AI_Pro_Tool_Woo_Products'  => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-products.php',
				'WP_MCP_AI_Pro_Tool_Woo_Orders'    => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-orders.php',
				'WP_MCP_AI_Pro_Tool_Woo_Customers' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-customers.php',
				'WP_MCP_AI_Pro_Tool_Woo_Coupons'   => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-coupons.php',
				'WP_MCP_AI_Tool_Research_Product'  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-research-product.php',
			);
			$pro_tools = array_merge( $pro_tools, $woo_tools );
		}

		// Add JetEngine tools if enabled.
		if ( ! empty( $settings['enable_jetengine_tools'] ) ) {
			$jetengine_tools = array(
				'WP_MCP_AI_Pro_Tool_JetEngine' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine.php',
			);
			$pro_tools       = array_merge( $pro_tools, $jetengine_tools );
		}

		// Add Elementor tools if enabled.
		if ( ! empty( $settings['enable_elementor_widgets'] ) ) {
			$elementor_tools = array(
				'WP_MCP_AI_Pro_Tool_Elementor' => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-elementor.php',
			);
			$pro_tools       = array_merge( $pro_tools, $elementor_tools );
		}

		// Add Media Toolkit tools if enabled.
		if ( ! empty( $settings['enable_media_toolkit'] ) ) {
			$media_toolkit_tools = array(
				'WP_MCP_AI_Tool_List_Media_Templates'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-media-templates.php',
				'WP_MCP_AI_Tool_Apply_Media_Template'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-apply-media-template.php',
				'WP_MCP_AI_Tool_Create_Media_Template'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-media-template.php',
				'WP_MCP_AI_Tool_Create_Media_Collection'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-media-collection.php',
				'WP_MCP_AI_Tool_Process_Collection'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-process-collection.php',
				'WP_MCP_AI_Tool_Apply_Collection_Template' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-apply-collection-template.php',
				// Sharp image optimization tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Optimize_Image_Sharp'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-optimize-image-sharp.php',
			);
			$pro_tools           = array_merge( $pro_tools, $media_toolkit_tools );
		}

		// Add E-commerce Toolkit tools if enabled (Phase 2 - New Pro Toolkits).
		if ( ! empty( $settings['enable_ecommerce_toolkit'] ) ) {
			$ecommerce_toolkit_tools = array(
				// Product Management tools.
				'WP_MCP_AI_Tool_Create_Product_Advanced'  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php',
				'WP_MCP_AI_Tool_Bulk_Update_Products'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php',
				'WP_MCP_AI_Tool_Import_Products_CSV'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-import-products-csv.php',
				'WP_MCP_AI_Tool_Export_Products_Report'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php',
				'WP_MCP_AI_Tool_Sync_Product_Inventory'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-sync-product-inventory.php',
				// Order Management tools.
				'WP_MCP_AI_Tool_Generate_Invoice_PDF'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-invoice-pdf.php',
				'WP_MCP_AI_Tool_Bulk_Order_Status_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-bulk-order-status-update.php',
				'WP_MCP_AI_Tool_Get_Order_Analytics'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-get-order-analytics.php',
				'WP_MCP_AI_Tool_Process_Order_Workflow'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-process-order-workflow.php',
				'WP_MCP_AI_Tool_Refund_Order_Advanced'    => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-refund-order-advanced.php',
				// Customer Management tools.
				'WP_MCP_AI_Tool_Segment_Customers'        => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-segment-customers.php',
				'WP_MCP_AI_Tool_Customer_Lifetime_Value'  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-customer-lifetime-value.php',
				'WP_MCP_AI_Tool_Export_Customer_Data'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-export-customer-data.php',
				// Inventory & Stock tools.
				'WP_MCP_AI_Tool_Track_Inventory_Movement' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-track-inventory-movement.php',
				'WP_MCP_AI_Tool_Low_Stock_Alert_Automation' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-low-stock-alert-automation.php',
				'WP_MCP_AI_Tool_Inventory_Forecast'       => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-inventory-forecast.php',
				// Marketing & Sales tools.
				'WP_MCP_AI_Tool_Create_Discount_Campaign' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-create-discount-campaign.php',
				'WP_MCP_AI_Tool_Abandoned_Cart_Recovery'  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-abandoned-cart-recovery.php',
				'WP_MCP_AI_Tool_Upsell_Recommendations'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-upsell-recommendations.php',
				'WP_MCP_AI_Tool_Sales_Performance_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-sales-performance-dashboard.php',
			);
			$pro_tools               = array_merge( $pro_tools, $ecommerce_toolkit_tools );
		}

		// Add Social Media Toolkit tools if enabled (Phase 3 - New Pro Toolkits).
		if ( ! empty( $settings['enable_social_media_toolkit'] ) ) {
			$social_media_toolkit_tools = array(
				// Content Publishing tools.
				'WP_MCP_AI_Tool_Post_To_Multiple_Platforms' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-post-to-multiple-platforms.php',
				'WP_MCP_AI_Tool_Schedule_Social_Post'      => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-schedule-social-post.php',
				'WP_MCP_AI_Tool_Bulk_Schedule_Posts'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-bulk-schedule-posts.php',
				'WP_MCP_AI_Tool_Auto_Optimize_Images'      => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-auto-optimize-images.php',
				'WP_MCP_AI_Tool_Create_Social_Video'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-create-social-video.php',
				// Engagement Management tools.
				'WP_MCP_AI_Tool_Monitor_Mentions_Replies'  => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-monitor-mentions-replies.php',
				'WP_MCP_AI_Tool_Auto_Respond_Messages'     => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-auto-respond-messages.php',
				'WP_MCP_AI_Tool_Moderate_Comments'         => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-moderate-comments.php',
				// Analytics & Insights tools.
				'WP_MCP_AI_Tool_Get_Cross_Platform_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-get-cross-platform-analytics.php',
				'WP_MCP_AI_Tool_Track_Hashtag_Performance' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-track-hashtag-performance.php',
				'WP_MCP_AI_Tool_Competitor_Analysis'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-competitor-analysis.php',
				'WP_MCP_AI_Tool_Influencer_Identification' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-influencer-identification.php',
				// Content Management tools.
				'WP_MCP_AI_Tool_Create_Content_Calendar'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-create-content-calendar.php',
				'WP_MCP_AI_Tool_Generate_Post_Ideas'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-generate-post-ideas.php',
				'WP_MCP_AI_Tool_Social_Listening_Trends'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-social-listening-trends.php',
			);
			$pro_tools                  = array_merge( $pro_tools, $social_media_toolkit_tools );
		}

		// Add Advanced Analytics Toolkit tools if enabled (Phase 4 - New Pro Toolkits).
		if ( ! empty( $settings['enable_advanced_analytics_toolkit'] ) ) {
			$analytics_toolkit_tools = array(
				// Data Collection & Processing tools.
				'WP_MCP_AI_Tool_Collect_Custom_Metrics'   => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-collect-custom-metrics.php',
				'WP_MCP_AI_Tool_Data_Warehouse_Sync'      => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-data-warehouse-sync.php',
				'WP_MCP_AI_Tool_Real_Time_Event_Tracking' => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-real-time-event-tracking.php',
				// Analytics & Reporting tools.
				'WP_MCP_AI_Tool_Generate_Executive_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-generate-executive-dashboard.php',
				'WP_MCP_AI_Tool_Cohort_Analysis'          => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-cohort-analysis.php',
				'WP_MCP_AI_Tool_Funnel_Analysis'          => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-funnel-analysis.php',
				'WP_MCP_AI_Tool_Attribution_Modeling'     => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-attribution-modeling.php',
				// Predictive Analytics tools.
				'WP_MCP_AI_Tool_Revenue_Forecast'         => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-revenue-forecast.php',
				'WP_MCP_AI_Tool_Churn_Prediction'         => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-churn-prediction.php',
				'WP_MCP_AI_Tool_Customer_Segmentation_ML' => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-customer-segmentation-ml.php',
				// Export & Integration tools.
				'WP_MCP_AI_Tool_Export_Analytics_API'     => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-export-analytics-api.php',
				'WP_MCP_AI_Tool_Create_Custom_Report'     => WP_MCP_AI_PRO_PATH . 'includes/tools/analytics/class-wp-mcp-ai-tool-create-custom-report.php',
			);
			$pro_tools               = array_merge( $pro_tools, $analytics_toolkit_tools );
		}

		// Add Multi-language Content Toolkit tools if enabled (Phase 5 - New Pro Toolkits).
		if ( ! empty( $settings['enable_multilingual_toolkit'] ) ) {
			$multilingual_toolkit_tools = array(
				// Translation Management tools.
				'WP_MCP_AI_Tool_Auto_Translate_Content'    => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-auto-translate-content.php',
				'WP_MCP_AI_Tool_Translate_WooCommerce_Products' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-translate-woocommerce-products.php',
				'WP_MCP_AI_Tool_Translation_Memory_Search' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-translation-memory-search.php',
				'WP_MCP_AI_Tool_Export_Import_Translations' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-export-import-translations.php',
				// Localization tools.
				'WP_MCP_AI_Tool_Detect_Content_Language'   => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-detect-content-language.php',
				'WP_MCP_AI_Tool_Localize_Dates_Currencies' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-localize-dates-currencies.php',
				'WP_MCP_AI_Tool_RTL_Content_Optimization'  => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-rtl-content-optimization.php',
				// Quality Assurance tools.
				'WP_MCP_AI_Tool_Translation_Quality_Check' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-translation-quality-check.php',
				'WP_MCP_AI_Tool_Find_Untranslated_Strings' => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-find-untranslated-strings.php',
				'WP_MCP_AI_Tool_Multilingual_SEO_Audit'    => WP_MCP_AI_PRO_PATH . 'includes/tools/multilingual/class-wp-mcp-ai-tool-multilingual-seo-audit.php',
			);
			$pro_tools                  = array_merge( $pro_tools, $multilingual_toolkit_tools );
		}

		// Add Financial Planner Toolkit tools if enabled (Phase 2.5 - New Pro Toolkits).
		if ( ! empty( $settings['enable_financial_planner_toolkit'] ) ) {
			$financial_planner_toolkit_tools = array(
				// Retirement Planning tools.
				'WP_MCP_AI_Tool_Retirement_Calculator'     => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-retirement-calculator.php',
				'WP_MCP_AI_Tool_IRA_Roth_Comparison'       => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-ira-roth-comparison.php',
				'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-withdrawal-strategy-planner.php',
				'WP_MCP_AI_Tool_Social_Security_Optimizer' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-social-security-optimizer.php',
				'WP_MCP_AI_Tool_Pension_Analyzer'          => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-pension-analyzer.php',
				// Budget & Expense Tracking tools.
				'WP_MCP_AI_Tool_Budget_Planner'            => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-budget-planner.php',
				'WP_MCP_AI_Tool_Expense_Tracker'           => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-expense-tracker.php',
				'WP_MCP_AI_Tool_Net_Worth_Calculator'      => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-net-worth-calculator.php',
				'WP_MCP_AI_Tool_Cash_Flow_Analyzer'        => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-cash-flow-analyzer.php',
				'WP_MCP_AI_Tool_Bank_Account_Sync'         => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-bank-account-sync.php',
				// Investment & Portfolio tools.
				'WP_MCP_AI_Tool_Portfolio_Visualizer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-portfolio-visualizer.php',
				'WP_MCP_AI_Tool_Asset_Allocation_Planner'  => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-asset-allocation-planner.php',
				'WP_MCP_AI_Tool_Investment_Return_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-investment-return-calculator.php',
				'WP_MCP_AI_Tool_Rebalancing_Analyzer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-rebalancing-analyzer.php',
				'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-tax-loss-harvesting-tracker.php',
				// Debt & Loan Management tools.
				'WP_MCP_AI_Tool_Debt_Payoff_Calculator'    => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-debt-payoff-calculator.php',
				'WP_MCP_AI_Tool_Mortgage_Calculator'       => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-mortgage-calculator.php',
				'WP_MCP_AI_Tool_Credit_Score_Tracker'      => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-credit-score-tracker.php',
				// Goal Planning & Savings tools.
				'WP_MCP_AI_Tool_Savings_Goal_Planner'      => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-savings-goal-planner.php',
				'WP_MCP_AI_Tool_Emergency_Fund_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-emergency-fund-calculator.php',
				// Financial Literacy tools.
				'WP_MCP_AI_Tool_Financial_Health_Score'    => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-health-score.php',
				'WP_MCP_AI_Tool_Tax_Estimator'             => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-tax-estimator.php',
				'WP_MCP_AI_Tool_College_Savings_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-college-savings-calculator.php',
				'WP_MCP_AI_Tool_Insurance_Needs_Analyzer'  => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-insurance-needs-analyzer.php',
			);
			$pro_tools                       = array_merge( $pro_tools, $financial_planner_toolkit_tools );
		}

		// Add Video Production Toolkit tools if enabled (Phase 6 - New Pro Toolkits).
		if ( ! empty( $settings['enable_video_production_toolkit'] ) ) {
			$video_production_toolkit_tools = array(
				// Video Creation tools.
				'WP_MCP_AI_Tool_Create_Video_From_Images'  => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-create-video-from-images.php',
				'WP_MCP_AI_Tool_Add_Watermark_To_Video'    => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-add-watermark-to-video.php',
				'WP_MCP_AI_Tool_Generate_Video_Captions'   => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-generate-video-captions.php',
				'WP_MCP_AI_Tool_Merge_Videos'              => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-merge-videos.php',
				// Video Editing tools.
				'WP_MCP_AI_Tool_Trim_Video'                => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-trim-video.php',
				'WP_MCP_AI_Tool_Resize_Video_Resolution'   => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-resize-video-resolution.php',
				'WP_MCP_AI_Tool_Adjust_Video_Speed'        => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-adjust-video-speed.php',
				// Video Optimization tools.
				'WP_MCP_AI_Tool_Compress_Video'            => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-compress-video.php',
				'WP_MCP_AI_Tool_Convert_Video_Format'      => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-convert-video-format.php',
				'WP_MCP_AI_Tool_Optimize_For_Platform'     => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-optimize-for-platform.php',
				// Video Analysis tools.
				'WP_MCP_AI_Tool_Extract_Video_Metadata'    => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-extract-video-metadata.php',
				'WP_MCP_AI_Tool_Generate_Video_Thumbnails' => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-generate-video-thumbnails.php',
			);
			$pro_tools                      = array_merge( $pro_tools, $video_production_toolkit_tools );
		}

		// Add Regulatory Registration Toolkit tools if enabled.
		if ( ! empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			$regulatory_registration_tools = array(
				// Product Management Tools.
				'WP_MCP_AI_Tool_Create_Reg_Product'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-create-reg-product.php',
				'WP_MCP_AI_Tool_List_Reg_Products'         => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-reg-products.php',
				'WP_MCP_AI_Tool_Get_Reg_Product'           => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-reg-product.php',
				'WP_MCP_AI_Tool_Update_Reg_Product'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-update-reg-product.php',
				'WP_MCP_AI_Tool_Delete_Reg_Product'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-delete-reg-product.php',
				'WP_MCP_AI_Tool_Search_Reg_Products'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-search-reg-products.php',
				'WP_MCP_AI_Tool_Duplicate_Reg_Product'     => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-duplicate-reg-product.php',
				'WP_MCP_AI_Tool_Validate_Reg_Product'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-reg-product.php',
				// Registration Management Tools.
				'WP_MCP_AI_Tool_Create_Registration'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-create-registration.php',
				'WP_MCP_AI_Tool_List_Registrations'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-registrations.php',
				'WP_MCP_AI_Tool_Get_Registration'          => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-registration.php',
				'WP_MCP_AI_Tool_Update_Registration_Status' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-update-registration-status.php',
				'WP_MCP_AI_Tool_List_Expiring_Registrations' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-expiring-registrations.php',
				'WP_MCP_AI_Tool_Submit_Registration'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-submit-registration.php',
				'WP_MCP_AI_Tool_Approve_Registration'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-approve-registration.php',
				'WP_MCP_AI_Tool_Renew_Registration'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-renew-registration.php',
				'WP_MCP_AI_Tool_Get_Registration_Timeline' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-registration-timeline.php',
				'WP_MCP_AI_Tool_List_Registrations_By_Country' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-registrations-by-country.php',
				// Document Management Tools.
				'WP_MCP_AI_Tool_List_Reg_Documents'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-reg-documents.php',
				'WP_MCP_AI_Tool_Check_Document_Expiry'     => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-check-document-expiry.php',
				'WP_MCP_AI_Tool_Upload_Reg_Document'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-upload-reg-document.php',
				'WP_MCP_AI_Tool_Update_Reg_Document'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-update-reg-document.php',
				'WP_MCP_AI_Tool_Get_Reg_Document'          => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-reg-document.php',
				'WP_MCP_AI_Tool_Validate_Document_Checklist' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-document-checklist.php',
				'WP_MCP_AI_Tool_Generate_Submission_Pack'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-submission-pack.php',
				'WP_MCP_AI_Tool_Track_Document_Version'    => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-track-document-version.php',
				// Compliance Tools.
				'WP_MCP_AI_Tool_Add_Regulatory_Requirement' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-add-regulatory-requirement.php',
				'WP_MCP_AI_Tool_Get_Regulatory_Requirements' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-regulatory-requirements.php',
				'WP_MCP_AI_Tool_Check_Product_Compliance'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-check-product-compliance.php',
				'WP_MCP_AI_Tool_Validate_INCI_Ingredients' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-inci-ingredients.php',
				'WP_MCP_AI_Tool_Check_HS_Code'             => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-check-hs-code.php',
				'WP_MCP_AI_Tool_Get_Regulatory_Updates'    => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-regulatory-updates.php',
				// PDF Generation Tools (Phase 3).
				'WP_MCP_AI_Tool_Generate_PDF_Dossier'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-pdf-dossier.php',
				'WP_MCP_AI_Tool_Generate_Cover_Letter'     => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-cover-letter.php',
				'WP_MCP_AI_Tool_Generate_Compliance_Certificate' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-compliance-certificate.php',
				// API Integration Tools (Phase 3).
				'WP_MCP_AI_Tool_Sync_With_NMRA'            => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-sync-with-nmra.php',
				'WP_MCP_AI_Tool_Sync_With_MOHAP'           => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-sync-with-mohap.php',
				'WP_MCP_AI_Tool_Check_Authority_Status'    => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-check-authority-status.php',
				'WP_MCP_AI_Tool_Submit_To_Authority'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-submit-to-authority.php',
				// Reporting Tools (Phase 3).
				'WP_MCP_AI_Tool_Generate_Compliance_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-compliance-report.php',
				'WP_MCP_AI_Tool_Generate_Pipeline_Report'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-pipeline-report.php',
				'WP_MCP_AI_Tool_Generate_Expiry_Forecast'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-expiry-forecast.php',
				'WP_MCP_AI_Tool_Generate_Country_Performance' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-country-performance.php',
				'WP_MCP_AI_Tool_Generate_Cost_Analysis'    => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-cost-analysis.php',
				// Excel Import/Export Tools (Phase 3).
				'WP_MCP_AI_Tool_Import_Products_From_Excel' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-import-products-from-excel.php',
				'WP_MCP_AI_Tool_Export_Products_To_Excel'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-export-products-to-excel.php',
				'WP_MCP_AI_Tool_Import_Registrations_From_Excel' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-import-registrations-from-excel.php',
				'WP_MCP_AI_Tool_Export_Registrations_To_Excel' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-export-registrations-to-excel.php',
				'WP_MCP_AI_Tool_Validate_Excel_Import'     => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-excel-import.php',
				// Email Notification Tools (Phase 3).
				'WP_MCP_AI_Tool_Configure_Email_Notifications' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-configure-email-notifications.php',
				'WP_MCP_AI_Tool_Send_Expiry_Alerts'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-send-expiry-alerts.php',
				'WP_MCP_AI_Tool_Send_Status_Change_Notification' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-send-status-change-notification.php',
				'WP_MCP_AI_Tool_Get_Notification_History'  => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-notification-history.php',
				// Workflow Automation Tools (Phase 3).
				'WP_MCP_AI_Tool_Create_Workflow_Rule'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-create-workflow-rule.php',
				'WP_MCP_AI_Tool_List_Workflow_Rules'       => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-list-workflow-rules.php',
				'WP_MCP_AI_Tool_Update_Workflow_Rule'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-update-workflow-rule.php',
				'WP_MCP_AI_Tool_Delete_Workflow_Rule'      => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-delete-workflow-rule.php',
				'WP_MCP_AI_Tool_Test_Workflow_Rule'        => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-test-workflow-rule.php',
				'WP_MCP_AI_Tool_Get_Workflow_Execution_Log' => WP_MCP_AI_PRO_PATH . 'includes/tools/regulatory-registration/class-wp-mcp-ai-tool-get-workflow-execution-log.php',
			);
			$pro_tools                     = array_merge( $pro_tools, $regulatory_registration_tools );
		}

		// Add Document Generation Toolkit tools if enabled.
		if ( ! empty( $settings['enable_document_generation_toolkit'] ) ) {
			$document_generation_tools = array(
				// Core document generation tools (Pro).
				'WP_MCP_AI_Tool_Pro_PDF'            => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php',
				'WP_MCP_AI_Tool_Pro_Word'           => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php',
				'WP_MCP_AI_Tool_Pro_Excel_Document' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php',
				// Simplified document generation tools.
				'WP_MCP_AI_Tool_Generate_PDF'       => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php',
				'WP_MCP_AI_Tool_Generate_Word'      => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php',
				'WP_MCP_AI_Tool_Generate_Excel'     => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php',
				// PDF manipulation tools.
				'WP_MCP_AI_Tool_Extract_PDF_Text'   => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php',
				'WP_MCP_AI_Tool_OCR_PDF_Text'       => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php',
				'WP_MCP_AI_Tool_Pro_Document_OCR'   => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php',
				'WP_MCP_AI_Tool_HTML_To_PDF'        => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php',
				'WP_MCP_AI_Tool_Merge_PDFs'         => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php',
				'WP_MCP_AI_Tool_Add_Watermark_To_PDF' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php',
				'WP_MCP_AI_Tool_Generate_Invoice_PDF' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php',
				// Excel data tools.
				'WP_MCP_AI_Tool_Excel_Data_Import'  => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php',
				'WP_MCP_AI_Tool_Excel_Data_Export'  => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-export.php',
			);
			$pro_tools                 = array_merge( $pro_tools, $document_generation_tools );
		}

		/**
		 * Filter the list of Pro tools to register.
		 *
		 * @since 1.0.0
		 *
		 * @param array $pro_tools Array of tool class names and file paths.
		 */
		$pro_tools = apply_filters( 'wp_mcp_ai_pro_tools', $pro_tools );

		/**
		 * Trigger the action to load toolkit-specific tools.
		 *
		 * This allows toolkits (Architect Agent, Site Creator, etc.) to register their tools
		 * with the registry by hooking into this action.
		 *
		 * @since 1.1.0
		 */
		do_action( 'wp_mcp_ai_load_pro_tools' );

		foreach ( $pro_tools as $class => $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;

				if ( class_exists( $class ) ) {
					$should_register = true;

					// Check if tool declares an availability check.
					if ( method_exists( $class, 'is_available' ) ) {
						$should_register = (bool) call_user_func( array( $class, 'is_available' ) );
					}

					if ( $should_register ) {
						$registry->register_tool( new $class() );
					}
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_permission_filter' ) ) {
	/**
	 * Pro permission filter for advanced access control.
	 *
	 * Implements field-level and record-level access policies.
	 *
	 * @since 1.0.0
	 *
	 * @param bool                          $can_run   Whether tool can run.
	 * @param WP_MCP_AI_Core_Tool_Interface $tool      Tool instance.
	 * @param array                         $arguments Tool arguments.
	 * @param WP_User|null                  $user      Current user.
	 * @return bool Whether tool can run.
	 */
	function wp_mcp_ai_pro_permission_filter( $can_run, $tool, $arguments, $user ) {
		// Skip if already denied.
		if ( ! $can_run ) {
			return false;
		}

		// Get Pro permission settings.
		$settings = get_option( 'wp_mcp_ai_pro_permissions', array() );

		if ( empty( $settings ) ) {
			return $can_run;
		}

		$tool_slug = $tool->get_slug();

		// Check tool-specific permissions.
		if ( isset( $settings['tools'][ $tool_slug ] ) ) {
			$tool_perms = $settings['tools'][ $tool_slug ];

			// Check allowed roles.
			if ( ! empty( $tool_perms['allowed_roles'] ) && $user ) {
				$user_roles = $user->roles;
				$allowed    = array_intersect( $user_roles, $tool_perms['allowed_roles'] );

				if ( empty( $allowed ) ) {
					return false;
				}
			}

			// Check denied roles.
			if ( ! empty( $tool_perms['denied_roles'] ) && $user ) {
				$user_roles = $user->roles;
				$denied     = array_intersect( $user_roles, $tool_perms['denied_roles'] );

				if ( ! empty( $denied ) ) {
					return false;
				}
			}
		}

		return $can_run;
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_rate_limit_filter' ) ) {
	/**
	 * Pro rate limiting filter.
	 *
	 * Implements per-user, per-tool quotas and burst control.
	 *
	 * @since 1.0.0
	 *
	 * @param bool         $allow   Whether to allow execution.
	 * @param string       $slug    Tool slug.
	 * @param WP_User|null $user    Current user.
	 * @param array        $context Execution context.
	 * @return bool Whether to allow execution.
	 */
	function wp_mcp_ai_pro_rate_limit_filter( $allow, $slug, $user, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter callback signature requires $context parameter.
		// Skip if already rate limited.
		if ( ! $allow ) {
			return false;
		}

		// Get Pro rate limit settings.
		$settings = get_option( 'wp_mcp_ai_pro_rate_limits', array() );

		if ( empty( $settings ) ) {
			return $allow;
		}

		// Check global rate limits.
		if ( ! empty( $settings['global_requests_per_minute'] ) ) {
			$limit = absint( $settings['global_requests_per_minute'] );
			$key   = 'wp_mcp_ai_pro_rate_global_' . floor( time() / 60 );
			$count = (int) get_transient( $key );

			if ( $count >= $limit ) {
				return false;
			}

			set_transient( $key, $count + 1, 60 );
		}

		// Check per-user rate limits.
		if ( ! empty( $settings['user_requests_per_minute'] ) && $user ) {
			$limit = absint( $settings['user_requests_per_minute'] );
			$key   = 'wp_mcp_ai_pro_rate_user_' . $user->ID . '_' . floor( time() / 60 );
			$count = (int) get_transient( $key );

			if ( $count >= $limit ) {
				return false;
			}

			set_transient( $key, $count + 1, 60 );
		}

		// Check per-tool rate limits.
		if ( ! empty( $settings['tool_limits'][ $slug ]['requests_per_minute'] ) ) {
			$limit = absint( $settings['tool_limits'][ $slug ]['requests_per_minute'] );
			$key   = 'wp_mcp_ai_pro_rate_tool_' . $slug . '_' . floor( time() / 60 );
			$count = (int) get_transient( $key );

			if ( $count >= $limit ) {
				return false;
			}

			set_transient( $key, $count + 1, 60 );
		}

		return $allow;
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) ) {
	/**
	 * Add Pro tools to the tool group map.
	 *
	 * Extends the core tool group map to include Pro addon tools
	 * so they appear in the assistant edit page.
	 *
	 * @since 1.0.0
	 *
	 * @param array $group_map Associative array of tool slugs to group identifiers.
	 * @return array Modified group map with Pro tools added.
	 */
	function wp_mcp_ai_pro_tool_group_map( $group_map ) {
		// Pro tools and their group assignments.
		$pro_tools = array(
			// Remote WordPress/WooCommerce Connection - Requires external API access.
			'remote_wp_connection'            => 'external-tools',
			// Exec service tools (video, audio, CLI) - Pro features.
			'check_wp_cli'                    => 'wordpress-core',
			'extract_video_frames'            => 'wordpress-core',
			'get_video_metadata'              => 'wordpress-core',
			'remove_background'               => 'wordpress-core',
			'generate_jukebox_music'          => 'external-tools',
			'check_jukebox_status'            => 'external-tools',
			// Product Actualization - Requires external APIs (OpenAI, Gemini).
			'product_actualization'           => 'external-tools',
			// Product Price Lookup - Requires external APIs (Crawl4AI, Google Vision).
			'lookup_product_price'            => 'external-tools',
			// WooCommerce tools - Require WooCommerce plugin.
			'woo_products'                    => 'wordpress-plugins',
			'woo_orders'                      => 'wordpress-plugins',
			'woo_customers'                   => 'wordpress-plugins',
			'woo_coupons'                     => 'wordpress-plugins',
			// JetEngine tools - Require JetEngine plugin.
			'jetengine'                       => 'wordpress-plugins',
			// Elementor tools - Require Elementor plugin.
			'elementor'                       => 'wordpress-plugins',
			// Social media publishing tools - Require external API credentials.
			'post_facebook_instagram'         => 'external-tools',
			'post_tiktok_video'               => 'external-tools',
			'post_linkedin_update'            => 'external-tools',
			'post_google_business_update'     => 'external-tools',
			// Social media insights/reporting tools - Require external API credentials.
			'get_facebook_instagram_insights' => 'external-tools',
			'get_tiktok_insights'             => 'external-tools',
			'get_linkedin_insights'           => 'external-tools',
			'get_google_business_insights'    => 'external-tools',
			// Messaging tools - Require external API credentials.
			'send_whatsapp_message'           => 'external-tools',
			'send_telegram_message'           => 'external-tools',
			// Chat channel tools - Require external API credentials.
			'send_slack_message'              => 'external-tools',
			'get_slack_channels'              => 'external-tools',
			'get_slack_messages'              => 'external-tools',
			'create_slack_channel'            => 'external-tools',
			'send_discord_message'            => 'external-tools',
			'get_discord_channels'            => 'external-tools',
			'get_discord_messages'            => 'external-tools',
			'create_discord_channel'          => 'external-tools',
			'send_teams_message'              => 'external-tools',
			'get_teams_channels'              => 'external-tools',
			'get_teams_messages'              => 'external-tools',
			'send_messenger_message'          => 'external-tools',
			'get_messenger_conversations'     => 'external-tools',
			'create_messenger_broadcast'      => 'external-tools',
			// Google Chat tools - Require external API credentials.
			'send_google_chat_message'        => 'external-tools',
			'get_google_chat_spaces'          => 'external-tools',
			'get_google_chat_messages'        => 'external-tools',
			'create_google_chat_space'        => 'external-tools',
			// Enhanced Telegram tools - Require external API credentials.
			'get_telegram_updates'            => 'external-tools',
			'manage_telegram_webhook'         => 'external-tools',
			// Enhanced WhatsApp tools - Require external API credentials.
			'send_whatsapp_template'          => 'external-tools',
			'get_whatsapp_messages'           => 'external-tools',
			// Unified broadcast tool - Requires external API credentials.
			'unified_channel_broadcast'       => 'external-tools',
			// Email and communication tools - Require external API credentials.
			'search_gmail'                    => 'external-tools',
			'send_mailjet_email'              => 'external-tools',
			// Google Workspace tools - Require external API credentials.
			'create_google_calendar_event'    => 'external-tools',
			'google_analytics_report'         => 'external-tools',
			// Business and accounting tools - Require external API credentials.
			'quickbooks_report'               => 'external-tools',
			// iSAMS School Management System - Requires external API credentials.
			'isams_query'                     => 'external-tools',
			// Site Creator and related tools.
			'site_creator'                    => 'wordpress-core',
			'install_and_activate_plugin'     => 'wordpress-core',
			'install_and_activate_theme'      => 'wordpress-core',
			'update_option'                   => 'wordpress-core',
		);

		// Add quiz tool mappings if enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_quiz_system'] ) ) {
			$pro_tools['create_quiz']          = 'wordpress-core';
			$pro_tools['list_quizzes']         = 'wordpress-core';
			$pro_tools['get_quiz']             = 'wordpress-core';
			$pro_tools['update_quiz']          = 'wordpress-core';
			$pro_tools['delete_quiz']          = 'wordpress-core';
			$pro_tools['submit_quiz_answer']   = 'wordpress-core';
			$pro_tools['grade_quiz']           = 'wordpress-core';
			$pro_tools['get_quiz_submissions'] = 'wordpress-core';
			$pro_tools['get_quiz_results']     = 'wordpress-core';
			$pro_tools['get_quiz_analytics']   = 'wordpress-core';
		}

		// Add Fantasy Football tool mappings if enabled.
		if ( ! empty( $settings['enable_fantasy_football'] ) ) {
			$pro_tools['yahoo_ff_auth']              = 'external-tools';
			$pro_tools['yahoo_ff_get_leagues']       = 'external-tools';
			$pro_tools['yahoo_ff_get_roster']        = 'external-tools';
			$pro_tools['yahoo_ff_get_player_stats']  = 'external-tools';
			$pro_tools['yahoo_ff_trade_analyzer']    = 'external-tools';
			$pro_tools['yahoo_ff_league_standings']  = 'external-tools';
			$pro_tools['ff_generate_team_logo']      = 'external-tools';
			$pro_tools['ff_create_league_report']    = 'external-tools';
			$pro_tools['ff_player_research']         = 'external-tools';
		}

		// Add project management tool mappings if enabled.
		if ( ! empty( $settings['enable_project_management'] ) ) {
			$pro_tools['create_project']    = 'wordpress-core';
			$pro_tools['update_project']    = 'wordpress-core';
			$pro_tools['delete_project']    = 'wordpress-core';
			$pro_tools['list_projects']     = 'wordpress-core';
			$pro_tools['create_task']       = 'wordpress-core';
			$pro_tools['update_task']       = 'wordpress-core';
			$pro_tools['delete_task']       = 'wordpress-core';
			$pro_tools['list_tasks']        = 'wordpress-core';
			$pro_tools['create_event']      = 'wordpress-core';
			$pro_tools['update_event']      = 'wordpress-core';
			$pro_tools['delete_event']      = 'wordpress-core';
			$pro_tools['list_events']       = 'wordpress-core';
			$pro_tools['get_calendar_view'] = 'wordpress-core';
		}

		// Add ECA management tool mappings if enabled.
		if ( ! empty( $settings['enable_eca_management'] ) ) {
			// ECA Management (CRUD).
			$pro_tools['create_eca'] = 'wordpress-core';
			$pro_tools['list_ecas']  = 'wordpress-core';
			$pro_tools['get_eca']    = 'wordpress-core';
			$pro_tools['update_eca'] = 'wordpress-core';
			$pro_tools['delete_eca'] = 'wordpress-core';
			// Student Management (CRUD).
			$pro_tools['create_student'] = 'wordpress-core';
			$pro_tools['list_students']  = 'wordpress-core';
			$pro_tools['get_student']    = 'wordpress-core';
			$pro_tools['update_student'] = 'wordpress-core';
			$pro_tools['delete_student'] = 'wordpress-core';
			// Specialized ECA tools.
			$pro_tools['enroll_student_eca']       = 'wordpress-core';
			$pro_tools['sync_students_from_isams'] = 'wordpress-core';
			$pro_tools['sync_ecas_from_isams']     = 'wordpress-core';
		}

		// Add health and wellness management tool mappings if enabled.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			// Member Management (CRUD).
			$pro_tools['create_member'] = 'wordpress-core';
			$pro_tools['list_members']  = 'wordpress-core';
			$pro_tools['get_member']    = 'wordpress-core';
			$pro_tools['update_member'] = 'wordpress-core';
			$pro_tools['delete_member'] = 'wordpress-core';
			// Policy Management (CRUD + Search).
			$pro_tools['create_policy']   = 'wordpress-core';
			$pro_tools['list_policies']   = 'wordpress-core';
			$pro_tools['get_policy']      = 'wordpress-core';
			$pro_tools['update_policy']   = 'wordpress-core';
			$pro_tools['delete_policy']   = 'wordpress-core';
			$pro_tools['search_policies'] = 'wordpress-core';
			// Prescription Management (CRUD + Search).
			$pro_tools['create_prescription']  = 'wordpress-core';
			$pro_tools['list_prescriptions']   = 'wordpress-core';
			$pro_tools['get_prescription']     = 'wordpress-core';
			$pro_tools['update_prescription']  = 'wordpress-core';
			$pro_tools['delete_prescription']  = 'wordpress-core';
			$pro_tools['search_prescriptions'] = 'wordpress-core';
			// Medical Record Management (CRUD + Search).
			$pro_tools['create_medical_record']  = 'wordpress-core';
			$pro_tools['list_medical_records']   = 'wordpress-core';
			$pro_tools['get_medical_record']     = 'wordpress-core';
			$pro_tools['update_medical_record']  = 'wordpress-core';
			$pro_tools['delete_medical_record']  = 'wordpress-core';
			$pro_tools['search_medical_records'] = 'wordpress-core';
			// Checkup/Appointment Management (CRUD + Specialized).
			$pro_tools['create_checkup']        = 'wordpress-core';
			$pro_tools['list_checkups']         = 'wordpress-core';
			$pro_tools['get_checkup']           = 'wordpress-core';
			$pro_tools['update_checkup']        = 'wordpress-core';
			$pro_tools['delete_checkup']        = 'wordpress-core';
			$pro_tools['get_upcoming_checkups'] = 'wordpress-core';
			// Allergy Management (CRUD).
			$pro_tools['create_allergy'] = 'wordpress-core';
			$pro_tools['list_allergies'] = 'wordpress-core';
			$pro_tools['get_allergy']    = 'wordpress-core';
			$pro_tools['update_allergy'] = 'wordpress-core';
			$pro_tools['delete_allergy'] = 'wordpress-core';
			// Specialized Health & Wellness Tools.
			$pro_tools['get_member_health_summary'] = 'wordpress-core';
			$pro_tools['get_medication_schedule']   = 'wordpress-core';
		}

		// Add Document Generation Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_document_generation_toolkit'] ) ) {
			$pro_tools['pro_pdf_document']   = 'external-tools';
			$pro_tools['pro_word_document']  = 'external-tools';
			$pro_tools['pro_excel_document'] = 'external-tools';
		}

		// Add Architectural Design Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_architectural_design_toolkit'] ) ) {
			// Floor Planning & Space Design tools.
			$pro_tools['generate_floor_plan']          = 'external-tools';
			$pro_tools['optimize_space_layout']        = 'external-tools';
			$pro_tools['create_floor_plan_variations'] = 'external-tools';
			$pro_tools['convert_sketch_to_floor_plan'] = 'external-tools';
			// 3D Modeling & Visualization tools.
			$pro_tools['generate_3d_model']            = 'external-tools';
			$pro_tools['render_architectural_view']    = 'external-tools';
			$pro_tools['create_walkthrough_animation'] = 'external-tools';
			// Documentation & Blueprints tools.
			$pro_tools['generate_construction_drawings'] = 'external-tools';
			$pro_tools['generate_detail_drawings']       = 'external-tools';
			$pro_tools['export_architectural_documents'] = 'external-tools';
			// Analysis & Compliance tools.
			$pro_tools['check_building_code_compliance']   = 'external-tools';
			$pro_tools['analyze_structural_feasibility']   = 'external-tools';
			$pro_tools['calculate_sustainability_metrics'] = 'external-tools';
			// Estimation & Scheduling tools.
			$pro_tools['generate_material_schedule']     = 'external-tools';
			$pro_tools['estimate_construction_cost']     = 'external-tools';
			$pro_tools['generate_construction_timeline'] = 'external-tools';
		}

		/**
		 * Filter the Pro tool group assignments.
		 *
		 * @since 1.0.0
		 *
		 * @param array $pro_tools Associative array of Pro tool slugs to group identifiers.
		 */
		$pro_tools = apply_filters( 'wp_mcp_ai_pro_tool_groups', $pro_tools );

		// Merge Pro tools into the main group map.
		return array_merge( $group_map, $pro_tools );
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_tool_categories' ) ) {
	/**
	 * Add Pro tools to tool categories for recommendations.
	 *
	 * Extends the core tool categories to include Pro addon tools
	 * so they get proper recommendations for token limits and models.
	 * Only adds tools to existing categories; does not create new ones.
	 *
	 * @since 1.0.0
	 *
	 * @param array $categories Associative array of category identifiers to category data.
	 * @return array Modified categories with Pro tools added.
	 */
	function wp_mcp_ai_pro_tool_categories( $categories ) {
		// Add pro tools to existing categories only.

		// WooCommerce, JetEngine, and Elementor tools - medium resource.
		if ( isset( $categories['medium_resource'] ) ) {
			$categories['medium_resource']['tools'][] = 'woo_products';
			$categories['medium_resource']['tools'][] = 'woo_orders';
			$categories['medium_resource']['tools'][] = 'woo_customers';
			$categories['medium_resource']['tools'][] = 'woo_coupons';
			$categories['medium_resource']['tools'][] = 'jetengine';
			$categories['medium_resource']['tools'][] = 'elementor';
		}

		// Product Actualization and Price Lookup - high resource (uses AI vision and web scraping).
		if ( isset( $categories['high_resource'] ) ) {
			$categories['high_resource']['tools'][] = 'product_actualization';
			$categories['high_resource']['tools'][] = 'lookup_product_price';
		}

		/**
		 * Filter the Pro tool category assignments.
		 *
		 * @since 1.0.0
		 *
		 * @param array $categories Complete category array with Pro tools added.
		 */
		return apply_filters( 'wp_mcp_ai_pro_tool_categories_filtered', $categories );
	}
}

// Initialize Pro addon.
// When loaded as part of combined plugin (via inline require_once), init immediately.
// When loaded as separate plugin, use plugins_loaded hook.
// Defensive check: only call did_action/doing_action if functions exist.
$functions_available  = function_exists( 'did_action' ) && function_exists( 'doing_action' );
$plugins_loaded_fired = $functions_available && ( did_action( 'plugins_loaded' ) || doing_action( 'plugins_loaded' ) );

if ( $plugins_loaded_fired ) {
	// Already in plugins_loaded or after - init immediately.
	// This handles the combined plugin scenario where Pro is loaded inline.
	wp_mcp_ai_pro_init();
} else {
	// Not yet at plugins_loaded - schedule init for when it fires.
	// This handles the separate plugin scenario and early activation scenarios.
	add_action( 'plugins_loaded', 'wp_mcp_ai_pro_init', 15 );
}

/**
 * ============================================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================================
 */

/**
 * Plugin activation handler.
 *
 * @param bool $network_wide Whether activated network-wide.
 */
function wp_mcp_ai_pro_activate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook callback signature requires $network_wide parameter for potential future multisite support.
	// Check if Core is active.
	if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
		// Deactivate self and show error.
		deactivate_plugins( plugin_basename( WP_MCP_AI_PRO_FILE ) );
		wp_die(
			esc_html__( 'Open Operator System Pro requires Open Operator System (NV oOS) to be installed and activated first.', 'mcp-ai-wpoos-pro' ),
			esc_html__( 'Plugin Activation Error', 'mcp-ai-wpoos-pro' ),
			array( 'back_link' => true )
		);
	}

	// Track Pro addon activation.
	if ( class_exists( 'WP_MCP_AI_Activation_Tracker' ) ) {
		WP_MCP_AI_Activation_Tracker::track_activation( 'pro' );
	}

	// Initialize default settings.
	if ( false === get_option( 'wp_mcp_ai_pro_permissions' ) ) {
		add_option( 'wp_mcp_ai_pro_permissions', array() );
	}

	if ( false === get_option( 'wp_mcp_ai_pro_rate_limits' ) ) {
		add_option(
			'wp_mcp_ai_pro_rate_limits',
			array(
				'global_requests_per_minute' => 100,
				'user_requests_per_minute'   => 20,
			)
		);
	}

	// Seed media template presets if media toolkit is enabled.
	if ( class_exists( 'WP_MCP_AI_Media_Template_Presets' ) ) {
		WP_MCP_AI_Media_Template_Presets::seed_presets();
	}

	flush_rewrite_rules();
}

/**
 * Plugin deactivation handler.
 *
 * @param bool $network_wide Whether deactivated network-wide.
 */
function wp_mcp_ai_pro_deactivate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook callback signature requires $network_wide parameter for potential future multisite support.
	// Track Pro addon deactivation.
	if ( class_exists( 'WP_MCP_AI_Activation_Tracker' ) ) {
		WP_MCP_AI_Activation_Tracker::track_deactivation( 'pro' );
	}

	flush_rewrite_rules();
}

/**
 * Register activation/deactivation hooks only when Pro addon is a standalone plugin.
 *
 * When loaded inline from the cloned repository by mcp-ai-wpoos.php, these hooks
 * should not be registered since the Pro addon is not being activated as a separate plugin.
 * Activation/deactivation hooks are only relevant for the built/distributed version.
 */
if ( function_exists( 'register_activation_hook' ) && function_exists( 'register_deactivation_hook' ) ) {
	// Check if we're being loaded as a standalone plugin (has plugin data in get_file_data).
	// When loaded inline, we won't have plugin headers since they're commented out in the repo version.
	$plugin_data = array();
	if ( function_exists( 'get_file_data' ) ) {
		$plugin_data = get_file_data(
			WP_MCP_AI_PRO_FILE,
			array( 'Name' => 'Plugin Name' ),
			'plugin'
		);
	}

	// Only register hooks if we have plugin headers (standalone plugin scenario).
	// In the cloned repo, this file has no plugin header at the top.
	if ( ! empty( $plugin_data['Name'] ) ) {
		register_activation_hook( WP_MCP_AI_PRO_FILE, 'wp_mcp_ai_pro_activate' );
		register_deactivation_hook( WP_MCP_AI_PRO_FILE, 'wp_mcp_ai_pro_deactivate' );
	}
}
