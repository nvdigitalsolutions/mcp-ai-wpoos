<?php
/**
 * Tool Presets Helper - Updated 2026
 *
 * Comprehensive tool selection presets including all 170+ tools organized by
 * use case and profession type. Includes DeepSeek V4 agent coordination tools
 * and Clear All/Select All functionality.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @updated 2026-01-19 - Fixed cloudflareai tool slug mismatch, verified all tools present
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for managing and rendering tool presets.
 *
 * @since 1.0.0
 * @updated 1.9.0 - Added agent coordination tools and profession-specific presets
 */
class WP_MCP_AI_Tool_Presets_Helper {

	/**
	 * Get the tool presets configuration.
	 *
	 * Updated 2026-01-19 to include all 170+ current tools organized by:
	 * - Core functionality (AI/ML, Media, Content, etc.)
	 * - Profession categories (Healthcare, Legal, Education, etc.)
	 * - Agentic workflows (including 3 coordination tools)
	 * - Fixed cloudflareai_text_to_image slug (was generate_cloudflareai_image)
	 *
	 * @return array Array of presets with name, description, and tools.
	 */
	public static function get_presets() {
		$presets = array(
			// =================================================================
			// CORE FUNCTIONALITY PRESETS
			// =================================================================

			'agentic_workflow'    => array(
				'name'        => __( '🤖 Agentic Workflow', 'mcp-ai-wpoos' ),
				'description' => __( 'DeepSeek V4 multi-agent orchestration tools for team composition, delegation, and result aggregation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination tools (DeepSeek V4).
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
					// Supporting tools for agentic operations.
					'list_professions',
					'get_profession',
					'get_profession_stats',
					'save_profession',
					'create_assistant',
					'probe_chat',
					'query_mesh_intelligent',
				),
			),

			'ai_ml'               => array(
				'name'        => __( '🧠 AI/ML Operations', 'mcp-ai-wpoos' ),
				'description' => __( 'AI model management, embeddings, batches, vector stores, and ML operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Model management.
					'list_available_models',
					'suggest_best_model',
					'get_model_information',
					'add_model_config',
					'discover_new_models',
					'research_model',
					// Token management.
					'count_tokens',
					// Embeddings & vectors.
					'create_text_embeddings',
					'batch_embed_content',
					'semantic_content_search',
					'vectorize_image',
					// Vector stores.
					'create_vector_store',
					'list_vector_stores',
					'get_vector_store',
					'manage_vector_store_files',
					// Batches.
					'create_batch',
					'list_batches',
					'get_batch_status',
					'monitor_batch',
					// File operations.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Analytics.
					'openai_usage_analytics',
					'open_openai_usage',
					'open_openai_logs',
					// Content moderation.
					'moderate_content',
					'analyze_comment_content',
				),
			),

			'media_generation'    => array(
				'name'        => __( '🎨 Media Generation', 'mcp-ai-wpoos' ),
				'description' => __( 'Image, video, and audio generation tools across multiple AI providers', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image generation.
					'generate_openai_image',
					'generate_openai_image_validated',
					'generate_gemini_image',
					'generate_gemini_image_validated',
					'cloudflareai_text_to_image',
					// Image editing.
					'edit_gemini_image',
					'edit_gemini_image_validated',
					'edit_openai_image',
					'create_image_variation',
					'graphic_editor_plus',
					'image_base',
					// Image processing.
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'remove_background',
					// Image analysis.
					'generate_image_alt_text',
					'generate_image_alt_text_validated',
					'generate_image_caption',
					'generate_image_caption_validated',
					'vision_object_localization',
					'vision_product_search',
					// Video generation.
					'generate_veo_video',
					'generate_veo_video_validated',
					'generate_sora_video',
					'generate_sora_video_validated',
					// Video analysis.
					'check_video_status',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'generate_video_caption',
					// Audio generation.
					'generate_music',
					'generate_music_validated',
					'generate_openai_speech',
					'generate_openai_speech_validated',
					'transcribe_openai_audio',
					'transcribe_openai_audio_validated',
				),
			),

			'content_writing'     => array(
				'name'        => __( '✍️ Content Writing', 'mcp-ai-wpoos' ),
				'description' => __( 'Tools for creating, managing, and optimizing content, posts, and pages', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Content search & retrieval.
					'search_content',
					'search_content_validated',
					'search_attachments',
					'get_recent_posts',
					'get_recent_posts_validated',
					'semantic_content_search',
					// Content creation.
					'save_post',
					'save_post_validated',
					'create_post',
					'create_post_validated',
					// Research.
					'web_search',
					'web_search_validated',
					'deep_research',
					'submit_document_prompt',
					// Taxonomy management.
					'create_term',
					'update_term',
					// SEO.
					'get_rankmath_seo',
					// Images for content.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					'generate_image_alt_text',
					// Quality assurance.
					'moderate_content',
					'analyze_comment_content',
				),
			),

			'ecommerce'           => array(
				'name'        => __( '🛒 E-commerce', 'mcp-ai-wpoos' ),
				'description' => __( 'WooCommerce, product management, and e-commerce operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// WooCommerce.
					'get_woo_recent_orders',
					'get_woo_products',
					'create_woo_product',
					'create_woo_product_validated',
					'woo_orders',
					'woo_products',
					// Product operations.
					'product_actualization',
					'scrape_product',
					'scrape_product_validated',
					'lookup_product_price',
					'crawl4ai_price_lookup',
					'vision_product_search',
					// Cannabis industry (Flowhub).
					'flowhub_create_order',
					'flowhub_get_customers',
					'flowhub_get_inventory',
					'flowhub_get_orders',
					'flowhub_get_products',
					'flowhub_manage_customer',
					'flowhub_manage_product',
					// Import/Export.
					'get_import_duty',
					'get_all_import_status',
					'list_all_export_templates',
					'list_all_import_templates',
					'trigger_all_export',
					'trigger_all_import',
					// Payments.
					'payhere_get_payment',
					// Email marketing.
					'send_group_email',
					'send_group_email_validated',
					// Remote connections.
					'remote_wp_connection',
				),
			),

			'site_management'     => array(
				'name'        => __( '⚙️ Site Management', 'mcp-ai-wpoos' ),
				'description' => __( 'WordPress core management, monitoring, and system operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Site information.
					'get_site_summary',
					'get_system_logs',
					'get_system_logs_validated',
					'get_update_status',
					'get_site_health',
					'get_environment_status',
					'check_site_security',
					// Caching.
					'purge_cache',
					'purge_cloudflare_cache',
					'purge_varnish_cache',
					// Cron jobs.
					'create_cron_job',
					'create_cron_job_validated',
					'list_cron_jobs',
					'get_cron_job',
					'delete_cron_job',
					// Plugins & themes.
					'install_and_activate_plugin',
					'install_and_activate_theme',
					// Options.
					'update_option',
					// Multi-site.
					'site_creator',
					// Remote connections.
					'remote_wp_connection',
					'query_remote_site',
				),
			),

			'seo_marketing'       => array(
				'name'        => __( '📈 SEO & Marketing', 'mcp-ai-wpoos' ),
				'description' => __( 'SEO analysis, social media management, and marketing automation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// SEO.
					'get_rankmath_seo',
					'web_search',
					// Social media.
					'post_facebook_instagram',
					'post_linkedin_update',
					'post_tiktok_video',
					'post_google_business_update',
					// Social insights.
					'get_facebook_instagram_insights',
					'get_linkedin_insights',
					'get_tiktok_insights',
					'get_google_business_insights',
					// Analytics.
					'google_analytics_report',
					// Calendar.
					'create_google_calendar_event',
					// Messaging.
					'send_telegram_message',
					'send_whatsapp_message',
					'schedule_notify_sms',
					// Email.
					'search_gmail',
					'send_group_email',
					// Newsletter.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_emails',
					'newsletter_get_subscriber_stats',
					'newsletter_get_subscribers',
					'newsletter_unsubscribe',
				),
			),

			'development'         => array(
				'name'        => __( '💻 Development', 'mcp-ai-wpoos' ),
				'description' => __( 'Code management, CLI operations, and technical development tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Code snippets.
					'create_wpcode_snippet',
					// CLI.
					'check_wp_cli',
					// Logs & debugging.
					'get_system_logs',
					// Tokens.
					'count_tokens',
					// Chat & probing.
					'probe_chat',
					'probe_remote_mcp',
					'query_mesh_intelligent',
					// GitHub.
					'github_repository_operations',
					'list_github_repositories',
					'manage_github_codespace',
					// External actions.
					'run_openai_external_action',
					// Generic REST.
					'generic_rest',
					// User management.
					'get_user_info',
					'get_user_info_validated',
					// Remote.
					'remote_wp_connection',
					'query_remote_site',
				),
			),

			'data_analytics'      => array(
				'name'        => __( '📊 Data & Analytics', 'mcp-ai-wpoos' ),
				'description' => __( 'Data collection, reporting, analytics, and business intelligence', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// JetEngine.
					'get_jetengine_items',
					'list_jetengine_rest_routes',
					'list_jetengine_routes',
					'invoke_jetengine_route',
					'jetengine',
					// JetFormBuilder.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Analytics.
					'google_analytics_report',
					'quickbooks_report',
					// File analysis.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Profession analytics.
					'list_professions',
					'get_profession',
					'get_profession_stats',
					'profession_stats',
					'save_profession',
					// Charts.
					'create_chart',
					'create_chart_validated',
					// Excel.
					'pro_excel',
					// Hugging Face datasets.
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_rows',
					'huggingface_dataset_preview_rows',
					'huggingface_dataset_get_size',
					'huggingface_dataset_get_statistics',
					'huggingface_dataset_filter',
					'huggingface_dataset_list_splits',
					'huggingface_dataset_get_parquet',
					'huggingface_dataset_is_valid',
					'huggingface_recommended_datasets',
				),
			),

			'design_professional' => array(
				'name'        => __( '🎨 Design Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Visual design, rendering, branding, and creative production tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image generation.
					'generate_openai_image',
					'generate_gemini_image',
					'cloudflareai_text_to_image',
					// Image editing.
					'edit_gemini_image',
					'edit_openai_image',
					'graphic_editor_plus',
					// Video.
					'generate_veo_video',
					'generate_sora_video',
					'check_video_status',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					// Image processing.
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'remove_background',
					'create_image_variation',
					// Image analysis.
					'vision_object_localization',
					'vision_product_search',
					'generate_image_alt_text',
					'generate_image_caption',
					// Charts & visualization.
					'create_chart',
					// Music.
					'generate_music',
					// Elementor.
					'get_elementor_templates',
					'import_elementor_template_kit',
					'elementor',
				),
			),

			'crawling_scraping'   => array(
				'name'        => __( '🕷️ Web Crawling & Scraping', 'mcp-ai-wpoos' ),
				'description' => __( 'Web scraping, crawling, and data extraction tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'run_crawl4ai_job',
					'run_crawl4ai_job_validated',
					'crawl4ai_price_lookup',
					'scrape_product',
					'scrape_product_validated',
					'web_search',
					'query_remote_site',
					'product_actualization',
				),
			),

			'files_documents'     => array(
				'name'        => __( '📁 Files & Documents', 'mcp-ai-wpoos' ),
				'description' => __( 'File management, search, and document processing tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// OpenAI file operations.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Document processing.
					'submit_document_prompt',
					// Search operations.
					'search_content',
					'search_content_validated',
					'search_attachments',
					'semantic_content_search',
					'search_drive',
					'search_gmail',
					// Excel.
					'pro_excel',
				),
			),

			'scheduling_automation' => array(
				'name'        => __( '⏰ Scheduling & Automation', 'mcp-ai-wpoos' ),
				'description' => __( 'Cron jobs, task scheduling, and workflow automation tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Cron job management.
					'create_cron_job',
					'create_cron_job_validated',
					'list_cron_jobs',
					'get_cron_job',
					'delete_cron_job',
					// Calendar events.
					'create_google_calendar_event',
					// Batch operations.
					'create_batch',
					'list_batches',
					'get_batch_status',
					'monitor_batch',
					// Import/Export automation.
					'trigger_all_export',
					'trigger_all_import',
					'get_all_import_status',
					'list_all_export_templates',
					'list_all_import_templates',
					// SMS scheduling.
					'schedule_notify_sms',
				),
			),

			'authentication_security' => array(
				'name'        => __( '🔐 Authentication & Security', 'mcp-ai-wpoos' ),
				'description' => __( 'Authentication tokens, security checks, and access control', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Token generation.
					'generate_auth0_token',
					'generate_simple_jwt_token',
					// Security checks.
					'check_site_security',
					'get_site_health',
					// Content moderation.
					'moderate_content',
					'analyze_comment_content',
					// User management.
					'get_user_info',
					'get_user_info_validated',
				),
			),

			'communication_messaging' => array(
				'name'        => __( '💬 Communication & Messaging', 'mcp-ai-wpoos' ),
				'description' => __( 'Email, SMS, messaging, and communication tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Email.
					'send_group_email',
					'send_group_email_validated',
					'send_mailjet_email',
					'search_gmail',
					// Newsletter.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_emails',
					'newsletter_get_subscriber_stats',
					'newsletter_get_subscribers',
					'newsletter_unsubscribe',
					// Messaging.
					'send_telegram_message',
					'send_whatsapp_message',
					'schedule_notify_sms',
				),
			),

			'assistant_management' => array(
				'name'        => __( '🤖 Assistant Management', 'mcp-ai-wpoos' ),
				'description' => __( 'AI assistant creation, configuration, and management', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'create_assistant',
					'create_assistant_validated',
					'probe_chat',
					'probe_remote_mcp',
					'query_mesh_intelligent',
					'list_professions',
					'get_profession',
					'save_profession',
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
				),
			),

			// =================================================================
			// PROFESSION-SPECIFIC PRESETS
			// =================================================================

			'healthcare'          => array(
				'name'        => __( '⚕️ Healthcare Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Medical, clinical, and healthcare management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Research & information.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					// Content creation.
					'save_post',
					'create_post',
					// Communication.
					'send_group_email',
					'schedule_notify_sms',
					'send_whatsapp_message',
					// Calendar.
					'create_google_calendar_event',
					// Forms & data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Analytics.
					'create_chart',
					// Images.
					'generate_image_caption',
					'vision_object_localization',
					// Moderation.
					'moderate_content',
					// User management.
					'get_user_info',
				),
			),

			'legal'               => array(
				'name'        => __( '⚖️ Legal Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Legal research, document management, and compliance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Research.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'submit_document_prompt',
					// Document management.
					'save_post',
					'create_post',
					'search_attachments',
					'get_recent_posts',
					// File analysis.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Communication.
					'send_group_email',
					'search_gmail',
					// Calendar.
					'create_google_calendar_event',
					// Data collection.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Moderation.
					'moderate_content',
					// Security.
					'check_site_security',
				),
			),

			'education'           => array(
				'name'        => __( '🎓 Education Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Educational content, course management, and learning tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Content creation.
					'save_post',
					'create_post',
					'search_content',
					'get_recent_posts',
					// Research.
					'web_search',
					'deep_research',
					'semantic_content_search',
					// Media.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					'generate_openai_speech',
					'transcribe_openai_audio',
					'generate_video_caption',
					// Charts & visualization.
					'create_chart',
					// Communication.
					'send_group_email',
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Forms.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Calendar.
					'create_google_calendar_event',
					// User management.
					'get_user_info',
					// Moderation.
					'moderate_content',
					'analyze_comment_content',
				),
			),

			'finance_business'    => array(
				'name'        => __( '💼 Finance & Business', 'mcp-ai-wpoos' ),
				'description' => __( 'Financial analysis, business intelligence, and reporting tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Analytics.
					'google_analytics_report',
					'quickbooks_report',
					'get_profession_stats',
					// Charts & reporting.
					'create_chart',
					'pro_excel',
					// Data collection.
					'get_jetengine_items',
					'get_jetformbuilder_submissions',
					// E-commerce.
					'get_woo_recent_orders',
					'get_woo_products',
					'payhere_get_payment',
					// Research.
					'web_search',
					'search_content',
					// Content.
					'save_post',
					'create_post',
					// Communication.
					'send_group_email',
					'search_gmail',
					// Calendar.
					'create_google_calendar_event',
					// Site management.
					'get_site_summary',
					'get_environment_status',
				),
			),

			'science_research'    => array(
				'name'        => __( '🔬 Science & Research', 'mcp-ai-wpoos' ),
				'description' => __( 'Scientific research, data analysis, and academic tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Research.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'submit_document_prompt',
					// Data analysis.
					'create_chart',
					'pro_excel',
					// Hugging Face datasets.
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_rows',
					'huggingface_dataset_preview_rows',
					'huggingface_dataset_get_statistics',
					'huggingface_recommended_datasets',
					// File analysis.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Content creation.
					'save_post',
					'create_post',
					// Images & visualization.
					'generate_image_caption',
					'create_chart',
					'vision_object_localization',
					// Geospatial.
					'gemini_geospatial_query',
					'geocode_address',
					'search_places',
					// Disaster & environmental.
					'get_gdacs_events',
					'get_nhc_active_storms',
					'reliefweb_reports',
					// Weather.
					'get_open_meteo_forecast',
				),
			),

			'real_estate'         => array(
				'name'        => __( '🏠 Real Estate', 'mcp-ai-wpoos' ),
				'description' => __( 'Property management, listings, and real estate operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Location services.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					// Images.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					'generate_image_alt_text',
					'vision_object_localization',
					// Video.
					'generate_veo_video',
					'analyze_video',
					// Content.
					'save_post',
					'create_post',
					'search_content',
					// Communication.
					'send_group_email',
					'schedule_notify_sms',
					'send_whatsapp_message',
					// Calendar.
					'create_google_calendar_event',
					// Forms.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// SEO.
					'get_rankmath_seo',
					// Social media.
					'post_facebook_instagram',
					'post_google_business_update',
				),
			),

			'travel_hospitality'  => array(
				'name'        => __( '✈️ Travel & Hospitality', 'mcp-ai-wpoos' ),
				'description' => __( 'Tourism, hospitality, and travel industry tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Location & maps.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					// Weather.
					'get_open_meteo_forecast',
					// Images.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					// Content.
					'save_post',
					'create_post',
					'search_content',
					// Communication.
					'send_group_email',
					'newsletter_add_subscriber',
					'send_whatsapp_message',
					// Calendar.
					'create_google_calendar_event',
					// Forms & bookings.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Social media.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_google_business_update',
					// Reviews & moderation.
					'moderate_content',
					'analyze_comment_content',
					// Drive & documents.
					'search_drive',
				),
			),

			'public_service'      => array(
				'name'        => __( '🏛️ Public Service & Government', 'mcp-ai-wpoos' ),
				'description' => __( 'Government, public administration, and civic tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Emergency & disaster.
					'get_gdacs_events',
					'get_nhc_active_storms',
					'reliefweb_reports',
					// Geospatial.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					// Communication.
					'send_group_email',
					'schedule_notify_sms',
					'send_telegram_message',
					'send_whatsapp_message',
					// Content management.
					'save_post',
					'create_post',
					'search_content',
					// Forms & data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Analytics & reporting.
					'create_chart',
					'pro_excel',
					// Calendar.
					'create_google_calendar_event',
					// Security.
					'check_site_security',
					'moderate_content',
				),
			),
		);

		/**
		 * Filter the tool selection presets.
		 *
		 * @param array $presets Array of presets with name, description, and tools.
		 * @since 1.0.0
		 */
		return apply_filters( 'wp_mcp_ai_tool_presets', $presets );
	}

	/**
	 * Render tool preset buttons with Clear All and Select All.
	 *
	 * @param array $args {
	 *     Optional. Arguments for rendering presets.
	 *
	 *     @type array  $available_tools Array of available tool slugs to filter presets. Default empty (no filtering).
	 *     @type string $title           Title for the presets section. Default 'Quick Tool Selection Presets'.
	 *     @type string $description     Description text. Default preset description.
	 *     @type string $button_class    Additional CSS class for buttons. Default 'button'.
	 *     @type string $container_class CSS class for the container. Default 'wp-mcp-ai-tool-presets'.
	 *     @type bool   $include_script  Whether to include JavaScript. Default true.
	 *     @type string $checkbox_selector CSS selector for tool checkboxes. Default 'input[name="wp_mcp_ai_tools[]"]'.
	 *     @type bool   $show_utility_buttons Whether to show Clear All and Select All buttons. Default true.
	 * }
	 */
	public static function render_presets( $args = array() ) {
		$defaults = array(
			'available_tools'      => array(),
			'title'                => __( 'Quick Tool Selection Presets', 'mcp-ai-wpoos' ),
			'description'          => __( 'Click a preset to add its tools to your selection. Click again to remove them. You can combine multiple presets.', 'mcp-ai-wpoos' ),
			'button_class'         => 'button',
			'container_class'      => 'wp-mcp-ai-tool-presets',
			'include_script'       => true,
			'checkbox_selector'    => 'input[name="wp_mcp_ai_tools[]"]',
			'show_utility_buttons' => true,
		);

		$args    = wp_parse_args( $args, $defaults );
		$presets = self::get_presets();

		if ( empty( $presets ) ) {
			return;
		}

		// If available tools are provided, filter presets.
		$filter_tools = ! empty( $args['available_tools'] ) && is_array( $args['available_tools'] );

		$container_style = 'margin-top: 1rem;';
		$title_style     = 'margin-top: 0; margin-bottom: 0.5rem; font-size: 14px;';
		$desc_style      = 'margin-top: 0; margin-bottom: 1rem;';
		$buttons_style   = 'display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; align-items: center;';

		echo '<div class="' . esc_attr( $args['container_class'] ) . '" style="' . esc_attr( $container_style ) . '">';
		echo '<h3 class="' . esc_attr( $args['container_class'] ) . '__title" style="' . esc_attr( $title_style ) . '">' . esc_html( $args['title'] ) . '</h3>';
		echo '<p class="' . esc_attr( $args['container_class'] ) . '__description description" style="' . esc_attr( $desc_style ) . '">' . esc_html( $args['description'] ) . '</p>';
		echo '<div class="' . esc_attr( $args['container_class'] ) . '__buttons" style="' . esc_attr( $buttons_style ) . '">';

		// Add utility buttons first.
		if ( $args['show_utility_buttons'] ) {
			printf(
				'<button type="button" class="%1$s wp-mcp-ai-select-all-tools" style="background: #00a32a; border-color: #00a32a; color: #fff;">%2$s</button>',
				esc_attr( $args['button_class'] ),
				esc_html__( '✓ Select All', 'mcp-ai-wpoos' )
			);
			printf(
				'<button type="button" class="%1$s wp-mcp-ai-clear-all-tools" style="background: #d63638; border-color: #d63638; color: #fff;">%2$s</button>',
				esc_attr( $args['button_class'] ),
				esc_html__( '✗ Clear All', 'mcp-ai-wpoos' )
			);
			echo '<span style="width: 20px; height: 1px; background: #dcdcde; margin: 0 10px;"></span>';
		}

		foreach ( $presets as $preset_key => $preset_data ) {
			if ( ! isset( $preset_data['name'], $preset_data['tools'] ) || ! is_array( $preset_data['tools'] ) ) {
				continue;
			}

			$preset_tools = $preset_data['tools'];

			// Filter to only include available tools if filtering is enabled.
			if ( $filter_tools ) {
				$preset_tools = array_intersect( $preset_tools, $args['available_tools'] );
				if ( empty( $preset_tools ) ) {
					continue;
				}
			}

			$preset_name        = sanitize_text_field( $preset_data['name'] );
			$preset_description = isset( $preset_data['description'] ) ? sanitize_text_field( $preset_data['description'] ) : '';
			$preset_tools_json  = wp_json_encode( array_values( $preset_tools ) );
			$tool_count         = count( $preset_tools );

			printf(
				'<button type="button" class="%1$s wp-mcp-ai-tool-preset-btn" data-preset="%2$s" data-tools="%3$s" title="%4$s (%5$d tools)">%6$s <span style="font-size: 0.85em; opacity: 0.7;">(%7$d)</span></button>',
				esc_attr( $args['button_class'] ),
				esc_attr( $preset_key ),
				esc_attr( $preset_tools_json ),
				esc_attr( $preset_description ),
				esc_attr( $tool_count ),
				esc_html( $preset_name ),
				esc_html( $tool_count )
			);
		}

		echo '</div>';
		echo '</div>';

		// Include JavaScript if requested.
		if ( $args['include_script'] ) {
			self::render_preset_script( $args['checkbox_selector'] );
		}
	}

	/**
	 * Render the JavaScript for preset functionality.
	 *
	 * Includes Clear All and Select All functionality.
	 *
	 * @param string $checkbox_selector CSS selector for tool checkboxes.
	 */
	protected static function render_preset_script( $checkbox_selector ) {
		static $preset_script_printed = false;

		if ( $preset_script_printed ) {
			return;
		}

		$preset_script_printed = true;
		?>
		<script type="text/javascript">
		( function() {
			'use strict';

			document.addEventListener( 'DOMContentLoaded', function() {
				var presetButtons = document.querySelectorAll( '.wp-mcp-ai-tool-preset-btn' );
				var checkboxSelector = <?php echo wp_json_encode( $checkbox_selector ); ?>;
				var selectAllBtn = document.querySelector( '.wp-mcp-ai-select-all-tools' );
				var clearAllBtn = document.querySelector( '.wp-mcp-ai-clear-all-tools' );

				// Helper function to toggle checkboxes.
				function toggleCheckboxes( toolSlugs, checked ) {
					toolSlugs.forEach( function( toolSlug ) {
						var checkbox = document.querySelector( checkboxSelector + '[value="' + toolSlug + '"]' );
						if ( checkbox ) {
							checkbox.checked = checked;
							var event = new Event( 'change', { bubbles: true } );
							checkbox.dispatchEvent( event );
						}
					} );
				}

				// Select All button handler.
				if ( selectAllBtn ) {
					selectAllBtn.addEventListener( 'click', function( e ) {
						e.preventDefault();
						var allCheckboxes = document.querySelectorAll( checkboxSelector );
						allCheckboxes.forEach( function( checkbox ) {
							if ( ! checkbox.checked ) {
								checkbox.checked = true;
								var event = new Event( 'change', { bubbles: true } );
								checkbox.dispatchEvent( event );
							}
						} );
					} );
				}

				// Clear All button handler.
				if ( clearAllBtn ) {
					clearAllBtn.addEventListener( 'click', function( e ) {
						e.preventDefault();
						var allCheckboxes = document.querySelectorAll( checkboxSelector );
						allCheckboxes.forEach( function( checkbox ) {
							if ( checkbox.checked ) {
								checkbox.checked = false;
								var event = new Event( 'change', { bubbles: true } );
								checkbox.dispatchEvent( event );
							}
						} );
						// Remove active state from all preset buttons.
						presetButtons.forEach( function( btn ) {
							btn.classList.remove( 'wp-mcp-ai-preset-active' );
							btn.style.backgroundColor = '';
							btn.style.color = '';
							btn.style.borderColor = '';
						} );
					} );
				}

				// Preset button handlers.
				presetButtons.forEach( function( button ) {
					button.addEventListener( 'click', function( e ) {
						e.preventDefault();

						var toolsData = button.getAttribute( 'data-tools' );
						if ( ! toolsData ) {
							return;
						}

						var presetTools;
						try {
							presetTools = JSON.parse( toolsData );
						} catch ( error ) {
							console.error( 'Failed to parse preset tools:', error );
							return;
						}

						if ( ! Array.isArray( presetTools ) ) {
							return;
						}

						// Check if preset is currently active (toggle behavior).
						var isActive = button.classList.contains( 'wp-mcp-ai-preset-active' );

						if ( isActive ) {
							// Deactivate: uncheck all tools in this preset.
							toggleCheckboxes( presetTools, false );

							// Remove active state.
							button.classList.remove( 'wp-mcp-ai-preset-active' );
							button.style.backgroundColor = '';
							button.style.color = '';
							button.style.borderColor = '';
						} else {
							// Activate: check all tools in this preset (add to current selection).
							toggleCheckboxes( presetTools, true );

							// Add active state.
							button.classList.add( 'wp-mcp-ai-preset-active' );
							button.style.backgroundColor = '#2271b1';
							button.style.color = '#fff';
							button.style.borderColor = '#2271b1';
						}
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
