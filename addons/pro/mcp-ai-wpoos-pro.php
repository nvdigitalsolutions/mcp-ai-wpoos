<?php
/**
 * Open Operator System (WP oOS) - Pro Add-on Entry Point
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
	define( 'WP_MCP_AI_PRO_URL', plugin_dir_url( WP_MCP_AI_PRO_FILE ) );
}

/**
 * ============================================================================
 * DEPENDENCY CHECK
 *
 * Verify that Open Operator System (WP oOS) Core is active before loading Pro features.
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_pro_check_dependencies' ) ) {
	/**
	 * Check if required dependencies are available.
	 *
	 * Pro addon requires either:
	 * - Open Operator System (WP oOS) Core (separated plugin architecture), OR
	 * - Open Operator System (WP oOS) combined plugin with tool registry
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
			'<strong>Open Operator System Pro</strong> requires either <strong>Open Operator System (WP oOS)</strong> or <strong>Open Operator System</strong> to be installed and activated. Please <a href="%s">install Open Operator System (WP oOS)</a> or <a href="%s">Open Operator System</a> first.',
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
	 * Loads the Performance monitoring section and other Pro-specific admin sections.
	 * Also instantiates the Performance section to register its AJAX handlers.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_pro_load_admin_sections() {
		// Load Performance section.
		$performance_section_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		if ( file_exists( $performance_section_file ) ) {
			require_once $performance_section_file;

			// Instantiate the Performance section to register AJAX handlers.
			// The instance is needed early so AJAX hooks are registered before WordPress processes AJAX requests.
			if ( class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
				new WP_MCP_AI_Section_Performance();
			}
		}
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

		// Load Pro tool interfaces (extend Core interfaces).
		// Pro tools can implement additional interfaces for advanced features.

		// Load Pro admin sections.
		// Performance section is only loaded in admin context.
		if ( is_admin() ) {
			wp_mcp_ai_pro_load_admin_sections();
		}

		// Load Project Management CPT registration (Pro feature).
		require_once WP_MCP_AI_PRO_PATH . 'includes/project-management-init.php';

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
		// Load Pro tool files.
		$pro_tools = array(
			// Exec service tools (video, audio, CLI).
			'WP_MCP_AI_Tool_Check_WP_CLI'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php',
			'WP_MCP_AI_Tool_Extract_Video_Frames'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-extract-video-frames.php',
			'WP_MCP_AI_Tool_Get_Video_Metadata'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-video-metadata.php',
			'WP_MCP_AI_Tool_Remove_Background'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remove-background.php',
			'WP_MCP_AI_Tool_Generate_Jukebox_Music'       => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php',
			'WP_MCP_AI_Tool_Check_Jukebox_Status'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-jukebox-status.php',
			// Project Management tools (Pro feature).
			'WP_MCP_AI_Tool_Create_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-project.php',
			'WP_MCP_AI_Tool_Update_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-project.php',
			'WP_MCP_AI_Tool_Delete_Project'               => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-project.php',
			'WP_MCP_AI_Tool_List_Projects'                => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-projects.php',
			'WP_MCP_AI_Tool_Create_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-task.php',
			'WP_MCP_AI_Tool_Update_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-task.php',
			'WP_MCP_AI_Tool_Delete_Task'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-task.php',
			'WP_MCP_AI_Tool_List_Tasks'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-tasks.php',
			'WP_MCP_AI_Tool_Create_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-event.php',
			'WP_MCP_AI_Tool_Update_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-event.php',
			'WP_MCP_AI_Tool_Delete_Event'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-event.php',
			'WP_MCP_AI_Tool_List_Events'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-events.php',
			'WP_MCP_AI_Tool_Get_Calendar_View'            => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-calendar-view.php',
			// WooCommerce tools.
			'WP_MCP_AI_Pro_Tool_Woo_Products'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-products.php',
			'WP_MCP_AI_Pro_Tool_Woo_Orders'               => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-orders.php',
			// JetEngine tools.
			'WP_MCP_AI_Pro_Tool_JetEngine'                => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine.php',
			// Elementor tools.
			'WP_MCP_AI_Pro_Tool_Elementor'                => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-elementor.php',
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
			// Email and communication tools.
			'WP_MCP_AI_Pro_Tool_Search_Gmail'             => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php',
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
		);

		/**
		 * Filter the list of Pro tools to register.
		 *
		 * @since 1.0.0
		 *
		 * @param array $pro_tools Array of tool class names and file paths.
		 */
		$pro_tools = apply_filters( 'wp_mcp_ai_pro_tools', $pro_tools );

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
			// Email and communication tools - Require external API credentials.
			'search_gmail'                    => 'external-tools',
			'send_mailjet_email'              => 'external-tools',
			// Google Workspace tools - Require external API credentials.
			'create_google_calendar_event'    => 'external-tools',
			'google_analytics_report'         => 'external-tools',
			// Business and accounting tools - Require external API credentials.
			'quickbooks_report'               => 'external-tools',
			// Site Creator and related tools.
			'site_creator'                    => 'wordpress-core',
			'install_and_activate_plugin'     => 'wordpress-core',
			'install_and_activate_theme'      => 'wordpress-core',
			'update_option'                   => 'wordpress-core',
		);

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
			esc_html__( 'Open Operator System Pro requires Open Operator System (WP oOS) to be installed and activated first.', 'wp-mcp-ai-pro' ),
			esc_html__( 'Plugin Activation Error', 'wp-mcp-ai-pro' ),
			array( 'back_link' => true )
		);
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

	flush_rewrite_rules();
}

/**
 * Plugin deactivation handler.
 *
 * @param bool $network_wide Whether deactivated network-wide.
 */
function wp_mcp_ai_pro_deactivate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook callback signature requires $network_wide parameter for potential future multisite support.
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
