<?php
/**
 * Outbound Appointment Booking — ICP list building (CSV import).
 *
 * Turns decision-maker lists into CRM lead records: dedupe by email, create
 * leads, attach LinkedIn/Instagram handles, ICP-score where data allows, and
 * optionally enroll the batch into an outreach sequence.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICP list importer.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Import {

	/**
	 * Expected CSV columns, in order.
	 *
	 * @var string[]
	 */
	const COLUMNS = array( 'email', 'first_name', 'last_name', 'company', 'job_title', 'linkedin', 'instagram' );

	/**
	 * Parse CSV text into normalised rows.
	 *
	 * @since 2.12.0
	 * @param string $csv CSV text (with or without header row).
	 * @return array Rows keyed by column names.
	 */
	public static function parse_csv( $csv ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $csv );
		$rows  = array();
		$first = true;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$cells = str_getcsv( $line );
			if ( $first ) {
				// Skip a header row if present.
				$lower = array_map(
					function ( $cell ) {
						return strtolower( trim( $cell ) );
					},
					$cells
				);
				if ( in_array( 'email', $lower, true ) ) {
					$first = false;
					continue;
				}
				$first = false;
			}
			$row = array();
			foreach ( self::COLUMNS as $i => $key ) {
				$row[ $key ] = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Import parsed rows as leads, optionally enrolling them in a sequence.
	 *
	 * @since 2.12.0
	 * @param array $rows        Parsed CSV rows.
	 * @param int   $sequence_id Sequence post ID to enroll into (0 = none).
	 * @param bool  $consent     Operator attestation for email consent.
	 * @return array|WP_Error Summary.
	 */
	public static function import_rows( $rows, $sequence_id = 0, $consent = true ) {
		if ( ! post_type_exists( 'mcp_ai_lead' ) ) {
			return new WP_Error( 'crm_disabled', __( 'CRM toolkit is disabled — enable it to import leads.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $sequence_id ) {
			$seq = get_post( $sequence_id );
			if ( ! $seq || 'mcp_ai_sequence' !== $seq->post_type ) {
				return new WP_Error( 'invalid_sequence', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$summary = array(
			'created'            => 0,
			'skipped_duplicate'  => 0,
			'skipped_invalid'    => 0,
			'enrolled'           => 0,
			'scored'             => 0,
			'lead_ids'           => array(),
		);

		$icp_available = class_exists( 'WP_MCP_AI_ICP_Scorer' ) && class_exists( 'WP_MCP_AI_ICP_Profile' );
		$icp_profile   = array();
		if ( $icp_available ) {
			$icp_profile = WP_MCP_AI_ICP_Profile::get_default();
			$icp_profile = is_array( $icp_profile ) ? $icp_profile : array();
		}

		foreach ( $rows as $row ) {
			$email = sanitize_email( $row['email'] );
			if ( ! is_email( $email ) ) {
				$summary['skipped_invalid']++;
				continue;
			}

			// Dedupe against existing leads.
			$existing = get_posts(
				array(
					'post_type'      => 'mcp_ai_lead',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => 'email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded dedupe lookup.
					'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded dedupe lookup.
				)
			);
			if ( ! empty( $existing ) ) {
				$summary['skipped_duplicate']++;
				if ( $sequence_id ) {
					self::enroll( (int) $existing[0], $sequence_id );
					$summary['enrolled']++;
				}
				continue;
			}

			$name      = trim( $row['first_name'] . ' ' . $row['last_name'] );
			$company   = sanitize_text_field( $row['company'] );
			$job_title = sanitize_text_field( $row['job_title'] );

			$lead_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_title'  => $name ? $name : $email,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $lead_id ) ) {
				$summary['skipped_invalid']++;
				continue;
			}

			update_post_meta( $lead_id, 'email', $email );
			update_post_meta( $lead_id, 'first_name', sanitize_text_field( $row['first_name'] ) );
			update_post_meta( $lead_id, 'last_name', sanitize_text_field( $row['last_name'] ) );
			if ( $company ) {
				update_post_meta( $lead_id, 'company_name', $company );
				update_post_meta( $lead_id, 'company', $company );
			}
			update_post_meta( $lead_id, 'job_title', $job_title );
			update_post_meta( $lead_id, 'source', 'cold_outreach' );
			update_post_meta( $lead_id, 'lead_status', 'new' );
			update_post_meta( $lead_id, '_oa_linkedin', esc_url_raw( $row['linkedin'] ) );
			update_post_meta( $lead_id, '_oa_instagram', sanitize_text_field( $row['instagram'] ) );
			if ( $consent ) {
				update_post_meta( $lead_id, '_oa_email_consent', '1' );
			}
			update_post_meta( $lead_id, '_oa_status', 'new' );

			// ICP scoring where a profile is configured.
			if ( $icp_available && ! empty( $icp_profile ) ) {
				$score = WP_MCP_AI_ICP_Scorer::compute_score(
					array(
						'name'       => $company,
						'job_title'  => $job_title,
						'country'    => '',
						'tech_stack' => array(),
					),
					$icp_profile,
					array( 'skip_cache' => true )
				);
				if ( ! is_wp_error( $score ) && isset( $score['total_score'] ) ) {
					update_post_meta( $lead_id, 'lead_score', absint( $score['total_score'] ) );
					update_post_meta( $lead_id, 'score_factors', wp_json_encode( array( 'icp' => $score['tier'] ) ) );
					$summary['scored']++;
				}
			}

			if ( $sequence_id ) {
				self::enroll( $lead_id, $sequence_id );
				$summary['enrolled']++;
			}
			$summary['created']++;
			$summary['lead_ids'][] = $lead_id;
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_import', 'lead', 0, $summary );
		}

		/**
		 * Fires after an outbound list import.
		 *
		 * @since 2.12.0
		 * @param array $summary Import summary.
		 */
		do_action( 'wp_mcp_ai_oa_after_import', $summary );

		return $summary;
	}

	/**
	 * Enroll a lead in a sequence (idempotent, mirrors the CRM enroll tool).
	 *
	 * @since 2.12.0
	 * @param int $lead_id     Lead post ID.
	 * @param int $sequence_id Sequence post ID.
	 * @return void
	 */
	private static function enroll( $lead_id, $sequence_id ) {
		if ( get_post_meta( $lead_id, '_active_sequence_id', true ) ) {
			return;
		}
		update_post_meta( $lead_id, '_active_sequence_id', $sequence_id );
		update_post_meta( $lead_id, '_sequence_step', 0 );
		update_post_meta( $lead_id, '_sequence_started', gmdate( 'c' ) );
		delete_post_meta( $lead_id, '_oa_next_due' ); // Due immediately on the next tick.
	}
}
