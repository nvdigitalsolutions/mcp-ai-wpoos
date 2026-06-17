<?php
/** Update Outreach Sequence @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Update_Outreach_Sequence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'update_outreach_sequence'; }
	public function get_name() {
		return __( 'Update Outreach Sequence', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Update an existing outreach sequence name, description, or steps.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'sequence_id' => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'steps'       => array( 'type' => 'array' ),
			),
			'required'   => array( 'sequence_id' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$id = absint( $arguments['sequence_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_sequence' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) ); }
		if ( ! empty( $arguments['name'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( $arguments['name'] ),
				)
			); }
		if ( isset( $arguments['description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => sanitize_textarea_field( $arguments['description'] ),
				)
			); }
		if ( isset( $arguments['steps'] ) ) {
			$clean = array();
			foreach ( $arguments['steps'] as $i => $s ) {
				$clean[] = array(
					'order'           => $i + 1,
					'channel'         => sanitize_key( $s['channel'] ?? 'email' ),
					'template_id'     => sanitize_key( $s['template_id'] ?? '' ),
					'wait_hours'      => absint( $s['wait_hours'] ?? 24 ),
					'branch_on_reply' => ! empty( $s['branch_on_reply'] ),
				);
			} update_post_meta( $id, 'steps', $clean );
			update_post_meta( $id, 'step_count', count( $clean ) ); }
		return array(
			'success'     => true,
			'message'     => __( 'Sequence updated.', 'mcp-ai-wpoos-pro' ),
			'sequence_id' => $id,
		);
	}
}
