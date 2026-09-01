<?php
/**
 * Profession Tool Recommender Service.
 *
 * Intelligently recommends tools for professions based on their category,
 * role, and specific needs. Provides contextual guidance for tool usage.
 *
 * Uses a three-tier recommendation system:
 * 1. Core tools (recommended for all professions)
 * 2. Category-specific tools (based on profession category)
 * 3. Profession-specific tools (tailored to individual professions)
 *
 * Key features:
 * - Automatic tool filtering based on tool registry availability
 * - Contextual, profession-specific usage guidance
 * - Functional categorization (Core, Media, Admin, etc.)
 * - Support for 40+ professions across 7 categories
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommends tools and provides usage guidance for professions.
 */
class ProfessionToolRecommender {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}
	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $tool_registry;

	/**
	 * Toolkit registry instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Registry|null
	 */
	protected $toolkit_registry;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry    $tool_registry    Optional tool registry instance.
	 * @param WP_MCP_AI_Toolkit_Registry $toolkit_registry Optional toolkit registry instance.
	 */
	public function __construct( $tool_registry = null, $toolkit_registry = null ) {
		$this->tool_registry    = $tool_registry;
		$this->toolkit_registry = $toolkit_registry;
	}

	/**
	 * Get recommended tools for a profession.
	 *
	 * Enhanced to use toolkit registry for intelligent recommendations.
	 *
	 * @param string $profession_slug Profession slug.
	 * @param string $category        Profession category.
	 * @param array  $options         Optional filtering options.
	 * @return array Array of tool slugs.
	 */
	public function get_recommended_tools( $profession_slug, $category, $options = array() ) {
		$tools = array();

		// If toolkit registry is available, use profession-tagged tools.
		if ( $this->toolkit_registry ) {
			$profession_tools = $this->toolkit_registry->get_tools_by_profession( $profession_slug );
			$tools            = array_merge( $tools, $profession_tools );
		}

		// Add core tools for all professions.
		$tools = array_merge( $tools, $this->get_core_tools() );

		// Add category-specific tools.
		$category_tools = $this->get_category_tools( $category );
		$tools          = array_merge( $tools, $category_tools );

		// Add profession-specific tools from legacy mapping.
		$profession_tools = $this->get_profession_specific_tools( $profession_slug );
		$tools            = array_merge( $tools, $profession_tools );

		// Apply filters if specified.
		if ( ! empty( $options['risk_level'] ) && $this->toolkit_registry ) {
			$risk_filtered_tools = $this->toolkit_registry->get_tools_by_risk_level( $options['risk_level'] );
			$tools               = array_intersect( $tools, $risk_filtered_tools );
		}

		if ( ! empty( $options['pattern'] ) && $this->toolkit_registry ) {
			$pattern_tools = $this->toolkit_registry->get_tools_by_pattern( $options['pattern'] );
			$tools         = array_intersect( $tools, $pattern_tools );
		}

		if ( ! empty( $options['toolkit'] ) && $this->toolkit_registry ) {
			$toolkit_tools = $this->toolkit_registry->get_toolkit_tools( $options['toolkit'] );
			$tools         = array_intersect( $tools, $toolkit_tools );
		}

		// Deduplicate and filter available tools.
		$tools = array_unique( $tools );
		$tools = $this->filter_available_tools( $tools );

		return array_values( $tools );
	}

	/**
	 * Get core tools recommended for all professions.
	 *
	 * @return array Array of tool slugs.
	 */
	protected function get_core_tools() {
		return array(
			'web_search',
			'search_content',
			'get_recent_posts',
			'save_post',
			'count_tokens',
		);
	}

	/**
	 * Get tools recommended for a category.
	 *
	 * @param string $category Category slug.
	 * @return array Array of tool slugs.
	 */
	protected function get_category_tools( $category ) {
		$category_tool_map = array(
			'technical'  => array(
				'search_attachments',
				'create_chart',
				'get_system_logs',
				'check_site_security',
				'get_site_health',
				'get_update_status',
				'purge_cache',
				'create_cron_job',
				'list_cron_jobs',
			),
			'creative'   => array(
				'generate_openai_image',
				'generate_gemini_image',
				'edit_gemini_image',
				'resize_image',
				'crop_image',
				'rotate_image',
				'convert_image_format',
				'generate_image_alt_text',
				'generate_image_caption',
				'generate_sora_video',
				'generate_veo_video',
				'analyze_video',
				'generate_video_caption',
				'generate_music',
				'generate_openai_speech',
				'transcribe_openai_audio',
			),
			'advisory'   => array(
				'create_chart',
				'web_search',
				'reliefweb_reports',
				'get_gdacs_events',
				'get_open_meteo_forecast',
			),
			'financial'  => array(
				'create_chart',
				'search_content',
				'create_cron_job',
				'send_group_email',
			),
			'legal'      => array(
				'web_search',
				'search_content',
				'save_post',
				'analyze_comment_content',
			),
			'healthcare' => array(
				'web_search',
				'search_content',
				'get_open_meteo_forecast',
				'reliefweb_reports',
			),
		);

		return isset( $category_tool_map[ $category ] ) ? $category_tool_map[ $category ] : array();
	}

	/**
	 * Get tools recommended for specific professions.
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of tool slugs.
	 */
	protected function get_profession_specific_tools( $profession_slug ) {
		$profession_tool_map = array(
			// Technical professions.
			'software_developer'       => array( 'get_site_summary', 'search_attachments', 'check_site_security' ),
			'software_engineer'        => array( 'get_site_summary', 'search_attachments', 'check_site_security' ),
			'web_developer'            => array( 'get_rankmath_seo', 'get_elementor_templates', 'purge_cache', 'purge_cloudflare_cache', 'purge_varnish_cache' ),
			'network_administrator'    => array( 'get_site_health', 'check_site_security', 'get_system_logs' ),
			'database_administrator'   => array( 'get_site_health', 'get_system_logs', 'create_cron_job' ),
			'cybersecurity_specialist' => array( 'check_site_security', 'get_system_logs', 'analyze_comment_content', 'get_site_health' ),
			'devops_engineer'          => array( 'create_cron_job', 'list_cron_jobs', 'purge_cache', 'get_site_health', 'get_update_status' ),
			'data_scientist'           => array( 'create_chart', 'web_search' ),
			'data_analyst'             => array( 'create_chart', 'search_content' ),

			// Creative professions.
			'graphic_designer'         => array( 'generate_openai_image', 'generate_gemini_image', 'resize_image', 'crop_image', 'convert_image_format' ),
			'photographer'             => array( 'resize_image', 'crop_image', 'rotate_image', 'generate_image_alt_text', 'generate_image_caption' ),
			'video_editor'             => array( 'analyze_video', 'generate_video_caption', 'transcribe_openai_audio' ),
			'videographer'             => array( 'generate_sora_video', 'generate_veo_video', 'analyze_video', 'generate_video_caption' ),
			'cinematographer'          => array( 'generate_sora_video', 'generate_veo_video', 'analyze_video' ),
			'animator'                 => array( 'generate_sora_video', 'generate_veo_video', 'generate_openai_image' ),
			'content_creator'          => array( 'generate_openai_image', 'generate_sora_video', 'transcribe_openai_audio', 'get_rankmath_seo' ),
			'copywriter'               => array( 'count_tokens', 'get_rankmath_seo', 'web_search' ),
			'content_writer'           => array( 'get_rankmath_seo', 'web_search', 'count_tokens' ),
			'technical_writer'         => array( 'search_content', 'get_recent_posts', 'web_search' ),
			'journalist'               => array( 'web_search', 'reliefweb_reports', 'get_gdacs_events', 'transcribe_openai_audio' ),
			'musician'                 => array( 'generate_music', 'transcribe_openai_audio' ),
			'composer'                 => array( 'generate_music' ),
			'sound_engineer'           => array( 'transcribe_openai_audio', 'generate_openai_speech' ),

			// Business/Financial professions.
			'accountant'               => array( 'create_chart', 'search_content', 'create_cron_job' ),
			'bookkeeper'               => array( 'search_content', 'create_cron_job' ),
			'tax_advisor'              => array( 'web_search', 'search_content', 'create_chart' ),
			'financial_advisor'        => array( 'create_chart', 'web_search' ),
			'business_consultant'      => array( 'create_chart', 'get_site_summary', 'web_search' ),
			'marketing_consultant'     => array( 'get_rankmath_seo', 'create_chart', 'web_search', 'generate_openai_image' ),
			'seo_specialist'           => array( 'get_rankmath_seo', 'web_search', 'purge_cache' ),
			'social_media_manager'     => array( 'generate_openai_image', 'web_search', 'schedule_notify_sms' ),
			'project_manager'          => array( 'create_chart', 'create_cron_job', 'list_cron_jobs', 'send_group_email' ),
			'product_manager'          => array( 'create_chart', 'web_search', 'search_content' ),

			// E-commerce professions.
			'ecommerce_manager'        => array( 'get_woo_products', 'get_woo_recent_orders', 'create_woo_product', 'scrape_product', 'crawl4ai_price_lookup' ),
			'merchandiser'             => array( 'get_woo_products', 'create_woo_product', 'scrape_product', 'crawl4ai_price_lookup' ),

			// Healthcare professions.
			'physician'                => array( 'web_search', 'reliefweb_reports' ),
			'nurse'                    => array( 'web_search', 'reliefweb_reports' ),
			'pharmacist'               => array( 'web_search', 'search_content' ),
			'medical_researcher'       => array( 'web_search', 'search_content', 'create_chart' ),

			// Legal professions.
			'lawyer'                   => array( 'web_search', 'search_content', 'save_post' ),
			'paralegal'                => array( 'search_content', 'save_post', 'web_search' ),

			// Other professions.
			'real_estate_agent'        => array( 'search_places', 'geocode_address', 'generate_openai_image' ),
			'teacher'                  => array( 'search_content', 'create_chart', 'generate_openai_image' ),
			'researcher'               => array( 'web_search', 'search_content', 'create_chart' ),
			'emergency_manager'        => array( 'get_gdacs_events', 'get_nhc_active_storms', 'reliefweb_reports', 'get_open_meteo_forecast' ),
			'meteorologist'            => array( 'get_open_meteo_forecast', 'get_nhc_active_storms', 'create_chart' ),
			'geographer'               => array( 'search_places', 'geocode_address', 'get_open_meteo_forecast' ),
			'environmental_scientist'  => array( 'get_open_meteo_forecast', 'web_search', 'create_chart' ),
		);

		return isset( $profession_tool_map[ $profession_slug ] ) ? $profession_tool_map[ $profession_slug ] : array();
	}

	/**
	 * Filter tools to only include those that are available.
	 *
	 * @param array $tool_slugs Array of tool slugs.
	 * @return array Filtered array of available tool slugs.
	 */
	protected function filter_available_tools( $tool_slugs ) {
		if ( null === $this->tool_registry ) {
			// If no registry provided, log warning and return all slugs.
			// This should only happen in testing scenarios.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI: Tool recommender initialized without registry. Tool availability cannot be verified.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, guarded by WP_DEBUG.
			}
			return $tool_slugs;
		}

		$available = array();
		foreach ( $tool_slugs as $slug ) {
			if ( $this->tool_registry->get_tool( $slug ) ) {
				$available[] = $slug;
			}
		}

		return $available;
	}

	/**
	 * Get tool usage guidance for a profession.
	 *
	 * Returns contextual advice on how to use specific tools effectively
	 * for the given profession.
	 *
	 * @param string $profession_slug Profession slug.
	 * @param array  $tool_slugs      Array of tool slugs to provide guidance for.
	 * @return string Formatted usage guidance text.
	 */
	public function get_tool_usage_guidance( $profession_slug, $tool_slugs ) {
		if ( empty( $tool_slugs ) ) {
			return '';
		}

		$guidance = array();

		// Group tools by category for better organization.
		$tool_groups = $this->group_tools_by_category( $tool_slugs );

		foreach ( $tool_groups as $group_name => $group_tools ) {
			$guidance[] = "### {$group_name}\n";

			foreach ( $group_tools as $tool_slug ) {
				$tool_guidance = $this->get_single_tool_guidance( $tool_slug, $profession_slug );
				if ( ! empty( $tool_guidance ) ) {
					$guidance[] = $tool_guidance;
				}
			}

			$guidance[] = '';
		}

		return implode( "\n", $guidance );
	}

	/**
	 * Group tools by functional category.
	 *
	 * @param array $tool_slugs Array of tool slugs.
	 * @return array Grouped tools.
	 */
	protected function group_tools_by_category( $tool_slugs ) {
		$groups = array(
			'Core Tools'              => array(),
			'Content Management'      => array(),
			'Media Generation'        => array(),
			'Media Manipulation'      => array(),
			'Data & Analytics'        => array(),
			'E-commerce'              => array(),
			'SEO & Marketing'         => array(),
			'System Administration'   => array(),
			'External Data & APIs'    => array(),
			'Communication'           => array(),
			'Automation & Scheduling' => array(),
			'Other Tools'             => array(),
		);

		$category_map = array(
			// Core tools.
			'web_search'                    => 'Core Tools',
			'search_content'                => 'Core Tools',
			'count_tokens'                  => 'Core Tools',

			// Content management.
			'get_recent_posts'              => 'Content Management',
			'save_post'                     => 'Content Management',
			'create_post'                   => 'Content Management',
			'search_attachments'            => 'Content Management',
			'get_elementor_templates'       => 'Content Management',
			'import_elementor_template_kit' => 'Content Management',

			// Media generation.
			'generate_openai_image'         => 'Media Generation',
			'generate_gemini_image'         => 'Media Generation',
			'generate_sora_video'           => 'Media Generation',
			'generate_veo_video'            => 'Media Generation',
			'generate_music'                => 'Media Generation',
			'generate_openai_speech'        => 'Media Generation',
			'transcribe_openai_audio'       => 'Media Generation',

			// Media manipulation.
			'edit_gemini_image'             => 'Media Manipulation',
			'resize_image'                  => 'Media Manipulation',
			'crop_image'                    => 'Media Manipulation',
			'rotate_image'                  => 'Media Manipulation',
			'convert_image_format'          => 'Media Manipulation',
			'generate_image_alt_text'       => 'Media Manipulation',
			'generate_image_caption'        => 'Media Manipulation',
			'analyze_video'                 => 'Media Manipulation',
			'generate_video_caption'        => 'Media Manipulation',

			// Data & analytics.
			'create_chart'                  => 'Data & Analytics',
			'query_mesh_intelligent'        => 'Data & Analytics',

			// E-commerce.
			'get_woo_products'              => 'E-commerce',
			'get_woo_recent_orders'         => 'E-commerce',
			'create_woo_product'            => 'E-commerce',
			'scrape_product'                => 'E-commerce',
			'crawl4ai_price_lookup'         => 'E-commerce',

			// SEO & Marketing.
			'get_rankmath_seo'              => 'SEO & Marketing',
			'search_places'                 => 'SEO & Marketing',
			'geocode_address'               => 'SEO & Marketing',

			// System administration.
			'get_site_summary'              => 'System Administration',
			'get_site_health'               => 'System Administration',
			'get_update_status'             => 'System Administration',
			'check_site_security'           => 'System Administration',
			'get_system_logs'               => 'System Administration',
			'purge_cache'                   => 'System Administration',
			'purge_cloudflare_cache'        => 'System Administration',
			'purge_varnish_cache'           => 'System Administration',
			'get_environment_status'        => 'System Administration',

			// External data.
			'reliefweb_reports'             => 'External Data & APIs',
			'get_gdacs_events'              => 'External Data & APIs',
			'get_nhc_active_storms'         => 'External Data & APIs',
			'get_open_meteo_forecast'       => 'External Data & APIs',
			'run_crawl4ai_job'              => 'External Data & APIs',
			'query_remote_site'             => 'External Data & APIs',

			// Communication.
			'send_group_email'              => 'Communication',
			'schedule_notify_sms'           => 'Communication',

			// Automation.
			'create_cron_job'               => 'Automation & Scheduling',
			'list_cron_jobs'                => 'Automation & Scheduling',
			'get_cron_job'                  => 'Automation & Scheduling',
			'delete_cron_job'               => 'Automation & Scheduling',
		);

		foreach ( $tool_slugs as $slug ) {
			$category = isset( $category_map[ $slug ] ) ? $category_map[ $slug ] : 'Other Tools';
			if ( ! isset( $groups[ $category ] ) ) {
				$groups[ $category ] = array();
			}
			$groups[ $category ][] = $slug;
		}

		// Remove empty groups.
		$groups = array_filter( $groups );

		return $groups;
	}

	/**
	 * Get usage guidance for a single tool.
	 *
	 * @param string $tool_slug       Tool slug.
	 * @param string $profession_slug Profession slug for context.
	 * @return string Tool usage guidance.
	 */
	protected function get_single_tool_guidance( $tool_slug, $profession_slug ) {
		// Define profession-specific tool guidance.
		$guidance_map = array(
			'web_search'              => array(
				'default'    => '**web_search** - Search the web for current information, research, and fact-checking. Use this when you need up-to-date information beyond your training data.',
				'journalist' => '**web_search** - Essential for fact-checking, finding sources, researching breaking news, and verifying claims before publication.',
				'researcher' => '**web_search** - Critical for literature reviews, finding recent studies, and staying current with field developments.',
			),
			'search_content'          => array(
				'default'          => '**search_content** - Search WordPress content (posts, pages, custom post types) using keywords, taxonomy filters, or meta queries.',
				'lawyer'           => '**search_content** - Quickly locate relevant case files, legal documents, and client information stored in WordPress.',
				'technical_writer' => '**search_content** - Find existing documentation, articles, and technical content to reference or update.',
			),
			'save_post'               => array(
				'default'        => '**save_post** - Create new posts or update existing content. Supports custom fields, taxonomies, and post status control.',
				'content_writer' => '**save_post** - Publish articles, blog posts, and web content with proper SEO metadata and formatting.',
				'journalist'     => '**save_post** - Draft and publish news articles with proper categories, tags, and editorial workflow.',
			),
			'get_rankmath_seo'        => array(
				'default'        => '**get_rankmath_seo** - Analyze SEO settings and scores for posts to optimize search engine visibility.',
				'seo_specialist' => '**get_rankmath_seo** - Essential for auditing page SEO, identifying optimization opportunities, and tracking SEO scores.',
				'web_developer'  => '**get_rankmath_seo** - Verify SEO implementation and ensure technical SEO requirements are met.',
			),
			'create_chart'            => array(
				'default'           => '**create_chart** - Generate interactive charts (bar, line, pie, etc.) from data using Chart.js.',
				'data_analyst'      => '**create_chart** - Visualize data insights, trends, and patterns for reports and presentations.',
				'financial_advisor' => '**create_chart** - Create financial visualizations showing portfolio performance, market trends, and projections.',
			),
			'generate_openai_image'   => array(
				'default'              => '**generate_openai_image** - Create AI-generated images from text descriptions using DALL-E.',
				'graphic_designer'     => '**generate_openai_image** - Generate concept art, mockups, and visual inspiration for design projects.',
				'marketing_consultant' => '**generate_openai_image** - Create marketing visuals, social media graphics, and campaign imagery.',
			),
			'resize_image'            => array(
				'default'       => '**resize_image** - Resize images to specific dimensions or scale proportionally.',
				'photographer'  => '**resize_image** - Prepare images for different deliverables (web, print, social media) with proper dimensions.',
				'web_developer' => '**resize_image** - Optimize images for responsive web design and performance.',
			),
			'get_woo_products'        => array(
				'default'           => '**get_woo_products** - Retrieve WooCommerce product listings with pricing, stock, and metadata.',
				'ecommerce_manager' => '**get_woo_products** - Monitor inventory, analyze product catalog, and manage merchandising.',
			),
			'create_cron_job'         => array(
				'default'         => '**create_cron_job** - Schedule automated tasks to run at specific times or intervals.',
				'devops_engineer' => '**create_cron_job** - Automate maintenance tasks, backups, and system monitoring.',
				'project_manager' => '**create_cron_job** - Schedule automated reports, reminders, and workflow triggers.',
			),
			'check_site_security'     => array(
				'default'                  => '**check_site_security** - Scan for common security vulnerabilities and misconfigurations.',
				'cybersecurity_specialist' => '**check_site_security** - Essential for security audits, identifying vulnerabilities, and compliance verification.',
			),
			'purge_cache'             => array(
				'default'       => '**purge_cache** - Clear caching layers (Cloudflare, Varnish, object cache) to ensure content freshness.',
				'web_developer' => '**purge_cache** - Clear caches after deployments or content updates to ensure changes are visible.',
			),
			'get_gdacs_events'        => array(
				'default'           => '**get_gdacs_events** - Retrieve global disaster alerts and coordination data.',
				'emergency_manager' => '**get_gdacs_events** - Monitor global disasters for emergency response planning and coordination.',
			),
			'transcribe_openai_audio' => array(
				'default'    => '**transcribe_openai_audio** - Convert audio to text with high accuracy, supporting multiple languages.',
				'journalist' => '**transcribe_openai_audio** - Transcribe interviews, press conferences, and audio recordings for articles.',
			),
		);

		// Get profession-specific guidance or fall back to default.
		if ( isset( $guidance_map[ $tool_slug ][ $profession_slug ] ) ) {
			return $guidance_map[ $tool_slug ][ $profession_slug ] . "\n";
		} elseif ( isset( $guidance_map[ $tool_slug ]['default'] ) ) {
			return $guidance_map[ $tool_slug ]['default'] . "\n";
		}

		// Generic guidance if no specific entry exists.
		return "**{$tool_slug}** - Tool available for this profession.\n";
	}

	/**
	 * Get a formatted tool reference section for playbooks.
	 *
	 * @param string $profession_slug Profession slug.
	 * @param string $category        Profession category.
	 * @return string Formatted tool reference section.
	 */
	public function get_tool_reference_section( $profession_slug, $category ) {
		$recommended_tools = $this->get_recommended_tools( $profession_slug, $category );

		if ( empty( $recommended_tools ) ) {
			return '';
		}

		$sections = array();

		$sections[] = "## Recommended Tools & How to Use Them\n";
		$sections[] = 'This profession has access to ' . count( $recommended_tools ) . " recommended tools that can help you accomplish your work more effectively.\n";
		$sections[] = "**Important:** Always verify you have permission to use each tool before executing it. Some tools require specific capabilities.\n";

		// Add usage guidance.
		$guidance = $this->get_tool_usage_guidance( $profession_slug, $recommended_tools );
		if ( ! empty( $guidance ) ) {
			$sections[] = $guidance;
		}

		$sections[] = "\n### Tool Usage Best Practices\n";
		$sections[] = '1. **Verify permissions** - Check that you have the required capabilities before using administrative tools';
		$sections[] = '2. **Test first** - For destructive operations (delete, purge), test on non-production environments when possible';
		$sections[] = '3. **Provide context** - Include relevant details in tool calls to get the best results';
		$sections[] = '4. **Check responses** - Always verify tool responses and handle errors gracefully';
		$sections[] = '5. **Document usage** - Keep notes on which tools work best for specific tasks';
		$sections[] = '6. **Stay updated** - Tool capabilities may expand; check documentation for new features';

		return implode( "\n", $sections );
	}

	/**
	 * Get toolkit-based recommendations for a profession.
	 *
	 * Returns tools organized by toolkit with metadata.
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of toolkits with recommended tools.
	 */
	public function get_toolkit_recommendations( $profession_slug ) {
		if ( ! $this->toolkit_registry ) {
			return array();
		}

		$recommendations = array();
		$toolkits        = $this->toolkit_registry->get_toolkits();

		foreach ( $toolkits as $toolkit_slug => $toolkit_info ) {
			// Get tools tagged for this profession in this toolkit.
			$toolkit_tools    = $this->toolkit_registry->get_toolkit_tools( $toolkit_slug );
			$profession_tools = $this->toolkit_registry->get_tools_by_profession( $profession_slug );

			// Find intersection.
			$relevant_tools = array_intersect( $toolkit_tools, $profession_tools );

			if ( ! empty( $relevant_tools ) ) {
				$recommendations[ $toolkit_slug ] = array(
					'name'            => $toolkit_info['name'],
					'description'     => $toolkit_info['description'],
					'primary_pattern' => $toolkit_info['primary_pattern'],
					'tool_count'      => count( $relevant_tools ),
					'tools'           => $relevant_tools,
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Get tools by risk level for a profession.
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @param string $risk_level      Risk level (info, standard, destructive).
	 * @return array Array of tool slugs.
	 */
	public function get_tools_by_risk_level( $profession_slug, $risk_level ) {
		if ( ! $this->toolkit_registry ) {
			return array();
		}

		// Get all tools for profession.
		$profession_tools = $this->toolkit_registry->get_tools_by_profession( $profession_slug );

		// Filter by risk level.
		$risk_tools = $this->toolkit_registry->get_tools_by_risk_level( $risk_level );

		return array_values( array_intersect( $profession_tools, $risk_tools ) );
	}

	/**
	 * Get safe tools for a profession (info risk level only).
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of tool slugs.
	 */
	public function get_safe_tools( $profession_slug ) {
		return $this->get_tools_by_risk_level( $profession_slug, 'info' );
	}

	/**
	 * Get destructive tools for a profession (requires extra caution).
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of tool slugs.
	 */
	public function get_destructive_tools( $profession_slug ) {
		return $this->get_tools_by_risk_level( $profession_slug, 'destructive' );
	}

	/**
	 * Get tools compatible with a specific multi-agent pattern.
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @param string $pattern_slug    Pattern slug.
	 * @return array Array of tool slugs.
	 */
	public function get_pattern_compatible_tools( $profession_slug, $pattern_slug ) {
		if ( ! $this->toolkit_registry ) {
			return array();
		}

		// Get all tools for profession.
		$profession_tools = $this->toolkit_registry->get_tools_by_profession( $profession_slug );

		// Filter by pattern compatibility.
		$pattern_tools = $this->toolkit_registry->get_tools_by_pattern( $pattern_slug );

		return array_values( array_intersect( $profession_tools, $pattern_tools ) );
	}

	/**
	 * Get profession statistics from toolkit registry.
	 *
	 * @since 1.1.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Statistics array.
	 */
	public function get_profession_stats( $profession_slug ) {
		if ( ! $this->toolkit_registry ) {
			return array(
				'total_tools'       => 0,
				'toolkits_used'     => 0,
				'patterns_used'     => 0,
				'safe_tools'        => 0,
				'destructive_tools' => 0,
			);
		}

		$all_tools = $this->toolkit_registry->get_tools_by_profession( $profession_slug );

		// Count toolkits.
		$toolkits_used = array();
		foreach ( $all_tools as $tool_slug ) {
			$metadata = $this->toolkit_registry->get_tool_metadata( $tool_slug );
			if ( isset( $metadata['toolkit'] ) ) {
				$toolkits_used[ $metadata['toolkit'] ] = true;
			}
		}

		// Count patterns.
		$patterns_used = array();
		foreach ( $all_tools as $tool_slug ) {
			$metadata = $this->toolkit_registry->get_tool_metadata( $tool_slug );
			if ( isset( $metadata['pattern_compatibility'] ) ) {
				foreach ( $metadata['pattern_compatibility'] as $pattern ) {
					$patterns_used[ $pattern ] = true;
				}
			}
		}

		return array(
			'total_tools'       => count( $all_tools ),
			'toolkits_used'     => count( $toolkits_used ),
			'patterns_used'     => count( $patterns_used ),
			'safe_tools'        => count( $this->get_safe_tools( $profession_slug ) ),
			'destructive_tools' => count( $this->get_destructive_tools( $profession_slug ) ),
			'toolkit_breakdown' => array_keys( $toolkits_used ),
			'pattern_breakdown' => array_keys( $patterns_used ),
		);
	}
}
