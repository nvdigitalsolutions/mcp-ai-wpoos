<?php
/**
 * Toolkit-Based Slash Command Manager
 *
 * Declarative registry mapping toolkit slash commands onto existing MCP tools.
 *
 * The tool registry is the single source of truth for business logic. Every
 * command defined here is a thin declarative wrapper: a name, a tool slug,
 * an optional argument map, documentation and a capability. Execution is
 * delegated to `WP_MCP_AI_Slash_Command_Tool_Adapter`, which routes through
 * `WP_MCP_AI_Tool_Registry::execute_tool()` — so capability checks, parameter
 * validation, sanitisation and the canonical envelope all live in the tool
 * layer and are never duplicated here.
 *
 * Commands are only registered when their associated toolkit is enabled.
 * A command whose backing tool is absent (e.g. Pro addon not installed) is
 * simply never registered, and the adapter returns a friendly error if a
 * tool is later removed.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Command Manager Class
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
	 * Every entry is declarative:
	 *
	 *     array(
	 *         'name'    => 'video-compress',           // Command name (no leading slash).
	 *         'tool'    => 'compress_video',           // Tool slug to delegate to.
	 *         'tool_config' => array(                  // Optional adapter config.
	 *             'arg_map' => array( 'video-id' => 'video_id' ),
	 *             'defaults' => array( 'quality' => 'medium' ),
	 *         ),
	 *         'description' => '...',
	 *         'usage'       => '/video-compress --video_id=123',
	 *         'capability'  => 'upload_files',
	 *         'toolkit'     => 'video_production',
	 *         'parameters'  => array( ... ),           // /help documentation schema.
	 *     )
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
		$commands = array(
			'content_publishing'  => $this->get_content_publishing_commands(),
			'media_processing'    => $this->get_media_processing_commands(),
			'data_analytics'      => $this->get_data_analytics_commands(),
			'developer_technical' => $this->get_developer_commands(),
			'security_compliance' => $this->get_security_commands(),
			'research_discovery'  => $this->get_research_commands(),
		);

		// Additional toolkit slash commands for extended toolkits.
		// All commands defined in this class are always registered regardless of which
		// addons are installed. Commands that reference Pro addon tools are silently
		// inert when the Pro addon is absent — the tool registry's is_available() check
		// prevents execution of any tool whose class does not exist.
		$commands = array_merge(
			$commands,
			array(
				'calendar_booking'    => $this->get_calendar_booking_commands(),
				'crm'                 => $this->get_crm_commands(),
				'document_generation' => $this->get_document_generation_commands(),
				'ecommerce_pro'       => $this->get_ecommerce_pro_commands(),
				'financial_planner'   => $this->get_financial_planner_commands(),
				'image_production'    => $this->get_image_production_commands(),
				'multilingual'        => $this->get_multilingual_commands(),
				'social_media'        => $this->get_social_media_commands(),
				'video_production'    => $this->get_video_production_commands(),
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
	 * Build a declarative tool-backed command entry.
	 *
	 * @since 1.1.78
	 *
	 * @param string $name        Command name.
	 * @param string $tool        Tool slug.
	 * @param string $description Command description.
	 * @param string $usage       Usage example.
	 * @param string $capability  Required capability.
	 * @param string $toolkit     Toolkit slug.
	 * @param array  $tool_config Adapter config (arg_map, positional, defaults, render).
	 * @param array  $parameters  Documentation schema for /help.
	 * @return array Command entry.
	 */
	protected function command( $name, $tool, $description, $usage, $capability, $toolkit, $tool_config = array(), $parameters = array() ) {
		$config = array(
			'tool'        => $tool,
			'tool_config' => $tool_config,
			'description' => $description,
			'usage'       => $usage,
			'capability'  => $capability,
			'toolkit'     => $toolkit,
			'parameters'  => $parameters,
		);

		return array(
			'name'   => $name,
			'config' => $config,
		);
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
			$this->command(
				'content-draft',
				'create_post',
				__( 'Create a new draft post via the content tool', 'mcp-ai-wpoos' ),
				'/content-draft --topic="AI trends" --type=post',
				'edit_posts',
				'content_publishing',
				array(
					'arg_map'  => array(
						'topic' => 'title',
						'type'  => 'post_type',
					),
					'defaults' => array( 'status' => 'draft' ),
				),
				array(
					'topic' => array(
						'description' => __( 'Post title/topic', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
					'type'  => array(
						'description' => __( 'Post type', 'mcp-ai-wpoos' ),
						'required'    => false,
						'default'     => 'post',
					),
				)
			),
			$this->command(
				'seo-optimize',
				'seo_meta_optimizer',
				__( 'Optimise SEO meta tags and description for a post', 'mcp-ai-wpoos' ),
				'/seo-optimize --post_id=123 --focus_keyword="ai"',
				'edit_posts',
				'content_publishing',
				array(),
				array(
					'post_id' => array(
						'description' => __( 'Post ID to optimise', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'meta-generate',
				'seo_meta_optimizer',
				__( 'Auto-generate SEO meta tags and descriptions', 'mcp-ai-wpoos' ),
				'/meta-generate --post_id=123',
				'edit_posts',
				'content_publishing',
				array(),
				array(
					'post_id' => array(
						'description' => __( 'Post ID to generate meta for', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
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
			$this->command(
				'image-optimize',
				'optimize_image_sharp',
				__( 'Compress and optimise an image attachment', 'mcp-ai-wpoos' ),
				'/image-optimize --attachment_id=456',
				'upload_files',
				'media_processing',
				array(),
				array(
					'attachment_id' => array(
						'description' => __( 'Image attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'video-transcode',
				'transcode_video',
				__( 'Transcode a video to a platform preset or format', 'mcp-ai-wpoos' ),
				'/video-transcode --attachment_id=456 --output_format=mp4',
				'upload_files',
				'media_processing',
				array(),
				array(
					'attachment_id' => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
					'output_format' => array(
						'description' => __( 'Target format (mp4, webm, avi, mov, mkv, flv)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
			$this->command(
				'watermark-add',
				'add_watermark_to_video',
				__( 'Add a watermark overlay to a video', 'mcp-ai-wpoos' ),
				'/watermark-add --video_id=123 --watermark_id=456 --position=bottom-right',
				'upload_files',
				'media_processing',
				array(),
				array(
					'video_id'     => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'watermark_id' => array(
						'description' => __( 'Watermark image attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
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
			$this->command(
				'chart-create',
				'create_chart',
				__( 'Create an interactive Chart.js chart', 'mcp-ai-wpoos' ),
				'/chart-create --type=bar --title="Sales" --save_as_attachment=true',
				'edit_posts',
				'data_analytics',
				array(),
				array(
					'type'  => array(
						'description' => __( 'Chart type (bar, line, pie, doughnut, radar, polarArea, scatter, bubble)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
					'title' => array(
						'description' => __( 'Chart title', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
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
			$this->command(
				'code-analyze',
				'analyze_code_sequence',
				__( 'Analyse and validate a code snippet', 'mcp-ai-wpoos' ),
				'/code-analyze --language=php --code="<?php echo 1;"',
				'edit_posts',
				'developer_technical',
				array(),
				array(
					'code'     => array(
						'description' => __( 'Code to analyse', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'language' => array(
						'description' => __( 'Programming language (default php)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
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
			$this->command(
				'security-scan',
				'check_site_security',
				__( 'Run a site security check', 'mcp-ai-wpoos' ),
				'/security-scan',
				'manage_options',
				'security_compliance'
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
			$this->command(
				'research-query',
				'deep_research',
				__( 'Run deep multi-source research on a topic', 'mcp-ai-wpoos' ),
				'/research-query --topic="WordPress AI plugins" --depth=standard',
				'edit_posts',
				'research_discovery',
				array(),
				array(
					'topic' => array(
						'description' => __( 'Research topic', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'depth' => array(
						'description' => __( 'Research depth (basic, standard, comprehensive)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
		);
	}

	/**
	 * Get Calendar & Booking toolkit commands.
	 *
	 * @since 1.1.78
	 *
	 * @return array Command definitions.
	 */
	protected function get_calendar_booking_commands() {
		return array(
			$this->command(
				'booking-create',
				'create_appointment',
				__( 'Create or update an appointment', 'mcp-ai-wpoos' ),
				'/booking-create --client_name="Jane" --client_email="jane@example.com" --start_time="2026-09-15 10:00:00"',
				'edit_posts',
				'calendar_booking',
				array(),
				array(
					'client_name'  => array(
						'description' => __( 'Client full name', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'client_email' => array(
						'description' => __( 'Client email address', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'start_time'   => array(
						'description' => __( 'Appointment start (Y-m-d H:i:s)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
		);
	}

	/**
	 * Get CRM toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_crm_commands() {
		return array(
			$this->command(
				'lead-add',
				'create_lead',
				__( 'Add a new CRM lead', 'mcp-ai-wpoos' ),
				'/lead-add --email="john@example.com" --first_name="John"',
				'edit_posts',
				'crm',
				array(),
				array(
					'email' => array(
						'description' => __( 'Lead email address', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'lead-qualify',
				'qualify_lead_bant',
				__( 'Qualify a lead with BANT scoring', 'mcp-ai-wpoos' ),
				'/lead-qualify --lead_id=456 --message_or_notes="Interested in pricing"',
				'edit_posts',
				'crm',
				array(
					'arg_map' => array(
						'message' => 'message_or_notes',
						'notes'   => 'message_or_notes',
					),
				),
				array(
					'lead_id' => array(
						'description' => __( 'Lead ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'lead-assign',
				'assign_lead_to_owner',
				__( 'Assign a lead to an owner', 'mcp-ai-wpoos' ),
				'/lead-assign --lead_id=456 --owner_id=789',
				'edit_posts',
				'crm',
				array(
					'arg_map' => array( 'user_id' => 'owner_id' ),
				),
				array(
					'lead_id' => array(
						'description' => __( 'Lead ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'deal-create',
				'create_deal',
				__( 'Create a CRM deal', 'mcp-ai-wpoos' ),
				'/deal-create --lead_id=456 --deal_name="Website project" --amount=5000',
				'edit_posts',
				'crm',
				array(),
				array(
					'lead_id' => array(
						'description' => __( 'Lead ID to associate', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'deal-move',
				'move_deal_stage',
				__( 'Move a deal to a new pipeline stage', 'mcp-ai-wpoos' ),
				'/deal-move --deal_id=789 --new_stage=proposal',
				'edit_posts',
				'crm',
				array(),
				array(
					'deal_id'   => array(
						'description' => __( 'Deal ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'new_stage' => array(
						'description' => __( 'Target stage slug', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'pipeline-view',
				'get_pipeline_view',
				__( 'View the CRM sales pipeline', 'mcp-ai-wpoos' ),
				'/pipeline-view --deal_owner=789 --per_stage=50',
				'edit_posts',
				'crm',
				array(),
				array(
					'deal_owner' => array(
						'description' => __( 'Filter by owner user ID', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
		);
	}

	/**
	 * Get Document Generation toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_document_generation_commands() {
		return array(
			$this->command(
				'doc-create',
				'pro_pdf_document',
				__( 'Generate a PDF document with AI', 'mcp-ai-wpoos' ),
				'/doc-create --title="Monthly report" --description="Quarterly sales summary"',
				'edit_posts',
				'document_generation',
				array(
					'defaults' => array( 'operation' => 'generate' ),
				),
				array(
					'description' => array(
						'description' => __( 'Natural-language description of the document', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
					'title'       => array(
						'description' => __( 'Document title', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
		);
	}

	/**
	 * Get E-Commerce Pro toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_ecommerce_pro_commands() {
		return array(
			$this->command(
				'abandoned-recover',
				'abandoned_cart_recovery',
				__( 'Identify or recover abandoned carts', 'mcp-ai-wpoos' ),
				'/abandoned-recover --action=identify --send_email=true',
				'manage_woocommerce',
				'ecommerce_pro',
				array(
					'defaults' => array( 'action' => 'identify' ),
				),
				array(
					'action' => array(
						'description' => __( 'identify, send_recovery or get_analytics', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
			$this->command(
				'inventory-forecast',
				'inventory_forecast',
				__( 'Forecast inventory demand and reorder points', 'mcp-ai-wpoos' ),
				'/inventory-forecast --forecast_type=demand --product_ids=1,2,3',
				'manage_woocommerce',
				'ecommerce_pro',
				array(
					'defaults' => array( 'forecast_type' => 'demand' ),
				),
				array(
					'forecast_type' => array(
						'description' => __( 'demand, reorder_points, stockout_risk or all', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
			$this->command(
				'create-discount-campaign',
				'create_discount_campaign',
				__( 'Create a WooCommerce discount coupon campaign', 'mcp-ai-wpoos' ),
				'/create-discount-campaign --code=SAVE20 --discount_type=percent --amount=20',
				'manage_woocommerce',
				'ecommerce_pro',
				array(),
				array(
					'code' => array(
						'description' => __( 'Coupon code', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
		);
	}

	/**
	 * Get Financial Planner toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_financial_planner_commands() {
		return array(
			$this->command(
				'budget-create',
				'budget_planner',
				__( 'Create or track a budget', 'mcp-ai-wpoos' ),
				'/budget-create --monthly_income=5000 --savings_goal=1000',
				'edit_posts',
				'financial_planner',
				array(
					'defaults' => array( 'action' => 'create' ),
				),
				array(
					'monthly_income' => array(
						'description' => __( 'Monthly after-tax income', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
		);
	}

	/**
	 * Get Image Production toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_image_production_commands() {
		return array(
			$this->command(
				'image-edit',
				'edit_gemini_image',
				__( 'Edit an image with an AI prompt', 'mcp-ai-wpoos' ),
				'/image-edit --attachment_id=456 --prompt="remove background"',
				'upload_files',
				'image_production',
				array(),
				array(
					'prompt'        => array(
						'description' => __( 'Edit instruction', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'attachment_id' => array(
						'description' => __( 'Image attachment ID', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
		);
	}

	/**
	 * Get Multilingual toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_multilingual_commands() {
		return array(
			$this->command(
				'content-translate',
				'auto_translate_content',
				__( 'Translate a post into another language', 'mcp-ai-wpoos' ),
				'/content-translate --post_id=123 --target_language=es',
				'edit_posts',
				'multilingual',
				array(),
				array(
					'post_id'         => array(
						'description' => __( 'Post ID to translate', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'target_language' => array(
						'description' => __( 'Target language code', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'translate-content',
				'auto_translate_content',
				__( 'Translate content to a target language', 'mcp-ai-wpoos' ),
				'/translate-content --post_id=123 --target_language=fr',
				'edit_posts',
				'multilingual',
				array(),
				array(
					'post_id'         => array(
						'description' => __( 'Post ID to translate', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'target_language' => array(
						'description' => __( 'Target language code', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
		);
	}

	/**
	 * Get Social Media toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_social_media_commands() {
		return array(
			$this->command(
				'social-post',
				'schedule_social_post',
				__( 'Schedule a social media post', 'mcp-ai-wpoos' ),
				'/social-post --content="Hello world" --platform=twitter',
				'edit_posts',
				'social_media',
				array(
					'arg_map' => array( 'platform' => 'platforms' ),
				),
				array(
					'content'  => array(
						'description' => __( 'Post content', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'platform' => array(
						'description' => __( 'Target platform', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'social-schedule',
				'schedule_social_post',
				__( 'Schedule a social post for later', 'mcp-ai-wpoos' ),
				'/social-schedule --content="Hello" --platform=linkedin --scheduled_time="2026-09-20T09:00:00"',
				'edit_posts',
				'social_media',
				array(
					'arg_map' => array( 'platform' => 'platforms' ),
				),
				array(
					'content'  => array(
						'description' => __( 'Post content', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'platform' => array(
						'description' => __( 'Target platform', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'social-analytics',
				'get_social_analytics',
				__( 'Fetch unified social media analytics', 'mcp-ai-wpoos' ),
				'/social-analytics --platforms=twitter,instagram',
				'edit_posts',
				'social_media',
				array(
					'arg_map' => array(
						'platform' => 'platforms',
						'period'   => 'group_by',
					),
				),
				array(
					'platforms' => array(
						'description' => __( 'Comma-separated platforms (empty = all)', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
			$this->command(
				'content-calendar',
				'get_content_calendar',
				__( 'View the planned content calendar', 'mcp-ai-wpoos' ),
				'/content-calendar --platform=instagram',
				'edit_posts',
				'social_media',
				array(),
				array(
					'platform' => array(
						'description' => __( 'Platform to filter by', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				)
			),
			$this->command(
				'social-publish',
				'publish_to_social',
				__( 'Publish a post to a social platform now', 'mcp-ai-wpoos' ),
				'/social-publish --platform=twitter --content="Hello" --dry_run=true',
				'edit_posts',
				'social_media',
				array(),
				array(
					'platform' => array(
						'description' => __( 'Target platform', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
					'content'  => array(
						'description' => __( 'Post content', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
		);
	}

	/**
	 * Get Video Production toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @return array Command definitions.
	 */
	protected function get_video_production_commands() {
		return array(
			$this->command(
				'video-compress',
				'compress_video',
				__( 'Compress a video', 'mcp-ai-wpoos' ),
				'/video-compress --video_id=123 --quality=medium',
				'upload_files',
				'video_production',
				array(),
				array(
					'video_id' => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'video-trim',
				'trim_video',
				__( 'Trim a video to a time range', 'mcp-ai-wpoos' ),
				'/video-trim --video_id=123 --start_time=10 --end_time=60',
				'upload_files',
				'video_production',
				array(),
				array(
					'video_id' => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'video-merge',
				'merge_videos',
				__( 'Merge multiple videos into one', 'mcp-ai-wpoos' ),
				'/video-merge --video_ids=1,2,3 --transition=fade',
				'upload_files',
				'video_production',
				array(),
				array(
					'video_ids' => array(
						'description' => __( 'Comma-separated video attachment IDs', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'video-thumbnail',
				'generate_video_thumbnails',
				__( 'Generate thumbnails from a video', 'mcp-ai-wpoos' ),
				'/video-thumbnail --video_id=123 --count=5',
				'upload_files',
				'video_production',
				array(),
				array(
					'video_id' => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'video-edit',
				'edit_omni_video',
				__( 'Edit a video with an AI prompt', 'mcp-ai-wpoos' ),
				'/video-edit --source_video_id=123 --edit_prompt="make it cinematic"',
				'upload_files',
				'video_production',
				array(),
				array(
					'edit_prompt' => array(
						'description' => __( 'Edit instruction', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
			$this->command(
				'get-video-metadata',
				'get_video_metadata',
				__( 'Inspect video metadata and streams', 'mcp-ai-wpoos' ),
				'/get-video-metadata --video_id=123',
				'upload_files',
				'video_production',
				array(
					'arg_map' => array( 'video_id' => 'attachment_id' ),
				),
				array(
					'video_id' => array(
						'description' => __( 'Video attachment ID', 'mcp-ai-wpoos' ),
						'required'    => true,
					),
				)
			),
		);
	}
}
