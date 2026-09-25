<?php
/**
 * Pro service: Jev decision evaluation harness.
 *
 * Runs labeled examples through TypeSafe Jev and reports accuracy —
 * overall and per confidence bucket — plus per-example grading. Implements
 * the TypeSafe self-consistency cookbook pattern ("evaluate on your own
 * traffic before trusting thresholds") without persisting anything: the
 * examples are supplied by the caller and nothing is trained or stored.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jev evaluation service.
 */
class WP_MCP_AI_Pro_Jev_Eval {

	/**
	 * Maximum examples evaluated per call (cost guard).
	 *
	 * @var int
	 */
	const MAX_EXAMPLES = 25;

	/**
	 * Score tolerance for grading score answers (rubric positions).
	 *
	 * @var float
	 */
	const SCORE_TOLERANCE = 0.5;

	/**
	 * Confidence buckets for the calibration report.
	 *
	 * @var array
	 */
	const CONFIDENCE_BUCKETS = array(
		array( 0.0, 0.2 ),
		array( 0.2, 0.4 ),
		array( 0.4, 0.6 ),
		array( 0.6, 0.8 ),
		array( 0.8, 1.0 ),
	);

	/**
	 * Evaluate labeled examples against Jev.
	 *
	 * Each example: { state, questions, expected } where expected is a map
	 * of question name => expected value (choice: option string; score:
	 * rubric position; noul: 0/1 or bool).
	 *
	 * @param array $examples Labeled examples.
	 * @param array $options  Optional: model, transport.
	 * @return array|WP_Error Report or WP_Error.
	 */
	public static function evaluate( $examples, $options = array() ) {
		if ( ! is_array( $examples ) || empty( $examples ) ) {
			return new WP_Error(
				'wp_mcp_ai_jev_eval_no_examples',
				__( 'At least one labeled example is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) ) {
			$classifier_file = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';
			if ( file_exists( $classifier_file ) ) {
				require_once $classifier_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) || ! WP_MCP_AI_Pro_Jev_Classifier::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_jev_unavailable',
				__( 'The Jev decision classifier is not available on this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		$buckets = array();
		foreach ( self::CONFIDENCE_BUCKETS as $range ) {
			$buckets[] = array(
				'low'     => $range[0],
				'high'    => $range[1],
				'total'   => 0,
				'correct' => 0,
				'accuracy' => 0.0,
			);
		}

		$total       = 0;
		$correct     = 0;
		$per_example = array();

		foreach ( array_slice( $examples, 0, self::MAX_EXAMPLES ) as $example ) {
			if ( ! is_array( $example ) || empty( $example['state'] ) || empty( $example['questions'] ) || empty( $example['expected'] ) ) {
				continue;
			}

			$decision = WP_MCP_AI_Pro_Jev_Classifier::decide( $example['state'], $example['questions'], $options );

			if ( is_wp_error( $decision ) ) {
				$per_example[] = array(
					'error' => $decision->get_error_code(),
				);
				continue;
			}

			$graded = array();
			$bucket_index = null;

			foreach ( $example['expected'] as $name => $expected ) {
				$name = sanitize_key( (string) $name );

				if ( ! isset( $decision['answers'][ $name ] ) ) {
					continue;
				}

				$answer     = $decision['answers'][ $name ];
				$predicted  = null;
				$confidence = isset( $answer['confidence'] ) ? floatval( $answer['confidence'] ) : 0.0;

				if ( isset( $answer['choice'] ) ) {
					$predicted = $answer['choice'];
				} elseif ( isset( $answer['score'] ) ) {
					$predicted = floatval( $answer['score'] );
				} elseif ( isset( $answer['noul'] ) ) {
					$predicted = floatval( $answer['noul'] );
					// Noul answers carry no confidence field — the distance
					// from the 0.5 boundary stands in for it.
					$confidence = 1.0 - min( 1.0, abs( $predicted - 0.5 ) * 2 );
				}

				if ( null === $predicted ) {
					continue;
				}

				$is_correct = self::grade( $answer, $predicted, $expected );

				$graded[ $name ] = array(
					'predicted' => $predicted,
					'expected'  => $expected,
					'correct'   => $is_correct,
					'confidence' => $confidence,
				);

				if ( null === $bucket_index ) {
					foreach ( self::CONFIDENCE_BUCKETS as $index => $range ) {
						if ( $confidence >= $range[0] && $confidence <= $range[1] ) {
							$bucket_index = $index;
							break;
						}
					}
					if ( null === $bucket_index ) {
						$bucket_index = 4;
					}
				}

				++$total;
				if ( $is_correct ) {
					++$correct;
				}
			}

			$per_example[] = array(
				'graded' => $graded,
				'model'  => isset( $decision['model'] ) ? $decision['model'] : '',
			);

			if ( null !== $bucket_index && ! empty( $graded ) ) {
				++$buckets[ $bucket_index ]['total'];
				$example_correct = true;
				foreach ( $graded as $grade ) {
					if ( empty( $grade['correct'] ) ) {
						$example_correct = false;
						break;
					}
				}
				if ( $example_correct ) {
					++$buckets[ $bucket_index ]['correct'];
				}
			}
		}

		foreach ( $buckets as &$bucket ) {
			$bucket['accuracy'] = $bucket['total'] > 0 ? round( $bucket['correct'] / $bucket['total'], 4 ) : 0.0;
		}
		unset( $bucket );

		return array(
			'total'      => $total,
			'correct'    => $correct,
			'accuracy'   => $total > 0 ? round( $correct / $total, 4 ) : 0.0,
			'buckets'    => $buckets,
			'examples'   => $per_example,
		);
	}

	/**
	 * Grade a single answer against its expected value.
	 *
	 * @param array $answer    Normalised answer entry.
	 * @param mixed $predicted Predicted value.
	 * @param mixed $expected  Expected value.
	 * @return bool
	 */
	private static function grade( $answer, $predicted, $expected ) {
		if ( isset( $answer['choice'] ) ) {
			return sanitize_text_field( (string) $predicted ) === sanitize_text_field( (string) $expected );
		}

		if ( isset( $answer['score'] ) ) {
			return abs( floatval( $predicted ) - floatval( $expected ) ) <= self::SCORE_TOLERANCE;
		}

		// Noul: expected is 0/1 or bool.
		$expected_bool = (bool) $expected;
		if ( is_string( $expected ) ) {
			$expected_bool = in_array( strtolower( $expected ), array( '1', 'true', 'yes' ), true );
		}

		return ( floatval( $predicted ) >= 0.5 ) === $expected_bool;
	}
}
