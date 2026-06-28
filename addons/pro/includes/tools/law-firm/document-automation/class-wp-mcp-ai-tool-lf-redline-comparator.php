<?php
/**
 * Redline Comparator Tool
 *
 * Compares two document versions and produces a word-level diff summary.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares two legal documents and produces a redline diff.
 */
class WP_MCP_AI_Tool_LF_Redline_Comparator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_redline_comparator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Redline Comparator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Compares two document versions and produces a word-level diff with additions, deletions, and material changes summary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_id_a'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the first (original) document.', 'mcp-ai-wpoos-pro' ),
				),
				'document_id_b'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the second (revised) document.', 'mcp-ai-wpoos-pro' ),
				),
				'comparison_mode' => array(
					'type'        => 'string',
					'description' => __( 'Comparison detail level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full', 'summary' ),
				),
			),
			'required'   => array( 'document_id_a', 'document_id_b' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$doc_id_a        = isset( $arguments['document_id_a'] ) ? absint( $arguments['document_id_a'] ) : 0;
		$doc_id_b        = isset( $arguments['document_id_b'] ) ? absint( $arguments['document_id_b'] ) : 0;
		$comparison_mode = isset( $arguments['comparison_mode'] ) ? sanitize_text_field( $arguments['comparison_mode'] ) : 'full';

		if ( ! $doc_id_a || ! $doc_id_b ) {
			return new WP_Error( 'missing_required', __( 'Both document IDs are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$doc_a = get_post( $doc_id_a );
		$doc_b = get_post( $doc_id_b );

		if ( ! $doc_a || ! $doc_b ) {
			return new WP_Error( 'not_found', __( 'One or both documents not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$words_a = preg_split( '/\s+/', wp_strip_all_tags( $doc_a->post_content ) );
		$words_b = preg_split( '/\s+/', wp_strip_all_tags( $doc_b->post_content ) );

		$additions_count  = 0;
		$deletions_count  = 0;
		$material_changes = array();

		$set_a = array_flip( $words_a );
		$set_b = array_flip( $words_b );

		$added   = array_diff( $words_b, $words_a );
		$removed = array_diff( $words_a, $words_b );

		$additions_count = count( $added );
		$deletions_count = count( $removed );

		$legal_keywords = array( 'indemnify', 'liability', 'termination', 'warranty', 'damages', 'confidential', 'arbitration', 'jurisdiction', 'governing', 'waive' );

		foreach ( $legal_keywords as $keyword ) {
			$in_a = isset( $set_a[ $keyword ] );
			$in_b = isset( $set_b[ $keyword ] );
			if ( $in_a !== $in_b ) {
				$material_changes[] = array(
					'keyword' => $keyword,
					'change'  => $in_b ? 'added' : 'removed',
				);
			}
		}

		$total_words = max( count( $words_a ), 1 );
		$change_pct  = round( ( ( $additions_count + $deletions_count ) / $total_words ) * 100, 1 );

		$data = array(
			'document_id_a'     => $doc_id_a,
			'document_id_b'     => $doc_id_b,
			'comparison_mode'   => $comparison_mode,
			'additions_count'   => $additions_count,
			'deletions_count'   => $deletions_count,
			'change_percentage' => $change_pct,
			'material_changes'  => $material_changes,
		);

		if ( 'full' === $comparison_mode ) {
			$data['added_words']   = array_values( array_slice( $added, 0, 50 ) );
			$data['removed_words'] = array_values( array_slice( $removed, 0, 50 ) );
		}

		$summary = sprintf(
			/* translators: 1: additions, 2: deletions, 3: change percentage */
			__( 'Comparison complete: %1$d additions, %2$d deletions (%3$s%% changed). ', 'mcp-ai-wpoos-pro' ),
			$additions_count,
			$deletions_count,
			$change_pct
		);

		return array(
			'success'    => true,
			'message'    => $summary . self::DISCLAIMER,
			'data'       => $data,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
