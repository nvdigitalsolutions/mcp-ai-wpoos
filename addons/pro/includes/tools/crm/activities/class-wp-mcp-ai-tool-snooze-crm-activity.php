<?php
/**
 * Snooze CRM Activity Tool
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Snooze CRM Activity — snooze a CRM activity to a future date.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Snooze_CRM_Activity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'snooze_crm_activity'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Snooze CRM Activity', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Snooze a CRM activity to a future date.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'activity_id'  => array( 'type' => 'integer' ),
				'new_due_date' => array(
					'type'        => 'string',
					'description' => __( 'New due date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),
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
		return array( 'pro', 'database-write', 'requires-capability' ); }

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

		$id = absint( $arguments['activity_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_crm_activity' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'mcp-ai-wpoos-pro' ) ); }

		$new_due = ! empty( $arguments['new_due_date'] ) ? sanitize_text_field( $arguments['new_due_date'] ) : gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		update_post_meta( $id, 'due_date', $new_due );
		$snooze_count = (int) get_post_meta( $id, 'snooze_count', true ) + 1;
		update_post_meta( $id, 'snooze_count', $snooze_count );

		return array(
			'success'      => true,
			'message'      => __( 'Activity snoozed.', 'mcp-ai-wpoos-pro' ),
			'activity_id'  => $id,
			'new_due_date' => $new_due,
			'snooze_count' => $snooze_count,
		);
	}
}
