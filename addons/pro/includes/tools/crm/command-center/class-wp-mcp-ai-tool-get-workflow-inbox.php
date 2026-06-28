<?php
/**
 * Workflow Command Center Inbox — unified queue of things needing attention.
 * Hot leads + overdue follow-ups + pending approvals + unread replies.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Workflow Command Center Inbox tool — unified queue of things needing attention.
 */
class WP_MCP_AI_Tool_Get_Workflow_Inbox implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_workflow_inbox'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Workflow Command Center Inbox', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Unified inbox: hot leads, overdue follow-ups, unread replies, and pending approvals.', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the JSON Schema for the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'owner_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Filter to a specific owner.', 'mcp-ai-wpoos-pro' ),
				),
				'per_section' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
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
		$uid   = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$per   = min( 50, max( 1, absint( $arguments['per_section'] ?? 10 ) ) );
		$owner = ! empty( $arguments['owner_id'] ) ? absint( $arguments['owner_id'] ) : 0;

		$inbox = array( 'sections' => array() );

		// Hot leads (score >= hot threshold).
		$hot_threshold = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_toolkit_settings()['hot_score_threshold'] : 70;
		$mq            = array(
			array(
				'key'     => 'lead_score',
				'value'   => $hot_threshold,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			),
		);
		if ( $owner ) {
			$mq[] = array(
				'key'   => 'contact_owner',
				'value' => $owner,
			); }
		$hq  = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => $per,
				'meta_query'     => $mq,
				'no_found_rows'  => true,
			)
		);
		$hot = array();
		foreach ( $hq->posts as $p ) {
			$hot[] = array(
				'id'    => $p->ID,
				'title' => $p->post_title,
				'score' => (int) get_post_meta( $p->ID, 'lead_score', true ),
			); }
		$inbox['sections'][] = array(
			'type'  => 'hot_leads',
			'label' => __( '🔥 Hot Leads', 'mcp-ai-wpoos-pro' ),
			'count' => count( $hot ),
			'items' => $hot,
		);

		// Overdue follow-ups.
		$oq      = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => $per,
				'meta_query'     => array(
					array(
						'key'     => 'due_date',
						'value'   => gmdate( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => 'completed',
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);
		$overdue = array();
		foreach ( $oq->posts as $p ) {
			$overdue[] = array(
				'id'         => $p->ID,
				'title'      => $p->post_title,
				'due_date'   => get_post_meta( $p->ID, 'due_date', true ),
				'related_id' => (int) get_post_meta( $p->ID, 'related_id', true ),
			); }
		$inbox['sections'][] = array(
			'type'  => 'overdue_follow_ups',
			'label' => __( '⏰ Overdue Follow-ups', 'mcp-ai-wpoos-pro' ),
			'count' => count( $overdue ),
			'items' => $overdue,
		);

		// Active sequences.
		$sq   = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => $per,
				'meta_query'     => array(
					array(
						'key'     => '_active_sequence_id',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);
		$seqs = array();
		foreach ( $sq->posts as $p ) {
			$seqs[] = array(
				'id'          => $p->ID,
				'title'       => $p->post_title,
				'sequence_id' => (int) get_post_meta( $p->ID, '_active_sequence_id', true ),
			); }
		$inbox['sections'][] = array(
			'type'  => 'active_sequences',
			'label' => __( '📋 Active Sequences', 'mcp-ai-wpoos-pro' ),
			'count' => count( $seqs ),
			'items' => $seqs,
		);

		$total                = array_sum( array_column( $inbox['sections'], 'count' ) );
		$inbox['total_items'] = $total;
		$inbox['message']     = $total > 0 ? sprintf(
			/* translators: %d: number of inbox items needing attention */
			__( '%d items need your attention.', 'mcp-ai-wpoos-pro' ),
			$total
		) : __( 'Inbox is clear — great job!', 'mcp-ai-wpoos-pro' );

		/**
		 * Filter the command center inbox response, allowing addons to inject
		 * custom widget sections into the inbox payload.
		 *
		 * @since 2.3.0
		 *
		 * @param array $inbox     The inbox array with keys: sections, total_items, message.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Execution context (user_id, etc.).
		 */
		$inbox = apply_filters( 'wp_mcp_ai_crm_command_center_widgets', $inbox, $arguments, $context );

		return array_merge( array( 'success' => true ), $inbox );
	}
}
