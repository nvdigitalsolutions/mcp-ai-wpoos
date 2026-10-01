<?php
/**
 * Pro Jev Classifier Service.
 *
 * Shared decision service for TypeSafe Jev integrations in the Pro addon.
 * Wraps the base decision clients (native TypeSafe + the OpenRouter
 * decisions bridge) behind fail-open helpers so Pro surfaces can use Jev
 * as a routing/classification pre-step without ever becoming chat clients.
 *
 * Every helper is fail-open: when Jev is unavailable, disabled, or errors,
 * the caller receives the unchanged input (or a clear WP_Error) so the
 * existing generative flow always proceeds.
 *
 * @package NV_oOS_Pro
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jev classification / routing helper for Pro surfaces.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Jev_Classifier {

	/**
	 * Default minimum relevance score to keep a source (0–3 rubric).
	 *
	 * @var float
	 */
	const DEFAULT_MIN_RELEVANCE = 1.0;

	/**
	 * Never drop below this many sources regardless of scores.
	 *
	 * @var int
	 */
	const DEFAULT_KEEP_MIN = 5;

	/**
	 * Maximum sources evaluated per Jev request (state-size guard).
	 *
	 * @var int
	 */
	const MAX_SOURCES_PER_CALL = 16;

	/**
	 * Maximum snippet characters sent per source (context-rot guard).
	 *
	 * @var int
	 */
	const MAX_SNIPPET_LENGTH = 500;

	/**
	 * Neutral routing signal returned when Jev is unavailable, errors, or is
	 * unsure about its own complexity read. Never steers the router — code
	 * consumers treat `confidence: 0.65` exactly like "no signal".
	 *
	 * @var array
	 */
	const NEUTRAL_ROUTING_SIGNAL = array(
		'task_type'             => 'general',
		'complexity'            => 1.0,
		'needs_frontier'        => 0.5,
		'confidence'            => 0.65,
		'complexity_confidence' => null,
		'used_jev'              => false,
		'provider'              => '',
		'model'                 => '',
	);

	/**
	 * Whether the Jev classifier is usable on this site.
	 *
	 * True when at least one decision transport is credentialed AND the
	 * base client classes are loaded. Filterable kill-switch via
	 * `wp_mcp_ai_jev_classifier_enabled`.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public static function is_available() {
		/**
		 * Filter the Jev classifier kill-switch.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $enabled Whether the classifier is allowed to run.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_jev_classifier_enabled', true ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Typesafe_Client' ) || ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			return false;
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );

		// Native TypeSafe transport: enabled + credentialed.
		if ( ! empty( $settings['enable_typesafe'] ) && WP_MCP_AI_Credential_Resolver::has_credentials( 'typesafe' ) ) {
			return true;
		}

		// OpenRouter decisions bridge: an OpenRouter key is enough.
		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve the preferred decision transport.
	 *
	 * @since 1.9.0
	 *
	 * @return string|WP_Error 'typesafe', 'openrouter', or WP_Error when neither is usable.
	 */
	public static function get_transport() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );

		if ( ! empty( $settings['enable_typesafe'] ) && WP_MCP_AI_Credential_Resolver::has_credentials( 'typesafe' ) ) {
			return 'typesafe';
		}

		if ( WP_MCP_AI_Credential_Resolver::has_credentials( 'openrouter' ) ) {
			return 'openrouter';
		}

		return new WP_Error(
			'wp_mcp_ai_jev_unavailable',
			__( 'No TypeSafe or OpenRouter credentials are configured for Jev decisions.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Send a decision request through the best available transport.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $state     Content to evaluate (string|object|array).
	 * @param array $questions Question map (type|instructions|criteria).
	 * @param array $options   Optional: model, timeout.
	 * @return array|WP_Error Normalised decision response (model, answers,
	 *                        usage, provider) or WP_Error.
	 */
	public static function decide( $state, $questions, $options = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_jev_unavailable',
				__( 'The Jev decision classifier is not available on this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		$transport = self::get_transport();
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		if ( 'typesafe' === $transport ) {
			$client = new WP_MCP_AI_Typesafe_Client();
			$result = $client->decide( $state, $questions, $options );
		} else {
			$client = new WP_MCP_AI_OpenRouter_Client();
			$result = $client->create_decision( $state, $questions, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['provider'] = $transport;

		return $result;
	}

	/**
	 * Classify a chat prompt for routing decisions (cascade pre-step).
	 *
	 * Returns a routing decision: the task type (choice), a complexity
	 * score on a 0–2 rubric, and whether the prompt likely needs a
	 * frontier model (noul). Callers decide what to do with it — this
	 * helper never routes anything itself.
	 *
	 * @since 1.9.0
	 *
	 * @param array $messages Chat messages (OpenAI-compatible format).
	 * @param array $options  Optional: model, timeout.
	 * @return array|WP_Error Decision array (task_type, complexity,
	 *                        needs_frontier, provider, model) or WP_Error.
	 */
	public static function classify_prompt( $messages, $options = array() ) {
		$prompt = self::extract_user_text( $messages );

		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_jev_no_prompt',
				__( 'No user text found in the provided messages.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = self::decide(
			$prompt,
			array(
				'task_type'      => array(
					'type'         => 'choice',
					'instructions' => 'What kind of task does this request describe?',
					'criteria'     => array(
						'general'       => 'General conversation or simple lookup',
						'coding'        => 'Writing, debugging, or explaining code',
						'writing'       => 'Drafting or editing prose content',
						'analysis'      => 'Data analysis, comparison, or reasoning',
						'summarization' => 'Summarizing provided material',
						'translation'   => 'Translating between languages',
						'other'         => 'Anything else',
					),
				),
				'complexity'     => array(
					'type'         => 'score',
					'instructions' => 'How complex is this request to answer well?',
					'criteria'     => array(
						'Simple lookup or standard procedure',
						'Requires judgment or multiple steps',
						'Unusual edge case or deep expertise needed',
					),
				),
				'needs_frontier' => array(
					'type'         => 'noul',
					'instructions' => 'This request likely needs a frontier model (highest-capability tier) to answer correctly.',
				),
			),
			$options
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$answers = $result['answers'];

		return array(
			'task_type'             => isset( $answers['task_type']['choice'] ) ? $answers['task_type']['choice'] : 'general',
			'complexity'            => isset( $answers['complexity']['score'] ) ? floatval( $answers['complexity']['score'] ) : 1.0,
			'needs_frontier'        => isset( $answers['needs_frontier']['noul'] ) ? floatval( $answers['needs_frontier']['noul'] ) : 0.5,
			'confidence'            => isset( $answers['task_type']['confidence'] ) ? floatval( $answers['task_type']['confidence'] ) : 0.0,
			'complexity_confidence' => isset( $answers['complexity']['confidence'] ) ? floatval( $answers['complexity']['confidence'] ) : 0.0,
			'provider'              => isset( $result['provider'] ) ? $result['provider'] : '',
			'model'                 => isset( $result['model'] ) ? $result['model'] : '',
		);
	}

	/**
	 * Produce a code-owned routing signal for tier selection (proposal 045).
	 *
	 * The decision model supplies the raw classification; this helper owns
	 * the mapping. `confidence = (2 - complexity) / 2` (complexity 0 → 1.0,
	 * complexity 2 → 0.0) expresses "confidence that a cheap tier suffices"
	 * — the exact shape `route_with_tier()` / the depth scheduler consume.
	 *
	 * When Jev is unavailable, errors, or is unsure about its own complexity
	 * read (complexity answer confidence < 0.5), the neutral fallback is
	 * returned so an uncertain decision model never steers the router.
	 *
	 * @since 1.9.0
	 *
	 * @param array $messages Chat messages (OpenAI-compatible format).
	 * @param array $options  Optional: model, timeout.
	 * @return array Routing signal: { task_type, complexity,
	 *               needs_frontier, confidence, complexity_confidence,
	 *               used_jev, provider, model }.
	 */
	public static function routing_signal_for( $messages, $options = array() ) {
		if ( ! self::is_available() ) {
			return self::NEUTRAL_ROUTING_SIGNAL;
		}

		$decision = self::classify_prompt( $messages, $options );
		if ( is_wp_error( $decision ) ) {
			return self::NEUTRAL_ROUTING_SIGNAL;
		}

		$complexity = max( 0.0, min( 2.0, floatval( $decision['complexity'] ) ) );
		$confidence = ( 2.0 - $complexity ) / 2.0;

		$complexity_confidence = isset( $decision['complexity_confidence'] ) ? floatval( $decision['complexity_confidence'] ) : 0.0;

		if ( $complexity_confidence < 0.5 ) {
			// The decision model is unsure about its own complexity read —
			// do not steer the router on an uncertain signal.
			$signal                          = self::NEUTRAL_ROUTING_SIGNAL;
			$signal['used_jev']              = true;
			$signal['complexity_confidence'] = $complexity_confidence;
			$signal['provider']              = isset( $decision['provider'] ) ? $decision['provider'] : '';
			$signal['model']                 = isset( $decision['model'] ) ? $decision['model'] : '';

			return $signal;
		}

		return array(
			'task_type'             => sanitize_key( (string) $decision['task_type'] ),
			'complexity'            => $complexity,
			'needs_frontier'        => max( 0.0, min( 1.0, floatval( $decision['needs_frontier'] ) ) ),
			'confidence'            => $confidence,
			'complexity_confidence' => $complexity_confidence,
			'used_jev'              => true,
			'provider'              => isset( $decision['provider'] ) ? $decision['provider'] : '',
			'model'                 => isset( $decision['model'] ) ? $decision['model'] : '',
		);
	}

	/**
	 * Rank + filter research sources by relevance to a query.
	 *
	 * The "retrieve, then judge" pattern: one Score question per source
	 * against a shared state, batched to respect the decision context
	 * window. Sources scoring below `min_relevance` are dropped, then the
	 * survivors are reordered most-relevant-first. `keep_min` guarantees a
	 * floor so filtering can never starve the prompt.
	 *
	 * Fail-open: returns the sources unchanged with `used_jev => false`
	 * when unavailable, disabled, or on any error.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $sources Sources array (url, title, snippet).
	 * @param string $query   Research query.
	 * @param array  $args    Optional: min_relevance (float), keep_min (int).
	 * @return array { sources: array, dropped: int, used_jev: bool }.
	 */
	public static function filter_sources_by_relevance( $sources, $query, $args = array() ) {
		$min_relevance = isset( $args['min_relevance'] ) ? floatval( $args['min_relevance'] ) : self::DEFAULT_MIN_RELEVANCE;
		$keep_min      = isset( $args['keep_min'] ) ? absint( $args['keep_min'] ) : self::DEFAULT_KEEP_MIN;

		if ( ! is_array( $sources ) || empty( $sources ) ) {
			return array(
				'sources'  => array(),
				'dropped'  => 0,
				'used_jev' => false,
			);
		}

		// Too few sources for filtering to be worth an external call.
		if ( count( $sources ) <= $keep_min ) {
			return array(
				'sources'  => $sources,
				'dropped'  => 0,
				'used_jev' => false,
			);
		}

		if ( ! self::is_available() ) {
			return array(
				'sources'  => $sources,
				'dropped'  => 0,
				'used_jev' => false,
			);
		}

		// Normalise + truncate the sources for the shared state.
		$state_sources = array();
		foreach ( $sources as $index => $source ) {
			$state_sources[] = array(
				'index'   => $index,
				'title'   => isset( $source['title'] ) ? sanitize_text_field( $source['title'] ) : '',
				'snippet' => isset( $source['snippet'] ) ? wp_trim_words( sanitize_text_field( $source['snippet'] ), self::MAX_SNIPPET_LENGTH / 6 ) : '',
			);
		}

		$scores = array();

		// Batch through MAX_SOURCES_PER_CALL-sized chunks.
		foreach ( array_chunk( $state_sources, self::MAX_SOURCES_PER_CALL ) as $chunk ) {
			$questions = array();
			foreach ( $chunk as $entry ) {
				$questions[ 'relevance_' . $entry['index'] ] = array(
					'type'         => 'score',
					'instructions' => sprintf(
						/* translators: %s: research query */
						__( 'How relevant is this source to the research query "%s"?', 'mcp-ai-wpoos-pro' ),
						$query
					),
					'criteria'     => array(
						__( 'Not relevant to the query', 'mcp-ai-wpoos-pro' ),
						__( 'Tangentially related', 'mcp-ai-wpoos-pro' ),
						__( 'Relevant and useful', 'mcp-ai-wpoos-pro' ),
						__( 'Directly relevant and detailed', 'mcp-ai-wpoos-pro' ),
					),
				);
			}

			$result = self::decide(
				array(
					'query'   => $query,
					'sources' => $chunk,
				),
				$questions
			);

			if ( is_wp_error( $result ) ) {
				// Fail-open: any transport error keeps the original order.
				WP_MCP_AI_Logger::log_event(
					'jev_source_filter_failed',
					'Jev source relevance filtering failed; keeping all sources.',
					array( 'error' => $result->get_error_code() )
				);

				return array(
					'sources'  => $sources,
					'dropped'  => 0,
					'used_jev' => false,
				);
			}

			foreach ( $chunk as $entry ) {
				$key                       = 'relevance_' . $entry['index'];
				$scores[ $entry['index'] ] = isset( $result['answers'][ $key ]['score'] )
					? floatval( $result['answers'][ $key ]['score'] )
					: floatval( $min_relevance );
			}
		}

		// Sort by score descending (stable for ties).
		uksort(
			$scores,
			static function ( $a, $b ) use ( $scores ) {
				$diff = $scores[ $b ] - $scores[ $a ];
				if ( 0.0 === $diff ) {
					return $a - $b;
				}

				return $diff > 0 ? 1 : -1;
			}
		);

		$kept_indexes = array();
		$dropped      = 0;

		foreach ( $scores as $index => $score ) {
			if ( $score >= $min_relevance || ( count( $sources ) - $dropped ) <= $keep_min ) {
				$kept_indexes[] = $index;
			} else {
				++$dropped;
			}
		}

		$filtered = array();
		foreach ( $kept_indexes as $index ) {
			$filtered[] = $sources[ $index ];
		}

		WP_MCP_AI_Logger::log_event(
			'jev_source_filter_applied',
			'Jev relevance filtering applied to research sources.',
			array(
				'before'  => count( $sources ),
				'after'   => count( $filtered ),
				'dropped' => $dropped,
			)
		);

		return array(
			'sources'  => $filtered,
			'dropped'  => $dropped,
			'used_jev' => true,
		);
	}

	/**
	 * Extract the concatenated user text from a messages array.
	 *
	 * @since 1.9.0
	 *
	 * @param array $messages Chat messages.
	 * @return string
	 */
	private static function extract_user_text( $messages ) {
		if ( ! is_array( $messages ) ) {
			return '';
		}

		$parts = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) || 'user' !== $message['role'] ) {
				continue;
			}

			$content = isset( $message['content'] ) ? $message['content'] : '';

			// Support segmented content (array of {type, text}) from the REST layer.
			if ( is_array( $content ) ) {
				foreach ( $content as $segment ) {
					if ( is_array( $segment ) && isset( $segment['text'] ) ) {
						$parts[] = sanitize_text_field( $segment['text'] );
					}
				}
				continue;
			}

			if ( is_string( $content ) && '' !== trim( $content ) ) {
				$parts[] = sanitize_text_field( $content );
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Verify `[n]` citation markers in a report against their sources.
	 *
	 * The TypeSafe "double-checking citations" pattern: for each unique
	 * cited source (bounded by `$max`), extract the sentence containing the
	 * marker, send it together with the source snippet as state, and ask one
	 * noul question — "does the source passage support the claim?".
	 *
	 * Fail-open: any unavailable transport or per-citation error simply
	 * skips that citation (returns whatever checks succeeded).
	 *
	 * @since 1.9.0
	 *
	 * @param string $report_text Report markdown text with [n] markers.
	 * @param array  $sources     Source list (url/title/snippet entries).
	 * @param int    $max         Maximum citations to verify (default 10).
	 * @return array List of { citation, source_title, supported, probability }.
	 */
	public static function check_citations( $report_text, $sources, $max = 10 ) {
		if ( ! is_string( $report_text ) || '' === $report_text || ! is_array( $sources ) || empty( $sources ) ) {
			return array();
		}

		if ( ! self::is_available() ) {
			return array();
		}

		$rows   = self::citation_claim_rows( $report_text, $sources, $max );
		$checks = array();

		foreach ( $rows as $row ) {
			$snippet = wp_strip_all_tags( $row['body'] );
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 800 ) : substr( $snippet, 0, 800 );

			$decision = self::decide(
				array(
					'claim'  => $row['claim'],
					'source' => $snippet,
				),
				array(
					'supported' => array(
						'type'         => 'noul',
						'instructions' => 'The report makes a claim and cites this source. Does the source passage support the claim?',
					),
				)
			);

			// Fail-open per citation.
			if ( is_wp_error( $decision ) ) {
				continue;
			}

			$probability = isset( $decision['answers']['supported']['noul'] ) ? floatval( $decision['answers']['supported']['noul'] ) : null;

			$checks[] = array(
				'citation'     => $row['index'],
				'source_title' => $row['title'],
				'supported'    => null === $probability ? null : ( $probability >= 0.5 ),
				'probability'  => $probability,
			);
		}

		return $checks;
	}

	/**
	 * Verify report citations with the per-claim verification battery
	 * (proposal 045, G5 consumer 1).
	 *
	 * The cascade variant of {@see check_citations()}: each claim is checked
	 * with two "bad = true" noul heads (hallucinated, off_target) against
	 * the source passage, batched in one decision call. A claim is marked
	 * unsupported when any head fires above the cascade threshold.
	 *
	 * Fail-open per citation: unavailable transport or verifier error skips
	 * the citation (returns whatever checks succeeded).
	 *
	 * @since 1.9.0
	 *
	 * @param string                              $report_text Report markdown text.
	 * @param array                               $sources     Source list.
	 * @param int                                 $max         Maximum citations (default 10).
	 * @param WP_MCP_AI_Verification_Cascade|null $cascade    Optional injected cascade (tests).
	 * @return array List of { citation, source_title, claim, supported,
	 *               probability, fired, fired_metrics }.
	 */
	public static function check_citations_cascade( $report_text, $sources, $max = 10, $cascade = null ) {
		if ( ! is_string( $report_text ) || '' === $report_text || ! is_array( $sources ) || empty( $sources ) ) {
			return array();
		}

		if ( ! self::is_available() ) {
			return array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Verification_Cascade' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-verification-cascade.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Verification_Cascade' ) ) {
			return array();
		}

		$cascade = ( $cascade instanceof WP_MCP_AI_Verification_Cascade ) ? $cascade : new WP_MCP_AI_Verification_Cascade();

		$rows   = self::citation_claim_rows( $report_text, $sources, $max );
		$checks = array();

		foreach ( $rows as $row ) {
			$snippet = wp_strip_all_tags( $row['body'] );
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 800 ) : substr( $snippet, 0, 800 );

			$evaluation = $cascade->evaluate(
				$cascade->build_battery(
					array( 'claim' => $row['claim'] ),
					array(
						'claim' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'The claim the report attributes to the cited source', 'mcp-ai-wpoos-pro' ),
						),
					),
					$snippet,
					array( 'hallucinated', 'off_target' )
				)
			);

			// Fail-open per citation: a total verifier outage skips the check.
			if ( ! $evaluation['used_jev'] && null !== $evaluation['error'] ) {
				continue;
			}

			$fired_metrics = array_keys( $evaluation['fired'] );
			$max_prob      = 0.0;
			foreach ( $evaluation['answers'] as $probability ) {
				$max_prob = max( $max_prob, floatval( $probability ) );
			}

			$supported = empty( $fired_metrics );

			$checks[] = array(
				'citation'      => $row['index'],
				'source_title'  => $row['title'],
				'claim'         => $row['claim'],
				'supported'     => $supported,
				'probability'   => round( 1.0 - $max_prob, 4 ),
				'fired'         => ! $supported,
				'fired_metrics' => $fired_metrics,
			);
		}

		return $checks;
	}

	/**
	 * Re-generate flagged citation claims on the verification tier
	 * (proposal 045, G5 consumer 1).
	 *
	 * For each check with `fired => true`, asks the verification-tier model
	 * for a revision strictly supported by the source passage. Single-rung:
	 * any error, empty revision, or `UNSUPPORTED` answer keeps the original
	 * claim (fail-open — the caller never loses text).
	 *
	 * @since 1.9.0
	 *
	 * @param string $report_text Report text (unused; kept for symmetry).
	 * @param array  $sources     Source list.
	 * @param array  $checks      Checks from {@see check_citations_cascade()}.
	 * @param string $provider    Optional provider override (defaults to the
	 *                            site priority list's first provider).
	 * @param array  $options     Optional: max_tokens.
	 * @return array List of { citation, claim, replacement, replaced, error }.
	 */
	public static function escalate_flagged_citations( $report_text, $sources, $checks, $provider = '', $options = array() ) {
		if ( ! is_array( $checks ) || empty( $checks ) || ! is_array( $sources ) ) {
			return array();
		}

		if ( ! self::is_available() ) {
			return array();
		}

		/**
		 * Filter the maximum number of escalation LLM calls per report.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max_attempts Maximum revisions per report (default 3).
		 */
		$max_attempts = apply_filters( 'wp_mcp_ai_citation_escalation_max', 3 );
		$max_attempts = max( 1, absint( $max_attempts ) );

		$replacements = array();
		$attempts     = 0;

		foreach ( $checks as $check ) {
			if ( $attempts >= $max_attempts ) {
				break;
			}

			if ( ! is_array( $check ) || empty( $check['fired'] ) || empty( $check['claim'] ) ) {
				continue;
			}

			$citation    = isset( $check['citation'] ) ? absint( $check['citation'] ) : 0;
			$source_body = self::source_body_for( $sources, $citation );

			if ( '' === $source_body ) {
				continue;
			}

			++$attempts;

			$revision = self::revise_claim( $check['claim'], $source_body, $provider, $options );

			if ( is_wp_error( $revision ) ) {
				$replacements[] = array(
					'citation'    => $citation,
					'claim'       => $check['claim'],
					'replacement' => null,
					'replaced'    => false,
					'error'       => $revision->get_error_code(),
				);
				continue;
			}

			$revision = trim( (string) $revision );

			if ( '' === $revision || 'UNSUPPORTED' === strtoupper( $revision ) ) {
				$replacements[] = array(
					'citation'    => $citation,
					'claim'       => $check['claim'],
					'replacement' => null,
					'replaced'    => false,
					'error'       => 'unsupported',
				);
				continue;
			}

			$replacements[] = array(
				'citation'    => $citation,
				'claim'       => $check['claim'],
				'replacement' => $revision,
				'replaced'    => true,
				'error'       => null,
			);
		}

		return $replacements;
	}

	/**
	 * Apply claim replacements to a report body.
	 *
	 * @since 1.9.0
	 *
	 * @param string $report_text  Report text.
	 * @param array  $replacements Replacements from
	 *                             {@see escalate_flagged_citations()}.
	 * @return array { text: string, replaced_count: int }.
	 */
	public static function apply_claim_replacements( $report_text, $replacements ) {
		$text           = is_string( $report_text ) ? $report_text : '';
		$replaced_count = 0;

		if ( '' === $text || ! is_array( $replacements ) ) {
			return array(
				'text'           => $text,
				'replaced_count' => 0,
			);
		}

		foreach ( $replacements as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['replaced'] ) || ! isset( $entry['claim'], $entry['replacement'] ) ) {
				continue;
			}

			if ( ! is_string( $entry['claim'] ) || ! is_string( $entry['replacement'] ) || '' === $entry['claim'] ) {
				continue;
			}

			if ( false === strpos( $text, $entry['claim'] ) ) {
				continue;
			}

			$text = str_replace( $entry['claim'], $entry['replacement'], $text );
			++$replaced_count;
		}

		return array(
			'text'           => $text,
			'replaced_count' => $replaced_count,
		);
	}

	/**
	 * Extract deduplicated citation claim rows from a report.
	 *
	 * Shared marker-walking loop for {@see check_citations()} and
	 * {@see check_citations_cascade()}: finds `[n]` markers, resolves each to
	 * its source, and extracts the containing claim sentence.
	 *
	 * @param string $report_text Report markdown text.
	 * @param array  $sources     Source list.
	 * @param int    $max         Maximum rows to return.
	 * @return array List of { index, title, body, claim }.
	 */
	private static function citation_claim_rows( $report_text, $sources, $max = 10 ) {
		preg_match_all( '/\[(\d+)\]/', $report_text, $matches, PREG_OFFSET_CAPTURE );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$rows = array();
		$seen = array();
		$max  = absint( $max );

		foreach ( $matches[1] as $pair ) {
			$index  = absint( $pair[0] );
			$offset = absint( $pair[1] );

			if ( $index < 1 || $index > count( $sources ) || isset( $seen[ $index ] ) ) {
				continue;
			}

			$seen[ $index ] = true;

			$source = $sources[ $index - 1 ];
			if ( ! is_array( $source ) ) {
				continue;
			}

			$title = isset( $source['title'] ) ? sanitize_text_field( $source['title'] ) : ( isset( $source['url'] ) ? esc_url_raw( $source['url'] ) : '' );
			$body  = isset( $source['snippet'] ) ? $source['snippet'] : ( isset( $source['content'] ) ? $source['content'] : '' );

			if ( ! is_string( $body ) || '' === trim( $body ) ) {
				continue;
			}

			$claim = self::extract_claim_sentence( $report_text, $offset );
			if ( '' === $claim ) {
				continue;
			}

			$rows[] = array(
				'index' => $index,
				'title' => $title,
				'body'  => $body,
				'claim' => $claim,
			);

			if ( count( $rows ) >= $max ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Resolve the source body for a 1-based citation index.
	 *
	 * @param array $sources  Source list.
	 * @param int   $citation Citation index.
	 * @return string Source body (snippet preferred, else content).
	 */
	private static function source_body_for( $sources, $citation ) {
		$citation = absint( $citation );

		if ( $citation < 1 || $citation > count( $sources ) ) {
			return '';
		}

		$source = $sources[ $citation - 1 ];
		if ( ! is_array( $source ) ) {
			return '';
		}

		$body = isset( $source['snippet'] ) ? $source['snippet'] : ( isset( $source['content'] ) ? $source['content'] : '' );

		return is_string( $body ) ? $body : '';
	}

	/**
	 * Ask the verification-tier model to revise a flagged claim.
	 *
	 * @param string $claim       Original claim sentence.
	 * @param string $source_body Source passage the claim must be grounded in.
	 * @param string $provider    Provider slug.
	 * @param array  $options     Optional: max_tokens.
	 * @return string|WP_Error Revised claim, UNSUPPORTED, or WP_Error.
	 */
	private static function revise_claim( $claim, $source_body, $provider, $options ) {
		$router = self::get_router();
		if ( is_wp_error( $router ) ) {
			return $router;
		}

		$provider = sanitize_key( '' !== (string) $provider ? (string) $provider : self::default_provider() );
		$config   = $router->get_verification_model_for_provider( $provider );

		$snippet = wp_strip_all_tags( $source_body );
		$snippet = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 1200 ) : substr( $snippet, 0, 1200 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => __( 'You revise claims so they are strictly supported by a cited source passage. Return only the revised claim, preserving any citation marker such as [2] at the end, or the exact word UNSUPPORTED when no supported revision is possible.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'role'    => 'user',
				'content' => sprintf(
					/* translators: 1: original claim, 2: source passage */
					__( "Claim: %1\$s\n\nSource passage:\n%2\$s", 'mcp-ai-wpoos-pro' ),
					$claim,
					$snippet
				),
			),
		);

		$result = $router->create_chat_completion(
			$messages,
			array(
				'provider'    => $config['provider'],
				'model'       => $config['model'],
				'temperature' => 0.3,
				'max_tokens'  => isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 300,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = self::extract_chat_text( $result );

		return '' === $text
			? new WP_Error( 'wp_mcp_ai_jev_escalation_empty', __( 'The revision model returned no text.', 'mcp-ai-wpoos-pro' ) )
			: $text;
	}

	/**
	 * Resolve the router used for citation escalation.
	 *
	 * Honors the `wp_mcp_ai_jev_escalation_router` filter, then the DI
	 * container, then a direct construction.
	 *
	 * @return WP_MCP_AI_Language_Model_Router|WP_Error
	 */
	private static function get_router() {
		/**
		 * Filter the router used for citation escalation.
		 *
		 * @since 1.9.0
		 *
		 * @param WP_MCP_AI_Language_Model_Router|WP_Error|null $router Router override.
		 */
		$router = apply_filters( 'wp_mcp_ai_jev_escalation_router', null );

		if ( $router instanceof WP_MCP_AI_Language_Model_Router ) {
			return $router;
		}

		if ( is_wp_error( $router ) ) {
			return $router;
		}

		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container && $container->has( 'router' ) ) {
				return $container->get( 'router' );
			}
		}

		return new WP_MCP_AI_Language_Model_Router(
			new WP_MCP_AI_OpenAI_Client(),
			new WP_MCP_AI_Gemini_Client()
		);
	}

	/**
	 * Resolve the default provider for escalation requests.
	 *
	 * @return string Provider slug.
	 */
	private static function default_provider() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ? WP_MCP_AI_Admin_Settings_Base::get_settings() : get_option( 'wp_mcp_ai_settings', array() );
		$priority = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] ) ? $settings['provider_priority_list'] : array();

		return ! empty( $priority[0] ) ? sanitize_key( $priority[0] ) : 'openai';
	}

	/**
	 * Tolerantly extract prose from a chat-completion response.
	 *
	 * Provider clients return different shapes; this helper reads the common
	 * ones without coupling to any single client.
	 *
	 * @param mixed $result Chat completion result.
	 * @return string Extracted text (empty when nothing found).
	 */
	private static function extract_chat_text( $result ) {
		if ( ! is_array( $result ) ) {
			return '';
		}

		foreach ( array( 'content', 'text', 'response' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				return $result[ $key ];
			}
		}

		if ( isset( $result['choices'][0]['message']['content'] ) && is_string( $result['choices'][0]['message']['content'] ) ) {
			return $result['choices'][0]['message']['content'];
		}

		if ( isset( $result['message']['content'] ) && is_string( $result['message']['content'] ) ) {
			return $result['message']['content'];
		}

		return '';
	}

	/**
	 * Extract the sentence containing a given byte offset.
	 *
	 * Finds the nearest sentence/line boundaries around the offset and
	 * returns the bounded sentence (max 500 characters).
	 *
	 * @param string $report_text Report text.
	 * @param int    $offset      Byte offset of the citation marker.
	 * @return string
	 */
	private static function extract_claim_sentence( $report_text, $offset ) {
		$length = strlen( $report_text );

		if ( $offset >= $length ) {
			return '';
		}

		$prefix = substr( $report_text, 0, $offset );
		$start  = 0;

		foreach ( array( '. ', "\n", "\r" ) as $delimiter ) {
			$pos = strrpos( $prefix, $delimiter );
			if ( false !== $pos ) {
				$start = max( $start, $pos + 1 );
			}
		}

		$suffix = substr( $report_text, $offset );
		$end    = strlen( $suffix );

		foreach ( array( '. ', "\n", "\r" ) as $delimiter ) {
			$pos = strpos( $suffix, $delimiter );
			if ( false !== $pos ) {
				$end = min( $end, $pos );
			}
		}

		$sentence = trim( substr( $report_text, $start, ( $offset - $start ) + $end ) );
		$sentence = function_exists( 'mb_substr' ) ? mb_substr( $sentence, 0, 500 ) : substr( $sentence, 0, 500 );

		return $sentence;
	}
}
