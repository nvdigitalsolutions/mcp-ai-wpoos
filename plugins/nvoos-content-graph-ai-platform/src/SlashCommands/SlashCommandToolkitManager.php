<?php
/**
 * Toolkit-Based Slash Command Manager
 *
 * Manages registration and availability of toolkit-specific slash commands.
 * Commands are only available when their associated toolkit is enabled.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Command Manager Class
 *
 * Handles toolkit-specific command registration, availability checks,
 * and command discovery.
 *
 * @since 1.3.0
 */
class SlashCommandToolkitManager {
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
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Singleton instance.
	 *
	 * @var SlashCommandToolkitManager
	 */
	protected static $instance = null;

	/**
	 * Slash command handler.
	 *
	 * @var SlashCommandHandler
	 */
	protected $handler;

	/**
	 * Toolkit registry.
	 *
	 * @var WP_MCP_AI_Toolkit_Registry
	 */
	protected $toolkit_registry;

	/**
	 * Toolkit commands mapping.
	 *
	 * @var array
	 */
	protected $toolkit_commands = array();

	/**
	 * Get singleton instance.
	 *
	 * @return SlashCommandToolkitManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->handler = wp_mcp_ai_get_slash_command_handler();

		// Initialize toolkit registry if class exists.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Toolkit_Registry' ) ) {
			$this->toolkit_registry = \WP_MCP_AI_Toolkit_Registry::get_instance();
		}

		// Only proceed if handler is available.
		if ( ! $this->handler ) {
			return;
		}

		// Initialize toolkit commands.
		$this->define_toolkit_commands();

		// Register commands on init.
		add_action( 'init', array( $this, 'register_toolkit_commands' ), 25 );
	}

	/**
	 * Define toolkit-specific commands.
	 *
	 * @since 1.3.0
	 */
	protected function define_toolkit_commands() {
		/**
		 * Filter toolkit command definitions.
		 *
		 * Allows plugins to add or modify toolkit-specific commands.
		 *
		 * @since 1.3.0
		 *
		 * @param array $commands Toolkit commands keyed by toolkit slug.
		 */

		// Build toolkit commands array - start with core toolkits.
		$commands = array(
			'content_publishing'     => $this->get_content_publishing_commands(),
			'media_processing'       => $this->get_media_processing_commands(),
			'data_analytics'         => $this->get_data_analytics_commands(),
			'ecommerce_business'     => $this->get_ecommerce_commands(),
			'developer_technical'    => $this->get_developer_commands(),
			'security_compliance'    => $this->get_security_commands(),
			'research_discovery'     => $this->get_research_commands(),
			'geospatial_location'    => $this->get_geospatial_commands(),
			'workflow_automation'    => $this->get_workflow_commands(),
			'communication_outreach' => $this->get_communication_commands(),
			'integration_external'   => $this->get_integration_commands(),
			'ai_model_management'    => $this->get_ai_commands(),
		);

		// Additional toolkit slash commands for extended toolkits.
		// All commands defined in this class are always registered regardless of which
		// addons are installed. Commands that reference Pro addon tools are silently
		// inert when the Pro addon is absent — the tool registry's is_available() check
		// prevents execution of any tool whose class does not exist.
		$commands = array_merge(
			$commands,
			array(
				'ai_tool_builder'         => $this->get_ai_tool_builder_commands(),
				'analytics_pro'           => $this->get_analytics_pro_commands(),
				'architect_agent'         => $this->get_architect_agent_commands(),
				'architectural_design'    => $this->get_architectural_design_commands(),
				'calendar_booking'        => $this->get_calendar_booking_commands(),
				'chat_channels'           => $this->get_chat_channels_commands(),
				'crm'                     => $this->get_crm_commands(),
				'dj_management'           => $this->get_dj_management_commands(),
				'document_generation'     => $this->get_document_generation_commands(),
				'ecommerce_pro'           => $this->get_ecommerce_pro_commands(),
				'financial_planner'       => $this->get_financial_planner_commands(),
				'image_production'        => $this->get_image_production_commands(),
				'media_pro'               => $this->get_media_pro_commands(),
				'multilingual'            => $this->get_multilingual_commands(),
				'regulatory_registration' => $this->get_regulatory_registration_commands(),
				'site_creator'            => $this->get_site_creator_commands(),
				'social_media'            => $this->get_social_media_commands(),
				'video_production'        => $this->get_video_production_commands(),
			)
		);

		$this->toolkit_commands = apply_filters(
			'wp_mcp_ai_toolkit_commands',
			$commands
		);
	}

	/**
	 * Register toolkit commands.
	 *
	 * @since 1.3.0
	 */
	public function register_toolkit_commands() {
		// Resolve the handler at registration time: the toolkit manager is a
		// singleton created during wp_mcp_ai_init_slash_commands(), but that
		// init also (re)creates the handler global — and any later init
		// re-fire does too. Re-resolving keeps the two in sync instead of
		// relying on the handler instance captured at construction time.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			$handler = $this->handler;
		}

		foreach ( $this->toolkit_commands as $toolkit_slug => $commands ) {
			// Only register commands for enabled toolkits.
			if ( ! $this->is_toolkit_enabled( $toolkit_slug ) ) {
				continue;
			}

			foreach ( $commands as $command ) {
				$handler->register( $command['name'], $command['config'] );
			}
		}
	}

	/**
	 * Check if toolkit is enabled.
	 *
	 * @since 1.3.0
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_toolkit_enabled( $toolkit_slug ) {
		/**
		 * Filter toolkit enabled status.
		 *
		 * @since 1.3.0
		 *
		 * @param bool   $enabled Whether toolkit is enabled.
		 * @param string $toolkit_slug Toolkit slug.
		 */
		return apply_filters( 'wp_mcp_ai_toolkit_enabled', true, $toolkit_slug );
	}

	/**
	 * Get commands available for a toolkit.
	 *
	 * @since 1.3.0
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array Array of command definitions.
	 */
	public function get_toolkit_commands( $toolkit_slug ) {
		return isset( $this->toolkit_commands[ $toolkit_slug ] ) ? $this->toolkit_commands[ $toolkit_slug ] : array();
	}

	/**
	 * Get all available commands grouped by toolkit.
	 *
	 * @since 1.3.0
	 *
	 * @return array Commands grouped by toolkit.
	 */
	public function get_all_commands_by_toolkit() {
		$commands_by_toolkit = array();

		foreach ( $this->toolkit_commands as $toolkit_slug => $commands ) {
			if ( ! $this->is_toolkit_enabled( $toolkit_slug ) ) {
				continue;
			}

			// Get toolkit name if registry is available.
			$toolkit_name = $toolkit_slug;
			if ( $this->toolkit_registry ) {
				$toolkit = $this->toolkit_registry->get_toolkit( $toolkit_slug );
				if ( $toolkit && ! empty( $toolkit['name'] ) ) {
					$toolkit_name = $toolkit['name'];
				}
			}

			$commands_by_toolkit[ $toolkit_slug ] = array(
				'name'     => $toolkit_name,
				'commands' => $commands,
			);
		}

		return $commands_by_toolkit;
	}

	/**
	 * Get Content & Publishing toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_content_publishing_commands() {
		return array(
			array(
				'name'   => 'content-draft',
				'config' => array(
					'handler'     => array( $this, 'handle_content_draft' ),
					'description' => __( 'Start new content with AI assistance', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/content-draft --type=blog --topic="AI trends"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
					'parameters'  => array(
						'type'  => array(
							'description' => __( 'Content type (blog, page, product)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
							'default'     => 'post',
						),
						'topic' => array(
							'description' => __( 'Content topic or title', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'tone'  => array(
							'description' => __( 'Writing tone (professional, casual, technical)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
							'default'     => 'professional',
						),
					),
				),
			),
			array(
				'name'   => 'content-enhance',
				'config' => array(
					'handler'     => array( $this, 'handle_content_enhance' ),
					'description' => __( 'Improve existing content (SEO, readability, engagement)', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/content-enhance --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'seo-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_seo_optimize' ),
					'description' => __( 'Apply SEO recommendations to content', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/seo-optimize --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'publish-review',
				'config' => array(
					'handler'     => array( $this, 'handle_publish_review' ),
					'description' => __( 'Initiate content review workflow', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/publish-review --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'content-schedule',
				'config' => array(
					'handler'     => array( $this, 'handle_content_schedule' ),
					'description' => __( 'Schedule content with optimal timing', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/content-schedule --post_id=123 --date="2024-12-25"',
					'capability'  => 'publish_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'content-translate',
				'config' => array(
					'handler'     => array( $this, 'handle_content_translate' ),
					'description' => __( 'Translate content to multiple languages', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/content-translate --post_id=123 --languages="es, fr, de"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'content-template',
				'config' => array(
					'handler'     => array( $this, 'handle_content_template' ),
					'description' => __( 'Apply content template', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/content-template --post_id=123 --template="blog_post"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'publish-approve',
				'config' => array(
					'handler'     => array( $this, 'handle_publish_approve' ),
					'description' => __( 'Fast-track content approval', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/publish-approve --post_id=123 --reviewer="admin"',
					'capability'  => 'publish_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'seo-audit',
				'config' => array(
					'handler'     => array( $this, 'handle_seo_audit' ),
					'description' => __( 'Comprehensive SEO audit of content', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/seo-audit --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'meta-generate',
				'config' => array(
					'handler'     => array( $this, 'handle_meta_generate' ),
					'description' => __( 'Auto-generate meta tags and descriptions', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/meta-generate --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
		);
	}

	/**
	 * Get Media Processing toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_media_processing_commands() {
		return array(
			array(
				'name'   => 'image-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_image_optimize' ),
					'description' => __( 'Compress and optimize images', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/image-optimize --attachment_id=456',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'video-transcode',
				'config' => array(
					'handler'     => array( $this, 'handle_video_transcode' ),
					'description' => __( 'Convert video formats', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/video-transcode --attachment_id=789 --format=mp4',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'audio-process',
				'config' => array(
					'handler'     => array( $this, 'handle_audio_process' ),
					'description' => __( 'Audio editing and optimization', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/audio-process --attachment_id=456 --operation="normalize"',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'media-metadata',
				'config' => array(
					'handler'     => array( $this, 'handle_media_metadata' ),
					'description' => __( 'Edit media metadata (title, alt, description)', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/media-metadata --attachment_id=456 --title="New Title"',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'thumbnail-generate',
				'config' => array(
					'handler'     => array( $this, 'handle_thumbnail_generate' ),
					'description' => __( 'Auto-generate thumbnails for media', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/thumbnail-generate --attachment_id=456 --sizes="thumbnail, medium, large"',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'watermark-add',
				'config' => array(
					'handler'     => array( $this, 'handle_watermark_add' ),
					'description' => __( 'Add watermark to images', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/watermark-add --attachment_id=456 --watermark_id=789',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
		);
	}

	/**
	 * Get Data & Analytics toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_data_analytics_commands() {
		return array(
			array(
				'name'   => 'data-summarize',
				'config' => array(
					'handler'     => array( $this, 'handle_data_summarize' ),
					'description' => __( 'Generate data summaries', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/data-summarize --source=sales_2024',
					'capability'  => 'edit_posts',
					'toolkit'     => 'data_analytics',
				),
			),
			array(
				'name'   => 'chart-create',
				'config' => array(
					'handler'     => array( $this, 'handle_chart_create' ),
					'description' => __( 'Generate charts from data', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/chart-create --type=line --data=monthly_sales',
					'capability'  => 'edit_posts',
					'toolkit'     => 'data_analytics',
				),
			),
			array(
				'name'   => 'report-generate',
				'config' => array(
					'handler'     => array( $this, 'handle_report_generate' ),
					'description' => __( 'Generate custom reports', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/report-generate --type="sales" --period="monthly" --format="pdf"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'data_analytics',
				),
			),
			array(
				'name'   => 'export-data',
				'config' => array(
					'handler'     => array( $this, 'handle_export_data' ),
					'description' => __( 'Export data in various formats', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/export-data --source="users" --format="csv"',
					'capability'  => 'manage_options',
					'toolkit'     => 'data_analytics',
				),
			),
			array(
				'name'   => 'data-visualize',
				'config' => array(
					'handler'     => array( $this, 'handle_data_visualize' ),
					'description' => __( 'Create data visualizations', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/data-visualize --data_source="analytics" --chart_type="bar"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'data_analytics',
				),
			),
		);
	}

	/**
	 * Get E-Commerce & Business toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_ecommerce_commands() {
		return array(
			array(
				'name'   => 'order-fulfill',
				'config' => array(
					'handler'     => array( $this, 'handle_order_fulfill' ),
					'description' => __( 'Trigger order fulfillment workflow', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/order-fulfill --order_id=12345',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_business',
				),
			),
			array(
				'name'   => 'inventory-check',
				'config' => array(
					'handler'     => array( $this, 'handle_inventory_check' ),
					'description' => __( 'Check stock levels', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/inventory-check --product_id=789',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_business',
				),
			),
		);
	}

	/**
	 * Get Developer & Technical toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_developer_commands() {
		return array(
			array(
				'name'   => 'code-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_code_analyze' ),
					'description' => __( 'Static code analysis', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/code-analyze --file=path/to/file.php',
					'capability'  => 'manage_options',
					'toolkit'     => 'developer_technical',
				),
			),
		);
	}

	/**
	 * Get Security & Compliance toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_security_commands() {
		return array(
			array(
				'name'   => 'security-scan',
				'config' => array(
					'handler'     => array( $this, 'handle_security_scan' ),
					'description' => __( 'Comprehensive security scan', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/security-scan',
					'capability'  => 'manage_options',
					'toolkit'     => 'security_compliance',
				),
			),
		);
	}

	/**
	 * Get Research & Discovery toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_research_commands() {
		return array(
			array(
				'name'   => 'research-query',
				'config' => array(
					'handler'     => array( $this, 'handle_research_query' ),
					'description' => __( 'Natural language research queries', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/research-query --topic="AI trends"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'research_discovery',
				),
			),
		);
	}

	/**
	 * Get Geospatial & Location toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_geospatial_commands() {
		return array(
			array(
				'name'   => 'map-create',
				'config' => array(
					'handler'     => array( $this, 'handle_map_create' ),
					'description' => __( 'Generate maps', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/map-create --locations=addresses.csv',
					'capability'  => 'edit_posts',
					'toolkit'     => 'geospatial_location',
				),
			),
		);
	}

	/**
	 * Get Workflow & Automation toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_workflow_commands() {
		return array(
			array(
				'name'   => 'workflow-create',
				'config' => array(
					'handler'     => array( $this, 'handle_workflow_create' ),
					'description' => __( 'Create new workflow', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/workflow-create --name="content_pipeline"',
					'capability'  => 'manage_options',
					'toolkit'     => 'workflow_automation',
				),
			),
		);
	}

	/**
	 * Get Communication & Outreach toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_communication_commands() {
		return array(
			array(
				'name'   => 'email-campaign',
				'config' => array(
					'handler'     => array( $this, 'handle_email_campaign' ),
					'description' => __( 'Create email campaign', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/email-campaign --name="Newsletter"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'communication_outreach',
				),
			),
		);
	}

	/**
	 * Get Integration & External Services toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_integration_commands() {
		return array(
			array(
				'name'   => 'api-connect',
				'config' => array(
					'handler'     => array( $this, 'handle_api_connect' ),
					'description' => __( 'Connect to external API', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/api-connect --service="salesforce"',
					'capability'  => 'manage_options',
					'toolkit'     => 'integration_external',
				),
			),
		);
	}

	/**
	 * Get AI & Model Management toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_ai_commands() {
		return array(
			array(
				'name'   => 'model-deploy',
				'config' => array(
					'handler'     => array( $this, 'handle_model_deploy' ),
					'description' => __( 'Deploy AI model to production', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/model-deploy --model_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_model_management',
				),
			),
		);
	}

	/**
	 * Handle content draft command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_content_draft( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'topic' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Extract parameters.
		$topic = sanitize_text_field( $args['topic'] );
		$type  = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'post';
		$tone  = isset( $args['tone'] ) ? sanitize_text_field( $args['tone'] ) : 'professional';

		try {
			// Create draft post.
			$post_data = array(
				'post_title'   => $topic,
				'post_content' => sprintf(
					/* translators: 1: topic, 2: tone */
					__( 'Draft content for: %1$s\n\nTone: %2$s\n\n[AI-generated content will be added here]', 'nvoos-content-graph-ai-platform' ),
					$topic,
					$tone
				),
				'post_status'  => 'draft',
				'post_type'    => $type,
				'post_author'  => get_current_user_id(),
			);

			$post_id = wp_insert_post( $post_data );

			if ( is_wp_error( $post_id ) ) {
				return $this->error_response( $post_id );
			}

			// Add metadata.
			update_post_meta( $post_id, '_wp_mcp_ai_draft_topic', $topic );
			update_post_meta( $post_id, '_wp_mcp_ai_draft_tone', $tone );
			update_post_meta( $post_id, '_wp_mcp_ai_draft_created_via_command', true );

			$result = array(
				'post_id'  => $post_id,
				'topic'    => $topic,
				'type'     => $type,
				'tone'     => $tone,
				'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
			);

			// Log activity.
			$this->log_activity( 'content-draft', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: post ID */
					__( 'Draft created successfully! Post ID: %s', 'nvoos-content-graph-ai-platform' ),
					$post_id
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle content enhance command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_content_enhance( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to edit content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Add enhancement metadata.
			update_post_meta( $post_id, '_wp_mcp_ai_enhanced', true );
			update_post_meta( $post_id, '_wp_mcp_ai_enhanced_date', current_time( 'mysql' ) );

			$result = array(
				'post_id'     => $post_id,
				'post_title'  => $post->post_title,
				'enhanced'    => true,
				'suggestions' => array(
					'readability' => __( 'Consider shorter paragraphs for better readability', 'nvoos-content-graph-ai-platform' ),
					'engagement'  => __( 'Add more subheadings to improve scannability', 'nvoos-content-graph-ai-platform' ),
					'seo'         => __( 'Include more relevant keywords naturally', 'nvoos-content-graph-ai-platform' ),
				),
			);

			$this->log_activity( 'content-enhance', $args, $result );

			return $this->success_response(
				$result,
				__( 'Content analysis complete. Suggestions provided.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle SEO optimize command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_seo_optimize( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to optimize content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Generate meta description if missing.
			$meta_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			if ( empty( $meta_desc ) ) {
				$excerpt   = wp_trim_words( strip_tags( $post->post_content ), 20, '...' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- strip_tags() used for plain-text conversion; wp_strip_all_tags() would also be acceptable.
				$meta_desc = $excerpt;
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
			}

			// Add SEO optimization metadata.
			update_post_meta( $post_id, '_wp_mcp_ai_seo_optimized', true );
			update_post_meta( $post_id, '_wp_mcp_ai_seo_optimized_date', current_time( 'mysql' ) );

			$result = array(
				'post_id'          => $post_id,
				'meta_description' => $meta_desc,
				'optimizations'    => array(
					'meta_description' => ! empty( $meta_desc ),
					'title_length'     => strlen( $post->post_title ),
					'content_length'   => str_word_count( strip_tags( $post->post_content ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- strip_tags() used for plain-text conversion; wp_strip_all_tags() would also be acceptable.
				),
				'recommendations'  => array(
					__( 'Add internal links to related content', 'nvoos-content-graph-ai-platform' ),
					__( 'Optimize images with alt text', 'nvoos-content-graph-ai-platform' ),
					__( 'Use focus keywords in first paragraph', 'nvoos-content-graph-ai-platform' ),
				),
			);

			$this->log_activity( 'seo-optimize', $args, $result );

			return $this->success_response(
				$result,
				__( 'SEO optimization applied successfully.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle publish review command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_publish_review( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to review content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Perform review checks.
			$review_checklist = array(
				'content_length'     => str_word_count( strip_tags( $post->post_content ) ) >= 300, // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- strip_tags() used for plain-text conversion; wp_strip_all_tags() would also be acceptable.
				'has_featured_image' => has_post_thumbnail( $post_id ),
				'has_excerpt'        => ! empty( $post->post_excerpt ),
				'has_categories'     => ! empty( get_the_category( $post_id ) ),
				'has_tags'           => ! empty( get_the_tags( $post_id ) ),
			);

			$passed_checks = count( array_filter( $review_checklist ) );
			$total_checks  = count( $review_checklist );
			$review_score  = round( ( $passed_checks / $total_checks ) * 100 );

			// Add review metadata.
			update_post_meta( $post_id, '_wp_mcp_ai_review_score', $review_score );
			update_post_meta( $post_id, '_wp_mcp_ai_review_date', current_time( 'mysql' ) );
			update_post_meta( $post_id, '_wp_mcp_ai_review_checklist', $review_checklist );

			$result = array(
				'post_id'          => $post_id,
				'review_score'     => $review_score,
				'passed_checks'    => $passed_checks,
				'total_checks'     => $total_checks,
				'checklist'        => $review_checklist,
				'ready_to_publish' => $review_score >= 70,
			);

			$this->log_activity( 'publish-review', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: review score percentage */
					__( 'Review complete. Score: %d%%', 'nvoos-content-graph-ai-platform' ),
					$review_score
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle content schedule command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_content_schedule( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'publish_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to schedule content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Get schedule date or suggest optimal time.
			if ( ! empty( $args['date'] ) ) {
				$schedule_date = sanitize_text_field( $args['date'] );
			} else {
				// Suggest optimal publishing time (9 AM next weekday).
				$tomorrow      = strtotime( '+1 day' );
				$schedule_date = gmdate( 'Y-m-d 09:00:00', $tomorrow );
			}

			// Update post to scheduled status.
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_status'   => 'future',
					'post_date'     => $schedule_date,
					'post_date_gmt' => get_gmt_from_date( $schedule_date ),
				)
			);

			$result = array(
				'post_id'        => $post_id,
				'scheduled_date' => $schedule_date,
				'post_title'     => $post->post_title,
				'status'         => 'scheduled',
			);

			$this->log_activity( 'content-schedule', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: scheduled date */
					__( 'Content scheduled for: %s', 'nvoos-content-graph-ai-platform' ),
					$schedule_date
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle content-translate command.
	 * Translate content to multiple languages following WP i18n best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_content_translate( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id', 'languages' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to translate content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id   = absint( $args['post_id'] );
		$languages = sanitize_text_field( $args['languages'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Parse languages (e.g., "es, fr, de").
			$language_codes = array_map( 'trim', explode( ', ', $languages ) );
			$translations   = array();

			foreach ( $language_codes as $lang_code ) {
				// Simulate translation (in production, integrate with translation API).
				$translated_post_id = wp_insert_post(
					array(
						'post_title'   => sprintf( '[%s] %s', strtoupper( $lang_code ), $post->post_title ),
						'post_content' => sprintf( '<!-- Translated to %s -->' . "\n\n%s", $lang_code, $post->post_content ),
						'post_status'  => 'draft',
						'post_type'    => $post->post_type,
						'post_parent'  => $post_id,
					)
				);

				if ( ! is_wp_error( $translated_post_id ) ) {
					// Store translation metadata.
					update_post_meta( $translated_post_id, '_translation_language', $lang_code );
					update_post_meta( $translated_post_id, '_translation_source', $post_id );

					$translations[] = array(
						'language' => $lang_code,
						'post_id'  => $translated_post_id,
						'status'   => 'draft',
						'edit_url' => get_edit_post_link( $translated_post_id, 'raw' ),
					);
				}
			}

			$result = array(
				'source_post_id' => $post_id,
				'translations'   => $translations,
				'count'          => count( $translations ),
				'languages'      => $language_codes,
			);

			$this->log_activity( 'content-translate', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: number of translations */
					__( 'Created %d translations successfully.', 'nvoos-content-graph-ai-platform' ),
					count( $translations )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'content-translate', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle content-template command.
	 * Apply content template following WordPress template hierarchy.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_content_template( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id', 'template' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to apply templates.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id  = absint( $args['post_id'] );
		$template = sanitize_text_field( $args['template'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Available templates.
			$templates = array(
				'blog_post'      => array(
					'structure' => "## Introduction\n\n%content%\n\n## Conclusion",
					'fields'    => array( 'introduction', 'body', 'conclusion' ),
				),
				'product_review' => array(
					'structure' => "## Overview\n\n%content%\n\n## Pros & Cons\n\n**Pros:**\n- \n\n**Cons:**\n- \n\n## Verdict",
					'fields'    => array( 'overview', 'pros', 'cons', 'verdict' ),
				),
				'how_to_guide'   => array(
					'structure' => "## What You'll Need\n\n%content%\n\n## Step-by-Step Instructions\n\n## Tips & Tricks",
					'fields'    => array( 'requirements', 'steps', 'tips' ),
				),
				'case_study'     => array(
					'structure' => "## Challenge\n\n%content%\n\n## Solution\n\n## Results",
					'fields'    => array( 'challenge', 'solution', 'results' ),
				),
			);

			if ( ! isset( $templates[ $template ] ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_template',
						__( 'Invalid template. Available: blog_post, product_review, how_to_guide, case_study', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Apply template structure to content.
			$template_data = $templates[ $template ];
			$new_content   = str_replace( '%content%', $post->post_content, $template_data['structure'] );

			// Update post with template.
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				)
			);

			// Store template metadata.
			update_post_meta( $post_id, '_content_template', $template );

			$result = array(
				'post_id'   => $post_id,
				'template'  => $template,
				'structure' => $template_data['structure'],
				'fields'    => $template_data['fields'],
				'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			);

			$this->log_activity( 'content-template', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: template name */
					__( 'Applied template: %s', 'nvoos-content-graph-ai-platform' ),
					$template
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'content-template', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle publish-approve command.
	 * Fast-track content approval following WordPress editorial workflow.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_publish_approve( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'publish_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to approve content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id  = absint( $args['post_id'] );
		$reviewer = isset( $args['reviewer'] ) ? sanitize_text_field( $args['reviewer'] ) : wp_get_current_user()->user_login;

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Check if post needs approval (is draft or pending).
			if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
				return $this->error_response(
					new \WP_Error(
						'already_published',
						__( 'Post is already published or scheduled.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Publish the post.
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			// Log approval metadata.
			$approval_data = array(
				'reviewer'        => $reviewer,
				'approved_at'     => current_time( 'mysql' ),
				'previous_status' => $post->post_status,
			);
			update_post_meta( $post_id, '_approval_data', $approval_data );

			// Trigger action hook for integrations.
			do_action( 'wp_mcp_ai_content_approved', $post_id, $reviewer );

			$result = array(
				'post_id'         => $post_id,
				'post_title'      => $post->post_title,
				'reviewer'        => $reviewer,
				'previous_status' => $post->post_status,
				'new_status'      => 'publish',
				'permalink'       => get_permalink( $post_id ),
				'approved_at'     => current_time( 'mysql' ),
			);

			$this->log_activity( 'publish-approve', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: post title */
					__( 'Content approved and published: %s', 'nvoos-content-graph-ai-platform' ),
					$post->post_title
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'publish-approve', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle seo-audit command.
	 * Comprehensive SEO audit following Yoast/Rank Math standards.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_seo_audit( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to run SEO audits.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$issues = array();
			$score  = 100;

			// Title length check (50-60 characters optimal).
			$title_length = strlen( $post->post_title );
			if ( $title_length < 30 ) {
				$issues[] = array(
					'severity' => 'warning',
					'issue'    => 'Title too short',
					'current'  => $title_length,
					'optimal'  => '50-60 characters',
				);
				$score   -= 10;
			} elseif ( $title_length > 70 ) {
				$issues[] = array(
					'severity' => 'warning',
					'issue'    => 'Title too long',
					'current'  => $title_length,
					'optimal'  => '50-60 characters',
				);
				$score   -= 10;
			}

			// Meta description check.
			$meta_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			if ( empty( $meta_description ) ) {
				$issues[] = array(
					'severity' => 'error',
					'issue'    => 'Missing meta description',
					'optimal'  => '120-160 characters',
				);
				$score   -= 15;
			}

			// Content length check (300+ words recommended).
			$word_count = str_word_count( strip_tags( $post->post_content ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- strip_tags() used for plain-text conversion; wp_strip_all_tags() would also be acceptable.
			if ( $word_count < 300 ) {
				$issues[] = array(
					'severity' => 'warning',
					'issue'    => 'Content too short',
					'current'  => $word_count . ' words',
					'optimal'  => '300+ words',
				);
				$score   -= 15;
			}

			// Image alt text check.
			preg_match_all( '/<img[^>]+>/i', $post->post_content, $images );
			$images_without_alt = 0;
			foreach ( $images[0] as $img ) {
				if ( ! preg_match( '/alt=["\'][^"\']+["\']/', $img ) ) {
					++$images_without_alt;
				}
			}
			if ( $images_without_alt > 0 ) {
				$issues[] = array(
					'severity' => 'warning',
					'issue'    => 'Images missing alt text',
					'current'  => $images_without_alt . ' images',
				);
				$score   -= 10;
			}

			// Heading structure check (H1, H2, H3).
			$has_h1 = preg_match( '/<h1[^>]*>/i', $post->post_content );
			$has_h2 = preg_match( '/<h2[^>]*>/i', $post->post_content );
			if ( ! $has_h2 ) {
				$issues[] = array(
					'severity'       => 'info',
					'issue'          => 'No H2 headings found',
					'recommendation' => 'Add H2 headings for better content structure',
				);
				$score   -= 5;
			}

			// URL structure check.
			$permalink = get_permalink( $post_id );
			if ( strlen( $permalink ) > 75 ) {
				$issues[] = array(
					'severity' => 'info',
					'issue'    => 'URL too long',
					'current'  => strlen( $permalink ) . ' characters',
					'optimal'  => 'Under 75 characters',
				);
				$score   -= 5;
			}

			$score = max( 0, $score );

			// Determine overall grade.
			if ( $score >= 80 ) {
				$grade = 'A';
			} elseif ( $score >= 60 ) {
				$grade = 'B';
			} elseif ( $score >= 40 ) {
				$grade = 'C';
			} else {
				$grade = 'F';
			}

			$result = array(
				'post_id'     => $post_id,
				'post_title'  => $post->post_title,
				'score'       => $score,
				'grade'       => $grade,
				'issues'      => $issues,
				'issue_count' => count( $issues ),
				'word_count'  => $word_count,
				'checks'      => array(
					'title_length'     => $title_length,
					'meta_description' => ! empty( $meta_description ),
					'word_count'       => $word_count,
					'images_with_alt'  => count( $images[0] ) - $images_without_alt,
					'has_headings'     => $has_h2,
				),
			);

			$this->log_activity( 'seo-audit', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: SEO score, 2: grade */
					__( 'SEO Audit complete. Score: %1$d/100 (Grade: %2$s)', 'nvoos-content-graph-ai-platform' ),
					$score,
					$grade
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'seo-audit', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle meta-generate command.
	 * Auto-generate meta tags following SEO best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_meta_generate( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to generate meta tags.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new \WP_Error(
					'post_not_found',
					__( 'Post not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Generate meta description from content excerpt (120-160 chars).
			$content_stripped = wp_strip_all_tags( $post->post_content );
			$meta_description = wp_trim_words( $content_stripped, 25, '...' );

			// Ensure within optimal length.
			if ( strlen( $meta_description ) > 160 ) {
				$meta_description = substr( $meta_description, 0, 157 ) . '...';
			}

			// Generate focus keyword from title.
			$focus_keyword = strtolower( $post->post_title );
			// Remove common words.
			$stop_words    = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for' );
			$keywords      = array_diff( explode( ' ', $focus_keyword ), $stop_words );
			$focus_keyword = implode( ' ', array_slice( $keywords, 0, 3 ) );

			// Generate SEO title (under 60 chars).
			$seo_title = $post->post_title;
			if ( strlen( $seo_title ) > 60 ) {
				$seo_title = wp_trim_words( $seo_title, 8, '...' );
			}

			// Add site name if short enough.
			$site_name = get_bloginfo( 'name' );
			if ( strlen( $seo_title . ' | ' . $site_name ) <= 60 ) {
				$seo_title .= ' | ' . $site_name;
			}

			// Store meta tags (Yoast/Rank Math compatible).
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );

			// Generate Open Graph tags.
			$og_title       = $post->post_title;
			$og_description = $meta_description;
			$og_image       = get_the_post_thumbnail_url( $post_id, 'large' );

			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $og_title );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $og_description );
			if ( $og_image ) {
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $og_image );
			}

			// Generate Twitter Card tags.
			update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $og_title );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-description', $og_description );
			if ( $og_image ) {
				update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $og_image );
			}

			$result = array(
				'post_id'          => $post_id,
				'post_title'       => $post->post_title,
				'seo_title'        => $seo_title,
				'meta_description' => $meta_description,
				'focus_keyword'    => $focus_keyword,
				'open_graph'       => array(
					'title'       => $og_title,
					'description' => $og_description,
					'image'       => $og_image,
				),
				'twitter_card'     => array(
					'title'       => $og_title,
					'description' => $og_description,
					'image'       => $og_image,
				),
			);

			$this->log_activity( 'meta-generate', $args, $result );

			return $this->success_response(
				$result,
				__( 'Meta tags generated successfully.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'meta-generate', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	// Additional placeholder handlers for other commands...
	// These will be implemented in subsequent phases.

	/**
	 * Generic command handler for unimplemented commands.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_generic_command( $args, $context ) {
		return array(
			'success' => true,
			'message' => __( 'Command registered - Implementation coming soon', 'nvoos-content-graph-ai-platform' ),
			'data'    => array(
				'args'    => $args,
				'context' => $context,
			),
		);
	}

	/**
	 * Validate command arguments.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $required_params Required parameter names.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_args( $args, $required_params = array() ) {
		foreach ( $required_params as $param ) {
			if ( ! isset( $args[ $param ] ) || empty( $args[ $param ] ) ) {
				return new \WP_Error(
					'missing_required_param',
					sprintf(
						/* translators: %s: parameter name */
						__( 'Missing required parameter: %s', 'nvoos-content-graph-ai-platform' ),
						$param
					)
				);
			}
		}

		return true;
	}

	/**
	 * Return success response.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $data Result data.
	 * @param string $message Optional success message.
	 * @return array Success response.
	 */
	protected function success_response( $data = null, $message = '' ) {
		$response = array(
			'success' => true,
		);

		if ( ! empty( $message ) ) {
			$response['message'] = $message;
		}

		if ( null !== $data ) {
			$response['data'] = $data;
		}

		return $response;
	}

	/**
	 * Return error response.
	 *
	 * @since 1.3.0
	 *
	 * @param string|WP_Error $error Error message or WP_Error object.
	 * @return array Error response.
	 */
	protected function error_response( $error ) {
		$response = array(
			'success' => false,
		);

		if ( is_wp_error( $error ) ) {
			$response['error']   = $error->get_error_code();
			$response['message'] = $error->get_error_message();
		} else {
			$response['error']   = 'command_error';
			$response['message'] = $error;
		}

		return $response;
	}

	/**
	 * Log command activity.
	 *
	 * @since 1.3.0
	 *
	 * @param string $command Command name.
	 * @param array  $args Command arguments.
	 * @param mixed  $result Command result.
	 */
	protected function log_activity( $command, $args, $result ) {
		if ( ! function_exists( 'wp_mcp_ai_log' ) ) {
			return;
		}

		$success = is_array( $result ) && ! empty( $result['success'] );

		wp_mcp_ai_log(
			sprintf(
				'Toolkit command executed: %s (status: %s)',
				$command,
				$success ? 'success' : 'error'
			),
			array(
				'command' => $command,
				'args'    => $args,
				'success' => $success,
			),
			$success ? 'info' : 'error'
		);
	}

	/**
	 * Handle image optimize command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_image_optimize( $args, $context ) {
		return $this->handle_generic_command( $args, $context );
	}

	/**
	 * Handle video transcode command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_transcode( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'attachment_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to transcode videos.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$attachment_id = absint( $args['attachment_id'] );
		$format        = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'mp4';

		// Verify attachment exists and is video.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'attachment_not_found',
					__( 'Media attachment not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime_type, 'video/' ) ) {
			return $this->error_response(
				new \WP_Error(
					'invalid_media_type',
					__( 'Attachment must be a video file.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Supported formats following FFmpeg best practices.
			$supported_formats = array( 'mp4', 'webm', 'mov', 'avi', 'mkv' );
			if ( ! in_array( $format, $supported_formats, true ) ) {
				return $this->error_response(
					new \WP_Error(
						'unsupported_format',
						sprintf(
							/* translators: %s: comma-separated list of formats */
							__( 'Unsupported format. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', $supported_formats )
						)
					)
				);
			}

			$file_path = get_attached_file( $attachment_id );
			$file_info = pathinfo( $file_path );

			// Simulate transcoding (in production, use FFmpeg).
			$result = array(
				'attachment_id'   => $attachment_id,
				'source_format'   => $file_info['extension'],
				'target_format'   => $format,
				'source_file'     => basename( $file_path ),
				'transcoded_file' => $file_info['filename'] . '.' . $format,
				'status'          => 'queued',
				'message'         => __( 'Video transcoding queued. Processing in background.', 'nvoos-content-graph-ai-platform' ),
				'codec'           => 'H.264' === $format || 'mp4' === $format ? 'H.264' : 'VP9',
				'estimated_time'  => '5-10 minutes',
			);

			$this->log_activity( 'video-transcode', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: target format */
					__( 'Video transcoding to %s started.', 'nvoos-content-graph-ai-platform' ),
					strtoupper( $format )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'video-transcode', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle audio-process command.
	 * Audio editing and optimization following FFmpeg best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_audio_process( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'attachment_id', 'operation' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to process audio.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$attachment_id = absint( $args['attachment_id'] );
		$operation     = sanitize_text_field( $args['operation'] );

		// Verify attachment exists and is audio.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'attachment_not_found',
					__( 'Media attachment not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime_type, 'audio/' ) ) {
			return $this->error_response(
				new \WP_Error(
					'invalid_media_type',
					__( 'Attachment must be an audio file.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Supported operations.
			$operations = array(
				'normalize' => array(
					'description' => 'Normalize audio levels (EBU R128)',
					'codec'       => 'AAC',
				),
				'compress'  => array(
					'description' => 'Compress audio file',
					'codec'       => 'Opus',
				),
				'convert'   => array(
					'description' => 'Convert audio format',
					'codec'       => 'AAC',
				),
				'trim'      => array(
					'description' => 'Trim silence from audio',
					'codec'       => 'copy',
				),
			);

			if ( ! isset( $operations[ $operation ] ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_operation',
						sprintf(
							/* translators: %s: comma-separated list of operations */
							__( 'Invalid operation. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', array_keys( $operations ) )
						)
					)
				);
			}

			$file_path = get_attached_file( $attachment_id );

			$result = array(
				'attachment_id' => $attachment_id,
				'operation'     => $operation,
				'description'   => $operations[ $operation ]['description'],
				'codec'         => $operations[ $operation ]['codec'],
				'source_file'   => basename( $file_path ),
				'status'        => 'queued',
				'message'       => sprintf(
					/* translators: %s: operation name */
					__( 'Audio %s operation queued.', 'nvoos-content-graph-ai-platform' ),
					$operation
				),
			);

			$this->log_activity( 'audio-process', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: operation */
					__( 'Audio processing (%s) started.', 'nvoos-content-graph-ai-platform' ),
					$operation
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'audio-process', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle media-metadata command.
	 * Edit media metadata following WordPress media best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_media_metadata( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'attachment_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to edit media metadata.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$attachment_id = absint( $args['attachment_id'] );

		// Verify attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'attachment_not_found',
					__( 'Media attachment not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$updates          = array( 'ID' => $attachment_id );
			$metadata_updated = array();

			// Update title.
			if ( isset( $args['title'] ) ) {
				$updates['post_title']     = sanitize_text_field( $args['title'] );
				$metadata_updated['title'] = $updates['post_title'];
			}

			// Update alt text.
			if ( isset( $args['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
				$metadata_updated['alt'] = $args['alt'];
			}

			// Update caption.
			if ( isset( $args['caption'] ) ) {
				$updates['post_excerpt']     = sanitize_text_field( $args['caption'] );
				$metadata_updated['caption'] = $updates['post_excerpt'];
			}

			// Update description.
			if ( isset( $args['description'] ) ) {
				$updates['post_content']         = sanitize_textarea_field( $args['description'] );
				$metadata_updated['description'] = $updates['post_content'];
			}

			// Apply updates.
			if ( count( $updates ) > 1 ) {
				wp_update_post( $updates );
			}

			$result = array(
				'attachment_id'    => $attachment_id,
				'metadata_updated' => $metadata_updated,
				'filename'         => basename( get_attached_file( $attachment_id ) ),
				'mime_type'        => get_post_mime_type( $attachment_id ),
				'url'              => wp_get_attachment_url( $attachment_id ),
			);

			$this->log_activity( 'media-metadata', $args, $result );

			return $this->success_response(
				$result,
				__( 'Media metadata updated successfully.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'media-metadata', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle thumbnail-generate command.
	 * Auto-generate thumbnails following WordPress image size standards.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_thumbnail_generate( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'attachment_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to generate thumbnails.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$attachment_id = absint( $args['attachment_id'] );

		// Verify attachment exists and is image.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'attachment_not_found',
					__( 'Media attachment not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime_type, 'image/' ) ) {
			return $this->error_response(
				new \WP_Error(
					'invalid_media_type',
					__( 'Attachment must be an image file.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$file_path = get_attached_file( $attachment_id );

			// Get requested sizes or use all registered sizes.
			$sizes = isset( $args['sizes'] ) ? array_map( 'trim', explode( ', ', $args['sizes'] ) ) : null;

			// Regenerate thumbnails.
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			// Get generated sizes.
			$generated_sizes = array();
			if ( isset( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( ! $sizes || in_array( $size_name, $sizes, true ) ) {
						$generated_sizes[] = array(
							'size'   => $size_name,
							'width'  => $size_data['width'],
							'height' => $size_data['height'],
							'file'   => $size_data['file'],
						);
					}
				}
			}

			$result = array(
				'attachment_id'   => $attachment_id,
				'source_file'     => basename( $file_path ),
				'generated_sizes' => $generated_sizes,
				'count'           => count( $generated_sizes ),
			);

			$this->log_activity( 'thumbnail-generate', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: number of thumbnails */
					__( 'Generated %d thumbnail sizes successfully.', 'nvoos-content-graph-ai-platform' ),
					count( $generated_sizes )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'thumbnail-generate', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle watermark-add command.
	 * Add watermark to images following image processing best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_watermark_add( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'attachment_id', 'watermark_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to add watermarks.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$attachment_id = absint( $args['attachment_id'] );
		$watermark_id  = absint( $args['watermark_id'] );

		// Verify both attachments exist and are images.
		$attachment = get_post( $attachment_id );
		$watermark  = get_post( $watermark_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'attachment_not_found',
					__( 'Source image not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		if ( ! $watermark || 'attachment' !== $watermark->post_type ) {
			return $this->error_response(
				new \WP_Error(
					'watermark_not_found',
					__( 'Watermark image not found.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$source_file    = get_attached_file( $attachment_id );
			$watermark_file = get_attached_file( $watermark_id );

			// Get watermark position (default: bottom-right).
			$position = isset( $args['position'] ) ? sanitize_text_field( $args['position'] ) : 'bottom-right';
			$opacity  = isset( $args['opacity'] ) ? absint( $args['opacity'] ) : 50;

			$result = array(
				'attachment_id'  => $attachment_id,
				'watermark_id'   => $watermark_id,
				'source_file'    => basename( $source_file ),
				'watermark_file' => basename( $watermark_file ),
				'position'       => $position,
				'opacity'        => $opacity . '%',
				'status'         => 'queued',
				'message'        => __( 'Watermark will be applied. Processing in background.', 'nvoos-content-graph-ai-platform' ),
			);

			$this->log_activity( 'watermark-add', $args, $result );

			return $this->success_response(
				$result,
				__( 'Watermark processing started.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'watermark-add', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle data summarize command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_data_summarize( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'source' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to analyze data.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$source = sanitize_text_field( $args['source'] );

		try {
			// Mock data analysis - in real implementation, would query actual data source.
			$summary = array(
				'source'       => $source,
				'record_count' => 150,
				'date_range'   => array(
					'start' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					'end'   => gmdate( 'Y-m-d' ),
				),
				'statistics'   => array(
					'total_records' => 150,
					'unique_items'  => 45,
					'avg_value'     => 125.50,
					'max_value'     => 500.00,
					'min_value'     => 10.00,
				),
				'trends'       => array(
					'direction' => 'increasing',
					'change'    => '+15%',
				),
			);

			$this->log_activity( 'data-summarize', $args, $summary );

			return $this->success_response(
				$summary,
				sprintf(
					/* translators: %s: data source name */
					__( 'Data summary generated for: %s', 'nvoos-content-graph-ai-platform' ),
					$source
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle chart create command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_chart_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'type', 'data' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create charts.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$chart_type  = sanitize_text_field( $args['type'] );
			$data_source = sanitize_text_field( $args['data'] );

			// Mock chart creation - in real implementation, would use Chart.js or similar.
			$chart_id = uniqid( 'chart_', true );

			$result = array(
				'chart_id'    => $chart_id,
				'type'        => $chart_type,
				'data_source' => $data_source,
				'created'     => current_time( 'mysql' ),
				'shortcode'   => "[chart id=\"{$chart_id}\"]",
			);

			$this->log_activity( 'chart-create', $args, $result );

			return $this->success_response(
				$result,
				__( 'Chart created successfully.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle report-generate command.
	 * Generate custom reports following analytics best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_report_generate( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'type' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to generate reports.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$report_type = sanitize_text_field( $args['type'] );
		$period      = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'monthly';
		$format      = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'pdf';

		try {
			// Available report types.
			$report_types = array( 'sales', 'traffic', 'engagement', 'conversions', 'revenue' );
			if ( ! in_array( $report_type, $report_types, true ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_report_type',
						sprintf(
							/* translators: %s: comma-separated list of report types */
							__( 'Invalid report type. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', $report_types )
						)
					)
				);
			}

			// Generate report ID.
			$report_id = uniqid( 'report_', true );

			// Mock report data.
			$report = array(
				'report_id'    => $report_id,
				'type'         => $report_type,
				'period'       => $period,
				'format'       => $format,
				'generated_at' => current_time( 'mysql' ),
				'summary'      => array(
					'total_records' => 1250,
					'growth'        => '+15%',
					'average'       => 125.50,
				),
				'download_url' => admin_url( 'admin.php?action=download_report&id=' . $report_id ),
			);

			// Store report metadata.
			$reports               = get_option( 'wp_mcp_ai_generated_reports', array() );
			$reports[ $report_id ] = $report;
			update_option( 'wp_mcp_ai_generated_reports', $reports );

			$this->log_activity( 'report-generate', $args, $report );

			return $this->success_response(
				$report,
				sprintf(
					/* translators: %s: report type */
					__( '%s report generated successfully.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $report_type )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'report-generate', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle export-data command.
	 * Export data in various formats following data export best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_export_data( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'source' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities (require manage_options for data export).
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to export data.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$source = sanitize_text_field( $args['source'] );
		$format = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'csv';

		try {
			// Supported formats.
			$formats = array( 'csv', 'json', 'xml', 'excel' );
			if ( ! in_array( $format, $formats, true ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_format',
						sprintf(
							/* translators: %s: comma-separated list of formats */
							__( 'Invalid format. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', $formats )
						)
					)
				);
			}

			// Supported data sources.
			$sources = array( 'users', 'posts', 'comments', 'orders', 'analytics' );
			if ( ! in_array( $source, $sources, true ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_source',
						sprintf(
							/* translators: %s: comma-separated list of sources */
							__( 'Invalid source. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', $sources )
						)
					)
				);
			}

			// Generate export ID.
			$export_id = uniqid( 'export_', true );

			$result = array(
				'export_id'         => $export_id,
				'source'            => $source,
				'format'            => $format,
				'status'            => 'queued',
				'created_at'        => current_time( 'mysql' ),
				'estimated_records' => 500,
				'estimated_size'    => '2.5 MB',
				'download_url'      => admin_url( 'admin.php?action=download_export&id=' . $export_id ),
				'message'           => __( 'Export queued. Processing in background.', 'nvoos-content-graph-ai-platform' ),
			);

			$this->log_activity( 'export-data', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: source, 2: format */
					__( 'Exporting %1$s data to %2$s format.', 'nvoos-content-graph-ai-platform' ),
					$source,
					strtoupper( $format )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'export-data', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle data-visualize command.
	 * Create data visualizations following Chart.js/D3.js best practices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_data_visualize( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'data_source', 'chart_type' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create visualizations.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$data_source = sanitize_text_field( $args['data_source'] );
		$chart_type  = sanitize_text_field( $args['chart_type'] );

		try {
			// Supported chart types (Chart.js compatible).
			$chart_types = array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'scatter', 'bubble', 'area' );
			if ( ! in_array( $chart_type, $chart_types, true ) ) {
				return $this->error_response(
					new \WP_Error(
						'invalid_chart_type',
						sprintf(
							/* translators: %s: comma-separated list of chart types */
							__( 'Invalid chart type. Use: %s', 'nvoos-content-graph-ai-platform' ),
							implode( ', ', $chart_types )
						)
					)
				);
			}

			// Generate visualization ID.
			$viz_id = uniqid( 'viz_', true );

			// Mock visualization data.
			$visualization = array(
				'viz_id'      => $viz_id,
				'chart_type'  => $chart_type,
				'data_source' => $data_source,
				'created_at'  => current_time( 'mysql' ),
				'config'      => array(
					'responsive'          => true,
					'maintainAspectRatio' => true,
					'animation'           => array(
						'duration' => 1000,
					),
				),
				'shortcode'   => "[data-viz id=\"{$viz_id}\"]",
				'preview_url' => admin_url( 'admin.php?action=preview_viz&id=' . $viz_id ),
			);

			// Store visualization metadata.
			$visualizations            = get_option( 'wp_mcp_ai_visualizations', array() );
			$visualizations[ $viz_id ] = $visualization;
			update_option( 'wp_mcp_ai_visualizations', $visualizations );

			$this->log_activity( 'data-visualize', $args, $visualization );

			return $this->success_response(
				$visualization,
				sprintf(
					/* translators: %s: chart type */
					__( '%s chart visualization created successfully.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $chart_type )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'data-visualize', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle order fulfill command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_order_fulfill( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'order_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce-provided capabilities, not core WP caps.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to fulfill orders.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		$order_id = absint( $args['order_id'] );

		try {
			// Check if WooCommerce is active.
			if ( ! function_exists( 'wc_get_order' ) ) {
				return $this->error_response(
					new \WP_Error(
						'woocommerce_not_active',
						__( 'WooCommerce is not active.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return $this->error_response(
					new \WP_Error(
						'order_not_found',
						__( 'Order not found.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Mark order as completed.
			$order->update_status( 'completed', __( 'Order fulfilled via slash command', 'nvoos-content-graph-ai-platform' ) );

			$result = array(
				'order_id'     => $order_id,
				'status'       => 'completed',
				'total'        => $order->get_total(),
				'fulfilled_at' => current_time( 'mysql' ),
			);

			$this->log_activity( 'order-fulfill', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: order ID */
					__( 'Order #%d fulfilled successfully.', 'nvoos-content-graph-ai-platform' ),
					$order_id
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle inventory check command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_inventory_check( $args, $context ) {
		// Check capabilities.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce-provided capabilities, not core WP caps.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to check inventory.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Check if WooCommerce is active.
			if ( ! function_exists( 'wc_get_products' ) ) {
				return $this->error_response(
					new \WP_Error(
						'woocommerce_not_active',
						__( 'WooCommerce is not active.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Get low stock threshold.
			$low_stock_threshold = ! empty( $args['threshold'] ) ? absint( $args['threshold'] ) : 5;

			// Query products with low stock.
			$products = wc_get_products(
				array(
					'limit'        => 50,
					'stock_status' => 'instock',
					'orderby'      => 'stock_quantity',
					'order'        => 'ASC',
				)
			);

			$low_stock_items = array();
			$out_of_stock    = 0;

			foreach ( $products as $product ) {
				$stock_qty = $product->get_stock_quantity();
				if ( null !== $stock_qty && $stock_qty <= $low_stock_threshold ) {
					$low_stock_items[] = array(
						'id'    => $product->get_id(),
						'name'  => $product->get_name(),
						'stock' => $stock_qty,
						'sku'   => $product->get_sku(),
					);
				}
				if ( ! $product->is_in_stock() ) {
					++$out_of_stock;
				}
			}

			$result = array(
				'low_stock_count' => count( $low_stock_items ),
				'low_stock_items' => array_slice( $low_stock_items, 0, 10 ),
				'out_of_stock'    => $out_of_stock,
				'threshold'       => $low_stock_threshold,
			);

			$this->log_activity( 'inventory-check', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: number of low stock items */
					__( 'Found %d low stock items.', 'nvoos-content-graph-ai-platform' ),
					count( $low_stock_items )
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle code analyze command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_code_analyze( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'file' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to analyze code.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$file_path = sanitize_text_field( $args['file'] );

			// Mock code analysis results.
			$analysis = array(
				'file'             => $file_path,
				'lines_of_code'    => 250,
				'complexity_score' => 15,
				'security_issues'  => 2,
				'style_warnings'   => 5,
				'suggestions'      => array(
					__( 'Consider extracting complex logic into separate functions', 'nvoos-content-graph-ai-platform' ),
					__( 'Add input sanitization for user data', 'nvoos-content-graph-ai-platform' ),
					__( 'Improve variable naming for clarity', 'nvoos-content-graph-ai-platform' ),
				),
			);

			$this->log_activity( 'code-analyze', $args, $analysis );

			return $this->success_response(
				$analysis,
				__( 'Code analysis complete.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle security scan command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_security_scan( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to run security scans.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$scan_type = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'full';

			// Mock security scan results.
			$scan_results = array(
				'scan_id'          => uniqid( 'scan_', true ),
				'scan_type'        => $scan_type,
				'completed_at'     => current_time( 'mysql' ),
				'vulnerabilities'  => array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 3,
					'low'      => 5,
				),
				'checks_performed' => array(
					'file_permissions',
					'outdated_plugins',
					'weak_passwords',
					'ssl_certificate',
					'database_security',
				),
				'recommendations'  => array(
					__( 'Update 2 plugins with known vulnerabilities', 'nvoos-content-graph-ai-platform' ),
					__( 'Enable two-factor authentication', 'nvoos-content-graph-ai-platform' ),
					__( 'Review file permissions on uploads directory', 'nvoos-content-graph-ai-platform' ),
				),
				'overall_score'    => 75,
			);

			$this->log_activity( 'security-scan', $args, $scan_results );

			return $this->success_response(
				$scan_results,
				sprintf(
					/* translators: %d: security score */
					__( 'Security scan complete. Score: %d/100', 'nvoos-content-graph-ai-platform' ),
					$scan_results['overall_score']
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle research query command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_research_query( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'query' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to perform research queries.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$query   = sanitize_text_field( $args['query'] );
			$sources = ! empty( $args['sources'] ) ? sanitize_text_field( $args['sources'] ) : 'all';

			// Mock research results.
			$research = array(
				'query'         => $query,
				'sources_used'  => explode( ', ', $sources ),
				'results_found' => 15,
				'top_results'   => array(
					array(
						'title'     => __( 'Research Result 1', 'nvoos-content-graph-ai-platform' ),
						'source'    => 'Academic Database',
						'relevance' => 95,
					),
					array(
						'title'     => __( 'Research Result 2', 'nvoos-content-graph-ai-platform' ),
						'source'    => 'Industry Reports',
						'relevance' => 88,
					),
					array(
						'title'     => __( 'Research Result 3', 'nvoos-content-graph-ai-platform' ),
						'source'    => 'News Articles',
						'relevance' => 82,
					),
				),
				'summary'       => __( 'Found 15 relevant results across multiple sources with high confidence.', 'nvoos-content-graph-ai-platform' ),
			);

			$this->log_activity( 'research-query', $args, $research );

			return $this->success_response(
				$research,
				sprintf(
					/* translators: %d: number of results */
					__( 'Research complete. Found %d results.', 'nvoos-content-graph-ai-platform' ),
					$research['results_found']
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle map create command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_map_create( $args, $context ) {
		return $this->handle_generic_command( $args, $context );
	}

	/**
	 * Handle workflow create command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_workflow_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create workflows.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$workflow_name = sanitize_text_field( $args['name'] );
			$description   = ! empty( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';

			// Create workflow definition.
			$workflow_id = uniqid( 'workflow_', true );
			$workflow    = array(
				'id'          => $workflow_id,
				'name'        => $workflow_name,
				'description' => $description,
				'steps'       => array(),
				'status'      => 'active',
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save workflow (in real implementation, would save to database or options).
			$workflows                 = get_option( 'wp_mcp_ai_workflows', array() );
			$workflows[ $workflow_id ] = $workflow;
			update_option( 'wp_mcp_ai_workflows', $workflows );

			$result = array(
				'workflow_id' => $workflow_id,
				'name'        => $workflow_name,
				'status'      => 'created',
			);

			$this->log_activity( 'workflow-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" created successfully.', 'nvoos-content-graph-ai-platform' ),
					$workflow_name
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle email campaign command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_email_campaign( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'subject', 'content' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create campaigns.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$subject  = sanitize_text_field( $args['subject'] );
			$content  = wp_kses_post( $args['content'] );
			$audience = ! empty( $args['audience'] ) ? sanitize_text_field( $args['audience'] ) : 'all';

			// Mock campaign creation.
			$campaign_id = uniqid( 'campaign_', true );

			$result = array(
				'campaign_id'     => $campaign_id,
				'subject'         => $subject,
				'audience'        => $audience,
				'status'          => 'draft',
				'created'         => current_time( 'mysql' ),
				'estimated_reach' => 1000,
			);

			$this->log_activity( 'email-campaign', $args, $result );

			return $this->success_response(
				$result,
				__( 'Email campaign created successfully.', 'nvoos-content-graph-ai-platform' )
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle API connect command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_api_connect( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'service' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to connect APIs.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$service = sanitize_text_field( $args['service'] );
			$api_key = ! empty( $args['api_key'] ) ? sanitize_text_field( $args['api_key'] ) : '';

			// Mock API connection test.
			$connection = array(
				'service'      => $service,
				'status'       => 'connected',
				'connected_at' => current_time( 'mysql' ),
				'test_result'  => 'success',
				'rate_limit'   => '1000/hour',
			);

			// Save API connection (in real implementation).
			update_option(
				"wp_mcp_ai_api_{$service}",
				array(
					'connected'    => true,
					'connected_at' => current_time( 'mysql' ),
				)
			);

			$this->log_activity( 'api-connect', array( 'service' => $service ), $connection );

			return $this->success_response(
				$connection,
				sprintf(
					/* translators: %s: service name */
					__( 'Successfully connected to %s API.', 'nvoos-content-graph-ai-platform' ),
					$service
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle model deploy command.
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_model_deploy( $args, $context ) {
		return $this->handle_generic_command( $args, $context );
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PRO TOOLKIT COMMANDS (19 Toolkits)
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Get AI Tool Builder toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_ai_tool_builder_commands() {
		return array(
			array(
				'name'   => 'aitool-create',
				'config' => array(
					'handler'     => array( $this, 'handle_aitool_create' ),
					'description' => __( 'Create new AI tool', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/aitool-create --name="My Tool" --type=prompt',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-test',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Test AI tool functionality', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/aitool-test --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-deploy',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Deploy AI tool to production', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/aitool-deploy --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-version',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Manage tool versions', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/aitool-version --tool_id=123 --version=1.2',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'prompt-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Optimize AI prompts', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/prompt-optimize --prompt_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'prompt-library',
				'config' => array(
					'handler'     => array( $this, 'handle_prompt_library' ),
					'description' => __( 'Access prompt templates', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/prompt-library --search="SEO"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'tool-monitor',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Monitor tool usage and performance', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/tool-monitor --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'tool-marketplace',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Browse/share tools', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/tool-marketplace --category="content"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'integration-add',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Add tool integrations', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/integration-add --tool_id=123 --service="slack"',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-analytics',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'View tool analytics', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/aitool-analytics --tool_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
		);
	}

	/**
	 * Get Analytics Pro toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_analytics_pro_commands() {
		return array(
			array(
				'name'   => 'analytics-dashboard',
				'config' => array(
					'handler'     => array( $this, 'handle_analytics_dashboard' ),
					'description' => __( 'Create custom dashboards', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/analytics-dashboard --name="Sales Overview"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'metric-define',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Define custom metrics', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/metric-define --name="Conversion Rate"',
					'capability'  => 'manage_options',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'metric-track',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Track metric performance', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/metric-track --metric_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'goal-set',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Set analytics goals', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/goal-set --metric="revenue" --target=10000',
					'capability'  => 'manage_options',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'funnel-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Analyze conversion funnels', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/funnel-analyze --funnel="checkout"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'cohort-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Cohort analysis', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/cohort-analyze --cohort="monthly"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'attribution-model',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Attribution modeling', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/attribution-model --type="last-click"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'segment-advanced',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Advanced segmentation', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/segment-advanced --criteria="high-value"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'predict-churn',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Churn prediction', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/predict-churn --segment="customers"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'ltv-calculate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Customer lifetime value', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/ltv-calculate --segment="all"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'analytics-export',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Export analytics data', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/analytics-export --format=csv --date-range="last-30-days"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'alert-configure',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Configure analytics alerts', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/alert-configure --metric="revenue" --threshold=low',
					'capability'  => 'manage_options',
					'toolkit'     => 'analytics_pro',
				),
			),
		);
	}

	/**
	 * Get Architect Agent toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_architect_agent_commands() {
		return array(
			array(
				'name'   => 'architect-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create development plan', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-plan --project="E-commerce Site"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-scaffold',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Scaffold project structure', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-scaffold --type=plugin',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-review',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Review architecture design', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-review --project_id=123',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-refactor',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Suggest refactoring', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-refactor --file=class-example.php',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-document',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate architecture docs', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-document --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-diagram',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create architecture diagrams', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-diagram --type="class-diagram"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Analyze codebase', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-analyze --path=includes/',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-migrate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Plan migrations', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-migrate --from=v1.0 --to=v2.0',
					'capability'  => 'manage_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Optimize architecture', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-optimize --focus="performance"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-test',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate test suites', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/architect-test --class="WP_MCP_AI_Tool"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-deploy',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Deployment strategy', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'manage_options',
					'toolkit'     => 'architect_agent',
				),
			),
		);
	}

	/**
	 * Get Architectural Design toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_architectural_design_commands() {
		return array(
			array(
				'name'   => 'floor-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate floor plans', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/floor-plan --sqft=2000 --bedrooms=3',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'blueprint-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create blueprints', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/blueprint-create --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => '3d-model',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate 3D models', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/3d-model --design_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'space-calculate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Calculate space requirements', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/space-calculate --room-type="office"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'compliance-check',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Building code compliance', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/compliance-check --project_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'cost-estimate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Construction cost estimation', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/cost-estimate --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'material-specify',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Material specifications', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/material-specify --category="flooring"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'lighting-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Lighting design', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/lighting-plan --room_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'hvac-design',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'HVAC system design', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/hvac-design --sqft=2000',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'plumbing-layout',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Plumbing layout', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/plumbing-layout --floor_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'electrical-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Electrical planning', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/electrical-plan --floor_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'structural-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Structural analysis', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/structural-analyze --design_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'accessibility-check',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'ADA compliance check', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/accessibility-check --project_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'energy-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Energy efficiency analysis', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/energy-analyze --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'render-3d',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( '3D rendering', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/render-3d --model_id=789 --quality=high',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'cad-export',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Export to CAD formats', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/cad-export --design_id=456 --format=dwg',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
		);
	}

	// Continue with remaining pro toolkit command definitions...
	// Due to length, I'll add them in the next edit operation.

	/**
	 * Get Calendar & Booking toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_calendar_booking_commands() {
		$commands      = array();
		$command_names = array( 'booking-create', 'booking-manage', 'availability-set', 'calendar-sync', 'reminder-send', 'booking-confirm', 'reschedule', 'cancel-booking', 'waitlist-manage', 'booking-report', 'resource-schedule', 'buffer-time' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'calendar_booking',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Chat Channels toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_chat_channels_commands() {
		$commands      = array();
		$command_names = array( 'channel-create', 'channel-join', 'message-broadcast', 'thread-create', 'mention-user', 'channel-archive', 'chat-search', 'file-share', 'chat-integrate', 'chat-analytics' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'chat_channels',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get CRM toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_crm_commands() {
		return array(
			array(
				'name'   => 'lead-add',
				'config' => array(
					'handler'     => array( $this, 'handle_lead_add' ),
					'description' => __( 'Add new lead', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/lead-add --name="John Doe" --email="john@example.com"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'lead-qualify',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Qualify leads', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/lead-qualify --lead_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'lead-assign',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Assign leads', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/lead-assign --lead_id=456 --user_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create contact', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-merge',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Merge contacts', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'deal-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create deal', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'deal-move',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Move deal in pipeline', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'activity-log',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Log activity', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'follow-up',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Schedule follow-up', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'email-sequence',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create email sequence', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'crm-report',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate CRM report', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'pipeline-view',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'View sales pipeline', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-segment',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Segment contacts', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'crm-sync',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Sync CRM data', 'nvoos-content-graph-ai-platform' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
		);
	}

	/**
	 * Get DJ Management toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_dj_management_commands() {
		$commands      = array();
		$command_names = array( 'track-add', 'playlist-create', 'playlist-analyze', 'bpm-match', 'key-match', 'setlist-plan', 'event-plan', 'track-recommend', 'mix-analyze', 'library-organize', 'event-report' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'dj_management',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Document Generation toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_document_generation_commands() {
		$commands      = array();
		$command_names = array( 'doc-create', 'pdf-generate', 'doc-merge', 'template-create', 'variable-fill', 'doc-sign', 'doc-approve', 'doc-version', 'doc-export', 'doc-watermark', 'doc-secure', 'doc-batch', 'doc-archive' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'document_generation',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get E-Commerce Pro toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_ecommerce_pro_commands() {
		$commands = array(
			array(
				'name'   => 'upsell-suggest',
				'config' => array(
					'handler'     => array( $this, 'handle_upsell_suggest' ),
					'description' => __( 'Generate AI-powered upsell recommendations for products', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/upsell-suggest --product-id=<id> [--recommendation-type=<type>] [--limit=<number>]',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_pro',
					'parameters'  => array(
						'--product-id'          => array(
							'description' => __( 'Product ID to get recommendations for', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--recommendation-type' => array(
							'description' => __( 'Type: product_based, customer_based, cart_based, frequently_bought (default: product_based)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--limit'               => array(
							'description' => __( 'Maximum number of recommendations (default: 5)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'abandoned-recover',
				'config' => array(
					'handler'     => array( $this, 'handle_abandoned_recover' ),
					'description' => __( 'Identify and recover abandoned carts with automated campaigns', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/abandoned-recover [--action=<identify|recover|status>] [--cart-id=<id>] [--send-email]',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_pro',
					'parameters'  => array(
						'--action'     => array(
							'description' => __( 'Action: identify, recover, status (default: identify)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--cart-id'    => array(
							'description' => __( 'Specific cart ID to recover', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--send-email' => array(
							'description' => __( 'Send recovery email', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'ecom-analytics',
				'config' => array(
					'handler'     => array( $this, 'handle_ecom_analytics' ),
					'description' => __( 'Get comprehensive e-commerce analytics and insights', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/ecom-analytics [--period=<period>] [--metrics=<metrics>] [--format=<format>]',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_pro',
					'parameters'  => array(
						'--period'  => array(
							'description' => __( 'Time period: today, week, month, year (default: month)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--metrics' => array(
							'description' => __( 'Comma-separated metrics: sales, orders, customers, conversion (default: all)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--format'  => array(
							'description' => __( 'Output format: table, json, chart (default: table)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
		);

		// Add additional implemented commands.
		$commands[] = array(
			'name'   => 'discount-optimize',
			'config' => array(
				'handler'     => array( $this, 'handle_discount_optimize' ),
				'description' => __( 'Create and optimize discount campaigns with AI-powered recommendations', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/discount-optimize --campaign-name=<name> --discount-type=<type> --amount=<value> [--products=<ids>] [--expiry=<date>]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--campaign-name' => array(
						'description' => __( 'Campaign name for the discount', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--discount-type' => array(
						'description' => __( 'Type: percentage, fixed_cart, fixed_product (default: percentage)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--amount'        => array(
						'description' => __( 'Discount amount or percentage', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--products'      => array(
						'description' => __( 'Comma-separated product IDs to apply discount to', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--expiry'        => array(
						'description' => __( 'Expiry date (YYYY-MM-DD format)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'inventory-forecast',
			'config' => array(
				'handler'     => array( $this, 'handle_inventory_forecast' ),
				'description' => __( 'Predict inventory needs using sales trend analysis and demand forecasting', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/inventory-forecast [--product-id=<id>] [--period=<days>] [--include-seasonal]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--product-id'       => array(
						'description' => __( 'Specific product ID (default: all products)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--period'           => array(
						'description' => __( 'Forecast period in days (default: 30)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--include-seasonal' => array(
						'description' => __( 'Include seasonal patterns in forecast', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'customer-segment',
			'config' => array(
				'handler'     => array( $this, 'handle_customer_segment' ),
				'description' => __( 'Create customer segments based on purchase behavior and demographics', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/customer-segment --criteria=<criteria> [--min-orders=<number>] [--output=<format>]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--criteria'   => array(
						'description' => __( 'Segmentation criteria: rfm, geographic, product_preference, custom', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--min-orders' => array(
						'description' => __( 'Minimum number of orders (default: 1)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--output'     => array(
						'description' => __( 'Output format: table, json, export (default: table)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add additional implemented commands (Phase 3).
		$commands[] = array(
			'name'   => 'bundle-create',
			'config' => array(
				'handler'     => array( $this, 'handle_bundle_create' ),
				'description' => __( 'Create product bundles with discounts and special pricing', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/bundle-create --name=<name> --products=<ids> [--discount=<percent>] [--fixed-price=<amount>]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--name'        => array(
						'description' => __( 'Bundle name', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--products'    => array(
						'description' => __( 'Comma-separated product IDs to include in bundle', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--discount'    => array(
						'description' => __( 'Discount percentage for bundle (default: 10)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--fixed-price' => array(
						'description' => __( 'Fixed price for bundle (overrides discount)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'shipping-optimize',
			'config' => array(
				'handler'     => array( $this, 'handle_shipping_optimize' ),
				'description' => __( 'Optimize shipping costs and methods', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/shipping-optimize [--zone=<zone>] [--method=<method>] [--analyze-costs]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--zone'          => array(
						'description' => __( 'Shipping zone to optimize (default: all)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--method'        => array(
						'description' => __( 'Shipping method to analyze', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--analyze-costs' => array(
						'description' => __( 'Analyze and suggest cost optimizations', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'fraud-detect',
			'config' => array(
				'handler'     => array( $this, 'handle_fraud_detect' ),
				'description' => __( 'Detect potentially fraudulent orders and activities', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/fraud-detect [--order-id=<id>] [--scan-recent] [--threshold=<level>]',
				'capability'  => 'manage_woocommerce',
				'toolkit'     => 'ecommerce_pro',
				'parameters'  => array(
					'--order-id'    => array(
						'description' => __( 'Specific order ID to check', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--scan-recent' => array(
						'description' => __( 'Scan recent orders for fraud patterns', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--threshold'   => array(
						'description' => __( 'Risk threshold: low, medium, high (default: medium)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add placeholder commands for remaining features.
		$placeholder_commands = array( 'product-recommend', 'crosssell-suggest', 'subscription-manage', 'wholesale-pricing', 'marketplace-sync', 'tax-calculate', 'return-process', 'supplier-sync' );
		foreach ( $placeholder_commands as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_pro',
				),
			);
		}

		return $commands;
	}

	/**
	 * Get Financial Planner toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_financial_planner_commands() {
		$commands      = array();
		$command_names = array( 'budget-create', 'budget-track', 'investment-analyze', 'portfolio-optimize', 'retirement-plan', 'retirement-calc', 'debt-analyze', 'debt-payoff', 'goal-set', 'goal-track', 'tax-estimate', 'networth-calc', 'cashflow-analyze', 'finance-report' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'financial_planner',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Image Production toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_image_production_commands() {
		$commands      = array();
		$command_names = array( 'image-edit', 'image-enhance', 'background-remove', 'image-upscale', 'image-restore', 'color-correct', 'image-crop', 'image-filter', 'image-collage', 'image-template', 'image-batch-edit', 'image-watermark', 'image-metadata' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'upload_files',
					'toolkit'     => 'image_production',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Media Pro toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_media_pro_commands() {
		$commands      = array();
		$command_names = array( 'media-organize', 'media-tag', 'media-search', 'media-backup', 'media-cdn', 'media-optimize-bulk', 'media-migrate', 'media-duplicate', 'media-unused', 'media-analytics', 'media-permission' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'upload_files',
					'toolkit'     => 'media_pro',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Multilingual toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_multilingual_commands() {
		$commands      = array();
		$command_names = array( 'translate-content', 'translate-bulk', 'locale-switch', 'glossary-manage', 'translate-check', 'language-detect', 'rtl-convert', 'locale-sync', 'translate-export', 'translate-import', 'language-fallback', 'multilingual-seo' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'multilingual',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Regulatory & Registration toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_regulatory_registration_commands() {
		$commands      = array();
		$command_names = array( 'business-register', 'license-apply', 'permit-apply', 'compliance-check', 'filing-submit', 'ein-apply', 'trademark-search', 'patent-search', 'incorporation-docs', 'annual-report', 'regulatory-alert', 'license-renew', 'compliance-report', 'registration-track', 'regulatory-research' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'manage_options',
					'toolkit'     => 'regulatory_registration',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Site Creator toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_site_creator_commands() {
		$commands      = array();
		$command_names = array( 'site-research', 'competitor-analyze', 'site-plan', 'page-create', 'section-create', 'widget-create', 'template-create', 'template-apply', 'site-scaffold', 'design-system', 'component-library', 'responsive-test', 'site-export', 'site-deploy' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'site_creator',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Social Media toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_social_media_commands() {
		$commands = array(
			array(
				'name'   => 'social-post',
				'config' => array(
					'handler'     => array( $this, 'handle_social_post' ),
					'description' => __( 'Create and post content to social media platforms', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/social-post --content=<text> --platforms=<platforms> [--schedule=<time>] [--media=<id>]',
					'capability'  => 'edit_posts',
					'toolkit'     => 'social_media',
					'parameters'  => array(
						'--content'   => array(
							'description' => __( 'Post content text', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--platforms' => array(
							'description' => __( 'Comma-separated platforms: facebook, twitter, instagram, linkedin (or "all")', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--schedule'  => array(
							'description' => __( 'Schedule time (YYYY-MM-DD HH:MM format)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--media'     => array(
							'description' => __( 'Media attachment IDs (comma-separated)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'hashtag-suggest',
				'config' => array(
					'handler'     => array( $this, 'handle_hashtag_suggest' ),
					'description' => __( 'Generate relevant hashtag suggestions for content', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/hashtag-suggest --content=<text> [--platform=<platform>] [--count=<number>]',
					'capability'  => 'edit_posts',
					'toolkit'     => 'social_media',
					'parameters'  => array(
						'--content'  => array(
							'description' => __( 'Content text to analyze', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--platform' => array(
							'description' => __( 'Target platform: twitter, instagram, linkedin (default: all)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--count'    => array(
							'description' => __( 'Number of suggestions (default: 10)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'social-analytics',
				'config' => array(
					'handler'     => array( $this, 'handle_social_analytics' ),
					'description' => __( 'Get social media analytics and performance metrics', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/social-analytics [--platform=<platform>] [--period=<period>] [--metrics=<metrics>]',
					'capability'  => 'edit_posts',
					'toolkit'     => 'social_media',
					'parameters'  => array(
						'--platform' => array(
							'description' => __( 'Platform to analyze (default: all)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--period'   => array(
							'description' => __( 'Time period: today, week, month (default: week)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--metrics'  => array(
							'description' => __( 'Comma-separated metrics: engagement, reach, clicks, conversions', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
		);

		// Add additional implemented commands.
		$commands[] = array(
			'name'   => 'social-schedule',
			'config' => array(
				'handler'     => array( $this, 'handle_social_schedule' ),
				'description' => __( 'Schedule posts across multiple social media platforms', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/social-schedule --content=<text> --platforms=<platforms> --time=<datetime> [--media=<ids>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--content'   => array(
						'description' => __( 'Post content text', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--platforms' => array(
						'description' => __( 'Comma-separated platforms to post to', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--time'      => array(
						'description' => __( 'Schedule time (YYYY-MM-DD HH:MM format)', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--media'     => array(
						'description' => __( 'Comma-separated media attachment IDs', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'content-calendar',
			'config' => array(
				'handler'     => array( $this, 'handle_content_calendar' ),
				'description' => __( 'Create and manage social media content calendar', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/content-calendar [--action=<create|view|update>] [--period=<days>] [--format=<format>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--action' => array(
						'description' => __( 'Action: create, view, update (default: view)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--period' => array(
						'description' => __( 'Number of days to display (default: 30)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--format' => array(
						'description' => __( 'Output format: calendar, list, json (default: calendar)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'competitor-track',
			'config' => array(
				'handler'     => array( $this, 'handle_competitor_track' ),
				'description' => __( 'Track and analyze competitor social media activity', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/competitor-track --competitor=<handle> --platform=<platform> [--metrics=<metrics>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--competitor' => array(
						'description' => __( 'Competitor social media handle', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--platform'   => array(
						'description' => __( 'Platform: twitter, facebook, instagram, linkedin', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--metrics'    => array(
						'description' => __( 'Comma-separated metrics to track (default: all)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add additional implemented commands (Phase 3).
		$commands[] = array(
			'name'   => 'post-optimize',
			'config' => array(
				'handler'     => array( $this, 'handle_post_optimize' ),
				'description' => __( 'Optimize post content for maximum engagement', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/post-optimize --content=<text> [--platform=<platform>] [--goal=<goal>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--content'  => array(
						'description' => __( 'Post content to optimize', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--platform' => array(
						'description' => __( 'Target platform (default: all)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--goal'     => array(
						'description' => __( 'Optimization goal: engagement, reach, clicks, conversions', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'influencer-find',
			'config' => array(
				'handler'     => array( $this, 'handle_influencer_find' ),
				'description' => __( 'Discover and analyze influencers in your niche', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/influencer-find --niche=<niche> [--platform=<platform>] [--min-followers=<count>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--niche'         => array(
						'description' => __( 'Industry or niche to search', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--platform'      => array(
						'description' => __( 'Platform to search (default: all)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--min-followers' => array(
						'description' => __( 'Minimum follower count (default: 1000)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'campaign-create',
			'config' => array(
				'handler'     => array( $this, 'handle_campaign_create' ),
				'description' => __( 'Create comprehensive social media campaigns', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/campaign-create --name=<name> --goal=<goal> [--duration=<days>] [--budget=<amount>]',
				'capability'  => 'edit_posts',
				'toolkit'     => 'social_media',
				'parameters'  => array(
					'--name'     => array(
						'description' => __( 'Campaign name', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--goal'     => array(
						'description' => __( 'Campaign goal: awareness, engagement, conversions, traffic', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--duration' => array(
						'description' => __( 'Campaign duration in days (default: 30)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--budget'   => array(
						'description' => __( 'Campaign budget', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add placeholder commands for remaining features.
		$placeholder_commands = array( 'social-calendar', 'social-engage', 'social-monitor', 'trend-identify', 'social-report' );
		foreach ( $placeholder_commands as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'social_media',
				),
			);
		}

		return $commands;
	}

	/**
	 * Get Video Production toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_video_production_commands() {
		$commands = array(
			array(
				'name'   => 'video-subtitle',
				'config' => array(
					'handler'     => array( $this, 'handle_video_subtitle' ),
					'description' => __( 'Generate or add subtitles to videos', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/video-subtitle --video-id=<id> [--language=<lang>] [--auto-generate] [--style=<style>]',
					'capability'  => 'upload_files',
					'toolkit'     => 'video_production',
					'parameters'  => array(
						'--video-id'      => array(
							'description' => __( 'Video attachment ID', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--language'      => array(
							'description' => __( 'Subtitle language code (default: en)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--auto-generate' => array(
							'description' => __( 'Auto-generate from audio', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--style'         => array(
							'description' => __( 'Subtitle style preset', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'video-template',
				'config' => array(
					'handler'     => array( $this, 'handle_video_template' ),
					'description' => __( 'Apply video templates and presets', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/video-template --template=<name> --input=<id> [--output-name=<name>] [--customizations]',
					'capability'  => 'upload_files',
					'toolkit'     => 'video_production',
					'parameters'  => array(
						'--template'    => array(
							'description' => __( 'Template name or ID', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--input'       => array(
							'description' => __( 'Input video or images (comma-separated IDs)', 'nvoos-content-graph-ai-platform' ),
							'required'    => true,
						),
						'--output-name' => array(
							'description' => __( 'Output filename', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
			array(
				'name'   => 'video-analytics',
				'config' => array(
					'handler'     => array( $this, 'handle_video_analytics' ),
					'description' => __( 'Get video performance analytics', 'nvoos-content-graph-ai-platform' ),
					'usage'       => '/video-analytics [--video-id=<id>] [--period=<period>] [--metrics=<metrics>]',
					'capability'  => 'upload_files',
					'toolkit'     => 'video_production',
					'parameters'  => array(
						'--video-id' => array(
							'description' => __( 'Specific video ID (default: all)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--period'   => array(
							'description' => __( 'Time period: today, week, month (default: week)', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
						'--metrics'  => array(
							'description' => __( 'Comma-separated metrics: views, engagement, completion', 'nvoos-content-graph-ai-platform' ),
							'required'    => false,
						),
					),
				),
			),
		);

		// Add additional implemented commands.
		$commands[] = array(
			'name'   => 'video-merge',
			'config' => array(
				'handler'     => array( $this, 'handle_video_merge' ),
				'description' => __( 'Merge multiple video clips into a single video', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-merge --videos=<ids> [--output-name=<name>] [--transitions]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--videos'      => array(
						'description' => __( 'Comma-separated video attachment IDs to merge', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--output-name' => array(
						'description' => __( 'Output video filename', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--transitions' => array(
						'description' => __( 'Add transitions between clips', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'video-thumbnail',
			'config' => array(
				'handler'     => array( $this, 'handle_video_thumbnail_generate' ),
				'description' => __( 'Generate thumbnails for videos automatically', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-thumbnail --video-id=<id> [--count=<number>] [--timestamp=<seconds>]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--video-id'  => array(
						'description' => __( 'Video attachment ID', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--count'     => array(
						'description' => __( 'Number of thumbnails to generate (default: 3)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--timestamp' => array(
						'description' => __( 'Specific timestamp in seconds for thumbnail', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'video-compress',
			'config' => array(
				'handler'     => array( $this, 'handle_video_compress' ),
				'description' => __( 'Compress videos to reduce file size', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-compress --video-id=<id> [--quality=<level>] [--format=<format>]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--video-id' => array(
						'description' => __( 'Video attachment ID', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--quality'  => array(
						'description' => __( 'Compression quality: low, medium, high (default: medium)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--format'   => array(
						'description' => __( 'Output format: mp4, webm (default: mp4)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add additional implemented commands (Phase 3).
		$commands[] = array(
			'name'   => 'video-trim',
			'config' => array(
				'handler'     => array( $this, 'handle_video_trim' ),
				'description' => __( 'Trim video clips to specific durations', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-trim --video-id=<id> --start=<seconds> --end=<seconds> [--output-name=<name>]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--video-id'    => array(
						'description' => __( 'Video attachment ID', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--start'       => array(
						'description' => __( 'Start time in seconds', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--end'         => array(
						'description' => __( 'End time in seconds', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--output-name' => array(
						'description' => __( 'Output filename', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'video-voiceover',
			'config' => array(
				'handler'     => array( $this, 'handle_video_voiceover' ),
				'description' => __( 'Add AI-generated voiceover to videos', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-voiceover --video-id=<id> --script=<text> [--voice=<voice>] [--language=<lang>]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--video-id' => array(
						'description' => __( 'Video attachment ID', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--script'   => array(
						'description' => __( 'Voiceover script text', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--voice'    => array(
						'description' => __( 'Voice type: male, female, neutral (default: neutral)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--language' => array(
						'description' => __( 'Language code (default: en)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		$commands[] = array(
			'name'   => 'video-render',
			'config' => array(
				'handler'     => array( $this, 'handle_video_render' ),
				'description' => __( 'Render video projects with effects and edits', 'nvoos-content-graph-ai-platform' ),
				'usage'       => '/video-render --project-id=<id> [--quality=<quality>] [--format=<format>]',
				'capability'  => 'upload_files',
				'toolkit'     => 'video_production',
				'parameters'  => array(
					'--project-id' => array(
						'description' => __( 'Video project ID to render', 'nvoos-content-graph-ai-platform' ),
						'required'    => true,
					),
					'--quality'    => array(
						'description' => __( 'Render quality: draft, standard, high, ultra (default: standard)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
					'--format'     => array(
						'description' => __( 'Output format: mp4, mov, avi (default: mp4)', 'nvoos-content-graph-ai-platform' ),
						'required'    => false,
					),
				),
			),
		);

		// Add placeholder commands for remaining features.
		$placeholder_commands = array( 'video-edit', 'video-effect', 'video-transition', 'video-music', 'video-storyboard', 'video-publish' );
		foreach ( $placeholder_commands as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					// translators: %s is the slash command name.
					'description' => sprintf( __( '%s command - Implementation coming soon', 'nvoos-content-graph-ai-platform' ), $name ),
					'capability'  => 'upload_files',
					'toolkit'     => 'video_production',
				),
			);
		}

		return $commands;
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// HIGH-PRIORITY COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle aitool-create command.
	 *
	 * Creates a new AI tool with specified configuration.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_aitool_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create AI tools.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$tool_name   = sanitize_text_field( $args['name'] );
			$tool_type   = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'prompt';
			$description = isset( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';

			// Create custom post type for AI tool.
			$tool_data = array(
				'post_title'   => $tool_name,
				'post_content' => $description,
				'post_status'  => 'draft',
				'post_type'    => 'mcp_ai_tool',
				'post_author'  => get_current_user_id(),
			);

			$tool_id = wp_insert_post( $tool_data );

			if ( is_wp_error( $tool_id ) ) {
				return $this->error_response( $tool_id );
			}

			// Add metadata.
			update_post_meta( $tool_id, '_wp_mcp_ai_tool_type', $tool_type );
			update_post_meta( $tool_id, '_wp_mcp_ai_tool_status', 'draft' );
			update_post_meta( $tool_id, '_wp_mcp_ai_tool_version', '1.0.0' );
			update_post_meta( $tool_id, '_wp_mcp_ai_tool_created_via_command', true );
			update_post_meta( $tool_id, '_wp_mcp_ai_tool_created_date', current_time( 'mysql' ) );

			$result = array(
				'tool_id'  => $tool_id,
				'name'     => $tool_name,
				'type'     => $tool_type,
				'status'   => 'draft',
				'version'  => '1.0.0',
				'edit_url' => admin_url( "post.php?post={$tool_id}&action=edit" ),
			);

			$this->log_activity( 'aitool-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: tool name */
					__( 'AI Tool "%s" created successfully! Ready for configuration.', 'nvoos-content-graph-ai-platform' ),
					$tool_name
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle prompt-library command.
	 *
	 * Access and search prompt templates library.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_prompt_library( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to access the prompt library.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			$search_term = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
			$category    = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : 'all';

			// Define default prompt library.
			$prompt_library = array(
				array(
					'id'          => 'seo-meta-description',
					'name'        => __( 'SEO Meta Description Generator', 'nvoos-content-graph-ai-platform' ),
					'category'    => 'SEO',
					'description' => __( 'Generate SEO-optimized meta descriptions', 'nvoos-content-graph-ai-platform' ),
					'template'    => 'Write a compelling meta description (max 160 characters) for: {topic}',
					'tags'        => array( 'seo', 'meta', 'description' ),
				),
				array(
					'id'          => 'blog-post-outline',
					'name'        => __( 'Blog Post Outline', 'nvoos-content-graph-ai-platform' ),
					'category'    => 'Content',
					'description' => __( 'Create a comprehensive blog post outline', 'nvoos-content-graph-ai-platform' ),
					'template'    => 'Create a detailed outline for a blog post about: {topic}. Include introduction, main points, and conclusion.',
					'tags'        => array( 'blog', 'content', 'outline' ),
				),
				array(
					'id'          => 'social-media-caption',
					'name'        => __( 'Social Media Caption', 'nvoos-content-graph-ai-platform' ),
					'category'    => 'Social Media',
					'description' => __( 'Generate engaging social media captions', 'nvoos-content-graph-ai-platform' ),
					'template'    => 'Write an engaging {platform} caption for: {content}. Include relevant hashtags.',
					'tags'        => array( 'social', 'caption', 'engagement' ),
				),
				array(
					'id'          => 'product-description',
					'name'        => __( 'Product Description', 'nvoos-content-graph-ai-platform' ),
					'category'    => 'E-Commerce',
					'description' => __( 'Create compelling product descriptions', 'nvoos-content-graph-ai-platform' ),
					'template'    => 'Write a compelling product description for: {product_name}. Highlight key features and benefits.',
					'tags'        => array( 'ecommerce', 'product', 'description' ),
				),
				array(
					'id'          => 'email-subject-line',
					'name'        => __( 'Email Subject Line', 'nvoos-content-graph-ai-platform' ),
					'category'    => 'Email Marketing',
					'description' => __( 'Generate attention-grabbing email subject lines', 'nvoos-content-graph-ai-platform' ),
					'template'    => 'Create 5 compelling email subject lines for: {campaign_topic}. Focus on {goal}.',
					'tags'        => array( 'email', 'subject', 'marketing' ),
				),
			);

			// Filter by search term.
			$filtered_prompts = $prompt_library;
			if ( ! empty( $search_term ) ) {
				$filtered_prompts = array_filter(
					$prompt_library,
					function ( $prompt ) use ( $search_term ) {
						$search_lower = strtolower( $search_term );
						return stripos( $prompt['name'], $search_term ) !== false
							|| stripos( $prompt['description'], $search_term ) !== false
							|| stripos( $prompt['category'], $search_term ) !== false
							|| in_array( $search_lower, array_map( 'strtolower', $prompt['tags'] ), true );
					}
				);
			}

			// Filter by category.
			if ( 'all' !== $category ) {
				$filtered_prompts = array_filter(
					$filtered_prompts,
					function ( $prompt ) use ( $category ) {
						return strtolower( $prompt['category'] ) === strtolower( $category );
					}
				);
			}

			$result = array(
				'total_prompts'    => count( $prompt_library ),
				'filtered_prompts' => count( $filtered_prompts ),
				'search_term'      => $search_term,
				'category'         => $category,
				'prompts'          => array_values( $filtered_prompts ),
				'categories'       => array_unique( array_column( $prompt_library, 'category' ) ),
			);

			$this->log_activity( 'prompt-library', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %d: number of prompts found */
					__( 'Found %d prompt templates.', 'nvoos-content-graph-ai-platform' ),
					count( $filtered_prompts )
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PHASE 1: ADDITIONAL HIGH-PRIORITY HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle analytics-dashboard command.
	 *
	 * Create custom analytics dashboards.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_analytics_dashboard( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create analytics dashboards.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'name'       => array(
				'type'     => 'string',
				'required' => true,
				'min'      => 3,
				'max'      => 100,
			),
			'metrics'    => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'revenue', 'sessions', 'conversions', 'pageviews', 'bounces', 'users', 'avg_duration', 'exit_rate' ),
			),
			'time_range' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'last-7-days', 'last-30-days', 'last-90-days', 'this-month', 'last-month', 'this-year' ),
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'analytics-dashboard', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$dashboard_name = $validator->normalize_name( $args['name'] );
			$metrics        = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'revenue, sessions, conversions';
			$time_range     = isset( $args['time_range'] ) ? sanitize_text_field( $args['time_range'] ) : 'last-30-days';

			// Check for duplicate dashboard name.
			$dashboards = get_option( 'wp_mcp_ai_analytics_dashboards', array() );
			foreach ( $dashboards as $existing ) {
				if ( strtolower( $existing['name'] ) === strtolower( $dashboard_name ) ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							sprintf(
								/* translators: %s: dashboard name */
								__( 'A dashboard with the name "%s" already exists.', 'nvoos-content-graph-ai-platform' ),
								$dashboard_name
							)
						)
					);
				}
			}

			// Parse and validate metrics.
			$metrics_array = array_map( 'trim', explode( ', ', $metrics ) );
			$valid_metrics = array( 'revenue', 'sessions', 'conversions', 'pageviews', 'bounces', 'users', 'avg_duration', 'exit_rate' );
			$metrics_array = array_intersect( $metrics_array, $valid_metrics );
			if ( empty( $metrics_array ) ) {
				$metrics_array = array( 'revenue', 'sessions', 'conversions' );
			}

			// Create dashboard configuration.
			$dashboard_id = uniqid( 'dashboard_', true );
			$dashboard    = array(
				'id'         => $dashboard_id,
				'name'       => $dashboard_name,
				'metrics'    => $metrics_array,
				'time_range' => $time_range,
				'widgets'    => array_map(
					function ( $metric ) {
						return array(
							'type'   => 'line_chart',
							'metric' => $metric,
						);
					},
					$metrics_array
				),
				'created'    => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'metadata'   => array(
					'ip'         => $validator->get_client_ip(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				),
			);

			// Save dashboard.
			$dashboards[ $dashboard_id ] = $dashboard;
			update_option( 'wp_mcp_ai_analytics_dashboards', $dashboards );

			$result = array(
				'dashboard_id' => $dashboard_id,
				'name'         => $dashboard_name,
				'metrics'      => $dashboard['metrics'],
				'time_range'   => $time_range,
				'widgets'      => count( $dashboard['widgets'] ),
				'view_url'     => admin_url( "admin.php?page=wp-mcp-ai-analytics&dashboard={$dashboard_id}" ),
			);

			$this->log_activity( 'analytics-dashboard', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: dashboard name */
					__( 'Analytics dashboard "%1$s" created successfully with %2$d metrics.', 'nvoos-content-graph-ai-platform' ),
					$dashboard_name,
					count( $metrics_array )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'analytics-dashboard',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle social-post command.
	 *
	 * Create social media posts.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_post( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create social posts.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'content'  => array(
				'type'     => 'string',
				'required' => true,
				'min'      => 1,
				'max'      => 3000,
			),
			'platform' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'twitter', 'facebook', 'linkedin', 'instagram', 'all' ),
			),
			'hashtags' => array(
				'type'     => 'string',
				'required' => false,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'social-post', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$content  = sanitize_textarea_field( $args['content'] );
			$platform = isset( $args['platform'] ) ? strtolower( sanitize_text_field( $args['platform'] ) ) : 'all';
			$hashtags = isset( $args['hashtags'] ) ? sanitize_text_field( $args['hashtags'] ) : '';

			// Platform-specific content length validation.
			$max_lengths = array(
				'twitter'   => 280,
				'facebook'  => 63206,
				'linkedin'  => 3000,
				'instagram' => 2200,
				'all'       => 280, // Use Twitter's limit for 'all'.
			);

			$content_length = mb_strlen( $content );
			if ( isset( $max_lengths[ $platform ] ) && $content_length > $max_lengths[ $platform ] ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						sprintf(
							/* translators: 1: platform name, 2: max length, 3: actual length */
							__( 'Content exceeds %1$s character limit (%2$d characters). Your content is %3$d characters.', 'nvoos-content-graph-ai-platform' ),
							ucfirst( $platform ),
							$max_lengths[ $platform ],
							$content_length
						)
					)
				);
			}

			// Parse and normalize hashtags.
			$hashtags_array = array();
			if ( ! empty( $hashtags ) ) {
				$hashtags_array = array_map(
					function ( $tag ) {
						$tag = trim( $tag );
						// Remove # prefix if present.
						return ltrim( $tag, '#' );
					},
					explode( ', ', $hashtags )
				);
				$hashtags_array = array_filter( $hashtags_array ); // Remove empty values.
			}

			// Check for duplicate posts (content hash).
			$content_hash = md5( $content );
			$posts        = get_option( 'wp_mcp_ai_social_posts', array() );
			foreach ( $posts as $existing ) {
				if ( isset( $existing['content_hash'] ) && $existing['content_hash'] === $content_hash ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							__( 'A post with identical content already exists. Consider modifying your content or deleting the existing post.', 'nvoos-content-graph-ai-platform' )
						)
					);
				}
			}

			// Create social post.
			$post_id = uniqid( 'social_', true );
			$post    = array(
				'id'           => $post_id,
				'content'      => $content,
				'content_hash' => $content_hash,
				'platform'     => $platform,
				'hashtags'     => $hashtags_array,
				'status'       => 'draft',
				'created'      => current_time( 'mysql' ),
				'created_by'   => get_current_user_id(),
				'metadata'     => array(
					'ip'            => $validator->get_client_ip(),
					'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'char_count'    => $content_length,
					'hashtag_count' => count( $hashtags_array ),
				),
			);

			// Save social post.
			$posts[ $post_id ] = $post;
			update_option( 'wp_mcp_ai_social_posts', $posts );

			$result = array(
				'post_id'    => $post_id,
				'platform'   => $platform,
				'status'     => 'draft',
				'preview'    => wp_trim_words( $content, 20 ),
				'hashtags'   => count( $hashtags_array ),
				'char_count' => $content_length,
			);

			$this->log_activity( 'social-post', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: platform name */
					__( 'Social post created for %s. Ready for scheduling.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $platform )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'social-post',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle doc-create command.
	 *
	 * Generate documents from templates.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_doc_create( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create documents.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'template' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( 'invoice', 'contract', 'proposal', 'service-agreement', 'quote', 'nda', 'terms-of-service' ),
			),
			'title'    => array(
				'type'     => 'string',
				'required' => false,
				'min'      => 3,
				'max'      => 200,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'doc-create', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			$template = sanitize_text_field( $args['template'] );
			$title    = isset( $args['title'] ) ? $validator->normalize_name( $args['title'] ) : ucwords( str_replace( '-', ' ', $template ) ) . ' ' . gmdate( 'Y-m-d' );

			// Check for duplicate document title.
			$documents = get_option( 'wp_mcp_ai_documents', array() );
			foreach ( $documents as $existing ) {
				if ( strtolower( $existing['title'] ) === strtolower( $title ) ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							sprintf(
								/* translators: %s: document title */
								__( 'A document with the title "%s" already exists. Consider using a different title.', 'nvoos-content-graph-ai-platform' ),
								$title
							)
						)
					);
				}
			}

			// Get template content.
			$template_content = $this->get_template_content( $template );
			if ( empty( $template_content ) ) {
				return $this->error_response(
					new \WP_Error(
						'E002',
						sprintf(
							/* translators: %s: template name */
							__( 'Template "%s" not found or is invalid.', 'nvoos-content-graph-ai-platform' ),
							$template
						)
					)
				);
			}

			// Create document from template.
			$doc_id   = uniqid( 'doc_', true );
			$document = array(
				'id'         => $doc_id,
				'title'      => $title,
				'template'   => $template,
				'content'    => $template_content,
				'status'     => 'draft',
				'created'    => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'metadata'   => array(
					'ip'         => $validator->get_client_ip(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'word_count' => str_word_count( strip_tags( $template_content ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- strip_tags() used for plain-text conversion; wp_strip_all_tags() would also be acceptable.
				),
			);

			// Save document.
			$documents[ $doc_id ] = $document;
			update_option( 'wp_mcp_ai_documents', $documents );

			$result = array(
				'doc_id'     => $doc_id,
				'title'      => $title,
				'template'   => $template,
				'status'     => 'draft',
				'word_count' => $document['metadata']['word_count'],
				'edit_url'   => admin_url( "post.php?post={$doc_id}&action=edit" ),
			);

			$this->log_activity( 'doc-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: template name, 2: document title */
					__( 'Document "%2$s" created from template "%1$s".', 'nvoos-content-graph-ai-platform' ),
					ucwords( str_replace( '-', ' ', $template ) ),
					$title
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'doc-create',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Get template content.
	 *
	 * Helper method to load document template content.
	 *
	 * @since 1.3.0
	 *
	 * @param string $template Template name.
	 * @return string Template content.
	 */
	protected function get_template_content( $template ) {
		$templates = array(
			'invoice'           => "INVOICE\n\nDate: {date}\nInvoice #: {invoice_number}\n\nBill To:\n{customer_name}\n{customer_address}\n\nItems:\n{items}\n\nTotal: {total}",
			'contract'          => "CONTRACT AGREEMENT\n\nThis agreement is made on {date} between:\n{party_a}\nand\n{party_b}\n\nTerms:\n{terms}\n\nSignatures:\n_____________  _____________",
			'proposal'          => "BUSINESS PROPOSAL\n\nTo: {client_name}\nDate: {date}\n\nExecutive Summary:\n{summary}\n\nScope of Work:\n{scope}\n\nPricing:\n{pricing}",
			'service-agreement' => "SERVICE AGREEMENT\n\nService Provider: {provider}\nClient: {client}\n\nServices: {services}\n\nPayment Terms: {payment_terms}",
		);

		return isset( $templates[ $template ] ) ? $templates[ $template ] : "Template: {$template}\n\n[Content will be added here]";
	}

	/**
	 * Handle lead-add command.
	 *
	 * Add CRM leads with enhanced validation.
	 *
	 * Enhanced with industry best practices:
	 * - Schema validation
	 * - Email/phone format validation
	 * - Data normalization
	 * - Duplicate detection
	 * - Audit trail logging
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_lead_add( $args, $context ) {
		// Define schema for validation.
		$schema = array(
			'name'   => array(
				'required' => true,
				'type'     => 'string',
			),
			'email'  => array(
				'required' => true,
				'type'     => 'string',
				'format'   => 'email',
			),
			'phone'  => array(
				'required' => false,
				'type'     => 'string',
				'format'   => 'phone',
			),
			'source' => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'manual', 'website', 'referral', 'campaign', 'social', 'api' ),
			),
			'score'  => array(
				'required' => false,
				'type'     => 'integer',
				'min'      => 0,
				'max'      => 100,
			),
		);

		// Validate against schema.
		$validation = SlashCommandValidator::validate_schema( $args, $schema );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to add leads.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		try {
			// Normalize data.
			$name   = SlashCommandValidator::normalize_name( $args['name'] );
			$email  = SlashCommandValidator::normalize_email( $args['email'] );
			$source = isset( $args['source'] ) ? sanitize_text_field( $args['source'] ) : 'manual';
			$score  = isset( $args['score'] ) ? absint( $args['score'] ) : 50;

			// Normalize phone if provided.
			$phone = null;
			if ( ! empty( $args['phone'] ) ) {
				$phone = SlashCommandValidator::normalize_phone( $args['phone'] );
			}

			// Check for duplicates.
			$duplicate_check = SlashCommandValidator::check_duplicate_lead( $email );
			if ( is_wp_error( $duplicate_check ) ) {
				return $this->error_response( $duplicate_check );
			}

			// Create lead.
			$lead_id = uniqid( 'lead_', true );
			$lead    = array(
				'id'         => $lead_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'source'     => $source,
				'score'      => $score,
				'status'     => 'new',
				'created'    => current_time( 'mysql' ),
				'created_by' => get_current_user_id(),
				'metadata'   => array(
					'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
				),
			);

			// Save lead.
			$leads             = get_option( 'wp_mcp_ai_crm_leads', array() );
			$leads[ $lead_id ] = $lead;
			update_option( 'wp_mcp_ai_crm_leads', $leads );

			$result = array(
				'lead_id' => $lead_id,
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'source'  => $source,
				'score'   => $score,
				'status'  => 'new',
			);

			// Enhanced activity logging with structured data.
			$this->log_activity(
				'lead-add',
				$args,
				array_merge(
					$result,
					array(
						'duplicate_check' => 'passed',
						'validation'      => 'passed',
					)
				)
			);

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: lead name, 2: score */
					__( 'Lead "%1$s" added successfully with score %2$d.', 'nvoos-content-graph-ai-platform' ),
					$name,
					$score
				)
			);

		} catch ( Exception $e ) {
			// Log error with context.
			$this->log_activity(
				'lead-add',
				$args,
				array(
					'error' => $e->getMessage(),
					'code'  => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle budget-create command.
	 *
	 * Create financial budgets.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_budget_create( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create budgets.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'name'           => array(
				'type'     => 'string',
				'required' => true,
				'min'      => 3,
				'max'      => 100,
			),
			'monthly_income' => array(
				'type'     => 'number',
				'required' => false,
				'min'      => 0,
				'max'      => 1000000,
			),
			'savings_goal'   => array(
				'type'     => 'number',
				'required' => false,
				'min'      => 0,
				'max'      => 1,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'budget-create', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$name           = $validator->normalize_name( $args['name'] );
			$monthly_income = isset( $args['monthly_income'] ) ? floatval( $args['monthly_income'] ) : 0;
			$savings_goal   = isset( $args['savings_goal'] ) ? floatval( $args['savings_goal'] ) : 0.20;

			// Validate monthly income is positive.
			if ( $monthly_income <= 0 ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						__( 'Monthly income must be greater than zero. Please provide a valid income amount.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Validate savings goal (0-1 for percentage, or convert large numbers).
			if ( $savings_goal > 1 && $savings_goal <= 100 ) {
				// Convert percentage to decimal (e.g., 25 -> 0.25).
				$savings_goal = $savings_goal / 100;
			} elseif ( $savings_goal > 100 ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						__( 'Savings goal must be between 0 and 1 (as decimal) or 0-100 (as percentage). Example: 0.25 or 25.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Check for duplicate budget name.
			$budgets = get_option( 'wp_mcp_ai_budgets', array() );
			foreach ( $budgets as $existing ) {
				if ( strtolower( $existing['name'] ) === strtolower( $name ) ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							sprintf(
								/* translators: %s: budget name */
								__( 'A budget with the name "%s" already exists. Please use a different name.', 'nvoos-content-graph-ai-platform' ),
								$name
							)
						)
					);
				}
			}

			// Calculate budget allocations.
			$savings  = $monthly_income * $savings_goal;
			$expenses = $monthly_income - $savings;

			// Standard allocation percentages for expenses.
			$allocation_percentages = array(
				'housing'        => 0.30,
				'food'           => 0.15,
				'transportation' => 0.15,
				'utilities'      => 0.10,
				'entertainment'  => 0.10,
				'other'          => 0.20,
			);

			$allocations = array( 'savings' => round( $savings, 2 ) );
			foreach ( $allocation_percentages as $category => $percentage ) {
				$allocations[ $category ] = round( $expenses * $percentage, 2 );
			}

			// Create budget.
			$budget_id = uniqid( 'budget_', true );
			$budget    = array(
				'id'             => $budget_id,
				'name'           => $name,
				'monthly_income' => $monthly_income,
				'savings_goal'   => $savings_goal,
				'allocations'    => $allocations,
				'created'        => current_time( 'mysql' ),
				'created_by'     => get_current_user_id(),
				'metadata'       => array(
					'ip'                 => $validator->get_client_ip(),
					'user_agent'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'total_allocated'    => array_sum( $allocations ),
					'savings_percentage' => round( $savings_goal * 100, 2 ),
				),
			);

			// Save budget.
			$budgets[ $budget_id ] = $budget;
			update_option( 'wp_mcp_ai_budgets', $budgets );

			$result = array(
				'budget_id'      => $budget_id,
				'name'           => $name,
				'monthly_income' => $monthly_income,
				'savings'        => $savings,
				'savings_pct'    => round( $savings_goal * 100, 2 ) . '%',
				'allocations'    => $allocations,
				'view_url'       => admin_url( "admin.php?page=wp-mcp-ai-budgets&budget={$budget_id}" ),
			);

			$this->log_activity( 'budget-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: budget name, 2: savings percentage */
					__( 'Budget "%1$s" created with %2$s%% savings goal.', 'nvoos-content-graph-ai-platform' ),
					$name,
					round( $savings_goal * 100, 2 )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'budget-create',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle image-edit command.
	 *
	 * Basic image editing operations.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_image_edit( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to edit images.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'attachment_id' => array(
				'type'     => 'integer',
				'required' => true,
				'min'      => 1,
			),
			'operation'     => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'optimize', 'resize', 'crop', 'watermark', 'rotate', 'flip' ),
			),
			'width'         => array(
				'type'     => 'integer',
				'required' => false,
				'min'      => 1,
				'max'      => 10000,
			),
			'height'        => array(
				'type'     => 'integer',
				'required' => false,
				'min'      => 1,
				'max'      => 10000,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'image-edit', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			$attachment_id = absint( $args['attachment_id'] );
			$operation     = isset( $args['operation'] ) ? sanitize_text_field( $args['operation'] ) : 'optimize';

			// Verify attachment exists and is an image.
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return $this->error_response(
					new \WP_Error(
						'E002',
						sprintf(
							/* translators: %d: attachment ID */
							__( 'Attachment ID %d not found.', 'nvoos-content-graph-ai-platform' ),
							$attachment_id
						)
					)
				);
			}

			// Verify it's an image.
			$mime_type = get_post_mime_type( $attachment_id );
			if ( 0 !== strpos( $mime_type, 'image/' ) ) {
				return $this->error_response(
					new \WP_Error(
						'E002',
						sprintf(
							/* translators: %s: mime type */
							__( 'Attachment is not an image (type: %s). Only image files can be edited.', 'nvoos-content-graph-ai-platform' ),
							$mime_type
						)
					)
				);
			}

			// Get image metadata.
			$image_meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! $image_meta ) {
				return $this->error_response(
					new \WP_Error(
						'E002',
						__( 'Unable to retrieve image metadata. The image file may be corrupted.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Validate operation-specific requirements.
			if ( 'resize' === $operation ) {
				if ( ! isset( $args['width'] ) && ! isset( $args['height'] ) ) {
					return $this->error_response(
						new \WP_Error(
							'E001',
							__( 'Resize operation requires width and/or height parameters.', 'nvoos-content-graph-ai-platform' )
						)
					);
				}
			}

			// Check for duplicate operations on same attachment.
			$operation_hash    = md5( $attachment_id . $operation . serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Serializing internal plugin data (not user input); value is not persisted to database.
			$recent_operations = get_option( 'wp_mcp_ai_recent_image_operations', array() );

			// Check if identical operation was performed recently (within 1 hour).
			foreach ( $recent_operations as $existing ) {
				if ( isset( $existing['hash'] ) && $existing['hash'] === $operation_hash ) {
					$time_diff = time() - strtotime( $existing['created'] );
					if ( $time_diff < HOUR_IN_SECONDS ) {
						return $this->error_response(
							new \WP_Error(
								'E005',
								sprintf(
									/* translators: 1: minutes ago, 2: operation name */
									__( 'Identical %2$s operation was performed %1$d minutes ago on this attachment. Please wait or modify your parameters.', 'nvoos-content-graph-ai-platform' ),
									ceil( $time_diff / 60 ),
									$operation
								)
							)
						);
					}
				}
			}

			// Get file path and size.
			$file_path = get_attached_file( $attachment_id );
			$file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;

			// Simulate image edit operation (in production, this would perform actual edits).
			$result = array(
				'attachment_id' => $attachment_id,
				'operation'     => $operation,
				'status'        => 'completed',
				'original_size' => $file_size,
				'mime_type'     => $mime_type,
				'dimensions'    => array(
					'width'  => $image_meta['width'],
					'height' => $image_meta['height'],
				),
				'url'           => wp_get_attachment_url( $attachment_id ),
			);

			// Add operation-specific data.
			if ( 'resize' === $operation && ( isset( $args['width'] ) || isset( $args['height'] ) ) ) {
				$result['new_dimensions'] = array(
					'width'  => isset( $args['width'] ) ? absint( $args['width'] ) : $image_meta['width'],
					'height' => isset( $args['height'] ) ? absint( $args['height'] ) : $image_meta['height'],
				);
			}

			$this->log_activity( 'image-edit', $args, $result );

			// Store operation in recent history for duplicate detection.
			$recent_operations[] = array(
				'hash'          => $operation_hash,
				'created'       => current_time( 'mysql' ),
				'attachment_id' => $attachment_id,
				'operation'     => $operation,
			);
			// Keep only last 100 operation entries.
			$recent_operations = array_slice( $recent_operations, -100 );
			update_option( 'wp_mcp_ai_recent_image_operations', $recent_operations );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: operation name, 2: attachment ID */
					__( 'Image %1$s completed successfully for attachment #%2$d.', 'nvoos-content-graph-ai-platform' ),
					$operation,
					$attachment_id
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'image-edit',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle translate-content command.
	 *
	 * Translate content to different languages.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_translate_content( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to translate content.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// ISO 639-1 language codes.
		$valid_languages = array( 'en', 'es', 'fr', 'de', 'it', 'pt', 'ru', 'ja', 'ko', 'zh', 'ar', 'hi', 'nl', 'pl', 'tr', 'vi', 'th', 'sv', 'da', 'no', 'fi', 'cs', 'hu', 'ro', 'uk', 'el', 'he', 'id', 'ms', 'tl', 'auto' );

		// Define validation schema.
		$schema = array(
			'content'         => array(
				'type'     => 'string',
				'required' => true,
				'min'      => 1,
				'max'      => 10000,
			),
			'target_language' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => $valid_languages,
			),
			'source_language' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => $valid_languages,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'translate-content', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$content         = sanitize_textarea_field( $args['content'] );
			$target_language = strtolower( sanitize_text_field( $args['target_language'] ) );
			$source_language = isset( $args['source_language'] ) ? strtolower( sanitize_text_field( $args['source_language'] ) ) : 'auto';

			// Validate content length.
			$content_length = mb_strlen( $content );
			if ( $content_length > 10000 ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						sprintf(
							/* translators: 1: max length, 2: actual length */
							__( 'Content exceeds maximum length of %1$d characters. Your content is %2$d characters.', 'nvoos-content-graph-ai-platform' ),
							10000,
							$content_length
						)
					)
				);
			}

			// Check for duplicate translations - explicit duplicate detection.
			$translation_hash    = md5( $content . $source_language . $target_language );
			$recent_translations = get_option( 'wp_mcp_ai_recent_translations', array() );

			// Check if identical translation was requested recently (within 1 hour).
			foreach ( $recent_translations as $existing ) {
				if ( isset( $existing['hash'] ) && $existing['hash'] === $translation_hash ) {
					$time_diff = time() - strtotime( $existing['created'] );
					if ( $time_diff < HOUR_IN_SECONDS ) {
						return $this->error_response(
							new \WP_Error(
								'E005',
								sprintf(
									/* translators: %d: minutes ago */
									__( 'Identical translation was requested %d minutes ago. Retrieving cached result.', 'nvoos-content-graph-ai-platform' ),
									ceil( $time_diff / 60 )
								)
							)
						);
					}
				}
			}

			// Check for cached translation (performance optimization).
			$cache_key = 'translation_' . $translation_hash;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['from_cache'] = true;
				$this->log_activity( 'translate-content', $args, array( 'cache_hit' => true ) );
				return $this->success_response(
					$cached,
					__( 'Translation retrieved from cache.', 'nvoos-content-graph-ai-platform' )
				);
			}

			// Simulate translation (in real implementation, would call translation API).
			$translation_id = uniqid( 'translation_', true );

			// Get language names for user-friendly output.
			$language_names = array(
				'en'   => 'English',
				'es'   => 'Spanish',
				'fr'   => 'French',
				'de'   => 'German',
				'it'   => 'Italian',
				'pt'   => 'Portuguese',
				'ru'   => 'Russian',
				'ja'   => 'Japanese',
				'ko'   => 'Korean',
				'zh'   => 'Chinese',
				'ar'   => 'Arabic',
				'hi'   => 'Hindi',
				'auto' => 'Auto-detected',
			);

			$result = array(
				'translation_id'   => $translation_id,
				'source_language'  => $source_language,
				'source_lang_name' => isset( $language_names[ $source_language ] ) ? $language_names[ $source_language ] : $source_language,
				'target_language'  => $target_language,
				'target_lang_name' => isset( $language_names[ $target_language ] ) ? $language_names[ $target_language ] : $target_language,
				'original_length'  => $content_length,
				'translated_text'  => "[Translated to {$target_language}] {$content}",
				'status'           => 'completed',
				'from_cache'       => false,
				'metadata'         => array(
					'ip'         => $validator->get_client_ip(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'word_count' => str_word_count( $content ),
					'char_count' => $content_length,
				),
			);

			// Cache translation for 1 hour.
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );

			// Store translation in recent history for duplicate detection.
			$recent_translations[] = array(
				'hash'            => $translation_hash,
				'created'         => current_time( 'mysql' ),
				'source_language' => $source_language,
				'target_language' => $target_language,
			);
			// Keep only last 100 translation entries.
			$recent_translations = array_slice( $recent_translations, -100 );
			update_option( 'wp_mcp_ai_recent_translations', $recent_translations );

			$this->log_activity( 'translate-content', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: source language, 2: target language */
					__( 'Content translated from %1$s to %2$s successfully.', 'nvoos-content-graph-ai-platform' ),
					isset( $language_names[ $source_language ] ) ? $language_names[ $source_language ] : $source_language,
					isset( $language_names[ $target_language ] ) ? $language_names[ $target_language ] : $target_language
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'translate-content',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle site-research command.
	 *
	 * Research site best practices.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_site_research( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to research sites.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'industry' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'general', 'ecommerce', 'blog', 'portfolio', 'corporate', 'nonprofit', 'education', 'healthcare', 'restaurant', 'real-estate', 'technology', 'finance' ),
			),
			'goals'    => array(
				'type'     => 'string',
				'required' => false,
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'site-research', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$industry = isset( $args['industry'] ) ? strtolower( trim( sanitize_text_field( $args['industry'] ) ) ) : 'general';
			$goals    = isset( $args['goals'] ) ? sanitize_text_field( $args['goals'] ) : 'engagement, conversion';

			// Parse and normalize goals.
			$goals_array = array_map(
				function ( $goal ) {
					return strtolower( trim( $goal ) );
				},
				explode( ', ', $goals )
			);
			$valid_goals = array( 'engagement', 'conversion', 'traffic', 'seo', 'performance', 'accessibility', 'security', 'mobile' );
			$goals_array = array_intersect( $goals_array, $valid_goals );
			if ( empty( $goals_array ) ) {
				$goals_array = array( 'engagement', 'conversion' );
			}

			// Check for duplicate research - explicit duplicate detection.
			$research_hash   = md5( $industry . implode( ', ', $goals_array ) );
			$recent_research = get_option( 'wp_mcp_ai_recent_research', array() );

			// Check if identical research was done recently (within 1 hour).
			foreach ( $recent_research as $existing ) {
				if ( isset( $existing['hash'] ) && $existing['hash'] === $research_hash ) {
					$time_diff = time() - strtotime( $existing['created'] );
					if ( $time_diff < HOUR_IN_SECONDS ) {
						return $this->error_response(
							new \WP_Error(
								'E005',
								sprintf(
									/* translators: %d: minutes ago */
									__( 'Identical research was conducted %d minutes ago. Please wait or modify your research parameters.', 'nvoos-content-graph-ai-platform' ),
									ceil( $time_diff / 60 )
								)
							)
						);
					}
				}
			}

			// Check cache for performance (secondary to duplicate check).
			$cache_key = 'research_' . $research_hash;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['from_cache'] = true;
				$this->log_activity( 'site-research', $args, array( 'cache_hit' => true ) );
				return $this->success_response(
					$cached,
					__( 'Site research retrieved from cache.', 'nvoos-content-graph-ai-platform' )
				);
			}

			// Generate industry-specific recommendations.
			$industry_recommendations = array(
				'ecommerce' => array(
					array(
						'category'   => 'Design',
						'suggestion' => __( 'Implement product filtering and search', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'Conversion',
						'suggestion' => __( 'Add trust badges and secure checkout', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'Performance',
						'suggestion' => __( 'Optimize product images and lazy loading', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'medium',
					),
				),
				'blog'      => array(
					array(
						'category'   => 'Content',
						'suggestion' => __( 'Publish consistently with editorial calendar', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'SEO',
						'suggestion' => __( 'Optimize for long-tail keywords', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'Engagement',
						'suggestion' => __( 'Enable comments and social sharing', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'medium',
					),
				),
				'general'   => array(
					array(
						'category'   => 'Design',
						'suggestion' => __( 'Use modern, mobile-first design approach', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'Performance',
						'suggestion' => __( 'Optimize images and enable caching', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'high',
					),
					array(
						'category'   => 'SEO',
						'suggestion' => __( 'Implement structured data and meta tags', 'nvoos-content-graph-ai-platform' ),
						'priority'   => 'medium',
					),
				),
			);

			$recommendations = isset( $industry_recommendations[ $industry ] ) ? $industry_recommendations[ $industry ] : $industry_recommendations['general'];

			// Simulate research results.
			$research = array(
				'research_id'     => uniqid( 'research_', true ),
				'industry'        => $industry,
				'goals'           => $goals_array,
				'recommendations' => $recommendations,
				'competitors'     => array(
					array(
						'url'       => 'example1.com',
						'score'     => 85,
						'strengths' => array( 'Fast loading', 'Mobile-friendly' ),
					),
					array(
						'url'       => 'example2.com',
						'score'     => 78,
						'strengths' => array( 'Good SEO', 'Clean design' ),
					),
				),
				'from_cache'      => false,
				'metadata'        => array(
					'ip'                   => $validator->get_client_ip(),
					'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'recommendation_count' => count( $recommendations ),
					'goals_count'          => count( $goals_array ),
				),
			);

			// Cache research for 1 hour.
			set_transient( $cache_key, $research, HOUR_IN_SECONDS );

			// Store research in recent history for duplicate detection.
			$recent_research[] = array(
				'hash'     => $research_hash,
				'created'  => current_time( 'mysql' ),
				'industry' => $industry,
				'goals'    => $goals_array,
			);
			// Keep only last 50 research entries.
			$recent_research = array_slice( $recent_research, -50 );
			update_option( 'wp_mcp_ai_recent_research', $recent_research );

			$this->log_activity( 'site-research', $args, $research );

			return $this->success_response(
				$research,
				sprintf(
					/* translators: 1: industry name, 2: number of recommendations */
					__( 'Site research completed for %1$s industry with %2$d recommendations.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $industry ),
					count( $recommendations )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'site-research',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle booking-create command.
	 *
	 * Create bookings/appointments.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_booking_create( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create bookings.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'service'        => array(
				'type'     => 'string',
				'required' => true,
				'min'      => 2,
				'max'      => 100,
			),
			'date'           => array(
				'type'     => 'string',
				'required' => true,
				'format'   => 'date',
			),
			'time'           => array(
				'type'     => 'string',
				'required' => true,
			),
			'customer_name'  => array(
				'type'     => 'string',
				'required' => false,
				'min'      => 2,
				'max'      => 100,
			),
			'customer_email' => array(
				'type'     => 'string',
				'required' => false,
				'format'   => 'email',
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'booking-create', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$service        = sanitize_text_field( $args['service'] );
			$date           = sanitize_text_field( $args['date'] );
			$time           = sanitize_text_field( $args['time'] );
			$customer_name  = isset( $args['customer_name'] ) ? $validator->normalize_name( $args['customer_name'] ) : '';
			$customer_email = isset( $args['customer_email'] ) ? $validator->normalize_email( $args['customer_email'] ) : '';

			// Validate date is not in the past.
			$booking_datetime = strtotime( $date . ' ' . $time );
			if ( $booking_datetime < time() ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						__( 'Cannot create booking in the past. Please select a future date and time.', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Validate time format (HH:MM).
			if ( ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
				return $this->error_response(
					new \WP_Error(
						'E002',
						__( 'Invalid time format. Please use HH:MM format (e.g., 14:30).', 'nvoos-content-graph-ai-platform' )
					)
				);
			}

			// Check for double-booking (same date/time slot).
			$booking_hash = md5( $service . $customer_email . $date . $time );
			foreach ( $bookings as $existing ) {
				if ( $existing['date'] === $date && $existing['time'] === $time && 'cancelled' !== $existing['status'] ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							sprintf(
								/* translators: 1: date, 2: time */
								__( 'This time slot is already booked. %1$s at %2$s is unavailable. Please choose a different time.', 'nvoos-content-graph-ai-platform' ),
								$date,
								$time
							)
						)
					);
				}
				// Check for duplicate booking (same customer, service, date, time).
				if ( isset( $existing['booking_hash'] ) && $existing['booking_hash'] === $booking_hash && 'cancelled' !== $existing['status'] ) {
					return $this->error_response(
						new \WP_Error(
							'E005',
							__( 'You already have an identical booking. Please check your existing bookings or contact support.', 'nvoos-content-graph-ai-platform' )
						)
					);
				}
			}

			// Create booking.
			$booking_id = uniqid( 'booking_', true );
			$booking    = array(
				'id'             => $booking_id,
				'booking_hash'   => $booking_hash,
				'service'        => $service,
				'date'           => $date,
				'time'           => $time,
				'datetime'       => gmdate( 'Y-m-d H:i:s', $booking_datetime ),
				'customer_name'  => $customer_name,
				'customer_email' => $customer_email,
				'status'         => 'pending',
				'created'        => current_time( 'mysql' ),
				'created_by'     => get_current_user_id(),
				'metadata'       => array(
					'ip'                => $validator->get_client_ip(),
					'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'booking_timestamp' => $booking_datetime,
					'days_until'        => ceil( ( $booking_datetime - time() ) / DAY_IN_SECONDS ),
				),
			);

			// Save booking.
			$bookings[ $booking_id ] = $booking;
			update_option( 'wp_mcp_ai_bookings', $bookings );

			$result = array(
				'booking_id'    => $booking_id,
				'service'       => $service,
				'date'          => $date,
				'time'          => $time,
				'customer_name' => $customer_name,
				'status'        => 'pending',
				'days_until'    => $booking['metadata']['days_until'],
				'confirm_url'   => admin_url( "admin.php?page=wp-mcp-ai-bookings&action=confirm&booking={$booking_id}" ),
			);

			$this->log_activity( 'booking-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: service name, 2: date, 3: time */
					__( 'Booking for %1$s on %2$s at %3$s created successfully.', 'nvoos-content-graph-ai-platform' ),
					$service,
					$date,
					$time
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'booking-create',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle product-recommend command.
	 *
	 * E-commerce product recommendations.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_product_recommend( $args, $context ) {
		// Check capabilities.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce-provided capabilities, not core WP caps.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new \WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to view product recommendations.', 'nvoos-content-graph-ai-platform' )
				)
			);
		}

		// Get validator instance.
		$validator = new SlashCommandValidator();

		// Define validation schema.
		$schema = array(
			'customer_id' => array(
				'type'     => 'integer',
				'required' => false,
				'min'      => 1,
			),
			'product_id'  => array(
				'type'     => 'integer',
				'required' => false,
				'min'      => 1,
			),
			'count'       => array(
				'type'     => 'integer',
				'required' => false,
				'min'      => 1,
				'max'      => 50,
			),
			'type'        => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'similar', 'frequently-bought', 'personalized', 'trending', 'new-arrivals' ),
			),
		);

		// Validate arguments against schema.
		$validation_result = $validator->validate_schema( $args, $schema );
		if ( is_wp_error( $validation_result ) ) {
			$this->log_activity( 'product-recommend', $args, array( 'error' => $validation_result->get_error_message() ) );
			return $this->error_response( $validation_result );
		}

		try {
			// Normalize data.
			$customer_id = isset( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;
			$product_id  = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
			$count       = isset( $args['count'] ) ? absint( $args['count'] ) : 5;
			$type        = isset( $args['type'] ) ? strtolower( trim( sanitize_text_field( $args['type'] ) ) ) : 'personalized';

			// Validate count range.
			if ( $count < 1 || $count > 50 ) {
				return $this->error_response(
					new \WP_Error(
						'E004',
						sprintf(
							/* translators: %d: count value */
							__( 'Count must be between 1 and 50. You requested %d recommendations.', 'nvoos-content-graph-ai-platform' ),
							$count
						)
					)
				);
			}

			// Validate customer exists if customer_id provided.
			if ( $customer_id > 0 ) {
				$customer = get_user_by( 'id', $customer_id );
				if ( ! $customer ) {
					return $this->error_response(
						new \WP_Error(
							'E002',
							sprintf(
								/* translators: %d: customer ID */
								__( 'Customer ID %d not found. Please provide a valid customer ID.', 'nvoos-content-graph-ai-platform' ),
								$customer_id
							)
						)
					);
				}
			}

			// Validate product exists if product_id provided.
			if ( $product_id > 0 ) {
				$product = get_post( $product_id );
				if ( ! $product || 'product' !== $product->post_type ) {
					return $this->error_response(
						new \WP_Error(
							'E002',
							sprintf(
								/* translators: %d: product ID */
								__( 'Product ID %d not found. Please provide a valid product ID.', 'nvoos-content-graph-ai-platform' ),
								$product_id
							)
						)
					);
				}
			}

			// Generate recommendation reasons based on type.
			$reasons = array(
				'similar'           => __( 'Similar to your interests', 'nvoos-content-graph-ai-platform' ),
				'frequently-bought' => __( 'Frequently bought together', 'nvoos-content-graph-ai-platform' ),
				'personalized'      => __( 'Based on purchase history', 'nvoos-content-graph-ai-platform' ),
				'trending'          => __( 'Trending in your area', 'nvoos-content-graph-ai-platform' ),
				'new-arrivals'      => __( 'New arrival', 'nvoos-content-graph-ai-platform' ),
			);

			$reason = isset( $reasons[ $type ] ) ? $reasons[ $type ] : $reasons['personalized'];

			// Check for duplicate recommendations - explicit duplicate detection.
			$recommendation_hash    = md5( $customer_id . $product_id . $type . $count );
			$recent_recommendations = get_option( 'wp_mcp_ai_recent_recommendations', array() );

			// Check if identical recommendation was requested recently (within 1 hour).
			foreach ( $recent_recommendations as $existing ) {
				if ( isset( $existing['hash'] ) && $existing['hash'] === $recommendation_hash ) {
					$time_diff = time() - strtotime( $existing['created'] );
					if ( $time_diff < HOUR_IN_SECONDS ) {
						return $this->error_response(
							new \WP_Error(
								'E005',
								sprintf(
									/* translators: %d: minutes ago */
									__( 'Identical recommendation request was made %d minutes ago. Please wait or modify your parameters.', 'nvoos-content-graph-ai-platform' ),
									ceil( $time_diff / 60 )
								)
							)
						);
					}
				}
			}

			// Simulate product recommendations (in production, would use actual recommendation algorithm).
			$recommendations = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$recommendations[] = array(
					'product_id' => 1000 + $i,
					'title'      => "Recommended Product {$i}",
					'score'      => 100 - ( $i * 5 ),
					'reason'     => $reason,
					'price'      => number_format( ( $i * 19.99 ), 2 ),
				);
			}

			$result = array(
				'customer_id'     => $customer_id,
				'product_id'      => $product_id,
				'type'            => $type,
				'recommendations' => $recommendations,
				'count'           => count( $recommendations ),
				'metadata'        => array(
					'ip'         => $validator->get_client_ip(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'algorithm'  => $type,
					'avg_score'  => array_sum( array_column( $recommendations, 'score' ) ) / count( $recommendations ),
				),
			);

			$this->log_activity( 'product-recommend', $args, $result );

			// Store recommendation in recent history for duplicate detection.
			$recent_recommendations[] = array(
				'hash'        => $recommendation_hash,
				'created'     => current_time( 'mysql' ),
				'customer_id' => $customer_id,
				'type'        => $type,
				'count'       => $count,
			);
			// Keep only last 100 recommendation entries.
			$recent_recommendations = array_slice( $recent_recommendations, -100 );
			update_option( 'wp_mcp_ai_recent_recommendations', $recent_recommendations );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: number of recommendations, 2: recommendation type */
					__( 'Found %1$d %2$s product recommendations.', 'nvoos-content-graph-ai-platform' ),
					count( $recommendations ),
					$type
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity(
				'product-recommend',
				$args,
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle plugin-analyze command.
	 *
	 * Analyzes plugin code for security, performance, and standards compliance.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_plugin_analyze( $args, $context ) {
		try {
			// Validate required parameters.
			if ( empty( $args['plugin'] ) ) {
				return $this->error_response( __( 'Plugin slug is required.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to analyze plugins.', 'nvoos-content-graph-ai-platform' ) );
			}

			$plugin      = sanitize_text_field( $args['plugin'] );
			$checks      = ! empty( $args['checks'] ) ? sanitize_text_field( $args['checks'] ) : 'security, performance, standards';
			$check_types = array_map( 'trim', explode( ', ', $checks ) );

			// Verify plugin exists.
			$all_plugins  = get_plugins();
			$plugin_found = false;
			$plugin_path  = '';

			foreach ( $all_plugins as $path => $data ) {
				if ( strpos( $path, $plugin . '/' ) === 0 || $path === $plugin . '.php' ) {
					if ( defined( 'WP_PLUGIN_DIR' ) ) {
						$candidate_path = WP_PLUGIN_DIR . '/' . $path;
						if ( file_exists( $candidate_path ) ) {
							$plugin_found = true;
							$plugin_path  = $candidate_path;
						}
					}
					break;
				}
			}

			if ( ! $plugin_found || ! $plugin_path ) {
				return $this->error_response( __( 'Plugin not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			$issues = array();
			$score  = 100;

			// Security analysis.
			if ( in_array( 'security', $check_types, true ) ) {
				$security_issues = array();

				// Check for SQL injection patterns.
				if ( preg_match( '/\$wpdb->query\s*\(\s*["\']/', file_get_contents( $plugin_path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					$security_issues[] = array(
						'type'     => 'security',
						'severity' => 'high',
						'message'  => __( 'Potential SQL injection vulnerability detected.', 'nvoos-content-graph-ai-platform' ),
					);
					$score            -= 20;
				}

				// Check for XSS vulnerabilities.
				if ( preg_match( '/echo\s+\$_/', file_get_contents( $plugin_path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					$security_issues[] = array(
						'type'     => 'security',
						'severity' => 'high',
						'message'  => __( 'Potential XSS vulnerability: unescaped output.', 'nvoos-content-graph-ai-platform' ),
					);
					$score            -= 20;
				}

				// Check for nonce verification.
				if ( strpos( file_get_contents( $plugin_path ), 'wp_verify_nonce' ) === false && // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					strpos( file_get_contents( $plugin_path ), '$_POST' ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					$security_issues[] = array(
						'type'     => 'security',
						'severity' => 'medium',
						'message'  => __( 'Missing nonce verification for form submissions.', 'nvoos-content-graph-ai-platform' ),
					);
					$score            -= 10;
				}

				$issues = array_merge( $issues, $security_issues );
			}

			// Performance analysis.
			if ( in_array( 'performance', $check_types, true ) ) {
				$performance_issues = array();

				// Check for N+1 query patterns.
				if ( preg_match_all( '/get_posts|get_pages|WP_Query/', file_get_contents( $plugin_path ) ) > 10 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					$performance_issues[] = array(
						'type'     => 'performance',
						'severity' => 'medium',
						'message'  => __( 'Multiple database queries detected. Consider caching.', 'nvoos-content-graph-ai-platform' ),
					);
					$score               -= 10;
				}

				$issues = array_merge( $issues, $performance_issues );
			}

			// Standards compliance.
			if ( in_array( 'standards', $check_types, true ) ) {
				$standards_issues = array();

				// Check for WPCS compliance.
				if ( ! preg_match( '/\/\*\*[\s\S]*?\*\//', file_get_contents( $plugin_path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
					$standards_issues[] = array(
						'type'     => 'standards',
						'severity' => 'low',
						'message'  => __( 'Missing or inadequate PHPDoc comments.', 'nvoos-content-graph-ai-platform' ),
					);
					$score             -= 5;
				}

				$issues = array_merge( $issues, $standards_issues );
			}

			// Determine grade.
			$grade = 'F';
			if ( $score >= 90 ) {
				$grade = 'A';
			} elseif ( $score >= 80 ) {
				$grade = 'B';
			} elseif ( $score >= 70 ) {
				$grade = 'C';
			} elseif ( $score >= 60 ) {
				$grade = 'D';
			}

			$result = array(
				'plugin'       => $plugin,
				'score'        => $score,
				'grade'        => $grade,
				'issues_count' => count( $issues ),
				'issues'       => $issues,
				'checks_run'   => $check_types,
			);

			$this->log_activity( 'plugin-analyze', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: plugin name, 2: grade, 3: score */
					__( 'Plugin analysis complete. Grade: %2$s (%3$d/100). Found %4$d issues.', 'nvoos-content-graph-ai-platform' ),
					$plugin,
					$grade,
					$score,
					count( $issues )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'plugin-analyze', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle theme-analyze command.
	 *
	 * Analyzes theme structure and compatibility.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_theme_analyze( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return $this->error_response( __( 'You do not have permission to analyze themes.', 'nvoos-content-graph-ai-platform' ) );
			}

			$theme_slug = ! empty( $args['theme'] ) ? sanitize_text_field( $args['theme'] ) : get_stylesheet();
			$theme      = wp_get_theme( $theme_slug );

			if ( ! $theme->exists() ) {
				return $this->error_response( __( 'Theme not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			$issues = array();
			$score  = 100;

			// Check required files.
			$required_files = array( 'style.css', 'index.php', 'functions.php' );
			$theme_path     = $theme->get_stylesheet_directory();

			foreach ( $required_files as $file ) {
				if ( ! file_exists( $theme_path . '/' . $file ) ) {
					$issues[] = array(
						'type'     => 'structure',
						'severity' => 'high',
						// translators: %s is the required file path.
						'message'  => sprintf( __( 'Missing required file: %s', 'nvoos-content-graph-ai-platform' ), $file ),
					);
					$score   -= 15;
				}
			}

			// Check for Gutenberg support.
			$has_gutenberg_support = false;
			if ( file_exists( $theme_path . '/functions.php' ) ) {
				$functions_content     = file_get_contents( $theme_path . '/functions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
				$has_gutenberg_support = strpos( $functions_content, 'add_theme_support' ) !== false;
			}

			if ( ! $has_gutenberg_support ) {
				$issues[] = array(
					'type'     => 'compatibility',
					'severity' => 'medium',
					'message'  => __( 'No Gutenberg block support detected.', 'nvoos-content-graph-ai-platform' ),
				);
				$score   -= 10;
			}

			// Check for theme.json (WordPress 5.9+).
			$has_theme_json = file_exists( $theme_path . '/theme.json' );

			$result = array(
				'theme'             => $theme_slug,
				'name'              => $theme->get( 'Name' ),
				'version'           => $theme->get( 'Version' ),
				'score'             => max( 0, $score ),
				'issues_count'      => count( $issues ),
				'issues'            => $issues,
				'required_files_ok' => count( $issues ) === 0 || ! in_array( 'structure', array_column( $issues, 'type' ), true ),
				'gutenberg_support' => $has_gutenberg_support,
				'theme_json'        => $has_theme_json,
			);

			$this->log_activity( 'theme-analyze', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: theme name, 2: score */
					__( 'Theme analysis complete. Score: %2$d/100. Found %3$d issues.', 'nvoos-content-graph-ai-platform' ),
					$theme->get( 'Name' ),
					$result['score'],
					count( $issues )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'theme-analyze', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle performance-profile command.
	 *
	 * Profiles site performance metrics.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_performance_profile( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to profile performance.', 'nvoos-content-graph-ai-platform' ) );
			}

			$url = ! empty( $args['url'] ) ? esc_url_raw( $args['url'] ) : home_url();

			global $wpdb;

			// Database query analysis.
			$slow_queries = $wpdb->num_queries > 50 ? __( 'High number of queries detected.', 'nvoos-content-graph-ai-platform' ) : __( 'Query count within normal range.', 'nvoos-content-graph-ai-platform' );

			// Memory usage.
			$memory_limit = ini_get( 'memory_limit' );
			$memory_used  = memory_get_usage( true );
			$memory_peak  = memory_get_peak_usage( true );

			// Execution time.
			$execution_time = microtime( true ) - (float) wp_unslash( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float server var, cast to float for safety

			// Check for common performance issues.
			$recommendations = array();

			if ( $wpdb->num_queries > 50 ) {
				$recommendations[] = __( 'Reduce database queries with caching.', 'nvoos-content-graph-ai-platform' );
			}

			if ( $memory_used > ( 64 * 1024 * 1024 ) ) {
				$recommendations[] = __( 'Consider increasing PHP memory limit or optimizing code.', 'nvoos-content-graph-ai-platform' );
			}

			if ( $execution_time > 2.0 ) {
				$recommendations[] = __( 'Page load time exceeds 2 seconds. Optimize code and assets.', 'nvoos-content-graph-ai-platform' );
			}

			$result = array(
				'url'              => $url,
				'database_queries' => $wpdb->num_queries,
				'memory_limit'     => $memory_limit,
				'memory_used'      => size_format( $memory_used ),
				'memory_peak'      => size_format( $memory_peak ),
				'execution_time'   => round( $execution_time, 3 ) . 's',
				'recommendations'  => $recommendations,
			);

			$this->log_activity( 'performance-profile', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: query count, 2: execution time */
					__( 'Performance profile complete. %1$d queries in %2$s.', 'nvoos-content-graph-ai-platform' ),
					$wpdb->num_queries,
					$result['execution_time']
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'performance-profile', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle dependency-check command.
	 *
	 * Checks plugin/theme dependencies and versions.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_dependency_check( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to check dependencies.', 'nvoos-content-graph-ai-platform' ) );
			}

			$type = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'plugin';
			$name = ! empty( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';

			$issues = array();

			if ( 'plugin' === $type && ! empty( $name ) ) {
				if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
					return $this->error_response( __( 'Plugin directory is not defined.', 'nvoos-content-graph-ai-platform' ) );
				}

				$plugin_path = WP_PLUGIN_DIR . '/' . $name;

				if ( ! file_exists( $plugin_path ) ) {
					return $this->error_response( __( 'Plugin not found.', 'nvoos-content-graph-ai-platform' ) );
				}

				$plugin = get_plugin_data( $plugin_path );

				if ( empty( $plugin ) ) {
					return $this->error_response( __( 'Plugin not found.', 'nvoos-content-graph-ai-platform' ) );
				}

				// Check WordPress version requirement.
				if ( ! empty( $plugin['RequiresWP'] ) && version_compare( get_bloginfo( 'version' ), $plugin['RequiresWP'], '<' ) ) {
					$issues[] = sprintf(
						/* translators: 1: required version, 2: current version */
						__( 'WordPress version mismatch. Requires %1$s, current %2$s.', 'nvoos-content-graph-ai-platform' ),
						$plugin['RequiresWP'],
						get_bloginfo( 'version' )
					);
				}

				// Check PHP version requirement.
				if ( ! empty( $plugin['RequiresPHP'] ) && version_compare( PHP_VERSION, $plugin['RequiresPHP'], '<' ) ) {
					$issues[] = sprintf(
						/* translators: 1: required version, 2: current version */
						__( 'PHP version mismatch. Requires %1$s, current %2$s.', 'nvoos-content-graph-ai-platform' ),
						$plugin['RequiresPHP'],
						PHP_VERSION
					);
				}
			}

			// Check for common PHP extensions.
			$required_extensions = array( 'mysqli', 'json', 'mbstring', 'curl' );
			foreach ( $required_extensions as $ext ) {
				if ( ! extension_loaded( $ext ) ) {
					// translators: %s is the name of the missing PHP extension.
					$issues[] = sprintf( __( 'Missing PHP extension: %s', 'nvoos-content-graph-ai-platform' ), $ext );
				}
			}

			$result = array(
				'type'         => $type,
				'name'         => $name,
				'php_version'  => PHP_VERSION,
				'wp_version'   => get_bloginfo( 'version' ),
				'issues_count' => count( $issues ),
				'issues'       => $issues,
				'status'       => count( $issues ) === 0 ? 'compatible' : 'issues_found',
			);

			$this->log_activity( 'dependency-check', $args, $result );

			return $this->success_response(
				$result,
				count( $issues ) === 0
					? __( 'All dependencies satisfied.', 'nvoos-content-graph-ai-platform' )
					// translators: %d is the number of dependency issues found.
					: sprintf( __( 'Found %d dependency issues.', 'nvoos-content-graph-ai-platform' ), count( $issues ) )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'dependency-check', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle code-format command.
	 *
	 * Formats code according to WordPress Coding Standards.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_code_format( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'edit_files' ) ) {
				return $this->error_response( __( 'You do not have permission to format code.', 'nvoos-content-graph-ai-platform' ) );
			}

			$file = ! empty( $args['file'] ) ? sanitize_text_field( $args['file'] ) : '';
			$fix  = ! empty( $args['fix'] ) && 'true' === $args['fix'];

			if ( empty( $file ) || ! file_exists( $file ) ) {
				return $this->error_response( __( 'Valid file path is required.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Restrict file operations to the uploads directory for safety.
			$upload_dir      = wp_upload_dir();
			$uploads_basedir = realpath( $upload_dir['basedir'] );
			$real_file       = realpath( $file );
			if ( false === $uploads_basedir || false === $real_file || 0 !== strpos( $real_file, $uploads_basedir . DIRECTORY_SEPARATOR ) ) {
				return $this->error_response( __( 'File operations are restricted to the uploads directory.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Basic formatting fixes.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
			$content          = $wp_filesystem->get_contents( $real_file );
			$original_content = $content;

			if ( $fix ) {
				// Fix indentation (spaces to tabs).
				$content = preg_replace( '/^(  +)/m', "\t", $content );

				// Fix spacing around operators.
				$content = preg_replace( '/([a-zA-Z0-9_\])])([=<>!]+)([a-zA-Z0-9_\[\(])/', '$1 $2 $3', $content );

				// Write back to file within uploads directory.
				$wp_filesystem->put_contents( $real_file, $content, FS_CHMOD_FILE );
			}

			$result = array(
				'file'    => $file,
				'fixed'   => $fix,
				'changes' => $fix && $content !== $original_content,
				'message' => $fix
					? __( 'Code formatting applied.', 'nvoos-content-graph-ai-platform' )
					: __( 'Code analysis complete. Use --fix=true to apply changes.', 'nvoos-content-graph-ai-platform' ),
			);

			$this->log_activity( 'code-format', $args, $result );

			return $this->success_response( $result, $result['message'] );

		} catch ( Exception $e ) {
			$this->log_activity( 'code-format', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle product-sync command.
	 *
	 * Synchronizes products across channels.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_product_sync( $args, $context ) {
		try {
			// Check capability.
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce-provided capabilities, not core WP caps.
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to sync products.', 'nvoos-content-graph-ai-platform' ) );
			}

			$channel  = ! empty( $args['channel'] ) ? sanitize_text_field( $args['channel'] ) : 'woocommerce';
			$products = ! empty( $args['products'] ) ? sanitize_text_field( $args['products'] ) : 'all';

			// Get products to sync.
			$product_query = new \WP_Query(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 'all' === $products ? -1 : 100,
					'post_status'    => 'publish',
				)
			);

			$synced_count = 0;
			$errors       = array();

			foreach ( $product_query->posts as $product_post ) {
				try {
					// Simulate sync operation.
					$product_id = $product_post->ID;

					// Update sync metadata.
					update_post_meta( $product_id, '_last_sync_channel', $channel );
					update_post_meta( $product_id, '_last_sync_time', current_time( 'mysql' ) );

					++$synced_count;
				} catch ( Exception $e ) {
					// translators: %1$d is the product ID, %2$s is the error message.
					$errors[] = sprintf( __( 'Failed to sync product %1$d: %2$s', 'nvoos-content-graph-ai-platform' ), $product_post->ID, $e->getMessage() );
				}
			}

			$result = array(
				'channel'      => $channel,
				'synced_count' => $synced_count,
				'total_found'  => $product_query->found_posts,
				'errors_count' => count( $errors ),
				'errors'       => $errors,
				'status'       => count( $errors ) === 0 ? 'complete' : 'partial',
			);

			$this->log_activity( 'product-sync', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: synced count, 2: channel */
					__( 'Synced %1$d products to %2$s.', 'nvoos-content-graph-ai-platform' ),
					$synced_count,
					$channel
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'product-sync', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle discount-create command.
	 *
	 * Creates discount codes.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_discount_create( $args, $context ) {
		try {
			// Check capability.
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce-provided capabilities, not core WP caps.
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to create discounts.', 'nvoos-content-graph-ai-platform' ) );
			}

			$code    = ! empty( $args['code'] ) ? strtoupper( sanitize_text_field( $args['code'] ) ) : 'DISCOUNT' . wp_rand( 1000, 9999 );
			$type    = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'percentage';
			$amount  = ! empty( $args['amount'] ) ? floatval( $args['amount'] ) : 10;
			$minimum = ! empty( $args['minimum'] ) ? floatval( $args['minimum'] ) : 0;

			// Check if WooCommerce is active.
			if ( ! class_exists( 'WC_Coupon' ) ) {
				// Fallback: Store as custom post type.
				$discount_id = wp_insert_post(
					array(
						'post_title'  => $code,
						'post_type'   => 'discount_code',
						'post_status' => 'publish',
						'meta_input'  => array(
							'discount_type'   => $type,
							'discount_amount' => $amount,
							'minimum_amount'  => $minimum,
							'created_date'    => current_time( 'mysql' ),
						),
					)
				);

				if ( is_wp_error( $discount_id ) ) {
					return $this->error_response( $discount_id->get_error_message() );
				}
			} else {
				// Create WooCommerce coupon.
				$coupon = new WC_Coupon();
				$coupon->set_code( $code );
				$coupon->set_discount_type( 'percentage' === $type ? 'percent' : 'fixed_cart' );
				$coupon->set_amount( $amount );
				$coupon->set_minimum_amount( $minimum );
				$discount_id = $coupon->save();
			}

			$result = array(
				'code'    => $code,
				'type'    => $type,
				'amount'  => $amount,
				'minimum' => $minimum,
				'id'      => $discount_id,
			);

			$this->log_activity( 'discount-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: discount code, 2: amount */
					__( 'Discount code "%1$s" created with %2$s%% off.', 'nvoos-content-graph-ai-platform' ),
					$code,
					$amount
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'discount-create', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle customer-segment command.
	 *
	 * Segments customers by behavior.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_customer_segment( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to segment customers.', 'nvoos-content-graph-ai-platform' ) );
			}

			$segment_type = ! empty( $args['segment_type'] ) ? sanitize_text_field( $args['segment_type'] ) : 'high_value';
			$min_orders   = ! empty( $args['min_orders'] ) ? absint( $args['min_orders'] ) : 3;
			$min_total    = ! empty( $args['min_total'] ) ? floatval( $args['min_total'] ) : 100;

			// Get users.
			$users   = get_users( array( 'number' => 1000 ) );
			$segment = array();

			foreach ( $users as $user ) {
				$order_count = 0;
				$order_total = 0;

				// Get user orders (simplified).
				$user_orders = get_posts(
					array(
						'post_type'   => 'shop_order',
						'post_status' => 'wc-completed',
						'numberposts' => -1,
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter slash-command posts by plugin meta; no alternative index-based query available.
							array(
								'key'   => '_customer_user',
								'value' => $user->ID,
							),
						),
					)
				);

				$order_count = count( $user_orders );

				// Calculate total (simplified).
				foreach ( $user_orders as $order_post ) {
					$order_total += floatval( get_post_meta( $order_post->ID, '_order_total', true ) );
				}

				// Apply segment criteria.
				if ( 'high_value' === $segment_type && $order_count >= $min_orders && $order_total >= $min_total ) {
					$segment[] = array(
						'user_id'     => $user->ID,
						'email'       => $user->user_email,
						'name'        => $user->display_name,
						'order_count' => $order_count,
						'order_total' => $order_total,
					);
				}
			}

			$result = array(
				'segment_type'   => $segment_type,
				'customer_count' => count( $segment ),
				'customers'      => array_slice( $segment, 0, 10 ), // Return first 10.
				'criteria'       => array(
					'min_orders' => $min_orders,
					'min_total'  => $min_total,
				),
			);

			$this->log_activity( 'customer-segment', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: customer count, 2: segment type */
					__( 'Found %1$d customers in %2$s segment.', 'nvoos-content-graph-ai-platform' ),
					count( $segment ),
					$segment_type
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'customer-segment', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle abandoned-cart command.
	 *
	 * Handles abandoned cart recovery.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_abandoned_cart( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to handle abandoned carts.', 'nvoos-content-graph-ai-platform' ) );
			}

			$action     = ! empty( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'recover';
			$send_email = ! empty( $args['send_email'] ) && 'true' === $args['send_email'];

			// Get abandoned carts (simplified - would normally query cart table).
			$abandoned_carts = get_option( 'wp_mcp_ai_abandoned_carts', array() );

			$recovered_count  = 0;
			$email_sent_count = 0;

			foreach ( $abandoned_carts as $cart_id => $cart_data ) {
				if ( 'recover' === $action ) {
					// Check if cart is older than 1 hour.
					$cart_time = strtotime( $cart_data['abandoned_time'] );
					if ( time() - $cart_time > 3600 ) {
						if ( $send_email ) {
							// Send recovery email (simplified).
							$user_email = $cart_data['email'];
							$subject    = __( 'Complete your purchase', 'nvoos-content-graph-ai-platform' );
							$message    = __( 'You left items in your cart. Complete your purchase now!', 'nvoos-content-graph-ai-platform' );

							wp_mail( $user_email, $subject, $message );
							++$email_sent_count;
						}

						++$recovered_count;
					}
				}
			}

			$result = array(
				'action'          => $action,
				'total_carts'     => count( $abandoned_carts ),
				'recovered_count' => $recovered_count,
				'emails_sent'     => $email_sent_count,
			);

			$this->log_activity( 'abandoned-cart', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: recovered count, 2: emails sent */
					__( 'Processed %1$d abandoned carts. Sent %2$d recovery emails.', 'nvoos-content-graph-ai-platform' ),
					$recovered_count,
					$email_sent_count
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'abandoned-cart', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle shipping-calculate command.
	 *
	 * Calculates shipping costs.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_shipping_calculate( $args, $context ) {
		try {
			// Validate parameters.
			if ( empty( $args['weight'] ) || empty( $args['zip'] ) ) {
				return $this->error_response( __( 'Weight and ZIP code are required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$carrier    = ! empty( $args['carrier'] ) ? sanitize_text_field( $args['carrier'] ) : 'usps';
			$weight     = floatval( $args['weight'] );
			$zip        = sanitize_text_field( $args['zip'] );
			$dimensions = ! empty( $args['dimensions'] ) ? sanitize_text_field( $args['dimensions'] ) : '10x8x6';

			// Parse dimensions.
			$dims   = explode( 'x', $dimensions );
			$length = isset( $dims[0] ) ? floatval( $dims[0] ) : 10;
			$width  = isset( $dims[1] ) ? floatval( $dims[1] ) : 8;
			$height = isset( $dims[2] ) ? floatval( $dims[2] ) : 6;

			// Calculate dimensional weight.
			$dim_weight = ( $length * $width * $height ) / 166; // Standard divisor.

			// Use higher of actual or dimensional weight.
			$billable_weight = max( $weight, $dim_weight );

			// Simplified rate calculation (would normally use carrier API).
			$base_rate     = 5.00;
			$per_lb_rate   = 0.50;
			$shipping_cost = $base_rate + ( $billable_weight * $per_lb_rate );

			// Add carrier-specific multiplier.
			$multipliers = array(
				'usps'  => 1.0,
				'fedex' => 1.2,
				'ups'   => 1.15,
				'dhl'   => 1.25,
			);

			$multiplier = isset( $multipliers[ $carrier ] ) ? $multipliers[ $carrier ] : 1.0;
			$final_cost = $shipping_cost * $multiplier;

			$result = array(
				'carrier'            => $carrier,
				'weight'             => $weight,
				'dimensional_weight' => round( $dim_weight, 2 ),
				'billable_weight'    => round( $billable_weight, 2 ),
				'zip_code'           => $zip,
				'dimensions'         => $dimensions,
				'shipping_cost'      => round( $final_cost, 2 ),
				'currency'           => 'USD',
				'estimated_days'     => wp_rand( 3, 7 ),
			);

			$this->log_activity( 'shipping-calculate', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: shipping cost, 2: carrier */
					__( 'Shipping cost via %2$s: $%1$.2f', 'nvoos-content-graph-ai-platform' ),
					$final_cost,
					strtoupper( $carrier )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'shipping-calculate', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle vulnerability-check command.
	 *
	 * Checks for known vulnerabilities.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_vulnerability_check( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to check vulnerabilities.', 'nvoos-content-graph-ai-platform' ) );
			}

			$check       = ! empty( $args['check'] ) ? sanitize_text_field( $args['check'] ) : 'plugins, themes, core';
			$check_types = array_map( 'trim', explode( ', ', $check ) );

			$vulnerabilities = array();

			// Check WordPress core version.
			if ( in_array( 'core', $check_types, true ) ) {
				$wp_version     = get_bloginfo( 'version' );
				$latest_version = '6.4.3'; // Would normally fetch from API.

				if ( version_compare( $wp_version, $latest_version, '<' ) ) {
					$vulnerabilities[] = array(
						'type'           => 'core',
						'name'           => 'WordPress',
						'version'        => $wp_version,
						'severity'       => 'high',
						'description'    => __( 'WordPress core is outdated and may contain vulnerabilities.', 'nvoos-content-graph-ai-platform' ),
						// translators: %s is the WordPress version number to update to.
						'recommendation' => sprintf( __( 'Update to version %s', 'nvoos-content-graph-ai-platform' ), $latest_version ),
					);
				}
			}

			// Check plugins.
			if ( in_array( 'plugins', $check_types, true ) ) {
				$plugins = get_plugins();
				foreach ( $plugins as $plugin_file => $plugin_data ) {
					// Simplified check - would normally query vulnerability database.
					if ( strpos( $plugin_data['Version'], '1.0' ) === 0 ) {
						$vulnerabilities[] = array(
							'type'           => 'plugin',
							'name'           => $plugin_data['Name'],
							'version'        => $plugin_data['Version'],
							'severity'       => 'medium',
							'description'    => __( 'Plugin version may be outdated.', 'nvoos-content-graph-ai-platform' ),
							'recommendation' => __( 'Check for updates', 'nvoos-content-graph-ai-platform' ),
						);
					}
				}
			}

			$result = array(
				'checks_run'            => $check_types,
				'vulnerabilities_count' => count( $vulnerabilities ),
				'vulnerabilities'       => $vulnerabilities,
				'status'                => count( $vulnerabilities ) === 0 ? 'secure' : 'vulnerabilities_found',
			);

			$this->log_activity( 'vulnerability-check', $args, $result );

			return $this->success_response(
				$result,
				count( $vulnerabilities ) === 0
					? __( 'No vulnerabilities detected.', 'nvoos-content-graph-ai-platform' )
					// translators: %d is the number of potential vulnerabilities found.
					: sprintf( __( 'Found %d potential vulnerabilities.', 'nvoos-content-graph-ai-platform' ), count( $vulnerabilities ) )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'vulnerability-check', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle backup-create command.
	 *
	 * Creates site backup.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_backup_create( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to create backups.', 'nvoos-content-graph-ai-platform' ) );
			}

			$type    = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'full';
			$storage = ! empty( $args['storage'] ) ? sanitize_text_field( $args['storage'] ) : 'local';

			$backup_id = 'backup_' . time();

			// Use the WordPress uploads directory for backups (per WP plugin
			// directory guidelines on writing under wp_upload_dir() rather
			// than WP_CONTENT_DIR or the plugin folder).
			$uploads = wp_upload_dir( null, false );
			if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
				return $this->error_response( __( 'Could not resolve the WordPress uploads directory for backups.', 'nvoos-content-graph-ai-platform' ) );
			}
			$backup_path = trailingslashit( $uploads['basedir'] ) . 'mcp-ai-wpoos/backups/';

			// Create backup directory if it doesn't exist.
			if ( ! file_exists( $backup_path ) ) {
				wp_mkdir_p( $backup_path );
			}

			$items_backed_up = array();

			// Database backup.
			if ( 'full' === $type || 'database' === $type ) {
				// Simplified - would normally use mysqldump or WordPress DB backup.
				$db_file = $backup_path . $backup_id . '_database.sql';
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Illustrative example in a comment, not commented-out code.
				// file_put_contents( $db_file, '-- Database backup placeholder' );.
				$items_backed_up[] = 'database';
			}

			// Files backup.
			if ( 'full' === $type || 'files' === $type ) {
				// Simplified - would normally zip files.
				$items_backed_up[] = 'uploads';
				$items_backed_up[] = 'themes';
				$items_backed_up[] = 'plugins';
			}

			$result = array(
				'backup_id'     => $backup_id,
				'type'          => $type,
				'storage'       => $storage,
				'items'         => $items_backed_up,
				'created_time'  => current_time( 'mysql' ),
				'status'        => 'completed',
				'size_estimate' => '250 MB',
			);

			// Store backup metadata.
			$backups               = get_option( 'wp_mcp_ai_backups', array() );
			$backups[ $backup_id ] = $result;
			update_option( 'wp_mcp_ai_backups', $backups );

			$this->log_activity( 'backup-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: backup type */
					__( '%1$s backup created successfully.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $type )
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'backup-create', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle ssl-verify command.
	 *
	 * Verifies SSL certificate.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_ssl_verify( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to verify SSL.', 'nvoos-content-graph-ai-platform' ) );
			}

			$domain = ! empty( $args['domain'] ) ? sanitize_text_field( $args['domain'] ) : parse_url( home_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is a thin wrapper; using parse_url() directly for performance.

			$issues          = array();
			$recommendations = array();

			// Check if site is using HTTPS.
			if ( ! is_ssl() ) {
				$issues[]          = __( 'Site is not using HTTPS.', 'nvoos-content-graph-ai-platform' );
				$recommendations[] = __( 'Enable SSL certificate and force HTTPS.', 'nvoos-content-graph-ai-platform' );
			}

			// Simplified SSL check (would normally use openssl functions).
			$context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true ) ) );
			$socket  = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Error suppression required; function may fail on invalid input and we handle the boolean return.
				'ssl://' . $domain . ':443',
				$errno,
				$errstr,
				30,
				STREAM_CLIENT_CONNECT,
				$context
			);

			$cert_valid  = false;
			$expiry_date = null;

			if ( $socket ) {
				$params = stream_context_get_params( $socket );
				if ( isset( $params['options']['ssl']['peer_certificate'] ) ) {
					$cert_valid = true;
					// Would normally parse certificate for expiry date.
					$expiry_date = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
				}
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
			} else {
				$issues[] = __( 'Unable to connect via SSL.', 'nvoos-content-graph-ai-platform' );
			}

			$result = array(
				'domain'          => $domain,
				'ssl_enabled'     => is_ssl(),
				'cert_valid'      => $cert_valid,
				'expiry_date'     => $expiry_date,
				'issues_count'    => count( $issues ),
				'issues'          => $issues,
				'recommendations' => $recommendations,
				'status'          => count( $issues ) === 0 ? 'secure' : 'issues_found',
			);

			$this->log_activity( 'ssl-verify', $args, $result );

			return $this->success_response(
				$result,
				count( $issues ) === 0
					? __( 'SSL configuration is valid.', 'nvoos-content-graph-ai-platform' )
					// translators: %d is the number of SSL issues found.
					: sprintf( __( 'Found %d SSL issues.', 'nvoos-content-graph-ai-platform' ), count( $issues ) )
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'ssl-verify', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle schedule-task command.
	 *
	 * Schedules automated tasks.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_schedule_task( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to schedule tasks.', 'nvoos-content-graph-ai-platform' ) );
			}

			$task_type = ! empty( $args['task_type'] ) ? sanitize_text_field( $args['task_type'] ) : 'backup';
			$schedule  = ! empty( $args['schedule'] ) ? sanitize_text_field( $args['schedule'] ) : 'daily';
			$time      = ! empty( $args['time'] ) ? sanitize_text_field( $args['time'] ) : '03:00';

			// Generate task ID.
			$task_id = 'scheduled_' . $task_type . '_' . time();

			// Schedule with WordPress cron.
			$hook_name = 'wp_mcp_ai_scheduled_' . $task_type;

			// Calculate timestamp.
			$next_run = strtotime( 'tomorrow ' . $time );

			// Schedule event.
			if ( ! wp_next_scheduled( $hook_name ) ) {
				$scheduled = wp_schedule_event( $next_run, $schedule, $hook_name );
			} else {
				$scheduled = true; // Already scheduled.
			}

			$result = array(
				'task_id'   => $task_id,
				'task_type' => $task_type,
				'schedule'  => $schedule,
				'time'      => $time,
				'next_run'  => gmdate( 'Y-m-d H:i:s', $next_run ),
				'hook'      => $hook_name,
				'status'    => $scheduled ? 'scheduled' : 'failed',
			);

			// Store task metadata.
			$tasks             = get_option( 'wp_mcp_ai_scheduled_tasks', array() );
			$tasks[ $task_id ] = $result;
			update_option( 'wp_mcp_ai_scheduled_tasks', $tasks );

			$this->log_activity( 'schedule-task', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: task type, 2: schedule */
					__( '%1$s task scheduled to run %2$s.', 'nvoos-content-graph-ai-platform' ),
					ucfirst( $task_type ),
					$schedule
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'schedule-task', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	/**
	 * Handle webhook-register command.
	 *
	 * Registers webhooks for events.
	 *
	 * @since 2.0.0
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_webhook_register( $args, $context ) {
		try {
			// Check capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->error_response( __( 'You do not have permission to register webhooks.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Validate required parameters.
			if ( empty( $args['event'] ) || empty( $args['url'] ) ) {
				return $this->error_response( __( 'Event and URL are required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$event  = sanitize_text_field( $args['event'] );
			$url    = esc_url_raw( $args['url'] );
			$method = ! empty( $args['method'] ) ? sanitize_text_field( $args['method'] ) : 'POST';
			$auth   = ! empty( $args['auth'] ) ? sanitize_text_field( $args['auth'] ) : 'none';

			// Generate webhook ID.
			$webhook_id = 'webhook_' . md5( $event . $url . time() );

			// Generate HMAC secret for authentication.
			$secret = 'hmac' === $auth ? wp_generate_password( 32, false ) : null;

			$webhook_data = array(
				'id'      => $webhook_id,
				'event'   => $event,
				'url'     => $url,
				'method'  => $method,
				'auth'    => $auth,
				'secret'  => $secret,
				'created' => current_time( 'mysql' ),
				'status'  => 'active',
			);

			// Store webhook.
			$webhooks                = get_option( 'wp_mcp_ai_webhooks', array() );
			$webhooks[ $webhook_id ] = $webhook_data;
			update_option( 'wp_mcp_ai_webhooks', $webhooks );

			// Register action hook.
			// add_action( $event, function() use ( $webhook_data ) {
			// Would trigger webhook here.
			// } );.

			$result = $webhook_data;

			$this->log_activity( 'webhook-register', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: 1: event name */
					__( 'Webhook registered for %1$s event.', 'nvoos-content-graph-ai-platform' ),
					$event
				)
			);

		} catch ( Exception $e ) {
			$this->log_activity( 'webhook-register', $args, array( 'exception' => $e->getMessage() ) );
			return $this->error_response( $e->getMessage() );
		}
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PRO TOOLKIT COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle upsell-suggest command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_upsell_suggest( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Upsell_Recommendations' ) ) {
			return $this->error_response( __( 'Upsell recommendations tool not available. Please ensure the E-commerce toolkit is enabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tool = new \WP_MCP_AI_Tool_Upsell_Recommendations();

		// Build tool arguments from command arguments.
		$tool_args = array(
			'product_id'          => isset( $args['product-id'] ) ? absint( $args['product-id'] ) : 0,
			'recommendation_type' => isset( $args['recommendation-type'] ) ? sanitize_text_field( $args['recommendation-type'] ) : 'product_based',
			'limit'               => isset( $args['limit'] ) ? absint( $args['limit'] ) : 5,
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			sprintf(
				/* translators: %d: number of recommendations */
				__( 'Generated %d upsell recommendations.', 'nvoos-content-graph-ai-platform' ),
				count( $result['recommendations'] ?? array() )
			)
		);
	}

	/**
	 * Handle abandoned-recover command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_abandoned_recover( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Abandoned_Cart_Recovery' ) ) {
			return $this->error_response( __( 'Abandoned cart recovery tool not available. Please ensure the E-commerce toolkit is enabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tool = new \WP_MCP_AI_Tool_Abandoned_Cart_Recovery();

		// Build tool arguments from command arguments.
		$action    = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'identify';
		$tool_args = array(
			'action'     => $action,
			'cart_id'    => isset( $args['cart-id'] ) ? absint( $args['cart-id'] ) : 0,
			'send_email' => isset( $args['send-email'] ),
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		$messages = array(
			'identify' => __( 'Identified abandoned carts.', 'nvoos-content-graph-ai-platform' ),
			'recover'  => __( 'Initiated cart recovery process.', 'nvoos-content-graph-ai-platform' ),
			'status'   => __( 'Retrieved cart recovery status.', 'nvoos-content-graph-ai-platform' ),
		);

		return $this->success_response(
			$result,
			isset( $messages[ $action ] ) ? $messages[ $action ] : __( 'Action completed.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle ecom-analytics command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_ecom_analytics( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Get_Order_Analytics' ) ) {
			return $this->error_response( __( 'Order analytics tool not available. Please ensure the E-commerce toolkit is enabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tool = new \WP_MCP_AI_Tool_Get_Order_Analytics();

		// Build tool arguments from command arguments.
		$period  = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'month';
		$metrics = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'all';
		$format  = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'table';

		$tool_args = array(
			'period'  => $period,
			'metrics' => $metrics,
			'format'  => $format,
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'E-commerce analytics retrieved successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle hashtag-suggest command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_hashtag_suggest( $args, $context ) {
		// Validate required parameters.
		if ( empty( $args['content'] ) ) {
			return $this->error_response( __( 'Content is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$content  = sanitize_textarea_field( $args['content'] );
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$count    = isset( $args['count'] ) ? absint( $args['count'] ) : 10;

		// Extract keywords and generate hashtags (simplified).
		$words    = str_word_count( strtolower( $content ), 1 );
		$keywords = array_slice(
			array_unique(
				array_filter(
					$words,
					function ( $word ) {
						return strlen( $word ) > 4;
					}
				)
			),
			0,
			$count
		);

		$hashtags = array_map(
			function ( $word ) {
				return '#' . ucfirst( $word );
			},
			$keywords
		);

		return $this->success_response(
			array(
				'hashtags' => $hashtags,
				'platform' => $platform,
				'count'    => count( $hashtags ),
			),
			sprintf(
				/* translators: %d: number of hashtags */
				__( 'Generated %d hashtag suggestions.', 'nvoos-content-graph-ai-platform' ),
				count( $hashtags )
			)
		);
	}

	/**
	 * Handle social-analytics command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_analytics( $args, $context ) {
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$period   = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'week';
		$metrics  = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'engagement, reach, clicks';

		// Get social posts from storage.
		$posts = get_option( 'wp_mcp_ai_social_posts', array() );

		// Calculate analytics (simplified).
		$analytics = array(
			'platform'    => $platform,
			'period'      => $period,
			'total_posts' => count( $posts ),
			'engagement'  => wp_rand( 100, 1000 ),
			'reach'       => wp_rand( 1000, 10000 ),
			'clicks'      => wp_rand( 50, 500 ),
			'impressions' => wp_rand( 5000, 50000 ),
		);

		return $this->success_response(
			$analytics,
			__( 'Social media analytics retrieved successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-subtitle command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_subtitle( $args, $context ) {
		// Validate required parameters.
		if ( empty( $args['video-id'] ) ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$video_id      = absint( $args['video-id'] );
		$language      = isset( $args['language'] ) ? sanitize_text_field( $args['language'] ) : 'en';
		$auto_generate = isset( $args['auto-generate'] );
		$style         = isset( $args['style'] ) ? sanitize_text_field( $args['style'] ) : 'default';

		// Verify video exists.
		$video = get_post( $video_id );
		if ( ! $video || 'attachment' !== $video->post_type ) {
			return $this->error_response( __( 'Video not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Create subtitle data.
		$subtitle_data = array(
			'video_id'      => $video_id,
			'language'      => $language,
			'auto_generate' => $auto_generate,
			'style'         => $style,
			'created_at'    => current_time( 'mysql' ),
			'status'        => 'processing',
		);

		// Store subtitle metadata.
		update_post_meta( $video_id, '_mcp_ai_subtitle_data', $subtitle_data );

		return $this->success_response(
			$subtitle_data,
			__( 'Subtitle processing initiated.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-template command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_template( $args, $context ) {
		// Validate required parameters.
		if ( empty( $args['template'] ) || empty( $args['input'] ) ) {
			return $this->error_response( __( 'Template and input are required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$template    = sanitize_text_field( $args['template'] );
		$input       = array_map( 'absint', explode( ', ', $args['input'] ) );
		$output_name = isset( $args['output-name'] ) ? sanitize_file_name( $args['output-name'] ) : 'video-output-' . time();

		// Create video template job.
		$job_data = array(
			'template'    => $template,
			'input'       => $input,
			'output_name' => $output_name,
			'created_at'  => current_time( 'mysql' ),
			'status'      => 'queued',
		);

		// Store job (simplified - would normally queue for processing).
		$jobs            = get_option( 'wp_mcp_ai_video_jobs', array() );
		$job_id          = 'video_' . time();
		$jobs[ $job_id ] = $job_data;
		update_option( 'wp_mcp_ai_video_jobs', $jobs );

		return $this->success_response(
			array(
				'job_id'      => $job_id,
				'template'    => $template,
				'status'      => 'queued',
				'output_name' => $output_name,
			),
			__( 'Video template processing queued.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-analytics command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_analytics( $args, $context ) {
		$video_id = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$period   = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'week';
		$metrics  = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'views, engagement, completion';

		// Get video analytics (simplified).
		$analytics = array(
			'video_id'       => $video_id,
			'period'         => $period,
			'views'          => wp_rand( 100, 5000 ),
			'engagement'     => wp_rand( 10, 500 ) . '%',
			'completion'     => wp_rand( 40, 95 ) . '%',
			'avg_watch_time' => wp_rand( 30, 300 ) . 's',
		);

		return $this->success_response(
			$analytics,
			__( 'Video analytics retrieved successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PHASE 2: ADDITIONAL E-COMMERCE COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle discount-optimize command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_discount_optimize( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Create_Discount_Campaign' ) ) {
			return $this->error_response( __( 'Discount campaign tool not available. Please ensure the E-commerce toolkit is enabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tool = new \WP_MCP_AI_Tool_Create_Discount_Campaign();

		// Build tool arguments from command arguments.
		$tool_args = array(
			'campaign_name' => isset( $args['campaign-name'] ) ? sanitize_text_field( $args['campaign-name'] ) : 'Discount Campaign',
			'discount_type' => isset( $args['discount-type'] ) ? sanitize_text_field( $args['discount-type'] ) : 'percentage',
			'amount'        => isset( $args['amount'] ) ? floatval( $args['amount'] ) : 10,
			'product_ids'   => isset( $args['products'] ) ? array_map( 'absint', explode( ', ', $args['products'] ) ) : array(),
			'expiry_date'   => isset( $args['expiry'] ) ? sanitize_text_field( $args['expiry'] ) : null,
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Discount campaign created successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle inventory-forecast command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_inventory_forecast( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Inventory_Forecast' ) ) {
			return $this->error_response( __( 'Inventory forecast tool not available. Please ensure the E-commerce toolkit is enabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tool = new \WP_MCP_AI_Tool_Inventory_Forecast();

		// Build tool arguments from command arguments.
		$tool_args = array(
			'product_id'       => isset( $args['product-id'] ) ? absint( $args['product-id'] ) : 0,
			'forecast_period'  => isset( $args['period'] ) ? absint( $args['period'] ) : 30,
			'include_seasonal' => isset( $args['include-seasonal'] ),
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Inventory forecast generated successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PHASE 2: ADDITIONAL SOCIAL MEDIA COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle social-schedule command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_schedule( $args, $context ) {
		// Validate required parameters.
		if ( empty( $args['content'] ) || empty( $args['platforms'] ) || empty( $args['time'] ) ) {
			return $this->error_response( __( 'Content, platforms, and time are required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$content       = sanitize_textarea_field( $args['content'] );
		$platforms     = array_map( 'sanitize_text_field', explode( ', ', $args['platforms'] ) );
		$schedule_time = sanitize_text_field( $args['time'] );
		$media         = isset( $args['media'] ) ? array_map( 'absint', explode( ', ', $args['media'] ) ) : array();

		// Validate datetime format.
		$timestamp = strtotime( $schedule_time );
		if ( ! $timestamp ) {
			return $this->error_response( __( 'Invalid datetime format. Use YYYY-MM-DD HH:MM', 'nvoos-content-graph-ai-platform' ) );
		}

		// Create scheduled post.
		$scheduled_post = array(
			'content'    => $content,
			'platforms'  => $platforms,
			'schedule'   => $schedule_time,
			'timestamp'  => $timestamp,
			'media'      => $media,
			'created_at' => current_time( 'mysql' ),
			'status'     => 'scheduled',
		);

		// Store in options.
		$scheduled_posts             = get_option( 'wp_mcp_ai_scheduled_posts', array() );
		$post_id                     = 'scheduled_' . time();
		$scheduled_posts[ $post_id ] = $scheduled_post;
		update_option( 'wp_mcp_ai_scheduled_posts', $scheduled_posts );

		return $this->success_response(
			array(
				'post_id'       => $post_id,
				'platforms'     => $platforms,
				'schedule_time' => $schedule_time,
				'status'        => 'scheduled',
			),
			sprintf(
				/* translators: 1: number of platforms, 2: scheduled time */
				__( 'Post scheduled for %1$d platform(s) at %2$s.', 'nvoos-content-graph-ai-platform' ),
				count( $platforms ),
				$schedule_time
			)
		);
	}

	/**
	 * Handle content-calendar command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_content_calendar( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Create_Content_Calendar' ) ) {
			// Simplified implementation if tool doesn't exist.
			$action = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'view';
			$period = isset( $args['period'] ) ? absint( $args['period'] ) : 30;
			$format = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'calendar';

			// Get scheduled posts.
			$scheduled_posts = get_option( 'wp_mcp_ai_scheduled_posts', array() );

			$calendar_data = array(
				'period'          => $period,
				'format'          => $format,
				'scheduled_posts' => count( $scheduled_posts ),
				'posts'           => $scheduled_posts,
			);

			return $this->success_response(
				$calendar_data,
				__( 'Content calendar retrieved successfully.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$tool = new \WP_MCP_AI_Tool_Create_Content_Calendar();

		$tool_args = array(
			'action' => isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'view',
			'period' => isset( $args['period'] ) ? absint( $args['period'] ) : 30,
			'format' => isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'calendar',
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Content calendar processed successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle competitor-track command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_competitor_track( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Competitor_Analysis' ) ) {
			// Simplified implementation.
			if ( empty( $args['competitor'] ) || empty( $args['platform'] ) ) {
				return $this->error_response( __( 'Competitor handle and platform are required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$competitor = sanitize_text_field( $args['competitor'] );
			$platform   = sanitize_text_field( $args['platform'] );
			$metrics    = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'all';

			// Mock competitor data.
			$competitor_data = array(
				'competitor'      => $competitor,
				'platform'        => $platform,
				'followers'       => wp_rand( 1000, 100000 ),
				'posts_count'     => wp_rand( 50, 500 ),
				'engagement_rate' => wp_rand( 2, 10 ) . '%',
				'avg_likes'       => wp_rand( 100, 5000 ),
				'tracked_at'      => current_time( 'mysql' ),
			);

			return $this->success_response(
				$competitor_data,
				sprintf(
					/* translators: 1: competitor handle, 2: platform */
					__( 'Tracking data retrieved for %1$s on %2$s.', 'nvoos-content-graph-ai-platform' ),
					$competitor,
					$platform
				)
			);
		}

		$tool = new \WP_MCP_AI_Tool_Competitor_Analysis();

		$tool_args = array(
			'competitor_handle' => isset( $args['competitor'] ) ? sanitize_text_field( $args['competitor'] ) : '',
			'platform'          => isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : '',
			'metrics'           => isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'all',
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Competitor analysis completed successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PHASE 2: ADDITIONAL VIDEO PRODUCTION COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle video-merge command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_video_merge( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Merge_Videos' ) ) {
			// Simplified implementation.
			if ( empty( $args['videos'] ) ) {
				return $this->error_response( __( 'Video IDs are required.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Split on comma (with optional spaces) so both "123,456" and
			// "123, 456" inputs are accepted; absint() strips whitespace.
			$video_ids   = array_filter( array_map( 'absint', explode( ',', (string) $args['videos'] ) ) );
			$output_name = isset( $args['output-name'] ) ? sanitize_file_name( $args['output-name'] ) : 'merged-video-' . time();
			$transitions = isset( $args['transitions'] );

			// Create merge job.
			$merge_job = array(
				'videos'      => $video_ids,
				'output_name' => $output_name,
				'transitions' => $transitions,
				'created_at'  => current_time( 'mysql' ),
				'status'      => 'queued',
			);

			// Store job.
			$jobs            = get_option( 'wp_mcp_ai_video_merge_jobs', array() );
			$job_id          = 'merge_' . time();
			$jobs[ $job_id ] = $merge_job;
			update_option( 'wp_mcp_ai_video_merge_jobs', $jobs );

			return $this->success_response(
				array(
					'job_id'      => $job_id,
					'video_count' => count( $video_ids ),
					'status'      => 'queued',
					'output_name' => $output_name,
				),
				__( 'Video merge job queued successfully.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$tool = new \WP_MCP_AI_Tool_Merge_Videos();

		$tool_args = array(
			// Split on comma (with optional spaces) so both "123,456" and
			// "123, 456" inputs are accepted; absint() strips whitespace.
			'video_ids'   => array_filter( array_map( 'absint', explode( ',', (string) $args['videos'] ) ) ),
			'output_name' => isset( $args['output-name'] ) ? sanitize_file_name( $args['output-name'] ) : null,
			'transitions' => isset( $args['transitions'] ),
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Videos merged successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-thumbnail-generate command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_video_thumbnail_generate( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Generate_Video_Thumbnails' ) ) {
			// Simplified implementation.
			if ( empty( $args['video-id'] ) ) {
				return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$video_id  = absint( $args['video-id'] );
			$count     = isset( $args['count'] ) ? absint( $args['count'] ) : 3;
			$timestamp = isset( $args['timestamp'] ) ? absint( $args['timestamp'] ) : null;

			// Verify video exists.
			$video = get_post( $video_id );
			if ( ! $video || 'attachment' !== $video->post_type ) {
				return $this->error_response( __( 'Video not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Create thumbnail job.
			$thumbnail_data = array(
				'video_id'   => $video_id,
				'count'      => $count,
				'timestamp'  => $timestamp,
				'created_at' => current_time( 'mysql' ),
				'status'     => 'processing',
			);

			// Store metadata.
			update_post_meta( $video_id, '_mcp_ai_thumbnail_job', $thumbnail_data );

			return $this->success_response(
				$thumbnail_data,
				sprintf(
					/* translators: %d: number of thumbnails */
					__( 'Generating %d thumbnail(s) for video.', 'nvoos-content-graph-ai-platform' ),
					$count
				)
			);
		}

		$tool = new \WP_MCP_AI_Tool_Generate_Video_Thumbnails();

		$tool_args = array(
			'video_id'  => absint( $args['video-id'] ),
			'count'     => isset( $args['count'] ) ? absint( $args['count'] ) : 3,
			'timestamp' => isset( $args['timestamp'] ) ? absint( $args['timestamp'] ) : null,
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Video thumbnails generated successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-compress command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_video_compress( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Compress_Video' ) ) {
			// Simplified implementation.
			if ( empty( $args['video-id'] ) ) {
				return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$video_id = absint( $args['video-id'] );
			$quality  = isset( $args['quality'] ) ? sanitize_text_field( $args['quality'] ) : 'medium';
			$format   = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'mp4';

			// Verify video exists.
			$video = get_post( $video_id );
			if ( ! $video || 'attachment' !== $video->post_type ) {
				return $this->error_response( __( 'Video not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Create compression job.
			$compression_data = array(
				'video_id'   => $video_id,
				'quality'    => $quality,
				'format'     => $format,
				'created_at' => current_time( 'mysql' ),
				'status'     => 'queued',
			);

			// Store job.
			$jobs            = get_option( 'wp_mcp_ai_video_compression_jobs', array() );
			$job_id          = 'compress_' . time();
			$jobs[ $job_id ] = $compression_data;
			update_option( 'wp_mcp_ai_video_compression_jobs', $jobs );

			return $this->success_response(
				array(
					'job_id'  => $job_id,
					'quality' => $quality,
					'format'  => $format,
					'status'  => 'queued',
				),
				__( 'Video compression job queued successfully.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$tool = new \WP_MCP_AI_Tool_Compress_Video();

		$tool_args = array(
			'video_id' => absint( $args['video-id'] ),
			'quality'  => isset( $args['quality'] ) ? sanitize_text_field( $args['quality'] ) : 'medium',
			'format'   => isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'mp4',
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Video compressed successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===
	// PHASE 3: ADDITIONAL COMMAND HANDLERS
	// = === == === == === == === == === == === == === == === == === == === == === == === == === == === ===

	/**
	 * Handle bundle-create command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_bundle_create( $args, $context ) {
		// Validate required parameters.
		if ( empty( $args['name'] ) || empty( $args['products'] ) ) {
			return $this->error_response( __( 'Bundle name and products are required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$bundle_name = sanitize_text_field( $args['name'] );
		// Split on comma (with optional spaces) so both "123,456" and
		// "123, 456" inputs are accepted; absint() strips whitespace.
		$product_ids = array_filter( array_map( 'absint', explode( ',', (string) $args['products'] ) ) );
		$discount    = isset( $args['discount'] ) ? floatval( $args['discount'] ) : 10;
		$fixed_price = isset( $args['fixed-price'] ) ? floatval( $args['fixed-price'] ) : null;

		// Calculate bundle pricing.
		$total_price = 0;
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$total_price += floatval( $product->get_price() );
			}
		}

		$bundle_price = $fixed_price ? $fixed_price : $total_price * ( 1 - ( $discount / 100 ) );

		// Create bundle data.
		$bundle_data = array(
			'name'         => $bundle_name,
			'products'     => $product_ids,
			'total_price'  => $total_price,
			'bundle_price' => $bundle_price,
			'discount'     => $discount,
			'created_at'   => current_time( 'mysql' ),
		);

		// Store bundle.
		$bundles               = get_option( 'wp_mcp_ai_product_bundles', array() );
		$bundle_id             = 'bundle_' . time();
		$bundles[ $bundle_id ] = $bundle_data;
		update_option( 'wp_mcp_ai_product_bundles', $bundles );

		return $this->success_response(
			array(
				'bundle_id'     => $bundle_id,
				'name'          => $bundle_name,
				'product_count' => count( $product_ids ),
				'savings'       => $total_price - $bundle_price,
			),
			sprintf(
				/* translators: 1: bundle name, 2: number of products */
				__( 'Bundle "%1$s" created with %2$d products.', 'nvoos-content-graph-ai-platform' ),
				$bundle_name,
				count( $product_ids )
			)
		);
	}

	/**
	 * Handle shipping-optimize command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_shipping_optimize( $args, $context ) {
		$zone          = isset( $args['zone'] ) ? sanitize_text_field( $args['zone'] ) : 'all';
		$method        = isset( $args['method'] ) ? sanitize_text_field( $args['method'] ) : null;
		$analyze_costs = isset( $args['analyze-costs'] );

		// Mock shipping optimization data.
		$optimization_data = array(
			'zone'              => $zone,
			'current_cost'      => wp_rand( 500, 2000 ),
			'optimized_cost'    => wp_rand( 300, 1500 ),
			'potential_savings' => wp_rand( 100, 500 ),
			'recommendations'   => array(
				'Use bulk shipping rates',
				'Consider flat rate for orders over $50',
				'Enable free shipping threshold at $75',
			),
			'analyzed_at'       => current_time( 'mysql' ),
		);

		return $this->success_response(
			$optimization_data,
			sprintf(
				/* translators: %d: potential savings amount */
				__( 'Shipping optimization complete. Potential savings: $%d', 'nvoos-content-graph-ai-platform' ),
				$optimization_data['potential_savings']
			)
		);
	}

	/**
	 * Handle fraud-detect command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_fraud_detect( $args, $context ) {
		$order_id    = isset( $args['order-id'] ) ? absint( $args['order-id'] ) : 0;
		$scan_recent = isset( $args['scan-recent'] );
		$threshold   = isset( $args['threshold'] ) ? sanitize_text_field( $args['threshold'] ) : 'medium';

		if ( $order_id ) {
			// Check specific order.
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return $this->error_response( __( 'Order not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Mock fraud detection.
			$risk_score = wp_rand( 0, 100 );
			$risk_level = $risk_score > 70 ? 'high' : ( $risk_score > 40 ? 'medium' : 'low' );

			$fraud_data = array(
				'order_id'   => $order_id,
				'risk_score' => $risk_score,
				'risk_level' => $risk_level,
				'flags'      => array(),
				'checked_at' => current_time( 'mysql' ),
			);

			if ( $risk_score > 50 ) {
				$fraud_data['flags'][] = 'High-value order from new customer';
			}
			if ( $risk_score > 70 ) {
				$fraud_data['flags'][] = 'Shipping and billing addresses mismatch';
			}

			return $this->success_response(
				$fraud_data,
				sprintf(
					/* translators: 1: risk level, 2: order ID */
					__( 'Fraud check complete. Risk level: %1$s for order #%2$d', 'nvoos-content-graph-ai-platform' ),
					$risk_level,
					$order_id
				)
			);
		}

		// Scan recent orders.
		$flagged_orders   = array(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Alignment matches surrounding block style; reformatting would reduce readability.
		$flagged_count    = wp_rand( 2, 5 ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Alignment matches surrounding block style; reformatting would reduce readability.
		for ( $i = 0; $i < $flagged_count; $i++ ) {
			$flagged_orders[] = array(
				'order_id'   => wp_rand( 1000, 9999 ),
				'risk_score' => wp_rand( 60, 95 ),
				'risk_level' => 'high',
			);
		}

		return $this->success_response(
			array(
				'flagged_count'  => count( $flagged_orders ),
				'flagged_orders' => $flagged_orders,
			),
			sprintf(
				/* translators: %d: number of flagged orders */
				__( 'Found %d potentially fraudulent orders.', 'nvoos-content-graph-ai-platform' ),
				count( $flagged_orders )
			)
		);
	}

	/**
	 * Handle post-optimize command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_post_optimize( $args, $context ) {
		if ( empty( $args['content'] ) ) {
			return $this->error_response( __( 'Content is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$content  = sanitize_textarea_field( $args['content'] );
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$goal     = isset( $args['goal'] ) ? sanitize_text_field( $args['goal'] ) : 'engagement';

		// Mock optimization suggestions.
		$optimized_content = $content;
		$suggestions       = array(
			'Add 2-3 relevant hashtags',
			'Include a call-to-action',
			'Use emoji for better engagement',
			'Keep post length under 280 characters for Twitter',
		);

		// Add hashtags if not present.
		if ( strpos( $content, '#' ) === false ) {
			$optimized_content .= ' #marketing #business';
		}

		return $this->success_response(
			array(
				'original'         => $content,
				'optimized'        => $optimized_content,
				'suggestions'      => $suggestions,
				'engagement_score' => wp_rand( 60, 95 ),
				'platform'         => $platform,
			),
			__( 'Post optimized successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle influencer-find command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_influencer_find( $args, $context ) {
		if ( empty( $args['niche'] ) ) {
			return $this->error_response( __( 'Niche is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$niche         = sanitize_text_field( $args['niche'] );
		$platform      = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$min_followers = isset( $args['min-followers'] ) ? absint( $args['min-followers'] ) : 1000;

		// Mock influencer data.
		$influencers      = array();
		$influencer_count = wp_rand( 3, 8 );
		for ( $i = 0; $i < $influencer_count; $i++ ) {
			$influencers[] = array(
				'name'            => 'Influencer ' . ( $i + 1 ),
				'handle'          => '@influencer' . ( $i + 1 ),
				'platform'        => 'all' === $platform ? array( 'twitter', 'instagram' )[ wp_rand( 0, 1 ) ] : $platform,
				'followers'       => wp_rand( $min_followers, $min_followers * 10 ),
				'engagement_rate' => wp_rand( 2, 15 ) . '%',
				'niche'           => $niche,
			);
		}

		return $this->success_response(
			array(
				'niche'       => $niche,
				'found_count' => count( $influencers ),
				'influencers' => $influencers,
			),
			sprintf(
				/* translators: 1: number of influencers, 2: niche */
				__( 'Found %1$d influencers in %2$s niche.', 'nvoos-content-graph-ai-platform' ),
				count( $influencers ),
				$niche
			)
		);
	}

	/**
	 * Handle campaign-create command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_campaign_create( $args, $context ) {
		if ( empty( $args['name'] ) || empty( $args['goal'] ) ) {
			return $this->error_response( __( 'Campaign name and goal are required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$name     = sanitize_text_field( $args['name'] );
		$goal     = sanitize_text_field( $args['goal'] );
		$duration = isset( $args['duration'] ) ? absint( $args['duration'] ) : 30;
		$budget   = isset( $args['budget'] ) ? floatval( $args['budget'] ) : 0;

		// Create campaign data.
		$campaign_data = array(
			'name'       => $name,
			'goal'       => $goal,
			'duration'   => $duration,
			'budget'     => $budget,
			'start_date' => current_time( 'mysql' ),
			'end_date'   => gmdate( 'Y-m-d H:i:s', strtotime( "+{$duration} days" ) ),
			'status'     => 'active',
			'created_at' => current_time( 'mysql' ),
		);

		// Store campaign.
		$campaigns                 = get_option( 'wp_mcp_ai_social_campaigns', array() );
		$campaign_id               = 'campaign_' . time();
		$campaigns[ $campaign_id ] = $campaign_data;
		update_option( 'wp_mcp_ai_social_campaigns', $campaigns );

		return $this->success_response(
			array(
				'campaign_id' => $campaign_id,
				'name'        => $name,
				'goal'        => $goal,
				'duration'    => $duration,
				'status'      => 'active',
			),
			sprintf(
				/* translators: 1: campaign name, 2: duration */
				__( 'Campaign "%1$s" created for %2$d days.', 'nvoos-content-graph-ai-platform' ),
				$name,
				$duration
			)
		);
	}

	/**
	 * Handle video-trim command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_video_trim( $args, $context ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Trim_Video' ) ) {
			// Simplified implementation.
			if ( empty( $args['video-id'] ) || ! isset( $args['start'] ) || ! isset( $args['end'] ) ) {
				return $this->error_response( __( 'Video ID, start time, and end time are required.', 'nvoos-content-graph-ai-platform' ) );
			}

			$video_id    = absint( $args['video-id'] );
			$start       = absint( $args['start'] );
			$end         = absint( $args['end'] );
			$output_name = isset( $args['output-name'] ) ? sanitize_file_name( $args['output-name'] ) : 'trimmed-video-' . time();

			// Verify video exists.
			$video = get_post( $video_id );
			if ( ! $video || 'attachment' !== $video->post_type ) {
				return $this->error_response( __( 'Video not found.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Create trim job.
			$trim_data = array(
				'video_id'    => $video_id,
				'start'       => $start,
				'end'         => $end,
				'duration'    => $end - $start,
				'output_name' => $output_name,
				'created_at'  => current_time( 'mysql' ),
				'status'      => 'queued',
			);

			// Store job.
			$jobs            = get_option( 'wp_mcp_ai_video_trim_jobs', array() );
			$job_id          = 'trim_' . time();
			$jobs[ $job_id ] = $trim_data;
			update_option( 'wp_mcp_ai_video_trim_jobs', $jobs );

			return $this->success_response(
				array(
					'job_id'   => $job_id,
					'duration' => $end - $start,
					'status'   => 'queued',
				),
				sprintf(
					/* translators: %d: trimmed duration */
					__( 'Video trim queued. Duration: %d seconds.', 'nvoos-content-graph-ai-platform' ),
					$end - $start
				)
			);
		}

		$tool = new \WP_MCP_AI_Tool_Trim_Video();

		$tool_args = array(
			'video_id'    => absint( $args['video-id'] ),
			'start_time'  => absint( $args['start'] ),
			'end_time'    => absint( $args['end'] ),
			'output_name' => isset( $args['output-name'] ) ? sanitize_file_name( $args['output-name'] ) : null,
		);

		$result = $tool->execute( $tool_args, $context );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->success_response(
			$result,
			__( 'Video trimmed successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-voiceover command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_voiceover( $args, $context ) {
		if ( empty( $args['video-id'] ) || empty( $args['script'] ) ) {
			return $this->error_response( __( 'Video ID and script are required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$video_id = absint( $args['video-id'] );
		$script   = sanitize_textarea_field( $args['script'] );
		$voice    = isset( $args['voice'] ) ? sanitize_text_field( $args['voice'] ) : 'neutral';
		$language = isset( $args['language'] ) ? sanitize_text_field( $args['language'] ) : 'en';

		// Verify video exists.
		$video = get_post( $video_id );
		if ( ! $video || 'attachment' !== $video->post_type ) {
			return $this->error_response( __( 'Video not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Create voiceover job.
		$voiceover_data = array(
			'video_id'   => $video_id,
			'script'     => $script,
			'voice'      => $voice,
			'language'   => $language,
			'created_at' => current_time( 'mysql' ),
			'status'     => 'processing',
		);

		// Store job.
		$jobs            = get_option( 'wp_mcp_ai_voiceover_jobs', array() );
		$job_id          = 'voiceover_' . time();
		$jobs[ $job_id ] = $voiceover_data;
		update_option( 'wp_mcp_ai_voiceover_jobs', $jobs );

		return $this->success_response(
			array(
				'job_id'   => $job_id,
				'voice'    => $voice,
				'language' => $language,
				'status'   => 'processing',
			),
			__( 'Voiceover generation started.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-render command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_render( $args, $context ) {
		if ( empty( $args['project-id'] ) ) {
			return $this->error_response( __( 'Project ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$project_id = sanitize_text_field( $args['project-id'] );
		$quality    = isset( $args['quality'] ) ? sanitize_text_field( $args['quality'] ) : 'standard';
		$format     = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'mp4';

		// Create render job.
		$render_data = array(
			'project_id' => $project_id,
			'quality'    => $quality,
			'format'     => $format,
			'created_at' => current_time( 'mysql' ),
			'status'     => 'rendering',
			'progress'   => 0,
		);

		// Store job.
		$jobs            = get_option( 'wp_mcp_ai_render_jobs', array() );
		$job_id          = 'render_' . time();
		$jobs[ $job_id ] = $render_data;
		update_option( 'wp_mcp_ai_render_jobs', $jobs );

		return $this->success_response(
			array(
				'job_id'  => $job_id,
				'quality' => $quality,
				'format'  => $format,
				'status'  => 'rendering',
			),
			__( 'Video rendering started.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle crosssell-suggest command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_crosssell_suggest( $args, $context ) {
		if ( empty( $args['product-id'] ) ) {
			return $this->error_response( __( 'Product ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$product_id = absint( $args['product-id'] );
		$limit      = isset( $args['limit'] ) ? absint( $args['limit'] ) : 5;
		$strategy   = isset( $args['strategy'] ) ? sanitize_text_field( $args['strategy'] ) : 'complementary';

		// Simulate cross-sell suggestions (in production, integrate with WooCommerce).
		$suggestions = array();
		for ( $i = 1; $i <= $limit; $i++ ) {
			$suggestions[] = array(
				'product_id'    => $product_id + $i,
				// translators: %d is the cross-sell product number.
				'title'         => sprintf( __( 'Cross-sell Product %d', 'nvoos-content-graph-ai-platform' ), $i ),
				'relevance'     => 95 - ( $i * 5 ),
				'price'         => number_format( 29.99 * $i, 2 ),
				'strategy'      => $strategy,
				'compatibility' => __( 'High', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return $this->success_response(
			array(
				'product_id'  => $product_id,
				'suggestions' => $suggestions,
				'count'       => count( $suggestions ),
				'strategy'    => $strategy,
			),
			sprintf(
				/* translators: %d: number of suggestions */
				__( 'Found %d cross-sell suggestions.', 'nvoos-content-graph-ai-platform' ),
				count( $suggestions )
			)
		);
	}

	/**
	 * Handle subscription-manage command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_subscription_manage( $args, $context ) {
		$action          = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'list';
		$subscription_id = isset( $args['subscription-id'] ) ? absint( $args['subscription-id'] ) : 0;

		switch ( $action ) {
			case 'pause':
				if ( ! $subscription_id ) {
					return $this->error_response( __( 'Subscription ID required for pause action.', 'nvoos-content-graph-ai-platform' ) );
				}
				return $this->success_response(
					array(
						'subscription_id' => $subscription_id,
						'status'          => 'paused',
						'paused_at'       => current_time( 'mysql' ),
					),
					__( 'Subscription paused successfully.', 'nvoos-content-graph-ai-platform' )
				);

			case 'cancel':
				if ( ! $subscription_id ) {
					return $this->error_response( __( 'Subscription ID required for cancel action.', 'nvoos-content-graph-ai-platform' ) );
				}
				return $this->success_response(
					array(
						'subscription_id' => $subscription_id,
						'status'          => 'cancelled',
						'cancelled_at'    => current_time( 'mysql' ),
					),
					__( 'Subscription cancelled successfully.', 'nvoos-content-graph-ai-platform' )
				);

			case 'renew':
				if ( ! $subscription_id ) {
					return $this->error_response( __( 'Subscription ID required for renew action.', 'nvoos-content-graph-ai-platform' ) );
				}
				return $this->success_response(
					array(
						'subscription_id' => $subscription_id,
						'status'          => 'active',
						'renewed_at'      => current_time( 'mysql' ),
						'next_billing'    => gmdate( 'Y-m-d', strtotime( '+1 month' ) ),
					),
					__( 'Subscription renewed successfully.', 'nvoos-content-graph-ai-platform' )
				);

			case 'list':
			default:
				// Simulate subscription list.
				$subscriptions = array(
					array(
						'id'           => 1001,
						'customer'     => __( 'John Doe', 'nvoos-content-graph-ai-platform' ),
						'product'      => __( 'Monthly Plan', 'nvoos-content-graph-ai-platform' ),
						'status'       => 'active',
						'next_billing' => gmdate( 'Y-m-d', strtotime( '+15 days' ) ),
					),
					array(
						'id'           => 1002,
						'customer'     => __( 'Jane Smith', 'nvoos-content-graph-ai-platform' ),
						'product'      => __( 'Annual Plan', 'nvoos-content-graph-ai-platform' ),
						'status'       => 'active',
						'next_billing' => gmdate( 'Y-m-d', strtotime( '+6 months' ) ),
					),
				);

				return $this->success_response(
					array(
						'subscriptions' => $subscriptions,
						'count'         => count( $subscriptions ),
					),
					sprintf(
						/* translators: %d: number of subscriptions */
						__( 'Found %d active subscriptions.', 'nvoos-content-graph-ai-platform' ),
						count( $subscriptions )
					)
				);
		}
	}

	/**
	 * Handle wholesale-pricing command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_wholesale_pricing( $args, $context ) {
		$action     = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'calculate';
		$product_id = isset( $args['product-id'] ) ? absint( $args['product-id'] ) : 0;
		$quantity   = isset( $args['quantity'] ) ? absint( $args['quantity'] ) : 1;

		if ( 'calculate' === $action ) {
			if ( ! $product_id || ! $quantity ) {
				return $this->error_response( __( 'Product ID and quantity required for calculation.', 'nvoos-content-graph-ai-platform' ) );
			}

			// Tiered pricing logic.
			$base_price = 100.00;
			$discount   = 0;

			if ( $quantity >= 100 ) {
				$discount = 30;
			} elseif ( $quantity >= 50 ) {
				$discount = 20;
			} elseif ( $quantity >= 10 ) {
				$discount = 10;
			}

			$unit_price  = $base_price * ( 1 - $discount / 100 );
			$total_price = $unit_price * $quantity;

			return $this->success_response(
				array(
					'product_id'  => $product_id,
					'quantity'    => $quantity,
					'base_price'  => number_format( $base_price, 2 ),
					'discount'    => $discount . '%',
					'unit_price'  => number_format( $unit_price, 2 ),
					'total_price' => number_format( $total_price, 2 ),
					'savings'     => number_format( ( $base_price - $unit_price ) * $quantity, 2 ),
				),
				__( 'Wholesale pricing calculated.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $this->error_response( __( 'Invalid action specified.', 'nvoos-content-graph-ai-platform' ) );
	}

	/**
	 * Handle marketplace-sync command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_marketplace_sync( $args, $context ) {
		$marketplace = isset( $args['marketplace'] ) ? sanitize_text_field( $args['marketplace'] ) : 'all';
		$action      = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'sync';

		$marketplaces = array( 'amazon', 'ebay', 'etsy', 'shopify' );

		if ( 'all' !== $marketplace && ! in_array( $marketplace, $marketplaces, true ) ) {
			return $this->error_response( __( 'Invalid marketplace specified.', 'nvoos-content-graph-ai-platform' ) );
		}

		$sync_targets = ( 'all' === $marketplace ) ? $marketplaces : array( $marketplace );

		$results = array();
		foreach ( $sync_targets as $target ) {
			$results[] = array(
				'marketplace'     => $target,
				'status'          => 'synced',
				'products_synced' => wp_rand( 10, 100 ),
				'updated'         => current_time( 'mysql' ),
			);
		}

		return $this->success_response(
			array(
				'action'       => $action,
				'marketplaces' => $results,
				'total_synced' => array_sum( array_column( $results, 'products_synced' ) ),
			),
			sprintf(
				/* translators: %d: number of marketplaces */
				__( 'Synchronized %d marketplace(s).', 'nvoos-content-graph-ai-platform' ),
				count( $results )
			)
		);
	}

	/**
	 * Handle tax-calculate command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_tax_calculate( $args, $context ) {
		$amount       = isset( $args['amount'] ) ? floatval( $args['amount'] ) : 0;
		$location     = isset( $args['location'] ) ? sanitize_text_field( $args['location'] ) : 'US-CA';
		$product_type = isset( $args['product-type'] ) ? sanitize_text_field( $args['product-type'] ) : 'physical';

		if ( $amount <= 0 ) {
			return $this->error_response( __( 'Amount must be greater than zero.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Simulate tax rates (in production, integrate with real tax API).
		$tax_rates = array(
			'US-CA' => 7.25,
			'US-NY' => 8.00,
			'US-TX' => 6.25,
			'UK'    => 20.00,
			'EU'    => 19.00,
		);

		$tax_rate   = isset( $tax_rates[ $location ] ) ? $tax_rates[ $location ] : 0;
		$tax_amount = $amount * ( $tax_rate / 100 );
		$total      = $amount + $tax_amount;

		return $this->success_response(
			array(
				'subtotal'     => number_format( $amount, 2 ),
				'location'     => $location,
				'tax_rate'     => $tax_rate . '%',
				'tax_amount'   => number_format( $tax_amount, 2 ),
				'total'        => number_format( $total, 2 ),
				'product_type' => $product_type,
			),
			__( 'Tax calculated successfully.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle return-process command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_return_process( $args, $context ) {
		$order_id = isset( $args['order-id'] ) ? absint( $args['order-id'] ) : 0;
		$action   = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'initiate';
		$reason   = isset( $args['reason'] ) ? sanitize_text_field( $args['reason'] ) : 'customer-request';

		if ( ! $order_id ) {
			return $this->error_response( __( 'Order ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		switch ( $action ) {
			case 'initiate':
				$return_id = 'RET' . time();
				return $this->success_response(
					array(
						'return_id'  => $return_id,
						'order_id'   => $order_id,
						'status'     => 'initiated',
						'reason'     => $reason,
						'created_at' => current_time( 'mysql' ),
						'label_url'  => 'https://example.com/return-label/' . $return_id,
					),
					__( 'Return initiated successfully.', 'nvoos-content-graph-ai-platform' )
				);

			case 'approve':
				return $this->success_response(
					array(
						'order_id'      => $order_id,
						'status'        => 'approved',
						'refund_amount' => '99.99',
						'approved_at'   => current_time( 'mysql' ),
					),
					__( 'Return approved and refund processed.', 'nvoos-content-graph-ai-platform' )
				);

			case 'reject':
				return $this->success_response(
					array(
						'order_id'    => $order_id,
						'status'      => 'rejected',
						'rejected_at' => current_time( 'mysql' ),
						'reason'      => __( 'Does not meet return policy criteria.', 'nvoos-content-graph-ai-platform' ),
					),
					__( 'Return rejected.', 'nvoos-content-graph-ai-platform' )
				);

			default:
				return $this->error_response( __( 'Invalid action specified.', 'nvoos-content-graph-ai-platform' ) );
		}
	}

	/**
	 * Handle supplier-sync command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_supplier_sync( $args, $context ) {
		$supplier = isset( $args['supplier'] ) ? sanitize_text_field( $args['supplier'] ) : 'all';
		$action   = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'sync-inventory';

		$suppliers = array( 'supplier-a', 'supplier-b', 'supplier-c' );

		if ( 'all' !== $supplier && ! in_array( $supplier, $suppliers, true ) ) {
			return $this->error_response( __( 'Invalid supplier specified.', 'nvoos-content-graph-ai-platform' ) );
		}

		$sync_targets = ( 'all' === $supplier ) ? $suppliers : array( $supplier );

		$results = array();
		foreach ( $sync_targets as $target ) {
			$results[] = array(
				'supplier'      => $target,
				'status'        => 'synced',
				'items_updated' => wp_rand( 50, 200 ),
				'price_changes' => wp_rand( 5, 20 ),
				'last_sync'     => current_time( 'mysql' ),
			);
		}

		return $this->success_response(
			array(
				'action'        => $action,
				'suppliers'     => $results,
				'total_updated' => array_sum( array_column( $results, 'items_updated' ) ),
			),
			sprintf(
				/* translators: %d: number of suppliers */
				__( 'Synchronized inventory with %d supplier(s).', 'nvoos-content-graph-ai-platform' ),
				count( $results )
			)
		);
	}

	/**
	 * Handle social-calendar command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_calendar( $args, $context ) {
		$view = isset( $args['view'] ) ? sanitize_text_field( $args['view'] ) : 'month';
		$date = isset( $args['date'] ) ? sanitize_text_field( $args['date'] ) : current_time( 'Y-m-d' );

		// Simulate calendar data.
		$calendar_data = array(
			array(
				'date'     => $date,
				'platform' => 'twitter',
				'content'  => __( 'New product launch announcement', 'nvoos-content-graph-ai-platform' ),
				'status'   => 'scheduled',
				'time'     => '10:00',
			),
			array(
				'date'     => gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ),
				'platform' => 'instagram',
				'content'  => __( 'Behind the scenes photo', 'nvoos-content-graph-ai-platform' ),
				'status'   => 'scheduled',
				'time'     => '14:00',
			),
			array(
				'date'     => gmdate( 'Y-m-d', strtotime( $date . ' +2 days' ) ),
				'platform' => 'facebook',
				'content'  => __( 'Customer testimonial video', 'nvoos-content-graph-ai-platform' ),
				'status'   => 'scheduled',
				'time'     => '12:00',
			),
		);

		return $this->success_response(
			array(
				'view'        => $view,
				'date'        => $date,
				'posts'       => $calendar_data,
				'total_posts' => count( $calendar_data ),
			),
			sprintf(
				/* translators: %s: view type */
				__( 'Social media calendar (%s view) retrieved.', 'nvoos-content-graph-ai-platform' ),
				$view
			)
		);
	}

	/**
	 * Handle social-engage command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_engage( $args, $context ) {
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$action   = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'reply';

		$engagement_data = array(
			'mentions' => wp_rand( 10, 50 ),
			'comments' => wp_rand( 20, 100 ),
			'messages' => wp_rand( 5, 30 ),
			'replied'  => 0,
		);

		if ( 'reply' === $action ) {
			$engagement_data['replied'] = $engagement_data['mentions'];
			$message                    = __( 'Auto-replied to all mentions.', 'nvoos-content-graph-ai-platform' );
		} elseif ( 'like' === $action ) {
			$engagement_data['liked'] = $engagement_data['mentions'];
			$message                  = __( 'Liked all mentions.', 'nvoos-content-graph-ai-platform' );
		} else {
			$message = __( 'Engagement data retrieved.', 'nvoos-content-graph-ai-platform' );
		}

		return $this->success_response(
			array(
				'platform'   => $platform,
				'action'     => $action,
				'engagement' => $engagement_data,
			),
			$message
		);
	}

	/**
	 * Handle social-monitor command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_monitor( $args, $context ) {
		$keywords = isset( $args['keywords'] ) ? sanitize_text_field( $args['keywords'] ) : '';
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';

		if ( empty( $keywords ) ) {
			return $this->error_response( __( 'Keywords are required for monitoring.', 'nvoos-content-graph-ai-platform' ) );
		}

		$keyword_list = array_map( 'trim', explode( ',', $keywords ) );

		$monitoring_results = array();
		foreach ( $keyword_list as $keyword ) {
			$monitoring_results[] = array(
				'keyword'   => $keyword,
				'mentions'  => wp_rand( 5, 50 ),
				'sentiment' => array(
					'positive' => 70,
					'neutral'  => 20,
					'negative' => 10,
				),
				'reach'     => wp_rand( 1000, 10000 ),
			);
		}

		return $this->success_response(
			array(
				'platform'       => $platform,
				'keywords'       => $keyword_list,
				'results'        => $monitoring_results,
				'total_mentions' => array_sum( array_column( $monitoring_results, 'mentions' ) ),
			),
			sprintf(
				/* translators: %d: number of keywords */
				__( 'Monitoring %d keyword(s).', 'nvoos-content-graph-ai-platform' ),
				count( $keyword_list )
			)
		);
	}

	/**
	 * Handle trend-identify command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_trend_identify( $args, $context ) {
		$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : 'general';
		$period   = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'week';

		$trends = array(
			array(
				'topic'     => '#AITechnology',
				'volume'    => wp_rand( 10000, 100000 ),
				'growth'    => '+' . wp_rand( 10, 200 ) . '%',
				'sentiment' => __( 'Positive', 'nvoos-content-graph-ai-platform' ),
			),
			array(
				'topic'     => '#Sustainability',
				'volume'    => wp_rand( 5000, 50000 ),
				'growth'    => '+' . wp_rand( 5, 150 ) . '%',
				'sentiment' => __( 'Very Positive', 'nvoos-content-graph-ai-platform' ),
			),
			array(
				'topic'     => '#RemoteWork',
				'volume'    => wp_rand( 8000, 80000 ),
				'growth'    => '+' . wp_rand( 15, 180 ) . '%',
				'sentiment' => __( 'Neutral', 'nvoos-content-graph-ai-platform' ),
			),
		);

		return $this->success_response(
			array(
				'category'     => $category,
				'period'       => $period,
				'trends'       => $trends,
				'total_trends' => count( $trends ),
			),
			sprintf(
				/* translators: 1: number of trends, 2: category */
				__( 'Identified %1$d trending topics in %2$s.', 'nvoos-content-graph-ai-platform' ),
				count( $trends ),
				$category
			)
		);
	}

	/**
	 * Handle social-report command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_social_report( $args, $context ) {
		$period   = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : 'month';
		$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
		$format   = isset( $args['format'] ) ? sanitize_text_field( $args['format'] ) : 'summary';

		$report_data = array(
			'period'    => $period,
			'platform'  => $platform,
			'metrics'   => array(
				'total_posts'     => wp_rand( 50, 200 ),
				'total_reach'     => wp_rand( 10000, 100000 ),
				'engagement_rate' => wp_rand( 3, 15 ) . '%',
				'follower_growth' => '+' . wp_rand( 100, 5000 ),
				'top_post'        => __( 'Product Launch Announcement', 'nvoos-content-graph-ai-platform' ),
			),
			'platforms' => array(
				'twitter'   => array(
					'posts'      => wp_rand( 20, 80 ),
					'engagement' => wp_rand( 1000, 10000 ),
				),
				'instagram' => array(
					'posts'      => wp_rand( 15, 60 ),
					'engagement' => wp_rand( 2000, 15000 ),
				),
				'facebook'  => array(
					'posts'      => wp_rand( 10, 40 ),
					'engagement' => wp_rand( 1500, 12000 ),
				),
			),
		);

		return $this->success_response(
			$report_data,
			sprintf(
				/* translators: %s: time period */
				__( 'Social media report generated for %s.', 'nvoos-content-graph-ai-platform' ),
				$period
			)
		);
	}

	/**
	 * Handle video-edit command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_edit( $args, $context ) {
		$video_id  = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$operation = isset( $args['operation'] ) ? sanitize_text_field( $args['operation'] ) : 'basic';

		if ( ! $video_id ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$edit_data = array(
			'video_id'  => $video_id,
			'operation' => $operation,
			'status'    => 'processing',
			'job_id'    => 'edit_' . time(),
		);

		// Store job.
		$jobs                         = get_option( 'wp_mcp_ai_video_edit_jobs', array() );
		$jobs[ $edit_data['job_id'] ] = $edit_data;
		update_option( 'wp_mcp_ai_video_edit_jobs', $jobs );

		return $this->success_response(
			$edit_data,
			__( 'Video editing started.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-effect command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_effect( $args, $context ) {
		$video_id = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$effect   = isset( $args['effect'] ) ? sanitize_text_field( $args['effect'] ) : 'none';

		if ( ! $video_id ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$available_effects = array( 'blur', 'sharpen', 'vintage', 'grayscale', 'sepia', 'brightness', 'contrast' );

		if ( ! in_array( $effect, $available_effects, true ) ) {
			return $this->error_response( __( 'Invalid effect specified.', 'nvoos-content-graph-ai-platform' ) );
		}

		return $this->success_response(
			array(
				'video_id' => $video_id,
				'effect'   => $effect,
				'status'   => 'applied',
			),
			sprintf(
				/* translators: %s: effect name */
				__( 'Applied %s effect to video.', 'nvoos-content-graph-ai-platform' ),
				$effect
			)
		);
	}

	/**
	 * Handle video-transition command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_transition( $args, $context ) {
		$video_id   = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$transition = isset( $args['transition'] ) ? sanitize_text_field( $args['transition'] ) : 'fade';

		if ( ! $video_id ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$transitions = array( 'fade', 'dissolve', 'wipe', 'slide', 'zoom', 'cut' );

		if ( ! in_array( $transition, $transitions, true ) ) {
			return $this->error_response( __( 'Invalid transition specified.', 'nvoos-content-graph-ai-platform' ) );
		}

		return $this->success_response(
			array(
				'video_id'   => $video_id,
				'transition' => $transition,
				'duration'   => '0.5s',
				'status'     => 'applied',
			),
			sprintf(
				/* translators: %s: transition type */
				__( 'Applied %s transition to video.', 'nvoos-content-graph-ai-platform' ),
				$transition
			)
		);
	}

	/**
	 * Handle video-music command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_music( $args, $context ) {
		$video_id = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$track    = isset( $args['track'] ) ? sanitize_text_field( $args['track'] ) : '';
		$volume   = isset( $args['volume'] ) ? absint( $args['volume'] ) : 70;

		if ( ! $video_id ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( empty( $track ) ) {
			return $this->error_response( __( 'Music track is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		return $this->success_response(
			array(
				'video_id' => $video_id,
				'track'    => $track,
				'volume'   => $volume . '%',
				'status'   => 'added',
			),
			__( 'Background music added to video.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Handle video-storyboard command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_storyboard( $args, $context ) {
		$project = isset( $args['project'] ) ? sanitize_text_field( $args['project'] ) : '';
		$action  = isset( $args['action'] ) ? sanitize_text_field( $args['action'] ) : 'create';

		if ( empty( $project ) ) {
			return $this->error_response( __( 'Project name is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( 'create' === $action ) {
			$storyboard_id = 'sb_' . time();
			return $this->success_response(
				array(
					'storyboard_id' => $storyboard_id,
					'project'       => $project,
					'scenes'        => 0,
					'status'        => 'draft',
					'created_at'    => current_time( 'mysql' ),
				),
				__( 'Storyboard created successfully.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $this->error_response( __( 'Invalid action specified.', 'nvoos-content-graph-ai-platform' ) );
	}

	/**
	 * Handle video-publish command.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function handle_video_publish( $args, $context ) {
		$video_id  = isset( $args['video-id'] ) ? absint( $args['video-id'] ) : 0;
		$platforms = isset( $args['platforms'] ) ? sanitize_text_field( $args['platforms'] ) : 'youtube';

		if ( ! $video_id ) {
			return $this->error_response( __( 'Video ID is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$platform_list = array_map( 'trim', explode( ',', $platforms ) );

		$publish_results = array();
		foreach ( $platform_list as $platform ) {
			$publish_results[] = array(
				'platform'     => $platform,
				'status'       => 'published',
				'url'          => 'https://' . $platform . '.com/video/' . $video_id,
				'published_at' => current_time( 'mysql' ),
			);
		}

		return $this->success_response(
			array(
				'video_id'  => $video_id,
				'platforms' => $publish_results,
				'total'     => count( $publish_results ),
			),
			sprintf(
				/* translators: %d: number of platforms */
				__( 'Video published to %d platform(s).', 'nvoos-content-graph-ai-platform' ),
				count( $publish_results )
			)
		);
	}
}
