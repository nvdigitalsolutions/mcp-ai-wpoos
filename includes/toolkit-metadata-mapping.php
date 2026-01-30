<?php
/**
 * Toolkit Metadata Mapping.
 *
 * Maps tools to their appropriate toolkits based on the enhancement proposal.
 * This file serves as a reference for adding metadata to tools.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

/**
 * Tool-to-Toolkit mapping based on proposal analysis.
 *
 * Format: tool_slug => array( toolkit, pattern_compatibility, profession_tags, risk_level )
 */
return array(
	// ========================================
	// CONTENT & PUBLISHING TOOLKIT (45 tools)
	// ========================================
	'create_post'                        => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'writer', 'content_creator', 'journalist', 'blogger' ),
		'risk_level'            => 'standard',
	),
	'save_post'                          => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'writer', 'content_creator', 'journalist', 'blogger' ),
		'risk_level'            => 'standard',
	),
	'get_recent_posts'                   => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'writer', 'content_creator', 'editor' ),
		'risk_level'            => 'info',
	),
	'generate_post_excerpt'              => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'writer', 'content_creator', 'seo_specialist' ),
		'risk_level'            => 'info',
	),
	'auto_categorize_content'            => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'content_creator', 'content_strategist', 'editor' ),
		'risk_level'            => 'standard',
	),
	'content_recommendation_engine'      => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'content_strategist', 'editor' ),
		'risk_level'            => 'info',
	),
	'suggest_internal_links'             => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
		'risk_level'            => 'info',
	),
	'semantic_content_search'            => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'content_strategist', 'researcher' ),
		'risk_level'            => 'info',
	),
	'search_content'                     => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'writer', 'researcher', 'editor' ),
		'risk_level'            => 'info',
	),
	'get_rankmath_seo'                   => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
		'risk_level'            => 'info',
	),
	'seo_meta_optimizer'                 => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
		'risk_level'            => 'standard',
	),

	// Image generation tools (part of content publishing).
	'generate_gemini_image'              => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'content_creator', 'marketing_manager' ),
		'risk_level'            => 'standard',
	),
	'generate_openai_image'              => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'content_creator', 'marketing_manager' ),
		'risk_level'            => 'standard',
	),
	'generate_cloudflareai_image'        => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'generate_image_alt_text'            => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
		'profession_tags'       => array( 'seo_specialist', 'content_creator' ),
		'risk_level'            => 'info',
	),
	'generate_image_caption'             => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
		'profession_tags'       => array( 'content_creator', 'social_media_manager' ),
		'risk_level'            => 'info',
	),
	'edit_gemini_image'                  => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'photographer' ),
		'risk_level'            => 'standard',
	),
	'edit_openai_image'                  => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'photographer' ),
		'risk_level'            => 'standard',
	),

	// Video generation tools.
	'generate_sora_video'                => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'video_producer', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'generate_veo_video'                 => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'video_producer', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'analyze_video'                      => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'video_producer', 'content_creator' ),
		'risk_level'            => 'info',
	),
	'generate_video_caption'             => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'video_producer', 'content_creator' ),
		'risk_level'            => 'info',
	),

	// Audio generation tools.
	'generate_music'                     => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'musician', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'generate_openai_speech'             => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'content_creator', 'podcaster' ),
		'risk_level'            => 'standard',
	),
	'transcribe_openai_audio'            => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'journalist', 'writer', 'researcher' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// MEDIA PROCESSING TOOLKIT (30 tools)
	// ========================================
	'resize_image'                       => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'photographer', 'graphic_designer' ),
		'risk_level'            => 'standard',
	),
	'crop_image'                         => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'photographer', 'graphic_designer' ),
		'risk_level'            => 'standard',
	),
	'rotate_image'                       => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'photographer', 'graphic_designer' ),
		'risk_level'            => 'standard',
	),
	'convert_image_format'               => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'photographer', 'web_developer' ),
		'risk_level'            => 'standard',
	),
	'vectorize_image'                    => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer' ),
		'risk_level'            => 'standard',
	),
	'remove_background'                  => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'photographer' ),
		'risk_level'            => 'standard',
	),
	'image_alt_text_optimizer'           => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
		'profession_tags'       => array( 'seo_specialist', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'image_format_batch_converter'       => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'web_developer', 'photographer' ),
		'risk_level'            => 'standard',
	),
	'responsive_image_validator'         => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'web_developer', 'qa_engineer' ),
		'risk_level'            => 'info',
	),
	'media_library_optimizer'            => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
		'profession_tags'       => array( 'content_creator', 'systems_administrator' ),
		'risk_level'            => 'standard',
	),
	'search_attachments'                 => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'content_creator', 'photographer' ),
		'risk_level'            => 'info',
	),
	'vision_object_localization'         => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'data_scientist', 'researcher' ),
		'risk_level'            => 'info',
	),
	'vision_product_search'              => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'ecommerce_manager', 'product_manager' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// DATA & ANALYTICS TOOLKIT (28 tools)
	// ========================================
	'create_chart'                       => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
		'profession_tags'       => array( 'data_scientist', 'analyst', 'business_consultant', 'researcher' ),
		'risk_level'            => 'info',
	),
	'generate_chart'                     => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'data_scientist', 'business_analyst' ),
		'risk_level'            => 'info',
	),
	'generate_mermaid'                   => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'software_developer', 'technical_writer' ),
		'risk_level'            => 'info',
	),
	'create_vector_store'                => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),
		'risk_level'            => 'standard',
	),
	'get_vector_store'                   => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'list_vector_stores'                 => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'data_scientist' ),
		'risk_level'            => 'info',
	),
	'manage_vector_store_files'          => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'data_scientist' ),
		'risk_level'            => 'standard',
	),
	'create_text_embeddings'             => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'sequential', 'peer_to_peer' ),
		'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'batch_embed_content'                => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'data_scientist' ),
		'risk_level'            => 'standard',
	),
	'semantic_context_search'            => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'data_scientist', 'researcher' ),
		'risk_level'            => 'info',
	),

	// HuggingFace dataset tools.
	'huggingface_dataset_search'         => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'huggingface_dataset_get_rows'       => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'data_scientist' ),
		'risk_level'            => 'info',
	),
	'huggingface_dataset_get_statistics' => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'peer_to_peer' ),
		'profession_tags'       => array( 'data_scientist', 'statistician' ),
		'risk_level'            => 'info',
	),
	'huggingface_dataset_get_info'       => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'data_scientist' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// RESEARCH & DISCOVERY TOOLKIT (18 tools)
	// ========================================
	'web_search'                         => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
		'profession_tags'       => array( 'researcher', 'journalist', 'analyst', 'writer', 'librarian' ),
		'risk_level'            => 'info',
	),
	'deep_research'                      => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'researcher', 'analyst', 'journalist' ),
		'risk_level'            => 'info',
	),
	'client_summarize_text'              => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'sequential', 'peer_to_peer' ),
		'profession_tags'       => array( 'researcher', 'analyst', 'writer' ),
		'risk_level'            => 'info',
	),
	'client_extract_entities'            => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'researcher', 'data_scientist' ),
		'risk_level'            => 'info',
	),
	'client_question_answering'          => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'researcher', 'analyst' ),
		'risk_level'            => 'info',
	),
	'client_analyze_sentiment'           => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'market_researcher', 'analyst' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// E-COMMERCE & BUSINESS TOOLKIT (32 tools)
	// ========================================
	'get_woo_products'                   => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ecommerce_manager', 'product_manager' ),
		'risk_level'            => 'info',
	),
	'get_woo_recent_orders'              => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ecommerce_manager', 'sales_manager' ),
		'risk_level'            => 'info',
	),
	'create_woo_product'                 => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ecommerce_manager', 'product_manager' ),
		'risk_level'            => 'standard',
	),
	'scrape_product'                     => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'ecommerce_manager', 'market_researcher' ),
		'risk_level'            => 'info',
	),
	'crawl4ai_price_lookup'              => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'ecommerce_manager', 'pricing_analyst' ),
		'risk_level'            => 'info',
	),

	// Newsletter tools.
	'newsletter_create_email'            => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'marketing_manager', 'email_marketer' ),
		'risk_level'            => 'standard',
	),
	'newsletter_get_subscribers'         => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'marketing_manager' ),
		'risk_level'            => 'info',
	),
	'newsletter_get_subscriber_stats'    => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'peer_to_peer' ),
		'profession_tags'       => array( 'marketing_manager', 'analyst' ),
		'risk_level'            => 'info',
	),

	// Site Kit analytics.
	'sitekit_analytics'                  => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'marketing_manager', 'analyst', 'business_consultant' ),
		'risk_level'            => 'info',
	),
	'sitekit_adsense'                    => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'marketing_manager' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// DEVELOPER & TECHNICAL TOOLKIT (24 tools)
	// ========================================
	'get_site_health'                    => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'systems_administrator', 'devops_engineer' ),
		'risk_level'            => 'info',
	),
	'get_site_summary'                   => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'systems_administrator', 'web_developer' ),
		'risk_level'            => 'info',
	),
	'get_environment_status'             => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'devops_engineer', 'systems_administrator' ),
		'risk_level'            => 'info',
	),
	'get_system_logs'                    => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'devops_engineer', 'systems_administrator' ),
		'risk_level'            => 'info',
	),
	'get_update_status'                  => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'systems_administrator' ),
		'risk_level'            => 'info',
	),
	'purge_cache'                        => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'devops_engineer', 'web_developer' ),
		'risk_level'            => 'destructive',
	),
	'purge_cloudflare_cache'             => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'devops_engineer', 'cloud_architect' ),
		'risk_level'            => 'destructive',
	),
	'performance_optimizer_assistant'    => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'devops_engineer', 'performance_engineer' ),
		'risk_level'            => 'standard',
	),
	'analyze_code_sequence'              => array(
		'toolkit'               => 'developer_technical',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'software_developer', 'qa_engineer' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// SECURITY & COMPLIANCE TOOLKIT (12 tools)
	// ========================================
	'check_site_security'                => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'cybersecurity_specialist', 'security_analyst' ),
		'risk_level'            => 'info',
	),
	'login_security_monitor'             => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst' ),
		'risk_level'            => 'info',
	),
	'user_activity_auditor'              => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst', 'compliance_officer' ),
		'risk_level'            => 'info',
	),
	'password_strength_analyzer'         => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst' ),
		'risk_level'            => 'info',
	),
	'2fa_setup_assistant'                => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst', 'systems_administrator' ),
		'risk_level'            => 'standard',
	),
	'moderate_content'                   => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'content_moderator', 'community_manager' ),
		'risk_level'            => 'standard',
	),
	'analyze_comment_content'            => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'content_moderator' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// GEOSPATIAL & LOCATION TOOLKIT (8 tools)
	// ========================================
	'geocode_address'                    => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'urban_planner', 'logistics_coordinator' ),
		'risk_level'            => 'info',
	),
	'search_places'                      => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'urban_planner', 'event_planner' ),
		'risk_level'            => 'info',
	),
	'get_open_meteo_forecast'            => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'meteorologist', 'event_planner' ),
		'risk_level'            => 'info',
	),
	'get_nhc_active_storms'              => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'meteorologist', 'emergency_management_director' ),
		'risk_level'            => 'info',
	),
	'get_gdacs_events'                   => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'emergency_management_director', 'disaster_response_coordinator' ),
		'risk_level'            => 'info',
	),
	'reliefweb_reports'                  => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'disaster_response_coordinator', 'humanitarian_worker' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// WORKFLOW & AUTOMATION TOOLKIT (16 tools)
	// ========================================
	'create_cron_job'                    => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'systems_administrator', 'devops_engineer' ),
		'risk_level'            => 'standard',
	),
	'list_cron_jobs'                     => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'systems_administrator' ),
		'risk_level'            => 'info',
	),
	'get_cron_job'                       => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'systems_administrator' ),
		'risk_level'            => 'info',
	),
	'delete_cron_job'                    => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'systems_administrator' ),
		'risk_level'            => 'destructive',
	),
	'execute_workflow'                   => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical', 'orchestrator' ),
		'profession_tags'       => array( 'project_manager', 'operations_manager' ),
		'risk_level'            => 'standard',
	),
	'check_workflow_health'              => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'project_manager', 'devops_engineer' ),
		'risk_level'            => 'info',
	),
	'create_agent_team'                  => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical', 'orchestrator' ),
		'profession_tags'       => array( 'project_manager' ),
		'risk_level'            => 'standard',
	),
	'delegate_to_agent'                  => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'project_manager' ),
		'risk_level'            => 'standard',
	),

	// ========================================
	// COMMUNICATION & OUTREACH TOOLKIT (14 tools)
	// ========================================
	'send_group_email'                   => array(
		'toolkit'               => 'communication_outreach',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'marketing_manager', 'pr_specialist' ),
		'risk_level'            => 'standard',
	),
	'client_translate_text'              => array(
		'toolkit'               => 'communication_outreach',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'translator', 'content_creator' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// INTEGRATION & EXTERNAL SERVICES TOOLKIT (22 tools)
	// ========================================
	'probe_remote_mcp'                   => array(
		'toolkit'               => 'integration_external',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'integration_specialist', 'api_developer' ),
		'risk_level'            => 'info',
	),
	'query_remote_site'                  => array(
		'toolkit'               => 'integration_external',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'integration_specialist' ),
		'risk_level'            => 'info',
	),
	'get_jetengine_items'                => array(
		'toolkit'               => 'integration_external',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'web_developer', 'wordpress_developer' ),
		'risk_level'            => 'info',
	),
	'search_drive'                       => array(
		'toolkit'               => 'integration_external',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'office_manager', 'researcher' ),
		'risk_level'            => 'info',
	),
	'search_gmail'                       => array(
		'toolkit'               => 'integration_external',
		'pattern_compatibility' => array( 'skill_router' ),
		'profession_tags'       => array( 'executive_assistant', 'customer_service_rep' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// AI & MODEL MANAGEMENT TOOLKIT (18 tools)
	// ========================================
	'list_available_models'              => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'get_model_information'              => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'suggest_best_model'                 => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'discover_new_models'                => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher' ),
		'risk_level'            => 'info',
	),
	'add_model_config'                   => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'machine_learning_engineer', 'mlops_specialist' ),
		'risk_level'            => 'standard',
	),
	'research_model'                     => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation', 'orchestrator' ),
		'profession_tags'       => array( 'ai_researcher' ),
		'risk_level'            => 'info',
	),
	'count_tokens'                       => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation', 'sequential' ),
		'profession_tags'       => array( 'machine_learning_engineer', 'data_scientist' ),
		'risk_level'            => 'info',
	),
	'openai_usage_analytics'             => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation', 'peer_to_peer' ),
		'profession_tags'       => array( 'mlops_specialist', 'business_analyst' ),
		'risk_level'            => 'info',
	),
	'open_openai_logs'                   => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'create_batch'                       => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'machine_learning_engineer', 'data_scientist' ),
		'risk_level'            => 'standard',
	),
	'list_batches'                       => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),
	'get_batch_status'                   => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'machine_learning_engineer' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// VALIDATED TOOL VARIANTS
	// ========================================
	// These are validated (credential-based) versions of base tools.
	'create_assistant_validated'         => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ai_researcher', 'systems_administrator' ),
		'risk_level'            => 'standard',
	),
	'create_post_validated'              => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'writer', 'content_creator', 'journalist' ),
		'risk_level'            => 'standard',
	),
	'create_chart_validated'             => array(
		'toolkit'               => 'data_analytics',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'data_scientist', 'analyst' ),
		'risk_level'            => 'info',
	),
	'create_cron_job_validated'          => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical' ),
		'profession_tags'       => array( 'systems_administrator', 'devops_engineer' ),
		'risk_level'            => 'standard',
	),
	'create_woo_product_validated'       => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ecommerce_manager', 'product_manager' ),
		'risk_level'            => 'standard',
	),
	'generate_gemini_image_validated'    => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'generate_openai_image_validated'    => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'edit_gemini_image_validated'        => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'photographer' ),
		'risk_level'            => 'standard',
	),
	'generate_music_validated'           => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'musician', 'content_creator' ),
		'risk_level'            => 'standard',
	),
	'generate_openai_speech_validated'   => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'content_creator', 'podcaster' ),
		'risk_level'            => 'standard',
	),
	'generate_image_alt_text_validated'  => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'seo_specialist', 'content_creator' ),
		'risk_level'            => 'info',
	),
	'generate_image_caption_validated'   => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'content_creator', 'social_media_manager' ),
		'risk_level'            => 'info',
	),
	'transcribe_openai_audio_validated'  => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'journalist', 'writer', 'researcher' ),
		'risk_level'            => 'info',
	),
	'web_search_validated'               => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'researcher', 'journalist', 'analyst' ),
		'risk_level'            => 'info',
	),

	// ========================================
	// ADDITIONAL MISSING TOOLS
	// ========================================
	'create_assistant'                   => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ai_researcher', 'systems_administrator' ),
		'risk_level'            => 'standard',
	),
	'create_term'                        => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'content_strategist', 'seo_specialist' ),
		'risk_level'            => 'standard',
	),
	'create_image_variation'             => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'graphic_designer', 'photographer' ),
		'risk_level'            => 'standard',
	),
	'analyze_file_suitability'           => array(
		'toolkit'               => 'media_processing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'content_creator', 'web_developer' ),
		'risk_level'            => 'info',
	),
	'check_video_status'                 => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'sequential' ),
		'profession_tags'       => array( 'video_producer', 'content_creator' ),
		'risk_level'            => 'info',
	),
	'client_semantic_search'             => array(
		'toolkit'               => 'research_discovery',
		'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
		'profession_tags'       => array( 'researcher', 'data_scientist' ),
		'risk_level'            => 'info',
	),
	'content_freshness_checker'          => array(
		'toolkit'               => 'content_publishing',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'content_strategist', 'seo_specialist' ),
		'risk_level'            => 'info',
	),
	'enable_reasoning_mode'              => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
		'risk_level'            => 'standard',
	),
	'validate_reasoning_chain'           => array(
		'toolkit'               => 'ai_model_management',
		'pattern_compatibility' => array( 'experimentation' ),
		'profession_tags'       => array( 'ai_researcher' ),
		'risk_level'            => 'info',
	),
	'aggregate_agent_results'            => array(
		'toolkit'               => 'workflow_automation',
		'pattern_compatibility' => array( 'hierarchical', 'orchestrator' ),
		'profession_tags'       => array( 'project_manager', 'systems_administrator' ),
		'risk_level'            => 'info',
	),

	// Flowhub integration tools.
	'flowhub_get_inventory'              => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'inventory_specialist', 'ecommerce_manager' ),
		'risk_level'            => 'info',
	),
	'flowhub_get_orders'                 => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'ecommerce_manager', 'sales_manager' ),
		'risk_level'            => 'info',
	),
	'flowhub_get_customers'              => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'customer_service_rep', 'sales_manager' ),
		'risk_level'            => 'info',
	),
	'flowhub_get_products'               => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'product_manager', 'ecommerce_manager' ),
		'risk_level'            => 'info',
	),
	'flowhub_manage_customer'            => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'customer_service_rep', 'sales_manager' ),
		'risk_level'            => 'standard',
	),
	'flowhub_manage_product'             => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'product_manager', 'inventory_specialist' ),
		'risk_level'            => 'standard',
	),
	'flowhub_create_order'               => array(
		'toolkit'               => 'ecommerce_business',
		'pattern_compatibility' => array( 'orchestrator' ),
		'profession_tags'       => array( 'sales_manager', 'customer_service_rep' ),
		'risk_level'            => 'standard',
	),

	// Additional geospatial tools.
	'gemini_geospatial_query'            => array(
		'toolkit'               => 'geospatial_location',
		'pattern_compatibility' => array( 'event_driven' ),
		'profession_tags'       => array( 'urban_planner', 'geographer' ),
		'risk_level'            => 'info',
	),

	// Additional security/auth tools.
	'generate_auth0_token'               => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst', 'integration_specialist' ),
		'risk_level'            => 'standard',
	),
	'generate_simple_jwt_token'          => array(
		'toolkit'               => 'security_compliance',
		'pattern_compatibility' => array( 'layered_defense' ),
		'profession_tags'       => array( 'security_analyst', 'api_developer' ),
		'risk_level'            => 'standard',
	),
);
