<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-credit-score-tracker.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps (yfinance
 * service require / vendor autoload probe).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for tracking credit scores and health.
 *
 * Supports:
 * - Score history tracking
 * - Factor analysis (utilization, payment history, etc.)
 * - Improvement recommendations
 * - Goal setting and monitoring
 * - Multi-bureau tracking
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Credit_Score_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Credit score tracker tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'credit_score_tracker';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Credit Score Tracker', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Track credit score over time and monitor key factors. Log score updates, analyze trends, and get personalized recommendations for credit improvement. Supports multiple credit bureaus.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'             => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'log_score', 'get_history', 'analyze', 'get_recommendations' ),
					'default'     => 'get_history',
				),
				'score'              => array(
					'type'        => 'integer',
					'description' => __( 'Credit score value', 'nvoos-content-graph-pro' ),
					'minimum'     => 300,
					'maximum'     => 850,
				),
				'bureau'             => array(
					'type'        => 'string',
					'description' => __( 'Credit bureau', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'equifax', 'experian', 'transunion', 'fico', 'vantagescore' ),
					'default'     => 'fico',
				),
				'date'               => array(
					'type'        => 'string',
					'description' => __( 'Score date (YYYY-MM-DD)', 'nvoos-content-graph-pro' ),
					'format'      => 'date',
				),
				'credit_utilization' => array(
					'type'        => 'number',
					'description' => __( 'Credit utilization percentage', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'payment_history'    => array(
					'type'        => 'string',
					'description' => __( 'Payment history status', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'excellent', 'good', 'fair', 'poor' ),
				),
				'derogatory_marks'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of derogatory marks', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'total_accounts'     => array(
					'type'        => 'integer',
					'description' => __( 'Total number of credit accounts', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'hard_inquiries'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of hard inquiries in last 2 years', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to track credit scores.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'get_history';

		switch ( $action ) {
			case 'log_score':
				return $this->log_score( $arguments, $current_user_id );
			case 'get_history':
				return $this->get_history( $arguments, $current_user_id );
			case 'analyze':
				return $this->analyze_score( $arguments, $current_user_id );
			case 'get_recommendations':
				return $this->get_recommendations( $arguments, $current_user_id );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'nvoos-content-graph-pro' ) );
		}
	}

	/**
	 * Log a credit score.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function log_score( $arguments, $user_id ) {
		$score  = isset( $arguments['score'] ) ? absint( $arguments['score'] ) : 0;
		$bureau = isset( $arguments['bureau'] ) ? sanitize_text_field( $arguments['bureau'] ) : 'fico';
		$date   = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );

		if ( $score < 300 || $score > 850 ) {
			return new WP_Error( 'invalid_score', __( 'Credit score must be between 300 and 850.', 'nvoos-content-graph-pro' ) );
		}

		$history = get_user_meta( $user_id, 'wp_mcp_ai_credit_scores', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry_id             = uniqid( 'score_' );
		$history[ $entry_id ] = array(
			'id'                 => $entry_id,
			'score'              => $score,
			'bureau'             => $bureau,
			'date'               => $date,
			'credit_utilization' => isset( $arguments['credit_utilization'] ) ? floatval( $arguments['credit_utilization'] ) : null,
			'payment_history'    => isset( $arguments['payment_history'] ) ? sanitize_text_field( $arguments['payment_history'] ) : null,
			'derogatory_marks'   => isset( $arguments['derogatory_marks'] ) ? absint( $arguments['derogatory_marks'] ) : null,
			'total_accounts'     => isset( $arguments['total_accounts'] ) ? absint( $arguments['total_accounts'] ) : null,
			'hard_inquiries'     => isset( $arguments['hard_inquiries'] ) ? absint( $arguments['hard_inquiries'] ) : null,
			'logged_at'          => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, 'wp_mcp_ai_credit_scores', $history );

		$rating = $this->get_score_rating( $score );

		return array(
			'success'  => true,
			'entry_id' => $entry_id,
			'score'    => $score,
			'rating'   => $rating,
			'bureau'   => $bureau,
			'message'  => sprintf(
				/* translators: 1: Score, 2: Rating */
				__( 'Credit score %1$d logged (%2$s rating).', 'nvoos-content-graph-pro' ),
				$score,
				$rating
			),
		);
	}

	/**
	 * Get score history.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array History.
	 */
	protected function get_history( $arguments, $user_id ) {
		$history = get_user_meta( $user_id, 'wp_mcp_ai_credit_scores', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$bureau = isset( $arguments['bureau'] ) ? sanitize_text_field( $arguments['bureau'] ) : '';

		$filtered = array();
		foreach ( $history as $entry ) {
			if ( empty( $bureau ) || $entry['bureau'] === $bureau ) {
				$filtered[] = $entry;
			}
		}

		usort(
			$filtered,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		$trend = null;
		if ( count( $filtered ) >= 2 ) {
			$latest   = $filtered[0]['score'];
			$previous = $filtered[1]['score'];
			$change   = $latest - $previous;
			$trend    = array(
				'direction' => $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'stable' ),
				'change'    => $change,
			);
		}

		return array(
			'success' => true,
			'history' => $filtered,
			'count'   => count( $filtered ),
			'trend'   => $trend,
			'message' => sprintf(
				/* translators: %d: Number of entries */
				__( 'Found %d credit score entries.', 'nvoos-content-graph-pro' ),
				count( $filtered )
			),
		);
	}

	/**
	 * Analyze credit score.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Analysis.
	 */
	protected function analyze_score( $arguments, $user_id ) {
		$score       = isset( $arguments['score'] ) ? absint( $arguments['score'] ) : 0;
		$utilization = isset( $arguments['credit_utilization'] ) ? floatval( $arguments['credit_utilization'] ) : 0;

		if ( $score < 300 || $score > 850 ) {
			return new WP_Error( 'invalid_score', __( 'Credit score must be between 300 and 850.', 'nvoos-content-graph-pro' ) );
		}

		$rating  = $this->get_score_rating( $score );
		$factors = array();

		if ( $utilization > 30 ) {
			$factors[] = array(
				'factor' => 'credit_utilization',
				'status' => 'needs_improvement',
				'impact' => 'high',
			);
		} elseif ( $utilization > 10 ) {
			$factors[] = array(
				'factor' => 'credit_utilization',
				'status' => 'good',
				'impact' => 'medium',
			);
		}

		return array(
			'success' => true,
			'score'   => $score,
			'rating'  => $rating,
			'factors' => $factors,
			'message' => sprintf(
				/* translators: 1: Score, 2: Rating */
				__( 'Score %1$d is rated as %2$s.', 'nvoos-content-graph-pro' ),
				$score,
				$rating
			),
		);
	}

	/**
	 * Get credit improvement recommendations.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Recommendations.
	 */
	protected function get_recommendations( $arguments, $user_id ) {
		$utilization     = isset( $arguments['credit_utilization'] ) ? floatval( $arguments['credit_utilization'] ) : 0;
		$payment_history = isset( $arguments['payment_history'] ) ? sanitize_text_field( $arguments['payment_history'] ) : 'good';

		$recommendations = array();

		if ( $utilization > 30 ) {
			$recommendations[] = __( 'Reduce credit utilization below 30% to improve score. Aim for under 10% for best results.', 'nvoos-content-graph-pro' );
		}

		if ( 'excellent' !== $payment_history ) {
			$recommendations[] = __( 'Always pay bills on time. Set up automatic payments to ensure no missed payments.', 'nvoos-content-graph-pro' );
		}

		$recommendations[] = __( 'Keep old credit accounts open to maintain credit history length.', 'nvoos-content-graph-pro' );
		$recommendations[] = __( 'Limit hard inquiries by avoiding unnecessary credit applications.', 'nvoos-content-graph-pro' );

		return array(
			'success'         => true,
			'recommendations' => $recommendations,
			'count'           => count( $recommendations ),
			'message'         => sprintf(
				/* translators: %d: Number of recommendations */
				__( 'Generated %d personalized recommendations.', 'nvoos-content-graph-pro' ),
				count( $recommendations )
			),
		);
	}

	/**
	 * Get score rating.
	 *
	 * @param int $score Credit score.
	 * @return string Rating.
	 */
	protected function get_score_rating( $score ) {
		if ( $score >= 800 ) {
			return 'exceptional';
		} elseif ( $score >= 740 ) {
			return 'very_good';
		} elseif ( $score >= 670 ) {
			return 'good';
		} elseif ( $score >= 580 ) {
			return 'fair';
		} else {
			return 'poor';
		}
	}
}
