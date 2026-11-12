<?php
/**
 * Verify Information tool — cross-checks a claim against supplied sources.
 *
 * Pure logic: keyword-overlap scoring over caller-supplied text. No network,
 * no storage, no provider. Verdicts and the confidence calculation are
 * identical to the WordPress implementation.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class VerifyInformationTool extends AbstractTool {

	public function getSlug(): string {
		return 'verify_information';
	}

	public function getName(): string {
		return 'Verify Information';
	}

	public function getDescription(): string {
		return 'Cross-check and verify information accuracy across multiple sources with confidence scoring.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
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
			'required'             => array( 'claim', 'sources' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$claim   = trim( strip_tags( $this->stringParam( $arguments, 'claim' ) ) );
		$sources = $this->arrayParam( $arguments, 'sources' );

		if ( '' === $claim ) {
			return $this->errors->validationFailed(
				'A claim is required.',
				array( 'claim' => array( 'Provide the claim to verify.' ) )
			);
		}

		if ( array() === $sources ) {
			return $this->errors->validationFailed(
				'At least one source is required.',
				array( 'sources' => array( 'Provide the sources to check the claim against.' ) )
			);
		}

		$matches       = 0;
		$total         = count( $sources );
		$supporting    = array();
		$contradicting = array();
		$claim_words   = explode( ' ', strtolower( $claim ) );
		$word_count    = count( $claim_words );

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			// Upstream payloads can carry `content` as a non-string; stripos()
			// needs a string.
			$content     = isset( $source['content'] ) && is_string( $source['content'] ) ? $source['content'] : '';
			$credibility = isset( $source['credibility'] ) ? (string) $source['credibility'] : 'medium';
			$url         = isset( $source['url'] ) ? (string) $source['url'] : '';

			$matching_words = 0;
			foreach ( $claim_words as $word ) {
				if ( strlen( $word ) > 3 && false !== stripos( $content, $word ) ) {
					++$matching_words;
				}
			}

			$match_percentage = ( $matching_words / max( 1, $word_count ) ) * 100;

			if ( $match_percentage > 60 ) {
				++$matches;
				$supporting[] = array(
					'url'         => $url,
					'credibility' => $credibility,
					'match_score' => round( $match_percentage, 2 ),
				);
			} elseif ( $match_percentage < 30 ) {
				$contradicting[] = array(
					'url'         => $url,
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

		return $this->success(
			'Verification complete.',
			array(
				'claim'           => $claim,
				'verdict'         => $verdict,
				'confidence'      => $confidence,
				'sources_checked' => $total,
				'supporting'      => $supporting,
				'contradicting'   => $contradicting,
				'recommendation'  => $this->recommendation( $verdict ),
			)
		);
	}

	private function recommendation( string $verdict ): string {
		if ( 'verified' === $verdict ) {
			return 'Information is well-supported across sources';
		}

		if ( 'partially_verified' === $verdict ) {
			return 'Seek additional sources for confirmation';
		}

		if ( 'contradicted' === $verdict ) {
			return 'Information contradicted by sources - verify original claim';
		}

		return 'Insufficient data to verify - more sources needed';
	}
}
