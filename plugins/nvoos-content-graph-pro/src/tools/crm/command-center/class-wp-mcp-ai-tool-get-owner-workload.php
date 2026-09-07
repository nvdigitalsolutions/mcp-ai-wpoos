<?php
/**
 * Get Owner Workload Tool (ecosystem port — Wave F2, CRM workflow-rules
 * + routing batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/command-center/class-wp-mcp-ai-tool-get-owner-workload.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Get Owner Workload tool — active leads + overdue tasks + response SLA.
 */
class WP_MCP_AI_Tool_Get_Owner_Workload implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_owner_workload'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Owner Workload', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'View active lead count, overdue tasks, and response SLAs per owner.', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the JSON Schema for the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'owner_id' => array(
					'type'        => 'integer',
					'description' => __( 'Specific owner or omit for all in routing pool.', 'nvoos-content-graph-pro' ),
				),
			),
		); }
	/**
	 * Get the required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }
	/**
	 * Check whether this tool requires the base Pro version.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }
	/**
	 * Get capability flags for the tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	/**
	 * Execute the tool.
	 *
	 * @param array $arguments The tool arguments.
	 * @param array $context   The execution context.
	 * @return array|WP_Error
	 */
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
