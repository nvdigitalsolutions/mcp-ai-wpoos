<?php
/**
 * Tool Presets Helper - Updated 2026
 *
 * Comprehensive tool selection presets covering 870+ tools organized by
 * use case and profession type. Includes DeepSeek V4 agent coordination tools,
 * quiz management, media templates, music production, fantasy sports, webchat,
 * health vitals management, Shopify, registration management, appointment
 * scheduling, cloud storage, cross-platform messaging, Brevo email marketing,
 * Mailgun transactional email, listing image downloads, CRE debt & securitization,
 * and more. Clear All/Select All functionality included.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @updated 2026-03-16 - Full 760-tool coverage: added Shopify tools, registration management preset,
 *   appointment scheduling tools, cloud storage (iCloud/OneDrive), full cross-platform messaging
 *   (Discord/Slack/Teams/Apple Messages/Telegram/WhatsApp/Messenger), tool scaffolding & dev tools,
 *   PDF/Excel document tools, site builder section tools, regulatory/compliance tools, CRM email
 *   search, sales pipeline tools, health metrics logging, vitals import, MOH sync, social listening,
 *   competitor analysis, workflow CRUD, vault tools, and more across all presets.
 * @updated 2026-03-30 - Added 8 missing tools: Brevo email/contacts/stats, Mailgun email,
 *   Shopify Catalog, Facebook/Instagram/Google Maps image downloads to relevant presets.
 * @updated 2026-04-04 - Enhanced agent workflow presets with industry-standard patterns:
 *   supervisor, pipeline, swarm, hierarchical, and review/QA patterns. Added delegate_to_a2a_agent,
 *   deep_research, context lifecycle, task dependency tools to existing presets.
 *   Updated autonomous_orchestration with workflow rules and task dependencies.
 * @updated 2026-04-11 - Added CRE Debt & Securitization preset (58 tools), expanded education
 *   preset with 19 additional ECA management tools, added 8 missing financial planner tools,
 *   added research_blog_post, bulk_enroll_students, get_student_participation_summary,
 *   vehicle tools to relevant presets. Total preset tool coverage now 870+.
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for managing and rendering tool presets.
 *
 * @since 1.0.0
 * @updated 1.9.0 - Added agent coordination tools and profession-specific presets
 * @updated 2.0.0 - Added selected tools chips bar, auto-select from instructions/professions
 */
class WP_MCP_AI_Tool_Presets_Helper {

		/**
		 * Cached tool keyword index for auto-select matching.
		 *
		 * @var array|null
		 */
	protected static $tool_index = null;

		/**
		 * Get the tool presets configuration.
		 *
		 * Updated 2026-04-11 to include all 870+ current tools organized by:
		 * - Core functionality (AI/ML, Media, Content, etc.)
		 * - Profession categories (Healthcare, Legal, Education, etc.)
		 * - Specialized workflows (Quiz Management, Media Templates, Music Production)
		 * - Advanced tools (Math/Science, Research, Project Management)
		 * - Agentic workflows (including agent coordination tools)
		 * - Registration & Compliance Management
		 * - CRE Debt & Securitization (CMBS, underwriting, originations, fund management)
		 * - Full cross-platform messaging (Discord, Slack, Teams, Apple Messages, etc.)
		 * - Shopify e-commerce, cloud storage, tool scaffolding, and more
		 *
		 * @return array Array of presets with name, description, and tools.
		 */
	public static function get_presets() {
		$presets = array(
			// =================================================================.
			// ESSENTIALS — Layered foundation presets.
			// Stack Essentials (Internal) + Essentials (External) + a
			// profession preset to build a complete tool set without
			// duplication. These tools were previously copy-pasted into
			// 15–31 different profession presets.
			// =================================================================.

			'essentials_internal'       => array(
				'name'        => __( '📦 Essentials — Internal', 'mcp-ai-wpoos' ),
				'description' => __( 'Core WordPress tools that work without external API keys: content, users, email, agent memory, tasks, projects, events, forms, and charts', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Content management.
					'search_content',
					'search_attachments',
					'get_recent_posts',
					'save_post',
					'create_post',
					// Users & system.
					'get_user_info',
					// Communication.
					'send_group_email',
					// Visualization.
					'create_chart',
					// Agent memory & context (WP-stored).
					'store_agent_context',
					'wake_up_context',
					'retrieve_agent_memory',
					// Task planning.
					'create_task_plan',
					'update_task_plan',
					'get_task_plan',
					// Forms & data collection.
					'get_all_form_submissions',
					'get_jetformbuilder_forms',
					'get_jetformbuilder_submissions',
					// Project management.
					'create_project',
					'update_project',
					'list_projects',
					// Task management.
					'create_task',
					'update_task',
					'list_tasks',
					// Events & calendar (WP-internal).
					'create_event',
					'update_event',
					'list_events',
					'get_calendar_view',
					// Human-in-the-loop.
					'wait_for_user',
					// Agent coordination (WP-internal).
					'create_agent_team',
					'delegate_to_agent',
					'aggregate_agent_results',
				),
			),

			'essentials_external'       => array(
				'name'        => __( '🌐 Essentials — External', 'mcp-ai-wpoos' ),
				'description' => __( 'Broadly useful tools that require external API keys: web search, AI research, image generation, geocoding, calendar sync, email/SMS, and document generation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Search & research.
					'web_search',
					'deep_research',
					'semantic_content_search',
					// AI image generation.
					'generate_openai_image',
					'generate_gemini_image',
					'generate_image_caption',
					// Document generation.
					'pro_pdf_document',
					'pro_excel',
					// Calendar (Google API).
					'create_google_calendar_event',
					// Location services.
					'geocode_address',
					'search_places',
					'gemini_geospatial_query',
					// Messaging & notifications.
					'send_whatsapp_message',
					'schedule_notify_sms',
					// Content moderation & analysis.
					'moderate_content',
					'submit_document_prompt',
					'analyze_data_patterns',
					// Weather.
					'get_open_meteo_forecast',
				),
			),

			// =================================================================.
			// CORE FUNCTIONALITY PRESETS
			// =================================================================.

			'agentic_workflow'          => array(
				'name'        => __( '🤖 Agentic Workflow', 'mcp-ai-wpoos' ),
				'description' => __( 'Multi-agent orchestration tools for team composition, delegation, A2A communication, memory management, and result aggregation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Human-in-the-loop.
					'wait_for_user',
					// Agent coordination & delegation.
					'delegate_to_a2a_agent',
					// Agent memory & context management.
					'mine_agent_memory',
					'manage_context_lifecycle',
					'prioritize_context',
					'memory_audit_trail',
					// Workflow execution & validation.
					'execute_workflow',
					'check_workflow_health',
					'validate_workflow',
					'visualize_workflow_metrics',
					// Supporting tools for agentic operations.
					'list_professions',
					'get_profession',
					'get_profession_stats',
					'save_profession',
					'create_assistant',
					'probe_chat',
					'query_mesh_intelligent',
					// Continual harness (self-improving agent system).
					'evolve_harness',
					'apply_prompt_cue',
					'list_prompt_cues',
					'record_reflection',
					'retrieve_with_provenance',
					'scope_memory',
					'select_prompt_cue',
					'self_consistency_vote',
					// Memory provenance & recall.
					'trace_memory_provenance',
					'recall_memory',
					// Gemini managed agents.
					'run_gemini_managed_agent',
					// Skill loading.
					'load_skill',
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
					'analyze_comment_content',
					// Client-side AI operations (NEW).
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
					// File preparation for vector stores.
					'prepare_file_for_vector_store',
					// Memory operations.
					'recall_memory',
					'trace_memory_provenance',
					// Gemini managed agents.
					'run_gemini_managed_agent',
				),
			),

			'media_generation'          => array(
				'name'        => __( '🎨 Media Generation', 'mcp-ai-wpoos' ),
				'description' => __( 'Image, video, and audio generation tools across multiple AI providers', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image generation.
					'cloudflareai_text_to_image',
					// Image generation (Pro).
					'generate_image_ai',
					'generate_image_variations',
					'image_inpainting',
					'text_to_image_prompt_optimizer',
					// Image editing.
					'edit_gemini_image',
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
					'image_alt_text_optimizer',
					'vision_object_localization',
					'vision_product_search',
					// Video generation.
					'generate_veo_video',
					'generate_sora_video',
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
					'generate_openai_speech',
					'transcribe_openai_audio',
					'analyze_image',
					'extract_image_text',
					'generate_cloudflareai_image',
					// Image downloading from external platforms (Pro).
					'download_facebook_page_images',
					'download_instagram_page_images',
					'download_google_maps_images',
					// Harmonization Sub-Toolkit (Pro - Image Production).
					'generate_scene_background',
					'adapt_background_for_subject',
					'outpaint_background',
					'refine_subject_matte',
					'auto_clean_white_background',
					'harmonize_color',
					'relight_subject',
					'generate_shadow',
					'generate_reflection',
					'refine_composite_boundary',
					'analyze_scene_lighting',
					'suggest_placement',
					'harmonize_image_into_background',
					'harmonize_batch',
					// Omni video (Gemini).
					'generate_omni_video',
					'edit_omni_video',
				),
			),

			'content_writing'           => array(
				'name'        => __( '✍️ Content Writing', 'mcp-ai-wpoos' ),
				'description' => __( 'Tools for creating, managing, and optimizing content, posts, and pages', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Content search & retrieval.
					'get_post',
					'get_post_type_schema',
					// Content creation.
					'delete_post',
					// Content planning (Pro).
					'create_content_calendar',
					'generate_post_ideas',
					// Content optimization.
					'generate_post_excerpt',
					'generate_cover_letter',
					'auto_categorize_content',
					'suggest_internal_links',
					'content_freshness_checker',
					// Content recommendations.
					'content_recommendation_engine',
					// Research.
					'research_blog_post',
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
					'generate_image_alt_text',
					// Quality assurance.
					'moderate_comments',
					'analyze_comment_content',
				),
			),

			'ecommerce'                 => array(
				'name'        => __( '🛒 E-commerce', 'mcp-ai-wpoos' ),
				'description' => __( 'WooCommerce, Shopify, product management, and e-commerce operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// WooCommerce.
					'get_woo_recent_orders',
					'get_woo_products',
					'create_woo_product',
					'woo_orders',
					'woo_products',
					'woo_coupons',
					'woo_customers',
					// Shopify (Pro).
					'shopify_products',
					'shopify_orders',
					'shopify_customers',
					'shopify_inventory',
					'shopify_catalog',
					// Advanced product management (Pro).
					'create_product_advanced',
					'create_discount_campaign',
					'bulk_update_products',
					'bulk_order_status_update',
					'abandoned_cart_recovery',
					'refund_order_advanced',
					'upsell_recommendations',
					'process_order_workflow',
					'get_order_analytics',
					// Product operations.
					'product_actualization',
					'scrape_product',
					'lookup_product_price',
					'crawl4ai_price_lookup',
					'vision_product_search',
					// Inventory management (Pro).
					'sync_product_inventory',
					'inventory_forecast',
					'track_inventory_movement',
					'low_stock_alert_automation',
					// Regulated product management (Pro).
					'create_reg_product',
					'get_reg_product',
					'list_reg_products',
					'update_reg_product',
					'delete_reg_product',
					'duplicate_reg_product',
					'search_reg_products',
					'validate_reg_product',
					'validate_inci_ingredients',
					// Import/Export products.
					'import_products_csv',
					'import_products_from_excel',
					'export_products_to_excel',
					'export_products_report',
					// Translation for e-commerce.
					'translate_woocommerce_products',
					'auto_translate_content',
					'detect_content_language',
					// Analytics & insights.
					'churn_prediction',
					'customer_segmentation_ml',
					'customer_lifetime_value',
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
					// Import/Export (global).
					'get_import_duty',
					'check_hs_code',
					'get_all_import_status',
					'list_all_export_templates',
					'list_all_import_templates',
					'trigger_all_export',
					'trigger_all_import',
					// Payments.
					'payhere_get_payment',
					// Remote connections.
					'remote_wp_connection',
				),
			),

			'site_management'           => array(
				'name'        => __( '⚙️ Site Management', 'mcp-ai-wpoos' ),
				'description' => __( 'WordPress core management, page builder sections, templates, and system operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Site information.
					'get_site_summary',
					'get_system_logs',
					'get_update_status',
					'get_site_health',
					'get_environment_status',
					'check_site_security',
					'research_site_best_practices',
					// Caching.
					'purge_cache',
					'purge_cloudflare_cache',
					'purge_varnish_cache',
					// Cron jobs.
					'create_cron_job',
					'list_cron_jobs',
					'get_cron_job',
					'delete_cron_job',
					// Plugins & themes.
					'install_and_activate_plugin',
					'install_and_activate_theme',
					'scaffold_theme_structure',
					// Options.
					'update_option',
					// Multi-site.
					'site_creator',
					// Page builder sections (Pro).
					'create_hero_section',
					'create_cta_section',
					'create_homepage_layout',
					'create_service_pages',
					'create_footer_widget',
					'create_custom_widget',
					'build_navigation_menu',
					'build_about_page',
					'build_contact_section',
					'build_testimonial_section',
					'generate_landing_page',
					'generate_blog_layout',
					'generate_feature_section',
					'generate_gallery_section',
					'generate_sidebar_widget',
					// Template management (Pro).
					'import_site_template',
					'save_site_template',
					'export_template_kit',
					'suggest_template_patterns',
					'auto_optimize_images',
					// Utility.
					'toolkit_cpt',
					// Remote connections.
					'remote_wp_connection',
					'query_remote_site',
				),
			),

			'seo_marketing'             => array(
				'name'        => __( '📈 SEO & Marketing', 'mcp-ai-wpoos' ),
				'description' => __( 'SEO analysis, social media management, competitor analysis, and marketing automation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// SEO tools.
					'seo_meta_optimizer',
					'get_rankmath_seo',
					'multilingual_seo_audit',
					// Content optimization for SEO.
					'generate_post_excerpt',
					'suggest_internal_links',
					'content_freshness_checker',
					// Image SEO.
					'generate_image_alt_text',
					'image_alt_text_optimizer',
					// Performance optimization.
					'performance_optimizer_assistant',
					'responsive_image_validator',
					// Research & competitor analysis (Pro).
					'analyze_competitor_sites',
					'competitor_analysis',
					// Social media posting.
					'post_facebook_instagram',
					'post_linkedin_update',
					'post_tiktok_video',
					'post_google_business_update',
					'post_to_multiple_platforms',
					// Content scheduling (Pro).
					'schedule_social_post',
					'bulk_schedule_posts',
					'create_content_calendar',
					// Social listening & trends (Pro).
					'social_listening_trends',
					'track_hashtag_performance',
					'influencer_identification',
					'monitor_mentions_replies',
					// Social insights.
					'get_facebook_instagram_insights',
					'get_linkedin_insights',
					'get_tiktok_insights',
					'get_google_business_insights',
					'get_cross_platform_analytics',
					// Analytics.
					'google_analytics_report',
					// Reporting (Pro).
					'sales_performance_dashboard',
					'generate_pipeline_report',
					'generate_country_performance',
					// Messaging.
					'send_telegram_message',
					// Email.
					'search_gmail',
					// CRM email search (Pro).
					'crm_email_search_leads',
					'crm_email_search_correspondence',
					// Newsletter.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_emails',
					'newsletter_get_subscriber_stats',
					'newsletter_get_subscribers',
					'newsletter_unsubscribe',
					// Google Site Kit integration.
					'sitekit_get_adsense',
					'sitekit_get_analytics',
					'sitekit_get_pagespeed',
					'sitekit_get_search_console',
					// Social media image downloads (Pro).
					'download_facebook_page_images',
					'download_instagram_page_images',
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
					'image_alt_text_optimizer',
				),
			),

			'development'               => array(
				'name'        => __( '💻 Development', 'mcp-ai-wpoos' ),
				'description' => __( 'Code management, tool scaffolding, CLI operations, and technical development tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Code snippets.
					'create_wpcode_snippet',
					// Tool scaffolding & generation (Pro).
					'generate_tool_scaffold',
					'generate_tool_logic',
					'generate_tool_parameters',
					'generate_tool_documentation',
					'generate_tool_tests',
					'refactor_tool_code',
					'validate_tool_schema',
					'analyze_tool_security',
					'check_tool_compliance',
					'benchmark_tool_performance',
					// Code quality (Pro).
					'format_code_prettier',
					'automate_development_workflow',
					'search_codebase',
					'scaffold_theme_structure',
					// CLI & shell.
					'check_wp_cli',
					'execute_shell_command',
					// Version control (Pro).
					'git_operations',
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
					// Form submissions (all sources).
					'get_all_form_submissions',
					'get_elementor_form_submissions',
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
					// Erlang C call center analytics.
					'calculate_erlang_c',
					'erlang_c_concurrency_advisor',
					'erlang_c_queue_health',
					'erlang_c_staffing_advisor',
				),
			),

			'design_professional'       => array(
				'name'        => __( '🎨 Design Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Visual design, rendering, branding, AI-compositing/harmonization, creative production, and architectural design tools (Phases A–E).', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image generation.
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
					// Music.
					'generate_music',
					// Elementor.
					'get_elementor_templates',
					'import_elementor_template_kit',
					'elementor',
					// Architectural Design (Pro) — Phase A.
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
					// Architectural Design (Pro) — Phase B Regional Compliance & Analysis.
					'calculate_wind_loads',
					'calculate_seismic_loads',
					'validate_setbacks_and_far',
					'check_uda_planning_compliance',
					'check_jnbc_hurricane_compliance',
					'check_us_ibc_irc_compliance',
					'generate_compliance_dossier',
					'analyze_natural_ventilation',
					'analyze_daylight_and_solar_gain',
					'simulate_thermal_comfort',
					// Architectural Design (Pro) — Phase C Sustainability scoring & costing.
					'score_edge_certification',
					'score_leed_v4_certification',
					'generate_bill_of_quantities',
					'propose_value_engineering_options',
					// Architectural Design (Pro) — Phase D Interoperability & delivery.
					'import_dwg_floor_plan',
					'import_ifc_model',
					'export_to_ifc',
					'export_to_gbxml',
					'generate_bim_execution_plan',
					'manage_rfi_log',
					'manage_submittal_log',
					// Architectural Design (Pro) — Phase E Precedent library.
					'manage_architectural_precedents',
					'search_architectural_precedents',
					// Harmonization Sub-Toolkit (Pro - Image Production).
					'generate_scene_background',
					'adapt_background_for_subject',
					'outpaint_background',
					'refine_subject_matte',
					'auto_clean_white_background',
					'harmonize_color',
					'relight_subject',
					'generate_shadow',
					'generate_reflection',
					'refine_composite_boundary',
					'analyze_scene_lighting',
					'suggest_placement',
					'harmonize_image_into_background',
					'harmonize_batch',
				),
			),

			'crawling_scraping'         => array(
				'name'        => __( '🕷️ Web Crawling & Scraping', 'mcp-ai-wpoos' ),
				'description' => __( 'Web scraping, crawling, and data extraction tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'run_crawl4ai_job',
					'crawl4ai_price_lookup',
					'scrape_product',
					'query_remote_site',
					'product_actualization',
				),
			),

			'files_documents'           => array(
				'name'        => __( '📁 Files & Documents', 'mcp-ai-wpoos' ),
				'description' => __( 'File management, PDF/Excel tools, cloud storage, and document processing tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// OpenAI file operations.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// PDF tools (Pro).
					'add_watermark_to_pdf',
					'extract_pdf_text',
					'html_to_pdf',
					'merge_pdfs',
					'ocr_pdf_text',
					'pro_document_ocr',
					// Excel/Word generation (Pro).
					'generate_excel',
					'generate_pdf',
					'generate_pdf_dossier',
					'generate_word',
					'excel_data_export',
					'excel_data_import',
					'validate_excel_import',
					// Document management (Pro).
					'track_document_version',
					'validate_document_checklist',
					// Document Generation (Pro).
					'pro_word_document',
					'pro_excel_document',
					// Regulated documents (Pro).
					'get_reg_document',
					'list_reg_documents',
					'update_reg_document',
					'upload_reg_document',
					// Cloud storage (Pro).
					'get_icloud_drive_file',
					'list_icloud_drive_files',
					'upload_icloud_drive_file',
					'get_onedrive_file',
					'list_onedrive_files',
					'upload_onedrive_file',
					// File management.
					'manage_files',
					// Search operations.
					'search_drive',
					'search_gmail',
					// Paper Store flat-file storage.
					'paper_store_write',
					'paper_store_read',
					'paper_store_update',
					'paper_store_delete',
					'paper_store_list',
					'paper_store_search',
				),
			),

			'scheduling_automation'     => array(
				'name'        => __( '⏰ Scheduling & Automation', 'mcp-ai-wpoos' ),
				'description' => __( 'Cron jobs, appointment management, task scheduling, and workflow automation tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Cron job management.
					'create_cron_job',
					'list_cron_jobs',
					'get_cron_job',
					'delete_cron_job',
					// Appointment management (Pro).
					'create_appointment',
					'get_appointment_details',
					'update_appointment',
					'cancel_appointment',
					'reschedule_appointment',
					'check_availability',
					'get_available_slots',
					'set_availability_rules',
					'block_time_slot',
					'optimize_schedule',
					'generate_booking_link',
					'send_appointment_reminder',
					'send_booking_confirmation',
					// Calendar sync (Pro).
					'sync_google_calendar',
					'sync_outlook_calendar',
					// Calendar events.
					'export_calendar_ics',
					// Content scheduling (Pro).
					'bulk_schedule_posts',
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
					'schedule_all_export',
					'schedule_all_import',
				),
			),

			'authentication_security'   => array(
				'name'        => __( '🔐 Authentication & Security', 'mcp-ai-wpoos' ),
				'description' => __( 'Authentication tokens, security checks, vault access, and access control', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Token generation.
					'generate_auth0_token',
					'generate_simple_jwt_token',
					// Security checks.
					'check_site_security',
					'get_site_health',
					// Vault management (Pro).
					'vault_access',
					'vault_manage',
					// Tool security (Pro).
					'analyze_tool_security',
					'check_tool_compliance',
					// User security (Phase 4).
					'user_activity_auditor',
					'password_strength_analyzer',
					'login_security_monitor',
					'2fa_setup_assistant',
					'generate_password',
					// Content moderation.
					'analyze_comment_content',
				),
			),

			'communication_messaging'   => array(
				'name'        => __( '💬 Communication & Messaging', 'mcp-ai-wpoos' ),
				'description' => __( 'Email, SMS, webchat, and all messaging platforms (Discord, Slack, Teams, WhatsApp, Telegram, Apple Messages, Messenger)', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Email.
					'send_mailjet_email',
					'send_brevo_email',
					'send_mailgun_email',
					'send_outlook_mail',
					'get_outlook_messages',
					'search_gmail',
					// Email notifications (Pro).
					'configure_email_notifications',
					'get_notification_history',
					'send_status_change_notification',
					'auto_respond_messages',
					// Newsletter.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_emails',
					'newsletter_get_subscriber_stats',
					'newsletter_get_subscribers',
					'newsletter_unsubscribe',
					// SMS.
					'send_whatsapp_interactive',
					'send_whatsapp_media',
					'send_whatsapp_template',
					'get_whatsapp_messages',
					// Telegram (Pro).
					'send_telegram_message',
					'get_telegram_updates',
					'add_telegram_message_reaction',
					'manage_telegram_commands',
					'manage_telegram_webhook',
					// Discord (Pro).
					'send_discord_message',
					'add_discord_message_reaction',
					'create_discord_channel',
					'get_discord_channels',
					'get_discord_messages',
					'get_discord_voice_channel_members',
					// Slack (Pro).
					'send_slack_message',
					'create_slack_channel',
					'get_slack_channels',
					'get_slack_messages',
					// Microsoft Teams (Pro).
					'send_teams_message',
					'get_teams_channels',
					'get_teams_messages',
					// Apple Messages (Pro).
					'send_apple_message',
					'send_apple_message_group',
					'send_apple_message_interactive',
					'get_apple_messages',
					// Messenger (Pro).
					'send_messenger_message',
					'create_messenger_broadcast',
					'get_messenger_conversations',
					// Google Chat (Pro).
					'send_google_chat_message',
					'create_google_chat_space',
					'add_google_chat_space_member',
					'remove_google_chat_space_member',
					'list_google_chat_space_members',
					'get_google_chat_messages',
					'get_google_chat_spaces',
					// Twitter DMs (Pro).
					'send_twitter_dm',
					'get_twitter_dms',
					'manage_twitter_webhook',
					// Unified broadcast (Pro).
					'unified_channel_broadcast',
					// Webchat (Pro).
					'create_webchat_room',
					'list_webchat_rooms',
					'get_webchat_room',
					'get_webchat_status',
					'get_webchat_messages',
					'save_webchat_message',
					'send_webchat_message',
					// Mailjet contacts & stats (Pro).
					'manage_mailjet_contacts',
					'get_mailjet_statistics',
					// Brevo contacts & stats (Pro).
					'manage_brevo_contacts',
					'get_brevo_statistics',
					// Erlang C call center analytics.
					'calculate_erlang_c',
					'erlang_c_concurrency_advisor',
					'erlang_c_queue_health',
					'erlang_c_staffing_advisor',
				),
			),

			'assistant_management'      => array(
				'name'        => __( '🤖 Assistant Management', 'mcp-ai-wpoos' ),
				'description' => __( 'AI assistant creation, configuration, agent team management, and A2A delegation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'create_assistant',
					'probe_chat',
					'probe_remote_mcp',
					'query_mesh_intelligent',
					'list_professions',
					'get_profession',
					'save_profession',
					'delegate_to_a2a_agent',
					'manage_context_lifecycle',
					'execute_workflow',
					'validate_workflow',
					'check_workflow_health',
					// Skill loading.
					'load_skill',
				),
			),

			// =================================================================.
			// PROFESSION-SPECIFIC PRESETS
			// =================================================================.

			'healthcare'                => array(
				'name'        => __( '⚕️ Healthcare Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Medical, clinical, health vitals, and healthcare management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
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
					// Vital Signs & Care Management (Pro).
					'log_vital_signs',
					'track_vaccinations',
					'create_health_reminder',
					'manage_care_plan',
					// Health Metrics Logging (Pro).
					'log_health_metrics',
					// Vitals Import & Sync (Pro).
					'import_vitals',
					'sync_with_mohap',
					'sync_with_nmra',
					// Health Research & Data (Pro).
					'compile_health_research_data',
					'parse_health_information',
					'guide_health_record_creation',
					// FHIR Export (Pro).
					'export_fhir_data',
					// Images.
					'vision_object_localization',
				),
			),

			'legal'                     => array(
				'name'        => __( '⚖️ Legal Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Legal research, document management, regulatory compliance, and compliance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Regulatory management (Pro).
					'add_regulatory_requirement',
					'get_regulatory_requirements',
					'get_regulatory_updates',
					// Compliance (Pro).
					'check_document_expiry',
					'check_product_compliance',
					'generate_compliance_certificate',
					'generate_compliance_report',
					// File analysis.
					'list_openai_files',
					'get_openai_file_details',
					'analyze_file_suitability',
					// Communication.
					'search_gmail',
					// Security.
					'check_site_security',
				),
			),

			'education'                 => array(
				'name'        => __( '🎓 Education Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Educational content, course management, and learning tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
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
					'withdraw_student_eca',
					'research_eca',
					'sync_ecas_from_isams',
					'sync_eca_enrollments_from_isams',
					'sync_ecas_to_isams',
					'sync_ecas_from_socs',
					'import_ecas_csv',
					'export_eca_data',
					// ECA Attendance & Scheduling (Pro).
					'mark_eca_attendance',
					'get_eca_attendance_report',
					'get_eca_timetable',
					'set_eca_schedule',
					'check_eca_conflicts',
					'manage_eca_term',
					'manage_eca_waitlist',
					// ECA Notifications & Reports (Pro).
					'send_eca_notification',
					'configure_eca_notifications',
					'send_eca_parent_report',
					'generate_eca_participation_report',
					'generate_eca_analytics',
					'create_eca_workflow_rule',
					// Student bulk operations (Pro).
					'bulk_enroll_students',
					'get_student_participation_summary',
					// Task Management (Pro).
					'delete_task',
					// Research.
					'isams_query',
					// Media.
					'generate_openai_speech',
					'transcribe_openai_audio',
					'generate_video_caption',
					// Communication.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Calendar.
					'export_calendar_ics',
					// Moderation.
					'analyze_comment_content',
					// Registration management (Pro).
					'create_registration',
					'get_registration',
					'get_registration_timeline',
					'update_registration_status',
					'list_registrations',
					'list_registrations_by_country',
					'list_expiring_registrations',
					'export_registrations_to_excel',
					'import_registrations_from_excel',
					'submit_registration',
					'approve_registration',
					'renew_registration',
					'send_expiry_alerts',
					'generate_expiry_forecast',
				),
			),

			'finance_business'          => array(
				'name'        => __( '💼 Finance & Business', 'mcp-ai-wpoos' ),
				'description' => __( 'Financial analysis, business intelligence, invoicing, and reporting tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Financial Planning Toolkit tools.
					'financial_health_score',
					'budget_planner',
					'expense_tracker',
					'net_worth_calculator',
					'cash_flow_analyzer',
					'retirement_calculator',
					'tax_estimator',
					// Market Intelligence (Pro).
					'financial_search',
					'financial_news_aggregator',
					'financial_report_generator',
					'stock_data_fetcher',
					'market_forecast_analyzer',
					'market_sentiment_analyzer',
					// Analytics.
					'google_analytics_report',
					'quickbooks_report',
					'get_profession_stats',
					'revenue_forecast',
					'churn_prediction',
					'cohort_analysis',
					// Invoicing & reporting (Pro).
					'generate_invoice_pdf',
					'send_client_invoice',
					'generate_submission_pack',
					'generate_compliance_report',
					'generate_pdf_dossier',
					// Data collection.
					'get_jetengine_items',
					// E-commerce.
					'get_woo_recent_orders',
					'get_woo_products',
					'payhere_get_payment',
					// Communication.
					'search_gmail',
					// CRM email search (Pro).
					'crm_email_search_leads',
					'crm_email_search_correspondence',
					'crm_email_search_accounting',
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
					'mine_agent_memory',
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
					// Images & visualization.
					'vision_object_localization',
					// Disaster & environmental.
					'get_gdacs_events',
					'get_nhc_active_storms',
					'reliefweb_reports',
				),
			),

			'real_estate'               => array(
				'name'        => __( '🏠 Real Estate', 'mcp-ai-wpoos' ),
				'description' => __( 'Property management, listings, and real estate operations', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Images.
					'generate_image_alt_text',
					'vision_object_localization',
					// Video.
					'generate_veo_video',
					'analyze_video',
					// Communication.
					'search_gmail',
					// CRM email search (Pro).
					'crm_email_search_leads',
					'crm_email_search_correspondence',
					// SEO.
					'get_rankmath_seo',
					// Social media.
					'post_facebook_instagram',
					'post_google_business_update',
					// Listing images (Pro).
					'download_google_maps_images',
				),
			),

			'architect'                 => array(
				'name'        => __( '🏗️ Architect', 'mcp-ai-wpoos' ),
				'description' => __( 'Architectural design, planning, building codes (Sri Lanka UDA, Jamaica JNBC, US IBC/IRC), structural & sustainability analysis, interoperability (DWG/IFC/gbXML), and project delivery — full Phase A–E coverage.', 'mcp-ai-wpoos' ),
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
					'generate_architectural_drawing',
					// Site planning (Pro).
					'generate_site_plan',
					// Analysis & Compliance.
					'check_building_code_compliance',
					'analyze_structural_feasibility',
					'calculate_sustainability_metrics',
					// Estimation & Scheduling.
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
					// Phase B — Regional Compliance & Loads.
					'calculate_wind_loads',
					'calculate_seismic_loads',
					'validate_setbacks_and_far',
					'check_uda_planning_compliance',
					'check_jnbc_hurricane_compliance',
					'check_us_ibc_irc_compliance',
					'generate_compliance_dossier',
					// Phase B — Building physics analysis.
					'analyze_natural_ventilation',
					'analyze_daylight_and_solar_gain',
					'simulate_thermal_comfort',
					// Phase C — Sustainability certification & costing depth.
					'score_edge_certification',
					'score_leed_v4_certification',
					'generate_bill_of_quantities',
					'propose_value_engineering_options',
					// Phase D — Interoperability (DWG/IFC/gbXML).
					'import_dwg_floor_plan',
					'import_ifc_model',
					'export_to_ifc',
					'export_to_gbxml',
					// Phase D — Project delivery (BEP, RFI, submittals).
					'generate_bim_execution_plan',
					'manage_rfi_log',
					'manage_submittal_log',
					// Phase E — Precedent library + semantic search.
					'manage_architectural_precedents',
					'search_architectural_precedents',
					// Cross-discipline integration (Pro).
					'integrate_with_architect',
					// Visual Design & Rendering.
					'edit_gemini_image',
					'generate_veo_video',
					// Image Processing.
					'resize_image',
					'crop_image',
					'remove_background',
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
					// Geospatial Analysis.
					'analyze_geospatial',
					// Document Generation.
					'pro_word_document',
					// File Management.
					'list_openai_files',
					'get_openai_file_details',
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
					'delete_project',
					'research_project',
					// Task Management & Scheduling.
					'delete_task',
					// Documentation & Drawings (for review).
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					'generate_floor_plan',
					'generate_3d_model',
					// Communication & Team Coordination.
					'send_telegram_message',
					// Agent Coordination (for complex projects).
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
					'analyze_natural_ventilation',
					'analyze_daylight_and_solar_gain',
					'simulate_thermal_comfort',
					'score_edge_certification',
					'score_leed_v4_certification',
					// Documentation.
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					// Costing depth.
					'generate_bill_of_quantities',
					'propose_value_engineering_options',
					// Interoperability (CAD/BIM round-trip with architects).
					'import_dwg_floor_plan',
					'import_ifc_model',
					// Precedent library (mood-board / spec research).
					'search_architectural_precedents',
					// Research & Inspiration.
					'crawl4ai_price_lookup',
					// E-commerce (for furniture/decor).
					'get_woo_products',
					'create_woo_product',
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
					'generate_veo_video',
					'create_walkthrough_animation',
					// Documentation & Drawings.
					'generate_construction_drawings',
					'generate_detail_drawings',
					'export_architectural_documents',
					// Geospatial & Location Analysis.
					'analyze_geospatial',
					// Environmental & Weather Data.
					'get_gdacs_events',
					// Sustainability Analysis.
					'calculate_sustainability_metrics',
					'check_building_code_compliance',
					// Material & Cost Estimation.
					'generate_material_schedule',
					'estimate_construction_cost',
					'generate_construction_timeline',
					// Image Analysis (for site conditions).
					'vision_object_localization',
					// Image Processing.
					'resize_image',
					'crop_image',
					'remove_background',
				),
			),

			'urban_planner'             => array(
				'name'        => __( '🏙️ Urban Planner', 'mcp-ai-wpoos' ),
				'description' => __( 'Comprehensive planning, zoning, land use policy, and community development tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Geospatial Analysis & Mapping.
					'analyze_geospatial',
					// Data Analysis & Demographics.
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
					// Document Generation.
					'pro_word_document',
					// Project & Development Review.
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
					// Community Engagement Tools.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Visualization (for presentations).
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
					// Social Media.
					'post_facebook_instagram',
					'post_tiktok_video',
				),
			),

			'sales_crm'                 => array(
				'name'        => __( '💼 Sales & CRM Professional', 'mcp-ai-wpoos' ),
				'description' => __( 'Sales pipeline, CRM management, lead tracking, and customer relationships', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// CRM Tools (Pro).
					'manage_crm_contact',
					// Communication.
					'send_mailjet_email',
					'send_brevo_email',
					'send_mailgun_email',
					'search_gmail',
					'send_telegram_message',
					// Newsletter & Marketing.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					'newsletter_get_subscribers',
					// E-commerce Integration.
					'get_woo_products',
					'get_woo_recent_orders',
					'create_woo_product',
					// Analytics & Reporting.
					'revenue_forecast',
					'churn_prediction',
					'cohort_analysis',
					// Social Media Outreach.
					'post_facebook_instagram',
					'post_linkedin_update',
					'get_facebook_instagram_insights',
					'get_linkedin_insights',
					// Research & Prospecting.
					'crawl4ai_price_lookup',
					'scrape_product',
					'research_company',
					// Company management (Pro).
					'create_company',
					'get_companies',
					// CRM email search (Pro).
					'crm_email_search_leads',
					'crm_email_search_correspondence',
					'crm_email_search_accounting',
					// Client profile & communication (Pro).
					'create_client_profile',
					'client_communication_log',
					// Invoice & sales reporting (Pro).
					'generate_invoice_pdf',
					'send_client_invoice',
					'generate_pipeline_report',
					'generate_cost_analysis',
					'sales_performance_dashboard',
					// Document Management.
					'pro_word_document',
					// SEO & Marketing.
					'get_rankmath_seo',
					'google_analytics_report',
					// Brevo contacts (Pro).
					'manage_brevo_contacts',
					'get_brevo_statistics',
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
					// Document Generation.
					'pro_word_document',
					// Social Media Distribution.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_linkedin_update',
					// Asset Management.
					'list_openai_files',
					// Omni video (Gemini).
					'generate_omni_video',
					'edit_omni_video',
				),
			),

			'agriculture_environmental' => array(
				'name'        => __( '🌾 Agriculture & Environmental', 'mcp-ai-wpoos' ),
				'description' => __( 'Agriculture, environmental science, forestry, wildlife management, and sustainability', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Geospatial Analysis.
					'analyze_geospatial',
					// Weather & Climate.
					'get_gdacs_events',
					// Environmental Data.
					'get_nhc_active_storms',
					'reliefweb_reports',
					// Sustainability Analysis.
					'calculate_sustainability_metrics',
					// Research & Data.
					'huggingface_dataset_search',
					'huggingface_dataset_get_info',
					'huggingface_dataset_get_statistics',
					// Image Analysis (for crop/land assessment).
					'vision_object_localization',
					'analyze_video',
					// Place Management (for sites/plots).
					'create_place',
					'update_place',
					'list_places',
					'research_place',
					// Communication.
					'newsletter_add_subscriber',
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
					// Location Services.
					'analyze_geospatial',
					// Communication & Alerts.
					'send_telegram_message',
					// Documentation & Reporting.
					'pro_word_document',
					// Image Analysis (for damage assessment).
					'vision_object_localization',
					'analyze_video',
					// Team Coordination.
					'execute_workflow',
				),
			),

			'maritime_aviation'         => array(
				'name'        => __( '✈️ Maritime, Aviation & Transportation', 'mcp-ai-wpoos' ),
				'description' => __( 'Maritime operations, aviation, logistics, and transportation management', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Weather & Environmental.
					'get_nhc_active_storms',
					'get_gdacs_events',
					// Location & Navigation.
					'analyze_geospatial',
					// Scheduling & Operations.
					'export_calendar_ics',
					// Communication.
					'send_telegram_message',
					// Documentation & Logs.
					'pro_word_document',
					// Safety & Compliance.
					'check_site_security',
					'get_site_health',
				),
			),

			'food_service'              => array(
				'name'        => __( '👨‍🍳 Food Service & Culinary', 'mcp-ai-wpoos' ),
				'description' => __( 'Restaurant management, culinary arts, food service, and hospitality', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Image Generation (for menu/food photos).
					'vision_product_search',
					// E-commerce (online ordering).
					'get_woo_products',
					'create_woo_product',
					'get_woo_recent_orders',
					// Customer Communication.
					'newsletter_add_subscriber',
					'newsletter_create_email',
					// Social Media & Marketing.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_google_business_update',
					'get_facebook_instagram_insights',
					'get_google_business_insights',
					// Reviews & Reputation.
					'analyze_comment_content',
					// Analytics.
					'google_analytics_report',
					// Location Services.
					'post_google_business_update',
				),
			),

			'skilled_trades'            => array(
				'name'        => __( '🔧 Skilled Trades & Construction', 'mcp-ai-wpoos' ),
				'description' => __( 'Electricians, plumbers, HVAC, carpenters, welders, and skilled tradespeople', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Project Management.
					'research_project',
					// Cost Estimation.
					'estimate_construction_cost',
					'generate_material_schedule',
					// Building Codes & Compliance.
					'check_building_code_compliance',
					// Image Processing (before/after photos).
					'resize_image',
					'crop_image',
					// Equipment & Inventory.
					'create_place',
					'update_place',
					'list_places',
					// Vehicle Estimates (Pro).
					'vehicle_repair_estimate',
					'vehicle_cleaning_estimate',
					'vin_decode',
					// Social Media & Marketing.
					'post_facebook_instagram',
					'post_google_business_update',
				),
			),

			'travel_hospitality'        => array(
				'name'        => __( '✈️ Travel & Hospitality', 'mcp-ai-wpoos' ),
				'description' => __( 'Tourism, hospitality, and travel industry tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Communication.
					'newsletter_add_subscriber',
					// Social media.
					'post_facebook_instagram',
					'post_tiktok_video',
					'post_google_business_update',
					// Reviews & moderation.
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
					// Communication.
					'send_telegram_message',
					// Security.
					'check_site_security',
				),
			),

			// =================================================================.
			// PROJECT MANAGEMENT & ORCHESTRATION
			// =================================================================.

			'autonomous_orchestration'  => array(
				'name'        => __( '🎯 Autonomous Orchestration', 'mcp-ai-wpoos' ),
				'description' => __( 'Task planning, autonomous sessions, health monitoring, capacity management, and workflow rules for continuous AI workflow loops', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination and memory.
					'delegate_to_a2a_agent',
					'mine_agent_memory',
					'manage_context_lifecycle',
					'prioritize_context',
					// Core orchestration tools (Base plugin).
					'manage_autonomous_session',
					'detect_completion_indicators',
					'check_exit_conditions',
					'analyze_loop_health',
					'get_session_status',
					'calculate_orchestration_capacity',
					// Workflow execution & validation.
					'execute_workflow',
					'validate_workflow',
					'check_workflow_health',
					'visualize_workflow_metrics',
					// Workflow rules (Pro).
					'create_workflow_rule',
					'update_workflow_rule',
					'delete_workflow_rule',
					'list_workflow_rules',
					// Task dependencies (Pro).
					'add_task_dependency',
					'get_task_dependencies',
					'remove_task_dependency',
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
					'verify_information',
					// Continual harness (self-improving agent system).
					'evolve_harness',
					'apply_prompt_cue',
					'list_prompt_cues',
					'record_reflection',
					'retrieve_with_provenance',
					'scope_memory',
					'select_prompt_cue',
					'self_consistency_vote',
					// Memory operations.
					'trace_memory_provenance',
					'recall_memory',
					// Gemini managed agents.
					'run_gemini_managed_agent',
				),
			),

			'task_planning'             => array(
				'name'        => __( '📋 Task Planning', 'mcp-ai-wpoos' ),
				'description' => __( 'Create and manage task plans with progress tracking and dependency management', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Task dependency management.
					'add_task_dependency',
					'get_task_dependencies',
					'remove_task_dependency',
					'delete_task',
					// Template management.
					'create_template',
					'instantiate_template',
					'list_templates',
				),
			),

			'research_automation'       => array(
				'name'        => __( '🔍 Research Automation', 'mcp-ai-wpoos' ),
				'description' => __( 'Multi-source research with aggregation, verification, professional report generation, and crawling', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination and memory.
					'mine_agent_memory',
					// Research enhancement.
					'aggregate_research_data',
					'extract_structured_data',
					'convert_html_to_markdown',
					'generate_research_report',
					'verify_information',
					// Core research.
					'run_crawl4ai_job',
					'scrape_product',
					// Data collection.
					'search_drive',
					'search_gmail',
					'get_jetengine_items',
				),
			),

			'workflow_monitoring'       => array(
				'name'        => __( '📊 Workflow Monitoring', 'mcp-ai-wpoos' ),
				'description' => __( 'Monitor autonomous sessions, health status, workflow rules, capacity, and execution logs', 'mcp-ai-wpoos' ),
				'tools'       => array(
					'manage_autonomous_session',
					'get_session_status',
					'analyze_loop_health',
					'calculate_orchestration_capacity',
					'detect_completion_indicators',
					'check_exit_conditions',
					// Workflow validation.
					'check_workflow_health',
					'validate_workflow',
					'visualize_workflow_metrics',
					// Workflow rules (Pro).
					'create_workflow_rule',
					'update_workflow_rule',
					'delete_workflow_rule',
					'list_workflow_rules',
					'test_workflow_rule',
					'get_workflow_execution_log',
				),
			),

			// =================================================================.
			// AGENT WORKFLOW PATTERNS (Industry Standard - 2026).
			// Based on supervisor, pipeline, swarm, and hierarchical patterns.
			// =================================================================.

			'agent_supervisor'          => array(
				'name'        => __( '👔 Supervisor Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Central supervisor agent assigns, tracks, and aggregates sub-agent work with quality control and escalation paths', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Supervisor coordination.
					'delegate_to_a2a_agent',
					// Task management & tracking.
					'add_task_dependency',
					'get_task_dependencies',
					// Session & health oversight.
					'manage_autonomous_session',
					'get_session_status',
					'analyze_loop_health',
					'check_exit_conditions',
					'detect_completion_indicators',
					// Quality control.
					'validate_workflow',
					'verify_information',
					'validate_reasoning_chain',
					// Agent memory for coordination state.
					'prioritize_context',
				),
			),

			'agent_pipeline'            => array(
				'name'        => __( '🔗 Pipeline Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Sequential agent assembly line where each agent refines output from the previous step with stage validation', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Pipeline orchestration.
					'execute_workflow',
					// Stage tracking & validation.
					'add_task_dependency',
					'get_task_dependencies',
					'detect_completion_indicators',
					'check_exit_conditions',
					// Data transformation between stages.
					'extract_structured_data',
					'convert_html_to_markdown',
					// Workflow health.
					'check_workflow_health',
					'validate_workflow',
					'visualize_workflow_metrics',
				),
			),

			'agent_swarm'               => array(
				'name'        => __( '🐝 Swarm Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Autonomous parallel agents work independently and aggregate results for search, analysis, and simulation tasks', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Swarm coordination.
					'delegate_to_a2a_agent',
					// Parallel data collection.
					'run_crawl4ai_job',
					'scrape_product',
					// Result aggregation & analysis.
					'aggregate_research_data',
					'extract_structured_data',
					'verify_information',
					'generate_research_report',
					// Health & capacity.
					'calculate_orchestration_capacity',
					'analyze_loop_health',
				),
			),

			'agent_hierarchical'        => array(
				'name'        => __( '🏛️ Hierarchical Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Multi-level manager-specialist agent relationships with escalation, delegation depth, and structured reporting', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Hierarchical team management.
					'delegate_to_a2a_agent',
					// Multi-level task planning.
					'add_task_dependency',
					'get_task_dependencies',
					// Workflow management at each level.
					'execute_workflow',
					'create_workflow_rule',
					'update_workflow_rule',
					'list_workflow_rules',
					// Context passing between levels.
					'manage_context_lifecycle',
					'prioritize_context',
					// Session monitoring across hierarchy.
					'manage_autonomous_session',
					'get_session_status',
					'check_workflow_health',
					'validate_workflow',
					'visualize_workflow_metrics',
					'calculate_orchestration_capacity',
					// Reporting up the chain.
					'generate_research_report',
				),
			),

			'agent_review_qa'           => array(
				'name'        => __( '✅ Review & QA Agent', 'mcp-ai-wpoos' ),
				'description' => __( 'Quality assurance and review agent pattern for output validation, compliance checking, and error correction', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Quality validation.
					'validate_workflow',
					'validate_reasoning_chain',
					'verify_information',
					'check_workflow_health',
					// Completion & exit criteria.
					'detect_completion_indicators',
					'check_exit_conditions',
					'analyze_loop_health',
					// Data analysis for QA.
					'extract_structured_data',
					'generate_research_report',
					// Context for review state.
					'memory_audit_trail',
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
					'segment_customers',
					'revenue_forecast',
					'create_custom_report',
					'export_analytics_api',
					'export_customer_data',
					// Related tools.
					'google_analytics_report',
					'quickbooks_report',
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
					// Market & Investment Intelligence (Pro - 8 tools).
					'financial_search',
					'financial_news_aggregator',
					'financial_report_generator',
					'financial_logic_visualizer',
					'stock_data_fetcher',
					'investment_signal_tracker',
					'market_forecast_analyzer',
					'market_sentiment_analyzer',
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
					// Omni video (Gemini).
					'generate_omni_video',
					'edit_omni_video',
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
					// Images.
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
					// Communication.
					'send_telegram_message',
					// Billing & payments.
					'payhere_get_payment',
					// Financial planning.
					'financial_health_score',
					'budget_planner',
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
					'manage_template_versions',
					// Media Collections (Pro).
					'create_media_collection',
					'process_collection',
					'apply_collection_template',
					// Video processing.
					'transcode_video',
					'extract_video_frames',
					'get_video_metadata',
					'analyze_video',
					// Social video (Pro).
					'create_social_video',
					'create_remotion_video',
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
					'generate_openai_speech',
					// Audio transcription.
					'transcribe_openai_audio',
				),
			),

			'research_tools'            => array(
				'name'        => __( '🔬 Research & Analysis', 'mcp-ai-wpoos' ),
				'description' => __( 'Research tools for posts, products, projects, places, policies, and more', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent memory for research contexts.
					'mine_agent_memory',
					// Research tools (Pro).
					'research_post',
					'research_product',
					'research_project',
					'research_page',
					'research_place',
					'research_policy',
					'research_eca',
					'research_quiz_topic',
					'research_blog_post',
					// Data extraction.
					'extract_structured_data',
					'aggregate_research_data',
					'generate_research_report',
					'verify_information',
					// Deep research.
					'web_browser',
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
					'analyze_geospatial',
				),
			),

			'project_management'        => array(
				'name'        => __( '📋 Project & Task Management', 'mcp-ai-wpoos' ),
				'description' => __( 'Project planning, task management, task dependencies, and team coordination tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Project Management (Pro).
					'delete_project',
					'research_project',
					// Task Management (Pro).
					'delete_task',
					// Task dependencies (Pro).
					'add_task_dependency',
					'remove_task_dependency',
					'get_task_dependencies',
					// Event Management (Pro).
					'delete_event',
					'export_calendar_ics',
					// Templates.
					'create_template',
					'instantiate_template',
					'list_templates',
					'manage_template_versions',
					// Coordination.
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
					// Regulatory management (Pro).
					'add_regulatory_requirement',
					'get_regulatory_requirements',
					'get_regulatory_updates',
					// Compliance (Pro).
					'check_document_expiry',
					'check_product_compliance',
					'check_authority_status',
					'submit_to_authority',
					'generate_compliance_certificate',
					'generate_compliance_report',
					'validate_document_checklist',
					// Research & analysis.
					'verify_information',
					// Document management.
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
					'analyze_geospatial',
					// Weather & environment.
					'get_gdacs_events',
					'get_nhc_active_storms',
					// Business listing images (Pro).
					'download_google_maps_images',
				),
			),

			'developer_advanced'        => array(
				'name'        => __( '⚙️ Developer Advanced Tools', 'mcp-ai-wpoos' ),
				'description' => __( 'Advanced development, API integration, and system management tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Agent coordination for development workflows.
					'mine_agent_memory',
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

			// =================================================================.
			// REGISTRATION & COMPLIANCE MANAGEMENT PRESET
			// =================================================================.

			'registration_management'   => array(
				'name'        => __( '📋 Registration & Compliance', 'mcp-ai-wpoos' ),
				'description' => __( 'Product registration, permit management, regulatory submissions, document expiry tracking, and compliance workflows', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// Registration management (Pro).
					'create_registration',
					'get_registration',
					'get_registration_timeline',
					'update_registration_status',
					'list_registrations',
					'list_registrations_by_country',
					'list_expiring_registrations',
					'export_registrations_to_excel',
					'import_registrations_from_excel',
					'submit_registration',
					'approve_registration',
					'renew_registration',
					// Expiry & alerts (Pro).
					'send_expiry_alerts',
					'generate_expiry_forecast',
					'check_document_expiry',
					// Regulatory management (Pro).
					'add_regulatory_requirement',
					'get_regulatory_requirements',
					'get_regulatory_updates',
					// Compliance & authority (Pro).
					'check_authority_status',
					'submit_to_authority',
					'check_product_compliance',
					'generate_compliance_certificate',
					'generate_compliance_report',
					'validate_document_checklist',
					'validate_reg_product',
					'validate_inci_ingredients',
					// Regulated products (Pro).
					'create_reg_product',
					'get_reg_product',
					'list_reg_products',
					'update_reg_product',
					'delete_reg_product',
					'duplicate_reg_product',
					'search_reg_products',
					// Regulated documents (Pro).
					'upload_reg_document',
					'get_reg_document',
					'list_reg_documents',
					'update_reg_document',
					// Import duty & trade (Pro).
					'get_import_duty',
					'check_hs_code',
					// MOH / NMRA sync (Pro).
					'sync_with_mohap',
					'sync_with_nmra',
				),
			),

			// =================================================================.
			// CRE DEBT & SECURITIZATION PRESET
			// =================================================================.

			'cre_debt_securitization'   => array(
				'name'        => __( '🏦 CRE Debt & Securitization', 'mcp-ai-wpoos' ),
				'description' => __( 'Commercial real estate debt underwriting, CMBS securitization, loan origination, fund management, and asset surveillance tools', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// CRE Underwriting (14 tools).
					'cre_debt_calculator',
					'cre_loan_sizer',
					'cre_noi_calculator',
					'cre_dcf_modeler',
					'cre_cap_rate_sensitivity',
					'cre_debt_yield_analyzer',
					'cre_rent_roll_analyzer',
					'cre_amortization_scheduler',
					'cre_leverage_return_analyzer',
					'cre_stress_test_modeler',
					'cre_property_valuation_engine',
					'cre_operating_expense_benchmarker',
					'cre_environmental_risk_scorer',
					'cre_underwriting_memo_generator',
					// CRE Originations (11 tools).
					'cre_deal_screening_calculator',
					'cre_loan_quote_generator',
					'cre_deal_pipeline_manager',
					'cre_borrower_profile_analyzer',
					'cre_market_comp_analyzer',
					'cre_term_sheet_comparator',
					'cre_rate_lock_manager',
					'cre_closing_checklist_manager',
					'cre_execution_strategy_advisor',
					'cre_broker_relationship_tracker',
					'cre_origination_volume_tracker',
					// CRE Asset Management (12 tools).
					'cre_property_performance_tracker',
					'cre_loan_surveillance_dashboard',
					'cre_watchlist_manager',
					'cre_lease_expiration_manager',
					'cre_tenant_credit_analyzer',
					'cre_property_budget_manager',
					'cre_capex_reserve_planner',
					'cre_hold_sell_analyzer',
					'cre_asset_disposition_analyzer',
					'cre_workout_scenario_modeler',
					'cre_loan_modification_calculator',
					'cre_servicing_fee_calculator',
					// CMBS Securitization (10 tools).
					'cmbs_deal_structurer',
					'cmbs_pool_analyzer',
					'cmbs_bond_cash_flow_modeler',
					'cmbs_rating_agency_analyzer',
					'cmbs_defeasance_calculator',
					'cmbs_investor_reporting_generator',
					'cmbs_maturity_risk_analyzer',
					'cmbs_special_servicing_tracker',
					'cmbs_surveillance_monitor',
					'cre_clo_modeler',
					// CRE Debt Fund & Portfolio (11 tools).
					'cre_fund_portfolio_dashboard',
					'cre_fund_return_calculator',
					'cre_fund_capital_call_calculator',
					'cre_fund_liquidity_analyzer',
					'cre_fund_scenario_modeler',
					'cre_lp_report_generator',
					'cre_concentration_limit_monitor',
					'cre_covenant_compliance_checker',
					'cre_credit_risk_scorer',
					'cre_debt_waterfall_modeler',
					'cre_warehouse_line_manager',
				),
			),

			// =================================================================.
			// SPORTS & ENTERTAINMENT PRESETS
			// =================================================================.

			'fantasy_sports'            => array(
				'name'        => __( '🏆 Fantasy Sports & Analytics', 'mcp-ai-wpoos' ),
				'description' => __( 'Fantasy sports management, player research, league analytics, and trade analysis for ESPN, Yahoo, and more. Requires the Fantasy Football addon.', 'mcp-ai-wpoos' ),
				'tools'       => array(
					// ESPN Fantasy Sports (Fantasy Football Addon).
					'espn_fantasy_get_league',
					'espn_fantasy_get_teams',
					'espn_fantasy_get_roster',
					'espn_fantasy_get_standings',
					'espn_fantasy_analyze_lineup',
					'espn_fantasy_sync_league',
					// Yahoo Fantasy Football (Fantasy Football Addon).
					'yahoo_ff_auth',
					'yahoo_ff_get_leagues',
					'yahoo_ff_get_roster',
					'yahoo_ff_get_player_stats',
					'yahoo_ff_league_standings',
					'yahoo_ff_trade_analyzer',
					// Fantasy Football Tools (Fantasy Football Addon).
					'ff_player_research',
					'ff_create_league_report',
					'ff_generate_team_logo',
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
		 * Get all tool presets (alias for get_presets).
		 *
		 * @return array
		 */
	public static function get_all_presets() {
		return self::get_presets();
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
		 *     @type string $system_prompt   The assistant's system prompt for auto-select. Default ''.
		 *     @type array  $primary_role_ids Array of profession post IDs for auto-select. Default array().
		 *     @type bool   $show_auto_select Whether to show the Auto-Select button. Default true.
		 *     @type bool   $show_selected_bar Whether to show the selected tools chips bar. Default true.
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
			'system_prompt'        => '',
			'primary_role_ids'     => array(),
			'show_auto_select'     => true,
			'show_selected_bar'    => true,
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

		// Selected tools chips bar.
		if ( $args['show_selected_bar'] ) {
			echo '<div class="wp-mcp-ai-selected-tools-bar" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; min-height: 28px; align-items: center;">';
			echo '<span class="wp-mcp-ai-selected-tools-count" style="font-weight: 600; font-size: 13px; margin-right: 6px; white-space: nowrap;">';
			echo esc_html__( 'Selected:', 'mcp-ai-wpoos' ) . ' <span id="wp-mcp-ai-selected-count">0</span>';
			echo '</span>';
			echo '<span id="wp-mcp-ai-selected-chips"></span>';
			echo '</div>';
		}

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

			// Auto-Select button.
			if ( $args['show_auto_select'] ) {
				echo '<span style="width: 10px;"></span>';
				printf(
					'<button type="button" class="%1$s wp-mcp-ai-auto-select-tools" style="background: #6c3cb8; border-color: #6c3cb8; color: #fff;" title="%2$s">%3$s</button>',
					esc_attr( $args['button_class'] ),
					esc_attr__( 'Analyze the assistant\'s instructions and selected professions to automatically choose the most relevant tools.', 'mcp-ai-wpoos' ),
					esc_html__( '🤖 Auto-Select', 'mcp-ai-wpoos' )
				);
			}

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
			$auto_select_data = array();
			if ( $args['show_auto_select'] ) {
				// Wrap in try-catch to prevent compute errors from breaking the page.
				try {
					$auto_select_data = self::compute_auto_select_data(
						$args['system_prompt'],
						$args['primary_role_ids'],
						$args['available_tools']
					);
				} catch ( \Throwable $e ) {
					// Log the error and fall back to no auto-select data.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'WP MCP AI auto-select compute error: ' . $e->getMessage() );
					}
					$auto_select_data = array(
						'tools'  => array(),
						'reason' => __( 'Auto-select is temporarily unavailable.', 'mcp-ai-wpoos' ),
					);
				}
			}
			self::render_preset_script( $args['checkbox_selector'], $args['show_selected_bar'], $args['show_auto_select'], $auto_select_data );
		}
	}

	/**
	 * Render the JavaScript for preset functionality.
	 *
	 * Includes Clear All, Select All, Auto-Select, and selected tools chips bar.
	 *
	 * @param string $checkbox_selector CSS selector for tool checkboxes.
	 * @param bool   $show_selected_bar Whether to render the selected tools chips bar.
	 * @param bool   $show_auto_select  Whether the auto-select button is present.
	 * @param array  $auto_select_data  Data for auto-select (scored tools list).
	 */
	protected static function render_preset_script( $checkbox_selector, $show_selected_bar = true, $show_auto_select = false, $auto_select_data = array() ) {
		static $preset_script_printed = false;

		if ( $preset_script_printed ) {
			return;
		}

		$preset_script_printed  = true;
		$checkbox_selector_json = wp_json_encode( $checkbox_selector );
		$auto_select_data_json  = wp_json_encode( $auto_select_data );
		$show_selected_bar_json = wp_json_encode( $show_selected_bar );
		$show_auto_select_json  = wp_json_encode( $show_auto_select );

		$script = "( function() {
			'use strict';

			document.addEventListener( 'DOMContentLoaded', function() {
				var presetButtons = document.querySelectorAll( '.wp-mcp-ai-tool-preset-btn' );
				var checkboxSelector = {$checkbox_selector_json};
				var selectAllBtn = document.querySelector( '.wp-mcp-ai-select-all-tools' );
				var clearAllBtn = document.querySelector( '.wp-mcp-ai-clear-all-tools' );
				var autoSelectBtn = document.querySelector( '.wp-mcp-ai-auto-select-tools' );
				var showSelectedBar = {$show_selected_bar_json};
				var showAutoSelect = {$show_auto_select_json};
				var autoSelectData = {$auto_select_data_json};

				// Helper function to toggle checkboxes.
				function toggleCheckboxes( toolSlugs, checked ) {
					toolSlugs.forEach( function( toolSlug ) {
						var checkbox = document.querySelector( checkboxSelector + '[value=\"' + toolSlug + '\"]' );
						if ( checkbox ) {
							checkbox.checked = checked;
							var event = new Event( 'change', { bubbles: true } );
							checkbox.dispatchEvent( event );
						}
					} );
				}

				// Helper function to get all checked tool slugs.
				function getCheckedSlugs() {
					var checked = [];
					var all = document.querySelectorAll( checkboxSelector );
					all.forEach( function( cb ) {
						if ( cb.checked ) {
							checked.push( cb.value );
						}
					} );
					return checked;
				}

				// Refresh the selected tools chips bar.
				function refreshSelectedChips() {
					if ( ! showSelectedBar ) {
						return;
					}
					var countEl = document.getElementById( 'wp-mcp-ai-selected-count' );
					var chipsEl = document.getElementById( 'wp-mcp-ai-selected-chips' );
					if ( ! countEl || ! chipsEl ) {
						return;
					}
					var checked = getCheckedSlugs();
					countEl.textContent = checked.length;

					var html = '';
					var maxShow = 20;
					var shown = 0;
					checked.forEach( function( slug ) {
						var isOverflow = shown >= maxShow;
						var labelEl = document.querySelector( checkboxSelector + '[value=\"' + slug + '\"]' );
						var label = slug;
						if ( labelEl ) {
							// Try to get the human-readable label from the nearby label element.
							var parentLabel = labelEl.closest( 'label' );
							if ( parentLabel ) {
								var text = parentLabel.textContent || '';
								// Remove the checkbox text content to get just the label.
								var clean = text.replace( /^\\\\s*/, '' ).replace( /\\\\s*$/, '' );
								if ( clean.length > 0 && clean.length < 80 ) {
									label = clean;
								}
							}
						}
						html += '<span class=\"wp-mcp-ai-tool-chip' + ( isOverflow ? ' wp-mcp-ai-tool-chip--overflow' : '' ) + '\" data-slug=\"' + slug + '\" style=\"display: ' + ( isOverflow ? 'none' : 'inline-flex' ) + '; align-items: center; background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 12px; font-size: 12px; border: 1px solid #c7d2fe; cursor: default; margin: 4px;\">';
						html += '<span class=\"wp-mcp-ai-tool-chip-label\" style=\"max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;\">' + label + '</span>';
						html += '<button type=\"button\" class=\"wp-mcp-ai-tool-chip-remove\" data-slug=\"' + slug + '\" style=\"margin-left: 4px; border: none; background: none; color: #6366f1; cursor: pointer; font-size: 14px; line-height: 1; padding: 0 2px;\" title=\"Remove \"' + slug + '\"\">&times;</button>';
						html += '</span>';
						shown++;
					} );
					if ( checked.length > maxShow ) {
						html += '<button type=\"button\" class=\"wp-mcp-ai-tool-chip-more\" style=\"font-size: 12px; color: #6b7280; margin: 4px; border: 1px dashed #c7d2fe; border-radius: 12px; padding: 2px 8px; background: none; cursor: pointer;\">+ ' + ( checked.length - maxShow ) + ' more</button>';
					}
					chipsEl.innerHTML = html;

					// Bind chip removal clicks.
					chipsEl.querySelectorAll( '.wp-mcp-ai-tool-chip-remove' ).forEach( function( btn ) {
						btn.addEventListener( 'click', function( e ) {
							e.preventDefault();
							e.stopPropagation();
							var slug = btn.getAttribute( 'data-slug' );
							var cb = document.querySelector( checkboxSelector + '[value=\"' + slug + '\"]' );
							if ( cb ) {
								cb.checked = false;
								cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
							}
						} );
					} );

					// Bind plus N more toggle.
					var moreBtn = chipsEl.querySelector( '.wp-mcp-ai-tool-chip-more' );
					if ( moreBtn ) {
						moreBtn.addEventListener( 'click', function( e ) {
							e.preventDefault();
							var overflowChips = chipsEl.querySelectorAll( '.wp-mcp-ai-tool-chip--overflow' );
							var isShowing = overflowChips.length > 0 && overflowChips[0].style.display === 'inline-flex';
							overflowChips.forEach( function( chip ) {
								chip.style.display = isShowing ? 'none' : 'inline-flex';
							} );
							moreBtn.textContent = isShowing ? '+ ' + overflowChips.length + ' more' : '- Show less';
						} );
					}
				}

				// Listen for checkbox changes to refresh chips.
				if ( showSelectedBar ) {
					document.addEventListener( 'change', function( e ) {
						if ( e.target.matches && e.target.matches( checkboxSelector ) ) {
							refreshSelectedChips();
						}
					} );
					// Initial refresh.
					refreshSelectedChips();
				}

				// Auto-Select button handler.
				if ( showAutoSelect && autoSelectBtn && autoSelectData && autoSelectData.tools && autoSelectData.tools.length > 0 ) {
					autoSelectBtn.addEventListener( 'click', function( e ) {
						e.preventDefault();

						// Visual feedback: disable button during processing.
						autoSelectBtn.disabled = true;
						autoSelectBtn.textContent = '...';

						// Clear current selection first.
						if ( clearAllBtn ) {
							clearAllBtn.click();
						}

						// Small delay for visual feedback, then select.
						setTimeout( function() {
							autoSelectData.tools.forEach( function( toolSlug ) {
								var checkbox = document.querySelector( checkboxSelector + '[value=\"' + toolSlug + '\"]' );
								if ( checkbox && ! checkbox.checked ) {
									checkbox.checked = true;
									checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
								}
							} );
							autoSelectBtn.disabled = false;
							autoSelectBtn.textContent = '\u{1F916} Auto-Select';

							// Show notification.
							var notice = document.createElement( 'div' );
							notice.className = 'notice notice-success is-dismissible';
							notice.style.margin = '10px 0';
							notice.innerHTML = '<p><strong>Auto-Select complete:</strong> ' + autoSelectData.tools.length + ' tools selected based on instructions and professions. ' + ( autoSelectData.reason || '' ) + '</p>';
							autoSelectBtn.parentNode.parentNode.insertBefore( notice, autoSelectBtn.parentNode );
							setTimeout( function() {
								if ( notice.parentNode ) {
									notice.parentNode.removeChild( notice );
								}
							}, 8000 );
						}, 150 );
					} );
				} else if ( showAutoSelect && autoSelectBtn ) {
					// No auto-select data available — disable the button.
					autoSelectBtn.disabled = true;
					autoSelectBtn.style.opacity = '0.6';
					autoSelectBtn.title = 'Set system instructions or select professions to enable auto-select.';
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
		} )();";
		wp_print_inline_script_tag( $script );
	}

	/**
	 * Compute auto-select tool recommendations based on system prompt and professions.
	 *
	 * Uses deterministic keyword matching against tool definitions (name, description,
	 * profession_tags, toolkit) to score each tool. Returns the top-scoring tools.
	 *
	 * @param string $system_prompt   The assistant's system instructions.
	 * @param array  $primary_role_ids Array of profession post IDs.
	 * @param array  $available_tools  Array of available tool slugs to consider.
	 * @return array { tools: string[], reason: string }|array Empty array if no data.
	 */
	public static function compute_auto_select_data( $system_prompt, $primary_role_ids, $available_tools ) {
		// Cap system prompt length to avoid excessive keyword extraction.
		$max_prompt_chars = apply_filters( 'wp_mcp_ai_auto_select_max_prompt_chars', 5000 );
		if ( is_string( $system_prompt ) && strlen( $system_prompt ) > $max_prompt_chars ) {
			$system_prompt = substr( $system_prompt, 0, $max_prompt_chars );
		}

		$keywords = array();

		// 1. Extract keywords from system prompt.
		if ( ! empty( $system_prompt ) && is_string( $system_prompt ) ) {
			$prompt_keywords = self::extract_keywords_from_text( $system_prompt );
			$keywords        = array_merge( $keywords, $prompt_keywords );
		}

		// 2. Extract keywords from profession data.
		$profession_tags = array();
		if ( ! empty( $primary_role_ids ) && is_array( $primary_role_ids ) ) {
			foreach ( $primary_role_ids as $role_id ) {
				$role_id = absint( $role_id );
				if ( $role_id <= 0 ) {
					continue;
				}

				$profession_post = get_post( $role_id );
				if ( ! $profession_post || 'mcp_ai_profession' !== $profession_post->post_type ) {
					continue;
				}

				// Collect profession title as tag.
				$title_lower       = strtolower( trim( $profession_post->post_title ) );
				$profession_tags[] = sanitize_title( $title_lower );
				$keywords[]        = $title_lower;

				// Collect role description.
				$role_description = get_post_meta( $role_id, '_wp_mcp_ai_profession_role_description', true );
				if ( ! empty( $role_description ) ) {
					$keywords = array_merge( $keywords, self::extract_keywords_from_text( $role_description ) );
				}

				// Collect knowledge base.
				$knowledge_base = get_post_meta( $role_id, '_wp_mcp_ai_profession_knowledge_base', true );
				if ( ! empty( $knowledge_base ) ) {
					$keywords = array_merge( $keywords, self::extract_keywords_from_text( $knowledge_base ) );
				}

				// Collect expertise areas.
				$expertise = get_post_meta( $role_id, '_wp_mcp_ai_profession_expertise', true );
				if ( is_array( $expertise ) ) {
					foreach ( $expertise as $exp ) {
						if ( is_string( $exp ) ) {
							$keywords[] = strtolower( trim( $exp ) );
						}
					}
				}

				// Collect default tools (these are directly relevant).
				$default_tools = get_post_meta( $role_id, '_wp_mcp_ai_profession_default_tools', true );
				if ( is_array( $default_tools ) ) {
					foreach ( $default_tools as $tool ) {
						if ( is_string( $tool ) ) {
							$keywords[] = strtolower( trim( $tool ) );
						}
					}
				}
			}
		}

		// If no keywords, return empty.
		if ( empty( $keywords ) ) {
			return array(
				'tools'  => array(),
				'reason' => __( 'No instructions or professions found to analyze.', 'mcp-ai-wpoos' ),
			);
		}

		// Deduplicate and normalize keywords.
		$keywords = array_unique( array_map( 'strtolower', $keywords ) );

		// Cap keywords to avoid excessive scoring loops.
		$max_keywords = apply_filters( 'wp_mcp_ai_auto_select_max_keywords', 300 );
		if ( count( $keywords ) > $max_keywords ) {
			$keywords = array_slice( $keywords, 0, $max_keywords );
		}

		// 3. Build tool index from registry.
		$tool_index = self::build_tool_index( $available_tools );

		if ( empty( $tool_index ) ) {
			return array(
				'tools'  => array(),
				'reason' => __( 'No tool definitions available to match against.', 'mcp-ai-wpoos' ),
			);
		}

		// 4. Score each tool against keywords.
		$scored = array();
		foreach ( $tool_index as $slug => $tool_data ) {
			$score = 0;

			// Direct profession tag match (highest weight).
			if ( ! empty( $tool_data['profession_tags'] ) ) {
				foreach ( $tool_data['profession_tags'] as $ptag ) {
					$ptag_lower = strtolower( trim( $ptag ) );
					if ( in_array( $ptag_lower, $profession_tags, true ) ) {
						$score += 15;
					}
					// Also check if profession tag appears in keywords.
					foreach ( $keywords as $kw ) {
						if ( false !== strpos( $ptag_lower, $kw ) || false !== strpos( $kw, $ptag_lower ) ) {
							$score += 8;
						}
					}
				}
			}

			// Tool name matches keywords.
			$tool_name_lower = strtolower( $tool_data['name'] );
			foreach ( $keywords as $kw ) {
				if ( strlen( $kw ) < 3 ) {
					continue;
				}
				if ( false !== strpos( $tool_name_lower, $kw ) ) {
					$score += 5;
				}
			}

			// Tool slug matches keywords.
			$slug_lower = strtolower( str_replace( '_', ' ', $slug ) );
			foreach ( $keywords as $kw ) {
				if ( strlen( $kw ) < 3 ) {
					continue;
				}
				if ( false !== strpos( $slug_lower, $kw ) ) {
					$score += 4;
				}
			}

			// Tool description matches keywords.
			if ( ! empty( $tool_data['description'] ) ) {
				$desc_lower = strtolower( $tool_data['description'] );
				foreach ( $keywords as $kw ) {
					if ( strlen( $kw ) < 3 ) {
						continue;
					}
					if ( false !== strpos( $desc_lower, $kw ) ) {
						$score += 2;
					}
				}
			}

			// Toolkit/category matches keywords.
			if ( ! empty( $tool_data['toolkit'] ) ) {
				$toolkit_lower = strtolower( str_replace( '_', ' ', $tool_data['toolkit'] ) );
				foreach ( $keywords as $kw ) {
					if ( strlen( $kw ) < 3 ) {
						continue;
					}
					if ( false !== strpos( $toolkit_lower, $kw ) ) {
						$score += 3;
					}
				}
			}

			if ( $score > 0 ) {
				$scored[ $slug ] = $score;
			}
		}

		// Sort by score descending.
		arsort( $scored, SORT_NUMERIC );

		// Take top-scoring tools (up to configurable max, default 100).
		$max_tools = apply_filters( 'wp_mcp_ai_auto_select_max_tools', 100 );
		$min_score = apply_filters( 'wp_mcp_ai_auto_select_min_score', 3 );

		$selected = array();
		foreach ( $scored as $slug => $score ) {
			if ( count( $selected ) >= $max_tools ) {
				break;
			}
			if ( $score < $min_score ) {
				break;
			}
			$selected[] = $slug;
		}

		$reason = sprintf(
			/* translators: 1: number of keywords analyzed, 2: number of professions */
			__( 'Analyzed %1$d keywords from instructions and %2$d profession(s).', 'mcp-ai-wpoos' ),
			count( $keywords ),
			count( $primary_role_ids )
		);

		return array(
			'tools'  => array_values( $selected ),
			'reason' => $reason,
		);
	}

	/**
	 * Extract meaningful keywords from a text string.
	 *
	 * Removes common stop words and short tokens.
	 *
	 * @param string $text Text to analyze.
	 * @return string[] Array of lowercase keywords.
	 */
	protected static function extract_keywords_from_text( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return array();
		}

		// Convert to lowercase and split into tokens.
		$text = strtolower( $text );

		// Replace common punctuation with spaces.
		$text = preg_replace( '/[^\w\s-]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		// Split into words.
		$words = explode( ' ', trim( $text ) );

		// Common English stop words to filter out.
		$stop_words = array(
			'the',
			'a',
			'an',
			'is',
			'are',
			'was',
			'were',
			'be',
			'been',
			'being',
			'have',
			'has',
			'had',
			'do',
			'does',
			'did',
			'will',
			'would',
			'shall',
			'should',
			'may',
			'might',
			'must',
			'can',
			'could',
			'i',
			'me',
			'my',
			'we',
			'our',
			'us',
			'you',
			'your',
			'he',
			'she',
			'it',
			'they',
			'them',
			'their',
			'its',
			'and',
			'or',
			'but',
			'not',
			'no',
			'if',
			'then',
			'else',
			'when',
			'where',
			'how',
			'what',
			'which',
			'who',
			'whom',
			'this',
			'that',
			'these',
			'those',
			'to',
			'of',
			'in',
			'for',
			'on',
			'with',
			'at',
			'by',
			'from',
			'as',
			'into',
			'about',
			'above',
			'after',
			'before',
			'between',
			'under',
			'over',
			'again',
			'further',
			'each',
			'both',
			'few',
			'more',
			'most',
			'other',
			'some',
			'such',
			'only',
			'own',
			'same',
			'so',
			'than',
			'too',
			'very',
			'just',
			'also',
			'now',
			'there',
			'here',
			'all',
			'any',
			'up',
			'down',
			'out',
			'off',
			'during',
		);

		// Cap number of words processed to avoid excessive memory/time.
		$max_words = apply_filters( 'wp_mcp_ai_auto_select_max_words', 1000 );
		if ( count( $words ) > $max_words ) {
			$words = array_slice( $words, 0, $max_words );
		}

		$keywords = array();
		foreach ( $words as $word ) {
			$word = trim( $word, "-_.\t\n\r" );
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( in_array( $word, $stop_words, true ) ) {
				continue;
			}
			// Skip pure numbers.
			if ( is_numeric( $word ) ) {
				continue;
			}
			$keywords[] = $word;
		}

		// Also include bigrams (two-word phrases) for better matching.
		$count        = count( $words );
		$max_bigrams  = apply_filters( 'wp_mcp_ai_auto_select_max_bigrams', 200 );
		$bigram_count = 0;
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$first  = trim( $words[ $i ], "-_.\t\n\r" );
			$second = trim( $words[ $i + 1 ], "-_.\t\n\r" );
			if ( strlen( $first ) < 3 || strlen( $second ) < 3 ) {
				continue;
			}
			if ( in_array( $first, $stop_words, true ) || in_array( $second, $stop_words, true ) ) {
				continue;
			}
			$bigram = strtolower( $first . ' ' . $second );
			// Only include if it looks like a meaningful phrase.
			if ( strlen( $bigram ) >= 7 ) {
				$keywords[] = $bigram;
				++$bigram_count;
				if ( $bigram_count >= $max_bigrams ) {
					break;
				}
			}
		}

		return array_unique( $keywords );
	}

	/**
	 * Build a searchable index of tool definitions from the registry.
	 *
	 * Caches the result in a static property for performance.
	 *
	 * @param array $available_tools Optional array of tool slugs to limit the index.
	 * @return array<string, array> Mapping of tool slug => definition metadata.
	 */
	protected static function build_tool_index( $available_tools = array() ) {
		if ( null !== self::$tool_index ) {
			if ( empty( $available_tools ) ) {
				return self::$tool_index;
			}
			// Filter cached index to only available tools.
			$filtered = array();
			foreach ( $available_tools as $slug ) {
				if ( isset( self::$tool_index[ $slug ] ) ) {
					$filtered[ $slug ] = self::$tool_index[ $slug ];
				}
			}
			return $filtered;
		}

		$index = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $index;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof \WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();
			if ( '' === $slug ) {
				continue;
			}

			// If available_tools is specified, skip tools not in that list.
			if ( ! empty( $available_tools ) && ! in_array( $slug, $available_tools, true ) ) {
				continue;
			}

			// get_definition() is not part of the interface; fall back to
			// interface methods when a tool doesn't provide its own.
			if ( method_exists( $tool, 'get_definition' ) ) {
				try {
					$definition = $tool->get_definition();
				} catch ( \Throwable $e ) {
					$definition = null;
				}
			} else {
				$definition = null;
			}

			if ( is_array( $definition ) ) {
				$index[ $slug ] = array(
					'name'            => isset( $definition['name'] ) ? (string) $definition['name'] : $slug,
					'description'     => isset( $definition['description'] ) ? (string) $definition['description'] : '',
					'profession_tags' => isset( $definition['profession_tags'] ) ? (array) $definition['profession_tags'] : array(),
					'toolkit'         => isset( $definition['toolkit'] ) ? (string) $definition['toolkit'] : '',
					'risk_level'      => isset( $definition['risk_level'] ) ? (string) $definition['risk_level'] : '',
				);
			} else {
				$index[ $slug ] = array(
					'name'            => $tool->get_name(),
					'description'     => $tool->get_description(),
					'profession_tags' => array(),
					'toolkit'         => '',
					'risk_level'      => '',
				);
			}
		}

		self::$tool_index = $index;

		return $index;
	}
}
