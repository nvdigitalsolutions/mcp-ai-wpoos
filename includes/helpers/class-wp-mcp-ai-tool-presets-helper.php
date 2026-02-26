<?php
/**
 * Tool Presets Helper - Updated 2026
 *
 * Comprehensive tool selection presets including all 310+ tools organized by
 * use case and profession type. Includes DeepSeek V4 agent coordination tools,
 * quiz management, media templates, music production, and more.
 * Clear All/Select All functionality included.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @updated 2026-01-22 - Added quiz tools, media templates, music production, research tools, math/science, project management, legal/policy, location services, and developer advanced presets
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
	 * Updated 2026-01-22 to include all 310+ current tools organized by:
	 * - Core functionality (AI/ML, Media, Content, etc.)
	 * - Profession categories (Healthcare, Legal, Education, etc.)
	 * - Specialized workflows (Quiz Management, Media Templates, Music Production)
	 * - Advanced tools (Math/Science, Research, Project Management)
	 * - Agentic workflows (including 3 coordination tools)
	 *
	 * @return array Array of presets with name, description, and tools.
	 */
	public static function get_presets() {
		$presets = array(
			// =================================================================.
			// CORE FUNCTIONALITY PRESETS
			// =================================================================.

			'agentic_workflow'          => array(
				'name'        => __( '🤖 Agentic Workflow', 'mcp-ai-wpoos' ),
				'description' => __( 'DeepSeek V4 multi-agent orchestration tools for team composition, delegation, memory management, and result aggregation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination tools (DeepSeek V4).
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
					// Agent memory tools (Phase 4/5).
					'store_agent_context',
					'retrieve_agent_memory',
					'execute_workflow',
					// Supporting tools for agentic operations.
					'list_professions',
					'get_profession',
					'get_profession_stats',
					'save_profession',
					'create_assistant',
					'probe_chat',
					'query_mesh_intelligent',
					// Workflow validation & monitoring (NEW)
					'check_workflow_health',
					'validate_workflow',
					'visualize_workflow_metrics',
				),
			),

			'ai_ml'                     => array(
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
					// Client-side AI operations (NEW)
					'batch_manage_memory',
					'client_analyze_sentiment',
					'client_extract_entities',
					'client_question_answering',
					'client_semantic_search',
					'client_summarize_text',
					'client_translate_text',
					'enable_reasoning_mode',
					'manage_context_lifecycle',
					'memory_audit_trail',
					'prioritize_context',
					'semantic_context_search',
					'validate_reasoning_chain',
				),
			),

			'media_generation'          => array(
				'name'        => __( '🎨 Media Generation', 'mcp-ai-wpoos' ),
				'description' => __( 'Image, video, and audio generation tools across multiple AI providers', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image generation.
					'generate_openai_image',
					'generate_openai_image_validated',
					'generate_gemini_image',
					'generate_gemini_image_validated',
					'cloudflareai_text_to_image',
					// Image generation (Pro).
					'generate_image_ai',
					'generate_image_variations',
					'image_inpainting',
					'text_to_image_prompt_optimizer',
					// Image editing.
					'edit_gemini_image',
					'edit_gemini_image_validated',
					'edit_openai_image',
					'create_image_variation',
					'graphic_editor_plus',
					'image_base',
					// Image processing (Pro - preferred over basic).
					'remove_image_background',
					'upscale_image_ai',
					'enhance_image_quality',
					'apply_artistic_style',
					'colorize_image',
					'resize_image_smart',
					'compress_image',
					'optimize_for_web',
					'batch_process_images',
					'generate_responsive_images',
					// Image optimization (NEW - Phase 5).
					'media_library_optimizer',
					'image_format_batch_converter',
					'responsive_image_validator',
					// Image analysis.
					'generate_image_alt_text',
					'generate_image_alt_text_validated',
					'image_alt_text_optimizer',
					'generate_image_caption',
					'generate_image_caption_validated',
					'vision_object_localization',
					'vision_product_search',
					// Video generation.
					'generate_veo_video',
					'generate_veo_video_validated',
					'generate_sora_video',
					'generate_sora_video_validated',
					// Video production.
					'create_video_from_images',
					'add_watermark_to_video',
					'generate_video_captions',
					'merge_videos',
					'trim_video',
					'resize_video_resolution',
					'adjust_video_speed',
					'compress_video',
					'convert_video_format',
					'optimize_for_platform',
					// Video analysis.
					'check_video_status',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'generate_video_caption',
					'extract_video_metadata',
					'generate_video_thumbnails',
					// Audio generation.
					'generate_music',
					'generate_music_validated',
					'generate_openai_speech',
					'generate_openai_speech_validated',
					'transcribe_openai_audio',
					'transcribe_openai_audio_validated',
					'analyze_image',
					'extract_image_text',
					'generate_cloudflareai_image',
				),
			),

			'content_writing'           => array(
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
					// Content optimization (Phase 2).
					'generate_post_excerpt',
					'auto_categorize_content',
					'suggest_internal_links',
					'content_freshness_checker',
					// Content recommendations (Phase 6).
					'content_recommendation_engine',
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
					'multilingual_seo_audit',
					// Translation.
					'auto_translate_content',
					'detect_content_language',
					'translation_quality_check',
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

			'ecommerce'                 => array(
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
					// Translation for e-commerce.
					'translate_woocommerce_products',
					'auto_translate_content',
					'detect_content_language',
					// Analytics & insights.
					'churn_prediction',
					'customer_segmentation_ml',
					'revenue_forecast',
					'cohort_analysis',
					'funnel_analysis',
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

			'site_management'           => array(
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

			'seo_marketing'             => array(
				'name'        => __( '📈 SEO & Marketing', 'mcp-ai-wpoos' ),
				'description' => __( 'SEO analysis, social media management, and marketing automation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// SEO tools (Phase 3).
					'seo_meta_optimizer',
					'get_rankmath_seo',
					'multilingual_seo_audit',
					// Content optimization for SEO (Phase 2).
					'generate_post_excerpt',
					'suggest_internal_links',
					'content_freshness_checker',
					// Image SEO (Phase 3).
					'generate_image_alt_text',
					'image_alt_text_optimizer',
					// Performance optimization (Phase 6).
					'performance_optimizer_assistant',
					'responsive_image_validator',
					// Research & analysis.
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
					// Google Site Kit integration (NEW)
					'sitekit_adsense',
					'sitekit_analytics',
					'sitekit_pagespeed',
					'sitekit_search_console',
				),
			),

			'gutenberg_blocks'          => array(
				'name'        => __( '🧱 Gutenberg & Blocks', 'mcp-ai-wpoos' ),
				'description' => __( 'Gutenberg block patterns, FSE, and WordPress editor tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Block pattern generation (Phase 5).
					'gutenberg_block_pattern_generator',
					// Image optimization for blocks.
					'responsive_image_validator',
					'image_format_batch_converter',
					'media_library_optimizer',
					// Content for blocks.
					'generate_post_excerpt',
					'generate_image_caption',
					'image_alt_text_optimizer',
				),
			),

			'development'               => array(
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
					'analyze_code_sequence',
					'generate_mermaid',
				),
			),

			'data_analytics'            => array(
				'name'        => __( '📊 Data & Analytics', 'mcp-ai-wpoos' ),
				'description' => __( 'Data collection, reporting, analytics, and business intelligence', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Analytics Toolkit tools.
					'collect_custom_metrics',
					'generate_executive_dashboard',
					'cohort_analysis',
					'funnel_analysis',
					'churn_prediction',
					'customer_segmentation_ml',
					'revenue_forecast',
					'create_custom_report',
					'export_analytics_api',
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
					'generate_chart',
				),
			),

			'design_professional'       => array(
				'name'        => __( '🎨 Design Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Visual design, rendering, branding, creative production, and architectural design tools', 'mcp-ai-wpoos' ),
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
					// Architectural Design (Pro).
					'generate_floor_plan',
					'optimize_space_layout',
					'create_floor_plan_variations',
					'convert_sketch_to_floor_plan',
					'generate_3d_model',
					'render_architectural_view',
					'create_walkthrough_animation',
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					'check_building_code_compliance',
					'analyze_structural_feasibility',
					'calculate_sustainability_metrics',
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
				),
			),

			'crawling_scraping'         => array(
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

			'files_documents'           => array(
				'name'        => __( '📁 Files & Documents', 'mcp-ai-wpoos' ),
				'description' => __( 'File management, search, and document processing tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// OpenAI file operations.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Document processing.
					'submit_document_prompt',
					// Document Generation (Pro).
					'pro_pdf_document',
					'pro_word_document',
					'pro_excel_document',
					// Search operations.
					'search_content',
					'search_content_validated',
					'search_attachments',
					'semantic_content_search',
					'search_drive',
					'search_gmail',
					// Legacy Excel.
					'pro_excel',
				),
			),

			'scheduling_automation'     => array(
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

			'authentication_security'   => array(
				'name'        => __( '🔐 Authentication & Security', 'mcp-ai-wpoos' ),
				'description' => __( 'Authentication tokens, security checks, and access control', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Token generation.
					'generate_auth0_token',
					'generate_simple_jwt_token',
					// Security checks.
					'check_site_security',
					'get_site_health',
					// User security (Phase 4).
					'user_activity_auditor',
					'password_strength_analyzer',
					'login_security_monitor',
					'2fa_setup_assistant',
					// Content moderation.
					'moderate_content',
					'analyze_comment_content',
					// User management.
					'get_user_info',
					'get_user_info_validated',
				),
			),

			'communication_messaging'   => array(
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

			'assistant_management'      => array(
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
					'store_agent_context',
					'retrieve_agent_memory',
					'execute_workflow',
				),
			),

			// =================================================================.
			// PROFESSION-SPECIFIC PRESETS
			// =================================================================.

			'healthcare'                => array(
				'name'        => __( '⚕️ Healthcare Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Medical, clinical, and healthcare management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for patient context and medical history.
					'store_agent_context',
					'retrieve_agent_memory',
					// Health & Wellness Management (Pro).
					'create_member',
					'update_member',
					'get_member',
					'list_members',
					'delete_member',
					'get_member_health_summary',
					'create_medical_record',
					'update_medical_record',
					'get_medical_record',
					'list_medical_records',
					'search_medical_records',
					'delete_medical_record',
					'generate_health_chart',
					// Prescription Management (Pro).
					'create_prescription',
					'update_prescription',
					'delete_prescription',
					'get_prescription',
					'list_prescriptions',
					'search_prescriptions',
					'get_medication_schedule',
					// Allergy Management (Pro).
					'create_allergy',
					'update_allergy',
					'delete_allergy',
					'get_allergy',
					'list_allergies',
					// Checkup Management (Pro).
					'create_checkup',
					'update_checkup',
					'delete_checkup',
					'get_checkup',
					'list_checkups',
					'get_upcoming_checkups',
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

			'legal'                     => array(
				'name'        => __( '⚖️ Legal Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Legal research, document management, and compliance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for case context and precedents.
					'store_agent_context',
					'retrieve_agent_memory',
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

			'education'                 => array(
				'name'        => __( '🎓 Education Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Educational content, course management, and learning tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for student progress and course context.
					'store_agent_context',
					'retrieve_agent_memory',
					// Quiz Management (Pro).
					'create_quiz',
					'update_quiz',
					'delete_quiz',
					'get_quiz',
					'list_quizzes',
					'submit_quiz_answer',
					'grade_quiz',
					'get_quiz_submissions',
					'get_quiz_results',
					'get_quiz_analytics',
					'research_quiz_topic',
					// Student Management (Pro).
					'create_student',
					'update_student',
					'delete_student',
					'get_student',
					'list_students',
					'sync_students_from_isams',
					// ECA Management (Pro).
					'create_eca',
					'update_eca',
					'delete_eca',
					'get_eca',
					'list_ecas',
					'enroll_student_eca',
					'research_eca',
					'sync_ecas_from_isams',
					// Task Management (Pro).
					'create_task',
					'update_task',
					'delete_task',
					'list_tasks',
					// Content creation.
					'save_post',
					'create_post',
					'search_content',
					'get_recent_posts',
					// Research.
					'web_search',
					'deep_research',
					'semantic_content_search',
					'isams_query',
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
					'export_calendar_ics',
					// User management.
					'get_user_info',
					// Moderation.
					'moderate_content',
					'analyze_comment_content',
				),
			),

			'finance_business'          => array(
				'name'        => __( '💼 Finance & Business', 'mcp-ai-wpoos' ),
				'description' => __( 'Financial analysis, business intelligence, and reporting tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for financial context and client information.
					'store_agent_context',
					'retrieve_agent_memory',
					// Financial Planning Toolkit tools.
					'financial_health_score',
					'budget_planner',
					'expense_tracker',
					'net_worth_calculator',
					'cash_flow_analyzer',
					'retirement_calculator',
					'tax_estimator',
					// Analytics.
					'google_analytics_report',
					'quickbooks_report',
					'get_profession_stats',
					'revenue_forecast',
					'churn_prediction',
					'cohort_analysis',
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

			'science_research'          => array(
				'name'        => __( '🔬 Science & Research', 'mcp-ai-wpoos' ),
				'description' => __( 'Scientific research, data analysis, and academic tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for research contexts.
					'store_agent_context',
					'retrieve_agent_memory',
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

			'real_estate'               => array(
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

			'architect'                 => array(
				'name'        => __( '🏗️ Architect', 'mcp-ai-wpoos' ),
				'description' => __( 'Architectural design, planning, building codes, and construction documentation tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Architectural Design (Pro) - Floor Planning & Space Design.
					'generate_floor_plan',
					'optimize_space_layout',
					'create_floor_plan_variations',
					'convert_sketch_to_floor_plan',
					// 3D Modeling & Visualization.
					'generate_3d_model',
					'render_architectural_view',
					'create_walkthrough_animation',
					// Documentation & Blueprints.
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					// Analysis & Compliance.
					'check_building_code_compliance',
					'analyze_structural_feasibility',
					'calculate_sustainability_metrics',
					// Estimation & Scheduling.
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
					// Visual Design & Rendering.
					'generate_openai_image',
					'generate_gemini_image',
					'edit_gemini_image',
					'generate_veo_video',
					'create_walkthrough_animation',
					// Image Processing.
					'resize_image',
					'crop_image',
					'remove_background',
					// Research & Analysis.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					// Document Management.
					'save_post',
					'create_post',
					'search_attachments',
					'submit_document_prompt',
					'pro_pdf_document',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					// Data & Charts.
					'create_chart',
					'pro_excel',
					// Location Services.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					// Communication.
					'send_group_email',
					'create_google_calendar_event',
					// CAD/BIM Integration.
					'get_elementor_templates',
					'elementor',
				),
			),

			'engineering'               => array(
				'name'        => __( '⚙️ Engineering Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Civil, structural, MEP engineering with analysis, calculations, and technical documentation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Structural Analysis & Building Code Compliance.
					'analyze_structural_feasibility',
					'check_building_code_compliance',
					'calculate_sustainability_metrics',
					// Architectural Design Tools (for coordination).
					'generate_floor_plan',
					'generate_3d_model',
					'render_architectural_view',
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					// Material & Cost Estimation.
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
					// Mathematical & Scientific Tools.
					'solve_equation',
					'simplify_expression',
					'calculate_derivative',
					'calculate_integral',
					'matrix_operations',
					'render_math_equation',
					// Data Analysis & Visualization.
					'create_chart',
					'analyze_data_patterns',
					'pro_excel',
					// Geospatial Analysis.
					'geocode_address',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Research & Technical Documentation.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'submit_document_prompt',
					// Document Generation.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_word_document',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					// File Management.
					'search_attachments',
					'list_openai_files',
					'get_openai_file_details',
					// Communication & Collaboration.
					'send_group_email',
					'create_google_calendar_event',
				),
			),

			'construction_management'   => array(
				'name'        => __( '👷 Construction Management', 'mcp-ai-wpoos' ),
				'description' => __( 'Construction planning, scheduling, cost estimation, and project coordination tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Cost Estimation & Scheduling.
					'estimate_construction_cost',
					'generate_construction_timeline',
					'generate_material_schedule',
					// Building Code & Safety Compliance.
					'check_building_code_compliance',
					'analyze_structural_feasibility',
					'calculate_sustainability_metrics',
					// Project Management & Coordination.
					'create_project',
					'update_project',
					'delete_project',
					'list_projects',
					'research_project',
					// Task Management & Scheduling.
					'create_task',
					'update_task',
					'delete_task',
					'list_tasks',
					'create_task_plan',
					'get_task_plan',
					// Calendar & Events.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Documentation & Drawings (for review).
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					'generate_floor_plan',
					'generate_3d_model',
					// Document Management.
					'save_post',
					'create_post',
					'search_attachments',
					'submit_document_prompt',
					'pro_pdf_document',
					'pro_excel',
					// Analytics & Reporting.
					'create_chart',
					'analyze_data_patterns',
					// Communication & Team Coordination.
					'send_group_email',
					'schedule_notify_sms',
					'send_telegram_message',
					'send_whatsapp_message',
					// Location Services.
					'geocode_address',
					'search_places',
					'get_open_meteo_forecast',
					// Research & Information.
					'web_search',
					'search_content',
					'semantic_content_search',
					// Forms & Data Collection.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Agent Coordination (for complex projects).
					'create_agent_team',
					'delegate_to_agent',
					'execute_workflow',
				),
			),

			'interior_designer'         => array(
				'name'        => __( '🛋️ Interior Designer', 'mcp-ai-wpoos' ),
				'description' => __( 'Interior space planning, FF&E specification, material selection, and design visualization', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Space Planning & Layout.
					'generate_floor_plan',
					'optimize_space_layout',
					'create_floor_plan_variations',
					// 3D Visualization & Rendering.
					'generate_3d_model',
					'render_architectural_view',
					'create_walkthrough_animation',
					// Visual Design & Mood Boards.
					'generate_openai_image',
					'generate_gemini_image',
					'edit_gemini_image',
					'cloudflareai_text_to_image',
					// Image Processing.
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'remove_background',
					'create_image_variation',
					// Image Analysis (for materials & finishes).
					'vision_object_localization',
					'vision_product_search',
					'generate_image_caption',
					'generate_image_alt_text',
					// Material & Product Specifications.
					'generate_material_schedule',
					'scrape_product',
					'product_actualization',
					// Cost Estimation.
					'estimate_construction_cost',
					// Compliance & Analysis.
					'check_building_code_compliance',
					'calculate_sustainability_metrics',
					// Documentation.
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					'pro_pdf_document',
					// Charts & Design Boards.
					'create_chart',
					// Research & Inspiration.
					'web_search',
					'search_content',
					'crawl4ai_price_lookup',
					// Content Management.
					'save_post',
					'create_post',
					'search_attachments',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					// Communication.
					'send_group_email',
					'create_google_calendar_event',
					// E-commerce (for furniture/decor).
					'get_woo_products',
					'create_woo_product',
					// Forms & Client Data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
				),
			),

			'landscape_architect'       => array(
				'name'        => __( '🌳 Landscape Architect', 'mcp-ai-wpoos' ),
				'description' => __( 'Site design, planting plans, hardscape design, and sustainable landscape solutions', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Site Planning & Design.
					'generate_floor_plan',
					'optimize_space_layout',
					'generate_3d_model',
					'render_architectural_view',
					// Visualization & Rendering.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_veo_video',
					'create_walkthrough_animation',
					// Documentation & Drawings.
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					// Geospatial & Location Analysis.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Environmental & Weather Data.
					'get_open_meteo_forecast',
					'get_gdacs_events',
					// Sustainability Analysis.
					'calculate_sustainability_metrics',
					'check_building_code_compliance',
					// Material & Cost Estimation.
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
					// Research & Plant Selection.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					// Image Analysis (for site conditions).
					'vision_object_localization',
					'generate_image_caption',
					// Image Processing.
					'resize_image',
					'crop_image',
					'remove_background',
					// Document Management.
					'save_post',
					'create_post',
					'search_attachments',
					'submit_document_prompt',
					'pro_pdf_document',
					// Data & Charts.
					'create_chart',
					'pro_excel',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					// Communication.
					'send_group_email',
					'create_google_calendar_event',
					// Forms & Client Data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
				),
			),

			'urban_planner'             => array(
				'name'        => __( '🏙️ Urban Planner', 'mcp-ai-wpoos' ),
				'description' => __( 'Comprehensive planning, zoning, land use policy, and community development tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Geospatial Analysis & Mapping.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Data Analysis & Demographics.
					'analyze_data_patterns',
					'create_chart',
					'pro_excel',
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_statistics',
					// Policy & Document Management.
					'create_policy',
					'update_policy',
					'delete_policy',
					'get_policy',
					'list_policies',
					'search_policies',
					'research_policy',
					// Research & Analysis.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'submit_document_prompt',
					// Document Generation.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_word_document',
					'pro_excel',
					// Project & Development Review.
					'create_project',
					'update_project',
					'list_projects',
					'research_project',
					// Place Management.
					'create_place',
					'update_place',
					'get_place',
					'list_places',
					'research_place',
					// Environmental & Disaster Planning.
					'get_gdacs_events',
					'get_nhc_active_storms',
					'reliefweb_reports',
					'get_open_meteo_forecast',
					// Community Engagement Tools.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					'send_group_email',
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Public Meeting & Calendar Management.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Task & Timeline Management.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Content Management (public outreach).
					'search_attachments',
					'moderate_content',
					// Visualization (for presentations).
					'generate_openai_image',
					'generate_gemini_image',
					'render_architectural_view',
					// Building Code Reference (for zoning).
					'check_building_code_compliance',
					'analyze_structural_feasibility',
					// File Management.
					'list_openai_files',
					'get_openai_file_details',
				),
			),

			'dj_musician'               => array(
				'name'        => __( '🎵 DJ & Music Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'DJ services, music production, event management, and audio engineering', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// DJ Management Tools (Pro).
					'create_playlist',
					'generate_playlist_ai',
					'manage_music_library',
					'analyze_track_bpm',
					'mix_transition_planner',
					// Event Management.
					'create_event_booking',
					'update_event_details',
					'send_event_confirmation',
					'generate_event_timeline',
					// Client Management.
					'create_client_profile',
					'client_communication_log',
					'generate_dj_contract',
					'send_client_invoice',
					'track_event_payments',
					// Equipment Management.
					'add_equipment_item',
					'reserve_equipment',
					'track_equipment_maintenance',
					'equipment_inventory_report',
					// Music Production.
					'generate_music',
					'generate_jukebox_music',
					'check_jukebox_status',
					// Audio Processing.
					'transcribe_openai_audio',
					'generate_openai_speech',
					// Event Planning.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					// Communication.
					'send_group_email',
					'send_whatsapp_message',
					'schedule_notify_sms',
					// Forms & Client Data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Document Generation.
					'pro_pdf_document',
					'save_post',
					'create_post',
					// Social Media.
					'post_facebook_instagram',
					'post_tiktok_video',
					// Media.
					'generate_image_caption',
					'generate_openai_image',
				),
			),

			'sales_crm'                 => array(
				'name'        => __( '💼 Sales & CRM Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Sales pipeline, CRM management, lead tracking, and customer relationships', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// CRM Tools (Pro).
					'manage_crm_contact',
					// Project & Client Management.
					'create_project',
					'update_project',
					'list_projects',
					// Task & Follow-up Management.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Calendar & Scheduling.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Communication.
					'send_group_email',
					'send_mailjet_email',
					'search_gmail',
					'send_whatsapp_message',
					'send_telegram_message',
					'schedule_notify_sms',
					// Newsletter & Marketing.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_subscribers',
					// Forms & Lead Capture.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// E-commerce Integration.
					'get_woo_products',
					'get_woo_recent_orders',
					'create_woo_product',
					// Analytics & Reporting.
					'create_chart',
					'pro_excel',
					'analyze_data_patterns',
					'revenue_forecast',
					'churn_prediction',
					'cohort_analysis',
					// Social Media Outreach.
					'post_facebook_instagram',
					'post_linkedin_update',
					'get_facebook_instagram_insights',
					'get_linkedin_insights',
					// Research & Prospecting.
					'web_search',
					'search_content',
					'crawl4ai_price_lookup',
					'scrape_product',
					// Document Management.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_word_document',
					// SEO & Marketing.
					'get_rankmath_seo',
					'google_analytics_report',
				),
			),

			'film_video_production'     => array(
				'name'        => __( '🎬 Film & Video Production', 'mcp-ai-wpoos' ),
				'description' => __( 'Film production, video editing, cinematography, and post-production', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Video Generation & Editing.
					'generate_veo_video',
					'generate_sora_video',
					'check_video_status',
					// Video Processing (Pro).
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'transcode_video',
					'generate_video_caption',
					// Storyboarding & Visualization.
					'generate_openai_image',
					'generate_gemini_image',
					'edit_gemini_image',
					'create_image_variation',
					// Image Processing.
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					'remove_background',
					// Audio.
					'transcribe_openai_audio',
					'generate_openai_speech',
					'generate_music',
					// Script & Content Writing.
					'save_post',
					'create_post',
					'search_content',
					'submit_document_prompt',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'create_task',
					'list_tasks',
					'create_task_plan',
					// Scheduling & Calendar.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Communication & Collaboration.
					'send_group_email',
					'send_whatsapp_message',
					// Document Generation.
					'pro_pdf_document',
					'pro_word_document',
					// Social Media Distribution.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_linkedin_update',
					// Forms & Casting.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Location Scouting.
					'geocode_address',
					'search_places',
					'get_open_meteo_forecast',
					// Asset Management.
					'search_attachments',
					'list_openai_files',
				),
			),

			'agriculture_environmental' => array(
				'name'        => __( '🌾 Agriculture & Environmental', 'mcp-ai-wpoos' ),
				'description' => __( 'Agriculture, environmental science, forestry, wildlife management, and sustainability', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Geospatial Analysis.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Weather & Climate.
					'get_open_meteo_forecast',
					'get_gdacs_events',
					// Environmental Data.
					'get_nhc_active_storms',
					'reliefweb_reports',
					// Sustainability Analysis.
					'calculate_sustainability_metrics',
					// Research & Data.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_statistics',
					// Data Analysis.
					'analyze_data_patterns',
					'create_chart',
					'pro_excel',
					// Image Analysis (for crop/land assessment).
					'vision_object_localization',
					'generate_image_caption',
					'analyze_video',
					// Project Management (for land/farm management).
					'create_project',
					'update_project',
					'list_projects',
					// Place Management (for sites/plots).
					'create_place',
					'update_place',
					'list_places',
					'research_place',
					// Task Planning.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Documentation.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_excel',
					// Communication.
					'send_group_email',
					'newsletter_add_subscriber',
					// Calendar.
					'create_event',
					'update_event',
					'get_calendar_view',
					'create_google_calendar_event',
					// Forms & Surveys.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
				),
			),

			'emergency_services'        => array(
				'name'        => __( '🚨 Emergency Services & Public Safety', 'mcp-ai-wpoos' ),
				'description' => __( 'Emergency response, disaster management, public safety, and first responders', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Emergency & Disaster Data.
					'get_gdacs_events',
					'get_nhc_active_storms',
					'reliefweb_reports',
					// Weather & Hazards.
					'get_open_meteo_forecast',
					// Location Services.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Communication & Alerts.
					'send_group_email',
					'schedule_notify_sms',
					'send_telegram_message',
					'send_whatsapp_message',
					// Incident Management.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					// Task Coordination.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Project Management (operations).
					'create_project',
					'update_project',
					'list_projects',
					// Documentation & Reporting.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_word_document',
					// Forms & Data Collection.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Research & Intelligence.
					'web_search',
					'search_content',
					// Image Analysis (for damage assessment).
					'vision_object_localization',
					'generate_image_caption',
					'analyze_video',
					// Charts & Reporting.
					'create_chart',
					'analyze_data_patterns',
					// Calendar.
					'create_google_calendar_event',
					// Team Coordination.
					'create_agent_team',
					'delegate_to_agent',
					'execute_workflow',
				),
			),

			'maritime_aviation'         => array(
				'name'        => __( '✈️ Maritime, Aviation & Transportation', 'mcp-ai-wpoos' ),
				'description' => __( 'Maritime operations, aviation, logistics, and transportation management', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Weather & Environmental.
					'get_open_meteo_forecast',
					'get_nhc_active_storms',
					'get_gdacs_events',
					// Location & Navigation.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					'analyze_geospatial',
					// Scheduling & Operations.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					'export_calendar_ics',
					// Task Management.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					// Communication.
					'send_group_email',
					'schedule_notify_sms',
					'send_telegram_message',
					'send_whatsapp_message',
					// Documentation & Logs.
					'save_post',
					'create_post',
					'pro_pdf_document',
					'pro_word_document',
					'pro_excel',
					// Forms & Checklists.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Safety & Compliance.
					'check_site_security',
					'get_site_health',
					// Research & Information.
					'web_search',
					'search_content',
					// Analytics & Reporting.
					'create_chart',
					'analyze_data_patterns',
					// Crew Management.
					'create_agent_team',
					'delegate_to_agent',
				),
			),

			'food_service'              => array(
				'name'        => __( '👨‍🍳 Food Service & Culinary', 'mcp-ai-wpoos' ),
				'description' => __( 'Restaurant management, culinary arts, food service, and hospitality', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Menu & Recipe Management.
					'save_post',
					'create_post',
					'search_content',
					// Image Generation (for menu/food photos).
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					'vision_product_search',
					// E-commerce (online ordering).
					'get_woo_products',
					'create_woo_product',
					'get_woo_recent_orders',
					// Inventory & Supplies.
					'create_project',
					'update_project',
					'list_projects',
					// Task Management.
					'create_task',
					'update_task',
					'list_tasks',
					// Scheduling & Staffing.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Customer Communication.
					'send_group_email',
					'send_whatsapp_message',
					'schedule_notify_sms',
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Reservations & Bookings.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Social Media & Marketing.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_google_business_update',
					'get_facebook_instagram_insights',
					'get_google_business_insights',
					// Reviews & Reputation.
					'moderate_content',
					'analyze_comment_content',
					// Analytics.
					'google_analytics_report',
					'create_chart',
					// Document Management.
					'pro_pdf_document',
					'search_attachments',
					// Research (recipes, trends).
					'web_search',
					'search_content',
					// Location Services.
					'geocode_address',
					'search_places',
					'post_google_business_update',
				),
			),

			'skilled_trades'            => array(
				'name'        => __( '🔧 Skilled Trades & Construction', 'mcp-ai-wpoos' ),
				'description' => __( 'Electricians, plumbers, HVAC, carpenters, welders, and skilled tradespeople', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Project Management.
					'create_project',
					'update_project',
					'list_projects',
					'research_project',
					// Task & Job Management.
					'create_task',
					'update_task',
					'list_tasks',
					'create_task_plan',
					// Scheduling & Appointments.
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					'create_google_calendar_event',
					// Cost Estimation.
					'estimate_construction_cost',
					'generate_material_schedule',
					// Building Codes & Compliance.
					'check_building_code_compliance',
					// Documentation & Photos.
					'save_post',
					'create_post',
					'search_attachments',
					'generate_image_caption',
					// Image Processing (before/after photos).
					'resize_image',
					'crop_image',
					// Invoicing & Payments.
					'pro_pdf_document',
					'pro_excel',
					// Communication.
					'send_group_email',
					'send_whatsapp_message',
					'schedule_notify_sms',
					// Location Services.
					'geocode_address',
					'search_places',
					'get_open_meteo_forecast',
					// Forms & Customer Data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Research & Technical Info.
					'web_search',
					'search_content',
					// Equipment & Inventory.
					'create_place',
					'update_place',
					'list_places',
					// Analytics & Reporting.
					'create_chart',
					// Social Media & Marketing.
					'post_facebook_instagram',
					'post_google_business_update',
				),
			),

			'travel_hospitality'        => array(
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

			'public_service'            => array(
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

			// =================================================================.
			// PROJECT MANAGEMENT & ORCHESTRATION
			// =================================================================.

			'autonomous_orchestration'  => array(
				'name'        => __( '🎯 Autonomous Orchestration', 'mcp-ai-wpoos' ),
				'description' => __( 'Task planning, autonomous sessions, health monitoring, and capacity management for continuous AI workflow loops', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination and memory.
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
					'store_agent_context',
					'retrieve_agent_memory',
					// Core orchestration tools (Base plugin).
					'create_task_plan',
					'update_task_plan',
					'get_task_plan',
					'manage_autonomous_session',
					'detect_completion_indicators',
					'check_exit_conditions',
					'analyze_loop_health',
					'get_session_status',
					'calculate_orchestration_capacity',
					// Template management (Pro addon).
					'create_template',
					'instantiate_template',
					'list_templates',
					'seed_template_library',
					// Research enhancement (Pro addon).
					'aggregate_research_data',
					'extract_structured_data',
					'convert_html_to_markdown',
					'generate_research_report',
					'analyze_data_patterns',
					'verify_information',
					// Supporting tools.
					'web_search',
					'deep_research',
					'search_content',
					'create_post',
					'save_post',
				),
			),

			'task_planning'             => array(
				'name'        => __( '📋 Task Planning', 'mcp-ai-wpoos' ),
				'description' => __( 'Create and manage task plans with progress tracking', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'create_task_plan',
					'update_task_plan',
					'get_task_plan',
					'create_template',
					'instantiate_template',
					'list_templates',
				),
			),

			'research_automation'       => array(
				'name'        => __( '🔍 Research Automation', 'mcp-ai-wpoos' ),
				'description' => __( 'Multi-source research with aggregation, verification, and professional report generation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination and memory.
					'store_agent_context',
					'retrieve_agent_memory',
					// Research enhancement.
					'aggregate_research_data',
					'extract_structured_data',
					'convert_html_to_markdown',
					'generate_research_report',
					'analyze_data_patterns',
					'verify_information',
					// Core research.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					// Data collection.
					'search_drive',
					'search_gmail',
					'get_jetengine_items',
				),
			),

			'workflow_monitoring'       => array(
				'name'        => __( '📊 Workflow Monitoring', 'mcp-ai-wpoos' ),
				'description' => __( 'Monitor autonomous sessions, health status, and system capacity', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'manage_autonomous_session',
					'get_session_status',
					'analyze_loop_health',
					'calculate_orchestration_capacity',
					'detect_completion_indicators',
					'check_exit_conditions',
				),
			),

			// NEW PRO TOOLKIT PRESETS (2026).
			// =================================================================.

			'business_analytics'        => array(
				'name'        => __( '📊 Business Analytics & BI', 'mcp-ai-wpoos' ),
				'description' => __( 'Advanced analytics, data warehousing, churn prediction, and business intelligence tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Analytics Toolkit (12 tools).
					'collect_custom_metrics',
					'data_warehouse_sync',
					'real_time_event_tracking',
					'generate_executive_dashboard',
					'cohort_analysis',
					'funnel_analysis',
					'attribution_modeling',
					'churn_prediction',
					'customer_segmentation_ml',
					'revenue_forecast',
					'create_custom_report',
					'export_analytics_api',
					// Related tools.
					'google_analytics_report',
					'quickbooks_report',
					'create_chart',
					'pro_excel',
					'get_profession_stats',
				),
			),

			'financial_planning'        => array(
				'name'        => __( '💰 Financial Planning & Wealth', 'mcp-ai-wpoos' ),
				'description' => __( 'Retirement planning, investment analysis, budget tracking, and personal finance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Retirement Planning (5 tools).
					'retirement_calculator',
					'ira_roth_comparison',
					'withdrawal_strategy_planner',
					'social_security_optimizer',
					'pension_analyzer',
					// Budget & Expense Tracking (5 tools).
					'budget_planner',
					'expense_tracker',
					'net_worth_calculator',
					'cash_flow_analyzer',
					'bank_account_sync',
					// Investment & Portfolio (5 tools).
					'portfolio_visualizer',
					'asset_allocation_planner',
					'investment_return_calculator',
					'rebalancing_analyzer',
					'tax_loss_harvesting_tracker',
					// Debt & Loan Management (3 tools).
					'debt_payoff_calculator',
					'mortgage_calculator',
					'credit_score_tracker',
					// Goal Planning & Savings (2 tools).
					'savings_goal_planner',
					'emergency_fund_calculator',
					// Financial Literacy (4 tools).
					'financial_health_score',
					'tax_estimator',
					'college_savings_calculator',
					'insurance_needs_analyzer',
				),
			),

			'multilingual_global'       => array(
				'name'        => __( '🌍 Multilingual & Global Content', 'mcp-ai-wpoos' ),
				'description' => __( 'Translation, localization, and multilingual content management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Translation Management (4 tools).
					'auto_translate_content',
					'translate_woocommerce_products',
					'translation_memory_search',
					'export_import_translations',
					// Localization (3 tools).
					'detect_content_language',
					'localize_dates_currencies',
					'rtl_content_optimization',
					// Quality Assurance (3 tools).
					'translation_quality_check',
					'find_untranslated_strings',
					'multilingual_seo_audit',
					// Related content tools.
					'save_post',
					'create_post',
					'search_content',
					'get_recent_posts',
				),
			),

			'video_production'          => array(
				'name'        => __( '🎬 Video Production & Editing', 'mcp-ai-wpoos' ),
				'description' => __( 'Video creation, editing, optimization, and production tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Video Creation (4 tools).
					'create_video_from_images',
					'add_watermark_to_video',
					'generate_video_captions',
					'merge_videos',
					// Video Editing (3 tools).
					'trim_video',
					'resize_video_resolution',
					'adjust_video_speed',
					// Video Optimization (3 tools).
					'compress_video',
					'convert_video_format',
					'optimize_for_platform',
					// Video Analysis (2 tools).
					'extract_video_metadata',
					'generate_video_thumbnails',
					// Related video tools.
					'generate_veo_video',
					'generate_sora_video',
					'check_video_status',
					'analyze_video',
					'extract_video_frames',
					'get_video_metadata',
					'generate_video_caption',
				),
			),

			'predictive_analytics'      => array(
				'name'        => __( '🔮 Predictive Analytics & ML', 'mcp-ai-wpoos' ),
				'description' => __( 'Machine learning, churn prediction, forecasting, and predictive modeling', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Predictive Analytics from Analytics Toolkit.
					'churn_prediction',
					'customer_segmentation_ml',
					'revenue_forecast',
					'cohort_analysis',
					'attribution_modeling',
					// Related ML/AI tools.
					'create_text_embeddings',
					'batch_embed_content',
					'semantic_content_search',
					'vectorize_image',
					'create_vector_store',
					'list_vector_stores',
					'get_vector_store',
					// Hugging Face datasets for ML.
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_statistics',
					'huggingface_recommended_datasets',
				),
			),

			'data_warehousing'          => array(
				'name'        => __( '🗄️ Data Warehousing & ETL', 'mcp-ai-wpoos' ),
				'description' => __( 'Data warehouse sync, ETL operations, and data integration tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Data Warehouse & Integration.
					'data_warehouse_sync',
					'export_analytics_api',
					'collect_custom_metrics',
					'real_time_event_tracking',
					// Batch operations.
					'create_batch',
					'list_batches',
					'get_batch_status',
					'monitor_batch',
					// Import/Export.
					'trigger_all_export',
					'trigger_all_import',
					'get_all_import_status',
					'list_all_export_templates',
					'list_all_import_templates',
					// File operations.
					'list_openai_files',
					'get_openai_file_details',
					// Data sources.
					'get_jetengine_items',
					'list_jetengine_rest_routes',
					'jetengine',
					'huggingface_dataset_get_rows',
					'huggingface_dataset_get_parquet',
				),
			),

			'content_creator_pro'       => array(
				'name'        => __( '🎥 Content Creator Pro', 'mcp-ai-wpoos' ),
				'description' => __( 'Complete content creation suite: writing, images, video, audio, and multilingual', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Content writing.
					'save_post',
					'create_post',
					'search_content',
					'get_recent_posts',
					'web_search',
					'deep_research',
					// Images.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					'generate_image_alt_text',
					'edit_gemini_image',
					'edit_openai_image',
					'resize_image',
					'crop_image',
					'remove_background',
					// Video.
					'create_video_from_images',
					'add_watermark_to_video',
					'generate_video_captions',
					'merge_videos',
					'trim_video',
					'compress_video',
					'generate_veo_video',
					'generate_sora_video',
					// Audio.
					'generate_music',
					'generate_openai_speech',
					'transcribe_openai_audio',
					// Translation.
					'auto_translate_content',
					'detect_content_language',
					// SEO & optimization.
					'get_rankmath_seo',
					'moderate_content',
				),
			),

			'saas_platform'             => array(
				'name'        => __( '🚀 SaaS Platform', 'mcp-ai-wpoos' ),
				'description' => __( 'Complete SaaS toolkit: analytics, churn prediction, billing, and customer management', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Analytics & insights.
					'generate_executive_dashboard',
					'cohort_analysis',
					'funnel_analysis',
					'attribution_modeling',
					'collect_custom_metrics',
					'real_time_event_tracking',
					// Churn & retention.
					'churn_prediction',
					'customer_segmentation_ml',
					'revenue_forecast',
					// Reporting.
					'create_custom_report',
					'export_analytics_api',
					'create_chart',
					// User management.
					'get_user_info',
					// Communication.
					'send_group_email',
					'schedule_notify_sms',
					'send_telegram_message',
					// Billing & payments.
					'payhere_get_payment',
					// Financial planning.
					'financial_health_score',
					'budget_planner',
					// Forms & data.
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
				),
			),

			'media_templates'           => array(
				'name'        => __( '🎬 Media Templates & Collections', 'mcp-ai-wpoos' ),
				'description' => __( 'Media template management and collection processing tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Media Templates (Pro).
					'create_media_template',
					'list_media_templates',
					'apply_media_template',
					// Media Collections (Pro).
					'create_media_collection',
					'process_collection',
					'apply_collection_template',
					// Video processing.
					'transcode_video',
					'extract_video_frames',
					'get_video_metadata',
					'analyze_video',
					// Image processing.
					'optimize_image_sharp',
					'remove_background',
					'resize_image',
					'crop_image',
					'rotate_image',
					'convert_image_format',
					// Template management.
					'instantiate_template',
					'create_template',
					'list_templates',
					'seed_template_library',
				),
			),

			'music_production'          => array(
				'name'        => __( '🎵 Music & Audio Production', 'mcp-ai-wpoos' ),
				'description' => __( 'Music generation, jukebox management, and audio production tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Jukebox (Pro).
					'generate_jukebox_music',
					'check_jukebox_status',
					// Audio generation.
					'generate_music',
					'generate_music_validated',
					'generate_openai_speech',
					'generate_openai_speech_validated',
					// Audio transcription.
					'transcribe_openai_audio',
					'transcribe_openai_audio_validated',
				),
			),

			'research_tools'            => array(
				'name'        => __( '🔬 Research & Analysis', 'mcp-ai-wpoos' ),
				'description' => __( 'Research tools for posts, products, projects, places, policies, and more', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for research contexts.
					'store_agent_context',
					'retrieve_agent_memory',
					// Research tools (Pro).
					'research_post',
					'research_product',
					'research_project',
					'research_page',
					'research_place',
					'research_policy',
					'research_eca',
					'research_quiz_topic',
					// Data extraction.
					'extract_structured_data',
					'analyze_data_patterns',
					'aggregate_research_data',
					'generate_research_report',
					'verify_information',
					// Deep research.
					'deep_research',
					'web_search',
					'web_browser',
					'semantic_content_search',
				),
			),

			'math_science'              => array(
				'name'        => __( '🧮 Mathematics & Science', 'mcp-ai-wpoos' ),
				'description' => __( 'Mathematical computation, equation solving, and scientific tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Mathematical operations (Pro).
					'solve_equation',
					'simplify_expression',
					'calculate_derivative',
					'calculate_integral',
					'graph_function',
					'render_math_equation',
					'matrix_operations',
					// Data analysis.
					'analyze_data_patterns',
					'analyze_geospatial',
					// Visualization.
					'create_chart',
				),
			),

			'project_management'        => array(
				'name'        => __( '📋 Project & Task Management', 'mcp-ai-wpoos' ),
				'description' => __( 'Project planning, task management, and team coordination tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Project Management (Pro).
					'create_project',
					'update_project',
					'delete_project',
					'list_projects',
					'research_project',
					// Task Management (Pro).
					'create_task',
					'update_task',
					'delete_task',
					'list_tasks',
					'create_task_plan',
					'get_task_plan',
					'update_task_plan',
					// Event Management (Pro).
					'create_event',
					'update_event',
					'delete_event',
					'list_events',
					'get_calendar_view',
					'export_calendar_ics',
					// Templates.
					'create_template',
					'instantiate_template',
					'list_templates',
					// Coordination.
					'create_agent_team',
					'delegate_to_agent',
					'execute_workflow',
				),
			),

			'legal_compliance'          => array(
				'name'        => __( '⚖️ Legal & Policy Management', 'mcp-ai-wpoos' ),
				'description' => __( 'Policy management, legal research, and compliance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Policy Management (Pro).
					'create_policy',
					'update_policy',
					'delete_policy',
					'get_policy',
					'list_policies',
					'search_policies',
					'research_policy',
					// Research & analysis.
					'web_search',
					'deep_research',
					'search_content',
					'semantic_content_search',
					'submit_document_prompt',
					'verify_information',
					// Document management.
					'save_post',
					'create_post',
					'search_attachments',
					'get_recent_posts',
					'convert_html_to_markdown',
					// File analysis.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
				),
			),

			'location_services'         => array(
				'name'        => __( '📍 Location & Place Management', 'mcp-ai-wpoos' ),
				'description' => __( 'Place management, geospatial analysis, and location-based tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Place Management (Pro).
					'create_place',
					'update_place',
					'delete_place',
					'get_place',
					'list_places',
					'research_place',
					'search_and_save_places',
					// Geospatial.
					'geocode_address',
					'search_places',
					'analyze_geospatial',
					'gemini_geospatial_query',
					// Weather & environment.
					'get_open_meteo_forecast',
					'get_gdacs_events',
					'get_nhc_active_storms',
				),
			),

			'developer_advanced'        => array(
				'name'        => __( '⚙️ Developer Advanced Tools', 'mcp-ai-wpoos' ),
				'description' => __( 'Advanced development, API integration, and system management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination for development workflows.
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
					'store_agent_context',
					'retrieve_agent_memory',
					// Generic integrations (Pro).
					'generic_rest_api',
					'remote_wp_connection',
					'web_browser',
					// Code tools (Pro).
					'format_code_prettier',
					// ERP Integration (Pro).
					'ezuite_erp',
					'ezuite_erp_get_products',
					// Session management (Pro).
					'manage_autonomous_session',
					'get_session_status',
					'check_exit_conditions',
					'detect_completion_indicators',
					'check_wp_cli',
					// Import/Export scheduling (Pro).
					'schedule_all_export',
					'schedule_all_import',
					'delete_all_export',
					'delete_all_import',
					// Email templates (Pro).
					'generate_email_template',
					// Architecture (Pro).
					'generate_architectural_drawing',
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
