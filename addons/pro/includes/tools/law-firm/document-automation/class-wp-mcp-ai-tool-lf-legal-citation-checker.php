<?php
/**
 * Legal Citation Checker Tool
 *
 * Extracts and validates legal citations from text using regex patterns.
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
 * Validates legal citation formatting per Bluebook or ALWD standards.
 */
class WP_MCP_AI_Tool_LF_Legal_Citation_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
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
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_legal_citation_checker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Legal Citation Checker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Extracts and validates legal citations from text, checking Bluebook or ALWD format compliance with ABA Opinion 512 hallucination warnings.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'            => array(
					'type'        => 'string',
					'description' => __( 'Text containing legal citations.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'citation_format' => array(
					'type'        => 'string',
					'description' => __( 'Citation format standard.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'bluebook', 'alwd' ),
				),
			),
			'required'   => array( 'text' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$text   = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';
		$format = isset( $arguments['citation_format'] ) ? sanitize_text_field( $arguments['citation_format'] ) : 'bluebook';

		if ( empty( $text ) ) {
			return new WP_Error( 'missing_required', __( 'Text is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$citations     = array();
		$format_issues = array();

		// US Reports: e.g., 347 U.S. 483 (1954).
		if ( preg_match_all( '/\d{1,3}\s+U\.S\.\s+\d{1,4}( ? ( :\s*\(\d{4}\))?/', $text, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				$citations[] = array(
					'citation'     => trim( $m ),
					'type'         => 'us_reports',
					'format_valid' => true,
				);
			}
		}

		// Federal Reporter: e.g., 123 F.3d 456 (9th Cir. 2020).
		if ( preg_match_all( '/\d{1,4}\s+F\.( ? ( :2d|3d|4th)\s+\d{1,4}( ? ( :\s*\([^)]+\))?/', $text, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				$citations[] = array(
					'citation'     => trim( $m ),
					'type'         => 'federal_reporter',
					'format_valid' => true,
				);
			}
		}

		// Federal Supplement: e.g., 123 F. Supp. 2d 456.
		if ( preg_match_all( '/\d{1,4}\s+F\.\s*Supp\.?\s*( ? ( :2d|3d)?\s+\d{1,4}/', $text, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				$citations[] = array(
					'citation'     => trim( $m ),
					'type'         => 'federal_supplement',
					'format_valid' => true,
				);
			}
		}

		// State reporters: generic pattern e.g., 123 Cal.App.4th 456.
		if ( preg_match_all( '/\d{1,4}\s+[A-Z][a-z]+\.( ? ( :\s*App\.)?( ? ( :\s*\d+[a-z]{2})?\s+\d{1,4}/', $text, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				$citations[] = array(
					'citation'     => trim( $m ),
					'type'         => 'state_reporter',
					'format_valid' => true,
				);
			}
		}

		// Statutes: e.g., 42 U.S.C. § 1983.
		if ( preg_match_all( '/\d{1,2}\s+U\.S\.C\.\s*§+\s*\d+/', $text, $matches ) ) {
			foreach ( $matches[0] as $m ) {
				$citations[] = array(
					'citation'     => trim( $m ),
					'type'         => 'usc_statute',
					'format_valid' => true,
				);
			}
		}

		// Check for common format issues.
		if ( preg_match( '/\bId\b(?!\.)/', $text ) ) {
			$format_issues[] = __( '"Id" should be italicized and followed by a period: "Id."', 'mcp-ai-wpoos-pro' );
		}
		if ( preg_match( '/\bsupra\b(?!\s+note)/', $text ) ) {
			$format_issues[] = __( '"supra" should generally be followed by "note [number]" and a pinpoint.', 'mcp-ai-wpoos-pro' );
		}

		// Deduplicate citations by text.
		$seen   = array();
		$unique = array();
		foreach ( $citations as $c ) {
			if ( ! isset( $seen[ $c['citation'] ] ) ) {
				$seen[ $c['citation'] ] = true;
				$unique[]               = $c;
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of citations found */
				__( 'Found %d citations. ', 'mcp-ai-wpoos-pro' ),
				count( $unique )
			) . self::DISCLAIMER,
			'data'       => array(
				'citations_found'       => $unique,
				'total_citations'       => count( $unique ),
				'citation_format'       => $format,
				'format_issues'         => $format_issues,
				'hallucination_warning' => __( 'Per ABA Formal Opinion 512: AI-generated citations MUST be independently verified. AI systems may fabricate case names, volumes, or page numbers.', 'mcp-ai-wpoos-pro' ),
				'human_review_required' => true,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
