<?php
/**
 * Token Usage Service for NV oOS.
 *
 * Provides centralized token usage management and statistics extracted from the admin layer:
 * - User token usage tracking and totals
 * - Tool-specific token limits and multipliers
 * - Site-wide token statistics
 * - Provider and model usage aggregation
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Usage Service class.
 *
 * This service provides business logic for token usage calculations and statistics,
 * separated from the admin UI presentation layer.
 */
class WP_MCP_AI_Token_Usage_Service {

	/**
	 * Calculate total usage from usage array.
	 *
	 * @param array $usage Usage data.
	 * @return array Totals.
	 */
	public static function calculate_usage_totals( $usage ) {
		$totals = array(
			'requests'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'cached_tokens'     => 0,
			'total_cost'        => 0.0,
		);

		if ( ! is_array( $usage ) ) {
			return $totals;
		}

		foreach ( $usage as $provider => $models ) {
			if ( ! is_array( $models ) ) {
				continue;
			}

			foreach ( $models as $model => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
				$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;

				$totals['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
				$totals['prompt_tokens']     += $prompt_tokens;
				$totals['completion_tokens'] += $completion_tokens;
				$totals['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
				$totals['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;

				// Calculate cost for this model.
				if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
					$totals['total_cost'] += WP_MCP_AI_Usage_Tracker::calculate_cost(
						$provider,
						$model,
						$prompt_tokens,
						$completion_tokens
					);
				}
			}
		}

		return $totals;
	}

	/**
	 * Get all available tools.
	 *
	 * @return array Tool slug => Tool name pairs.
	 */
	public static function get_all_available_tools() {
		$tools = array();

		// Get all registered tools from the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( ! $registry ) {
			// Fallback to hardcoded tools if registry is not available.
			$tools = array(
				'run_crawl4ai_job' => __( 'Crawl4AI Web Scraper', 'mcp-ai-wpoos' ),
				'general_tools'    => __( 'General Tools (Default)', 'mcp-ai-wpoos' ),
			);
		} else {
			// Ensure registry is initialized.
			$registry->init();

			$registered_tools = $registry->get_tools();

			// Build array of tool slug => name pairs from registered tools.
			foreach ( $registered_tools as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
					$slug = $tool->get_slug();
					$name = $tool->get_name();

					if ( ! empty( $slug ) && ! empty( $name ) ) {
						$tools[ $slug ] = $name;
					}
				}
			}

			// Also load unregistered tools from Pro addon if available.
			// This ensures tools that are conditionally registered (e.g., based on settings)
			// still appear in admin pages for configuration purposes.
			$unregistered_tools = self::get_unregistered_tools();
			foreach ( $unregistered_tools as $slug => $name ) {
				// Only add if not already in the list.
				if ( ! isset( $tools[ $slug ] ) ) {
					$tools[ $slug ] = $name;
				}
			}

			// Sort tools by name for better UI experience.
			asort( $tools );
		}

		/**
		 * Filter available tools for token limit configuration.
		 *
		 * @param array $tools Tool slug => Tool name pairs.
		 */
		return apply_filters( 'wp_mcp_ai_token_manager_tools', $tools );
	}

	/**
	 * Get unregistered tools from Pro addon.
	 *
	 * This method loads tool definitions from the Pro addon that may not be
	 * currently registered due to missing configuration or disabled features.
	 * This allows admin pages to display all tools for configuration purposes.
	 *
	 * @since 1.0.0
	 * @return array Tool slug => Tool name pairs for unregistered tools.
	 */
	private static function get_unregistered_tools() {
		$unregistered_tools = array();

		// Check if Pro addon is available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return $unregistered_tools;
		}

		// Get Pro tool definitions using the same logic as wp_mcp_ai_pro_register_tools().
		$pro_tools = array(
			// Remote WordPress/WooCommerce Connection tool.
			'WP_MCP_AI_Tool_Remote_WP_Connection'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php',
			// Generic REST API Connection tool.
			'WP_MCP_AI_Tool_Generic_REST_API'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generic-rest-api.php',
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
			'WP_MCP_AI_Pro_Tool_Woo_Customers'            => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-customers.php',
			'WP_MCP_AI_Pro_Tool_Woo_Coupons'              => WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-coupons.php',
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
			// WP All Import/Export Pro tools.
			'WP_MCP_AI_Pro_Tool_Schedule_All_Export'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-export.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Export'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-export.php',
			'WP_MCP_AI_Pro_Tool_Schedule_All_Import'      => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-import.php',
			'WP_MCP_AI_Pro_Tool_Delete_All_Import'        => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-import.php',
			// iSAMS School Management System tool.
			'WP_MCP_AI_Tool_ISAMS_Query'                  => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-isams-query.php',
		);

		// Add quiz tools if enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_quiz_system'] ) ) {
			$quiz_tools = array(
				'WP_MCP_AI_Tool_Create_Quiz'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz.php',
				'WP_MCP_AI_Tool_List_Quizzes'         => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-quizzes.php',
				'WP_MCP_AI_Tool_Submit_Quiz_Answer'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-quiz-answer.php',
				'WP_MCP_AI_Tool_Grade_Quiz'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-grade-quiz.php',
				'WP_MCP_AI_Tool_Get_Quiz_Submissions' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz-submissions.php',
				'WP_MCP_AI_Tool_Get_Quiz_Results'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quiz-results.php',
			);
			$pro_tools = array_merge( $pro_tools, $quiz_tools );
		}

		// Add places management tools if enabled.
		if ( ! empty( $settings['enable_places_management'] ) ) {
			$places_tools = array(
				'WP_MCP_AI_Tool_Create_Place'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-place.php',
				'WP_MCP_AI_Tool_List_Places'           => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-places.php',
				'WP_MCP_AI_Tool_Update_Place'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-place.php',
				'WP_MCP_AI_Tool_Delete_Place'          => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-place.php',
				'WP_MCP_AI_Tool_Get_Place'             => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-place.php',
				'WP_MCP_AI_Tool_Search_And_Save_Places' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-and-save-places.php',
			);
			$pro_tools = array_merge( $pro_tools, $places_tools );
		}

		// Apply the same filter as in wp_mcp_ai_pro_register_tools().
		$pro_tools = apply_filters( 'wp_mcp_ai_pro_tools', $pro_tools );

		// Load each tool class and get its metadata.
		foreach ( $pro_tools as $class => $file ) {
			// Skip if the file doesn't exist.
			if ( ! file_exists( $file ) ) {
				continue;
			}

			// Skip if already loaded (avoids re-requiring).
			if ( class_exists( $class ) ) {
				try {
					// Instantiate the tool to get its metadata.
					$tool_instance = new $class();

					if ( $tool_instance instanceof WP_MCP_AI_Tool_Interface ) {
						$slug = $tool_instance->get_slug();
						$name = $tool_instance->get_name();

						if ( ! empty( $slug ) && ! empty( $name ) ) {
							$unregistered_tools[ $slug ] = $name;
						}
					}
				} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Silently skip tools that can't be instantiated due to missing dependencies.
					// This is expected for tools that require plugins like WooCommerce, JetEngine, etc.
				}
				continue;
			}

			// Try to load the file, but be prepared for missing dependencies.
			// Use output buffering to suppress any errors/warnings during file load.
			ob_start();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Necessary to handle missing tool dependencies gracefully.
			$loaded = @include_once $file;
			ob_end_clean();

			// Check if class now exists after loading.
			if ( class_exists( $class ) ) {
				try {
					// Instantiate the tool to get its metadata.
					$tool_instance = new $class();

					if ( $tool_instance instanceof WP_MCP_AI_Tool_Interface ) {
						$slug = $tool_instance->get_slug();
						$name = $tool_instance->get_name();

						if ( ! empty( $slug ) && ! empty( $name ) ) {
							$unregistered_tools[ $slug ] = $name;
						}
					}
				} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Silently skip tools that can't be instantiated due to missing dependencies.
					// This is expected for tools that require plugins like WooCommerce, JetEngine, etc.
				}
			}
		}

		return $unregistered_tools;
	}

	/**
	 * Get site-wide statistics.
	 *
	 * @return array Site statistics.
	 */
	public static function get_site_wide_statistics() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			return array(
				'total_users'    => 0,
				'total_requests' => 0,
				'total_tokens'   => 0,
				'total_cost'     => 0.0,
				'by_provider'    => array(),
				'top_models'     => array(),
				'top_tools'      => array(),
				'tools_used'     => 0,
			);
		}

		$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;

		// Get all user IDs with usage data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		$stats = array(
			'total_users'    => count( $user_ids ),
			'total_requests' => 0,
			'total_tokens'   => 0,
			'total_cost'     => 0.0,
			'by_provider'    => array(),
			'top_models'     => array(),
			'top_tools'      => array(),
			'tools_used'     => 0,
		);

		$all_models = array();
		$all_tools  = array();

		foreach ( $user_ids as $user_id ) {
			$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

			foreach ( $usage as $provider => $models ) {
				if ( ! isset( $stats['by_provider'][ $provider ] ) ) {
					$stats['by_provider'][ $provider ] = array(
						'requests'          => 0,
						'prompt_tokens'     => 0,
						'completion_tokens' => 0,
						'total_tokens'      => 0,
						'cached_tokens'     => 0,
						'total_cost'        => 0.0,
					);
				}

				foreach ( $models as $model => $data ) {
					$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
					$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
					$cost              = WP_MCP_AI_Usage_Tracker::calculate_cost( $provider, $model, $prompt_tokens, $completion_tokens );

					$stats['total_requests'] += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['total_tokens']   += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$stats['total_cost']     += $cost;

					$stats['by_provider'][ $provider ]['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['by_provider'][ $provider ]['prompt_tokens']     += $prompt_tokens;
					$stats['by_provider'][ $provider ]['completion_tokens'] += $completion_tokens;
					$stats['by_provider'][ $provider ]['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$stats['by_provider'][ $provider ]['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;
					$stats['by_provider'][ $provider ]['total_cost']        += $cost;

					$model_key = $provider . '|' . $model;
					if ( ! isset( $all_models[ $model_key ] ) ) {
						$all_models[ $model_key ] = array(
							'provider'     => $provider,
							'model'        => $model,
							'requests'     => 0,
							'total_tokens' => 0,
							'total_cost'   => 0.0,
						);
					}

					$all_models[ $model_key ]['requests']     += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$all_models[ $model_key ]['total_tokens'] += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$all_models[ $model_key ]['total_cost']   += $cost;
				}
			}
		}

		// Sort models by total tokens and get top 10.
		uasort(
			$all_models,
			function ( $a, $b ) {
				// Use spaceship operator for safe comparison (PHP 7+).
				return $b['total_tokens'] <=> $a['total_tokens'];
			}
		);

		$stats['top_models'] = array_slice( $all_models, 0, 10 );

		// Collect tool usage statistics.
		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			$tool_meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tool_users = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$tool_meta_key
				)
			);

			$tools_set       = array();
			$available_tools = self::get_all_available_tools();

			foreach ( $tool_users as $user_id ) {
				$tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

				foreach ( $tool_usage as $tool_slug => $tool_data ) {
					$tools_set[ $tool_slug ] = true;

					// Initialize tool stats if not exists.
					if ( ! isset( $all_tools[ $tool_slug ] ) ) {
						$all_tools[ $tool_slug ] = array(
							'tool_slug'    => $tool_slug,
							'tool_name'    => isset( $available_tools[ $tool_slug ] ) ? $available_tools[ $tool_slug ] : ucwords( str_replace( '_', ' ', $tool_slug ) ),
							'users'        => array(), // Track unique users as associative array (will be converted to count for output).
							'requests'     => 0,
							'total_tokens' => 0,
						);
					}

					// Track unique user for this tool (O(1) lookup using associative array).
					$all_tools[ $tool_slug ]['users'][ $user_id ] = true;

					// Add requests.
					if ( isset( $tool_data['requests'] ) ) {
						$all_tools[ $tool_slug ]['requests'] += (int) $tool_data['requests'];
					}

					// Add total tokens.
					if ( isset( $tool_data['total_tokens'] ) ) {
						$all_tools[ $tool_slug ]['total_tokens'] += (int) $tool_data['total_tokens'];
					}
				}
			}

			$stats['tools_used'] = count( $tools_set );

			// Prepare tools for output by converting user tracking to counts.
			$prepared_tools = array();
			foreach ( $all_tools as $tool_slug => $tool_data ) {
				$prepared_tools[ $tool_slug ] = array(
					'tool_slug'    => $tool_data['tool_slug'],
					'tool_name'    => $tool_data['tool_name'],
					'total_users'  => count( $tool_data['users'] ),
					'requests'     => $tool_data['requests'],
					'total_tokens' => $tool_data['total_tokens'],
				);
			}

			// Sort tools by total tokens and get top 10.
			uasort(
				$prepared_tools,
				function ( $a, $b ) {
					// Use spaceship operator for safe comparison (PHP 7+).
					return $b['total_tokens'] <=> $a['total_tokens'];
				}
			);

			$stats['top_tools'] = array_slice( $prepared_tools, 0, 10 );
		}

		return $stats;
	}

	/**
	 * Get formatted provider display name.
	 *
	 * @param string $provider Provider key.
	 * @return string Formatted provider name.
	 */
	public static function get_provider_display_name( $provider ) {
		$provider = sanitize_key( $provider );

		$provider_labels = array(
			'openai'    => __( 'OpenAI', 'mcp-ai-wpoos' ),
			'anthropic' => __( 'Anthropic (Claude)', 'mcp-ai-wpoos' ),
			'gemini'    => __( 'Gemini', 'mcp-ai-wpoos' ),
			'ollama'    => __( 'Ollama (Local AI)', 'mcp-ai-wpoos' ),
			'lm_studio' => __( 'LM Studio (Local AI)', 'mcp-ai-wpoos' ),
		);

		if ( isset( $provider_labels[ $provider ] ) ) {
			return $provider_labels[ $provider ];
		}

		// Fallback: capitalize and replace underscores/hyphens with spaces.
		return ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );
	}

	/**
	 * Get tool multiplier for token limits.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return float Multiplier value.
	 */
	public static function get_tool_multiplier( $tool_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return 1.0;
		}

		// Get multipliers from the WP_MCP_AI_Tool_Token_Limits class.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

		if ( isset( $multipliers[ $tool_slug ] ) ) {
			return (float) $multipliers[ $tool_slug ];
		}

		return 1.0; // Default multiplier.
	}
}
