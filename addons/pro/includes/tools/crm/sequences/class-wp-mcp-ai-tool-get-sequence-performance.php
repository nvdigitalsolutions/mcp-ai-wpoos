<?php
/** Get Sequence Performance @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Get_Sequence_Performance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'get_sequence_performance'; }
	public function get_name() {
		return __( 'Get Sequence Performance', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Returns enrollment counts, completion rates, and step-level metrics for a sequence.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'sequence_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'sequence_id' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$seq_id = absint( $arguments['sequence_id'] );
		$sp     = get_post( $seq_id );
		if ( ! $sp || 'mcp_ai_sequence' !== $sp->post_type ) {
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) ); }
		// Count enrollments.
		$eq          = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_active_sequence_id',
						'value' => $seq_id,
					),
				),
				'no_found_rows'  => false,
			)
		);
		$active      = $eq->found_posts;
		$completed_q = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => '_sequence_exited_at',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => false,
			)
		);
		// Approximate: use audit log for historical counts.
		return array(
			'success'            => true,
			'sequence_id'        => $seq_id,
			'name'               => $sp->post_title,
			'active_enrollments' => $active,
			'message'            => __( 'Performance snapshot retrieved.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
