<?php
/**
 * List / Update / Delete Workflow Rules.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Manage Workflow Rules tool — list, update, or delete workflow automation rules.
 */
class WP_MCP_AI_Tool_Manage_Workflow_Rules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_workflow_rules'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage Workflow Rules', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List, update, or delete workflow automation rules.', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the JSON Schema for the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'    => array(
					'type' => 'string',
					'enum' => array( 'list', 'update', 'delete', 'toggle' ),
				),
				'rule_id'   => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'is_active' => array( 'type' => 'boolean' ),
				'per_page'  => array(
					'type'    => 'integer',
					'default' => 20,
				),
			),
			'required'   => array( 'action' ),
		); }
	/**
	 * Get the required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options'; }
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
		return array( 'pro', 'database-write', 'requires-capability' ); }
	/**
	 * Execute the tool.
	 *
	 * @param array $arguments The tool arguments.
	 * @param array $context   The execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = sanitize_key( $arguments['action'] );
		if ( 'list' === $action ) {
			$q     = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_crm_wf_rule',
					'post_status'    => 'publish',
					'posts_per_page' => min( 100, absint( $arguments['per_page'] ?? 20 ) ),
					'paged'          => max( 1, absint( $arguments['page'] ?? 1 ) ),
				)
			);
			$rules = array();
			foreach ( $q->posts as $p ) {
				$rules[] = array(
					'id'        => $p->ID,
					'name'      => $p->post_title,
					'trigger'   => get_post_meta( $p->ID, 'trigger', true ),
					'is_active' => '1' === get_post_meta( $p->ID, 'is_active', true ),
					'actions'   => get_post_meta( $p->ID, 'actions', true ),
				); }
			return array(
				'success' => true,
				'rules'   => $rules,
				'total'   => $q->found_posts,
			);
		}
		$rule_id = absint( $arguments['rule_id'] ?? 0 );
		$p       = get_post( $rule_id );
		if ( ! $p || 'mcp_ai_crm_wf_rule' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Rule not found.', 'mcp-ai-wpoos-pro' ) ); }
		switch ( $action ) {
			case 'update':
				if ( ! empty( $arguments['name'] ) ) {
					wp_update_post(
						array(
							'ID'         => $rule_id,
							'post_title' => sanitize_text_field( $arguments['name'] ),
						)
					);
				} $m = __( 'Rule updated.', 'mcp-ai-wpoos-pro' );
				break;
			case 'delete':
				wp_trash_post( $rule_id );
				$m = __( 'Rule deleted.', 'mcp-ai-wpoos-pro' );
				break;
			case 'toggle':
				$curr = '1' === get_post_meta( $rule_id, 'is_active', true );
				update_post_meta( $rule_id, 'is_active', $curr ? '0' : '1' );
				$m = $curr ? __( 'Rule disabled.', 'mcp-ai-wpoos-pro' ) : __( 'Rule enabled.', 'mcp-ai-wpoos-pro' );
				break;
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
		return array(
			'success' => true,
			'message' => $m,
			'rule_id' => $rule_id,
		);
	}
}
