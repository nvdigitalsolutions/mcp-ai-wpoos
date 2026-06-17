<?php
/**
 * Market Sentiment Analyzer Tool
 *
 * Provides rule-based financial text sentiment analysis using comprehensive
 * keyword matching with intensity modifiers and confidence scoring.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for analyzing sentiment in financial texts.
 *
 * Supports:
 * - Rule-based keyword sentiment scoring
 * - Positive/negative/neutral classification
 * - Intensity modifier detection
 * - Per-text and aggregate sentiment analysis
 * - Confidence scoring based on keyword density
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Market_Sentiment_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Market sentiment analyzer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'market_sentiment_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Market Sentiment Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyze sentiment in financial texts using rule-based keyword scoring. Scores texts from -1.0 (bearish) to +1.0 (bullish) with confidence levels. Supports intensity modifiers and aggregate analysis. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'texts'             => array(
					'type'        => 'array',
					'description' => __( 'Array of financial text strings to analyze (max 20).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => 20,
				),
				'mode'              => array(
					'type'        => 'string',
					'description' => __( 'Analysis mode.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'keyword' ),
					'default'     => 'keyword',
				),
				'include_aggregate' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include aggregate sentiment across all texts.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'texts' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the sentiment analyzer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$texts             = isset( $arguments['texts'] ) && is_array( $arguments['texts'] ) ? $arguments['texts'] : array();
		$include_aggregate = isset( $arguments['include_aggregate'] ) ? (bool) $arguments['include_aggregate'] : true;

		if ( empty( $texts ) ) {
			return new WP_Error( 'empty_texts', __( 'At least one text string is required for analysis.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( count( $texts ) > 20 ) {
			$texts = array_slice( $texts, 0, 20 );
		}

		$results     = array();
		$total_score = 0;
		$total_conf  = 0;

		foreach ( $texts as $index => $text ) {
			$text         = sanitize_text_field( $text );
			$analysis     = $this->analyze_text( $text );
			$results[]    = $analysis;
			$total_score += $analysis['score'];
			$total_conf  += $analysis['confidence'];
		}

		$count    = count( $results );
		$response = array(
			'success'    => true,
			'mode'       => 'keyword',
			'results'    => $results,
			'text_count' => $count,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Sentiment analysis is based on rule-based keyword matching and may not reflect actual market conditions. This is a simplified analysis tool and should not be used as the sole basis for investment decisions. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		if ( $include_aggregate && $count > 0 ) {
			$avg_score      = $total_score / $count;
			$avg_conf       = $total_conf / $count;
			$positive_count = 0;
			$negative_count = 0;
			$neutral_count  = 0;

			foreach ( $results as $r ) {
				if ( 'positive' === $r['label'] ) {
					++$positive_count;
				} elseif ( 'negative' === $r['label'] ) {
					++$negative_count;
				} else {
					++$neutral_count;
				}
			}

			$response['aggregate'] = array(
				'average_score'      => round( $avg_score, 4 ),
				'average_confidence' => round( $avg_conf, 4 ),
				'overall_label'      => $this->score_to_label( $avg_score ),
				'texts_analyzed'     => $count,
				'positive_count'     => $positive_count,
				'negative_count'     => $negative_count,
				'neutral_count'      => $neutral_count,
				'summary'            => $this->generate_aggregate_summary( $count, $avg_score, $positive_count, $negative_count ),
			);
		}

		return $response;
	}

	/**
	 * Analyze a single text for sentiment.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Text to analyze.
	 * @return array Analysis result with score, label, confidence, and matched keywords.
	 */
	private function analyze_text( $text ) {
		$text_lower = strtolower( $text );
		$words      = str_word_count( $text_lower, 1 );
		$word_count = count( $words );

		$positive_keywords = $this->get_positive_keywords();
		$negative_keywords = $this->get_negative_keywords();
		$modifiers         = $this->get_intensity_modifiers();

		$positive_matches = array();
		$negative_matches = array();
		$positive_score   = 0.0;
		$negative_score   = 0.0;

		// Check for multi-word and single-word positive keywords.
		foreach ( $positive_keywords as $keyword => $weight ) {
			if ( false !== strpos( $text_lower, $keyword ) ) {
				$modifier           = $this->detect_modifier( $text_lower, $keyword, $modifiers );
				$adjusted_weight    = $weight * $modifier;
				$positive_score    += $adjusted_weight;
				$positive_matches[] = array(
					'keyword'  => $keyword,
					'weight'   => round( $adjusted_weight, 3 ),
					'modifier' => $modifier > 1.0 ? 'intensified' : ( $modifier < 1.0 ? 'softened' : 'none' ),
				);
			}
		}

		// Check for multi-word and single-word negative keywords.
		foreach ( $negative_keywords as $keyword => $weight ) {
			if ( false !== strpos( $text_lower, $keyword ) ) {
				$modifier           = $this->detect_modifier( $text_lower, $keyword, $modifiers );
				$adjusted_weight    = $weight * $modifier;
				$negative_score    += $adjusted_weight;
				$negative_matches[] = array(
					'keyword'  => $keyword,
					'weight'   => round( $adjusted_weight, 3 ),
					'modifier' => $modifier > 1.0 ? 'intensified' : ( $modifier < 1.0 ? 'softened' : 'none' ),
				);
			}
		}

		// Normalize score to -1.0 to +1.0 range.
		$raw_score    = $positive_score - $negative_score;
		$total_weight = $positive_score + $negative_score;
		$score        = 0.0;

		if ( $total_weight > 0 ) {
			$score = $raw_score / $total_weight;
		}

		$score = max( -1.0, min( 1.0, $score ) );

		// Calculate confidence based on keyword density.
		$match_count = count( $positive_matches ) + count( $negative_matches );
		$confidence  = 0.0;
		if ( $word_count > 0 && $match_count > 0 ) {
			$confidence = min( 1.0, $match_count / max( 1, $word_count / 10 ) );
		}

		return array(
			'text'             => wp_trim_words( $text, 30, '...' ),
			'score'            => round( $score, 4 ),
			'label'            => $this->score_to_label( $score ),
			'confidence'       => round( $confidence, 4 ),
			'positive_matches' => $positive_matches,
			'negative_matches' => $negative_matches,
			'keyword_count'    => $match_count,
			'word_count'       => $word_count,
		);
	}

	/**
	 * Convert a numeric score to a sentiment label.
	 *
	 * @since 1.1.0
	 *
	 * @param float $score Sentiment score (-1.0 to 1.0).
	 * @return string Label: 'positive', 'negative', or 'neutral'.
	 */
	private function score_to_label( $score ) {
		if ( $score > 0.1 ) {
			return 'positive';
		}
		if ( $score < -0.1 ) {
			return 'negative';
		}
		return 'neutral';
	}

	/**
	 * Detect intensity modifiers near a keyword in text.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text      Full text (lowercase).
	 * @param string $keyword   Keyword to check context for.
	 * @param array  $modifiers Modifier words and their multipliers.
	 * @return float Modifier multiplier (default 1.0).
	 */
	private function detect_modifier( $text, $keyword, $modifiers ) {
		foreach ( $modifiers as $mod_word => $multiplier ) {
			// Check if modifier appears within 3 words before the keyword.
			$pattern = '/' . preg_quote( $mod_word, '/' ) . '\s+( ? ( :\w+\s+){0,2}' . preg_quote( $keyword, '/' ) . '/';
			if ( preg_match( $pattern, $text ) ) {
				return $multiplier;
			}
		}
		return 1.0;
	}

	/**
	 * Get positive sentiment keywords with weights.
	 *
	 * @since 1.1.0
	 *
	 * @return array Keyword => weight mapping.
	 */
	private function get_positive_keywords() {
		return array(
			'revenue growth' => 0.9,
			'earnings beat'  => 0.9,
			'all-time high'  => 0.8,
			'strong demand'  => 0.8,
			'bull'           => 0.7,
			'bullish'        => 0.8,
			'surge'          => 0.8,
			'surged'         => 0.8,
			'profit'         => 0.6,
			'profitable'     => 0.6,
			'growth'         => 0.6,
			'rally'          => 0.7,
			'rallied'        => 0.7,
			'upgrade'        => 0.7,
			'upgraded'       => 0.7,
			'beat'           => 0.6,
			'outperform'     => 0.7,
			'strong'         => 0.5,
			'recovery'       => 0.6,
			'recovering'     => 0.6,
			'gains'          => 0.6,
			'gained'         => 0.6,
			'breakout'       => 0.7,
			'dividend'       => 0.5,
			'buy'            => 0.5,
			'optimism'       => 0.6,
			'optimistic'     => 0.6,
			'momentum'       => 0.5,
			'expansion'      => 0.5,
			'exceeded'       => 0.6,
			'record'         => 0.5,
			'positive'       => 0.4,
			'upside'         => 0.5,
			'innovation'     => 0.4,
			'opportunity'    => 0.4,
		);
	}

	/**
	 * Get negative sentiment keywords with weights.
	 *
	 * @since 1.1.0
	 *
	 * @return array Keyword => weight mapping.
	 */
	private function get_negative_keywords() {
		return array(
			'debt crisis'   => 0.9,
			'earnings miss' => 0.9,
			'bear'          => 0.7,
			'bearish'       => 0.8,
			'crash'         => 0.9,
			'crashed'       => 0.9,
			'loss'          => 0.6,
			'losses'        => 0.6,
			'decline'       => 0.6,
			'declined'      => 0.6,
			'downgrade'     => 0.7,
			'downgraded'    => 0.7,
			'miss'          => 0.6,
			'missed'        => 0.6,
			'underperform'  => 0.7,
			'weak'          => 0.5,
			'weakness'      => 0.5,
			'recession'     => 0.8,
			'selloff'       => 0.7,
			'sell-off'      => 0.7,
			'default'       => 0.8,
			'bankruptcy'    => 0.9,
			'layoffs'       => 0.6,
			'layoff'        => 0.6,
			'warning'       => 0.5,
			'risk'          => 0.3,
			'volatile'      => 0.4,
			'volatility'    => 0.4,
			'sell'          => 0.5,
			'pessimism'     => 0.6,
			'pessimistic'   => 0.6,
			'contraction'   => 0.5,
			'plunge'        => 0.8,
			'plunged'       => 0.8,
			'negative'      => 0.4,
			'downside'      => 0.5,
			'correction'    => 0.5,
			'inflation'     => 0.3,
			'uncertainty'   => 0.4,
		);
	}

	/**
	 * Get intensity modifier words and their multipliers.
	 *
	 * @since 1.1.0
	 *
	 * @return array Modifier => multiplier mapping.
	 */
	private function get_intensity_modifiers() {
		return array(
			'very'          => 1.3,
			'extremely'     => 1.5,
			'slightly'      => 0.6,
			'sharply'       => 1.4,
			'dramatically'  => 1.5,
			'significantly' => 1.3,
			'massively'     => 1.5,
			'moderately'    => 0.8,
			'somewhat'      => 0.7,
			'highly'        => 1.3,
			'deeply'        => 1.3,
			'barely'        => 0.5,
			'unexpectedly'  => 1.2,
		);
	}

	/**
	 * Generate an aggregate summary from pre-computed counts.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $count          Total number of texts analyzed.
	 * @param float $avg_score      Average sentiment score.
	 * @param int   $positive_count Number of positive texts.
	 * @param int   $negative_count Number of negative texts.
	 * @return string Human-readable summary.
	 */
	private function generate_aggregate_summary( $count, $avg_score, $positive_count, $negative_count ) {
		$label = $this->score_to_label( $avg_score );

		return sprintf(
			/* translators: 1: text count, 2: overall label, 3: avg score, 4: positive count, 5: negative count */
			__( 'Analyzed %1$d texts. Overall sentiment: %2$s (score: %3$s). %4$d positive, %5$d negative.', 'mcp-ai-wpoos-pro' ),
			$count,
			$label,
			number_format( $avg_score, 3 ),
			$positive_count,
			$negative_count
		);
	}
}
