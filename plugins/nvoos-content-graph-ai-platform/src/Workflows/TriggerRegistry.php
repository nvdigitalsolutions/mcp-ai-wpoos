<?php
/**
 * Workflow Trigger Registry (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Workflow_Trigger_Registry`:
 * byte-identical singleton lifecycle, the `register()` contract
 * (sanitize_key'd type + wp_parse_args defaults), the
 * `get_triggers()`/`get_trigger()` lookups, the
 * `wp_mcp_ai_register_workflow_triggers` extension action, and the
 * seven built-in trigger definitions with their labels, descriptions,
 * and config schemas.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - No standalone hook registration — the registry instantiates on
 *    demand (same lazy singleton contract as the base); its consumers
 *    (trigger REST/admin surfaces) land with E-UI-2.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Registry for workflow trigger type definitions.
 *
 * @since 2.1.0
 */
class TriggerRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
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
		 * @param TriggerRegistry $registry The registry instance.
		 */
		do_action( 'wp_mcp_ai_register_workflow_triggers', $this );
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
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
	public function register( $type, array $config ): void {
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
	private function register_built_ins(): void {
		$this->register(
			'post_status_change',
			array(
				'label'       => __( 'Post Status Change', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when a post transitions between statuses.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(
					'post_type'   => array(
						'type'        => 'string',
						'description' => __( 'Post type slug.', 'nvoos-content-graph-ai-platform' ),
					),
					'from_status' => array(
						'type'        => 'string',
						'description' => __( 'Previous post status (or * for any).', 'nvoos-content-graph-ai-platform' ),
					),
					'to_status'   => array(
						'type'        => 'string',
						'description' => __( 'New post status (or * for any).', 'nvoos-content-graph-ai-platform' ),
					),
				),
			)
		);

		$this->register(
			'cron_schedule',
			array(
				'label'       => __( 'Cron Schedule', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires on a recurring WordPress cron schedule.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(
					'schedule' => array(
						'type'        => 'string',
						'enum'        => array( 'hourly', 'twicedaily', 'daily', 'weekly' ),
						'description' => __( 'Cron recurrence identifier.', 'nvoos-content-graph-ai-platform' ),
					),
				),
			)
		);

		$this->register(
			'rest_webhook',
			array(
				'label'       => __( 'REST Webhook', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when an external caller POSTs to the generated webhook endpoint.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'a2a_inbound',
			array(
				'label'       => __( 'A2A Inbound Message', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when an Agent-to-Agent protocol message is received.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'user_registration',
			array(
				'label'       => __( 'User Registration', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when a new user registers on the site.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'comment_published',
			array(
				'label'       => __( 'Comment Published', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when a new comment is published.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(),
			)
		);

		$this->register(
			'file_upload',
			array(
				'label'       => __( 'File Upload', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Fires when a file is uploaded to the media library.', 'nvoos-content-graph-ai-platform' ),
				'schema'      => array(),
			)
		);
	}
}
