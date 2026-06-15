<?php
/**
 * Get CRM Activity Tool
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Get CRM Activity — retrieve a single CRM activity by ID.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Get_CRM_Activity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'get_crm_activity'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get CRM Activity', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve a single CRM activity by ID.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'activity_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'activity_id' ),
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$id = absint( $arguments['activity_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_crm_activity' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'mcp-ai-wpoos-pro' ) ); }

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'activity_viewed', 'activity', $id ); }

		return array(
			'success'  => true,
			'activity' => array(
				'id'            => $p->ID,
				'title'         => $p->post_title,
				'body'          => $p->post_content,
				'activity_type' => sanitize_key( (string) get_post_meta( $p->ID, 'activity_type', true ) ),
				'related_type'  => sanitize_key( (string) get_post_meta( $p->ID, 'related_type', true ) ),
				'related_id'    => (int) get_post_meta( $p->ID, 'related_id', true ),
				'due_date'      => sanitize_text_field( (string) get_post_meta( $p->ID, 'due_date', true ) ),
				'disposition'   => sanitize_key( (string) get_post_meta( $p->ID, 'disposition', true ) ),
				'assigned_to'   => (int) get_post_meta( $p->ID, 'assigned_to', true ),
				'created_date'  => $p->post_date,
			),
		);
	}
}
