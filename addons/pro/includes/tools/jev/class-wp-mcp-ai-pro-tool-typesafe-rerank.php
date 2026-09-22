<?php
/**
 * Pro tool: TypeSafe Rerank (Jev).
 *
 * General re-ranking of candidate sets (search results, vector-store hits,
 * media entries) against a query using TypeSafe Jev. Each candidate is one
 * score question against a shared query state, batched into a single
 * decision call; candidates are reordered most-relevant-first, dropped
 * below a relevance floor, with a keep-minimum guarantee.
 *
 * Generalizes the Pro research source filter to any candidate list.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TypeSafe Rerank Pro Tool.
 */
class WP_MCP_AI_Pro_Tool_Typesafe_Rerank implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum candidates scored per call.
	 *
	 * @var int
	 */
	const MAX_CANDIDATES = 64;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'typesafe_rerank';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'TypeSafe Rerank (Jev)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Rerank a set of candidates (search results, vector-store hits, media entries) by relevance to a query using the TypeSafe Jev decision model. Each candidate is scored on a 0–3 relevance rubric in a single batched call, then the list is reordered most-relevant-first and candidates below the floor are dropped (a keep-minimum guarantees the list never starves). Requires a TypeSafe key or an OpenRouter key.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reordering retrieved passages before prompt building; trimming oversized candidate lists; scoring search/vector/media results against a query when the Pro research filter is not in play.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Ranking more than 64 candidates in one call (chunk them); ranking candidates that need pairwise comparison rather than query-relative relevance; any ranking that gates state-changing operations alone.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'typesafe_decide', 'semantic_content_search', 'generate_research_report' ),
			'notes'           => __( 'Relevance scores are rubric positions (0 = irrelevant … 3 = directly relevant), not accuracy percentages. Keep snippets short — context rot degrades ranking accuracy.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'The query to rank candidates against.', 'mcp-ai-wpoos-pro' ),
				),
				'candidates'  => array(
					'type'        => 'array',
					'description' => __( 'Candidate list: plain strings, or objects with id/text (any extra keys are preserved).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => array( 'string', 'object' ),
						'properties' => array(
							'id'   => array( 'type' => array( 'string', 'number' ) ),
							'text' => array( 'type' => 'string' ),
						),
					),
				),
				'min_score'   => array(
					'type'        => 'number',
					'minimum'     => 0,
					'maximum'     => 3,
					'default'     => 1.0,
					'description' => __( 'Relevance floor (0–3). Candidates scoring below it are dropped, subject to keep_min.', 'mcp-ai-wpoos-pro' ),
				),
				'keep_min'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 3,
					'description' => __( 'Never drop below this many candidates regardless of scores.', 'mcp-ai-wpoos-pro' ),
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'Optional model override.', 'mcp-ai-wpoos-pro' ),
				),
				'transport'   => array(
					'type'        => 'string',
					'enum'        => array( 'typesafe', 'openrouter' ),
					'default'     => 'typesafe',
					'description' => __( 'Which transport to use.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'query', 'candidates' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'external-api',
			'requires-capability',
			'consumes-tokens',
			'network-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Normalise the candidates argument into entries with id/text.
	 *
	 * @param mixed $candidates Raw candidates argument.
	 * @return array|WP_Error Normalised candidates or WP_Error.
	 */
	private function normalize_candidates( $candidates ) {
		if ( ! is_array( $candidates ) || empty( $candidates ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_candidates',
				__( 'At least one candidate is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$normalized = array();

		foreach ( array_slice( $candidates, 0, self::MAX_CANDIDATES ) as $index => $candidate ) {
			$id   = (string) $index;
			$text = '';

			if ( is_string( $candidate ) ) {
				$text = $candidate;
			} elseif ( is_array( $candidate ) ) {
				if ( isset( $candidate['id'] ) ) {
					$id = sanitize_text_field( (string) $candidate['id'] );
				}
				$text = isset( $candidate['text'] ) && is_string( $candidate['text'] ) ? $candidate['text'] : '';
			}

			$text = sanitize_text_field( $text );

			if ( '' === $text ) {
				continue;
			}

			$normalized[] = array(
				'id'   => $id,
				'text' => $text,
			);
		}

		if ( empty( $normalized ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_candidates',
				__( 'Candidates must contain at least one non-empty text entry.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $normalized;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to run reranking requests. This tool requires administrator privileges.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['query'] ) || ! is_string( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'A query string is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query      = sanitize_text_field( $arguments['query'] );
		$candidates = $this->normalize_candidates( isset( $arguments['candidates'] ) ? $arguments['candidates'] : array() );

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$min_score = isset( $arguments['min_score'] ) && is_numeric( $arguments['min_score'] )
			? max( 0.0, min( 3.0, (float) $arguments['min_score'] ) )
			: 1.0;
		$keep_min  = isset( $arguments['keep_min'] ) ? max( 1, absint( $arguments['keep_min'] ) ) : 3;
		$keep_min  = min( $keep_min, count( $candidates ) );

		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'typesafe';
		$transport = in_array( $transport, array( 'typesafe', 'openrouter' ), true ) ? $transport : 'typesafe';

		$options = array();
		if ( ! empty( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		// One score question per candidate against the shared query state.
		$questions = array();
		foreach ( $candidates as $index => $candidate ) {
			$questions[ 'rank_' . $index ] = array(
				'type'         => 'score',
				'instructions' => sprintf(
					/* translators: %s: candidate text */
					__( 'How relevant is this candidate to the query? Candidate: %s', 'mcp-ai-wpoos-pro' ),
					$candidate['text']
				),
				'criteria'     => array(
					__( 'Not relevant to the query', 'mcp-ai-wpoos-pro' ),
					__( 'Tangentially related', 'mcp-ai-wpoos-pro' ),
					__( 'Relevant and useful', 'mcp-ai-wpoos-pro' ),
					__( 'Directly relevant and detailed', 'mcp-ai-wpoos-pro' ),
				),
			);
		}

		if ( 'openrouter' === $transport ) {
			if ( ! class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
				return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'The OpenRouter client is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$openrouter = new WP_MCP_AI_OpenRouter_Client();
			$result     = $openrouter->create_decision( array( 'query' => $query ), $questions, $options );
		} else {
			if ( ! class_exists( 'WP_MCP_AI_Typesafe_Client' ) ) {
				return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'The TypeSafe client is not available.', 'mcp-ai-wpoos-pro' ) );
			}

			$typesafe = new WP_MCP_AI_Typesafe_Client();
			$result   = $typesafe->decide( array( 'query' => $query ), $questions, $options );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['answers'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The decision provider returned an unexpected response shape.', 'mcp-ai-wpoos-pro' ) );
		}

		$scored = array();
		foreach ( $candidates as $index => $candidate ) {
			$key      = 'rank_' . $index;
			$score    = isset( $result['answers'][ $key ]['score'] ) ? floatval( $result['answers'][ $key ]['score'] ) : floatval( $min_score );
			$scored[] = array(
				'id'    => $candidate['id'],
				'text'  => $candidate['text'],
				'score' => $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$ranked  = array();
		$dropped = array();

		foreach ( $scored as $entry ) {
			if ( $entry['score'] >= $min_score || count( $ranked ) < $keep_min ) {
				$ranked[] = $entry;
			} else {
				$dropped[] = $entry;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'typesafe_rerank_completed',
				'TypeSafe reranking completed.',
				array(
					'model'     => isset( $result['model'] ) ? $result['model'] : '',
					'transport' => $transport,
					'before'    => count( $candidates ),
					'after'     => count( $ranked ),
					'dropped'   => count( $dropped ),
				)
			);
		}

		$usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();

		return array(
			'success'  => true,
			'model'    => isset( $result['model'] ) ? esc_html( $result['model'] ) : '',
			'provider' => 'openrouter' === $transport ? 'openrouter' : 'typesafe',
			'query'    => esc_html( $query ),
			'results'  => $ranked,
			'dropped'  => $dropped,
			'usage'    => array_merge(
				array(
					'input_tokens'      => isset( $usage['input_tokens'] ) ? absint( $usage['input_tokens'] ) : 0,
					'output_tokens'     => isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0,
					'prompt_tokens'     => isset( $usage['input_tokens'] ) ? absint( $usage['input_tokens'] ) : 0,
					'completion_tokens' => isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0,
				),
				$usage
			),
			'cached'   => ! empty( $result['cached'] ),
			'message'  => sprintf(
				/* translators: 1: ranked count, 2: dropped count */
				__( 'Ranked %1$d candidate(s); %2$d dropped below the relevance floor.', 'mcp-ai-wpoos-pro' ),
				count( $ranked ),
				count( $dropped )
			),
		);
	}
}
