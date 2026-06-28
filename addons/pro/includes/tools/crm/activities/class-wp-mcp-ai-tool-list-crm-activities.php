<?php
/**
 * List CRM Activities Tool
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * List CRM Activities Tool
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_List_CRM_Activities implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	/**
	 * Reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'list_crm_activities'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'List CRM Activities', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List and filter CRM activities.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'activity_type' => array(
					'type'    => 'string',
					'enum'    => array_merge( array( '' ), WP_MCP_AI_CRM_Activity_CPT::ACTIVITY_TYPES ),
					'default' => '',
				),
				'related_type'  => array(
					'type'    => 'string',
					'enum'    => array( '', 'lead', 'deal', 'contact' ),
					'default' => '',
				),
				'related_id'    => array( 'type' => 'integer' ),
				'assigned_to'   => array( 'type' => 'integer' ),
				'due_before'    => array( 'type' => 'string' ),
				'completed'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'per_page'      => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'          => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
			),
		);
	}
	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }

	/**
	 * Whether this tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }

	/**
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$per_page = min( 100, max( 1, absint( $arguments['per_page'] ?? 20 ) ) );
		$page     = max( 1, absint( $arguments['page'] ?? 1 ) );

		$args = array(
			'post_type'      => 'mcp_ai_crm_activity',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_q = array( 'relation' => 'AND' );

		if ( ! empty( $arguments['activity_type'] ) ) {
			$meta_q[] = array(
				'key'   => 'activity_type',
				'value' => sanitize_key( $arguments['activity_type'] ),
			);
		}
		if ( ! empty( $arguments['related_type'] ) ) {
			$meta_q[] = array(
				'key'   => 'related_type',
				'value' => sanitize_key( $arguments['related_type'] ),
			);
		}
		if ( ! empty( $arguments['related_id'] ) ) {
			$meta_q[] = array(
				'key'   => 'related_id',
				'value' => absint( $arguments['related_id'] ),
				'type'  => 'NUMERIC',
			);
		}
		if ( ! empty( $arguments['assigned_to'] ) ) {
			$meta_q[] = array(
				'key'   => 'assigned_to',
				'value' => absint( $arguments['assigned_to'] ),
				'type'  => 'NUMERIC',
			);
		}
		if ( ! empty( $arguments['completed'] ) ) {
			$meta_q[] = array(
				'key'   => 'completed',
				'value' => '1',
			);
		}
		if ( count( $meta_q ) > 1 ) { $args['meta_query'] = $meta_q; } // phpcs:ignore

		$q          = new WP_Query( $args );
		$activities = array();
		foreach ( $q->posts as $p ) {
			$activities[] = array(
				'id'            => $p->ID,
				'title'         => $p->post_title,
				'activity_type' => sanitize_key( (string) get_post_meta( $p->ID, 'activity_type', true ) ),
				'related_type'  => sanitize_key( (string) get_post_meta( $p->ID, 'related_type', true ) ),
				'related_id'    => (int) get_post_meta( $p->ID, 'related_id', true ),
				'due_date'      => sanitize_text_field( (string) get_post_meta( $p->ID, 'due_date', true ) ),
				'disposition'   => sanitize_key( (string) get_post_meta( $p->ID, 'disposition', true ) ),
				'assigned_to'   => (int) get_post_meta( $p->ID, 'assigned_to', true ),
				'created_date'  => $p->post_date,
			);
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'activities_listed', 'activity' ); }

		return array(
			'success'    => true,
			'activities' => $activities,
			'total'      => $q->found_posts,
			'per_page'   => $per_page,
			'page'       => $page,
			'pages'      => max( 1, $q->max_num_pages ),
		);
	}
}
