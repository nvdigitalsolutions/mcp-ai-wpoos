<?php
/**
 * Contract Reviewer Tool
 *
 * Analyzes legal documents for risk factors, missing clauses, and ambiguous language.
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
 * Reviews contracts for risk assessment, missing clauses, and ambiguous language.
 */
class WP_MCP_AI_Tool_LF_Contract_Reviewer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_contract_reviewer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Contract Reviewer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Analyzes legal documents for risk factors, missing standard clauses, and ambiguous language. Provides risk assessment per ABA Opinion 512 guidelines with mandatory human-review flag.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the document to review.', 'mcp-ai-wpoos-pro' ),
				),
				'review_focus' => array(
					'type'        => 'string',
					'description' => __( 'Focus area for the review.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'risk_assessment', 'missing_clauses', 'ambiguous_language', 'all' ),
				),
				'jurisdiction' => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction for compliance checking.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'document_id' ),
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

		$document_id  = isset( $arguments['document_id'] ) ? absint( $arguments['document_id'] ) : 0;
		$review_focus = isset( $arguments['review_focus'] ) ? sanitize_text_field( $arguments['review_focus'] ) : 'all';
		$jurisdiction = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';

		if ( ! $document_id ) {
			return new WP_Error( 'missing_required', __( 'Document ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$document = get_post( $document_id );
		if ( ! $document ) {
			return new WP_Error( 'not_found', __( 'Document not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$content = $document->post_content;
		if ( empty( $content ) ) {
			return new WP_Error( 'empty_document', __( 'Document has no content to review.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_focus = array( 'risk_assessment', 'missing_clauses', 'ambiguous_language', 'all' );
		if ( ! in_array( $review_focus, $valid_focus, true ) ) {
			$review_focus = 'all';
		}

		$risk_flags               = array();
		$missing_standard_clauses = array();
		$ambiguous_items          = array();

		if ( 'all' === $review_focus || 'risk_assessment' === $review_focus ) {
			$risk_flags = $this->assess_risks( $content );
		}
		if ( 'all' === $review_focus || 'missing_clauses' === $review_focus ) {
			$missing_standard_clauses = $this->check_missing_clauses( $content );
		}
		if ( 'all' === $review_focus || 'ambiguous_language' === $review_focus ) {
			$ambiguous_items = $this->check_ambiguous_language( $content );
		}

		$risk_score = count( $risk_flags ) + count( $missing_standard_clauses ) + count( $ambiguous_items );
		if ( $risk_score >= 5 ) {
			$overall_risk = 'high';
		} elseif ( $risk_score >= 2 ) {
			$overall_risk = 'medium';
		} else {
			$overall_risk = 'low';
		}

		$recommendations = array();
		if ( ! empty( $risk_flags ) ) {
			$recommendations[] = __( 'Address identified risk factors before execution.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! empty( $missing_standard_clauses ) ) {
			$recommendations[] = __( 'Consider adding missing standard clauses.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! empty( $ambiguous_items ) ) {
			$recommendations[] = __( 'Clarify ambiguous language to reduce dispute risk.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: overall risk level */
				__( 'Contract review complete. Overall risk level: %s. ', 'mcp-ai-wpoos-pro' ),
				$overall_risk
			) . self::DISCLAIMER,
			'data'       => array(
				'document_id'              => $document_id,
				'review_focus'             => $review_focus,
				'risk_flags'               => $risk_flags,
				'missing_standard_clauses' => $missing_standard_clauses,
				'ambiguous_items'          => $ambiguous_items,
				'overall_risk_level'       => $overall_risk,
				'recommendations'          => $recommendations,
				'human_review_required'    => true,
				'aba_opinion_512_notice'   => __( 'Per ABA Formal Opinion 512, AI-assisted document review must be supervised by a licensed attorney.', 'mcp-ai-wpoos-pro' ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Assess risk factors in document content.
	 *
	 * @param string $content Document content.
	 * @return array Risk flags found.
	 */
	private function assess_risks( string $content ): array {
		$flags    = array();
		$lower    = strtolower( $content );
		$patterns = array(
			'unlimited_liability'     => 'unlimited liability',
			'automatic_renewal'       => 'automatic renewal',
			'unilateral_termination'  => 'sole discretion to terminate',
			'broad_indemnification'   => 'indemnify and hold harmless from any and all',
			'waiver_of_jury_trial'    => 'waive jury trial',
			'non_compete_broad'       => 'shall not compete',
			'liquidated_damages'      => 'liquidated damages',
			'force_majeure_missing'   => false,
			'assignment_unrestricted' => 'freely assignable',
		);

		foreach ( $patterns as $flag => $pattern ) {
			if ( false === $pattern ) {
				if ( false === strpos( $lower, 'force majeure' ) ) {
					$flags[] = array(
						'flag'   => $flag,
						'detail' => __( 'No force majeure clause detected.', 'mcp-ai-wpoos-pro' ),
					);
				}
				continue;
			}
			if ( false !== strpos( $lower, $pattern ) ) {
				$flags[] = array(
					'flag'   => $flag,
					'detail' => sprintf(
						/* translators: %s: pattern found */
						__( 'Found pattern: "%s"', 'mcp-ai-wpoos-pro' ),
						$pattern
					),
				);
			}
		}

		return $flags;
	}

	/**
	 * Check for missing standard clauses.
	 *
	 * @param string $content Document content.
	 * @return array Missing clauses.
	 */
	private function check_missing_clauses( string $content ): array {
		$missing  = array();
		$lower    = strtolower( $content );
		$standard = array(
			'governing_law'        => array( 'governing law', 'choice of law', 'applicable law' ),
			'dispute_resolution'   => array( 'dispute resolution', 'arbitration', 'mediation' ),
			'confidentiality'      => array( 'confidential', 'non-disclosure' ),
			'limitation_liability' => array( 'limitation of liability', 'limit of liability' ),
			'severability'         => array( 'severability', 'severable' ),
			'entire_agreement'     => array( 'entire agreement', 'whole agreement' ),
			'notice_provisions'    => array( 'notice', 'written notice' ),
			'amendment'            => array( 'amendment', 'modification' ),
		);

		foreach ( $standard as $clause => $keywords ) {
			$found = false;
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $lower, $keyword ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = array(
					'clause' => $clause,
					'label'  => ucwords( str_replace( '_', ' ', $clause ) ),
				);
			}
		}

		return $missing;
	}

	/**
	 * Check for ambiguous language patterns.
	 *
	 * @param string $content Document content.
	 * @return array Ambiguous language instances.
	 */
	private function check_ambiguous_language( string $content ): array {
		$ambiguous = array();
		$patterns  = array(
			'reasonable efforts'      => __( 'Consider defining what constitutes "reasonable efforts".', 'mcp-ai-wpoos-pro' ),
			'best efforts'            => __( '"Best efforts" is often litigated; consider a more specific standard.', 'mcp-ai-wpoos-pro' ),
			'material adverse'        => __( 'Define "material adverse" with specific thresholds.', 'mcp-ai-wpoos-pro' ),
			'commercially reasonable' => __( 'Specify what "commercially reasonable" means in this context.', 'mcp-ai-wpoos-pro' ),
			'promptly'                => __( 'Replace "promptly" with specific time frames.', 'mcp-ai-wpoos-pro' ),
			'from time to time'       => __( 'Vague timing; consider specifying frequency.', 'mcp-ai-wpoos-pro' ),
		);

		$lower = strtolower( $content );
		foreach ( $patterns as $phrase => $suggestion ) {
			if ( false !== strpos( $lower, $phrase ) ) {
				$ambiguous[] = array(
					'phrase'     => $phrase,
					'suggestion' => $suggestion,
				);
			}
		}

		return $ambiguous;
	}
}
