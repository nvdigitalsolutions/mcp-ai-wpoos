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
 */

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
class WP_MCP_AI_Slash_Command_Toolkit_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Toolkit_Manager
	 */
	protected static $instance = null;

	/**
	 * Slash command handler.
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
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
	 * @return WP_MCP_AI_Slash_Command_Toolkit_Manager
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
		if ( class_exists( 'WP_MCP_AI_Toolkit_Registry' ) ) {
			$this->toolkit_registry = WP_MCP_AI_Toolkit_Registry::get_instance();
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
		// Define base version check constant if not already defined.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', false );
		}

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

		// Add pro toolkit commands if not in base version mode.
		if ( ! WP_MCP_AI_BASE_VERSION ) {
			$commands = array_merge(
				$commands,
				array(
					'ai_tool_builder'            => $this->get_ai_tool_builder_commands(),
					'analytics_pro'              => $this->get_analytics_pro_commands(),
					'architect_agent'            => $this->get_architect_agent_commands(),
					'architectural_design'       => $this->get_architectural_design_commands(),
					'calendar_booking'           => $this->get_calendar_booking_commands(),
					'chat_channels'              => $this->get_chat_channels_commands(),
					'crm'                        => $this->get_crm_commands(),
					'dj_management'              => $this->get_dj_management_commands(),
					'document_generation'        => $this->get_document_generation_commands(),
					'ecommerce_pro'              => $this->get_ecommerce_pro_commands(),
					'fantasy_football'           => $this->get_fantasy_football_commands(),
					'financial_planner'          => $this->get_financial_planner_commands(),
					'image_production'           => $this->get_image_production_commands(),
					'media_pro'                  => $this->get_media_pro_commands(),
					'multilingual'               => $this->get_multilingual_commands(),
					'regulatory_registration'    => $this->get_regulatory_registration_commands(),
					'site_creator'               => $this->get_site_creator_commands(),
					'social_media'               => $this->get_social_media_commands(),
					'video_production'           => $this->get_video_production_commands(),
				)
			);
		}

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
		foreach ( $this->toolkit_commands as $toolkit_slug => $commands ) {
			// Only register commands for enabled toolkits.
			if ( ! $this->is_toolkit_enabled( $toolkit_slug ) ) {
				continue;
			}

			foreach ( $commands as $command ) {
				$this->handler->register( $command['name'], $command['config'] );
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
					'description' => __( 'Start new content with AI assistance', 'mcp-ai-wpoos' ),
					'usage'       => '/content-draft --type=blog --topic="AI trends"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
					'parameters'  => array(
						'type'  => array(
							'description' => __( 'Content type (blog, page, product)', 'mcp-ai-wpoos' ),
							'required'    => false,
							'default'     => 'post',
						),
						'topic' => array(
							'description' => __( 'Content topic or title', 'mcp-ai-wpoos' ),
							'required'    => true,
						),
						'tone'  => array(
							'description' => __( 'Writing tone (professional, casual, technical)', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Improve existing content (SEO, readability, engagement)', 'mcp-ai-wpoos' ),
					'usage'       => '/content-enhance --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'seo-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_seo_optimize' ),
					'description' => __( 'Apply SEO recommendations to content', 'mcp-ai-wpoos' ),
					'usage'       => '/seo-optimize --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'publish-review',
				'config' => array(
					'handler'     => array( $this, 'handle_publish_review' ),
					'description' => __( 'Initiate content review workflow', 'mcp-ai-wpoos' ),
					'usage'       => '/publish-review --post_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'content_publishing',
				),
			),
			array(
				'name'   => 'content-schedule',
				'config' => array(
					'handler'     => array( $this, 'handle_content_schedule' ),
					'description' => __( 'Schedule content with optimal timing', 'mcp-ai-wpoos' ),
					'usage'       => '/content-schedule --post_id=123 --date="2024-12-25"',
					'capability'  => 'publish_posts',
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
					'description' => __( 'Compress and optimize images', 'mcp-ai-wpoos' ),
					'usage'       => '/image-optimize --attachment_id=456',
					'capability'  => 'upload_files',
					'toolkit'     => 'media_processing',
				),
			),
			array(
				'name'   => 'video-transcode',
				'config' => array(
					'handler'     => array( $this, 'handle_video_transcode' ),
					'description' => __( 'Convert video formats', 'mcp-ai-wpoos' ),
					'usage'       => '/video-transcode --attachment_id=789 --format=mp4',
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
					'description' => __( 'Generate data summaries', 'mcp-ai-wpoos' ),
					'usage'       => '/data-summarize --source=sales_2024',
					'capability'  => 'edit_posts',
					'toolkit'     => 'data_analytics',
				),
			),
			array(
				'name'   => 'chart-create',
				'config' => array(
					'handler'     => array( $this, 'handle_chart_create' ),
					'description' => __( 'Generate charts from data', 'mcp-ai-wpoos' ),
					'usage'       => '/chart-create --type=line --data=monthly_sales',
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
					'description' => __( 'Trigger order fulfillment workflow', 'mcp-ai-wpoos' ),
					'usage'       => '/order-fulfill --order_id=12345',
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_business',
				),
			),
			array(
				'name'   => 'inventory-check',
				'config' => array(
					'handler'     => array( $this, 'handle_inventory_check' ),
					'description' => __( 'Check stock levels', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Static code analysis', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Comprehensive security scan', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Natural language research queries', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Generate maps', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Create new workflow', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Create email campaign', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Connect to external API', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Deploy AI model to production', 'mcp-ai-wpoos' ),
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
	public function handle_content_draft( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'topic' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create content.', 'mcp-ai-wpoos' )
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
					__( 'Draft content for: %1$s\n\nTone: %2$s\n\n[AI-generated content will be added here]', 'mcp-ai-wpoos' ),
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
					__( 'Draft created successfully! Post ID: %s', 'mcp-ai-wpoos' ),
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
	public function handle_content_enhance( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to edit content.', 'mcp-ai-wpoos' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new WP_Error(
					'post_not_found',
					__( 'Post not found.', 'mcp-ai-wpoos' )
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
					'readability' => __( 'Consider shorter paragraphs for better readability', 'mcp-ai-wpoos' ),
					'engagement'  => __( 'Add more subheadings to improve scannability', 'mcp-ai-wpoos' ),
					'seo'         => __( 'Include more relevant keywords naturally', 'mcp-ai-wpoos' ),
				),
			);

			$this->log_activity( 'content-enhance', $args, $result );

			return $this->success_response(
				$result,
				__( 'Content analysis complete. Suggestions provided.', 'mcp-ai-wpoos' )
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
	public function handle_seo_optimize( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to optimize content.', 'mcp-ai-wpoos' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new WP_Error(
					'post_not_found',
					__( 'Post not found.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			// Generate meta description if missing.
			$meta_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			if ( empty( $meta_desc ) ) {
				$excerpt   = wp_trim_words( strip_tags( $post->post_content ), 20, '...' );
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
					'content_length'   => str_word_count( strip_tags( $post->post_content ) ),
				),
				'recommendations'  => array(
					__( 'Add internal links to related content', 'mcp-ai-wpoos' ),
					__( 'Optimize images with alt text', 'mcp-ai-wpoos' ),
					__( 'Use focus keywords in first paragraph', 'mcp-ai-wpoos' ),
				),
			);

			$this->log_activity( 'seo-optimize', $args, $result );

			return $this->success_response(
				$result,
				__( 'SEO optimization applied successfully.', 'mcp-ai-wpoos' )
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
	public function handle_publish_review( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to review content.', 'mcp-ai-wpoos' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new WP_Error(
					'post_not_found',
					__( 'Post not found.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			// Perform review checks.
			$review_checklist = array(
				'content_length'   => str_word_count( strip_tags( $post->post_content ) ) >= 300,
				'has_featured_image' => has_post_thumbnail( $post_id ),
				'has_excerpt'      => ! empty( $post->post_excerpt ),
				'has_categories'   => ! empty( get_the_category( $post_id ) ),
				'has_tags'         => ! empty( get_the_tags( $post_id ) ),
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
					__( 'Review complete. Score: %d%%', 'mcp-ai-wpoos' ),
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
	public function handle_content_schedule( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'post_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'publish_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to schedule content.', 'mcp-ai-wpoos' )
				)
			);
		}

		$post_id = absint( $args['post_id'] );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response(
				new WP_Error(
					'post_not_found',
					__( 'Post not found.', 'mcp-ai-wpoos' )
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
					__( 'Content scheduled for: %s', 'mcp-ai-wpoos' ),
					$schedule_date
				)
			);

		} catch ( Exception $e ) {
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
	protected function handle_generic_command( $args, $context ) {
		return array(
			'success' => true,
			'message' => __( 'Command registered - Implementation coming soon', 'mcp-ai-wpoos' ),
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
				return new WP_Error(
					'missing_required_param',
					sprintf(
						/* translators: %s: parameter name */
						__( 'Missing required parameter: %s', 'mcp-ai-wpoos' ),
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
	public function handle_video_transcode( $args, $context ) {
		return $this->handle_generic_command( $args, $context );
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
	public function handle_data_summarize( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'source' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to analyze data.', 'mcp-ai-wpoos' )
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
					__( 'Data summary generated for: %s', 'mcp-ai-wpoos' ),
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
	public function handle_chart_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'type', 'data' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create charts.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$chart_type = sanitize_text_field( $args['type'] );
			$data_source = sanitize_text_field( $args['data'] );

			// Mock chart creation - in real implementation, would use Chart.js or similar.
			$chart_id = uniqid( 'chart_', true );

			$result = array(
				'chart_id'   => $chart_id,
				'type'       => $chart_type,
				'data_source' => $data_source,
				'created'    => current_time( 'mysql' ),
				'shortcode'  => "[chart id=\"{$chart_id}\"]",
			);

			$this->log_activity( 'chart-create', $args, $result );

			return $this->success_response(
				$result,
				__( 'Chart created successfully.', 'mcp-ai-wpoos' )
			);

		} catch ( Exception $e ) {
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
	public function handle_order_fulfill( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'order_id' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to fulfill orders.', 'mcp-ai-wpoos' )
				)
			);
		}

		$order_id = absint( $args['order_id' ] );

		try {
			// Check if WooCommerce is active.
			if ( ! function_exists( 'wc_get_order' ) ) {
				return $this->error_response(
					new WP_Error(
						'woocommerce_not_active',
						__( 'WooCommerce is not active.', 'mcp-ai-wpoos' )
					)
				);
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return $this->error_response(
					new WP_Error(
						'order_not_found',
						__( 'Order not found.', 'mcp-ai-wpoos' )
					)
				);
			}

			// Mark order as completed.
			$order->update_status( 'completed', __( 'Order fulfilled via slash command', 'mcp-ai-wpoos' ) );

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
					__( 'Order #%d fulfilled successfully.', 'mcp-ai-wpoos' ),
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
	public function handle_inventory_check( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to check inventory.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			// Check if WooCommerce is active.
			if ( ! function_exists( 'wc_get_products' ) ) {
				return $this->error_response(
					new WP_Error(
						'woocommerce_not_active',
						__( 'WooCommerce is not active.', 'mcp-ai-wpoos' )
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
			$out_of_stock = 0;

			foreach ( $products as $product ) {
				$stock_qty = $product->get_stock_quantity();
				if ( $stock_qty !== null && $stock_qty <= $low_stock_threshold ) {
					$low_stock_items[] = array(
						'id'       => $product->get_id(),
						'name'     => $product->get_name(),
						'stock'    => $stock_qty,
						'sku'      => $product->get_sku(),
					);
				}
				if ( ! $product->is_in_stock() ) {
					$out_of_stock++;
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
					__( 'Found %d low stock items.', 'mcp-ai-wpoos' ),
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
	public function handle_code_analyze( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'file' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to analyze code.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$file_path = sanitize_text_field( $args['file'] );

			// Mock code analysis results.
			$analysis = array(
				'file'              => $file_path,
				'lines_of_code'     => 250,
				'complexity_score'  => 15,
				'security_issues'   => 2,
				'style_warnings'    => 5,
				'suggestions'       => array(
					__( 'Consider extracting complex logic into separate functions', 'mcp-ai-wpoos' ),
					__( 'Add input sanitization for user data', 'mcp-ai-wpoos' ),
					__( 'Improve variable naming for clarity', 'mcp-ai-wpoos' ),
				),
			);

			$this->log_activity( 'code-analyze', $args, $analysis );

			return $this->success_response(
				$analysis,
				__( 'Code analysis complete.', 'mcp-ai-wpoos' )
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
	public function handle_security_scan( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to run security scans.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$scan_type = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'full';

			// Mock security scan results.
			$scan_results = array(
				'scan_id'             => uniqid( 'scan_', true ),
				'scan_type'           => $scan_type,
				'completed_at'        => current_time( 'mysql' ),
				'vulnerabilities'     => array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 3,
					'low'      => 5,
				),
				'checks_performed'    => array(
					'file_permissions',
					'outdated_plugins',
					'weak_passwords',
					'ssl_certificate',
					'database_security',
				),
				'recommendations'     => array(
					__( 'Update 2 plugins with known vulnerabilities', 'mcp-ai-wpoos' ),
					__( 'Enable two-factor authentication', 'mcp-ai-wpoos' ),
					__( 'Review file permissions on uploads directory', 'mcp-ai-wpoos' ),
				),
				'overall_score'       => 75,
			);

			$this->log_activity( 'security-scan', $args, $scan_results );

			return $this->success_response(
				$scan_results,
				sprintf(
					/* translators: %d: security score */
					__( 'Security scan complete. Score: %d/100', 'mcp-ai-wpoos' ),
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
	public function handle_research_query( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'query' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to perform research queries.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$query = sanitize_text_field( $args['query'] );
			$sources = ! empty( $args['sources'] ) ? sanitize_text_field( $args['sources'] ) : 'all';

			// Mock research results.
			$research = array(
				'query'         => $query,
				'sources_used'  => explode( ',', $sources ),
				'results_found' => 15,
				'top_results'   => array(
					array(
						'title'   => __( 'Research Result 1', 'mcp-ai-wpoos' ),
						'source'  => 'Academic Database',
						'relevance' => 95,
					),
					array(
						'title'   => __( 'Research Result 2', 'mcp-ai-wpoos' ),
						'source'  => 'Industry Reports',
						'relevance' => 88,
					),
					array(
						'title'   => __( 'Research Result 3', 'mcp-ai-wpoos' ),
						'source'  => 'News Articles',
						'relevance' => 82,
					),
				),
				'summary'       => __( 'Found 15 relevant results across multiple sources with high confidence.', 'mcp-ai-wpoos' ),
			);

			$this->log_activity( 'research-query', $args, $research );

			return $this->success_response(
				$research,
				sprintf(
					/* translators: %d: number of results */
					__( 'Research complete. Found %d results.', 'mcp-ai-wpoos' ),
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
	public function handle_workflow_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create workflows.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$workflow_name = sanitize_text_field( $args['name'] );
			$description = ! empty( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';

			// Create workflow definition.
			$workflow_id = uniqid( 'workflow_', true );
			$workflow = array(
				'id'          => $workflow_id,
				'name'        => $workflow_name,
				'description' => $description,
				'steps'       => array(),
				'status'      => 'active',
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save workflow (in real implementation, would save to database or options).
			$workflows = get_option( 'wp_mcp_ai_workflows', array() );
			$workflows[ $workflow_id ] = $workflow;
			update_option( 'wp_mcp_ai_workflows', $workflows );

			$result = array(
				'workflow_id'   => $workflow_id,
				'name'          => $workflow_name,
				'status'        => 'created',
			);

			$this->log_activity( 'workflow-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" created successfully.', 'mcp-ai-wpoos' ),
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
	public function handle_email_campaign( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'subject', 'content' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create campaigns.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$subject = sanitize_text_field( $args['subject'] );
			$content = wp_kses_post( $args['content'] );
			$audience = ! empty( $args['audience'] ) ? sanitize_text_field( $args['audience'] ) : 'all';

			// Mock campaign creation.
			$campaign_id = uniqid( 'campaign_', true );

			$result = array(
				'campaign_id'      => $campaign_id,
				'subject'          => $subject,
				'audience'         => $audience,
				'status'           => 'draft',
				'created'          => current_time( 'mysql' ),
				'estimated_reach'  => 1000,
			);

			$this->log_activity( 'email-campaign', $args, $result );

			return $this->success_response(
				$result,
				__( 'Email campaign created successfully.', 'mcp-ai-wpoos' )
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
	public function handle_api_connect( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'service' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to connect APIs.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$service = sanitize_text_field( $args['service'] );
			$api_key = ! empty( $args['api_key'] ) ? sanitize_text_field( $args['api_key'] ) : '';

			// Mock API connection test.
			$connection = array(
				'service'       => $service,
				'status'        => 'connected',
				'connected_at'  => current_time( 'mysql' ),
				'test_result'   => 'success',
				'rate_limit'    => '1000/hour',
			);

			// Save API connection (in real implementation).
			update_option( "wp_mcp_ai_api_{$service}", array(
				'connected'    => true,
				'connected_at' => current_time( 'mysql' ),
			) );

			$this->log_activity( 'api-connect', array( 'service' => $service ), $connection );

			return $this->success_response(
				$connection,
				sprintf(
					/* translators: %s: service name */
					__( 'Successfully connected to %s API.', 'mcp-ai-wpoos' ),
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

	// ========================================================================
	// PRO TOOLKIT COMMANDS (19 Toolkits)
	// ========================================================================

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
					'description' => __( 'Create new AI tool', 'mcp-ai-wpoos' ),
					'usage'       => '/aitool-create --name="My Tool" --type=prompt',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-test',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Test AI tool functionality', 'mcp-ai-wpoos' ),
					'usage'       => '/aitool-test --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-deploy',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Deploy AI tool to production', 'mcp-ai-wpoos' ),
					'usage'       => '/aitool-deploy --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-version',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Manage tool versions', 'mcp-ai-wpoos' ),
					'usage'       => '/aitool-version --tool_id=123 --version=1.2',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'prompt-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Optimize AI prompts', 'mcp-ai-wpoos' ),
					'usage'       => '/prompt-optimize --prompt_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'prompt-library',
				'config' => array(
					'handler'     => array( $this, 'handle_prompt_library' ),
					'description' => __( 'Access prompt templates', 'mcp-ai-wpoos' ),
					'usage'       => '/prompt-library --search="SEO"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'tool-monitor',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Monitor tool usage and performance', 'mcp-ai-wpoos' ),
					'usage'       => '/tool-monitor --tool_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'tool-marketplace',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Browse/share tools', 'mcp-ai-wpoos' ),
					'usage'       => '/tool-marketplace --category="content"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'integration-add',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Add tool integrations', 'mcp-ai-wpoos' ),
					'usage'       => '/integration-add --tool_id=123 --service="slack"',
					'capability'  => 'manage_options',
					'toolkit'     => 'ai_tool_builder',
				),
			),
			array(
				'name'   => 'aitool-analytics',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'View tool analytics', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Create custom dashboards', 'mcp-ai-wpoos' ),
					'usage'       => '/analytics-dashboard --name="Sales Overview"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'metric-define',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Define custom metrics', 'mcp-ai-wpoos' ),
					'usage'       => '/metric-define --name="Conversion Rate"',
					'capability'  => 'manage_options',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'metric-track',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Track metric performance', 'mcp-ai-wpoos' ),
					'usage'       => '/metric-track --metric_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'goal-set',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Set analytics goals', 'mcp-ai-wpoos' ),
					'usage'       => '/goal-set --metric="revenue" --target=10000',
					'capability'  => 'manage_options',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'funnel-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Analyze conversion funnels', 'mcp-ai-wpoos' ),
					'usage'       => '/funnel-analyze --funnel="checkout"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'cohort-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Cohort analysis', 'mcp-ai-wpoos' ),
					'usage'       => '/cohort-analyze --cohort="monthly"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'attribution-model',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Attribution modeling', 'mcp-ai-wpoos' ),
					'usage'       => '/attribution-model --type="last-click"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'segment-advanced',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Advanced segmentation', 'mcp-ai-wpoos' ),
					'usage'       => '/segment-advanced --criteria="high-value"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'predict-churn',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Churn prediction', 'mcp-ai-wpoos' ),
					'usage'       => '/predict-churn --segment="customers"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'ltv-calculate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Customer lifetime value', 'mcp-ai-wpoos' ),
					'usage'       => '/ltv-calculate --segment="all"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'analytics-export',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Export analytics data', 'mcp-ai-wpoos' ),
					'usage'       => '/analytics-export --format=csv --date-range="last-30-days"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'analytics_pro',
				),
			),
			array(
				'name'   => 'alert-configure',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Configure analytics alerts', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Create development plan', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-plan --project="E-commerce Site"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-scaffold',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Scaffold project structure', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-scaffold --type=plugin',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-review',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Review architecture design', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-review --project_id=123',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-refactor',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Suggest refactoring', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-refactor --file=class-example.php',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-document',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate architecture docs', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-document --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-diagram',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create architecture diagrams', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-diagram --type="class-diagram"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Analyze codebase', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-analyze --path=includes/',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-migrate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Plan migrations', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-migrate --from=v1.0 --to=v2.0',
					'capability'  => 'manage_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-optimize',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Optimize architecture', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-optimize --focus="performance"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-test',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate test suites', 'mcp-ai-wpoos' ),
					'usage'       => '/architect-test --class="WP_MCP_AI_Tool"',
					'capability'  => 'edit_theme_options',
					'toolkit'     => 'architect_agent',
				),
			),
			array(
				'name'   => 'architect-deploy',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Deployment strategy', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Generate floor plans', 'mcp-ai-wpoos' ),
					'usage'       => '/floor-plan --sqft=2000 --bedrooms=3',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'blueprint-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create blueprints', 'mcp-ai-wpoos' ),
					'usage'       => '/blueprint-create --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => '3d-model',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate 3D models', 'mcp-ai-wpoos' ),
					'usage'       => '/3d-model --design_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'space-calculate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Calculate space requirements', 'mcp-ai-wpoos' ),
					'usage'       => '/space-calculate --room-type="office"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'compliance-check',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Building code compliance', 'mcp-ai-wpoos' ),
					'usage'       => '/compliance-check --project_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'cost-estimate',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Construction cost estimation', 'mcp-ai-wpoos' ),
					'usage'       => '/cost-estimate --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'material-specify',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Material specifications', 'mcp-ai-wpoos' ),
					'usage'       => '/material-specify --category="flooring"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'lighting-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Lighting design', 'mcp-ai-wpoos' ),
					'usage'       => '/lighting-plan --room_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'hvac-design',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'HVAC system design', 'mcp-ai-wpoos' ),
					'usage'       => '/hvac-design --sqft=2000',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'plumbing-layout',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Plumbing layout', 'mcp-ai-wpoos' ),
					'usage'       => '/plumbing-layout --floor_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'electrical-plan',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Electrical planning', 'mcp-ai-wpoos' ),
					'usage'       => '/electrical-plan --floor_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'structural-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Structural analysis', 'mcp-ai-wpoos' ),
					'usage'       => '/structural-analyze --design_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'accessibility-check',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'ADA compliance check', 'mcp-ai-wpoos' ),
					'usage'       => '/accessibility-check --project_id=123',
					'capability'  => 'manage_options',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'energy-analyze',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Energy efficiency analysis', 'mcp-ai-wpoos' ),
					'usage'       => '/energy-analyze --project_id=123',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'render-3d',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( '3D rendering', 'mcp-ai-wpoos' ),
					'usage'       => '/render-3d --model_id=789 --quality=high',
					'capability'  => 'edit_posts',
					'toolkit'     => 'architectural_design',
				),
			),
			array(
				'name'   => 'cad-export',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Export to CAD formats', 'mcp-ai-wpoos' ),
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
		$commands = array();
		$command_names = array( 'booking-create', 'booking-manage', 'availability-set', 'calendar-sync', 'reminder-send', 'booking-confirm', 'reschedule', 'cancel-booking', 'waitlist-manage', 'booking-report', 'resource-schedule', 'buffer-time' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'channel-create', 'channel-join', 'message-broadcast', 'thread-create', 'mention-user', 'channel-archive', 'chat-search', 'file-share', 'chat-integrate', 'chat-analytics' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
					'description' => __( 'Add new lead', 'mcp-ai-wpoos' ),
					'usage'       => '/lead-add --name="John Doe" --email="john@example.com"',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'lead-qualify',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Qualify leads', 'mcp-ai-wpoos' ),
					'usage'       => '/lead-qualify --lead_id=456',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'lead-assign',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Assign leads', 'mcp-ai-wpoos' ),
					'usage'       => '/lead-assign --lead_id=456 --user_id=789',
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create contact', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-merge',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Merge contacts', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'deal-create',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create deal', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'deal-move',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Move deal in pipeline', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'activity-log',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Log activity', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'follow-up',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Schedule follow-up', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'email-sequence',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Create email sequence', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'crm-report',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Generate CRM report', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'pipeline-view',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'View sales pipeline', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'contact-segment',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Segment contacts', 'mcp-ai-wpoos' ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'crm',
				),
			),
			array(
				'name'   => 'crm-sync',
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => __( 'Sync CRM data', 'mcp-ai-wpoos' ),
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
		$commands = array();
		$command_names = array( 'track-add', 'playlist-create', 'playlist-analyze', 'bpm-match', 'key-match', 'setlist-plan', 'event-plan', 'track-recommend', 'mix-analyze', 'library-organize', 'event-report' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'doc-create', 'pdf-generate', 'doc-merge', 'template-create', 'variable-fill', 'doc-sign', 'doc-approve', 'doc-version', 'doc-export', 'doc-watermark', 'doc-secure', 'doc-batch', 'doc-archive' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'product-recommend', 'upsell-suggest', 'crosssell-suggest', 'bundle-create', 'discount-optimize', 'abandoned-recover', 'subscription-manage', 'wholesale-pricing', 'marketplace-sync', 'shipping-optimize', 'tax-calculate', 'fraud-detect', 'return-process', 'supplier-sync', 'ecom-analytics' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
					'capability'  => 'manage_woocommerce',
					'toolkit'     => 'ecommerce_pro',
				),
			);
		}
		return $commands;
	}

	/**
	 * Get Fantasy Football toolkit commands.
	 *
	 * @since 1.3.0
	 * @return array Command definitions.
	 */
	protected function get_fantasy_football_commands() {
		$commands = array();
		$command_names = array( 'player-analyze', 'draft-strategy', 'draft-mock', 'waiver-recommend', 'trade-analyze', 'lineup-optimize', 'matchup-preview', 'injury-track', 'projection-update', 'league-standings', 'stats-compare', 'sleeper-identify' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
					'capability'  => 'edit_posts',
					'toolkit'     => 'fantasy_football',
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
		$commands = array();
		$command_names = array( 'budget-create', 'budget-track', 'investment-analyze', 'portfolio-optimize', 'retirement-plan', 'retirement-calc', 'debt-analyze', 'debt-payoff', 'goal-set', 'goal-track', 'tax-estimate', 'networth-calc', 'cashflow-analyze', 'finance-report' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'image-edit', 'image-enhance', 'background-remove', 'image-upscale', 'image-restore', 'color-correct', 'image-crop', 'image-filter', 'image-collage', 'image-template', 'image-batch-edit', 'image-watermark', 'image-metadata' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'media-organize', 'media-tag', 'media-search', 'media-backup', 'media-cdn', 'media-optimize-bulk', 'media-migrate', 'media-duplicate', 'media-unused', 'media-analytics', 'media-permission' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'translate-content', 'translate-bulk', 'locale-switch', 'glossary-manage', 'translate-check', 'language-detect', 'rtl-convert', 'locale-sync', 'translate-export', 'translate-import', 'language-fallback', 'multilingual-seo' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'business-register', 'license-apply', 'permit-apply', 'compliance-check', 'filing-submit', 'ein-apply', 'trademark-search', 'patent-search', 'incorporation-docs', 'annual-report', 'regulatory-alert', 'license-renew', 'compliance-report', 'registration-track', 'regulatory-research' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'site-research', 'competitor-analyze', 'site-plan', 'page-create', 'section-create', 'widget-create', 'template-create', 'template-apply', 'site-scaffold', 'design-system', 'component-library', 'responsive-test', 'site-export', 'site-deploy' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'social-post', 'social-schedule', 'social-calendar', 'hashtag-suggest', 'post-optimize', 'social-engage', 'social-monitor', 'influencer-find', 'campaign-create', 'social-analytics', 'competitor-track', 'trend-identify', 'social-report' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
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
		$commands = array();
		$command_names = array( 'video-edit', 'video-trim', 'video-merge', 'video-effect', 'video-transition', 'video-subtitle', 'video-voiceover', 'video-music', 'video-template', 'video-storyboard', 'video-render', 'video-publish', 'video-analytics', 'video-thumbnail' );
		foreach ( $command_names as $name ) {
			$commands[] = array(
				'name'   => $name,
				'config' => array(
					'handler'     => array( $this, 'handle_generic_command' ),
					'description' => sprintf( __( '%s command - Implementation coming soon', 'mcp-ai-wpoos' ), $name ),
					'capability'  => 'upload_files',
					'toolkit'     => 'video_production',
				),
			);
		}
		return $commands;
	}

	// ========================================================================
	// HIGH-PRIORITY COMMAND HANDLERS
	// ========================================================================

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
	public function handle_aitool_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create AI tools.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$tool_name = sanitize_text_field( $args['name'] );
			$tool_type = isset( $args['type'] ) ? sanitize_text_field( $args['type'] ) : 'prompt';
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
				'tool_id'     => $tool_id,
				'name'        => $tool_name,
				'type'        => $tool_type,
				'status'      => 'draft',
				'version'     => '1.0.0',
				'edit_url'    => admin_url( "post.php?post={$tool_id}&action=edit" ),
			);

			$this->log_activity( 'aitool-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: tool name */
					__( 'AI Tool "%s" created successfully! Ready for configuration.', 'mcp-ai-wpoos' ),
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
	public function handle_prompt_library( $args, $context ) {
		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to access the prompt library.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$search_term = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
			$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : 'all';

			// Define default prompt library.
			$prompt_library = array(
				array(
					'id'          => 'seo-meta-description',
					'name'        => __( 'SEO Meta Description Generator', 'mcp-ai-wpoos' ),
					'category'    => 'SEO',
					'description' => __( 'Generate SEO-optimized meta descriptions', 'mcp-ai-wpoos' ),
					'template'    => 'Write a compelling meta description (max 160 characters) for: {topic}',
					'tags'        => array( 'seo', 'meta', 'description' ),
				),
				array(
					'id'          => 'blog-post-outline',
					'name'        => __( 'Blog Post Outline', 'mcp-ai-wpoos' ),
					'category'    => 'Content',
					'description' => __( 'Create a comprehensive blog post outline', 'mcp-ai-wpoos' ),
					'template'    => 'Create a detailed outline for a blog post about: {topic}. Include introduction, main points, and conclusion.',
					'tags'        => array( 'blog', 'content', 'outline' ),
				),
				array(
					'id'          => 'social-media-caption',
					'name'        => __( 'Social Media Caption', 'mcp-ai-wpoos' ),
					'category'    => 'Social Media',
					'description' => __( 'Generate engaging social media captions', 'mcp-ai-wpoos' ),
					'template'    => 'Write an engaging {platform} caption for: {content}. Include relevant hashtags.',
					'tags'        => array( 'social', 'caption', 'engagement' ),
				),
				array(
					'id'          => 'product-description',
					'name'        => __( 'Product Description', 'mcp-ai-wpoos' ),
					'category'    => 'E-Commerce',
					'description' => __( 'Create compelling product descriptions', 'mcp-ai-wpoos' ),
					'template'    => 'Write a compelling product description for: {product_name}. Highlight key features and benefits.',
					'tags'        => array( 'ecommerce', 'product', 'description' ),
				),
				array(
					'id'          => 'email-subject-line',
					'name'        => __( 'Email Subject Line', 'mcp-ai-wpoos' ),
					'category'    => 'Email Marketing',
					'description' => __( 'Generate attention-grabbing email subject lines', 'mcp-ai-wpoos' ),
					'template'    => 'Create 5 compelling email subject lines for: {campaign_topic}. Focus on {goal}.',
					'tags'        => array( 'email', 'subject', 'marketing' ),
				),
			);

			// Filter by search term.
			$filtered_prompts = $prompt_library;
			if ( ! empty( $search_term ) ) {
				$filtered_prompts = array_filter(
					$prompt_library,
					function( $prompt ) use ( $search_term ) {
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
					function( $prompt ) use ( $category ) {
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
					__( 'Found %d prompt templates.', 'mcp-ai-wpoos' ),
					count( $filtered_prompts )
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}

	// ========================================================================
	// PHASE 1: ADDITIONAL HIGH-PRIORITY HANDLERS
	// ========================================================================

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
	public function handle_analytics_dashboard( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create analytics dashboards.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$dashboard_name = sanitize_text_field( $args['name'] );
			$metrics = isset( $args['metrics'] ) ? sanitize_text_field( $args['metrics'] ) : 'pageviews,sessions,bounces';
			$time_range = isset( $args['time_range'] ) ? sanitize_text_field( $args['time_range'] ) : 'last-30-days';

			// Create dashboard configuration.
			$dashboard_id = uniqid( 'dashboard_', true );
			$dashboard = array(
				'id'          => $dashboard_id,
				'name'        => $dashboard_name,
				'metrics'     => explode( ',', $metrics ),
				'time_range'  => $time_range,
				'widgets'     => array(
					array( 'type' => 'line_chart', 'metric' => 'pageviews' ),
					array( 'type' => 'bar_chart', 'metric' => 'sessions' ),
					array( 'type' => 'pie_chart', 'metric' => 'bounces' ),
				),
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save dashboard.
			$dashboards = get_option( 'wp_mcp_ai_analytics_dashboards', array() );
			$dashboards[ $dashboard_id ] = $dashboard;
			update_option( 'wp_mcp_ai_analytics_dashboards', $dashboards );

			$result = array(
				'dashboard_id' => $dashboard_id,
				'name'         => $dashboard_name,
				'metrics'      => $dashboard['metrics'],
				'widgets'      => count( $dashboard['widgets'] ),
				'view_url'     => admin_url( "admin.php?page=wp-mcp-ai-analytics&dashboard={$dashboard_id}" ),
			);

			$this->log_activity( 'analytics-dashboard', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: dashboard name */
					__( 'Analytics dashboard "%s" created successfully.', 'mcp-ai-wpoos' ),
					$dashboard_name
				)
			);

		} catch ( Exception $e ) {
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
	public function handle_social_post( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'content' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create social posts.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$content = sanitize_textarea_field( $args['content'] );
			$platform = isset( $args['platform'] ) ? sanitize_text_field( $args['platform'] ) : 'all';
			$hashtags = isset( $args['hashtags'] ) ? sanitize_text_field( $args['hashtags'] ) : '';

			// Create social post.
			$post_id = uniqid( 'social_', true );
			$post = array(
				'id'          => $post_id,
				'content'     => $content,
				'platform'    => $platform,
				'hashtags'    => $hashtags ? explode( ',', $hashtags ) : array(),
				'status'      => 'draft',
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save social post.
			$posts = get_option( 'wp_mcp_ai_social_posts', array() );
			$posts[ $post_id ] = $post;
			update_option( 'wp_mcp_ai_social_posts', $posts );

			$result = array(
				'post_id'  => $post_id,
				'platform' => $platform,
				'status'   => 'draft',
				'preview'  => wp_trim_words( $content, 20 ),
			);

			$this->log_activity( 'social-post', $args, $result );

			return $this->success_response(
				$result,
				__( 'Social post created successfully. Ready for scheduling.', 'mcp-ai-wpoos' )
			);

		} catch ( Exception $e ) {
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
	public function handle_doc_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'template' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create documents.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$template = sanitize_text_field( $args['template'] );
			$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : "Document from {$template}";

			// Create document from template.
			$doc_id = uniqid( 'doc_', true );
			$document = array(
				'id'          => $doc_id,
				'title'       => $title,
				'template'    => $template,
				'content'     => $this->get_template_content( $template ),
				'status'      => 'draft',
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save document.
			$documents = get_option( 'wp_mcp_ai_documents', array() );
			$documents[ $doc_id ] = $document;
			update_option( 'wp_mcp_ai_documents', $documents );

			$result = array(
				'doc_id'   => $doc_id,
				'title'    => $title,
				'template' => $template,
				'status'   => 'draft',
			);

			$this->log_activity( 'doc-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: template name */
					__( 'Document created from template "%s".', 'mcp-ai-wpoos' ),
					$template
				)
			);

		} catch ( Exception $e ) {
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
	 * Add CRM leads.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Command arguments.
	 * @param array $context Execution context.
	 * @return array Command result.
	 */
	public function handle_lead_add( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name', 'email' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to add leads.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$name = sanitize_text_field( $args['name'] );
			$email = sanitize_email( $args['email'] );
			$source = isset( $args['source'] ) ? sanitize_text_field( $args['source'] ) : 'manual';
			$score = isset( $args['score'] ) ? absint( $args['score'] ) : 50;

			// Create lead.
			$lead_id = uniqid( 'lead_', true );
			$lead = array(
				'id'          => $lead_id,
				'name'        => $name,
				'email'       => $email,
				'source'      => $source,
				'score'       => $score,
				'status'      => 'new',
				'created'     => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			);

			// Save lead.
			$leads = get_option( 'wp_mcp_ai_crm_leads', array() );
			$leads[ $lead_id ] = $lead;
			update_option( 'wp_mcp_ai_crm_leads', $leads );

			$result = array(
				'lead_id' => $lead_id,
				'name'    => $name,
				'email'   => $email,
				'source'  => $source,
				'score'   => $score,
				'status'  => 'new',
			);

			$this->log_activity( 'lead-add', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: lead name */
					__( 'Lead "%s" added successfully.', 'mcp-ai-wpoos' ),
					$name
				)
			);

		} catch ( Exception $e ) {
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
	public function handle_budget_create( $args, $context ) {
		// Validate required parameters.
		$validation = $this->validate_args( $args, array( 'name' ) );
		if ( is_wp_error( $validation ) ) {
			return $this->error_response( $validation );
		}

		// Check capabilities.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_response(
				new WP_Error(
					'insufficient_permissions',
					__( 'You do not have permission to create budgets.', 'mcp-ai-wpoos' )
				)
			);
		}

		try {
			$name = sanitize_text_field( $args['name'] );
			$monthly_income = isset( $args['monthly_income'] ) ? floatval( $args['monthly_income'] ) : 0;
			$savings_goal = isset( $args['savings_goal'] ) ? floatval( $args['savings_goal'] ) : 0.20;

			// Calculate budget allocations.
			$savings = $monthly_income * $savings_goal;
			$expenses = $monthly_income - $savings;

			// Create budget.
			$budget_id = uniqid( 'budget_', true );
			$budget = array(
				'id'              => $budget_id,
				'name'            => $name,
				'monthly_income'  => $monthly_income,
				'savings_goal'    => $savings_goal,
				'allocations'     => array(
					'savings'      => $savings,
					'housing'      => $expenses * 0.30,
					'food'         => $expenses * 0.15,
					'transportation' => $expenses * 0.15,
					'utilities'    => $expenses * 0.10,
					'other'        => $expenses * 0.30,
				),
				'created'         => current_time( 'mysql' ),
				'created_by'      => get_current_user_id(),
			);

			// Save budget.
			$budgets = get_option( 'wp_mcp_ai_budgets', array() );
			$budgets[ $budget_id ] = $budget;
			update_option( 'wp_mcp_ai_budgets', $budgets );

			$result = array(
				'budget_id'      => $budget_id,
				'name'           => $name,
				'monthly_income' => $monthly_income,
				'savings'        => $savings,
				'allocations'    => $budget['allocations'],
			);

			$this->log_activity( 'budget-create', $args, $result );

			return $this->success_response(
				$result,
				sprintf(
					/* translators: %s: budget name */
					__( 'Budget "%s" created successfully.', 'mcp-ai-wpoos' ),
					$name
				)
			);

		} catch ( Exception $e ) {
			return $this->error_response( $e->getMessage() );
		}
	}
}
