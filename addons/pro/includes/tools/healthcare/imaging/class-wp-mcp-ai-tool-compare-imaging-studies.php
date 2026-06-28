<?php
/**
 * Tool: compare_imaging_studies
 *
 * Produces a structured diff between a prior and current imaging study
 * stored in the `mcp_ai_imaging_study` CPT.  Compares modality, study
 * date, description, series count, per-series instance counts, and (if
 * present) attached radiology report impressions.
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
 * Compare imaging studies tool.
 */
class WP_MCP_AI_Tool_Compare_Imaging_Studies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return ! empty( $settings['enable_healthcare_imaging'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'compare_imaging_studies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Compare Imaging Studies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Diff a prior and current imaging study (modality, dates, series and instance counts, attached impressions). Useful for radiology follow-up reads.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prior_study_id'    => array( 'type' => 'integer' ),
				'current_study_id'  => array( 'type' => 'integer' ),
				'prior_study_uid'   => array( 'type' => 'string' ),
				'current_study_uid' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'phi-data' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read imaging studies.', 'mcp-ai-wpoos-pro' ) );
		}

		$prior   = $this->resolve_study( $arguments, 'prior' );
		$current = $this->resolve_study( $arguments, 'current' );
		if ( is_wp_error( $prior ) ) {
			return $prior;
		}
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$prior_summary   = $this->summarize_study( $prior );
		$current_summary = $this->summarize_study( $current );

		$diff = array(
			'modality_changed'     => $prior_summary['modality'] !== $current_summary['modality'],
			'study_date_changed'   => $prior_summary['study_date'] !== $current_summary['study_date'],
			'series_count_delta'   => $current_summary['series_count'] - $prior_summary['series_count'],
			'instance_count_delta' => $current_summary['instance_count'] - $prior_summary['instance_count'],
			'description_changed'  => $prior_summary['description'] !== $current_summary['description'],
		);

		// Compute days between studies, when both dates parse.
		$days_between = null;
		$prior_dt     = $this->parse_dicom_date( $prior_summary['study_date'] );
		$current_dt   = $this->parse_dicom_date( $current_summary['study_date'] );
		if ( $prior_dt && $current_dt ) {
			$days_between = (int) round( ( $current_dt - $prior_dt ) / DAY_IN_SECONDS );
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'compare',
				'imaging_study',
				(int) $current->ID,
				array(
					'user_id'        => $current_user_id,
					'tool'           => $this->get_slug(),
					'prior_study_id' => (int) $prior->ID,
				)
			);
		}

		return array(
			'success'      => true,
			'prior'        => $prior_summary,
			'current'      => $current_summary,
			'diff'         => $diff,
			'days_between' => $days_between,
		);
	}

	/**
	 * Resolve a study from id/uid arguments.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $prefix    'prior' or 'current'.
	 * @return WP_Post|WP_Error
	 */
	private function resolve_study( array $arguments, $prefix ) {
		$id_key  = $prefix . '_study_id';
		$uid_key = $prefix . '_study_uid';
		$id      = isset( $arguments[ $id_key ] ) ? absint( $arguments[ $id_key ] ) : 0;
		$uid     = isset( $arguments[ $uid_key ] ) ? sanitize_text_field( $arguments[ $uid_key ] ) : '';

		$study = null;
		if ( $id > 0 ) {
			$study = get_post( $id );
		} elseif ( '' !== $uid && class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$study = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $uid );
		}
		if ( ! $study || 'mcp_ai_imaging_study' !== $study->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_study_not_found',
				sprintf(
					/* translators: %s: prefix (prior or current) */
					__( 'Could not resolve %s imaging study.', 'mcp-ai-wpoos-pro' ),
					$prefix
				)
			);
		}
		return $study;
	}

	/**
	 * Build a summary for a study.
	 *
	 * @param WP_Post $study Study post.
	 * @return array
	 */
	private function summarize_study( WP_Post $study ) {
		$series_json = (string) get_post_meta( $study->ID, '_imaging_series', true );
		$series      = '' !== $series_json ? json_decode( $series_json, true ) : array();
		if ( ! is_array( $series ) ) {
			$series = array();
		}
		$instance_count = 0;
		foreach ( $series as $s ) {
			if ( isset( $s['instance_count'] ) ) {
				$instance_count += (int) $s['instance_count'];
			}
		}

		$report_ids = (array) get_post_meta( $study->ID, '_imaging_report_ids', true );
		$report_ids = array_values( array_filter( array_map( 'absint', $report_ids ) ) );
		$impression = '';
		if ( ! empty( $report_ids ) ) {
			$impression = (string) get_post_meta( $report_ids[0], '_report_impression', true );
		}

		return array(
			'study_id'         => (int) $study->ID,
			'study_uid'        => (string) get_post_meta( $study->ID, '_imaging_study_instance_uid', true ),
			'modality'         => (string) get_post_meta( $study->ID, '_imaging_modality', true ),
			'study_date'       => (string) get_post_meta( $study->ID, '_imaging_study_date', true ),
			'description'      => (string) get_post_meta( $study->ID, '_imaging_study_description', true ),
			'series_count'     => count( $series ),
			'instance_count'   => $instance_count,
			'report_count'     => count( $report_ids ),
			'first_impression' => $impression,
		);
	}

	/**
	 * Parse a DICOM DA (YYYYMMDD) date to a unix timestamp.
	 *
	 * @param string $date Date string.
	 * @return int|null
	 */
	private function parse_dicom_date( $date ) {
		$date = preg_replace( '/[^0-9]/', '', (string) $date );
		if ( 8 !== strlen( $date ) ) {
			return null;
		}
		$year  = (int) substr( $date, 0, 4 );
		$month = (int) substr( $date, 4, 2 );
		$day   = (int) substr( $date, 6, 2 );
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}
		return mktime( 0, 0, 0, $month, $day, $year );
	}
}
