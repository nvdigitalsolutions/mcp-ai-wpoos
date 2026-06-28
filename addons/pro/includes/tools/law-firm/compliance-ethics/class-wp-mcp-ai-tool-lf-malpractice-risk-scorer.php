<?php
/**
 * Malpractice Risk Scorer Tool
 *
 * Scores malpractice risk for a given matter based on multiple risk factors.
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
 * Scores malpractice risk for legal matters based on practice area, deadlines,
 * communication frequency, and complexity factors.
 */
class WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Practice area base risk weights (0-25 scale).
	 *
	 * @var array
	 */
	private static $practice_area_weights = array(
		'medical_malpractice'   => 22,
		'personal_injury'       => 18,
		'real_estate'           => 16,
		'family_law'            => 15,
		'criminal_defense'      => 20,
		'immigration'           => 17,
		'corporate'             => 12,
		'employment'            => 14,
		'intellectual_property' => 11,
		'bankruptcy'            => 13,
		'tax'                   => 16,
		'estate_planning'       => 10,
		'general_litigation'    => 15,
	);

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
		return 'lf_malpractice_risk_scorer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Malpractice Risk Scorer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates a malpractice risk score (0-100) for a legal matter based on practice area risk, deadline proximity, communication frequency, and case complexity.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id' => array(
					'type'        => 'integer',
					'description' => __( 'Post ID of the legal matter (mcp_ai_lf_matter CPT).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'matter_id' ),
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

		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'invalid_matter', __( 'Matter not found or invalid post type.', 'mcp-ai-wpoos-pro' ) );
		}

		$risk_factors = array();
		$total_score  = 0;

		// Factor 1: Practice area risk (0-25 points).
		$practice_area  = get_post_meta( $matter_id, '_lf_practice_area', true );
		$area_key       = sanitize_title( str_replace( ' ', '_', strtolower( $practice_area ) ) );
		$area_weight    = isset( self::$practice_area_weights[ $area_key ] ) ? self::$practice_area_weights[ $area_key ] : 15;
		$total_score   += $area_weight;
		$risk_factors[] = array(
			'factor'      => 'practice_area',
			'description' => sprintf(
				/* translators: %s: practice area name */
				__( 'Practice area: %s', 'mcp-ai-wpoos-pro' ),
				$practice_area ? $practice_area : __( 'Unknown', 'mcp-ai-wpoos-pro' )
			),
			'score'       => $area_weight,
			'max_score'   => 25,
		);

		// Factor 2: Deadline proximity (0-25 points).
		$deadline_score = $this->score_deadline_proximity( $matter_id );
		$total_score   += $deadline_score;
		$risk_factors[] = array(
			'factor'      => 'deadline_proximity',
			'description' => __( 'Proximity and number of upcoming deadlines', 'mcp-ai-wpoos-pro' ),
			'score'       => $deadline_score,
			'max_score'   => 25,
		);

		// Factor 3: Communication frequency (0-25 points).
		$comm_score     = $this->score_communication_frequency( $matter_id );
		$total_score   += $comm_score;
		$risk_factors[] = array(
			'factor'      => 'communication_frequency',
			'description' => __( 'Frequency and recency of client communications', 'mcp-ai-wpoos-pro' ),
			'score'       => $comm_score,
			'max_score'   => 25,
		);

		// Factor 4: Complexity (0-25 points).
		$complexity_score = $this->score_complexity( $matter_id );
		$total_score     += $complexity_score;
		$risk_factors[]   = array(
			'factor'      => 'complexity',
			'description' => __( 'Matter complexity based on parties, documents, and duration', 'mcp-ai-wpoos-pro' ),
			'score'       => $complexity_score,
			'max_score'   => 25,
		);

		// Clamp total score to 0-100.
		$total_score = max( 0, min( 100, $total_score ) );

		// Determine risk level.
		if ( $total_score >= 75 ) {
			$risk_level = 'critical';
		} elseif ( $total_score >= 50 ) {
			$risk_level = 'high';
		} elseif ( $total_score >= 25 ) {
			$risk_level = 'moderate';
		} else {
			$risk_level = 'low';
		}

		// Generate mitigation recommendations.
		$recommendations = $this->generate_recommendations( $risk_factors, $risk_level );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: matter title, 2: risk score, 3: risk level */
				__( 'Malpractice risk for "%1$s": %2$d/100 (%3$s risk).', 'mcp-ai-wpoos-pro' ),
				$matter->post_title,
				$total_score,
				$risk_level
			) . ' ' . self::DISCLAIMER,
			'data'       => array(
				'matter_id'                  => $matter_id,
				'matter_title'               => $matter->post_title,
				'risk_score'                 => $total_score,
				'risk_level'                 => $risk_level,
				'risk_factors'               => $risk_factors,
				'mitigation_recommendations' => $recommendations,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Score deadline proximity risk.
	 *
	 * @param int $matter_id Matter post ID.
	 * @return int Score 0-25.
	 */
	private function score_deadline_proximity( $matter_id ) {
		$deadlines = get_post_meta( $matter_id, '_lf_deadlines', true );
		if ( ! is_array( $deadlines ) || empty( $deadlines ) ) {
			// No deadlines tracked is itself a risk.
			return 15;
		}

		$score         = 0;
		$now           = time();
		$urgent_count  = 0;
		$overdue_count = 0;

		foreach ( $deadlines as $deadline ) {
			if ( empty( $deadline['date'] ) ) {
				continue;
			}
			$deadline_time = strtotime( $deadline['date'] );
			if ( ! $deadline_time ) {
				continue;
			}

			$days_until = ( $deadline_time - $now ) / DAY_IN_SECONDS;

			if ( $days_until < 0 ) {
				++$overdue_count;
			} elseif ( $days_until < 7 ) {
				++$urgent_count;
			}
		}

		// Overdue deadlines are high risk.
		$score += min( $overdue_count * 10, 20 );
		// Urgent deadlines add risk.
		$score += min( $urgent_count * 5, 10 );

		return min( $score, 25 );
	}

	/**
	 * Score communication frequency risk.
	 *
	 * @param int $matter_id Matter post ID.
	 * @return int Score 0-25.
	 */
	private function score_communication_frequency( $matter_id ) {
		$last_contact = get_post_meta( $matter_id, '_lf_last_client_contact', true );
		$contact_log  = get_post_meta( $matter_id, '_lf_contact_log', true );

		$score = 0;

		// Score based on time since last contact.
		if ( empty( $last_contact ) ) {
			$score += 20;
		} else {
			$days_since = ( time() - strtotime( $last_contact ) ) / DAY_IN_SECONDS;
			if ( $days_since > 60 ) {
				$score += 20;
			} elseif ( $days_since > 30 ) {
				$score += 12;
			} elseif ( $days_since > 14 ) {
				$score += 6;
			}
		}

		// Low communication volume.
		if ( is_array( $contact_log ) ) {
			$recent_count = 0;
			$ninety_ago   = time() - ( 90 * DAY_IN_SECONDS );
			foreach ( $contact_log as $entry ) {
				if ( ! empty( $entry['date'] ) && strtotime( $entry['date'] ) > $ninety_ago ) {
					++$recent_count;
				}
			}
			if ( $recent_count < 2 ) {
				$score += 5;
			}
		} else {
			$score += 5;
		}

		return min( $score, 25 );
	}

	/**
	 * Score matter complexity risk.
	 *
	 * @param int $matter_id Matter post ID.
	 * @return int Score 0-25.
	 */
	private function score_complexity( $matter_id ) {
		$score = 0;

		// Number of parties involved.
		$parties = get_post_meta( $matter_id, '_lf_parties', true );
		if ( is_array( $parties ) && count( $parties ) > 3 ) {
			$score += min( count( $parties ) * 2, 8 );
		}

		// Matter age.
		$matter = get_post( $matter_id );
		if ( $matter ) {
			$age_days = ( time() - strtotime( $matter->post_date ) ) / DAY_IN_SECONDS;
			if ( $age_days > 730 ) {
				$score += 8;
			} elseif ( $age_days > 365 ) {
				$score += 5;
			} elseif ( $age_days > 180 ) {
				$score += 2;
			}
		}

		// Document count (high volume matters are complex).
		$doc_count = get_post_meta( $matter_id, '_lf_document_count', true );
		if ( absint( $doc_count ) > 100 ) {
			$score += 9;
		} elseif ( absint( $doc_count ) > 50 ) {
			$score += 5;
		} elseif ( absint( $doc_count ) > 20 ) {
			$score += 2;
		}

		return min( $score, 25 );
	}

	/**
	 * Generate mitigation recommendations based on risk factors.
	 *
	 * @param array  $risk_factors Array of risk factor details.
	 * @param string $risk_level   Overall risk level.
	 * @return array
	 */
	private function generate_recommendations( $risk_factors, $risk_level ) {
		$recommendations = array();

		foreach ( $risk_factors as $factor ) {
			$ratio = $factor['max_score'] > 0 ? ( $factor['score'] / $factor['max_score'] ) : 0;
			if ( $ratio < 0.5 ) {
				continue;
			}

			switch ( $factor['factor'] ) {
				case 'practice_area':
					$recommendations[] = __( 'Consider carrying higher malpractice insurance limits for this practice area.', 'mcp-ai-wpoos-pro' );
					$recommendations[] = __( 'Ensure supervising attorney reviews all critical filings.', 'mcp-ai-wpoos-pro' );
					break;
				case 'deadline_proximity':
					$recommendations[] = __( 'Immediately review and calendar all upcoming deadlines.', 'mcp-ai-wpoos-pro' );
					$recommendations[] = __( 'Implement a dual-calendar system with backup reminders.', 'mcp-ai-wpoos-pro' );
					break;
				case 'communication_frequency':
					$recommendations[] = __( 'Schedule a client status update within the next 48 hours.', 'mcp-ai-wpoos-pro' );
					$recommendations[] = __( 'Establish a regular communication schedule with the client.', 'mcp-ai-wpoos-pro' );
					break;
				case 'complexity':
					$recommendations[] = __( 'Assign additional attorney oversight for complex matter management.', 'mcp-ai-wpoos-pro' );
					$recommendations[] = __( 'Conduct a comprehensive file review to ensure nothing has been overlooked.', 'mcp-ai-wpoos-pro' );
					break;
			}
		}

		if ( 'critical' === $risk_level ) {
			array_unshift( $recommendations, __( 'URGENT: This matter requires immediate senior partner review and risk mitigation action.', 'mcp-ai-wpoos-pro' ) );
		}

		return $recommendations;
	}
}
