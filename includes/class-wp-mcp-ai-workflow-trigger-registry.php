<?php
/**
 * Workflow Trigger Registry.
 *
 * Manages registered trigger types for the workflow automation system.
 * Provides a central registry of built-in and custom trigger types.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for workflow trigger type definitions.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Workflow_Trigger_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Workflow_Trigger_Registry|null
	 */
	private static $instance = null;

	/**
	 * Registered trigger definitions keyed by type slug.
	 *
	 * @var array
	 */
	private $triggers = array();

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {
		$this->register_built_ins();
		/**
		 * Fires after built-in triggers are registered, allowing third-party code
		 * to add custom trigger types.
		 *
		 * @since 2.2.0
		 *
		 * @param WP_MCP_AI_Workflow_Trigger_Registry $registry The registry instance.
		 */
		do_action( 'wp_mcp_ai_register_workflow_triggers', $this );
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return WP_MCP_AI_Workflow_Trigger_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a trigger type.
	 *
	 * @param string $type   Unique trigger type key (e.g. 'post_status_change').
	 * @param array  $config {
	 *     Trigger configuration.
	 *
	 *     @type string $label         Human-readable label.
	 *     @type string $description   Description of what fires this trigger.
	 *     @type string $handler_class Class name of the trigger adapter (optional).
	 *     @type array  $schema        JSON Schema of the type-specific config fields.
	 * }
	 * @return void
	 */
	public function register( $type, array $config ) {
		$type = sanitize_key( $type );
		if ( empty( $type ) ) {
			return;
		}
		$this->triggers[ $type ] = wp_parse_args(
			$config,
			array(
				'label'         => $type,
				'description'   => '',
				'handler_class' => '',
				'schema'        => array(),
			)
		);
	}

	/**
	 * Retrieve all registered trigger definitions.
	 *
	 * @return array
	 */
	public function get_triggers() {
		return $this->triggers;
	}

	/**
	 * Retrieve a single trigger definition.
	 *
	 * @param string $type Trigger type key.
	 * @return array|false The definition array or false if not found.
	 */
	public function get_trigger( $type ) {
		$type = sanitize_key( $type );
		return isset( $this->triggers[ $type ] ) ? $this->triggers[ $type ] : false;
	}

	/**
	 * Register all built-in trigger types.
	 *
	 * @return void
	 */
	private function register_built_ins() {
		$this->register(
			'post_status_change',
			array(
				'label'       => __( 'Post Status Change', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when a post transitions between statuses.', 'mcp-ai-wpoos' ),
				'schema'      => array(
					'post_type'   => array( 'type' => 'string', 'description' => __( 'Post type slug.', 'mcp-ai-wpoos' ) ),
					'from_status' => array( 'type' => 'string', 'description' => __( 'Previous post status (or * for any).', 'mcp-ai-wpoos' ) ),
					'to_status'   => array( 'type' => 'string', 'description' => __( 'New post status (or * for any).', 'mcp-ai-wpoos' ) ),
				),
			)
		);

		$this->register(
			'cron_schedule',
			array(
				'label'       => __( 'Cron Schedule', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires on a recurring WordPress cron schedule.', 'mcp-ai-wpoos' ),
				'schema'      => array(
					'schedule' => array(
						'type'        => 'string',
						'enum'        => array( 'hourly', 'twicedaily', 'daily', 'weekly' ),
						'description' => __( 'Cron recurrence identifier.', 'mcp-ai-wpoos' ),
					),
				),
			)
		);

		$this->register(
			'rest_webhook',
			array(
				'label'       => __( 'REST Webhook', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when an external caller POSTs to the generated webhook endpoint.', 'mcp-ai-wpoos' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'a2a_inbound',
			array(
				'label'       => __( 'A2A Inbound Message', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when an Agent-to-Agent protocol message is received.', 'mcp-ai-wpoos' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'user_registration',
			array(
				'label'       => __( 'User Registration', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when a new user registers on the site.', 'mcp-ai-wpoos' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'comment_published',
			array(
				'label'       => __( 'Comment Published', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when a new comment is published.', 'mcp-ai-wpoos' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'file_upload',
			array(
				'label'       => __( 'File Upload', 'mcp-ai-wpoos' ),
				'description' => __( 'Fires when a file is uploaded to the media library.', 'mcp-ai-wpoos' ),
				'schema'      => array(),
			)
		);
	}
}
