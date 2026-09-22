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
			'task_type'      => isset( $answers['task_type']['choice'] ) ? $answers['task_type']['choice'] : 'general',
			'complexity'     => isset( $answers['complexity']['score'] ) ? floatval( $answers['complexity']['score'] ) : 1.0,
			'needs_frontier' => isset( $answers['needs_frontier']['noul'] ) ? floatval( $answers['needs_frontier']['noul'] ) : 0.5,
			'confidence'     => isset( $answers['task_type']['confidence'] ) ? floatval( $answers['task_type']['confidence'] ) : 0.0,
			'provider'       => isset( $result['provider'] ) ? $result['provider'] : '',
			'model'          => isset( $result['model'] ) ? $result['model'] : '',
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

		preg_match_all( '/\[(\d+)\]/', $report_text, $matches, PREG_OFFSET_CAPTURE );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$checks = array();
		$seen   = array();

		foreach ( $matches[1] as $pair ) {
			$index  = absint( $pair[0] );
			$offset = absint( $pair[1] );

			if ( $index < 1 || $index > count( $sources ) || isset( $seen[ $index ] ) ) {
				continue;
			}

			$seen[ $index ] = true;

			$source = $sources[ $index - 1 ];
			$title  = isset( $source['title'] ) ? sanitize_text_field( $source['title'] ) : ( isset( $source['url'] ) ? esc_url_raw( $source['url'] ) : '' );
			$body   = isset( $source['snippet'] ) ? $source['snippet'] : ( isset( $source['content'] ) ? $source['content'] : '' );

			if ( ! is_string( $body ) || '' === trim( $body ) ) {
				continue;
			}

			$claim = self::extract_claim_sentence( $report_text, $offset );
			if ( '' === $claim ) {
				continue;
			}

			$snippet = wp_strip_all_tags( $body );
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 800 ) : substr( $snippet, 0, 800 );

			$decision = self::decide(
				array(
					'claim'  => $claim,
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
				'citation'     => $index,
				'source_title' => $title,
				'supported'    => null === $probability ? null : ( $probability >= 0.5 ),
				'probability'  => $probability,
			);

			if ( count( $checks ) >= absint( $max ) ) {
				break;
			}
		}

		return $checks;
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
