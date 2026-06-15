<?php
/**
 * Pro tool: Update a Pro Schedule.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Provides an AI tool for updating an existing pro scheduled task.
 */
class WP_MCP_AI_Pro_Tool_Update_Pro_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_pro_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Pro Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing named pro schedule. Supports partial updates: only provided fields are modified.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$valid_schedules = array_keys( wp_get_schedules() );
		sort( $valid_schedules );
		$valid_schedules = array_merge( array( 'single' ), $valid_schedules );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id'       => array(
					'type'        => 'string',
					'description' => __( 'Unique ID of the schedule to update (returned by create_pro_schedule).', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'New human-readable label.', 'mcp-ai-wpoos-pro' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'New description.', 'mcp-ai-wpoos-pro' ),
				),
				'schedule'          => array(
					'type'        => 'string',
					'enum'        => $valid_schedules,
					'description' => __( 'New recurrence interval.', 'mcp-ai-wpoos-pro' ),
				),
				'timestamp'         => array(
					'type'        => 'integer',
					'description' => __( 'New next-run Unix timestamp. Must be in the future.', 'mcp-ai-wpoos-pro' ),
				),
				'enabled'           => array(
					'type'        => 'boolean',
					'description' => __( 'Enable or disable the schedule.', 'mcp-ai-wpoos-pro' ),
				),
				'priority'          => array(
					'type'        => 'integer',
					'description' => __( 'New display priority (1-10).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'tags'              => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Replace all tags with this new list.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_on_failure' => array(
					'type'        => 'boolean',
					'description' => __( 'Toggle failure notifications.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_email'      => array(
					'type'        => 'string',
					'description' => __( 'New failure notification email.', 'mcp-ai-wpoos-pro' ),
				),
				'max_retries'       => array(
					'type'        => 'integer',
					'description' => __( 'New maximum retry count (0-5).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'retry_delay'       => array(
					'type'        => 'integer',
					'description' => __( 'New retry delay in seconds. Minimum 60.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
				),
				'timeout'           => array(
					'type'        => 'integer',
					'description' => __( 'New maximum execution time in seconds. 0 = no limit.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'callback_url'      => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'New external webhook callback URL. Empty string to remove.', 'mcp-ai-wpoos-pro' ),
				),
				'callback_secret'   => array(
					'type'        => 'string',
					'description' => __( 'HMAC-SHA256 signing secret for the callback. Empty string to remove. When set, every webhook POST includes X-WP-MCP-AI-Signature: sha256=<hex> computed over "<timestamp>.<body>". The X-WP-MCP-AI-Timestamp header contains the Unix timestamp; receivers should reject requests where that timestamp is more than 300 seconds old to prevent replay attacks.', 'mcp-ai-wpoos-pro' ),
				),
				'display'           => array(
					'type'        => 'object',
					'description' => __( 'Display / widget-binding settings consumed by the Scheduled Result block & Elementor widget.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'result_capture'   => array(
							'type' => 'string',
							'enum' => array( 'disabled', 'summary', 'full' ),
						),
						'public_render'    => array( 'type' => 'boolean' ),
						'public_fields'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'result_retention' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'widget_defaults'  => array(
							'type'       => 'object',
							'properties' => array(
								'render_mode'      => array(
									'type' => 'string',
									'enum' => array( 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' ),
								),
								'title'            => array( 'type' => 'string' ),
								'refresh_interval' => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 3600,
								),
							),
						),
					),
				),
			),
			'required'             => array( 'schedule_id' ),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}


	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id     = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$schedule_id = isset( $arguments['schedule_id'] ) ? sanitize_text_field( $arguments['schedule_id'] ) : '';

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to update schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to update schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $schedule_id ) {
			return new WP_Error( 'missing_id', __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Remove schedule_id from fields to update.
		unset( $arguments['schedule_id'] );

		$result = WP_MCP_AI_Pro_Schedule_Manager::update_schedule( $schedule_id, $arguments, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule_id );

		return array(
			'schedule_id' => $schedule_id,
			'name'        => $schedule['name'],
			'hook'        => $schedule['hook'],
			'schedule'    => $schedule['schedule'],
			'enabled'     => $schedule['enabled'],
			'next_run'    => $next_run ? wp_date( DATE_ATOM, $next_run ) : null,
			'message'     => __( 'Pro schedule updated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'requires-capability',
			'state-changing',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.0.0
	 * @return array Extended tool definition.
	 */
	public function get_extended_definition() {
		return array(
			'name'              => $this->get_name(),
			'slug'              => $this->get_slug(),
			'description'       => $this->get_description(),
			'parameters_schema' => $this->get_parameters_schema(),
			'capability_flags'  => $this->get_capability_flags(),
			'toolkit'           => 'schedule-manager',
			'category'          => 'automation',
			'risk_level'        => 'standard',
		);
	}
}
