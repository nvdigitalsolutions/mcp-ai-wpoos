<?php
/**
 * Complete Crm Activity Tool (ecosystem port — Wave F2, CRM activities batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/activities/class-wp-mcp-ai-tool-complete-crm-activity.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
* Complete CRM Activity Tool
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Complete CRM Activity Tool
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Complete_CRM_Activity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' ); }

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'complete_crm_activity'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Complete CRM Activity', 'nvoos-content-graph-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Mark a CRM activity as completed.', 'nvoos-content-graph-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'activity_id' => array( 'type' => 'integer' ),
				'outcome'     => array( 'type' => 'string' ),
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
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) ); }

		$id = absint( $arguments['activity_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_crm_activity' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'nvoos-content-graph-pro' ) ); }

		update_post_meta( $id, 'completed', '1' );
		update_post_meta( $id, 'completed_at', gmdate( 'c' ) );
		if ( ! empty( $arguments['outcome'] ) ) {
			update_post_meta( $id, 'outcome', sanitize_textarea_field( $arguments['outcome'] ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'activity_completed', 'activity', $id ); }

		return array(
			'success'     => true,
			'message'     => __( 'Activity marked as completed.', 'nvoos-content-graph-pro' ),
			'activity_id' => $id,
		);
	}
}
