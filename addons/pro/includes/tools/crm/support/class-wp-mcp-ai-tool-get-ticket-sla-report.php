<?php
/**
 * Get Ticket SLA Report Tool
 *
 * SLA compliance reporting: breached count, average first response
 * time, average resolution time, grouped by assignee.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@inheritdoc}
 */
class WP_MCP_AI_Tool_Get_Ticket_Sla_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Ticket SLA Report tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_ticket_sla_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Ticket SLA Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'SLA compliance report: breached count, avg first response, avg resolution, by assignee.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from' => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d). Default: 30 days ago.', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d). Default: today.', 'mcp-ai-wpoos-pro' ),
				),
				'group_by'  => array(
					'type'        => 'string',
					'description' => __( 'Group results: assignee, priority, status. Default: assignee.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$date_from = sanitize_text_field( $arguments['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$date_to   = sanitize_text_field( $arguments['date_to'] ?? gmdate( 'Y-m-d' ) );
		$group_by  = sanitize_key( $arguments['group_by'] ?? 'assignee' );

		$tickets = get_posts(
			array(
				'post_type'      => 'mcp_ai_support_ticket',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after'     => $date_from,
						'before'    => $date_to,
						'inclusive' => true,
					),
				),
			)
		);

		$total          = count( $tickets );
		$breached       = 0;
		$on_track       = 0;
		$at_risk        = 0;
		$resolved_count = 0;
		$fr_times       = array();
		$res_times      = array();
		$by_group       = array();
		$by_priority    = array();
		$reopened_total = 0;

		foreach ( $tickets as $tid ) {
			$sla_status = get_post_meta( $tid, '_ticket_sla_status', true );
			$status     = get_post_meta( $tid, '_ticket_status', true );
			$priority   = get_post_meta( $tid, '_ticket_priority', true ) ? get_post_meta( $tid, '_ticket_priority', true ) : 'p2_high';
			$assignee   = (int) get_post_meta( $tid, '_ticket_assignee_id', true );
			$reopened   = (int) get_post_meta( $tid, '_ticket_reopened_count', true );
			$post       = get_post( $tid );
			$created_ts = $post ? strtotime( $post->post_date ) : time();

			// SLA status counts.
			if ( 'breached' === $sla_status ) {
				++$breached;
			} elseif ( 'at_risk' === $sla_status ) {
				++$at_risk;
			} else {
				++$on_track;
			}

			// Resolved count.
			if ( in_array( $status, array( 'resolved', 'closed' ), true ) ) {
				++$resolved_count;
			}

			// First response time.
			$fr_at = get_post_meta( $tid, '_ticket_sla_first_response_at', true );
			if ( $fr_at ) {
				$fr_secs    = strtotime( $fr_at ) - $created_ts;
				$fr_times[] = max( 0, $fr_secs );
			}

			// Resolution time.
			$res_at = get_post_meta( $tid, '_ticket_sla_resolved_at', true );
			if ( $res_at ) {
				$res_secs    = strtotime( $res_at ) - $created_ts;
				$res_times[] = max( 0, $res_secs );
			}

			// Reopened.
			$reopened_total += $reopened;

			// Group by.
			$group_key = 'assignee' === $group_by
				? ( $assignee ? get_userdata( $assignee )->display_name ?? "User #{$assignee}" : __( 'Unassigned', 'mcp-ai-wpoos-pro' ) )
				: ( 'priority' === $group_by ? $priority : $status );

			if ( ! isset( $by_group[ $group_key ] ) ) {
				$by_group[ $group_key ] = array(
					'total'    => 0,
					'breached' => 0,
					'resolved' => 0,
				);
			}
			++$by_group[ $group_key ]['total'];
			if ( 'breached' === $sla_status ) {
				++$by_group[ $group_key ]['breached'];
			}
			if ( in_array( $status, array( 'resolved', 'closed' ), true ) ) {
				++$by_group[ $group_key ]['resolved'];
			}

			// By priority.
			if ( ! isset( $by_priority[ $priority ] ) ) {
				$by_priority[ $priority ] = 0;
			}
			++$by_priority[ $priority ];
		}

		$avg_fr_minutes  = ! empty( $fr_times ) ? round( array_sum( $fr_times ) / count( $fr_times ) / 60, 1 ) : 0;
		$avg_res_minutes = ! empty( $res_times ) ? round( array_sum( $res_times ) / count( $res_times ) / 60, 1 ) : 0;

		return $this->format_success_response(
			__( 'SLA report generated.', 'mcp-ai-wpoos-pro' ),
			array(
				'period'                     => array(
					'from' => $date_from,
					'to'   => $date_to,
				),
				'total_tickets'              => $total,
				'sla_breakdown'              => array(
					'on_track'       => $on_track,
					'at_risk'        => $at_risk,
					'breached'       => $breached,
					'compliance_pct' => $total > 0 ? round( ( $on_track / $total ) * 100, 1 ) : 100,
				),
				'resolved'                   => $resolved_count,
				'reopened_total'             => $reopened_total,
				'avg_first_response_minutes' => $avg_fr_minutes,
				'avg_resolution_minutes'     => $avg_res_minutes,
				'by_group'                   => $by_group,
				'by_priority'                => $by_priority,
			)
		);
	}
}
