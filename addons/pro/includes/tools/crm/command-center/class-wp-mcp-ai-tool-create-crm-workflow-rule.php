<?php
/**
 * Create Workflow Rule — if-this-then-that automation rule.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Create Workflow Rule tool — if-this-then-that automation rule.
 *
 * Distinct from the regulatory-registration `WP_MCP_AI_Tool_Create_Workflow_Rule`
 * (registration lifecycle automation): both files originally declared the same
 * class name, which fataled whenever both were loaded in one process.
 */
class WP_MCP_AI_Tool_Create_Crm_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'create_crm_workflow_rule'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Workflow Rule', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create an if-this-then-that automation rule for the CRM Workflow Command Center.', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the JSON Schema for the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'       => array( 'type' => 'string' ),
				'trigger'    => array(
					'type' => 'string',
					'enum' => array( 'inbound_message_received', 'lead_score_exceeds', 'lead_status_changed', 'deal_stage_changed', 'activity_due', 'consent_revoked', 'lead_assigned' ),
				),
				'conditions' => array(
					'type'        => 'object',
					'description' => 'Optional key-value filters',
				),
				'actions'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'type'   => array(
								'type' => 'string',
								'enum' => array( 'send_email', 'send_sms', 'send_whatsapp', 'create_activity', 'assign_owner', 'enroll_sequence', 'update_lead', 'notify_owner' ),
							),
							'params' => array( 'type' => 'object' ),
						),
					),
				),
				'is_active'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'required'   => array( 'name', 'trigger', 'actions' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }
		$name       = sanitize_text_field( $arguments['name'] );
		$trigger    = sanitize_key( $arguments['trigger'] );
		$actions    = $arguments['actions'] ?? array();
		$conditions = $arguments['conditions'] ?? array();
		$rule_id    = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_wf_rule',
				'post_title'  => $name,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $rule_id ) ) {
			return $rule_id; }
		update_post_meta( $rule_id, 'trigger', $trigger );
		update_post_meta( $rule_id, 'conditions', $conditions );
		update_post_meta( $rule_id, 'actions', $actions );
		update_post_meta( $rule_id, 'is_active', ! empty( $arguments['is_active'] ) ? '1' : '0' );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'workflow_rule_created', 'workflow_rule', $rule_id ); }
		return array(
			'success'      => true,
			'message'      => __( 'Workflow rule created.', 'mcp-ai-wpoos-pro' ),
			'rule_id'      => $rule_id,
			'trigger'      => $trigger,
			'action_count' => count( $actions ),
		);
	}
}
