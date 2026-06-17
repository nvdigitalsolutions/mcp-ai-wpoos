<?php
/**
 * Create Outreach Sequence — defines a multi-step outreach cadence.
 * Steps: ordered list of { channel, template_id, wait_hours, branch_on_reply }.
 * @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Create_Outreach_Sequence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'create_outreach_sequence'; }
	public function get_name() {
		return __( 'Create Outreach Sequence', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Define a multi-step outreach cadence with channel, timing, and branching rules.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'steps'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'channel'         => array(
								'type' => 'string',
								'enum' => WP_MCP_AI_CRM_Codes::CHANNELS,
							),
							'template_id'     => array( 'type' => 'string' ),
							'wait_hours'      => array(
								'type'    => 'integer',
								'default' => 24,
							),
							'branch_on_reply' => array(
								'type'    => 'boolean',
								'default' => true,
							),
						),
					),
				),
			),
			'required'   => array( 'name', 'steps' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }
		$name  = sanitize_text_field( $arguments['name'] );
		$steps = $arguments['steps'] ?? array();
		if ( empty( $steps ) ) {
			return new WP_Error( 'no_steps', __( 'At least one step is required.', 'mcp-ai-wpoos-pro' ) ); }
		// Sanitise steps.
		$clean = array();
		foreach ( $steps as $i => $s ) {
			$clean[] = array(
				'order'           => $i + 1,
				'channel'         => sanitize_key( $s['channel'] ?? 'email' ),
				'template_id'     => sanitize_key( $s['template_id'] ?? '' ),
				'wait_hours'      => absint( $s['wait_hours'] ?? 24 ),
				'branch_on_reply' => ! empty( $s['branch_on_reply'] ),
			); }
		$seq_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_sequence',
				'post_title'   => $name,
				'post_content' => sanitize_textarea_field( $arguments['description'] ?? '' ),
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $seq_id ) ) {
			return $seq_id; }
		update_post_meta( $seq_id, 'steps', $clean );
		update_post_meta( $seq_id, 'step_count', count( $clean ) );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'sequence_created', 'sequence', $seq_id ); }
		return array(
			'success'     => true,
			'message'     => __( 'Sequence created.', 'mcp-ai-wpoos-pro' ),
			'sequence_id' => $seq_id,
			'step_count'  => count( $clean ),
		);
	}
}
