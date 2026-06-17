<?php
/**
 * Pro tool: Render Schedule Result.
 *
 * Returns rendered HTML for the latest scheduled-result envelope so that an
 * assistant can embed it directly in a chat reply or admin tile.
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
require_once WP_MCP_AI_PATH . 'includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Provides an AI tool for rendering a Pro schedule's latest result envelope.
 */
class WP_MCP_AI_Pro_Tool_Render_Schedule_Result implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'render_schedule_result';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Render Schedule Result', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Renders the latest run of a Pro Schedule as sanitized HTML using one of six canonical modes (summary-card, list, table, metric, timeline, raw).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id'    => array(
					'type'        => 'string',
					'description' => __( 'ID of the schedule to render.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'render_mode'    => array(
					'type'        => 'string',
					'enum'        => array( 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' ),
					'default'     => 'summary-card',
					'description' => __( 'Render mode.', 'mcp-ai-wpoos-pro' ),
				),
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Optional override for the tile title.', 'mcp-ai-wpoos-pro' ),
				),
				'show_last_run'  => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether to show the last-run timestamp.', 'mcp-ai-wpoos-pro' ),
				),
				'truncate_chars' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 4000,
					'default'     => 0,
					'description' => __( 'Soft-truncate raw text at N characters (0 = off).', 'mcp-ai-wpoos-pro' ),
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

	 * @param array $arguments Tool arguments.

	 *  * @param array $context   Execution context.
	 *
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id     = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$schedule_id = isset( $arguments['schedule_id'] ) ? sanitize_text_field( $arguments['schedule_id'] ) : '';

		if ( ! user_can( $user_id, 'read_private_posts' ) && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to render schedule results.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $schedule_id ) {
			return new WP_Error( 'missing_id', __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$opts = array(
			'render_mode'    => isset( $arguments['render_mode'] ) ? sanitize_text_field( $arguments['render_mode'] ) : 'summary-card',
			'title'          => isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '',
			'show_last_run'  => isset( $arguments['show_last_run'] ) ? (bool) $arguments['show_last_run'] : true,
			'truncate_chars' => isset( $arguments['truncate_chars'] ) ? (int) $arguments['truncate_chars'] : 0,
		);

		$html = WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id, $opts );

		return array(
			'schedule_id' => $schedule_id,
			'html'        => $html,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'requires-capability', 'pro' );
	}
}
