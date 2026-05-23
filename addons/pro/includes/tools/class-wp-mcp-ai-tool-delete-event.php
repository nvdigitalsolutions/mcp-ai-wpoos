<?php
/**
 * Tool for deleting events.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an event.
 */
class WP_MCP_AI_Tool_Delete_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'delete_event';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Delete Event', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Deletes a calendar event permanently.', 'mcp-ai-wpoos-pro' );
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
				'event_id' => array(
					'type'        => 'integer',
					'description' => __( 'Event ID to delete (required)', 'mcp-ai-wpoos-pro' ),
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
			'risk_level'            => 'high',
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
			'destructive',
		);
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete events.', 'mcp-ai-wpoos-pro' ) );
		}

		$event_id = isset( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;

		if ( ! $event_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Event ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$event = get_post( $event_id );
		if ( ! $event || 'mcp_ai_event' !== $event->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_event', __( 'Invalid event ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $event_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete event.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'  => true,
			'message'  => __( 'Event deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'event_id' => $event_id,
		);
	}
}
