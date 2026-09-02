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
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
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
	define( 'WP_MCP_AI_PRO_VERSION', '1.1.67' );
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

		// Load Node Package install hints helper (used by multiple settings pages).
		$node_hints_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-node-package-hints.php';
		if ( file_exists( $node_hints_file ) ) {
			require_once $node_hints_file;
		}

		// Load Pro Packages Settings Page (Node.js package status).
		$pro_packages_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php';
		if ( file_exists( $pro_packages_page ) ) {
			require_once $pro_packages_page;
		}

		// Load Addons admin page (one-click install for standalone addons).
		$addons_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-addons-page.php';
		if ( file_exists( $addons_page ) ) {
			require_once $addons_page;
			// Note: Class instantiates itself at the bottom of the file.
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

		// Load Pro Schedule Manager section.
		$schedule_manager_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php';
		if ( file_exists( $schedule_manager_file ) ) {
			require_once $schedule_manager_file;
		}

		// Load Schedule and Workflow Presets (Phase 2.1.0 — pre-built preset libraries).
		$schedule_presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-presets.php';
		if ( file_exists( $schedule_presets_file ) ) {
			require_once $schedule_presets_file;
		}
		$workflow_presets_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-workflow-presets.php';
		if ( file_exists( $workflow_presets_file ) ) {
			require_once $workflow_presets_file;
		}

		// In the base + pro separate-plugin scenario the base plugin's
		// settings-dashboard-init.php already called
		// $container->get( 'section.schedule_manager' ) before the Pro class
		// file was loaded, so the factory returned null.  Because PHP's isset()
		// returns false for null, the container will re-run the factory on the
		// next get() call — but nothing triggers that call during AJAX requests,
		// meaning the section's AJAX handlers are never registered.
		//
		// Force the container to resolve the singleton now that the class file
		// is loaded so the section constructor registers its AJAX hooks and the
		// standalone admin page can render and enqueue assets.
		if (
			function_exists( 'wp_mcp_ai_container' ) &&
			class_exists( 'WP_MCP_AI_Section_Schedule_Manager' )
		) {
			wp_mcp_ai_container()->get( 'section.schedule_manager' );
		}

		// Load Pro Schedule Manager standalone admin page (registers under NV oOS Pro Dashboard menu).
		$schedule_manager_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-manager-page.php';
		if ( file_exists( $schedule_manager_page ) ) {
			require_once $schedule_manager_page;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Research & Add Schedule admin page (sibling of Schedule Manager).
		$schedule_research_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-research-page.php';
		if ( file_exists( $schedule_research_page ) ) {
			require_once $schedule_research_page;
			// Class auto-initializes at the bottom of the file.
		}

		// Load Pro Schedule Toolkit Settings page (Overview · Configuration · Tools · Research · Help · MCP Server).
		$schedule_settings_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-schedule-toolkit-settings-page.php';
		if ( file_exists( $schedule_settings_page ) ) {
			require_once $schedule_settings_page;
			// Class auto-instantiates in admin context at the bottom of the file.
		}

		// Load Pro Webhook Status admin page (registers under NV oOS Pro Dashboard menu).
		$webhook_status_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php';
		if ( file_exists( $webhook_status_page ) ) {
			require_once $webhook_status_page;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Pro Agent Command Center (unified agent management dashboard).
		$agent_command_center = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-agent-command-center.php';
		if ( file_exists( $agent_command_center ) ) {
			require_once $agent_command_center;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Pro Status Dashboard page.
		$status_dashboard = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-status-dashboard-page.php';
		if ( file_exists( $status_dashboard ) ) {
			require_once $status_dashboard;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Pro Status AJAX handlers.
		$status_ajax = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-status-ajax.php';
		if ( file_exists( $status_ajax ) ) {
			require_once $status_ajax;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Pro Maintenance admin page.
		$maintenance_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-maintenance-page.php';
		if ( file_exists( $maintenance_page ) ) {
			require_once $maintenance_page;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Load Pro Incidents admin page.
		$incidents_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-incidents-page.php';
		if ( file_exists( $incidents_page ) ) {
			require_once $incidents_page;
			// Note: Class instantiates itself at the bottom of the file.
		}

		// Register Pro export providers for Backup & Restore.
		add_action(
			'wp_mcp_ai_register_export_providers',
			function ( $manager ) {
				// Remote Sites provider — the primary user-facing backup feature.
				$remote_sites_provider = WP_MCP_AI_PRO_PATH . 'includes/export/class-wp-mcp-ai-export-provider-remote-sites.php';
				if ( file_exists( $remote_sites_provider ) ) {
					require_once $remote_sites_provider;
					if ( class_exists( 'WP_MCP_AI_Export_Provider_Remote_Sites' ) ) {
						$manager->register( new WP_MCP_AI_Export_Provider_Remote_Sites() );
					}
				}

				// Pro License provider.
				$license_provider = WP_MCP_AI_PRO_PATH . 'includes/export/class-wp-mcp-ai-export-provider-license.php';
				if ( file_exists( $license_provider ) ) {
					require_once $license_provider;
					if ( class_exists( 'WP_MCP_AI_Export_Provider_License' ) ) {
						$manager->register( new WP_MCP_AI_Export_Provider_License() );
					}
				}

				// JetEngine CCT provider — only if JetEngine is active.
				if ( class_exists( 'Jet_Engine' ) ) {
					$jetengine_provider = WP_MCP_AI_PRO_PATH . 'includes/export/class-wp-mcp-ai-export-provider-jetengine-ccts.php';
					if ( file_exists( $jetengine_provider ) ) {
						require_once $jetengine_provider;
						if ( class_exists( 'WP_MCP_AI_Export_Provider_JetEngine_CCTs' ) ) {
							$manager->register( new WP_MCP_AI_Export_Provider_JetEngine_CCTs() );
						}
					}
				}
			}
		);
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
		if ( version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
			$pro_vendor_autoload = WP_MCP_AI_PRO_PATH . 'vendor/autoload.php';
			if ( file_exists( $pro_vendor_autoload ) ) {
				require_once $pro_vendor_autoload;
			}
		}

		// Register text domain loading on init hook.
		add_action( 'init', 'wp_mcp_ai_pro_load_textdomain', 1 );

		// Delegate all Pro subsystem loading to the module registry.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-module-registry.php';
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

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

		// Load Pro slash commands.
		$pro_slash_init = WP_MCP_AI_PRO_PATH . 'includes/slash-commands/slash-commands-init.php';
		if ( file_exists( $pro_slash_init ) ) {
			require_once $pro_slash_init;
		}

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
			// Universal-operator math/logic tools (Boolean NAND + continuous EML).
			'WP_MCP_AI_Tool_Evaluate_Logic_Gate'           => WP_MCP_AI_PRO_PATH . 'includes/tools/math/class-wp-mcp-ai-tool-evaluate-logic-gate.php',
			'WP_MCP_AI_Tool_Generate_Truth_Table'          => WP_MCP_AI_PRO_PATH . 'includes/tools/math/class-wp-mcp-ai-tool-generate-truth-table.php',
			'WP_MCP_AI_Tool_Evaluate_Eml'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-evaluate-eml.php',
			// Vector storage preparation tool (orphaned file registered as of 1.2.0).
			'WP_MCP_AI_Tool_Prepare_File_For_Vector_Store' => WP_MCP_AI_PRO_PATH . 'includes/tools/vector-storage/class-wp-mcp-ai-tool-prepare-file-for-vector-store.php',
			// Remote WordPress/WooCommerce Connection tool.
			'WP_MCP_AI_Tool_Remote_WP_Connection'          => WP_MCP_AI_PRO_PATH . 'includes/tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php',
			// Printful Print-on-Demand tool.
			'WP_MCP_AI_Pro_Tool_Printful'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-printful.php',
			// Generic REST API Connection tool.
			'WP_MCP_AI_Tool_Generic_REST_API'              => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-generic-rest-api.php',
			// NPM Package Enhanced Tools (new in 1.1.0).
			'WP_MCP_AI_Tool_Format_Code_Prettier'          => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-format-code-prettier.php',
			'WP_MCP_AI_Tool_Generate_Email_Template'       => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-email-template.php',
			'WP_MCP_AI_Tool_Transcode_Video'               => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-transcode-video.php',
			// EZuite ERP Connection tool.
			'WP_MCP_AI_Tool_EZuite_ERP'                    => WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/class-wp-mcp-ai-tool-ezuite-erp.php',
			'WP_MCP_AI_Tool_EZuite_ERP_Get_Products'       => WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/class-wp-mcp-ai-tool-ezuite-erp-get-products.php',
			// Exec service tools (video, audio, CLI).
			'WP_MCP_AI_Tool_Check_WP_CLI'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-check-wp-cli.php',
			'WP_MCP_AI_Tool_Extract_Video_Frames'          => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-extract-video-frames.php',
			'WP_MCP_AI_Tool_Get_Video_Metadata'            => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-get-video-metadata.php',
			'WP_MCP_AI_Tool_Remove_Background'             => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-remove-background.php',
			'WP_MCP_AI_Tool_Generate_Jukebox_Music'        => WP_MCP_AI_PRO_PATH . 'includes/tools/dj-management/class-wp-mcp-ai-tool-generate-jukebox-music.php',
			'WP_MCP_AI_Tool_Check_Jukebox_Status'          => WP_MCP_AI_PRO_PATH . 'includes/tools/dj-management/class-wp-mcp-ai-tool-check-jukebox-status.php',
			// Architectural Drawing tool (Pro feature).
			'WP_MCP_AI_Tool_Generate_Architectural_Drawing' => WP_MCP_AI_PRO_PATH . 'includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-architectural-drawing.php',
			// Project Management tools (Pro feature).
			'WP_MCP_AI_Tool_Create_Project'                => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-create-project.php',
			'WP_MCP_AI_Tool_Update_Project'                => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-update-project.php',
			'WP_MCP_AI_Tool_Delete_Project'                => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-delete-project.php',
			'WP_MCP_AI_Tool_List_Projects'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-list-projects.php',
			'WP_MCP_AI_Tool_Research_Project'              => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-project.php',
			'WP_MCP_AI_Tool_Create_Task'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-create-task.php',
			'WP_MCP_AI_Tool_Update_Task'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-update-task.php',
			'WP_MCP_AI_Tool_Delete_Task'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-delete-task.php',
			'WP_MCP_AI_Tool_List_Tasks'                    => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-list-tasks.php',
			// Task Dependency tools (Pro feature - v1.2.0).
			'WP_MCP_AI_Tool_Add_Task_Dependency'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-add-task-dependency.php',
			'WP_MCP_AI_Tool_Remove_Task_Dependency'        => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-remove-task-dependency.php',
			'WP_MCP_AI_Tool_Get_Task_Dependencies'         => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-get-task-dependencies.php',
			'WP_MCP_AI_Tool_Create_Event'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-create-event.php',
			'WP_MCP_AI_Tool_Update_Event'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-update-event.php',
			'WP_MCP_AI_Tool_Delete_Event'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-delete-event.php',
			'WP_MCP_AI_Tool_List_Events'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-list-events.php',
			'WP_MCP_AI_Tool_Get_Calendar_View'             => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-calendar-view.php',
			// NOTE: Core orchestration tools (9 tools) moved to base plugin in includes/orchestration-init.php
			// This includes: create/update/get task plan, manage sessions, detect completion, check exit conditions,
			// analyze loop health, get session status, calculate capacity (Little's Law).
			// Research Enhancement tools (Ralph pattern - Phase 2 - Pro only).
							'WP_MCP_AI_Pro_Tool_Aggregate_Research_Data' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-aggregate-research-data.php',
			'WP_MCP_AI_Pro_Tool_Extract_Structured_Data'   => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-extract-structured-data.php',
			'WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown'  => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-convert-html-to-markdown.php',
			'WP_MCP_AI_Pro_Tool_Generate_Research_Report'  => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php',
			'WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns'     => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-analyze-data-patterns.php',
			'WP_MCP_AI_Pro_Tool_Verify_Information'        => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-verify-information.php',
			// Research → Paper Store pipeline (Phase 3 - Post creation).
			'WP_MCP_AI_Pro_Tool_Create_Post_From_Research' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-post-from-research.php',
			// Template Management tools (Ralph pattern - Phase 3).
			'WP_MCP_AI_Pro_Tool_Create_Template'           => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-template.php',
			'WP_MCP_AI_Pro_Tool_Instantiate_Template'      => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-instantiate-template.php',
			'WP_MCP_AI_Pro_Tool_List_Templates'            => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-list-templates.php',
			'WP_MCP_AI_Pro_Tool_Seed_Template_Library'     => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-seed-template-library.php',
			// ICS calendar export tool (enhanced with NPM package).
			'WP_MCP_AI_Tool_Export_Calendar_ICS'           => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-export-calendar-ics.php',
			// Calendar Booking Toolkit — service CRUD + bulk import (Pro feature - v1.4.0).
			'WP_MCP_AI_Tool_Create_Service'                => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-create-service.php',
			'WP_MCP_AI_Tool_Import_Services'               => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-import-services.php',
			// Calendar Booking Toolkit — no-show / unconfirmed query tools (Pro feature - v2.9.0).
			'WP_MCP_AI_Tool_Get_No_Show_Appointments'      => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-no-show-appointments.php',
			'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings'      => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-unconfirmed-bookings.php',
			// Calendar Booking Toolkit — bulk action tools (Pro feature - v2.9.0).
			'WP_MCP_AI_Tool_Send_Booking_Confirmations'    => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-send-booking-confirmations.php',
			'WP_MCP_AI_Tool_Send_Reschedule_Invitation'    => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-send-reschedule-invitation.php',
			// Calendar Booking Toolkit — JetAppointment/JetBooking sync + query tools (Pro feature - v1.5.0).
			'WP_MCP_AI_Tool_Sync_From_JetAppointment'      => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_To_JetAppointment'        => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-to-jetappointment.php',
			'WP_MCP_AI_Tool_Sync_From_JetBooking'          => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetbooking.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Providers'  => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-providers.php',
			'WP_MCP_AI_Tool_Get_JetAppointment_Services'   => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetappointment-services.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Units'          => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-units.php',
			'WP_MCP_AI_Tool_Get_JetBooking_Instances'      => WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-jetbooking-instances.php',
			// Places Toolkit — find bookable places (Pro feature - v1.5.0).
			'WP_MCP_AI_Tool_Find_Bookable_Places'          => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-find-bookable-places.php',
			// PARA Methodology tools (Pro feature - v1.2.0).
			'WP_MCP_AI_Tool_PARA_Classify_Item'            => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-classify-item.php',
			'WP_MCP_AI_Tool_PARA_Move_To_Archives'         => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-move-to-archives.php',
			'WP_MCP_AI_Tool_PARA_Create_Area'              => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-create-area.php',
			'WP_MCP_AI_Tool_PARA_Update_Area'              => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-update-area.php',
			'WP_MCP_AI_Tool_PARA_List_Areas'               => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-list-areas.php',
			'WP_MCP_AI_Tool_PARA_Weekly_Review'            => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-weekly-review.php',
			'WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project' => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-para-promote-resource-to-project.php',
			// MemPalace capture tool (Phase B1) — decision/status/ADR.
			'WP_MCP_AI_Tool_PM_Capture_Decision'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/class-wp-mcp-ai-tool-pm-capture-decision.php',
			// PM Analytics tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Get_Burndown_Chart'            => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-get-burndown-chart.php',
			'WP_MCP_AI_Tool_Get_Team_Velocity'             => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-get-team-velocity.php',
			'WP_MCP_AI_Tool_Get_Portfolio_Health'          => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-get-portfolio-health.php',
			'WP_MCP_AI_Tool_Get_Resource_Utilization'      => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-get-resource-utilization.php',
			'WP_MCP_AI_Tool_Get_Project_Timeline'          => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-get-project-timeline.php',
			'WP_MCP_AI_Tool_Forecast_Completion'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/analytics/class-wp-mcp-ai-tool-forecast-completion.php',
			// PM Risk tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Assess_Project_Risk'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/risk/class-wp-mcp-ai-tool-assess-project-risk.php',
			'WP_MCP_AI_Tool_Detect_Stale_Tasks'            => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/risk/class-wp-mcp-ai-tool-detect-stale-tasks.php',
			'WP_MCP_AI_Tool_Identify_Blockers'             => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/risk/class-wp-mcp-ai-tool-identify-blockers.php',
			// PM Command Center query tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Get_PM_KPIs'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/command-center/class-wp-mcp-ai-tool-get-pm-kpis.php',
			'WP_MCP_AI_Tool_Get_Project_Pipeline'          => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/command-center/class-wp-mcp-ai-tool-get-project-pipeline.php',
			'WP_MCP_AI_Tool_Get_Upcoming_Deadlines'        => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/command-center/class-wp-mcp-ai-tool-get-upcoming-deadlines.php',
			'WP_MCP_AI_Tool_Get_My_Tasks'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/command-center/class-wp-mcp-ai-tool-get-my-tasks.php',
			// PM Workflow Automation tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Create_PM_Workflow_Rule'       => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/workflow/class-wp-mcp-ai-tool-create-pm-workflow-rule.php',
			'WP_MCP_AI_Tool_List_PM_Workflow_Rules'        => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/workflow/class-wp-mcp-ai-tool-list-pm-workflow-rules.php',
			'WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule'     => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/workflow/class-wp-mcp-ai-tool-simulate-pm-workflow-rule.php',
			// PM Task Template tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Create_Task_Template'          => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/templates/class-wp-mcp-ai-tool-create-task-template.php',
			'WP_MCP_AI_Tool_List_Task_Templates'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/templates/class-wp-mcp-ai-tool-list-task-templates.php',
			'WP_MCP_AI_Tool_Instantiate_Task_Template'     => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/templates/class-wp-mcp-ai-tool-instantiate-task-template.php',
			// PM Sprint Management tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Create_Sprint'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/sprints/class-wp-mcp-ai-tool-create-sprint.php',
			'WP_MCP_AI_Tool_Plan_Sprint'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/sprints/class-wp-mcp-ai-tool-plan-sprint.php',
			'WP_MCP_AI_Tool_Close_Sprint'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/sprints/class-wp-mcp-ai-tool-close-sprint.php',
			// PM Reporting tools (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Generate_Status_Report'        => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/reports/class-wp-mcp-ai-tool-generate-status-report.php',
			'WP_MCP_AI_Tool_Export_Project_CSV'            => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/reports/class-wp-mcp-ai-tool-export-project-csv.php',
			// PM Blueprint Import tool (Pro feature - v2.7.0).
			'WP_MCP_AI_Tool_Import_PM_Blueprint'           => WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/examples/class-wp-mcp-ai-tool-import-project-management-blueprint.php',
			// QMS (ISO 9001:2015 Clause 7.5) tools (Pro feature - v1.2.0).
			'WP_MCP_AI_Tool_QMS_Create_Controlled_Document' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-create-controlled-document.php',
			'WP_MCP_AI_Tool_QMS_Submit_For_Review'         => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-submit-for-review.php',
			'WP_MCP_AI_Tool_QMS_Approve_Document'          => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-approve-document.php',
			'WP_MCP_AI_Tool_QMS_Release_Document'          => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-release-document.php',
			'WP_MCP_AI_Tool_QMS_Supersede_Document'        => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-supersede-document.php',
			'WP_MCP_AI_Tool_QMS_Mark_Obsolete'             => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-mark-obsolete.php',
			'WP_MCP_AI_Tool_QMS_Sign_Document'             => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-sign-document.php',
			'WP_MCP_AI_Tool_QMS_List_Controlled_Documents' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-list-controlled-documents.php',
			'WP_MCP_AI_Tool_QMS_Get_Audit_Trail'           => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-get-audit-trail.php',
			'WP_MCP_AI_Tool_QMS_Schedule_Review'           => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-qms-schedule-review.php',
			// Remotion programmatic video creation tool (React/Node.js, always-on pro tool).
			'WP_MCP_AI_Tool_Create_Remotion_Video'         => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-create-remotion-video.php',
			// Product Actualization tool.
			'WP_MCP_AI_Pro_Tool_Product_Actualization'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-product-actualization.php',
			// Validate Image for Product Placement tool.
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php',
			// Validate Image for Vehicle Estimate tool.
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php',
			// Product Price Lookup tool.
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-lookup-product-price.php',
			// Listing image download tools (Google Maps, Facebook, Instagram).
			'WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-download-google-maps-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-download-facebook-page-images.php',
			'WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-download-instagram-page-images.php',
			// Social media publishing tools.
			'WP_MCP_AI_Pro_Tool_Post_Facebook_Instagram'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php',
			'WP_MCP_AI_Pro_Tool_Post_Tiktok_Video'         => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-post-tiktok-video.php',
			'WP_MCP_AI_Pro_Tool_Post_Linkedin_Update'      => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-post-linkedin-update.php',
			'WP_MCP_AI_Pro_Tool_Post_Google_Business_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-post-google-business-update.php',
			// Social media insights/reporting tools.
			'WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights'     => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Business_Insights' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-pro-tool-get-google-business-insights.php',
			// Messaging tools.
			'WP_MCP_AI_Pro_Tool_Send_Whatsapp_Message'     => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php',
			'WP_MCP_AI_Pro_Tool_Send_Telegram_Message'     => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-telegram-message.php',
			'WP_MCP_AI_Pro_Tool_Schedule_Notify_SMS'       => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-schedule-notify-sms.php',
			// Chat channels tools (Discord, Slack, Teams).
			'WP_MCP_AI_Pro_Tool_Send_Slack_Message'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-slack-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Channels'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-slack-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Slack_Messages'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-slack-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Slack_Channel'      => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-create-slack-channel.php',
			'WP_MCP_AI_Pro_Tool_Send_Discord_Message'      => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-discord-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Channels'      => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-discord-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Discord_Messages'      => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-discord-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Discord_Channel'    => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-create-discord-channel.php',
			'WP_MCP_AI_Pro_Tool_Send_Teams_Message'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-teams-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Channels'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-teams-channels.php',
			'WP_MCP_AI_Pro_Tool_Get_Teams_Messages'        => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-teams-messages.php',
			'WP_MCP_AI_Pro_Tool_Send_Messenger_Message'    => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-messenger-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations' => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-messenger-conversations.php',
			'WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast' => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-create-messenger-broadcast.php',
			// Google Chat tools.
			'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message'  => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-google-chat-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces'    => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages'  => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-google-chat-messages.php',
			'WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space'  => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-create-google-chat-space.php',
			// Enhanced Telegram tools.
			'WP_MCP_AI_Pro_Tool_Get_Telegram_Updates'      => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-telegram-updates.php',
			'WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook'   => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-manage-telegram-webhook.php',
			// Enhanced WhatsApp tools.
			'WP_MCP_AI_Pro_Tool_Send_WhatsApp_Template'    => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-whatsapp-template.php',
			'WP_MCP_AI_Pro_Tool_Get_WhatsApp_Messages'     => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-whatsapp-messages.php',
			// Unified broadcast tool.
			'WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast' => WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-unified-channel-broadcast.php',
			// Email and communication tools.
			'WP_MCP_AI_Pro_Tool_Search_Gmail'              => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-search-gmail.php',
			'WP_MCP_AI_Pro_Tool_Get_Gmail_Message'         => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-gmail-message.php',
			'WP_MCP_AI_Pro_Tool_Get_Gmail_Thread'          => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-gmail-thread.php',
			'WP_MCP_AI_Pro_Tool_List_Gmail_Connections'    => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-gmail-connections.php',
			'WP_MCP_AI_Pro_Tool_Modify_Gmail_Message'      => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-modify-gmail-message.php',
			'WP_MCP_AI_Pro_Tool_Search_Drive'              => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-search-drive.php',
			'WP_MCP_AI_Pro_Tool_Get_Drive_File'            => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-drive-file.php',
			'WP_MCP_AI_Pro_Tool_List_Drive_Connections'    => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-drive-connections.php',
			'WP_MCP_AI_Pro_Tool_Send_Mailjet_Email'        => WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-send-mailjet-email.php',
			'WP_MCP_AI_Pro_Tool_Send_Brevo_Email'          => WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-send-brevo-email.php',
			'WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts'     => WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-manage-brevo-contacts.php',
			'WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics'      => WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-get-brevo-statistics.php',
			'WP_MCP_AI_Pro_Tool_Send_Mailgun_Email'        => WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-tool-send-mailgun-email.php',
			// Google Workspace tools.
			'WP_MCP_AI_Pro_Tool_Create_Google_Calendar_Event' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php',
			'WP_MCP_AI_Pro_Tool_List_Google_Calendars'     => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendars.php',
			'WP_MCP_AI_Pro_Tool_List_Google_Calendar_Events' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendar-events.php',
			'WP_MCP_AI_Pro_Tool_Update_Google_Calendar_Event' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-update-google-calendar-event.php',
			'WP_MCP_AI_Pro_Tool_Delete_Google_Calendar_Event' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-delete-google-calendar-event.php',
			'WP_MCP_AI_Pro_Tool_Check_Google_Calendar_Availability' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-check-google-calendar-availability.php',
			'WP_MCP_AI_Pro_Tool_Quick_Add_Google_Calendar_Event' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-quick-add-google-calendar-event.php',
			'WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-google-analytics-report.php',
			// Business and accounting tools.
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php',
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty'           => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-get-import-duty.php',
			// Code and development tools.
			'WP_MCP_AI_Pro_Tool_Create_WPCode_Snippet'     => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php',
			'WP_MCP_AI_Pro_Tool_Generic_REST'              => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-generic-rest.php',
			// Toolkit CPT – generic CRUD/search for any pro toolkit Custom Post Type.
			'WP_MCP_AI_Pro_Tool_CPT'                       => WP_MCP_AI_PRO_PATH . 'includes/tools/infrastructure/class-wp-mcp-ai-pro-tool-cpt.php',
			// GitHub tools.
			'WP_MCP_AI_Pro_Tool_Github_Repository_Operations' => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-github-repository-operations.php',
			'WP_MCP_AI_Pro_Tool_List_Github_Repositories'  => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-list-github-repositories.php',
			'WP_MCP_AI_Pro_Tool_Manage_Github_Codespace'   => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-pro-tool-manage-github-codespace.php',
			// Site Creator and related tools.
			'WP_MCP_AI_Pro_Tool_Site_Creator'              => WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-site-creator.php',
			'WP_MCP_AI_Pro_Tool_Install_And_Activate_Plugin' => WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-install-and-activate-plugin.php',
			'WP_MCP_AI_Pro_Tool_Install_And_Activate_Theme' => WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-install-and-activate-theme.php',
			'WP_MCP_AI_Pro_Tool_Update_Option'             => WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-update-option.php',
			// WP All Import/Export Pro tools.
			'WP_MCP_AI_Pro_Tool_Schedule_All_Export'       => WP_MCP_AI_PRO_PATH . 'includes/tools/wp-all-import-export/class-wp-mcp-ai-pro-tool-schedule-all-export.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Export'         => WP_MCP_AI_PRO_PATH . 'includes/tools/wp-all-import-export/class-wp-mcp-ai-pro-tool-delete-all-export.php',
			'WP_MCP_AI_Pro_Tool_Schedule_All_Import'       => WP_MCP_AI_PRO_PATH . 'includes/tools/wp-all-import-export/class-wp-mcp-ai-pro-tool-schedule-all-import.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Import'         => WP_MCP_AI_PRO_PATH . 'includes/tools/wp-all-import-export/class-wp-mcp-ai-pro-tool-delete-all-import.php',
			// Pro Schedule Manager tools.
			'WP_MCP_AI_Pro_Tool_Create_Pro_Schedule'       => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-pro-schedule.php',
			'WP_MCP_AI_Pro_Tool_Update_Pro_Schedule'       => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-update-pro-schedule.php',
			'WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule'       => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-delete-pro-schedule.php',
			'WP_MCP_AI_Pro_Tool_List_Pro_Schedules'        => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-list-pro-schedules.php',
			'WP_MCP_AI_Pro_Tool_Get_Schedule_Run_History'  => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-get-schedule-run-history.php',
			'WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule'      => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-dry-run-pro-schedule.php',
			'WP_MCP_AI_Pro_Tool_Schedule_Channel_Broadcast' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-schedule-channel-broadcast.php',
			'WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-plan-schedules-from-workflow.php',
			'WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-get-schedule-latest-result.php',
			'WP_MCP_AI_Pro_Tool_Render_Schedule_Result'    => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-render-schedule-result.php',
			'WP_MCP_AI_Pro_Tool_Configure_Schedule_Widget_Defaults' => WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-configure-schedule-widget-defaults.php',
			// iSAMS School Management System tool.
			'WP_MCP_AI_Tool_ISAMS_Query'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-isams-query.php',
			// Web Browser Automation tool (Playwright-based).
			'WP_MCP_AI_Tool_Web_Browser'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/capture/class-wp-mcp-ai-tool-web-browser.php',
			// Webpage Screenshot tool — always available, Playwright + mshots fallback.
			'WP_MCP_AI_Tool_Capture_Webpage_Screenshot'    => WP_MCP_AI_PRO_PATH . 'includes/tools/capture/class-wp-mcp-ai-tool-capture-webpage-screenshot.php',
		);

		// Add CRM Toolkit tools if enabled.
		if ( ! empty( $settings['enable_crm_toolkit'] ) ) {
			$crm_tools     = array(
				// ── CRM core CRUD (11 pre-Phase A) ──
				'WP_MCP_AI_Tool_Manage_CRM_Contact'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-manage-crm-contact.php',
				'WP_MCP_AI_Tool_Create_Company'            => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-create-company.php',
				'WP_MCP_AI_Tool_Get_Companies'             => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-get-companies.php',
				'WP_MCP_AI_Tool_Research_Company'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-research-company.php',
				'WP_MCP_AI_Tool_CRM_Email_Search_Leads'    => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-crm-email-search-leads.php',
				'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-crm-email-search-correspondence.php',
				'WP_MCP_AI_Tool_CRM_Email_Search_Accounting' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-crm-email-search-accounting.php',
				'WP_MCP_AI_Tool_Search_Upwork_Jobs'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php',
				'WP_MCP_AI_Tool_Score_Upwork_Job'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php',
				'WP_MCP_AI_Tool_Draft_Upwork_Proposal'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-draft-upwork-proposal.php',
				'WP_MCP_AI_Tool_CRM_Capture_Interaction'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-crm-capture-interaction.php',

				// ── Phase B: Leads (6) ──
				'WP_MCP_AI_Tool_Create_Lead'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php',
				'WP_MCP_AI_Tool_List_Leads'                => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-list-leads.php',
				'WP_MCP_AI_Tool_Get_Lead'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-get-lead.php',
				'WP_MCP_AI_Tool_Update_Lead'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-update-lead.php',
				'WP_MCP_AI_Tool_Delete_Lead'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-delete-lead.php',
				'WP_MCP_AI_Tool_Convert_Lead_To_Customer'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/leads/class-wp-mcp-ai-tool-convert-lead-to-customer.php',

				// ── Phase B: Deals (6) ──
				'WP_MCP_AI_Tool_Create_Deal'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-create-deal.php',
				'WP_MCP_AI_Tool_List_Deals'                => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-list-deals.php',
				'WP_MCP_AI_Tool_Get_Deal'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-get-deal.php',
				'WP_MCP_AI_Tool_Update_Deal'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-update-deal.php',
				'WP_MCP_AI_Tool_Delete_Deal'               => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-delete-deal.php',
				'WP_MCP_AI_Tool_Move_Deal_Stage'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/deals/class-wp-mcp-ai-tool-move-deal-stage.php',

				// ── Phase B: Activities (5) ──
				'WP_MCP_AI_Tool_Create_CRM_Activity'       => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/activities/class-wp-mcp-ai-tool-create-crm-activity.php',
				'WP_MCP_AI_Tool_List_CRM_Activities'       => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/activities/class-wp-mcp-ai-tool-list-crm-activities.php',
				'WP_MCP_AI_Tool_Get_CRM_Activity'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/activities/class-wp-mcp-ai-tool-get-crm-activity.php',
				'WP_MCP_AI_Tool_Complete_CRM_Activity'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/activities/class-wp-mcp-ai-tool-complete-crm-activity.php',
				'WP_MCP_AI_Tool_Snooze_CRM_Activity'       => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/activities/class-wp-mcp-ai-tool-snooze-crm-activity.php',

				// ── Phase B: Pipeline Analytics (5) ──
				'WP_MCP_AI_Tool_Get_Pipeline_View'         => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/analytics/class-wp-mcp-ai-tool-get-pipeline-view.php',
				'WP_MCP_AI_Tool_Get_Conversion_Funnel'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/analytics/class-wp-mcp-ai-tool-get-conversion-funnel.php',
				'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/analytics/class-wp-mcp-ai-tool-forecast-pipeline-revenue.php',
				'WP_MCP_AI_Tool_Identify_Top_Customers'    => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/analytics/class-wp-mcp-ai-tool-identify-top-customers.php',
				'WP_MCP_AI_Tool_Identify_Top_Clients'      => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/analytics/class-wp-mcp-ai-tool-identify-top-clients.php',

				// ── Phase B: Routing (2) ──
				'WP_MCP_AI_Tool_Assign_Lead_To_Owner'      => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/routing/class-wp-mcp-ai-tool-assign-lead-to-owner.php',
				'WP_MCP_AI_Tool_Rotate_Leads'              => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/routing/class-wp-mcp-ai-tool-rotate-leads.php',

				// ── Phase C: Inbound Triage (7) ──
				'WP_MCP_AI_Tool_Evaluate_Inbound_Message'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php',
				'WP_MCP_AI_Tool_Classify_Message_Intent'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-classify-message-intent.php',
				'WP_MCP_AI_Tool_Extract_Lead_From_Message' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-extract-lead-from-message.php',
				'WP_MCP_AI_Tool_Detect_Buying_Signals'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-detect-buying-signals.php',
				'WP_MCP_AI_Tool_Score_Lead'                => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-score-lead.php',
				'WP_MCP_AI_Tool_Qualify_Lead_Bant'         => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-bant.php',
				'WP_MCP_AI_Tool_Qualify_Lead_Meddic'       => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-meddic.php',

				// ── Phase C: Outbound Multichannel (8) ──
				'WP_MCP_AI_Tool_Send_Lead_Email'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php',
				'WP_MCP_AI_Tool_Send_Lead_SMS'             => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-sms.php',
				'WP_MCP_AI_Tool_Send_Lead_Whatsapp'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-whatsapp.php',
				'WP_MCP_AI_Tool_Send_Lead_Dm'              => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-dm.php',
				'WP_MCP_AI_Tool_Log_Call_Outcome'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-log-call-outcome.php',
				'WP_MCP_AI_Tool_Draft_Lead_Reply'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-draft-lead-reply.php',
				'WP_MCP_AI_Tool_Auto_Reply_Inbound'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-auto-reply-inbound.php',
				'WP_MCP_AI_Tool_Schedule_Follow_Up'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-schedule-follow-up.php',

				// ── Phase D: Sequences (7) ──
				'WP_MCP_AI_Tool_Create_Outreach_Sequence'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-create-outreach-sequence.php',
				'WP_MCP_AI_Tool_Update_Outreach_Sequence'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-update-outreach-sequence.php',
				'WP_MCP_AI_Tool_Delete_Outreach_Sequence'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-delete-outreach-sequence.php',
				'WP_MCP_AI_Tool_List_Outreach_Sequences'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-list-outreach-sequences.php',
				'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-enroll-lead-in-sequence.php',
				'WP_MCP_AI_Tool_Manage_Sequence_State'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-manage-sequence-state.php',
				'WP_MCP_AI_Tool_Get_Sequence_Performance'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/sequences/class-wp-mcp-ai-tool-get-sequence-performance.php',

				// ── Phase D: Command Center (5) ──
				'WP_MCP_AI_Tool_Create_Crm_Workflow_Rule'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-create-crm-workflow-rule.php',
				'WP_MCP_AI_Tool_Manage_Workflow_Rules'     => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-manage-workflow-rules.php',
				'WP_MCP_AI_Tool_Simulate_Workflow_Rule'    => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-simulate-workflow-rule.php',
				'WP_MCP_AI_Tool_Get_Workflow_Inbox'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-get-workflow-inbox.php',
				'WP_MCP_AI_Tool_Auto_Route_Inbound_Message' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-auto-route-inbound-message.php',
				'WP_MCP_AI_Tool_Get_Owner_Workload'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/command-center/class-wp-mcp-ai-tool-get-owner-workload.php',

				// ── Phase E: Compliance (7→10) ──
				'WP_MCP_AI_Tool_Record_Consent'            => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-record-consent.php',
				'WP_MCP_AI_Tool_Revoke_Consent'            => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-revoke-consent.php',
				'WP_MCP_AI_Tool_Process_Opt_Out'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-process-opt-out.php',
				'WP_MCP_AI_Tool_Check_Dnc_Status'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-check-dnc-status.php',
				'WP_MCP_AI_Tool_Get_Consent_Audit'         => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-get-consent-audit.php',
				'WP_MCP_AI_Tool_Import_CRM_Csv'            => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-import-crm-csv.php',
				'WP_MCP_AI_Tool_Connect_To_External_Crm'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-connect-to-external-crm.php',
				// ── Phase E: Email Hygiene (3→4) — v2.8.0 ──
				'WP_MCP_AI_Tool_Classify_Email_Hygiene'    => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-classify-email-hygiene.php',
				'WP_MCP_AI_Tool_Manage_Email_Hygiene'      => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-manage-email-hygiene.php',
				'WP_MCP_AI_Tool_Prune_CRM_Messages'        => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-prune-crm-messages.php',
				'WP_MCP_AI_Tool_Repair_CRM_Data'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-repair-crm-data.php',
				// ── Phase E: Deduplication (2) — v2.8.0 ──
				'WP_MCP_AI_Tool_Detect_Duplicates'         => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-detect-duplicates.php',
				'WP_MCP_AI_Tool_Merge_Duplicates'          => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/compliance/class-wp-mcp-ai-tool-merge-duplicates.php',

				// ── Phase E: Blueprint (1) ──
				'WP_MCP_AI_Tool_Import_CRM_Blueprint'      => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples/class-wp-mcp-ai-tool-import-crm-blueprint.php',

				// ── Phase B: Customers (5) — v2.6.0 ──
				'WP_MCP_AI_Tool_Create_Customer'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/customers/class-wp-mcp-ai-tool-create-customer.php',
				'WP_MCP_AI_Tool_Get_Customer'              => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/customers/class-wp-mcp-ai-tool-get-customer.php',
				'WP_MCP_AI_Tool_Update_Customer'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/customers/class-wp-mcp-ai-tool-update-customer.php',
				'WP_MCP_AI_Tool_Delete_Customer'           => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/customers/class-wp-mcp-ai-tool-delete-customer.php',
				'WP_MCP_AI_Tool_List_Customers'            => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/customers/class-wp-mcp-ai-tool-list-customers.php',

				// ── CRM data hygiene & engagement tools (v2.9.0) ──
				'WP_MCP_AI_Tool_Get_Contact_Interactions'  => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-get-contact-interactions.php',
				'WP_MCP_AI_Tool_Archive_Stale_Contacts'    => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-archive-stale-contacts.php',
				'WP_MCP_AI_Tool_Recalculate_Engagement_Scores' => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-recalculate-engagement-scores.php',
				'WP_MCP_AI_Tool_Scan_Duplicate_Contacts'   => WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-tool-scan-duplicate-contacts.php',
			);
				$pro_tools = array_merge( $pro_tools, $crm_tools );
		}

		// Add AI CPT Management tools if enabled.
		if ( ! empty( $settings['enable_ai_cpt_management'] ) ) {
			$cpt_research_tools = array(
				'WP_MCP_AI_Tool_Research_Post'      => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-post.php',
				'WP_MCP_AI_Tool_Research_Page'      => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-page.php',
				'WP_MCP_AI_Tool_Research_Blog_Post' => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-blog-post.php',
			);
			$pro_tools          = array_merge( $pro_tools, $cpt_research_tools );
		}

		// Add ECA management tools if enabled.
		if ( ! empty( $settings['enable_eca_management'] ) ) {
			$eca_tools = array(
				// ECA Management (CRUD).
				'WP_MCP_AI_Tool_Create_ECA'                => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-create-eca.php',
				'WP_MCP_AI_Tool_List_ECAs'                 => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-list-ecas.php',
				'WP_MCP_AI_Tool_Get_ECA'                   => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-get-eca.php',
				'WP_MCP_AI_Tool_Update_ECA'                => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-update-eca.php',
				'WP_MCP_AI_Tool_Delete_ECA'                => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-delete-eca.php',
				// Student Management (CRUD).
				'WP_MCP_AI_Tool_Create_Student'            => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-create-student.php',
				'WP_MCP_AI_Tool_List_Students'             => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-list-students.php',
				'WP_MCP_AI_Tool_Get_Student'               => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-get-student.php',
				'WP_MCP_AI_Tool_Update_Student'            => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-update-student.php',
				'WP_MCP_AI_Tool_Delete_Student'            => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-delete-student.php',
				// Specialized ECA tools.
				'WP_MCP_AI_Tool_Enroll_Student_ECA'        => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-enroll-student-eca.php',
				'WP_MCP_AI_Tool_Sync_Students_From_ISAMS'  => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-sync-students-from-isams.php',
				'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS'      => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-from-isams.php',
				'WP_MCP_AI_Tool_Research_ECA'              => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-research-eca.php',
				// Attendance & participation tools.
				'WP_MCP_AI_Tool_Mark_ECA_Attendance'       => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-mark-eca-attendance.php',
				'WP_MCP_AI_Tool_Get_ECA_Attendance_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-get-eca-attendance-report.php',
				'WP_MCP_AI_Tool_Get_Student_Participation_Summary' => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-get-student-participation-summary.php',
				// Waitlist & enrollment automation.
				'WP_MCP_AI_Tool_Manage_ECA_Waitlist'       => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-manage-eca-waitlist.php',
				'WP_MCP_AI_Tool_Withdraw_Student_ECA'      => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-withdraw-student-eca.php',
				'WP_MCP_AI_Tool_Bulk_Enroll_Students'      => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-bulk-enroll-students.php',
				// Scheduling & conflict detection.
				'WP_MCP_AI_Tool_Check_ECA_Conflicts'       => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-check-eca-conflicts.php',
				'WP_MCP_AI_Tool_Set_ECA_Schedule'          => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-set-eca-schedule.php',
				'WP_MCP_AI_Tool_Get_ECA_Timetable'         => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-get-eca-timetable.php',
				// Notifications & communication.
				'WP_MCP_AI_Tool_Send_ECA_Notification'     => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-send-eca-notification.php',
				'WP_MCP_AI_Tool_Configure_ECA_Notifications' => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-configure-eca-notifications.php',
				'WP_MCP_AI_Tool_Send_ECA_Parent_Report'    => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-send-eca-parent-report.php',
				// Reporting & analytics.
				'WP_MCP_AI_Tool_Generate_ECA_Analytics'    => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-generate-eca-analytics.php',
				'WP_MCP_AI_Tool_Generate_ECA_Participation_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-generate-eca-participation-report.php',
				'WP_MCP_AI_Tool_Export_ECA_Data'           => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-export-eca-data.php',
				// Integration tools.
				'WP_MCP_AI_Tool_Sync_ECA_Enrollments_From_ISAMS' => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-sync-eca-enrollments-from-isams.php',
				'WP_MCP_AI_Tool_Sync_ECAs_To_ISAMS'        => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-to-isams.php',
				'WP_MCP_AI_Tool_Sync_ECAs_From_SOCS'       => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-from-socs.php',
				// Workflow & lifecycle.
				'WP_MCP_AI_Tool_Manage_ECA_Term'           => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-manage-eca-term.php',
				'WP_MCP_AI_Tool_Create_ECA_Workflow_Rule'  => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-create-eca-workflow-rule.php',
				'WP_MCP_AI_Tool_Import_ECAs_CSV'           => WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-import-ecas-csv.php',
			);
			$pro_tools = array_merge( $pro_tools, $eca_tools );
		}

		// Add quiz tools if enabled.
		if ( ! empty( $settings['enable_quiz_system'] ) ) {
			$quiz_tools = array(
				// Quiz CRUD.
				'WP_MCP_AI_Tool_Create_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-create-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz'             => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-get-quiz.php',
				'WP_MCP_AI_Tool_List_Quizzes'         => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-list-quizzes.php',
				'WP_MCP_AI_Tool_Update_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-update-quiz.php',
				'WP_MCP_AI_Tool_Delete_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-delete-quiz.php',
				// Quiz specialized tools.
				'WP_MCP_AI_Tool_Submit_Quiz_Answer'   => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-submit-quiz-answer.php',
				'WP_MCP_AI_Tool_Grade_Quiz'           => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-grade-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz_Submissions' => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-get-quiz-submissions.php',
				'WP_MCP_AI_Tool_Get_Quiz_Results'     => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-get-quiz-results.php',
				'WP_MCP_AI_Tool_Get_Quiz_Analytics'   => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-get-quiz-analytics.php',
				'WP_MCP_AI_Tool_Research_Quiz_Topic'  => WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/class-wp-mcp-ai-tool-research-quiz-topic.php',
				// KaTeX math rendering tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Render_Math_Equation' => WP_MCP_AI_PRO_PATH . 'includes/tools/math/class-wp-mcp-ai-tool-render-math-equation.php',
			);
			$pro_tools  = array_merge( $pro_tools, $quiz_tools );
		}

		// Add places management tools if enabled.
		if ( ! empty( $settings['enable_places_management'] ) ) {
			$places_tools = array(
				'WP_MCP_AI_Tool_Create_Place'             => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-create-place.php',
				'WP_MCP_AI_Tool_List_Places'              => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-list-places.php',
				'WP_MCP_AI_Tool_Update_Place'             => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-update-place.php',
				'WP_MCP_AI_Tool_Delete_Place'             => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-delete-place.php',
				'WP_MCP_AI_Tool_Get_Place'                => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-get-place.php',
				'WP_MCP_AI_Tool_Search_And_Save_Places'   => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-search-and-save-places.php',
				'WP_MCP_AI_Tool_Research_Place'           => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-research-place.php',
				// Turf.js geospatial analysis tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Analyze_Geospatial'       => WP_MCP_AI_PRO_PATH . 'includes/tools/developer/class-wp-mcp-ai-tool-analyze-geospatial.php',
				// Bulk import tools (v1.4.0).
				'WP_MCP_AI_Tool_Import_Places'            => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-import-places.php',
				'WP_MCP_AI_Tool_Import_Places_From_Html'  => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-import-places-from-html.php',
				// Enrichment tools (v1.4.2).
				'WP_MCP_AI_Tool_Enrich_Place_Coordinates' => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-enrich-place-coordinates.php',
				'WP_MCP_AI_Tool_Enrich_Place_Details'     => WP_MCP_AI_PRO_PATH . 'includes/tools/places/class-wp-mcp-ai-tool-enrich-place-details.php',
			);
			$pro_tools    = array_merge( $pro_tools, $places_tools );
		}

		// Add health and wellness management tools if enabled.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			$health_wellness_tools = array(
				// Member Management (CRUD).
				'WP_MCP_AI_Tool_Create_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
				'WP_MCP_AI_Tool_List_Members'              => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-list-members.php',
				'WP_MCP_AI_Tool_Get_Member'                => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-get-member.php',
				'WP_MCP_AI_Tool_Update_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php',
				'WP_MCP_AI_Tool_Delete_Member'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-delete-member.php',
				// Policy Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-create-policy.php',
				'WP_MCP_AI_Tool_List_Policies'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-list-policies.php',
				'WP_MCP_AI_Tool_Get_Policy'                => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-get-policy.php',
				'WP_MCP_AI_Tool_Update_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-update-policy.php',
				'WP_MCP_AI_Tool_Delete_Policy'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-delete-policy.php',
				'WP_MCP_AI_Tool_Search_Policies'           => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
				// Prescription Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php',
				'WP_MCP_AI_Tool_List_Prescriptions'        => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-list-prescriptions.php',
				'WP_MCP_AI_Tool_Get_Prescription'          => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php',
				'WP_MCP_AI_Tool_Update_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-update-prescription.php',
				'WP_MCP_AI_Tool_Delete_Prescription'       => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-delete-prescription.php',
				'WP_MCP_AI_Tool_Search_Prescriptions'      => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-search-prescriptions.php',
				// Medical Record Management (CRUD + Search).
				'WP_MCP_AI_Tool_Create_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php',
				'WP_MCP_AI_Tool_List_Medical_Records'      => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-list-medical-records.php',
				'WP_MCP_AI_Tool_Get_Medical_Record'        => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php',
				'WP_MCP_AI_Tool_Update_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php',
				'WP_MCP_AI_Tool_Delete_Medical_Record'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-delete-medical-record.php',
				'WP_MCP_AI_Tool_Search_Medical_Records'    => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-search-medical-records.php',
				// Checkup/Appointment Management (CRUD + Specialized).
				'WP_MCP_AI_Tool_Create_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-create-checkup.php',
				'WP_MCP_AI_Tool_List_Checkups'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php',
				'WP_MCP_AI_Tool_Get_Checkup'               => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-checkup.php',
				'WP_MCP_AI_Tool_Update_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-update-checkup.php',
				'WP_MCP_AI_Tool_Delete_Checkup'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-delete-checkup.php',
				'WP_MCP_AI_Tool_Get_Upcoming_Checkups'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-upcoming-checkups.php',
				// Allergy Management (CRUD).
				'WP_MCP_AI_Tool_Create_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-create-allergy.php',
				'WP_MCP_AI_Tool_List_Allergies'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php',
				'WP_MCP_AI_Tool_Get_Allergy'               => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-get-allergy.php',
				'WP_MCP_AI_Tool_Update_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-update-allergy.php',
				'WP_MCP_AI_Tool_Delete_Allergy'            => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-delete-allergy.php',
				// Specialized Health & Wellness Tools.
				'WP_MCP_AI_Tool_Get_Member_Health_Summary' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-member-health-summary.php',
				'WP_MCP_AI_Tool_Get_Medication_Schedule'   => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php',
				'WP_MCP_AI_Tool_Research_Policy'           => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-policy.php',
				// Chart.js data visualization tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Generate_Health_Chart'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-generate-health-chart.php',
				// Industry Standards-Based Health Management Tools (FHIR, HIPAA, PHR).
				'WP_MCP_AI_Tool_Create_Health_Reminder'    => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php',
				'WP_MCP_AI_Tool_Track_Vaccinations'        => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-track-vaccinations.php',
				'WP_MCP_AI_Tool_Log_Health_Metrics'        => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-log-health-metrics.php',
				'WP_MCP_AI_Tool_Log_Vital_Signs'           => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-log-vital-signs.php',
				'WP_MCP_AI_Tool_Import_Vitals'             => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-import-vitals.php',
				'WP_MCP_AI_Tool_Export_FHIR_Data'          => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/interop/class-wp-mcp-ai-tool-export-fhir-data.php',
				'WP_MCP_AI_Tool_Manage_Care_Plan'          => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-manage-care-plan.php',
				// Health Research: compile data from CCT, options, files, and vector store.
				'WP_MCP_AI_Tool_Compile_Health_Research_Data' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-compile-health-research-data.php',
				// AI-Assisted Data Entry (agentic flow tools for guided CPT population).
				'WP_MCP_AI_Tool_Guide_Health_Record_Creation' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-guide-health-record-creation.php',
				'WP_MCP_AI_Tool_Parse_Health_Information'  => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-parse-health-information.php',
			);
			$pro_tools             = array_merge( $pro_tools, $health_wellness_tools );

			// Auto-include JetEngine CCT tool when health management is enabled and JetEngine is active.
			// This allows the AI to create/update/delete vitals_log CCT items directly without using create_post.
			if ( function_exists( 'jet_engine' ) && ! isset( $pro_tools['WP_MCP_AI_Pro_Tool_JetEngine'] ) ) {
				$pro_tools['WP_MCP_AI_Pro_Tool_JetEngine'] = WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine.php';
			}
		}

		// Add Medical Vitals sub-toolkit (Phase B) tools if enabled.
		// Defaults to the value of `enable_health_wellness_management` for
		// backwards compatibility (see WP_MCP_AI_Healthcare_Engine).
		$vitals_enabled = array_key_exists( 'enable_medical_vitals', $settings )
			? ! empty( $settings['enable_medical_vitals'] )
			: ! empty( $settings['enable_health_wellness_management'] );
		if ( $vitals_enabled ) {
			$vitals_tools = array(
				'WP_MCP_AI_Tool_Flag_Abnormal_Vitals'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-flag-abnormal-vitals.php',
				'WP_MCP_AI_Tool_Analyze_Vital_Trends'     => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php',
				'WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php',
				'WP_MCP_AI_Tool_Get_Vaccination_Schedule' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-get-vaccination-schedule.php',
			);
			$pro_tools    = array_merge( $pro_tools, $vitals_tools );
		}

		// Add Health & Wellness breadth (Phase C) tools if enabled.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			$wellness_tools = array(
				'WP_MCP_AI_Tool_Check_Member_Allergies'    => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-check-member-allergies.php',
				'WP_MCP_AI_Tool_Get_Health_Timeline'       => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-get-health-timeline.php',
				'WP_MCP_AI_Tool_Link_Prescription_To_Record' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-link-prescription-to-record.php',
				'WP_MCP_AI_Tool_Verify_Prescription_Interactions' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php',
				'WP_MCP_AI_Tool_Generate_Visit_Summary'    => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-generate-visit-summary.php',
				'WP_MCP_AI_Tool_Merge_Duplicate_Members'   => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-merge-duplicate-members.php',
				// MemPalace capture tool (Phase B1) — clinical encounter (PHI, tier=core).
				'WP_MCP_AI_Tool_Health_Capture_Encounter'  => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php',
				// Appointment query & follow-up tools (v2.9.0).
				'WP_MCP_AI_Tool_Get_Recent_Health_Appointments' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-get-recent-health-appointments.php',
				'WP_MCP_AI_Tool_Send_Appointment_Followup' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/wellness/class-wp-mcp-ai-tool-send-appointment-followup.php',
			);
			$pro_tools      = array_merge( $pro_tools, $wellness_tools );
		}

		// Add Healthcare Imaging tools if enabled.
		if ( ! empty( $settings['enable_healthcare_imaging'] ) ) {
			// Helper: DICOMweb HTTP client (not a tool — required by Phase D tools).
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-dicomweb-client.php';

			$imaging_tools = array(
				'WP_MCP_AI_Tool_Manage_Imaging_Studies'  => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-manage-imaging-studies.php',
				'WP_MCP_AI_Tool_Interpret_Imaging_Study' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php',
				// Phase D — DICOMweb depth.
				'WP_MCP_AI_Tool_Connect_DICOMweb'        => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-connect-dicomweb.php',
				'WP_MCP_AI_Tool_Import_DICOM_Study'      => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-import-dicom-study.php',
				'WP_MCP_AI_Tool_Export_DICOM_Study'      => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-export-dicom-study.php',
				'WP_MCP_AI_Tool_Attach_Radiology_Report' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-attach-radiology-report.php',
				'WP_MCP_AI_Tool_Compare_Imaging_Studies' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-compare-imaging-studies.php',
				'WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/imaging/class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php',
			);
			$pro_tools     = array_merge( $pro_tools, $imaging_tools );
		}

		// Add Healthcare Interoperability tools (Phase E) — gated on health & wellness toggle.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			$interop_tools = array(
				'WP_MCP_AI_Tool_Import_FHIR_Bundle'   => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/interop/class-wp-mcp-ai-tool-import-fhir-bundle.php',
				'WP_MCP_AI_Tool_Export_CCDA_Document' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/interop/class-wp-mcp-ai-tool-export-ccda-document.php',
				'WP_MCP_AI_Tool_Import_HL7v2_Message' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/interop/class-wp-mcp-ai-tool-import-hl7v2-message.php',
				'WP_MCP_AI_Tool_Connect_To_EHR'       => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/interop/class-wp-mcp-ai-tool-connect-to-ehr.php',
				// Phase E: Blueprint import.
				'WP_MCP_AI_Tool_Import_Healthcare_Blueprint' => WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php',
			);
			$pro_tools     = array_merge( $pro_tools, $interop_tools );
		}

		// Vehicle Estimation tools — always available Pro tools.
		$vehicle_tools = array(
			'WP_MCP_AI_Tool_VIN_Decode'                => WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vin-decode.php',
			'WP_MCP_AI_Tool_Vehicle_Repair_Estimate'   => WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-repair-estimate.php',
			'WP_MCP_AI_Tool_Vehicle_Cleaning_Estimate' => WP_MCP_AI_PRO_PATH . 'includes/tools/automotive/class-wp-mcp-ai-tool-vehicle-cleaning-estimate.php',
		);
		$pro_tools     = array_merge( $pro_tools, $vehicle_tools );

		// Add WooCommerce tools if enabled.
		if ( wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings ) ) {
			$woo_tools = array(
				'WP_MCP_AI_Pro_Tool_Woo_Products'  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-products.php',
				'WP_MCP_AI_Pro_Tool_Woo_Orders'    => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-orders.php',
				'WP_MCP_AI_Pro_Tool_Woo_Customers' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-customers.php',
				'WP_MCP_AI_Pro_Tool_Woo_Coupons'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-coupons.php',
				'WP_MCP_AI_Tool_Research_Product'  => WP_MCP_AI_PRO_PATH . 'includes/tools/research/class-wp-mcp-ai-tool-research-product.php',
			);
			$pro_tools = array_merge( $pro_tools, $woo_tools );
		}

		// Add Shopify tools — always available when a Shopify connection is configured.
		// The tools themselves validate the connection at execution time.
		// Load the shared Shopify connection resolver trait.
		if ( ! trait_exists( 'WP_MCP_AI_Shopify_Connection_Resolver' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';
		}
		// Load the smart search trait for progressive query relaxation.
		if ( ! trait_exists( 'WP_MCP_AI_Shopify_Smart_Search' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-smart-search.php';
		}
		$shopify_tools = array(
			'WP_MCP_AI_Tool_Remote_Shopify_Connection' => WP_MCP_AI_PRO_PATH . 'includes/tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Products'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-products.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Orders'        => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-orders.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Customers'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-customers.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Inventory'     => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-inventory.php',
			'WP_MCP_AI_Pro_Tool_Shopify_Catalog'       => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-catalog.php',
		);
		$pro_tools     = array_merge( $pro_tools, $shopify_tools );

		// Add JetEngine tools if enabled.
		if ( ! empty( $settings['enable_jetengine_tools'] ) ) {
			$jetengine_tools = array(
				'WP_MCP_AI_Pro_Tool_JetEngine' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine.php',
			);
			$pro_tools       = array_merge( $pro_tools, $jetengine_tools );

			// JetEngine 3.8+ MCP Server tools.
			$jetengine_mcp_tools = array(
				'WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-mcp-bridge.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-create-post-type.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-create-taxonomy.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-create-meta-field.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-manage-relations.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Site_Context' => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-site-context.php',
				'WP_MCP_AI_Pro_Tool_JetEngine_Prompts'    => WP_MCP_AI_PRO_PATH . 'includes/tools/jetengine/class-wp-mcp-ai-pro-tool-jetengine-prompts.php',
			);
			$pro_tools           = array_merge( $pro_tools, $jetengine_mcp_tools );
		}

		// Add Elementor tools if enabled.
		if ( ! empty( $settings['enable_elementor_widgets'] ) ) {
			$elementor_tools = array(
				'WP_MCP_AI_Pro_Tool_Elementor' => WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-elementor.php',
			);
			$pro_tools       = array_merge( $pro_tools, $elementor_tools );
		}

		// Add Media Toolkit tools if enabled.
		if ( ! empty( $settings['enable_media_toolkit'] ) ) {
			$media_toolkit_tools = array(
				'WP_MCP_AI_Tool_List_Media_Templates'      => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-list-media-templates.php',
				'WP_MCP_AI_Tool_Apply_Media_Template'      => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-apply-media-template.php',
				'WP_MCP_AI_Tool_Create_Media_Template'     => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-create-media-template.php',
				'WP_MCP_AI_Tool_Create_Media_Collection'   => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-create-media-collection.php',
				'WP_MCP_AI_Tool_Process_Collection'        => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-process-collection.php',
				'WP_MCP_AI_Tool_Apply_Collection_Template' => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-apply-collection-template.php',
				// Sharp image optimization tool (enhanced with NPM package).
				'WP_MCP_AI_Tool_Optimize_Image_Sharp'      => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-optimize-image-sharp.php',
				// Orphaned media management tools.
				'WP_MCP_AI_Tool_Scan_Orphaned_Media'       => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-scan-orphaned-media.php',
				'WP_MCP_AI_Tool_Cleanup_Orphaned_Media'    => WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-cleanup-orphaned-media.php',
			);
			$pro_tools           = array_merge( $pro_tools, $media_toolkit_tools );
		}

		// Add Image Production Toolkit tools if enabled (Phase 2.9).
		if ( ! empty( $settings['enable_image_production_toolkit'] ) ) {
			$image_production_tools = array(
				// Query tools.
				'WP_MCP_AI_Tool_Get_Images_Without_Alt'   => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-get-images-without-alt.php',
				'WP_MCP_AI_Tool_Get_Unoptimised_Images'   => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-get-unoptimised-images.php',
				'WP_MCP_AI_Tool_Get_Unwatermarked_Images' => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-get-unwatermarked-images.php',
				// Action tools.
				'WP_MCP_AI_Tool_Apply_Watermark_Batch'    => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-apply-watermark-batch.php',
				'WP_MCP_AI_Tool_Optimise_Images_Batch'    => WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-optimise-images-batch.php',
			);
			$pro_tools              = array_merge( $pro_tools, $image_production_tools );
		}

		// Add DJ Management Toolkit tools if enabled (Phase 2.7).
		if ( ! empty( $settings['enable_dj_management_toolkit'] ) ) {
			$dj_tools  = array(
				'WP_MCP_AI_Tool_Get_Trending_Tracks'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php',
				'WP_MCP_AI_Tool_Update_Playlist_Rotation' => WP_MCP_AI_PRO_PATH . 'includes/tools/dj-management/class-wp-mcp-ai-tool-update-playlist-rotation.php',
			);
			$pro_tools = array_merge( $pro_tools, $dj_tools );
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
				'WP_MCP_AI_Tool_Get_Abandoned_Carts'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-get-abandoned-carts.php',
				'WP_MCP_AI_Tool_Send_Cart_Recovery_Email' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-send-cart-recovery-email.php',
				'WP_MCP_AI_Tool_Upsell_Recommendations'   => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-upsell-recommendations.php',
				'WP_MCP_AI_Tool_Sales_Performance_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-sales-performance-dashboard.php',
				// Shipping & Fulfillment tools.
				'WP_MCP_AI_Tool_Shipping_Box_Packer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-box-packer.php',
				'WP_MCP_AI_Tool_Shipping_Rate_Estimator'  => WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-shipping-rate-estimator.php',
			);

			// Preserve the WooCommerce order-based invoice contract when the
			// generic document-generation implementation is not enabled.
			if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
				$ecommerce_toolkit_tools['WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF'] = WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php';
			}

			$pro_tools = array_merge( $pro_tools, $ecommerce_toolkit_tools );
		}

		// Add FlowHub Inventory Sync Toolkit tools if enabled (Pro feature � FlowHub POS integration).
		if ( ! empty( $settings['enable_flowhub_toolkit'] ) && class_exists( 'WooCommerce' ) ) {
			$flowhub_toolkit_tools = array(
				'WP_MCP_AI_Pro_Tool_FlowHub_Inventory' => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-inventory.php',
				'WP_MCP_AI_Pro_Tool_FlowHub_Products'  => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-products.php',
				'WP_MCP_AI_Pro_Tool_FlowHub_Locations' => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-locations.php',
				'WP_MCP_AI_Pro_Tool_FlowHub_Sync'      => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-sync.php',
				'WP_MCP_AI_Pro_Tool_FlowHub_Settings'  => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-settings.php',
				'WP_MCP_AI_Pro_Tool_FlowHub_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-analytics.php',
			);
			$pro_tools             = array_merge( $pro_tools, $flowhub_toolkit_tools );
		}

		// Add EZuite Inventory Sync Pro Toolkit tools if enabled (Pro feature — EZuite ERP integration).
		if ( ! empty( $settings['enable_ezuite_toolkit'] ) && class_exists( 'WooCommerce' ) ) {
			$ezuite_toolkit_tools = array(
				'WP_MCP_AI_Pro_Tool_EZuite_Inventory' => WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/class-wp-mcp-ai-pro-tool-ezuite-inventory.php',
				'WP_MCP_AI_Pro_Tool_EZuite_Sync'      => WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/class-wp-mcp-ai-pro-tool-ezuite-sync.php',
				'WP_MCP_AI_Pro_Tool_EZuite_Settings'  => WP_MCP_AI_PRO_PATH . 'includes/tools/erp-ezuite/class-wp-mcp-ai-pro-tool-ezuite-settings.php',
			);
			$pro_tools            = array_merge( $pro_tools, $ezuite_toolkit_tools );
		}

		// Add Shopify Sync Toolkit tools if enabled (Pro feature — Shopify↔WooCommerce sync with CCT cache).
		if ( ! empty( $settings['enable_shopify_sync_toolkit'] ) && class_exists( 'WooCommerce' ) && class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			$shopify_sync_toolkit_tools = array(
				'WP_MCP_AI_Pro_Tool_Shopify_Sync_Inventory' => WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-inventory.php',
				'WP_MCP_AI_Pro_Tool_Shopify_Sync_Products' => WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-products.php',
				'WP_MCP_AI_Pro_Tool_Shopify_Sync_Orders'   => WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-orders.php',
				'WP_MCP_AI_Pro_Tool_Shopify_Sync_Settings' => WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-settings.php',
				'WP_MCP_AI_Pro_Tool_Shopify_Sync_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-analytics.php',
			);
			$pro_tools                  = array_merge( $pro_tools, $shopify_sync_toolkit_tools );
		}

			// Add Cloudways Pro Toolkit tools if enabled (Phase 1-4 — server/application management).
		if ( ! empty( $settings['enable_cloudways_toolkit'] ) ) {
			$cloudways_toolkit_tools = array(
				// Phase 1 - Read tools.
				'WP_MCP_AI_Tool_Cloudways_List_Servers'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-servers.php',
				'WP_MCP_AI_Tool_Cloudways_Get_Server'      => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-server.php',
				'WP_MCP_AI_Tool_Cloudways_List_Apps'       => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-apps.php',
				'WP_MCP_AI_Tool_Cloudways_Get_App'         => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-app.php',
				'WP_MCP_AI_Tool_Cloudways_Service_Status'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-service-status.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Monitor_Summary' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-monitor-summary.php',
				'WP_MCP_AI_Tool_Cloudways_App_Monitor_Summary' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-monitor-summary.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Settings_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-settings-get.php',
				'WP_MCP_AI_Tool_Cloudways_App_Traffic_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-traffic-analytics.php',
				'WP_MCP_AI_Tool_Cloudways_App_PHP_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-php-analytics.php',
				'WP_MCP_AI_Tool_Cloudways_App_MySQL_Analytics' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-mysql-analytics.php',
				'WP_MCP_AI_Tool_Cloudways_App_Vulnerabilities_List' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-vulnerabilities-list.php',
				'WP_MCP_AI_Tool_Cloudways_List_Projects'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-projects.php',
				'WP_MCP_AI_Tool_Cloudways_Get_Operation_Status' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-get-operation-status.php',
				// Phase 2 - Safe action tools.
				'WP_MCP_AI_Tool_Cloudways_Purge_App_Cache' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-purge-app-cache.php',
				'WP_MCP_AI_Tool_Cloudways_Restart_Service' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-restart-service.php',
				'WP_MCP_AI_Tool_Cloudways_Create_App_Backup' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-create-app-backup.php',
				'WP_MCP_AI_Tool_Cloudways_Create_Server_Backup' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-create-server-backup.php',
				'WP_MCP_AI_Tool_Cloudways_Update_Server_Label' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-update-server-label.php',
				'WP_MCP_AI_Tool_Cloudways_Update_App_Label' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-update-app-label.php',
				'WP_MCP_AI_Tool_Cloudways_Git_Pull'        => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-pull.php',
				'WP_MCP_AI_Tool_Cloudways_Git_History_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-history-get.php',
				'WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cron-list-get.php',
				'WP_MCP_AI_Tool_Cloudways_App_Credentials' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-credentials.php',
				// Phase 3 - Provisioning & destructive tools.
				'WP_MCP_AI_Tool_Cloudways_Server_Start'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-start.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Stop'     => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-stop.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Restart'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-restart.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-scale.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Clone'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-clone.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Create'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-create.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Delete'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-delete.php',
				'WP_MCP_AI_Tool_Cloudways_App_Create'      => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-create.php',
				'WP_MCP_AI_Tool_Cloudways_App_Clone'       => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-clone.php',
				'WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-clone-to-server.php',
				'WP_MCP_AI_Tool_Cloudways_App_Delete'      => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-delete.php',
				'WP_MCP_AI_Tool_Cloudways_App_Restore'     => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-restore.php',
				'WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-restore-rollback.php',
				'WP_MCP_AI_Tool_Cloudways_App_CNAME_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cname-update.php',
				'WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-server-scale-volume.php',
				// Phase 4 - Add-ons, DNS, Cloudflare, SSH, Git, Copilot, Advanced.
				'WP_MCP_AI_Tool_Cloudways_Addon_List'      => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-addon-list.php',
				'WP_MCP_AI_Tool_Cloudways_Addon_Activate'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-addon-activate.php',
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Details' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-cloudflare-details.php',
				'WP_MCP_AI_Tool_Cloudways_Cloudflare_Add_Domain' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-cloudflare-add-domain.php',
				'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-list-domains.php',
				'WP_MCP_AI_Tool_Cloudways_DNS_List_Records' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-list-records.php',
				'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-add-record.php',
				'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-dns-delete-record.php',
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_Create'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-create.php',
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-delete.php',
				'WP_MCP_AI_Tool_Cloudways_SSH_Key_List'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-ssh-key-list.php',
				'WP_MCP_AI_Tool_Cloudways_Git_Generate_Key' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-generate-key.php',
				'WP_MCP_AI_Tool_Cloudways_Git_Key_Get'     => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-key-get.php',
				'WP_MCP_AI_Tool_Cloudways_Git_Branches_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-branches-get.php',
				'WP_MCP_AI_Tool_Cloudways_Git_Clone'       => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-git-clone.php',
				'WP_MCP_AI_Tool_Cloudways_Copilot_Insights_List' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-copilot-insights-list.php',
				'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-fpm-settings-get.php',
				'WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-fpm-settings-update.php',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-varnish-settings-get.php',
				'WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-varnish-settings-update.php',
				'WP_MCP_AI_Tool_Cloudways_App_CORS_Headers_Update' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-app-cors-headers-update.php',
			);
			$pro_tools               = array_merge( $pro_tools, $cloudways_toolkit_tools );
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
				// Unified social analytics (Phase 4 shared service).
				'WP_MCP_AI_Tool_Get_Social_Analytics'      => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-get-social-analytics.php',
				// Content Management tools.
				'WP_MCP_AI_Tool_Create_Content_Calendar'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-create-content-calendar.php',
				'WP_MCP_AI_Tool_Generate_Post_Ideas'       => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-generate-post-ideas.php',
				'WP_MCP_AI_Tool_Social_Listening_Trends'   => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-social-listening-trends.php',
				// MemPalace capture tool (Phase B1) — voice + post performance.
				'WP_MCP_AI_Tool_Social_Capture_Post_Performance' => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-social-capture-post-performance.php',
				// Phase 2.8 — Content Calendar query, AI captions, batch scheduling, immediate publishing.
				'WP_MCP_AI_Tool_Get_Content_Calendar'      => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-get-content-calendar.php',
				'WP_MCP_AI_Tool_Generate_Social_Captions'  => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-generate-social-captions.php',
				'WP_MCP_AI_Tool_Schedule_Social_Posts'     => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-schedule-social-posts.php',
				'WP_MCP_AI_Tool_Publish_To_Social'         => WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-tool-publish-to-social.php',
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
				// Market Analysis & Research tools (inspired by Awesome-finance-skills).
				'WP_MCP_AI_Tool_Financial_News_Aggregator' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-news-aggregator.php',
				'WP_MCP_AI_Tool_Stock_Data_Fetcher'        => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php',
				'WP_MCP_AI_Tool_Market_Sentiment_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-market-sentiment-analyzer.php',
				'WP_MCP_AI_Tool_Market_Forecast_Analyzer'  => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-market-forecast-analyzer.php',
				'WP_MCP_AI_Tool_Investment_Signal_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-investment-signal-tracker.php',
				'WP_MCP_AI_Tool_Financial_Logic_Visualizer' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-logic-visualizer.php',
				'WP_MCP_AI_Tool_Financial_Report_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-report-generator.php',
				'WP_MCP_AI_Tool_Financial_Search'          => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-search.php',
				// Transaction Categorisation tools.
				'WP_MCP_AI_Tool_Get_Uncategorised_Transactions' => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-get-uncategorised-transactions.php',
				'WP_MCP_AI_Tool_Categorise_Transactions'   => WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-categorise-transactions.php',
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
				// Phase 2.8 — Queue management, thumbnail/transcript gaps, batch upload, transcription.
				'WP_MCP_AI_Tool_Get_Queued_Videos'         => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-get-queued-videos.php',
				'WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails' => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-get-videos-without-thumbnails.php',
				'WP_MCP_AI_Tool_Get_Videos_Without_Transcripts' => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-get-videos-without-transcripts.php',
				'WP_MCP_AI_Tool_Upload_Video_Batch'        => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-upload-video-batch.php',
				'WP_MCP_AI_Tool_Transcribe_Video'          => WP_MCP_AI_PRO_PATH . 'includes/tools/video-production/class-wp-mcp-ai-tool-transcribe-video.php',
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
				'WP_MCP_AI_Tool_Pro_PDF'                => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php',
				'WP_MCP_AI_Tool_Pro_Word'               => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php',
				'WP_MCP_AI_Tool_Pro_Excel_Document'     => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php',
				// Simplified document generation tools.
				'WP_MCP_AI_Tool_Generate_PDF'           => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php',
				'WP_MCP_AI_Tool_Generate_Word'          => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php',
				'WP_MCP_AI_Tool_Generate_Excel'         => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php',
				// PDF manipulation tools.
				'WP_MCP_AI_Tool_Extract_PDF_Text'       => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php',
				'WP_MCP_AI_Tool_OCR_PDF_Text'           => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php',
				'WP_MCP_AI_Tool_Pro_Document_OCR'       => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php',
				'WP_MCP_AI_Tool_HTML_To_PDF'            => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php',
				'WP_MCP_AI_Tool_Merge_PDFs'             => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php',
				'WP_MCP_AI_Tool_Add_Watermark_To_PDF'   => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php',
				'WP_MCP_AI_Tool_Generate_Invoice_PDF'   => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php',
				// Excel data tools.
				'WP_MCP_AI_Tool_Excel_Data_Import'      => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php',
				'WP_MCP_AI_Tool_Excel_Data_Export'      => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-export.php',
				// MemPalace capture tool (Phase B1) — style + drafts; allows summarisation discipline.
				'WP_MCP_AI_Tool_DocGen_Capture_Style_Memory' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-docgen-capture-style-memory.php',
				// Document audit & batch tools (v2.9.0).
				'WP_MCP_AI_Tool_Get_Expired_Documents'  => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-get-expired-documents.php',
				'WP_MCP_AI_Tool_Get_Uninvoiced_Orders'  => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-get-uninvoiced-orders.php',
				'WP_MCP_AI_Tool_Archive_Documents'      => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-archive-documents.php',
				'WP_MCP_AI_Tool_Generate_Invoice_Batch' => WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-batch.php',
			);
				$pro_tools             = array_merge( $pro_tools, $document_generation_tools );
		}

		// Add CRE Debt & Securitization toolkit tools if enabled.
		if ( ! empty( $settings['enable_cre_debt_toolkit'] ) ) {
			$cre_debt_toolkit_tools = array(
				// Originations module (11 tools).
				'WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-pipeline-manager.php',
				'WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-borrower-profile-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Loan_Quote_Generator'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-loan-quote-generator.php',
				'WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-market-comp-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-screening-calculator.php',
				'WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-origination-volume-tracker.php',
				'WP_MCP_AI_Tool_CRE_Rate_Lock_Manager'     => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-rate-lock-manager.php',
				'WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-broker-relationship-tracker.php',
				'WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-term-sheet-comparator.php',
				'WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-execution-strategy-advisor.php',
				'WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-closing-checklist-manager.php',
				// Underwriting module (13 tools).
				'WP_MCP_AI_Tool_CRE_DCF_Modeler'           => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-dcf-modeler.php',
				'WP_MCP_AI_Tool_CRE_NOI_Calculator'        => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-noi-calculator.php',
				'WP_MCP_AI_Tool_CRE_Loan_Sizer'            => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-loan-sizer.php',
				'WP_MCP_AI_Tool_CRE_Amortization_Scheduler' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-amortization-scheduler.php',
				'WP_MCP_AI_Tool_CRE_Debt_Yield_Analyzer'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-debt-yield-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Cap_Rate_Sensitivity'  => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php',
				'WP_MCP_AI_Tool_CRE_Rent_Roll_Analyzer'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-rent-roll-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Operating_Expense_Benchmarker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-operating-expense-benchmarker.php',
				'WP_MCP_AI_Tool_CRE_Stress_Test_Modeler'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-stress-test-modeler.php',
				'WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-leverage-return-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Property_Valuation_Engine' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-property-valuation-engine.php',
				'WP_MCP_AI_Tool_CRE_Environmental_Risk_Scorer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-environmental-risk-scorer.php',
				'WP_MCP_AI_Tool_CRE_Underwriting_Memo_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php',
				// CMBS / Securitization module (10 tools).
				'WP_MCP_AI_Tool_CMBS_Deal_Structurer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-deal-structurer.php',
				'WP_MCP_AI_Tool_CMBS_Bond_Cash_Flow_Modeler' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-bond-cash-flow-modeler.php',
				'WP_MCP_AI_Tool_CMBS_Pool_Analyzer'        => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-pool-analyzer.php',
				'WP_MCP_AI_Tool_CMBS_Surveillance_Monitor' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-surveillance-monitor.php',
				'WP_MCP_AI_Tool_CMBS_Special_Servicing_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-special-servicing-tracker.php',
				'WP_MCP_AI_Tool_CRE_CLO_Modeler'           => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cre-clo-modeler.php',
				'WP_MCP_AI_Tool_CMBS_Defeasance_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-defeasance-calculator.php',
				'WP_MCP_AI_Tool_CMBS_Rating_Agency_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-rating-agency-analyzer.php',
				'WP_MCP_AI_Tool_CMBS_Investor_Reporting_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-investor-reporting-generator.php',
				'WP_MCP_AI_Tool_CMBS_Maturity_Risk_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/cmbs/class-wp-mcp-ai-tool-cmbs-maturity-risk-analyzer.php',
				// Debt Fund Management module (11 tools).
				'WP_MCP_AI_Tool_CRE_Fund_Portfolio_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-portfolio-dashboard.php',
				'WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-debt-waterfall-modeler.php',
				'WP_MCP_AI_Tool_CRE_Fund_Return_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-return-calculator.php',
				'WP_MCP_AI_Tool_CRE_Credit_Risk_Scorer'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-credit-risk-scorer.php',
				'WP_MCP_AI_Tool_CRE_Concentration_Limit_Monitor' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-concentration-limit-monitor.php',
				'WP_MCP_AI_Tool_CRE_Warehouse_Line_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-warehouse-line-manager.php',
				'WP_MCP_AI_Tool_CRE_LP_Report_Generator'   => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-lp-report-generator.php',
				'WP_MCP_AI_Tool_CRE_Fund_Capital_Call_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php',
				'WP_MCP_AI_Tool_CRE_Fund_Liquidity_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-liquidity-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Covenant_Compliance_Checker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php',
				'WP_MCP_AI_Tool_CRE_Fund_Scenario_Modeler' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-scenario-modeler.php',
				// Asset Management module (12 tools).
				'WP_MCP_AI_Tool_CRE_Property_Budget_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-budget-manager.php',
				'WP_MCP_AI_Tool_CRE_Lease_Expiration_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-lease-expiration-manager.php',
				'WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-capex-reserve-planner.php',
				'WP_MCP_AI_Tool_CRE_Tenant_Credit_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-tenant-credit-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Hold_Sell_Analyzer'    => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-hold-sell-analyzer.php',
				'WP_MCP_AI_Tool_CRE_Property_Performance_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-property-performance-tracker.php',
				'WP_MCP_AI_Tool_CRE_Loan_Surveillance_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-surveillance-dashboard.php',
				'WP_MCP_AI_Tool_CRE_Watchlist_Manager'     => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-watchlist-manager.php',
				'WP_MCP_AI_Tool_CRE_Workout_Scenario_Modeler' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-workout-scenario-modeler.php',
				'WP_MCP_AI_Tool_CRE_Loan_Modification_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-loan-modification-calculator.php',
				'WP_MCP_AI_Tool_CRE_Servicing_Fee_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-servicing-fee-calculator.php',
				'WP_MCP_AI_Tool_CRE_Asset_Disposition_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/cre-debt/asset-management/class-wp-mcp-ai-tool-cre-asset-disposition-analyzer.php',
			);
			$pro_tools              = array_merge( $pro_tools, $cre_debt_toolkit_tools );
		}

		// Add Law Firm toolkit tools if enabled (62 tools across 7 modules).
		if ( ! empty( $settings['enable_law_firm_toolkit'] ) ) {
			$law_firm_toolkit_tools = array(
				// Client Intake & Management module (8 tools).
				'WP_MCP_AI_Tool_LF_Client_Intake_Processor' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-intake-processor.php',
				'WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-conflict-of-interest-checker.php',
				'WP_MCP_AI_Tool_LF_Client_Profile_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-profile-analyzer.php',
				'WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-lead-scoring-calculator.php',
				'WP_MCP_AI_Tool_LF_Engagement_Letter_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-engagement-letter-generator.php',
				'WP_MCP_AI_Tool_LF_Client_Communication_Logger' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-communication-logger.php',
				'WP_MCP_AI_Tool_LF_Referral_Source_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-referral-source-tracker.php',
				'WP_MCP_AI_Tool_LF_Client_Portal_Manager'  => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-portal-manager.php',
				// Matter & Case Management module (10 tools).
				'WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php',
				'WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-statute-of-limitations-calculator.php',
				'WP_MCP_AI_Tool_LF_Court_Deadline_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-court-deadline-tracker.php',
				'WP_MCP_AI_Tool_LF_Case_Timeline_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-timeline-generator.php',
				'WP_MCP_AI_Tool_LF_Task_Assignment_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-task-assignment-manager.php',
				'WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-calendar-rule-calculator.php',
				'WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-opposing-counsel-tracker.php',
				'WP_MCP_AI_Tool_LF_Case_Outcome_Predictor' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-outcome-predictor.php',
				'WP_MCP_AI_Tool_LF_Matter_Budget_Manager'  => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php',
				'WP_MCP_AI_Tool_LF_Case_Status_Dashboard'  => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-status-dashboard.php',
				// Document Automation module (10 tools).
				'WP_MCP_AI_Tool_LF_Document_Drafter'       => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-drafter.php',
				'WP_MCP_AI_Tool_LF_Contract_Reviewer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-contract-reviewer.php',
				'WP_MCP_AI_Tool_LF_Clause_Library_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-clause-library-manager.php',
				'WP_MCP_AI_Tool_LF_Redline_Comparator'     => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-redline-comparator.php',
				'WP_MCP_AI_Tool_LF_Pleading_Generator'     => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php',
				'WP_MCP_AI_Tool_LF_Discovery_Request_Builder' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php',
				'WP_MCP_AI_Tool_LF_Document_Version_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-version-tracker.php',
				'WP_MCP_AI_Tool_LF_Legal_Citation_Checker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-legal-citation-checker.php',
				'WP_MCP_AI_Tool_LF_Brief_Outline_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-brief-outline-generator.php',
				'WP_MCP_AI_Tool_LF_Document_Template_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php',
				// Billing & Trust Accounting module (10 tools).
				'WP_MCP_AI_Tool_LF_Time_Entry_Recorder'    => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-time-entry-recorder.php',
				'WP_MCP_AI_Tool_LF_Invoice_Generator'      => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-invoice-generator.php',
				'WP_MCP_AI_Tool_LF_Trust_Account_Manager'  => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-account-manager.php',
				'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-reconciliation-tool.php',
				'WP_MCP_AI_Tool_LF_Fee_Calculator'         => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-fee-calculator.php',
				'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-billing-compliance-checker.php',
				'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-accounts-receivable-tracker.php',
				'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-retainer-balance-monitor.php',
				'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-expense-reimbursement-tracker.php',
				'WP_MCP_AI_Tool_LF_Profitability_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-profitability-analyzer.php',
				// Compliance & Ethics module (8 tools).
				'WP_MCP_AI_Tool_LF_Ethics_Rule_Checker'    => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ethics-rule-checker.php',
				'WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor'   => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-bar-deadline-monitor.php',
				'WP_MCP_AI_Tool_LF_CLE_Credit_Tracker'     => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-cle-credit-tracker.php',
				'WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-malpractice-risk-scorer.php',
				'WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-data-privacy-compliance-checker.php',
				'WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-client-confidentiality-auditor.php',
				'WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-regulatory-change-monitor.php',
				'WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ai-usage-disclosure-generator.php',
				// Litigation Support module (8 tools).
				'WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-ediscovery-document-analyzer.php',
				'WP_MCP_AI_Tool_LF_Deposition_Summary_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-deposition-summary-generator.php',
				'WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-evidence-catalog-manager.php',
				'WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php',
				'WP_MCP_AI_Tool_LF_Settlement_Value_Calculator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-settlement-value-calculator.php',
				'WP_MCP_AI_Tool_LF_Damages_Calculator'     => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-damages-calculator.php',
				'WP_MCP_AI_Tool_LF_Expert_Witness_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-expert-witness-tracker.php',
				'WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-trial-preparation-checklist.php',
				// Legal Research & Analytics module (8 tools).
				'WP_MCP_AI_Tool_LF_Legal_Research_Assistant' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-legal-research-assistant.php',
				'WP_MCP_AI_Tool_LF_Case_Law_Analyzer'      => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-case-law-analyzer.php',
				'WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-firm-performance-dashboard.php',
				'WP_MCP_AI_Tool_LF_Matter_Analytics_Generator' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-matter-analytics-generator.php',
				'WP_MCP_AI_Tool_LF_Revenue_Forecaster'     => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-revenue-forecaster.php',
				'WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-attorney-utilization-tracker.php',
				'WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-client-satisfaction-analyzer.php',
				'WP_MCP_AI_Tool_LF_Competitive_Benchmarker' => WP_MCP_AI_PRO_PATH . 'includes/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-competitive-benchmarker.php',
			);
			$pro_tools              = array_merge( $pro_tools, $law_firm_toolkit_tools );
		}

		// Add DietPi Pro Toolkit tools if enabled (19 tools — Raspberry Pi / DietPi server and media app management).
		if ( ! empty( $settings['enable_dietpi_toolkit'] ) ) {
			$dietpi_toolkit_tools = array(
				// System tools (5).
				'WP_MCP_AI_Tool_DietPi_Send_SSH_Command'   => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-send-ssh-command.php',
				'WP_MCP_AI_Tool_DietPi_List_Services'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-list-services.php',
				'WP_MCP_AI_Tool_DietPi_Control_Service'    => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-control-service.php',
				'WP_MCP_AI_Tool_DietPi_System_Info'        => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-system-info.php',
				'WP_MCP_AI_Tool_DietPi_System_Stats'       => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-system-stats.php',
				// Transmission tools (3).
				'WP_MCP_AI_Tool_DietPi_List_Transmission'  => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-list-transmission.php',
				'WP_MCP_AI_Tool_DietPi_Add_Transmission'   => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-add-transmission.php',
				'WP_MCP_AI_Tool_DietPi_Control_Transmission' => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-control-transmission.php',
				// Jackett tools (2).
				'WP_MCP_AI_Tool_DietPi_Search_Jackett'     => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-search-jackett.php',
				'WP_MCP_AI_Tool_DietPi_List_Jackett_Indexers' => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-list-jackett-indexers.php',
				// Sonarr tools (3).
				'WP_MCP_AI_Tool_DietPi_List_Sonarr_Series' => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-list-sonarr-series.php',
				'WP_MCP_AI_Tool_DietPi_Add_Sonarr_Series'  => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-add-sonarr-series.php',
				'WP_MCP_AI_Tool_DietPi_Manage_Sonarr'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-manage-sonarr.php',
				// Radarr tools (3).
				'WP_MCP_AI_Tool_DietPi_List_Radarr_Movies' => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-list-radarr-movies.php',
				'WP_MCP_AI_Tool_DietPi_Add_Radarr_Movie'   => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-add-radarr-movie.php',
				'WP_MCP_AI_Tool_DietPi_Manage_Radarr'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-manage-radarr.php',
				// Media center, health check, cross-app workflow (3).
				'WP_MCP_AI_Tool_DietPi_Media_Center'       => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-media-center.php',
				'WP_MCP_AI_Tool_DietPi_Health_Check'       => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-health-check.php',
				'WP_MCP_AI_Tool_DietPi_Media_Request_Flow' => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-media-request-flow.php',
				// Phase 2 tools (4).
				'WP_MCP_AI_Tool_DietPi_Backup_System'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-backup-system.php',
				'WP_MCP_AI_Tool_DietPi_Update_System'      => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-update-system.php',
				'WP_MCP_AI_Tool_DietPi_Manage_Storage'     => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-manage-storage.php',
				'WP_MCP_AI_Tool_DietPi_Dashboard_Summary'  => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-dashboard-summary.php',
				// Phase 3 tool (1).
				'WP_MCP_AI_Tool_DietPi_Provision_New_App'  => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-provision-new-app.php',
			);
			$pro_tools            = array_merge( $pro_tools, $dietpi_toolkit_tools );
		}

		// Comic Creation Toolkit (12 tools).
		if ( ! empty( $settings['enable_comic_creation_toolkit'] ) ) {
			$comic_tools_base = WP_MCP_AI_PRO_PATH . 'includes/tools/comic-creation/';
			$comic_tools      = array(
				'WP_MCP_AI_Tool_Generate_Comic_Script'    => $comic_tools_base . 'class-wp-mcp-ai-tool-generate-comic-script.php',
				'WP_MCP_AI_Tool_Breakdown_Comic_Panels'   => $comic_tools_base . 'class-wp-mcp-ai-tool-breakdown-comic-panels.php',
				'WP_MCP_AI_Tool_Generate_Character_Sheet' => $comic_tools_base . 'class-wp-mcp-ai-tool-generate-character-sheet.php',
				'WP_MCP_AI_Tool_Generate_Comic_Panel'     => $comic_tools_base . 'class-wp-mcp-ai-tool-generate-comic-panel.php',
				'WP_MCP_AI_Tool_Create_Comic_Layout'      => $comic_tools_base . 'class-wp-mcp-ai-tool-create-comic-layout.php',
				'WP_MCP_AI_Tool_Add_Speech_Bubbles'       => $comic_tools_base . 'class-wp-mcp-ai-tool-add-speech-bubbles.php',
				'WP_MCP_AI_Tool_Export_Comic_Cbz'         => $comic_tools_base . 'class-wp-mcp-ai-tool-export-comic-cbz.php',
				'WP_MCP_AI_Tool_Colorize_Comic_Panel'     => $comic_tools_base . 'class-wp-mcp-ai-tool-colorize-comic-panel.php',
				'WP_MCP_AI_Tool_Ink_Comic_Panel'          => $comic_tools_base . 'class-wp-mcp-ai-tool-ink-comic-panel.php',
				'WP_MCP_AI_Tool_Letter_Comic_Panel'       => $comic_tools_base . 'class-wp-mcp-ai-tool-letter-comic-panel.php',
				'WP_MCP_AI_Tool_Upscale_Comic_Page'       => $comic_tools_base . 'class-wp-mcp-ai-tool-upscale-comic-page.php',
				'WP_MCP_AI_Tool_Apply_Comic_Style'        => $comic_tools_base . 'class-wp-mcp-ai-tool-apply-comic-style.php',
			);
			$pro_tools        = array_merge( $pro_tools, $comic_tools );
		}

		// Extended Cognition Toolkit.
		if ( ! empty( $settings['enable_extended_cognition_toolkit'] ) ) {
			$ext_cog_tools = array(
				'WP_MCP_AI_Tool_Ext_Cog_Manage_Sensor_Permissions' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-manage-sensor-permissions.php',
				'WP_MCP_AI_Tool_Ext_Cog_Capture_Visual' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-capture-visual.php',
				'WP_MCP_AI_Tool_Ext_Cog_Capture_Audio'  => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-capture-audio.php',
				'WP_MCP_AI_Tool_Ext_Cog_Capture_Screen' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-capture-screen.php',
				'WP_MCP_AI_Tool_Ext_Cog_Get_Motion_Context' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-get-motion-context.php',
				'WP_MCP_AI_Tool_Ext_Cog_Analyze_Sensory_Input' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-analyze-sensory-input.php',
				'WP_MCP_AI_Tool_Ext_Cog_Remember_Sensory_Context' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-remember-sensory-context.php',
				'WP_MCP_AI_Tool_Ext_Cog_Detect_Objects' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-detect-objects.php',
				'WP_MCP_AI_Tool_Ext_Cog_Recognize_Products' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-recognize-products.php',
				'WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed' => WP_MCP_AI_PRO_PATH . 'includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-analyze-video-feed.php',
			);
			$pro_tools     = array_merge( $pro_tools, $ext_cog_tools );
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
						$tool = new $class();

						// Legacy-format classes (pre-interface) are transparently
						// wrapped so they still register with the canonical tool
						// interface. Wrap BEFORE the first register attempt so the
						// registry's fail-loud "missing interface" log is not
						// emitted for a class that is about to register
						// successfully via the wrapper.
						if ( ! $tool instanceof WP_MCP_AI_Tool_Interface && class_exists( 'WP_MCP_AI_Legacy_Tool_Wrapper' ) ) {
							$tool = new WP_MCP_AI_Legacy_Tool_Wrapper( $tool );
						}

						$registered = $registry->register_tool( $tool );

						if ( ! $registered && method_exists( $registry, 'mark_tool_unavailable' ) ) {
							$skipped = new $class();
							if ( method_exists( $skipped, 'get_slug' ) ) {
								$registry->mark_tool_unavailable( $skipped->get_slug() );
							}
						}
					} elseif ( method_exists( $registry, 'mark_tool_unavailable' ) ) {
						// Keep the registry's "known but gated" slug list complete
						// so consumers can distinguish toolkit-gated Pro tools.
						$skipped = new $class();
						if ( method_exists( $skipped, 'get_slug' ) ) {
							$registry->mark_tool_unavailable( $skipped->get_slug() );
						}
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
			'remote_wp_connection'               => 'external-tools',
			// Printful Print-on-Demand - Requires external API access.
			'printful'                           => 'external-tools',
			// Exec service tools (video, audio, CLI) - Pro features.
			'check_wp_cli'                       => 'wordpress-core',
			'extract_video_frames'               => 'wordpress-core',
			'get_video_metadata'                 => 'wordpress-core',
			'remove_background'                  => 'wordpress-core',
			'generate_jukebox_music'             => 'external-tools',
			'check_jukebox_status'               => 'external-tools',
			// Product Actualization - Requires external APIs (OpenAI, Gemini).
			'product_actualization'              => 'external-tools',
			// Validate Image for Product Placement - Requires OpenAI Vision API.
			'validate_image_for_product'         => 'external-tools',
			// Validate Image for Vehicle Estimate - Requires OpenAI Vision API.
			'validate_image_for_vehicle'         => 'external-tools',
			// Product Price Lookup - Requires external APIs (Crawl4AI, Google Vision).
			'lookup_product_price'               => 'external-tools',
			// Listing image download tools - Require external API credentials.
			'download_google_maps_images'        => 'external-tools',
			'download_facebook_page_images'      => 'external-tools',
			'download_instagram_page_images'     => 'external-tools',
			// WooCommerce tools - Require WooCommerce plugin.
			'woo_products'                       => 'wordpress-plugins',
			'woo_orders'                         => 'wordpress-plugins',
			'woo_customers'                      => 'wordpress-plugins',
			'woo_coupons'                        => 'wordpress-plugins',
			// JetEngine tools - Require JetEngine plugin.
			'jetengine'                          => 'wordpress-plugins',
			'jetengine_mcp'                      => 'wordpress-plugins',
			'jetengine_create_post_type'         => 'wordpress-plugins',
			'jetengine_create_taxonomy'          => 'wordpress-plugins',
			'jetengine_create_meta_field'        => 'wordpress-plugins',
			'jetengine_manage_relations'         => 'wordpress-plugins',
			'jetengine_site_context'             => 'wordpress-plugins',
			'jetengine_prompts'                  => 'wordpress-plugins',
			// Toolkit CPT - generic CRUD/search for pro toolkit Custom Post Types.
			'toolkit_cpt'                        => 'wordpress-core',
			// Elementor tools - Require Elementor plugin.
			'elementor'                          => 'wordpress-plugins',
			// Social media publishing tools - Require external API credentials.
			'post_facebook_instagram'            => 'external-tools',
			'post_tiktok_video'                  => 'external-tools',
			'post_linkedin_update'               => 'external-tools',
			'post_google_business_update'        => 'external-tools',
			// Social media insights/reporting tools - Require external API credentials.
			'get_facebook_instagram_insights'    => 'external-tools',
			'get_tiktok_insights'                => 'external-tools',
			'get_linkedin_insights'              => 'external-tools',
			'get_google_business_insights'       => 'external-tools',
			// Messaging tools - Require external API credentials.
			'send_whatsapp_message'              => 'external-tools',
			'send_telegram_message'              => 'external-tools',
			// Chat channel tools - Require external API credentials.
			'send_slack_message'                 => 'external-tools',
			'get_slack_channels'                 => 'external-tools',
			'get_slack_messages'                 => 'external-tools',
			'create_slack_channel'               => 'external-tools',
			'send_discord_message'               => 'external-tools',
			'get_discord_channels'               => 'external-tools',
			'get_discord_messages'               => 'external-tools',
			'create_discord_channel'             => 'external-tools',
			'send_teams_message'                 => 'external-tools',
			'get_teams_channels'                 => 'external-tools',
			'get_teams_messages'                 => 'external-tools',
			'send_messenger_message'             => 'external-tools',
			'get_messenger_conversations'        => 'external-tools',
			'create_messenger_broadcast'         => 'external-tools',
			// Google Chat tools - Require external API credentials.
			'send_google_chat_message'           => 'external-tools',
			'get_google_chat_spaces'             => 'external-tools',
			'get_google_chat_messages'           => 'external-tools',
			'create_google_chat_space'           => 'external-tools',
			// Enhanced Telegram tools - Require external API credentials.
			'get_telegram_updates'               => 'external-tools',
			'manage_telegram_webhook'            => 'external-tools',
			// Enhanced WhatsApp tools - Require external API credentials.
			'send_whatsapp_template'             => 'external-tools',
			'get_whatsapp_messages'              => 'external-tools',
			// Unified broadcast tool - Requires external API credentials.
			'unified_channel_broadcast'          => 'external-tools',
			// Email and communication tools - Require external API credentials.
			'search_gmail'                       => 'external-tools',
			'get_gmail_message'                  => 'external-tools',
			'get_gmail_thread'                   => 'external-tools',
			'list_gmail_connections'             => 'external-tools',
			'modify_gmail_message'               => 'external-tools',
			'send_mailjet_email'                 => 'external-tools',
			// Brevo email marketing and CRM tools - Require Brevo API key.
			'send_brevo_email'                   => 'external-tools',
			'manage_brevo_contacts'              => 'external-tools',
			'get_brevo_statistics'               => 'external-tools',
			// Mailgun email delivery tools - Require Mailgun API key and domain.
			'send_mailgun_email'                 => 'external-tools',
			// Google Workspace tools - Require external API credentials.
			'create_google_calendar_event'       => 'external-tools',
			'list_google_calendars'              => 'external-tools',
			'list_google_calendar_events'        => 'external-tools',
			'update_google_calendar_event'       => 'external-tools',
			'delete_google_calendar_event'       => 'external-tools',
			'check_google_calendar_availability' => 'external-tools',
			'quick_add_google_calendar_event'    => 'external-tools',
			'search_drive'                       => 'external-tools',
			'get_drive_file'                     => 'external-tools',
			'list_drive_connections'             => 'external-tools',
			'google_analytics_report'            => 'external-tools',
			// Business and accounting tools - Require external API credentials.
			'quickbooks_report'                  => 'external-tools',
			'quickbooks_desktop_sync'            => 'external-tools',
			// iSAMS School Management System - Requires external API credentials.
			'isams_query'                        => 'external-tools',
			// Shopify e-commerce tools - Require a configured Shopify Remote Sites connection.
			'remote_shopify_connection'          => 'external-tools',
			'shopify_products'                   => 'external-tools',
			'shopify_orders'                     => 'external-tools',
			'shopify_customers'                  => 'external-tools',
			'shopify_inventory'                  => 'external-tools',
			'shopify_catalog'                    => 'external-tools',
			// Site Creator and related tools.
			'site_creator'                       => 'wordpress-core',
			'install_and_activate_plugin'        => 'wordpress-core',
			'install_and_activate_theme'         => 'wordpress-core',
			'update_option'                      => 'wordpress-core',
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

		// Add CRM Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_crm_toolkit'] ) ) {
			// CRM core CRUD tools.
			$pro_tools['manage_crm_contact'] = 'wordpress-core';
			$pro_tools['create_company']     = 'wordpress-core';
			$pro_tools['get_companies']      = 'wordpress-core';
			$pro_tools['research_company']   = 'wordpress-core';
			// CRM Email Search tools.
			$pro_tools['crm_email_search_leads']          = 'wordpress-core';
			$pro_tools['crm_email_search_correspondence'] = 'wordpress-core';
			$pro_tools['crm_email_search_accounting']     = 'wordpress-core';
			// Upwork CRM tools.
			$pro_tools['search_upwork_jobs']    = 'wordpress-core';
			$pro_tools['score_upwork_job']      = 'wordpress-core';
			$pro_tools['draft_upwork_proposal'] = 'wordpress-core';

			// ICP (Ideal Customer Profile) tools (v2.11.0).
			$pro_tools['compute_icp_score']  = 'wordpress-core';
			$pro_tools['manage_icp_profile'] = 'wordpress-core';

			// Customer CRUD tools (v2.6.0).
			$pro_tools['create_customer'] = 'wordpress-core';
			$pro_tools['get_customer']    = 'wordpress-core';
			$pro_tools['update_customer'] = 'wordpress-core';
			$pro_tools['delete_customer'] = 'wordpress-core';
			$pro_tools['list_customers']  = 'wordpress-core';

			// CRM data hygiene & engagement tools (v2.9.0).
			$pro_tools['get_contact_interactions']      = 'wordpress-core';
			$pro_tools['archive_stale_contacts']        = 'wordpress-core';
			$pro_tools['recalculate_engagement_scores'] = 'wordpress-core';
			$pro_tools['scan_duplicate_contacts']       = 'wordpress-core';
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
			$pro_tools['research_eca']             = 'wordpress-core';
			// Attendance & participation tools.
			$pro_tools['mark_eca_attendance']               = 'wordpress-core';
			$pro_tools['get_eca_attendance_report']         = 'wordpress-core';
			$pro_tools['get_student_participation_summary'] = 'wordpress-core';
			// Waitlist & enrollment automation.
			$pro_tools['manage_eca_waitlist']  = 'wordpress-core';
			$pro_tools['withdraw_student_eca'] = 'wordpress-core';
			$pro_tools['bulk_enroll_students'] = 'wordpress-core';
			// Scheduling & conflict detection.
			$pro_tools['check_eca_conflicts'] = 'wordpress-core';
			$pro_tools['set_eca_schedule']    = 'wordpress-core';
			$pro_tools['get_eca_timetable']   = 'wordpress-core';
			// Notifications & communication.
			$pro_tools['send_eca_notification']       = 'wordpress-core';
			$pro_tools['configure_eca_notifications'] = 'wordpress-core';
			$pro_tools['send_eca_parent_report']      = 'wordpress-core';
			// Reporting & analytics.
			$pro_tools['generate_eca_analytics']            = 'wordpress-core';
			$pro_tools['generate_eca_participation_report'] = 'wordpress-core';
			$pro_tools['export_eca_data']                   = 'wordpress-core';
			// Integration tools.
			$pro_tools['sync_eca_enrollments_from_isams'] = 'wordpress-core';
			$pro_tools['sync_ecas_to_isams']              = 'wordpress-core';
			$pro_tools['sync_ecas_from_socs']             = 'wordpress-core';
			// Workflow & lifecycle.
			$pro_tools['manage_eca_term']          = 'wordpress-core';
			$pro_tools['create_eca_workflow_rule'] = 'wordpress-core';
			$pro_tools['import_ecas_csv']          = 'wordpress-core';
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
			$pro_tools['research_policy']           = 'wordpress-core';
			// Chart.js data visualization tool.
			$pro_tools['generate_health_chart'] = 'wordpress-core';
			// Industry Standards-Based Health Management Tools (FHIR, HIPAA, PHR).
			$pro_tools['create_health_reminder'] = 'wordpress-core';
			$pro_tools['track_vaccinations']     = 'wordpress-core';
			$pro_tools['log_health_metrics']     = 'wordpress-core';
			$pro_tools['log_vital_signs']        = 'wordpress-core';
			$pro_tools['import_vitals']          = 'wordpress-core';
			$pro_tools['export_fhir_data']       = 'wordpress-core';
			$pro_tools['manage_care_plan']       = 'wordpress-core';
			// Health Research: compile data from CCT, options, files, and vector store.
			$pro_tools['compile_health_research_data'] = 'wordpress-core';
			// AI-Assisted Data Entry (agentic flow tools for guided CPT population).
			$pro_tools['guide_health_record_creation'] = 'wordpress-core';
			$pro_tools['parse_health_information']     = 'wordpress-core';

			// Phase C — Health & Wellness breadth.
			$pro_tools['check_member_allergies']           = 'wordpress-core';
			$pro_tools['get_health_timeline']              = 'wordpress-core';
			$pro_tools['link_prescription_to_record']      = 'wordpress-core';
			$pro_tools['verify_prescription_interactions'] = 'wordpress-core';
			$pro_tools['generate_visit_summary']           = 'wordpress-core';
			$pro_tools['merge_duplicate_members']          = 'wordpress-core';
			// Appointment query & follow-up tools (v2.9.0).
			$pro_tools['get_recent_health_appointments'] = 'wordpress-core';
			$pro_tools['send_appointment_followup']      = 'wordpress-core';
		}

		// Add Medical Vitals (Phase B) tool mappings if enabled.
		$vitals_mappings_enabled = array_key_exists( 'enable_medical_vitals', $settings )
			? ! empty( $settings['enable_medical_vitals'] )
			: ! empty( $settings['enable_health_wellness_management'] );
		if ( $vitals_mappings_enabled ) {
			$pro_tools['flag_abnormal_vitals']              = 'wordpress-core';
			$pro_tools['analyze_vital_trends']              = 'wordpress-core';
			$pro_tools['compute_bmi_and_growth_percentile'] = 'wordpress-core';
			$pro_tools['get_vaccination_schedule']          = 'wordpress-core';
		}

		// Add Healthcare Imaging tool mappings if enabled.
		if ( ! empty( $settings['enable_healthcare_imaging'] ) ) {
			$pro_tools['manage_imaging_studies']       = 'wordpress-core';
			$pro_tools['interpret_imaging_study']      = 'wordpress-core';
			$pro_tools['connect_dicomweb']             = 'external-tools';
			$pro_tools['import_dicom_study']           = 'external-tools';
			$pro_tools['export_dicom_study']           = 'external-tools';
			$pro_tools['attach_radiology_report']      = 'wordpress-core';
			$pro_tools['compare_imaging_studies']      = 'wordpress-core';
			$pro_tools['get_imaging_hanging_protocol'] = 'wordpress-core';
		}

		// Add Healthcare Interoperability tool mappings (Phase E) if H&W is enabled.
		if ( ! empty( $settings['enable_health_wellness_management'] ) ) {
			$pro_tools['import_fhir_bundle']   = 'wordpress-core';
			$pro_tools['export_ccda_document'] = 'wordpress-core';
			$pro_tools['import_hl7v2_message'] = 'wordpress-core';
			$pro_tools['connect_to_ehr']       = 'external-tools';
		}

		// Vehicle Estimation tool mappings — always available.
		$pro_tools['vin_decode']                = 'external-tools';
		$pro_tools['vehicle_repair_estimate']   = 'external-tools';
		$pro_tools['vehicle_cleaning_estimate'] = 'external-tools';

		// Add Document Generation Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_document_generation_toolkit'] ) ) {
			$pro_tools['pro_pdf_document']   = 'external-tools';
			$pro_tools['pro_word_document']  = 'external-tools';
			$pro_tools['pro_excel_document'] = 'external-tools';
			// Document audit & batch tools (v2.9.0).
			$pro_tools['get_expired_documents']  = 'wordpress-core';
			$pro_tools['get_uninvoiced_orders']  = 'wordpress-core';
			$pro_tools['archive_documents']      = 'wordpress-core';
			$pro_tools['generate_invoice_batch'] = 'wordpress-core';
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

		// Add Media Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_media_toolkit'] ) ) {
			$pro_tools['scan_orphaned_media']    = 'wordpress-core';
			$pro_tools['cleanup_orphaned_media'] = 'wordpress-core';
		}

		// Add Image Production Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_image_production_toolkit'] ) ) {
			$pro_tools['get_images_without_alt']   = 'wordpress-core';
			$pro_tools['get_unoptimised_images']   = 'wordpress-core';
			$pro_tools['get_unwatermarked_images'] = 'wordpress-core';
			$pro_tools['apply_watermark_batch']    = 'wordpress-core';
			$pro_tools['optimise_images_batch']    = 'wordpress-core';
		}

		// Add Social Media Toolkit tool mappings if enabled (Phase 2.8).
		if ( ! empty( $settings['enable_social_media_toolkit'] ) ) {
			$pro_tools['get_content_calendar']     = 'wordpress-core';
			$pro_tools['generate_social_captions'] = 'wordpress-core';
			$pro_tools['schedule_social_posts']    = 'wordpress-core';
			$pro_tools['publish_to_social']        = 'wordpress-core';
		}

		// Add Video Production Toolkit tool mappings if enabled (Phase 2.8).
		if ( ! empty( $settings['enable_video_production_toolkit'] ) ) {
			$pro_tools['get_queued_videos']              = 'wordpress-core';
			$pro_tools['get_videos_without_thumbnails']  = 'wordpress-core';
			$pro_tools['get_videos_without_transcripts'] = 'wordpress-core';
			$pro_tools['upload_video_batch']             = 'wordpress-core';
			$pro_tools['transcribe_video']               = 'wordpress-core';
		}

		// Add DJ Management Toolkit tool mappings if enabled (Phase 2.7).
		if ( ! empty( $settings['enable_dj_management_toolkit'] ) ) {
			$pro_tools['get_trending_tracks']      = 'wordpress-core';
			$pro_tools['update_playlist_rotation'] = 'wordpress-core';
		}

		// Add Calendar Booking Toolkit tool mappings if enabled.
		if ( ! empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			$pro_tools['get_no_show_appointments']   = 'wordpress-core';
			$pro_tools['get_unconfirmed_bookings']   = 'wordpress-core';
			$pro_tools['send_booking_confirmations'] = 'wordpress-core';
			$pro_tools['send_reschedule_invitation'] = 'wordpress-core';
			$pro_tools['create_service']             = 'wordpress-core';
			$pro_tools['import_services']            = 'wordpress-core';
		}

		// Pro Schedule Manager tools — always available (no toolkit gate).
		$pro_tools['create_pro_schedule']                = 'wordpress-core';
		$pro_tools['update_pro_schedule']                = 'wordpress-core';
		$pro_tools['delete_pro_schedule']                = 'wordpress-core';
		$pro_tools['list_pro_schedules']                 = 'wordpress-core';
		$pro_tools['get_schedule_run_history']           = 'wordpress-core';
		$pro_tools['schedule_channel_broadcast']         = 'wordpress-core';
		$pro_tools['plan_schedules_from_workflow']       = 'wordpress-core';
		$pro_tools['get_schedule_latest_result']         = 'wordpress-core';
		$pro_tools['render_schedule_result']             = 'wordpress-core';
		$pro_tools['configure_schedule_widget_defaults'] = 'wordpress-core';

		/**
		 * Filter the Pro tool group assignments.
		 *
		 * @since 1.0.0
		 *
		 * @param array $pro_tools Associative array of Pro tool slugs to group identifiers.
		 */
		$pro_tools = apply_filters( 'wp_mcp_ai_pro_tool_groups', $pro_tools );

		// ── DietPi Toolkit (19 tools) ──
		$pro_tools['dietpi_send_ssh_command']      = 'system';
		$pro_tools['dietpi_list_services']         = 'system';
		$pro_tools['dietpi_control_service']       = 'system';
		$pro_tools['dietpi_system_info']           = 'system';
		$pro_tools['dietpi_system_stats']          = 'system';
		$pro_tools['dietpi_list_transmission']     = 'dietpi-apps';
		$pro_tools['dietpi_add_transmission']      = 'dietpi-apps';
		$pro_tools['dietpi_control_transmission']  = 'dietpi-apps';
		$pro_tools['dietpi_search_jackett']        = 'dietpi-apps';
		$pro_tools['dietpi_list_jackett_indexers'] = 'dietpi-apps';
		$pro_tools['dietpi_list_sonarr_series']    = 'dietpi-apps';
		$pro_tools['dietpi_add_sonarr_series']     = 'dietpi-apps';
		$pro_tools['dietpi_manage_sonarr']         = 'dietpi-apps';
		$pro_tools['dietpi_list_radarr_movies']    = 'dietpi-apps';
		$pro_tools['dietpi_add_radarr_movie']      = 'dietpi-apps';
		$pro_tools['dietpi_manage_radarr']         = 'dietpi-apps';
		$pro_tools['dietpi_media_center']          = 'dietpi-apps';
		$pro_tools['dietpi_health_check']          = 'dietpi-apps';
		$pro_tools['dietpi_media_request_flow']    = 'dietpi-apps';
		$pro_tools['dietpi_backup_system']         = 'system';
		$pro_tools['dietpi_update_system']         = 'system';
		$pro_tools['dietpi_manage_storage']        = 'system';
		$pro_tools['dietpi_dashboard_summary']     = 'system';
		$pro_tools['dietpi_provision_new_app']     = 'system';

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
			$categories['medium_resource']['tools'][] = 'jetengine_mcp';
			$categories['medium_resource']['tools'][] = 'jetengine_create_post_type';
			$categories['medium_resource']['tools'][] = 'jetengine_create_taxonomy';
			$categories['medium_resource']['tools'][] = 'jetengine_create_meta_field';
			$categories['medium_resource']['tools'][] = 'jetengine_manage_relations';
			$categories['medium_resource']['tools'][] = 'jetengine_site_context';
			$categories['medium_resource']['tools'][] = 'jetengine_prompts';
			$categories['medium_resource']['tools'][] = 'elementor';
		}

		// Product Actualization and Price Lookup - high resource (uses AI vision and web scraping).
		if ( isset( $categories['high_resource'] ) ) {
			$categories['high_resource']['tools'][] = 'product_actualization';
			$categories['high_resource']['tools'][] = 'lookup_product_price';
		}

		// Listing image download tools - medium resource (external API + file downloads).
		if ( isset( $categories['medium_resource'] ) ) {
			$categories['medium_resource']['tools'][] = 'download_google_maps_images';
			$categories['medium_resource']['tools'][] = 'download_facebook_page_images';
			$categories['medium_resource']['tools'][] = 'download_instagram_page_images';
		}

		// QuickBooks Desktop sync - medium resource (external relay API).
		if ( isset( $categories['medium_resource'] ) ) {
			$categories['medium_resource']['tools'][] = 'quickbooks_desktop_sync';
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
 * Register WP-CLI commands for the Pro addon.
 *
 * Loads and registers all Pro gap-fill CLI command classes when WP-CLI is active.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$wp_mcp_ai_pro_cli_dir = WP_MCP_AI_PRO_PATH . 'includes/cli/';

	$wp_mcp_ai_pro_cli_files = array(
		'class-wp-mcp-ai-pro-cli-status-command.php',
		'class-wp-mcp-ai-pro-cli-toolkit-command.php',
		'class-wp-mcp-ai-pro-cli-connection-command.php',
		'class-wp-mcp-ai-pro-cli-project-command.php',
		'class-wp-mcp-ai-pro-cli-task-command.php',
		'class-wp-mcp-ai-pro-cli-mcp-server-command.php',
		'class-wp-mcp-ai-pro-cli-place-command.php',
		'class-wp-mcp-ai-pro-cli-calendar-command.php',
		'class-wp-mcp-ai-pro-cli-composition-command.php',
	);

	foreach ( $wp_mcp_ai_pro_cli_files as $wp_mcp_ai_pro_cli_file ) {
		$wp_mcp_ai_pro_cli_path = $wp_mcp_ai_pro_cli_dir . $wp_mcp_ai_pro_cli_file;
		if ( file_exists( $wp_mcp_ai_pro_cli_path ) ) {
			require_once $wp_mcp_ai_pro_cli_path;
		}
	}

	unset( $wp_mcp_ai_pro_cli_dir, $wp_mcp_ai_pro_cli_files, $wp_mcp_ai_pro_cli_file, $wp_mcp_ai_pro_cli_path );
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
		WP_MCP_AI_Media_Template_Presets::seed_collections();
	}

	// Schedule installation of Pro bundled skills (Google Workspace CLI skills, etc.).
	set_transient( 'wp_mcp_ai_pro_install_bundled_skills', true, HOUR_IN_SECONDS );

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

/**
 * Install Pro bundled skills on init if the activation transient is set.
 *
 * Copies pre-packaged SKILL.md files from the Pro addon's bundled-skills directory
 * (Google Workspace CLI skills and other Pro-exclusive skills) to the uploads skill
 * storage. Already-installed skills are skipped.
 *
 * @since 1.7.2
 */
add_action(
	'init',
	function () {
		if ( ! get_transient( 'wp_mcp_ai_pro_install_bundled_skills' ) ) {
			return;
		}

		delete_transient( 'wp_mcp_ai_pro_install_bundled_skills' );

		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			return;
		}

		$pro_bundled_dir = defined( 'WP_MCP_AI_PRO_PATH' )
			? trailingslashit( WP_MCP_AI_PRO_PATH ) . 'includes/bundled-skills'
			: '';

		if ( empty( $pro_bundled_dir ) || ! is_dir( $pro_bundled_dir ) ) {
			return;
		}

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_bundled_skills_from_dir( $pro_bundled_dir );

		// Log any errors for debugging.
		if ( ! empty( $result['errors'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Development debugging only when WP_DEBUG is enabled.
			error_log( 'WP_MCP_AI Pro: Bundled skills install errors: ' . implode( '; ', $result['errors'] ) );
		}
	},
	100
);

// Load Media Worker Sidecar Settings Page (eager � registers admin_menu hook).
$media_worker_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-worker-settings.php';
if ( file_exists( $media_worker_page ) ) {
	require_once $media_worker_page;
}
