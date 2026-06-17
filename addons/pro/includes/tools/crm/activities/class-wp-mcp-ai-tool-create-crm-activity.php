<?php
/**
 * Create CRM Activity Tool
 *
 * Logs a sales activity (call, email, meeting, task, note) against a lead or deal.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Tool_Create_CRM_Activity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	public static function get_unavailable_reason() {
		return __( 'The Create CRM Activity tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	public function get_slug() {
		return 'create_crm_activity'; }
	public function get_name() {
		return __( 'Create CRM Activity', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Log a sales activity (call, email, meeting, task, note) against a lead or deal.', 'mcp-ai-wpoos-pro' ); }

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Activity summary / subject.', 'mcp-ai-wpoos-pro' ),
				),
				'activity_type' => array(
					'type'        => 'string',
					'enum'        => WP_MCP_AI_CRM_Activity_CPT::ACTIVITY_TYPES,
					'description' => __( 'Type of activity.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'note',
				),
				'related_type'  => array(
					'type'        => 'string',
					'enum'        => array( 'lead', 'deal', 'contact' ),
					'description' => __( 'Related entity type.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'lead',
				),
				'related_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Post ID of the related lead/deal/contact.', 'mcp-ai-wpoos-pro' ),
				),
				'body'          => array(
					'type'        => 'string',
					'description' => __( 'Activity notes / body.', 'mcp-ai-wpoos-pro' ),
				),
				'due_date'      => array(
					'type'        => 'string',
					'description' => __( 'Due date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'disposition'   => array(
					'type'        => 'string',
					'description' => __( 'Call/meeting disposition slug.', 'mcp-ai-wpoos-pro' ),
				),
				'assigned_to'   => array(
					'type'        => 'integer',
					'description' => __( 'WP user ID the activity is assigned to.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'title' ),
		);
	}

	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_unavailable', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$title         = sanitize_text_field( $arguments['title'] );
		$activity_type = isset( $arguments['activity_type'] ) ? sanitize_key( $arguments['activity_type'] ) : 'note';
		if ( ! in_array( $activity_type, WP_MCP_AI_CRM_Activity_CPT::ACTIVITY_TYPES, true ) ) {
			$activity_type = 'note';
		}

		$related_type = isset( $arguments['related_type'] ) ? sanitize_key( $arguments['related_type'] ) : 'lead';
		$related_id   = isset( $arguments['related_id'] ) ? absint( $arguments['related_id'] ) : 0;

		$post_data = array(
			'post_type'    => 'mcp_ai_crm_activity',
			'post_title'   => $title,
			'post_content' => isset( $arguments['body'] ) ? sanitize_textarea_field( $arguments['body'] ) : '',
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		$activity_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $activity_id ) ) {
			return new WP_Error( 'create_failed', $activity_id->get_error_message() );
		}

		update_post_meta( $activity_id, 'activity_type', $activity_type );
		if ( $related_id ) {
			update_post_meta( $activity_id, 'related_type', $related_type );
			update_post_meta( $activity_id, 'related_id', $related_id );
		}
		if ( ! empty( $arguments['due_date'] ) ) {
			update_post_meta( $activity_id, 'due_date', sanitize_text_field( $arguments['due_date'] ) );
		}
		if ( ! empty( $arguments['disposition'] ) ) {
			update_post_meta( $activity_id, 'disposition', sanitize_key( $arguments['disposition'] ) );
		}
		if ( ! empty( $arguments['assigned_to'] ) ) {
			update_post_meta( $activity_id, 'assigned_to', absint( $arguments['assigned_to'] ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'activity_created', 'activity', $activity_id, array( 'type' => $activity_type ) );
		}

		return array(
			'success'     => true,
			'message'     => __( 'Activity logged successfully.', 'mcp-ai-wpoos-pro' ),
			'activity_id' => $activity_id,
		);
	}
}
