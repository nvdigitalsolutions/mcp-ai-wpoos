<?php
/**
 * CRM Toolkit Intent / Sentiment Classifier
 *
 * Lightweight shim around the configured LLM provider that classifies
 * inbound messages for intent, sentiment, buying signals, and BANT/MEDDIC
 * field extraction.  Designed to be swappable via filter so premium
 * deployments can plug in a dedicated classification model.
 *
 * The default implementation uses the same AI provider configured for
 * the plugin (OpenAI / Gemini / Ollama) with a structured prompt, but
 * the filter wp_mcp_ai_crm_classify_intent allows partners to replace
 * the entire classifier.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM intent / sentiment classifier.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Classifier {

	/**
	 * Classify an inbound message for intent, sentiment, and buying signals.
	 *
	 * Returns an array with:
	 *  - intent           Classification slug (new_inquiry, support, complaint, …).
	 *  - intent_confidence Confidence score (0–1).
	 *  - sentiment        positive | neutral | negative | mixed | unknown.
	 *  - buying_signals   Array of detected signal keywords / phrases.
	 *  - is_spam          Boolean.
	 *  - summary          One-sentence summary of the message.
	 *
	 * @param string $message_body   Message text / transcript.
	 * @param string $channel        Channel slug (email, whatsapp, sms, …).
	 * @param array  $context        Additional context (sender_name, previous_messages, …).
	 * @return array|WP_Error Classification result or WP_Error on failure.
	 */
	public static function classify( $message_body, $channel = 'email', array $context = array() ) {
		$message_body = sanitize_textarea_field( $message_body );
		$channel      = sanitize_key( $channel );

		if ( empty( $message_body ) ) {
			return new WP_Error( 'crm_classifier_empty', __( 'Cannot classify an empty message.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Filter: override the entire classifier.
		 *
		 * Return an array to short-circuit. Return false to fall through to
		 * the default LLM-based classification.
		 *
		 * @param array|false $result      Override result or false.
		 * @param string      $message_body Message body.
		 * @param string      $channel     Channel slug.
		 * @param array       $context     Additional context.
		 */
		$override = apply_filters( 'wp_mcp_ai_crm_classify_intent', false, $message_body, $channel, $context );
		if ( is_array( $override ) && isset( $override['intent'] ) ) {
			return self::sanitise_result( $override );
		}

		// ------ Default heuristic fallback (no LLM call needed for Phase A) ------

		return self::heuristic_classify( $message_body, $channel, $context );
	}

	/**
	 * Heuristic (keyword-based) classification for Phase A.
	 *
	 * Falls back when no LLM override is configured.
	 *
	 * @param string $message_body Message text.
	 * @param string $channel      Channel slug.
	 * @param array  $context      Additional context.
	 * @return array
	 */
	private static function heuristic_classify( $message_body, $channel, array $context ) {
		$lower = mb_strtolower( $message_body );

		// --- Intent ---
		$intent            = 'general';
		$intent_confidence = 0.5;

		$intent_keywords = array(
			'demo_request'           => array( 'demo', 'demonstration', 'walk through', 'walkthrough', 'see it in action' ),
			'pricing_inquiry'        => array( 'pricing', 'price', 'cost', 'how much', 'quote', 'rate', 'package' ),
			'support'                => array( 'help', 'issue', 'problem', 'bug', 'error', 'not working', 'broken', 'fix' ),
			'complaint'              => array( 'complaint', 'unhappy', 'disappointed', 'angry', 'refund', 'cancel', 'never' ),
			'follow_up'              => array( 'following up', 'just checking', 'any update', 'status', 'circling back' ),
			'qualification_response' => array( 'we have budget', 'decision maker', 'timeline', 'authority', 'need' ),
			'unsubscribe'            => array( 'unsubscribe', 'opt out', 'opt-out', 'stop email', 'remove me' ),
		);

		foreach ( $intent_keywords as $intent_slug => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $lower, $kw ) ) {
					$intent            = $intent_slug;
					$intent_confidence = 0.7;
					break 2;
				}
			}
		}

		// --- Spam detection ---
		$is_spam       = false;
		$spam_keywords = array( 'viagra', 'casino', 'lottery', 'you won', 'click here', 'Nigerian prince' );
		foreach ( $spam_keywords as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$is_spam = true;
				break;
			}
		}
		if ( $is_spam ) {
			$intent            = 'spam';
			$intent_confidence = 0.95;
		}

		// --- Sentiment ---
		$sentiment = 'neutral';

		$sentiment_keywords = array(
			'positive' => array( 'great', 'excellent', 'love', 'happy', 'thanks', 'thank you', 'awesome', 'fantastic', 'wonderful' ),
			'negative' => array( 'bad', 'terrible', 'hate', 'awful', 'disappoint', 'frustrat', 'angry', 'waste', 'poor' ),
		);

		$positive_hits = 0;
		$negative_hits = 0;

		foreach ( $sentiment_keywords['positive'] as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				++$positive_hits;
			}
		}
		foreach ( $sentiment_keywords['negative'] as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				++$negative_hits;
			}
		}

		if ( $positive_hits > $negative_hits ) {
			$sentiment = 'positive';
		} elseif ( $negative_hits > $positive_hits ) {
			$sentiment = 'negative';
		} elseif ( $positive_hits > 0 && $negative_hits > 0 ) {
			$sentiment = 'mixed';
		}

		// --- Buying signals ---
		$buying_signals = array();
		$buying_kw      = array( 'pricing', 'demo', 'next step', 'timeline', 'budget', 'decision maker', 'authority', 'trial', 'competing', 'competitor', 'implement', 'rollout', 'buy', 'purchase', 'sign' );

		$buying_kw_filterable = apply_filters( 'wp_mcp_ai_crm_buying_signal_keywords', $buying_kw );

		foreach ( $buying_kw_filterable as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$buying_signals[] = $kw;
			}
		}

		return array(
			'intent'            => $intent,
			'intent_confidence' => $intent_confidence,
			'sentiment'         => $sentiment,
			'buying_signals'    => $buying_signals,
			'is_spam'           => $is_spam,
			'summary'           => mb_substr( $message_body, 0, 120 ),
			'classifier'        => 'heuristic',
		);
	}

	/**
	 * Sanitise a classification result.
	 *
	 * @param array $result Raw result.
	 * @return array Sanitised result.
	 */
	private static function sanitise_result( array $result ) {
		return array(
			'intent'            => isset( $result['intent'] ) ? sanitize_key( $result['intent'] ) : 'general',
			'intent_confidence' => isset( $result['intent_confidence'] ) ? (float) $result['intent_confidence'] : 0.0,
			'sentiment'         => isset( $result['sentiment'] ) ? sanitize_key( $result['sentiment'] ) : 'unknown',
			'buying_signals'    => isset( $result['buying_signals'] ) ? array_map( 'sanitize_text_field', (array) $result['buying_signals'] ) : array(),
			'is_spam'           => ! empty( $result['is_spam'] ),
			'summary'           => isset( $result['summary'] ) ? sanitize_text_field( $result['summary'] ) : '',
			'classifier'        => isset( $result['classifier'] ) ? sanitize_key( $result['classifier'] ) : 'unknown',
		);
	}

	/**
	 * Extract BANT qualification fields from a message.
	 *
	 * @param string $message_body Message / conversation text.
	 * @return array BANT assessment (budget, authority, need, timeline — each: score 0–100 + evidence).
	 */
	public static function extract_bant( $message_body ) {
		$lower = mb_strtolower( sanitize_textarea_field( $message_body ) );
		$bant  = array(
			'budget'    => array(
				'score'    => 0,
				'evidence' => '',
			),
			'authority' => array(
				'score'    => 0,
				'evidence' => '',
			),
			'need'      => array(
				'score'    => 0,
				'evidence' => '',
			),
			'timeline'  => array(
				'score'    => 0,
				'evidence' => '',
			),
		);

		// Budget signals.
		$budget_kw = array( 'budget', 'allocated', 'approved', 'funding', 'invest', 'purchase', 'buy', '$', '€', '£', 'price', 'cost' );
		foreach ( $budget_kw as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$bant['budget']['score']     = min( 100, $bant['budget']['score'] + 25 );
				$bant['budget']['evidence'] .= $kw . '; ';
			}
		}

		// Authority signals.
		$authority_kw = array( 'decision maker', 'ceo', 'cto', 'cfo', 'vp', 'director', 'head of', 'i decide', 'my team', 'approve' );
		foreach ( $authority_kw as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$bant['authority']['score']     = min( 100, $bant['authority']['score'] + 25 );
				$bant['authority']['evidence'] .= $kw . '; ';
			}
		}

		// Need signals.
		$need_kw = array( 'need', 'problem', 'challenge', 'pain', 'looking for', 'solution', 'help with', 'struggling', 'current tool', 'replacing', 'requires' );
		foreach ( $need_kw as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$bant['need']['score']     = min( 100, $bant['need']['score'] + 25 );
				$bant['need']['evidence'] .= $kw . '; ';
			}
		}

		// Timeline signals.
		$timeline_kw = array( 'urgent', 'asap', 'immediately', 'this week', 'this month', 'this quarter', 'next month', 'deadline', 'timeline', 'rolling out', 'by', 'soon', 'planning', 'q1', 'q2', 'q3', 'q4' );
		foreach ( $timeline_kw as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$bant['timeline']['score']     = min( 100, $bant['timeline']['score'] + 25 );
				$bant['timeline']['evidence'] .= $kw . '; ';
			}
		}

		return $bant;
	}

	/**
	 * Extract MEDDIC qualification fields from a message.
	 *
	 * @param string $message_body Message / conversation text.
	 * @return array MEDDIC assessment (6 fields, each: score 0–100 + evidence).
	 */
	public static function extract_meddic( $message_body ) {
		$lower  = mb_strtolower( sanitize_textarea_field( $message_body ) );
		$meddic = array(
			'metrics'           => array(
				'score'    => 0,
				'evidence' => '',
			),
			'economic_buyer'    => array(
				'score'    => 0,
				'evidence' => '',
			),
			'decision_criteria' => array(
				'score'    => 0,
				'evidence' => '',
			),
			'decision_process'  => array(
				'score'    => 0,
				'evidence' => '',
			),
			'identify_pain'     => array(
				'score'    => 0,
				'evidence' => '',
			),
			'champion'          => array(
				'score'    => 0,
				'evidence' => '',
			),
		);

		// Metrics.
		foreach ( array( 'roi', 'kpi', 'metric', '%', 'revenue', 'cost saving', 'efficiency', 'increase', 'reduce' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['metrics']['score']     = min( 100, $meddic['metrics']['score'] + 25 );
				$meddic['metrics']['evidence'] .= $kw . '; ';
			}
		}

		// Economic buyer.
		foreach ( array( 'budget owner', 'cfo', 'finance', 'procurement', 'purchasing', 'budget holder' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['economic_buyer']['score']     = min( 100, $meddic['economic_buyer']['score'] + 30 );
				$meddic['economic_buyer']['evidence'] .= $kw . '; ';
			}
		}

		// Decision criteria.
		foreach ( array( 'criteria', 'requirement', 'must have', 'nice to have', 'spec', 'compliance', 'security', 'sla' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['decision_criteria']['score']     = min( 100, $meddic['decision_criteria']['score'] + 20 );
				$meddic['decision_criteria']['evidence'] .= $kw . '; ';
			}
		}

		// Decision process.
		foreach ( array( 'process', 'approval', 'committee', 'review board', 'stakeholder', 'legal', 'sign off', 'sign-off' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['decision_process']['score']     = min( 100, $meddic['decision_process']['score'] + 20 );
				$meddic['decision_process']['evidence'] .= $kw . '; ';
			}
		}

		// Identify pain.
		foreach ( array( 'pain', 'problem', 'challenge', 'difficult', 'hard', 'expensive', 'slow', 'manual', 'error' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['identify_pain']['score']     = min( 100, $meddic['identify_pain']['score'] + 25 );
				$meddic['identify_pain']['evidence'] .= $kw . '; ';
			}
		}

		// Champion.
		foreach ( array( 'champion', 'advocate', 'sponsor', 'championing', 'supporter', 'internal', 'driving' ) as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				$meddic['champion']['score']     = min( 100, $meddic['champion']['score'] + 30 );
				$meddic['champion']['evidence'] .= $kw . '; ';
			}
		}

		return $meddic;
	}
}
