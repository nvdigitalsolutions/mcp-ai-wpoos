<?php
/**
 * Verify Information Tool - Cross-check facts across multiple sources
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Pro_Tool_Verify_Information {
	public function get_slug() {
		return 'verify_information';
	}

	public function get_definition() {
		return array(
			'name'                => 'verify_information',
			'description'         => 'Cross-check and verify information accuracy across multiple sources with confidence scoring.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'claim'   => array(
						'type'        => 'string',
						'description' => 'Information or claim to verify',
					),
					'sources' => array(
						'type'        => 'array',
						'description' => 'Sources to check against',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'content'     => array( 'type' => 'string' ),
								'url'         => array( 'type' => 'string' ),
								'credibility' => array(
									'type' => 'string',
									'enum' => array( 'high', 'medium', 'low' ),
								),
							),
						),
					),
				),
				'required'   => array( 'claim', 'sources' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$claim   = sanitize_text_field( $arguments['claim'] );
		$sources = $arguments['sources'];

		$matches       = 0;
		$total         = count( $sources );
		$supporting    = array();
		$contradicting = array();

		foreach ( $sources as $source ) {
			$content     = $source['content'];
			$credibility = isset( $source['credibility'] ) ? $source['credibility'] : 'medium';

			// Simple keyword matching (in production, use semantic analysis).
			$claim_words    = explode( ' ', strtolower( $claim ) );
			$matching_words = 0;

			foreach ( $claim_words as $word ) {
				if ( strlen( $word ) > 3 && stripos( $content, $word ) !== false ) {
					++$matching_words;
				}
			}

			$match_percentage = ( $matching_words / count( $claim_words ) ) * 100;

			if ( $match_percentage > 60 ) {
				++$matches;
				$supporting[] = array(
					'url'         => isset( $source['url'] ) ? $source['url'] : '',
					'credibility' => $credibility,
					'match_score' => round( $match_percentage, 2 ),
				);
			} elseif ( $match_percentage < 30 ) {
				$contradicting[] = array(
					'url'         => isset( $source['url'] ) ? $source['url'] : '',
					'credibility' => $credibility,
				);
			}
		}

		$confidence = $total > 0 ? round( ( $matches / $total ) * 100, 2 ) : 0;

		$verdict = 'unverified';
		if ( $confidence >= 70 ) {
			$verdict = 'verified';
		} elseif ( $confidence >= 40 ) {
			$verdict = 'partially_verified';
		} elseif ( count( $contradicting ) > count( $supporting ) ) {
			$verdict = 'contradicted';
		}

		return array(
			'success'         => true,
			'claim'           => $claim,
			'verdict'         => $verdict,
			'confidence'      => $confidence,
			'sources_checked' => $total,
			'supporting'      => $supporting,
			'contradicting'   => $contradicting,
			'recommendation'  => $this->get_recommendation( $verdict, $confidence ),
		);
	}

	private function get_recommendation( $verdict, $confidence ) {
		if ( 'verified' === $verdict ) {
			return 'Information is well-supported across sources';
		} elseif ( 'partially_verified' === $verdict ) {
			return 'Seek additional sources for confirmation';
		} elseif ( 'contradicted' === $verdict ) {
			return 'Information contradicted by sources - verify original claim';
		}
		return 'Insufficient data to verify - more sources needed';
	}
}
