<?php
/**
 * Tool: generate_visit_summary
 *
 * Produces a discharge-style visit summary for a member by collating
 * checkups, vital-sign readings, prescriptions and medical-record entries
 * within a date range.  Output is a structured array suitable for further
 * formatting (Markdown, FHIR DocumentReference, etc.).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate visit summary tool.
 */
class WP_MCP_AI_Tool_Generate_Visit_Summary implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_visit_summary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Visit Summary', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a structured visit / discharge summary for a member, drawing from checkups, prescriptions, vital-sign logs and medical records over a date range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'date_from' => array(
					'type'        => 'string',
					'description' => __( 'Inclusive start date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'Inclusive end date (YYYY-MM-DD); defaults to today.', 'mcp-ai-wpoos-pro' ),
				),
				'format'    => array(
					'type'    => 'string',
					'enum'    => array( 'structured', 'markdown' ),
					'default' => 'structured',
				),
			),
			'required'   => array( 'member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate visit summaries.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'A valid member_id is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$format    = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'structured';
		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to   = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d' );
		$ts_from   = '' !== $date_from ? strtotime( $date_from ) : false;
		$ts_to     = '' !== $date_to ? strtotime( $date_to . ' 23:59:59' ) : strtotime( gmdate( 'Y-m-d 23:59:59' ) );

		$timeline_tool = new WP_MCP_AI_Tool_Get_Health_Timeline();
		$timeline      = $timeline_tool->execute(
			array(
				'member_id' => $member_id,
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'order'     => 'asc',
				'per_page'  => 200,
			),
			$context
		);
		if ( is_wp_error( $timeline ) ) {
			return $timeline;
		}

		$checkups      = array();
		$prescriptions = array();
		$vitals        = array();
		$records       = array();
		foreach ( $timeline['events'] as $evt ) {
			switch ( $evt['event_type'] ) {
				case 'checkup':
					$checkups[] = $evt;
					break;
				case 'prescription':
					$prescriptions[] = $evt;
					break;
				case 'vital_log':
					$vitals[] = $evt;
					break;
				case 'medical_record':
					$records[] = $evt;
					break;
			}
		}

		$summary = array(
			'member'   => array(
				'id'   => $member_id,
				'name' => $member->post_title,
			),
			'period'   => array(
				'from' => false !== $ts_from ? gmdate( 'Y-m-d', $ts_from ) : '',
				'to'   => gmdate( 'Y-m-d', $ts_to ),
			),
			'sections' => array(
				'visits'          => $checkups,
				'prescriptions'   => $prescriptions,
				'vital_logs'      => $vitals,
				'medical_records' => $records,
			),
			'totals'   => array(
				'visits'          => count( $checkups ),
				'prescriptions'   => count( $prescriptions ),
				'vital_logs'      => count( $vitals ),
				'medical_records' => count( $records ),
			),
		);

		if ( 'markdown' === $format ) {
			$summary['markdown'] = $this->render_markdown( $summary );
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'visit_summary',
				$member_id,
				array(
					'user_id' => $current_user_id,
					'tool'    => $this->get_slug(),
					'period'  => $summary['period'],
				)
			);
		}

		return array_merge( array( 'success' => true ), $summary );
	}

	/**
	 * Render the summary as Markdown.
	 *
	 * @param array $summary Structured summary.
	 * @return string
	 */
	private function render_markdown( array $summary ) {
		$lines   = array();
		$lines[] = '# ' . sprintf(
			/* translators: %s: member name */
			__( 'Visit summary — %s', 'mcp-ai-wpoos-pro' ),
			$summary['member']['name']
		);
		if ( ! empty( $summary['period']['from'] ) || ! empty( $summary['period']['to'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: start date, 2: end date */
				__( '_Period: %1$s → %2$s_', 'mcp-ai-wpoos-pro' ),
				$summary['period']['from'] ? $summary['period']['from'] : '∞',
				$summary['period']['to']
			);
		}
		$lines[] = '';
		$lines[] = '## ' . __( 'Visits & checkups', 'mcp-ai-wpoos-pro' );
		if ( empty( $summary['sections']['visits'] ) ) {
			$lines[] = __( '_No checkups recorded in this period._', 'mcp-ai-wpoos-pro' );
		} else {
			foreach ( $summary['sections']['visits'] as $v ) {
				$lines[] = '- **' . $v['date'] . '** — ' . $v['title']
					. ( ! empty( $v['provider'] ) ? ' (' . $v['provider'] . ')' : '' )
					. ( ! empty( $v['status'] ) ? ' [' . $v['status'] . ']' : '' );
			}
		}
		$lines[] = '';
		$lines[] = '## ' . __( 'Prescriptions', 'mcp-ai-wpoos-pro' );
		if ( empty( $summary['sections']['prescriptions'] ) ) {
			$lines[] = __( '_No prescriptions recorded in this period._', 'mcp-ai-wpoos-pro' );
		} else {
			foreach ( $summary['sections']['prescriptions'] as $p ) {
				$lines[] = '- **' . ( ! empty( $p['medication_name'] ) ? $p['medication_name'] : $p['title'] ) . '** — '
					. ( ! empty( $p['date'] ) ? $p['date'] : '' )
					. ( ! empty( $p['status'] ) ? ' (' . $p['status'] . ')' : '' );
			}
		}
		$lines[] = '';
		$lines[] = '## ' . __( 'Vital signs', 'mcp-ai-wpoos-pro' );
		if ( empty( $summary['sections']['vital_logs'] ) ) {
			$lines[] = __( '_No vital readings recorded in this period._', 'mcp-ai-wpoos-pro' );
		} else {
			foreach ( $summary['sections']['vital_logs'] as $v ) {
				$lines[] = '- ' . $v['date'] . ' — ' . $v['title'];
			}
		}
		$lines[] = '';
		$lines[] = '## ' . __( 'Medical records', 'mcp-ai-wpoos-pro' );
		if ( empty( $summary['sections']['medical_records'] ) ) {
			$lines[] = __( '_No medical records in this period._', 'mcp-ai-wpoos-pro' );
		} else {
			foreach ( $summary['sections']['medical_records'] as $r ) {
				$lines[] = '- ' . $r['date'] . ' — ' . $r['title']
					. ( ! empty( $r['provider'] ) ? ' (' . $r['provider'] . ')' : '' );
			}
		}
		return implode( "\n", $lines );
	}
}
