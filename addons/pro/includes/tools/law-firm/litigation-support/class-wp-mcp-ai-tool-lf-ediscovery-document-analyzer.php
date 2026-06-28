<?php
/**
 * Performs the operation.
 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
 * eDiscovery Document Analyzer Tool
 *
 * Analyzes documents for relevance scoring, privilege flags, and key term
 * identification to support electronic discovery workflows.
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
 * Analyzes documents for eDiscovery relevance, privilege, and key terms.
 */
class WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_ediscovery_document_analyzer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'eDiscovery Document Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Analyzes documents for eDiscovery relevance scoring, privilege flags, and key term identification. Returns relevance score, privilege flags, key terms found, and classification.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_id'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the document to analyze.', 'mcp-ai-wpoos-pro' ),
				),
				'analysis_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of analysis to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'relevance', 'privilege', 'key_terms', 'all' ),
					'default'     => 'all',
				),
				'search_terms'  => array(
					'type'        => 'array',
					'description' => __( 'List of search terms to look for in the document.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
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

		$document_id   = isset( $arguments['document_id'] ) ? absint( $arguments['document_id'] ) : 0;
		$analysis_type = isset( $arguments['analysis_type'] ) ? sanitize_text_field( $arguments['analysis_type'] ) : 'all';
		$search_terms  = array();
		if ( ! empty( $arguments['search_terms'] ) && is_array( $arguments['search_terms'] ) ) {
			$search_terms = array_map( 'sanitize_text_field', $arguments['search_terms'] );
		}

		if ( $document_id <= 0 ) {
			return new WP_Error( 'missing_required', __( 'A valid document_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $document_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Document not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$content    = wp_strip_all_tags( $post->post_content );
		$title      = $post->post_title;
		$word_count = str_word_count( $content );
		$lower      = strtolower( $content . ' ' . $title );

		$result = array(
			'document_id' => $document_id,
			'title'       => $title,
			'word_count'  => $word_count,
		);

		// Relevance analysis.
		if ( 'relevance' === $analysis_type || 'all' === $analysis_type ) {
			$relevance_score = 0;
			$term_hits       = array();
			if ( ! empty( $search_terms ) ) {
				foreach ( $search_terms as $term ) {
					$count = substr_count( $lower, strtolower( $term ) );
					if ( $count > 0 ) {
						$term_hits[ $term ] = $count;
					}
				}
				$hit_ratio       = count( $term_hits ) / count( $search_terms );
				$relevance_score = (int) min( 100, round( $hit_ratio * 70 + min( array_sum( $term_hits ), 30 ) ) );
			} else {
				$relevance_score = $word_count > 500 ? 50 : (int) round( ( $word_count / 500 ) * 50 );
			}
			$result['relevance_score'] = $relevance_score;
			$result['term_hits']       = $term_hits;
		}

		// Privilege analysis.
		if ( 'privilege' === $analysis_type || 'all' === $analysis_type ) {
			$privilege_keywords = array(
				'attorney-client' => array( 'privileged', 'confidential', 'attorney-client', 'legal advice', 'counsel' ),
				'work_product'    => array( 'work product', 'trial preparation', 'litigation strategy', 'case analysis' ),
				'deliberative'    => array( 'deliberative', 'draft', 'internal memo', 'preliminary' ),
				'common_interest' => array( 'common interest', 'joint defense', 'joint privilege' ),
			);
			$privilege_flags    = array();
			foreach ( $privilege_keywords as $flag => $keywords ) {
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $lower, strtolower( $keyword ) ) ) {
						$privilege_flags[] = $flag;
						break;
					}
				}
			}
			$result['privilege_flags']    = array_unique( $privilege_flags );
			$result['privilege_detected'] = ! empty( $privilege_flags );
		}

		// Key terms analysis.
		if ( 'key_terms' === $analysis_type || 'all' === $analysis_type ) {
			$legal_terms     = array(
				'liability',
				'damages',
				'negligence',
				'breach',
				'contract',
				'defendant',
				'plaintiff',
				'jurisdiction',
				'discovery',
				'deposition',
				'motion',
				'summary judgment',
				'injunction',
				'settlement',
				'verdict',
				'testimony',
				'evidence',
				'subpoena',
				'complaint',
				'counterclaim',
			);
			$key_terms_found = array();
			foreach ( $legal_terms as $term ) {
				$count = substr_count( $lower, $term );
				if ( $count > 0 ) {
					$key_terms_found[ $term ] = $count;
				}
			}
			arsort( $key_terms_found );
			$result['key_terms_found'] = $key_terms_found;
		}

		// Classification.
		$classifications = array(
			'responsive'     => array( 'relevant', 'responsive', 'material', 'pertinent' ),
			'non_responsive' => array( 'unrelated', 'irrelevant', 'personal' ),
			'privileged'     => array( 'privileged', 'attorney-client', 'work product' ),
			'confidential'   => array( 'confidential', 'trade secret', 'proprietary' ),
		);
		$classification  = 'unclassified';
		if ( ! empty( $result['privilege_flags'] ) ) {
			$classification = 'privileged';
		} elseif ( isset( $result['relevance_score'] ) && $result['relevance_score'] >= 60 ) {
			$classification = 'responsive';
		} elseif ( isset( $result['relevance_score'] ) && $result['relevance_score'] < 20 ) {
			$classification = 'non_responsive';
		} else {
			foreach ( $classifications as $class => $keywords ) {
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $lower, $keyword ) ) {
						$classification = $class;
						break 2;
					}
				}
			}
		}
		$result['classification'] = $classification;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: document title, 2: classification */
				__( 'Document "%1$s" analyzed. Classification: %2$s. ', 'mcp-ai-wpoos-pro' ),
				$title,
				$classification
			) . self::DISCLAIMER,
			'data'       => $result,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
