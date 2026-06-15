<?php
/**
 * Pro tool: Configure Schedule Widget Defaults.
 *
 * Updates the `display` settings on a Pro Schedule — the render mode, public
 * visibility, public-field allow-list, retention count and widget defaults
 * consumed by the Scheduled Result block/Elementor widget.
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
 * Provides an AI tool for configuring the Scheduled Result widget binding on a schedule.
 */
class WP_MCP_AI_Pro_Tool_Configure_Schedule_Widget_Defaults implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'configure_schedule_widget_defaults';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Configure Schedule Widget Defaults', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates the display/widget-binding settings on a Pro Schedule (capture mode, public visibility, public-field allow-list, retention, render defaults).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id'      => array(
					'type'        => 'string',
					'description' => __( 'ID of the schedule to configure.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'result_capture'   => array(
					'type'        => 'string',
					'enum'        => array( 'disabled', 'summary', 'full' ),
					'description' => __( 'How much of the run output to capture into the result envelope.', 'mcp-ai-wpoos-pro' ),
				),
				'public_render'    => array(
					'type'        => 'boolean',
					'description' => __( 'When true, the renderer surfaces a redacted envelope to anonymous visitors.', 'mcp-ai-wpoos-pro' ),
				),
				'public_fields'    => array(
					'type'        => 'array',
					'description' => __( 'Allow-list of dotted JSON paths exposed to the public renderer (e.g. ["summary","data.top_3"]).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'result_retention' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Number of result envelopes to retain (separate from the run-history ring buffer).', 'mcp-ai-wpoos-pro' ),
				),
				'widget_defaults'  => array(
					'type'        => 'object',
					'description' => __( 'Default render mode/title/refresh interval for the Scheduled Result block & Elementor widget.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
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
				__( 'You do not have permission to configure schedule widget defaults.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $schedule_id ) {
			return new WP_Error( 'missing_id', __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$display = array();
		foreach ( array( 'result_capture', 'public_render', 'public_fields', 'result_retention', 'widget_defaults' ) as $key ) {
			if ( array_key_exists( $key, $arguments ) ) {
				$display[ $key ] = $arguments[ $key ];
			}
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::update_schedule( $schedule_id, array( 'display' => $display ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		return array(
			'schedule_id' => $schedule_id,
			'display'     => isset( $schedule['display'] ) ? $schedule['display'] : array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability', 'pro' );
	}
}
