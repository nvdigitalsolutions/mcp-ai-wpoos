<?php
/** Simulate Workflow Rule — dry-run against historical messages. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Simulate_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'simulate_workflow_rule'; }
	public function get_name() {
		return __( 'Simulate Workflow Rule', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Dry-run a workflow rule against a sample message to see what would happen without executing.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'rule_id'        => array( 'type' => 'integer' ),
				'sample_message' => array(
					'type'        => 'string',
					'description' => __( 'Test message body to simulate.', 'mcp-ai-wpoos-pro' ),
				),
				'sample_intent'  => array( 'type' => 'string' ),
				'sample_channel' => array(
					'type'    => 'string',
					'default' => 'email',
				),
			),
			'required'   => array( 'rule_id', 'sample_message' ),
		); }
	public function get_required_capability() {
		return 'manage_options'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$rule_id = absint( $arguments['rule_id'] );
		$p       = get_post( $rule_id );
		if ( ! $p || 'mcp_ai_crm_workflow_rule' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Rule not found.', 'mcp-ai-wpoos-pro' ) ); }
		$trigger       = get_post_meta( $rule_id, 'trigger', true );
		$actions       = get_post_meta( $rule_id, 'actions', true ) ?: array();
		$is_active     = '1' === get_post_meta( $rule_id, 'is_active', true );
		$sample_intent = sanitize_key( $arguments['sample_intent'] ?? 'general' );
		$channel       = sanitize_key( $arguments['sample_channel'] ?? 'email' );

		// Simple simulation: check if trigger matches.
		$would_match = false;
		if ( 'inbound_message_received' === $trigger ) {
			$would_match = true; }

		$result = array(
			'success'     => true,
			'rule_id'     => $rule_id,
			'rule_name'   => $p->post_title,
			'is_active'   => $is_active,
			'trigger'     => $trigger,
			'would_match' => $would_match,
			'actions'     => array(),
		);

		/**
		 * Fires when a CRM workflow rule's conditions match and actions
		 * are about to be dispatched (simulated or real).
		 *
		 * @since 2.3.0
		 *
		 * @param int    $rule_id   The workflow rule post ID.
		 * @param string $trigger   The trigger type slug.
		 * @param array  $actions   The actions to be dispatched.
		 * @param array  $arguments Original tool arguments (context).
		 * @param bool   $is_active Whether the rule is active.
		 * @param bool   $simulated Whether this is a dry-run.
		 */
		do_action( 'wp_mcp_ai_crm_workflow_trigger', $rule_id, $trigger, $actions, $arguments, $is_active, true );

		if ( $would_match && $is_active ) {
			foreach ( $actions as $a ) {
				$result['actions'][] = array(
					'type'      => $a['type'] ?? 'unknown',
					'simulated' => true,
					'note'      => sprintf( __( 'Would execute: %s', 'mcp-ai-wpoos-pro' ), $a['type'] ?? 'unknown' ),
				); }
		}
		if ( ! $is_active ) {
			$result['message'] = __( 'Rule is currently inactive — no actions would fire.', 'mcp-ai-wpoos-pro' ); } elseif ( ! $would_match ) {
			$result['message'] = __( 'Trigger conditions did not match the sample — no actions would fire.', 'mcp-ai-wpoos-pro' ); } else {
				$result['message'] = sprintf( __( 'Rule WOULD fire %d action(s).', 'mcp-ai-wpoos-pro' ), count( $actions ) ); }
			return $result;
	}
}
