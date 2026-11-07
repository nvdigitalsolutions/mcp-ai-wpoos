<?php
/**
 * Export Outreach CSV — sequencer-ready list with evidence.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export a sequencer-ready CSV.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Export_Outreach_Csv implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'export_outreach_csv';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Export Outreach CSV', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Write a sequencer-ready CSV of leads with address status, timezone, consent basis, signals and evidence. DNC and invalid addresses are excluded by default.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Handing a verified, evidence-backed list to another sequencer, or archiving a campaign audience.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Sending from this site; use the sequence tools. Counting a cohort; use query_signal_cohort.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'query_signal_cohort', 'explain_prospect_fit', 'verify_lead_email' ),
			'notes'           => __( 'Writes to a temp path on the server; the response includes the path and a preview, not a public URL.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'limit'              => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 5000,
					'default' => 1000,
				),
				'include_suppressed' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include DNC and invalid addresses.', 'mcp-ai-wpoos-pro' ),
				),
				'signal_keys'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional subset of signal keys to include in the summary.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Requires Base Pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'filesystem-write', 'requires-capability', 'pii-access' );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Arguments.
	 * @param array $context   Context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}

		$limit              = max( 1, min( 5000, absint( $arguments['limit'] ?? 1000 ) ) );
		$include_suppressed = ! empty( $arguments['include_suppressed'] );
		$signal_keys        = ! empty( $arguments['signal_keys'] ) && is_array( $arguments['signal_keys'] )
			? array_map( 'sanitize_key', $arguments['signal_keys'] )
			: null;

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$dir = trailingslashit( get_temp_dir() ) . 'mcp-ai-crm-exports';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'export_dir_failed', __( 'Could not create the export directory.', 'mcp-ai-wpoos-pro' ) );
		}
		$path = trailingslashit( $dir ) . 'outreach-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false ) . '.csv';

		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming export.
		if ( ! $handle ) {
			return new WP_Error( 'export_open_failed', __( 'Could not open the export file.', 'mcp-ai-wpoos-pro' ) );
		}

		$columns = array(
			'external_id',
			'first_name',
			'last_name',
			'title',
			'company',
			'website',
			'email',
			'email_status',
			'email_confidence',
			'timezone',
			'country',
			'consent_basis',
			'signals',
			'evidence_summary',
			'booking_url',
			'source',
			'observed_at',
		);
		fputcsv( $handle, $columns );

		$rows     = 0;
		$preview  = array();
		$excluded = 0;
		$observed = gmdate( 'c' );

		foreach ( $query->posts as $lead_id ) {
			$lead_id = absint( $lead_id );
			$email   = (string) get_post_meta( $lead_id, 'email', true );
			if ( ! $email ) {
				++$excluded;
				continue;
			}

			$status = class_exists( 'WP_MCP_AI_CRM_Deliverability' )
				? WP_MCP_AI_CRM_Deliverability::get_status( $lead_id )
				: array(
					'status'     => 'unknown',
					'confidence' => 0,
				);

			$is_dnc = class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $email, 'email' );
			if ( ! $include_suppressed && ( $is_dnc || 'invalid' === $status['status'] ) ) {
				++$excluded;
				continue;
			}

			$timezone = class_exists( 'WP_MCP_AI_CRM_Send_Window' )
				? WP_MCP_AI_CRM_Send_Window::resolve_timezone( $lead_id )
				: array( 'zone' => '' );

			$basis = '';
			if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
				foreach ( WP_MCP_AI_CRM_Consent::get_records( $lead_id ) as $record ) {
					if ( isset( $record['channel'], $record['status'] ) && 'email' === $record['channel'] && 'active' === $record['status'] ) {
						$basis = isset( $record['legal_basis'] ) ? $record['legal_basis'] : '';
						break;
					}
				}
			}

			$card    = class_exists( 'WP_MCP_AI_CRM_Signals' ) ? WP_MCP_AI_CRM_Signals::evidence_card( $lead_id ) : array(
				'signals' => array(),
				'summary' => '',
			);
			$signals = array();
			foreach ( $card['signals'] as $signal ) {
				if ( null !== $signal_keys && ! in_array( $signal['key'], $signal_keys, true ) ) {
					continue;
				}
				$value     = '' !== (string) $signal['value_text'] ? $signal['value_text'] : (string) $signal['value_num'];
				$signals[] = $signal['key'] . ( '' !== $value ? '=' . $value : '' );
			}

			$row = array(
				$lead_id,
				get_post_meta( $lead_id, 'first_name', true ),
				get_post_meta( $lead_id, 'last_name', true ),
				get_post_meta( $lead_id, 'job_title', true ),
				get_the_title( $lead_id ),
				get_post_meta( $lead_id, 'website', true ),
				$email,
				$status['status'],
				$status['confidence'],
				$timezone['zone'],
				get_post_meta( $lead_id, '_crm_country', true ),
				$basis,
				implode( ', ', $signals ),
				$card['summary'],
				get_post_meta( $lead_id, '_oa_booking_url', true ),
				get_post_meta( $lead_id, '_crm_source', true ),
				$observed,
			);

			$row = array_map( array( __CLASS__, 'csv_safe' ), $row );
			fputcsv( $handle, $row );
			++$rows;

			if ( count( $preview ) < 5 ) {
				$preview[] = array_combine( $columns, $row );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming export.

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: 1: rows, 2: excluded */
				__( 'Exported %1$d lead(s); excluded %2$d suppressed or invalid row(s).', 'mcp-ai-wpoos-pro' ),
				$rows,
				$excluded
			),
			'path'     => $path,
			'filename' => basename( $path ),
			'rows'     => $rows,
			'excluded' => $excluded,
			'columns'  => $columns,
			'preview'  => $preview,
		);
	}

	/**
	 * Guard against CSV formula injection.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
