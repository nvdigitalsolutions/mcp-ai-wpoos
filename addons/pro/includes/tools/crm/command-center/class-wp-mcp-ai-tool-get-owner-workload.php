<?php
/** Get Owner Workload — active leads + overdue tasks + response SLA. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Get_Owner_Workload implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'get_owner_workload'; }
	public function get_name() {
		return __( 'Get Owner Workload', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'View active lead count, overdue tasks, and response SLAs per owner.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'owner_id' => array(
					'type'        => 'integer',
					'description' => __( 'Specific owner or omit for all in routing pool.', 'mcp-ai-wpoos-pro' ),
				),
			),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$owner_id = isset( $arguments['owner_id'] ) ? absint( $arguments['owner_id'] ) : 0;
		if ( $owner_id ) {
			$pool = array( $owner_id ); } else {
			$settings = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_toolkit_settings() : array();
			$pool     = isset( $settings['routing']['pool'] ) ? array_filter( (array) $settings['routing']['pool'], 'absint' ) : array(); }
			if ( empty( $pool ) ) {
				$pool  = array();
				$users = get_users(
					array(
						'capability' => 'edit_posts',
						'number'     => 20,
					)
				);
				foreach ( $users as $u ) {
							$pool[] = $u->ID; }
			}

			$workloads = array();
			foreach ( $pool as $uid ) {
				$active      = WP_MCP_AI_CRM_Engine::count_active_leads( $uid );
				$odue        = new WP_Query(
					array(
						'post_type'      => 'mcp_ai_crm_activity',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'meta_query'     => array(
							array(
								'key'     => 'due_date',
								'value'   => gmdate( 'Y-m-d' ),
								'compare' => '<',
								'type'    => 'DATE',
							),
							array(
								'key'   => 'assigned_to',
								'value' => $uid,
							),
							array(
								'key'     => 'completed',
								'compare' => 'NOT EXISTS',
							),
						),
						'no_found_rows'  => false,
					)
				);
				$u           = get_userdata( $uid );
				$workloads[] = array(
					'owner_id'      => $uid,
					'owner_name'    => $u ? $u->display_name : (string) $uid,
					'active_leads'  => $active,
					'overdue_tasks' => $odue->found_posts,
				);
			}
			return array(
				'success'             => true,
				'workloads'           => $workloads,
				'total_owners'        => count( $workloads ),
				'total_active_leads'  => array_sum( array_column( $workloads, 'active_leads' ) ),
				'total_overdue_tasks' => array_sum( array_column( $workloads, 'overdue_tasks' ) ),
			);
	}
}
