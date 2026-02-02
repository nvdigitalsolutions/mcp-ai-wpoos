<?php
/**
 * Tool Token Limit Recommendations
 *
 * Provides intelligent recommendations for tool token limits and model preferences
 * based on tool characteristics and resource requirements.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Recommendations Class
 *
 * Analyzes tools and provides recommended settings for token multipliers
 * and model preferences based on tool type and complexity.
 */
class WP_MCP_AI_Tool_Recommendations {

	/**
	 * Preset configurations.
	 *
	 * @var array
	 */
	protected static $presets = array(
		'conservative' => array(
			'name'                  => 'Conservative',
			'description'           => 'Lower token limits for cost control. Best for budget-conscious deployments.',
			'multiplier_adjustment' => 0.8, // Reduce all multipliers by 20%.
		),
		'balanced'     => array(
			'name'                  => 'Balanced (Recommended)',
			'description'           => 'Optimal balance between performance and cost. Uses our analyzed recommendations.',
			'multiplier_adjustment' => 1.0, // Use recommended values as-is.
		),
		'performance'  => array(
			'name'                  => 'Performance',
			'description'           => 'Higher token limits for maximum performance. Best for high-traffic or demanding applications.',
			'multiplier_adjustment' => 1.3, // Increase all multipliers by 30%.
		),
		'aggressive'   => array(
			'name'                  => 'Aggressive',
			'description'           => 'Maximum token limits for complex operations. Use when cost is not a concern.',
			'multiplier_adjustment' => 1.5, // Increase all multipliers by 50%.
		),
	);

	/**
	 * Tool categories and their characteristics
	 *
	 * Updated 2026-01-18: Comprehensive coverage of all 202 tools with 2026 industry standards
	 * Token multipliers based on GPT-4o/4o-mini, Claude 3.5, and Gemini 2.0 capabilities
	 *
	 * @var array
	 */
	protected static $tool_categories = array(
		// High resource tools - complex operations, large outputs.
		// 2026 Standard: 2.5x multiplier (was 2.0x) - AI models now handle more context efficiently.
		'high_resource'      => array(
			'multiplier'      => 2.5,
			'preferred_model' => 'gpt-4o',
			'description'     => 'Complex operations requiring extensive processing and large outputs',
			'tools'           => array(
				'run_crawl4ai_job',
				'run_crawl4ai_job_validated',
				'submit_document_prompt',
				'search_content',
				'search_content_validated',
				'web_search',
				'web_search_validated',
				'deep_research',
				'query_mesh_intelligent',
				'google_analytics_report',
				'quickbooks_report',
				'reliefweb_reports',
				'get_site_summary',
				'semantic_content_search',
				'batch_embed_content',
				'pro_excel',
			),
		),

		// Medium resource tools - moderate complexity.
		// 2026 Standard: 1.7x multiplier (was 1.5x) - Better model efficiency allows slight increase.
		'medium_resource'    => array(
			'multiplier'      => 1.7,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Moderate complexity operations with medium-sized outputs',
			'tools'           => array(
				'get_recent_posts',
				'get_recent_posts_validated',
				'get_woo_products',
				'get_woo_recent_orders',
				'get_jetengine_items',
				'search_attachments',
				'get_elementor_templates',
				'get_environment_status',
				'get_site_health',
				'check_site_security',
				'get_system_logs',
				'get_system_logs_validated',
				'get_facebook_instagram_insights',
				'get_linkedin_insights',
				'get_tiktok_insights',
				'get_google_business_insights',
				'search_gmail',
				'search_drive',
				'query_remote_site',
				'woo_orders',
				'woo_products',
				'get_jetformbuilder_submissions',
				'list_openai_files',
				'get_openai_file_details',
				'analyze_file_suitability',
			),
		),

		// Low resource tools - simple operations.
		// 2026 Standard: 1.0x multiplier (unchanged) - Simple operations remain efficient.
		'low_resource'       => array(
			'multiplier'      => 1.0,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Simple operations with minimal token requirements',
			'tools'           => array(
				'get_user_info',
				'get_user_info_validated',
				'get_update_status',
				'count_tokens',
				'get_cron_job',
				'list_cron_jobs',
				'check_wp_cli',
				'probe_chat',
				'probe_remote_mcp',
				'get_jetformbuilder_forms',
				'list_jetengine_routes',
				'list_jetengine_rest_routes',
				'crawl4ai_price_lookup',
				'get_import_duty',
				'get_all_import_status',
				'list_all_export_templates',
				'list_all_import_templates',
				'list_professions',
				'get_profession',
				'get_profession_stats',
				'profession_stats',
				'list_available_models',
				'get_model_information',
				'list_batches',
				'get_batch_status',
				'list_vector_stores',
				'get_vector_store',
				'check_video_status',
				'get_video_metadata',
			),
		),

		// Image/Media generation tools - specialized models.
		// 2026 Standard: 1.8x multiplier (was 1.5x) - More complex image generation workflows.
		'image_generation'   => array(
			'multiplier'      => 1.8,
			'preferred_model' => 'default', // Let assistant handle model selection.
			'description'     => 'Image and media generation operations',
			'tools'           => array(
				'generate_openai_image',
				'generate_openai_image_validated',
				'generate_gemini_image',
				'generate_gemini_image_validated',
				'generate_cloudflareai_image',
				'edit_gemini_image',
				'edit_gemini_image_validated',
				'edit_openai_image',
				'create_image_variation',
				'graphic_editor_plus',
				'image_base',
				'generate_image_alt_text',
				'generate_image_alt_text_validated',
				'generate_image_caption',
				'generate_image_caption_validated',
				'vision_object_localization',
				'vision_product_search',
				'vectorize_image',
				'resize_image',
				'crop_image',
				'rotate_image',
				'convert_image_format',
				'remove_background',
			),
		),

		// Video generation and processing - high complexity.
		// 2026 Standard: 2.2x multiplier (NEW category).
		'video_processing'   => array(
			'multiplier'      => 2.2,
			'preferred_model' => 'default',
			'description'     => 'Video generation, analysis, and processing operations',
			'tools'           => array(
				'generate_veo_video',
				'generate_veo_video_validated',
				'generate_sora_video',
				'generate_sora_video_validated',
				'analyze_video',
				'extract_video_frames',
				'generate_video_caption',
			),
		),

		// Audio/Speech tools.
		// 2026 Standard: 1.6x multiplier (was 1.5x) - Better audio processing.
		'audio_processing'   => array(
			'multiplier'      => 1.6,
			'preferred_model' => 'default',
			'description'     => 'Audio processing and speech operations',
			'tools'           => array(
				'generate_openai_speech',
				'generate_openai_speech_validated',
				'transcribe_openai_audio',
				'transcribe_openai_audio_validated',
				'generate_music',
				'generate_music_validated',
			),
		),

		// Content creation tools - need good quality models.
		// 2026 Standard: 1.8x multiplier (was 1.5x) - More sophisticated content generation.
		'content_creation'   => array(
			'multiplier'      => 1.8,
			'preferred_model' => 'gpt-4o',
			'description'     => 'Content creation and management operations',
			'tools'           => array(
				'save_post',
				'save_post_validated',
				'create_post',
				'create_post_validated',
				'create_woo_product',
				'create_woo_product_validated',
				'create_wpcode_snippet',
				'post_facebook_instagram',
				'post_linkedin_update',
				'post_google_business_update',
				'post_tiktok_video',
				'send_mailjet_email',
				'send_group_email',
				'send_group_email_validated',
				'newsletter_create_email',
				'create_term',
				'update_term',
				'update_option',
			),
		),

		// API/External tools - variable complexity.
		// 2026 Standard: 1.4x multiplier (was 1.2x) - More complex API integrations.
		'api_operations'     => array(
			'multiplier'      => 1.4,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'External API calls and integrations',
			'tools'           => array(
				'run_openai_external_action',
				'invoke_jetengine_route',
				'jetengine',
				'elementor',
				'get_rankmath_seo',
				'open_openai_logs',
				'open_openai_usage',
				'openai_usage_analytics',
				'create_google_calendar_event',
				'get_nhc_active_storms',
				'get_gdacs_events',
				'get_open_meteo_forecast',
				'geocode_address',
				'search_places',
				'gemini_geospatial_query',
				'generic_rest',
				'remote_wp_connection',
			),
		),

		// Messaging/Communication tools.
		// 2026 Standard: 1.0x multiplier (unchanged) - Lightweight operations.
		'messaging'          => array(
			'multiplier'      => 1.0,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Messaging and notification operations',
			'tools'           => array(
				'schedule_notify_sms',
				'send_telegram_message',
				'send_whatsapp_message',
				'newsletter_add_subscriber',
				'newsletter_get_emails',
				'newsletter_get_subscriber_stats',
				'newsletter_get_subscribers',
				'newsletter_unsubscribe',
			),
		),

		// Security/Auth tools - lightweight but critical.
		// 2026 Standard: 1.0x multiplier (unchanged).
		'security_auth'      => array(
			'multiplier'      => 1.0,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Security and authentication operations',
			'tools'           => array(
				'generate_auth0_token',
				'generate_simple_jwt_token',
				'moderate_content',
				'analyze_comment_content',
			),
		),

		// Cache/Performance tools - minimal token usage.
		// 2026 Standard: 0.8x multiplier (unchanged).
		'cache_performance'  => array(
			'multiplier'      => 0.8,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Cache management and performance operations',
			'tools'           => array(
				'purge_cache',
				'purge_cloudflare_cache',
				'purge_varnish_cache',
			),
		),

		// Scheduling/Automation tools.
		// 2026 Standard: 1.1x multiplier (was 1.0x) - More complex automation workflows.
		'scheduling'         => array(
			'multiplier'      => 1.1,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Scheduling and automation operations',
			'tools'           => array(
				'create_cron_job',
				'create_cron_job_validated',
				'delete_cron_job',
				'trigger_all_export',
				'trigger_all_import',
				'create_batch',
				'monitor_batch',
			),
		),

		// Dataset/Data operations - HuggingFace and external data sources.
		// 2026 Standard: 1.5x multiplier (was 1.3x) - Larger dataset operations.
		'dataset_operations' => array(
			'multiplier'      => 1.5,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Dataset queries and data retrieval from external sources',
			'tools'           => array(
				'huggingface_dataset_search',
				'huggingface_dataset_get_info',
				'huggingface_dataset_get_size',
				'huggingface_dataset_get_rows',
				'huggingface_dataset_preview_rows',
				'huggingface_dataset_list_splits',
				'huggingface_dataset_get_statistics',
				'huggingface_dataset_get_parquet',
				'huggingface_dataset_is_valid',
				'huggingface_dataset_filter',
				'huggingface_recommended_datasets',
			),
		),

		// Agent coordination tools - DeepSeek V4 orchestration (NEW).
		// 2026 Standard: 2.0x multiplier - Complex multi-agent operations.
		'agent_coordination' => array(
			'multiplier'      => 2.0,
			'preferred_model' => 'gpt-4o',
			'description'     => 'Multi-agent orchestration and coordination operations',
			'tools'           => array(
				'create_agent_team',
				'delegate_to_agent',
				'aggregate_agent_results',
				'create_assistant',
				'create_assistant_validated',
				'save_profession',
			),
		),

		// E-commerce operations (NEW).
		// 2026 Standard: 1.6x multiplier - Product management complexity.
		'ecommerce'          => array(
			'multiplier'      => 1.6,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'E-commerce and product management operations',
			'tools'           => array(
				'scrape_product',
				'scrape_product_validated',
				'lookup_product_price',
				'product_actualization',
				'payhere_get_payment',
				'flowhub_create_order',
				'flowhub_get_customers',
				'flowhub_get_inventory',
				'flowhub_get_orders',
				'flowhub_get_products',
				'flowhub_manage_customer',
				'flowhub_manage_product',
			),
		),

		// AI Model management (NEW).
		// 2026 Standard: 1.3x multiplier - Model research and configuration.
		'model_management'   => array(
			'multiplier'      => 1.3,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'AI model discovery, configuration, and management',
			'tools'           => array(
				'add_model_config',
				'discover_new_models',
				'research_model',
				'suggest_best_model',
			),
		),

		// Vector and embeddings operations (NEW).
		// 2026 Standard: 1.7x multiplier - Complex vector operations.
		'vector_operations'  => array(
			'multiplier'      => 1.7,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Vector store and embedding operations',
			'tools'           => array(
				'create_text_embeddings',
				'create_vector_store',
				'manage_vector_store_files',
			),
		),

		// Site and plugin management (NEW).
		// 2026 Standard: 1.2x multiplier - Installation and configuration.
		'site_management'    => array(
			'multiplier'      => 1.2,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'WordPress site and plugin management',
			'tools'           => array(
				'install_and_activate_plugin',
				'install_and_activate_theme',
				'site_creator',
				'get_elementor_templates',
				'import_elementor_template_kit',
			),
		),

		// GitHub operations (NEW).
		// 2026 Standard: 1.5x multiplier - Code repository management.
		'github_operations'  => array(
			'multiplier'      => 1.5,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'GitHub repository and codespace operations',
			'tools'           => array(
				'github_repository_operations',
				'list_github_repositories',
				'manage_github_codespace',
			),
		),

		// Charts and visualization (NEW).
		// 2026 Standard: 1.4x multiplier - Data visualization complexity.
		'visualization'      => array(
			'multiplier'      => 1.4,
			'preferred_model' => 'gpt-4o-mini',
			'description'     => 'Data visualization and chart generation',
			'tools'           => array(
				'create_chart',
				'create_chart_validated',
			),
		),
	);

	/**
	 * Get recommendation for a specific tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return array|null Recommendation data or null if no specific recommendation.
	 */
	public static function get_tool_recommendation( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		if ( '' === $tool_slug ) {
			return null;
		}

		// Get categories with filter support.
		$tool_categories = self::get_tool_categories();

		// Find which category this tool belongs to.
		foreach ( $tool_categories as $category => $data ) {
			if ( in_array( $tool_slug, $data['tools'], true ) ) {
				return array(
					'category'        => $category,
					'multiplier'      => $data['multiplier'],
					'preferred_model' => $data['preferred_model'],
					'description'     => $data['description'],
					'reason'          => self::get_recommendation_reason( $category, $tool_slug ),
				);
			}
		}

		// For uncategorized tools, try to suggest a category.
		$suggestion = self::suggest_tool_category( $tool_slug );

		// Default recommendation for uncategorized tools using the suggestion.
		return array(
			'category'        => $suggestion['category'],
			'multiplier'      => $suggestion['multiplier'],
			'preferred_model' => $suggestion['model'],
			'description'     => 'Auto-detected category',
			'reason'          => sprintf(
				/* translators: %s: reasoning for the suggestion */
				__( 'This is a new/uncategorized tool. Suggested settings: %s', 'mcp-ai-wpoos' ),
				$suggestion['reasoning']
			),
		);
	}

	/**
	 * Get all recommendations for all tools.
	 *
	 * @return array Tool slug => recommendation data pairs.
	 */
	public static function get_all_recommendations() {
		$recommendations = array();

		// Get all tools from tool categories first.
		// This ensures preset application works for all defined tools.
		$tool_categories = self::get_tool_categories();
		$recommendations = self::process_tools_from_categories( $tool_categories );

		// Also get tools from registry to catch any dynamically registered tools.
		$recommendations = self::add_tools_from_registry( $recommendations );

		return $recommendations;
	}

	/**
	 * Process tools from categories and generate recommendations.
	 *
	 * @param array $tool_categories Tool categories array.
	 * @return array Recommendations array.
	 */
	private static function process_tools_from_categories( $tool_categories ) {
		$recommendations = array();

		foreach ( $tool_categories as $category => $data ) {
			if ( isset( $data['tools'] ) && is_array( $data['tools'] ) ) {
				foreach ( $data['tools'] as $tool_slug ) {
					if ( ! empty( $tool_slug ) ) {
						$recommendations[ $tool_slug ] = self::get_tool_recommendation( $tool_slug );
					}
				}
			}
		}

		return $recommendations;
	}

	/**
	 * Add dynamically registered tools from registry to recommendations.
	 *
	 * @param array $recommendations Existing recommendations array.
	 * @return array Updated recommendations array.
	 */
	private static function add_tools_from_registry( $recommendations ) {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			return $recommendations;
		}

		$registry->init();
		$registered_tools = $registry->get_tools();

		foreach ( $registered_tools as $tool ) {
			if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
				$slug = $tool->get_slug();
				if ( ! empty( $slug ) && ! isset( $recommendations[ $slug ] ) ) {
					// Only add if not already in recommendations from categories.
					$recommendations[ $slug ] = self::get_tool_recommendation( $slug );
				}
			}
		}

		return $recommendations;
	}

	/**
	 * Get recommendation reason for a tool.
	 *
	 * @param string $category  Tool category.
	 * @param string $tool_slug Tool identifier.
	 * @return string Explanation of recommendation.
	 */
	protected static function get_recommendation_reason( $category, $tool_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for tool-specific reasons.
		$reasons = array(
			'high_resource'      => __( 'This tool performs complex operations or generates large outputs, requiring higher token limits for optimal performance.', 'mcp-ai-wpoos' ),
			'medium_resource'    => __( 'This tool handles moderate complexity operations. A balanced token limit provides good performance without excessive resource usage.', 'mcp-ai-wpoos' ),
			'low_resource'       => __( 'This tool performs simple operations with minimal token requirements. Standard limits are sufficient.', 'mcp-ai-wpoos' ),
			'image_generation'   => __( 'Image generation tools have specialized requirements. Let the assistant choose the best model for image tasks.', 'mcp-ai-wpoos' ),
			'video_processing'   => __( 'Video generation and processing requires substantial resources. Higher token limits ensure quality output.', 'mcp-ai-wpoos' ),
			'audio_processing'   => __( 'Audio processing tools benefit from specialized models. Token usage varies based on audio length.', 'mcp-ai-wpoos' ),
			'content_creation'   => __( 'Content creation requires high-quality language models for best results. Moderate token limits ensure quality output.', 'mcp-ai-wpoos' ),
			'api_operations'     => __( 'External API operations have moderate complexity. Slightly elevated limits account for API response processing.', 'mcp-ai-wpoos' ),
			'messaging'          => __( 'Messaging operations are typically lightweight and don\'t require elevated token limits.', 'mcp-ai-wpoos' ),
			'security_auth'      => __( 'Security and authentication operations are critical but lightweight, using standard token limits.', 'mcp-ai-wpoos' ),
			'cache_performance'  => __( 'Cache operations are simple and fast, requiring minimal token processing.', 'mcp-ai-wpoos' ),
			'scheduling'         => __( 'Scheduling operations have standard complexity and work well with default token limits.', 'mcp-ai-wpoos' ),
			'dataset_operations' => __( 'Dataset operations handle large amounts of data. Elevated token limits improve data processing efficiency.', 'mcp-ai-wpoos' ),
			'agent_coordination' => __( 'Multi-agent orchestration involves complex coordination and communication. Higher token limits ensure effective agent interactions.', 'mcp-ai-wpoos' ),
			'ecommerce'          => __( 'E-commerce operations handle product data and transactions. Moderate token limits balance performance and cost.', 'mcp-ai-wpoos' ),
			'model_management'   => __( 'AI model management involves configuration and research. Moderate token limits support model analysis.', 'mcp-ai-wpoos' ),
			'vector_operations'  => __( 'Vector and embedding operations process high-dimensional data. Elevated token limits improve accuracy.', 'mcp-ai-wpoos' ),
			'site_management'    => __( 'Site management tools handle WordPress installations and configurations. Moderate limits provide flexibility.', 'mcp-ai-wpoos' ),
			'github_operations'  => __( 'GitHub operations manage code repositories and development workflows. Moderate limits support code analysis.', 'mcp-ai-wpoos' ),
			'visualization'      => __( 'Data visualization tools create charts and graphs. Moderate limits support complex visualizations.', 'mcp-ai-wpoos' ),
		);

		return isset( $reasons[ $category ] ) ? $reasons[ $category ] : __( 'Recommended settings based on tool analysis.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get category statistics.
	 *
	 * @return array Category statistics.
	 */
	public static function get_category_statistics() {
		$stats = array();

		$tool_categories = self::get_tool_categories();

		foreach ( $tool_categories as $category => $data ) {
			$stats[ $category ] = array(
				'name'        => ucwords( str_replace( '_', ' ', $category ) ),
				'tool_count'  => count( $data['tools'] ),
				'multiplier'  => $data['multiplier'],
				'description' => $data['description'],
			);
		}

		return $stats;
	}

	/**
	 * Check if current settings match recommendations.
	 *
	 * @param string $tool_slug         Tool identifier.
	 * @param float  $current_multiplier Current multiplier setting.
	 * @param string $current_model      Current model preference.
	 * @return array Match status.
	 */
	public static function check_recommendation_match( $tool_slug, $current_multiplier, $current_model ) {
		$recommendation = self::get_tool_recommendation( $tool_slug );

		if ( ! $recommendation ) {
			return array(
				'matches'    => true,
				'suggestion' => null,
			);
		}

		$multiplier_matches = abs( (float) $current_multiplier - (float) $recommendation['multiplier'] ) < 0.1;
		$model_matches      = $current_model === $recommendation['preferred_model'] || 'default' === $recommendation['preferred_model'];

		return array(
			'matches'                => $multiplier_matches && $model_matches,
			'multiplier_matches'     => $multiplier_matches,
			'model_matches'          => $model_matches,
			'recommended_multiplier' => $recommendation['multiplier'],
			'recommended_model'      => $recommendation['preferred_model'],
			'category'               => $recommendation['category'],
			'reason'                 => $recommendation['reason'],
		);
	}

	/**
	 * Get tools that don't match recommendations.
	 *
	 * @return array Tools with mismatched settings.
	 */
	public static function get_mismatched_tools() {
		$mismatched = array();

		// Get current multipliers and model preferences.
		$multipliers       = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		$model_preferences = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preferences();

		// Get all recommendations.
		$recommendations = self::get_all_recommendations();

		foreach ( $recommendations as $tool_slug => $recommendation ) {
			$current_multiplier = isset( $multipliers[ $tool_slug ] ) ? $multipliers[ $tool_slug ] : 1.0;
			$current_model      = isset( $model_preferences[ $tool_slug ] ) ? $model_preferences[ $tool_slug ] : 'default';

			$match = self::check_recommendation_match( $tool_slug, $current_multiplier, $current_model );

			if ( ! $match['matches'] ) {
				$mismatched[ $tool_slug ] = array(
					'current_multiplier'     => $current_multiplier,
					'recommended_multiplier' => $recommendation['multiplier'],
					'current_model'          => $current_model,
					'recommended_model'      => $recommendation['preferred_model'],
					'category'               => $recommendation['category'],
				);
			}
		}

		return $mismatched;
	}

	/**
	 * Apply recommendations for a specific tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return bool True on success.
	 */
	public static function apply_recommendation( $tool_slug ) {
		$recommendation = self::get_tool_recommendation( $tool_slug );

		if ( ! $recommendation ) {
			return false;
		}

		$success = true;

		// Apply multiplier.
		if ( ! WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $recommendation['multiplier'] ) ) {
			$success = false;
		}

		// Apply model preference.
		if ( ! WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $recommendation['preferred_model'] ) ) {
			$success = false;
		}

		if ( $success ) {
			/**
			 * Fires after recommendations are applied to a tool.
			 *
			 * @param string $tool_slug      Tool identifier.
			 * @param array  $recommendation Applied recommendation data.
			 */
			do_action( 'wp_mcp_ai_tool_recommendation_applied', $tool_slug, $recommendation );

			WP_MCP_AI_Logger::log_event(
				'tool_recommendation_applied',
				'Applied recommended settings to tool.',
				array(
					'tool_slug'      => $tool_slug,
					'recommendation' => $recommendation,
				)
			);
		}

		return $success;
	}

	/**
	 * Apply recommendations for all tools.
	 *
	 * @return array Results (success/failure counts).
	 */
	public static function apply_all_recommendations() {
		$results = array(
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		$recommendations = self::get_all_recommendations();

		foreach ( $recommendations as $tool_slug => $recommendation ) {
			// Skip if already matches recommendation.
			$multipliers       = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
			$model_preferences = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preferences();

			$current_multiplier = isset( $multipliers[ $tool_slug ] ) ? $multipliers[ $tool_slug ] : 1.0;
			$current_model      = isset( $model_preferences[ $tool_slug ] ) ? $model_preferences[ $tool_slug ] : 'default';

			$match = self::check_recommendation_match( $tool_slug, $current_multiplier, $current_model );

			if ( $match['matches'] ) {
				++$results['skipped'];
				continue;
			}

			if ( self::apply_recommendation( $tool_slug ) ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}
		}

		WP_MCP_AI_Logger::log_event(
			'bulk_recommendations_applied',
			'Applied recommended settings to multiple tools.',
			array( 'results' => $results )
		);

		return $results;
	}

	/**
	 * Get available presets.
	 *
	 * @return array Preset configurations.
	 */
	public static function get_presets() {
		/**
		 * Filter available recommendation presets.
		 *
		 * @param array $presets Preset configurations.
		 */
		return apply_filters( 'wp_mcp_ai_recommendation_presets', self::$presets );
	}

	/**
	 * Get recommendation with preset adjustment.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param string $preset    Preset name.
	 * @return array|null Adjusted recommendation data.
	 */
	public static function get_tool_recommendation_with_preset( $tool_slug, $preset = 'balanced' ) {
		$recommendation = self::get_tool_recommendation( $tool_slug );

		if ( ! $recommendation ) {
			return null;
		}

		$presets = self::get_presets();
		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = 'balanced';
		}

		$adjustment = $presets[ $preset ]['multiplier_adjustment'];

		// Apply preset adjustment to multiplier.
		$recommendation['multiplier']         = round( $recommendation['multiplier'] * $adjustment, 1 );
		$recommendation['preset']             = $preset;
		$recommendation['preset_name']        = $presets[ $preset ]['name'];
		$recommendation['preset_description'] = $presets[ $preset ]['description'];

		return $recommendation;
	}

	/**
	 * Apply preset to all tools.
	 *
	 * @param string $preset Preset name (conservative, balanced, performance, aggressive).
	 * @return array Results (success/failure counts).
	 */
	public static function apply_preset( $preset = 'balanced' ) {
		$preset = sanitize_key( $preset );

		$presets = self::get_presets();
		if ( ! isset( $presets[ $preset ] ) ) {
			return array(
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
				'error'   => __( 'Invalid preset specified.', 'mcp-ai-wpoos' ),
			);
		}

		$results = array(
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		$recommendations = self::get_all_recommendations();

		// Batch updates: prepare all multipliers and model preferences first.
		$all_multipliers = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		if ( ! is_array( $all_multipliers ) ) {
			$all_multipliers = array();
		}

		$all_preferences = get_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION, array() );
		if ( ! is_array( $all_preferences ) ) {
			$all_preferences = array();
		}

		// Process each tool and prepare batch updates.
		foreach ( $recommendations as $tool_slug => $base_recommendation ) {
			$adjusted_recommendation = self::get_tool_recommendation_with_preset( $tool_slug, $preset );

			if ( ! $adjusted_recommendation ) {
				++$results['failed'];
				continue;
			}

			// Validate multiplier value.
			$multiplier = (float) $adjusted_recommendation['multiplier'];
			if ( $multiplier < 0.1 || $multiplier > 10 ) {
				++$results['failed'];
				continue;
			}

			// Add to batch updates.
			$all_multipliers[ $tool_slug ] = $multiplier;
			$all_preferences[ $tool_slug ] = sanitize_text_field( $adjusted_recommendation['preferred_model'] );

			++$results['success'];
		}

		// Apply batch updates - this is much more efficient than updating one at a time.
		$multipliers_updated = update_option( 'wp_mcp_ai_tool_multipliers', $all_multipliers, false );
		$preferences_updated = update_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION, $all_preferences, false );

		// If either batch update failed completely, log it.
		if ( ! $multipliers_updated && ! $preferences_updated ) {
			WP_MCP_AI_Logger::log_event(
				'preset_apply_error',
				'Failed to save preset settings to database.',
				array(
					'preset'  => $preset,
					'results' => $results,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'preset_applied',
			'Applied recommendation preset to tools.',
			array(
				'preset'              => $preset,
				'results'             => $results,
				'multipliers_updated' => $multipliers_updated,
				'preferences_updated' => $preferences_updated,
			)
		);

		/**
		 * Fires after a preset is applied to tools.
		 *
		 * @param string $preset  Preset name.
		 * @param array  $results Application results.
		 */
		do_action( 'wp_mcp_ai_preset_applied', $preset, $results );

		return $results;
	}

	/**
	 * Get current preset based on tool settings.
	 *
	 * Analyzes current multipliers and determines which preset they most closely match.
	 *
	 * @return string Detected preset name or 'custom' if no match.
	 */
	public static function detect_current_preset() {
		$multipliers     = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		$recommendations = self::get_all_recommendations();

		if ( empty( $multipliers ) || empty( $recommendations ) ) {
			return 'balanced';
		}

		$presets        = self::get_presets();
		$preset_matches = array();

		// Calculate match score for each preset.
		foreach ( $presets as $preset_key => $preset_data ) {
			$adjustment = $preset_data['multiplier_adjustment'];
			$matches    = 0;
			$total      = 0;

			foreach ( $recommendations as $tool_slug => $recommendation ) {
				$expected_multiplier = round( $recommendation['multiplier'] * $adjustment, 1 );
				$current_multiplier  = isset( $multipliers[ $tool_slug ] ) ? (float) $multipliers[ $tool_slug ] : 1.0;

				++$total;

				// Consider it a match if within 0.1 of expected.
				if ( abs( $current_multiplier - $expected_multiplier ) < 0.15 ) {
					++$matches;
				}
			}

			$preset_matches[ $preset_key ] = $total > 0 ? ( $matches / $total ) : 0;
		}

		// Find the preset with highest match percentage.
		arsort( $preset_matches );
		$best_preset        = key( $preset_matches );
		$best_match_percent = reset( $preset_matches );

		// If match is less than 60%, consider it custom.
		if ( $best_match_percent < 0.6 ) {
			return 'custom';
		}

		return $best_preset;
	}

	/**
	 * Get tools that are not in any category (new/uncategorized tools).
	 *
	 * @return array Array of uncategorized tool slugs.
	 */
	public static function get_uncategorized_tools() {
		$uncategorized = array();

		// Get all registered tools.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			return $uncategorized;
		}

		$registry->init();
		$registered_tools = $registry->get_tools();

		// Build list of all categorized tools.
		$categorized_tools = array();
		foreach ( self::$tool_categories as $category => $data ) {
			$categorized_tools = array_merge( $categorized_tools, $data['tools'] );
		}

		// Find tools not in any category.
		foreach ( $registered_tools as $tool ) {
			if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
				$slug = $tool->get_slug();
				if ( ! empty( $slug ) && ! in_array( $slug, $categorized_tools, true ) ) {
					$uncategorized[] = $slug;
				}
			}
		}

		return $uncategorized;
	}

	/**
	 * Analyze a tool and suggest categorization.
	 *
	 * This analyzes tool characteristics to suggest which category it should belong to.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return array Suggested category and reasoning.
	 */
	public static function suggest_tool_category( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		if ( '' === $tool_slug ) {
			return array(
				'category'   => 'low_resource',
				'multiplier' => 1.0,
				'model'      => 'gpt-4o-mini',
				'confidence' => 0,
				'reasoning'  => __( 'Invalid tool slug.', 'mcp-ai-wpoos' ),
			);
		}

		// Get the tool instance to analyze.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			return array(
				'category'   => 'low_resource',
				'multiplier' => 1.0,
				'model'      => 'gpt-4o-mini',
				'confidence' => 0,
				'reasoning'  => __( 'Tool registry not available.', 'mcp-ai-wpoos' ),
			);
		}

		$registry->init();
		$tool = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return array(
				'category'   => 'low_resource',
				'multiplier' => 1.0,
				'model'      => 'gpt-4o-mini',
				'confidence' => 0,
				'reasoning'  => __( 'Tool not found in registry.', 'mcp-ai-wpoos' ),
			);
		}

		// Analyze tool characteristics.
		$suggested_category = 'low_resource'; // Default.
		$confidence         = 50;
		$reasoning          = '';

		// Check tool slug for keywords.
		if ( strpos( $tool_slug, 'crawl' ) !== false || strpos( $tool_slug, 'search' ) !== false ) {
			$suggested_category = 'high_resource';
			$confidence         = 70;
			$reasoning          = __( 'Tool involves crawling or searching, which typically requires high resources.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'image' ) !== false || strpos( $tool_slug, 'vision' ) !== false || strpos( $tool_slug, 'generate' ) !== false ) {
			$suggested_category = 'image_generation';
			$confidence         = 80;
			$reasoning          = __( 'Tool involves image generation or vision processing.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'audio' ) !== false || strpos( $tool_slug, 'speech' ) !== false || strpos( $tool_slug, 'transcribe' ) !== false ) {
			$suggested_category = 'audio_processing';
			$confidence         = 80;
			$reasoning          = __( 'Tool involves audio or speech processing.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'post' ) !== false || strpos( $tool_slug, 'save' ) !== false || strpos( $tool_slug, 'create' ) !== false || strpos( $tool_slug, 'send' ) !== false ) {
			$suggested_category = 'content_creation';
			$confidence         = 65;
			$reasoning          = __( 'Tool involves content creation or posting.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'cache' ) !== false || strpos( $tool_slug, 'purge' ) !== false ) {
			$suggested_category = 'cache_performance';
			$confidence         = 85;
			$reasoning          = __( 'Tool involves cache management.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'cron' ) !== false || strpos( $tool_slug, 'schedule' ) !== false ) {
			$suggested_category = 'scheduling';
			$confidence         = 75;
			$reasoning          = __( 'Tool involves scheduling or automation.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'message' ) !== false || strpos( $tool_slug, 'sms' ) !== false || strpos( $tool_slug, 'notify' ) !== false ) {
			$suggested_category = 'messaging';
			$confidence         = 75;
			$reasoning          = __( 'Tool involves messaging or notifications.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'auth' ) !== false || strpos( $tool_slug, 'token' ) !== false || strpos( $tool_slug, 'jwt' ) !== false ) {
			$suggested_category = 'security_auth';
			$confidence         = 80;
			$reasoning          = __( 'Tool involves authentication or security.', 'mcp-ai-wpoos' );
		} elseif ( strpos( $tool_slug, 'get' ) !== false || strpos( $tool_slug, 'list' ) !== false || strpos( $tool_slug, 'query' ) !== false ) {
			$suggested_category = 'medium_resource';
			$confidence         = 60;
			$reasoning          = __( 'Tool involves data retrieval, typically medium resource usage.', 'mcp-ai-wpoos' );
		}

		// Get category data.
		$category_data = isset( self::$tool_categories[ $suggested_category ] ) ? self::$tool_categories[ $suggested_category ] : self::$tool_categories['low_resource'];

		return array(
			'category'   => $suggested_category,
			'multiplier' => $category_data['multiplier'],
			'model'      => $category_data['preferred_model'],
			'confidence' => $confidence,
			'reasoning'  => $reasoning,
		);
	}

	/**
	 * Add a tool to a category.
	 *
	 * This method allows dynamically adding tools to categories via a filter.
	 * Note: This doesn't modify the static array, but allows filtering.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param string $category  Category name.
	 * @return bool True on success, false if category doesn't exist.
	 */
	public static function add_tool_to_category( $tool_slug, $category ) {
		$tool_slug = sanitize_key( $tool_slug );
		$category  = sanitize_key( $category );

		if ( '' === $tool_slug || '' === $category ) {
			return false;
		}

		if ( ! isset( self::$tool_categories[ $category ] ) ) {
			return false;
		}

		// Use a filter to allow adding tools to categories dynamically.
		add_filter(
			'wp_mcp_ai_tool_categories',
			function ( $categories ) use ( $tool_slug, $category ) {
				if ( ! isset( $categories[ $category ] ) ) {
					return $categories;
				}

				if ( ! in_array( $tool_slug, $categories[ $category ]['tools'], true ) ) {
					$categories[ $category ]['tools'][] = $tool_slug;
				}

				return $categories;
			}
		);

		return true;
	}

	/**
	 * Get tool categories with dynamic filtering support.
	 *
	 * @return array Tool categories.
	 */
	public static function get_tool_categories() {
		/**
		 * Filter tool categories to allow dynamic additions.
		 *
		 * @param array $categories Tool categories.
		 */
		return apply_filters( 'wp_mcp_ai_tool_categories', self::$tool_categories );
	}
}
