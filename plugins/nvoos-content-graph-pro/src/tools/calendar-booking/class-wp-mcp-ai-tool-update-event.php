<?php
/**
 * Calendar event tool (ecosystem port — Wave F2, calendar event batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-update-event.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing event.
 */
class WP_MCP_AI_Tool_Update_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_event';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Update Event', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Updates an existing calendar event. Provide only the fields you want to update.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Event ID to update (required)', 'nvoos-content-graph-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'New event title (optional)', 'nvoos-content-graph-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New event description (optional)', 'nvoos-content-graph-pro' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'New start date (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'  => array(
					'type'        => 'string',
					'description' => __( 'New start time (HH:MM) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'New end date (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_time'    => array(
					'type'        => 'string',
					'description' => __( 'New end time (HH:MM) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'location'    => array(
					'type'        => 'string',
					'description' => __( 'New event location (optional)', 'nvoos-content-graph-pro' ),
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'New event type (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
				),
				'attendees'   => array(
					'type'        => 'array',
					'description' => __( 'New array of attendee user IDs (optional)', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'event_management',
			'post_type'             => 'mcp_ai_event',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'event_manager', 'project_manager' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
		);
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update events.', 'nvoos-content-graph-pro' ) );
		}

		$event_id = isset( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;

		if ( ! $event_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Event ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$event = get_post( $event_id );
		if ( ! $event || 'mcp_ai_event' !== $event->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_event', __( 'Invalid event ID.', 'nvoos-content-graph-pro' ) );
		}

		$post_data = array( 'ID' => $event_id );

		if ( isset( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}

		if ( isset( $arguments['start_date'] ) ) {
			update_post_meta( $event_id, '_event_start_date', sanitize_text_field( $arguments['start_date'] ) );
		}

		if ( isset( $arguments['start_time'] ) ) {
			update_post_meta( $event_id, '_event_start_time', sanitize_text_field( $arguments['start_time'] ) );
		}

		if ( isset( $arguments['end_date'] ) ) {
			update_post_meta( $event_id, '_event_end_date', sanitize_text_field( $arguments['end_date'] ) );
		}

		if ( isset( $arguments['end_time'] ) ) {
			update_post_meta( $event_id, '_event_end_time', sanitize_text_field( $arguments['end_time'] ) );
		}

		if ( isset( $arguments['location'] ) ) {
			update_post_meta( $event_id, '_event_location', sanitize_text_field( $arguments['location'] ) );
		}

		if ( isset( $arguments['type'] ) ) {
			update_post_meta( $event_id, '_event_type', sanitize_key( $arguments['type'] ) );
		}

		if ( isset( $arguments['attendees'] ) ) {
			update_post_meta( $event_id, '_event_attendees', array_map( 'absint', $arguments['attendees'] ) );
		}

		return array(
			'success'  => true,
			'message'  => __( 'Event updated successfully.', 'nvoos-content-graph-pro' ),
			'event_id' => $event_id,
		);
	}
}
