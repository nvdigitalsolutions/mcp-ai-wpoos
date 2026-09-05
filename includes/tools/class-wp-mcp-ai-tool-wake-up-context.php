<?php
/**
 * Tool that produces a "wake-up" context block for assistant boot.
 *
 * Inspired by the MemPalace project (https://github.com/MemPalace/mempalace),
 * which the WordPress plugin's hierarchical memory model is loosely modelled on.
 * Phase 2 enhancement: retrieves the top-N most-relevant memories for a given
 * agent (optionally scoped to a wing/room) and returns them formatted as a
 * labeled block ready to be prepended to the system prompt.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a wake-up memory block for an assistant.
 *
 * Reuses {@see WP_MCP_AI_Tool_Retrieve_Agent_Memory} so the existing wing/room
 * filters and the hybrid retrieval scoring boosters all apply identically.
 * The output is intentionally compact and TPM-aware: a configurable token
 * budget is enforced and any truncation is reported in the response.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Wake_Up_Context implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Default maximum number of memories pulled before token-budget pruning.
	 *
	 * @var int
	 */
	const DEFAULT_TOP_N = 5;

	/**
	 * Default token budget. Chosen conservatively so that loading the wake-up
	 * block does not eat into the assistant's reasoning headroom.
	 *
	 * @var int
	 */
	const DEFAULT_TOKEN_BUDGET = 800;

	/**
	 * Heading printed at the top of the wake-up block.
	 *
	 * @var string
	 */
	const BLOCK_HEADING = '=== Persistent Memory (auto-loaded at session start) ===';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'wake_up_context';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Wake-Up Context Loader', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the top-N most-relevant memories for an agent and returns them as a compact, labeled text block ready to prepend to the system prompt at session boot. Optionally scoped to a wing/room. Honours a token budget so it never blows past TPM limits.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier.', 'mcp-ai-wpoos' ),
				),
				'wing'            => array(
					'type'        => 'string',
					'description' => __( 'Optional wing (project/person scope) to restrict the wake-up to.', 'mcp-ai-wpoos' ),
				),
				'room'            => array(
					'type'        => 'string',
					'description' => __( 'Optional room (topic cluster) to restrict the wake-up to.', 'mcp-ai-wpoos' ),
				),
				'query'           => array(
					'type'        => 'string',
					'description' => __( 'Optional natural-language query that biases ranking toward a current task. When omitted, the most-important and most-recent memories surface.', 'mcp-ai-wpoos' ),
				),
				'top_n'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of memories to consider before token-budget pruning.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => self::DEFAULT_TOP_N,
				),
				'token_budget'    => array(
					'type'        => 'integer',
					'description' => __( 'Approximate maximum tokens for the rendered block (~4 chars per token). Records that would exceed the budget are dropped (lowest-priority first).', 'mcp-ai-wpoos' ),
					'minimum'     => 50,
					'maximum'     => 8000,
					'default'     => self::DEFAULT_TOKEN_BUDGET,
				),
				'context_types'   => array(
					'type'        => 'array',
					'description' => __( 'Restrict wake-up to specific context types.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'min_importance'  => array(
					'type'        => 'string',
					'description' => __( 'Minimum importance level for memories included in the wake-up block.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, include the full content of each memory in the rendered block. When false, only the title and metadata are rendered (smallest possible block).', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'mode'            => array(
					'type'        => 'string',
					'description' => __( 'Retrieval strategy. "auto" (default) uses graph traversal when the Graphify addon is active, and falls back to the transient + cosine path otherwise. "graph" forces graph traversal (errors if Graphify is unavailable). "transient" forces the legacy path even when Graphify is present.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'auto', 'graph', 'transient' ),
					'default'     => 'auto',
				),
			),
			'required'             => array( 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( empty( $arguments['agent_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_error', __( 'Agent ID is required.', 'mcp-ai-wpoos' ) );
		}

		$agent_id        = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$wing            = isset( $arguments['wing'] ) ? sanitize_text_field( $arguments['wing'] ) : '';
		$room            = isset( $arguments['room'] ) ? sanitize_text_field( $arguments['room'] ) : '';
		$query           = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$top_n           = isset( $arguments['top_n'] ) ? max( 1, min( 50, absint( $arguments['top_n'] ) ) ) : self::DEFAULT_TOP_N;
		$token_budget    = isset( $arguments['token_budget'] ) ? max( 50, min( 8000, absint( $arguments['token_budget'] ) ) ) : self::DEFAULT_TOKEN_BUDGET;
		$include_content = isset( $arguments['include_content'] ) ? (bool) $arguments['include_content'] : true;
		$mode            = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'auto';
		if ( ! in_array( $mode, array( 'auto', 'graph', 'transient' ), true ) ) {
			$mode = 'auto';
		}

		/**
		 * Filter the maximum number of memories considered for wake-up before
		 * token-budget pruning.
		 *
		 * @since 1.1.0
		 *
		 * @param int        $top_n   Caller-requested top-N (already clamped).
		 * @param int|string $agent_id Agent identifier.
		 * @param string     $wing    Wing scope (may be empty).
		 * @param string     $room    Room scope (may be empty).
		 */
		$top_n = (int) apply_filters( 'wp_mcp_ai_wake_up_top_n', $top_n, $agent_id, $wing, $room );

		/**
		 * Filter the token budget for the rendered wake-up block.
		 *
		 * @since 1.1.0
		 *
		 * @param int        $token_budget Caller-requested token budget (already clamped).
		 * @param int|string $agent_id     Agent identifier.
		 * @param string     $wing         Wing scope (may be empty).
		 * @param string     $room         Room scope (may be empty).
		 */
		$token_budget = (int) apply_filters( 'wp_mcp_ai_wake_up_token_budget', $token_budget, $agent_id, $wing, $room );

		// Build retrieval call.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$retrieve = $registry->get_tool( 'retrieve_agent_memory' );
		if ( ! $retrieve ) {
			return new WP_Error( 'wp_mcp_ai_error', __( 'retrieve_agent_memory tool is not available.', 'mcp-ai-wpoos' ) );
		}

		$filters = array();
		if ( '' !== $wing ) {
			$filters['wing'] = $wing;
		}
		if ( '' !== $room ) {
			$filters['room'] = $room;
		}
		if ( ! empty( $arguments['context_types'] ) && is_array( $arguments['context_types'] ) ) {
			$filters['context_types'] = array_map( 'sanitize_key', $arguments['context_types'] );
		}
		if ( ! empty( $arguments['min_importance'] ) ) {
			$importance_order = array( 'low', 'medium', 'high', 'critical' );
			$min              = sanitize_key( $arguments['min_importance'] );
			$min_index        = array_search( $min, $importance_order, true );
			if ( false !== $min_index ) {
				$filters['importance'] = array_slice( $importance_order, $min_index );
			}
		}

		$retrieve_args = array(
			'agent_id' => $agent_id,
			'limit'    => $top_n,
		);
		if ( ! empty( $filters ) ) {
			$retrieve_args['filters'] = $filters;
		}
		if ( '' !== $query ) {
			$retrieve_args['query'] = $query;
		}

		// Resolve whether the graph path should be used.
		$graphify_available = class_exists( 'NV_oOS_Graphify_Memory_Bridge' );
		$use_graph          = false;
		if ( 'graph' === $mode ) {
			if ( ! $graphify_available ) {
				return new WP_Error( 'wp_mcp_ai_error', __( 'Graph mode requested but the Graphify addon is not active.', 'mcp-ai-wpoos' ) );
			}
			$use_graph = true;
		} elseif ( 'auto' === $mode && $graphify_available ) {
			$use_graph = true;
		}

		$retrieval_path = 'transient';
		$result         = null;
		// Map of context_id => array of provenance signals ('agent','wing','room','keyword','vector')
		// surfaced in the response for observability — same shape exposed by mem0/Letta retrieval logs.
		$graph_via = array();

		if ( $use_graph ) {
			// The graph bridge is advisory — any failure (WP_Error, throwable,
			// malformed rows) must degrade to the transient retrieval path
			// instead of surfacing as a fatal tool error (fix #5).
			try {
				$ranked = NV_oOS_Graphify_Memory_Bridge::retrieve_graph(
					array(
						'agent_id' => $agent_id,
						'wing'     => $wing,
						'room'     => $room,
						'query'    => $query,
						'limit'    => $top_n,
					)
				);
			} catch ( \Throwable $e ) {
				$ranked = array();
			}
			if ( is_wp_error( $ranked ) || ! is_array( $ranked ) ) {
				$ranked = array();
			}

			foreach ( $ranked as $r ) {
				if ( ! empty( $r['context_id'] ) ) {
					$graph_via[ (string) $r['context_id'] ] = isset( $r['via'] ) && is_array( $r['via'] ) ? array_values( $r['via'] ) : array();
				}
			}

			$context_ids = array_values(
				array_filter(
					array_map(
						static function ( $r ) {
							return isset( $r['context_id'] ) ? (string) $r['context_id'] : '';
						},
						$ranked
					)
				)
			);

			/**
			 * Filter the ordered context_id list produced by graph retrieval
			 * before each memory is fetched from the underlying store.
			 *
			 * @since 1.1.0
			 *
			 * @param string[]   $context_ids Ordered list of context ids.
			 * @param array      $ranked      Raw graph-retrieval scores: [{context_id, score, via}].
			 * @param int|string $agent_id    Agent identifier.
			 * @param string     $wing        Wing scope (may be empty).
			 * @param string     $room        Room scope (may be empty).
			 * @param string     $query       Query string (may be empty).
			 */
			$context_ids = (array) apply_filters(
				'wp_mcp_ai_wake_up_graph_context_ids',
				$context_ids,
				$ranked,
				$agent_id,
				$wing,
				$room,
				$query
			);

			if ( ! empty( $context_ids ) ) {
				// Fetch each memory by id using the existing single-id retrieval
				// surface; this keeps filters/expiry handling consistent with
				// the legacy path while preserving graph-determined order.
				$contexts = array();
				foreach ( $context_ids as $cid ) {
					$single = $retrieve->execute(
						array(
							'agent_id'   => $agent_id,
							'context_id' => $cid,
						),
						$context
					);
					// The tool contract allows execute() to return WP_Error
					// (e.g. the graph-ranked context expired or was deleted
					// between ranking and fetch). Never index it like an array.
					if ( is_wp_error( $single ) || empty( $single['success'] ) || empty( $single['contexts'] ) ) {
						continue;
					}
					$record = $single['contexts'][0];
					if ( ! is_array( $record ) || ! $this->matches_wake_filters( $record, $filters ) ) {
						continue;
					}
					$contexts[] = $record;
					if ( count( $contexts ) >= $top_n ) {
						break;
					}
				}

				if ( ! empty( $contexts ) ) {
					$result         = array(
						'success'  => true,
						'contexts' => $contexts,
						'count'    => count( $contexts ),
					);
					$retrieval_path = 'graph';
				}
			}
			// Falls through to legacy retrieval when the graph yields nothing.
		}

		if ( null === $result ) {
			$result = $retrieve->execute( $retrieve_args, $context );
		}
		// WP_Error is a valid execute() return per the canonical envelope.
		if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['contexts'] ) ) {
			self::record_retrieval_telemetry( $retrieval_path );
			return array(
				'success'        => true,
				'message'        => __( 'No memories found for wake-up.', 'mcp-ai-wpoos' ),
				'system_block'   => '',
				'count'          => 0,
				'truncated'      => 0,
				'tokens_used'    => 0,
				'token_budget'   => $token_budget,
				'wing'           => $wing,
				'room'           => $room,
				'agent_id'       => $agent_id,
				'retrieval_path' => $retrieval_path,
			);
		}

		$contexts = $result['contexts'];

		// Token-budget pruning: render greedily, drop overflow records.
		$rendered     = array();
		$truncated    = 0;
		$tokens_used  = 0;
		$header       = self::BLOCK_HEADING . "\n";
		$header_cost  = $this->estimate_tokens( $header ) + 16; // small overhead for the closing line.
		$tokens_used += $header_cost;
		$remaining    = max( 0, $token_budget - $header_cost );

		foreach ( $contexts as $memory ) {
			$rendered_entry = $this->render_memory_entry( $memory, $include_content );
			$entry_tokens   = $this->estimate_tokens( $rendered_entry );

			if ( $entry_tokens > $remaining ) {
				++$truncated;
				continue;
			}

			$rendered[]   = $rendered_entry;
			$tokens_used += $entry_tokens;
			$remaining   -= $entry_tokens;
		}

		if ( empty( $rendered ) ) {
			self::record_retrieval_telemetry( $retrieval_path );
			return array(
				'success'        => true,
				'message'        => __( 'Token budget too small to render any memory entries.', 'mcp-ai-wpoos' ),
				'system_block'   => '',
				'count'          => 0,
				'truncated'      => $truncated,
				'tokens_used'    => 0,
				'token_budget'   => $token_budget,
				'wing'           => $wing,
				'room'           => $room,
				'agent_id'       => $agent_id,
				'retrieval_path' => $retrieval_path,
			);
		}

		$footer       = "\n=== End persistent memory ===";
		$tokens_used += $this->estimate_tokens( $footer );

		$system_block = $header . implode( "\n\n", $rendered ) . $footer;

		/**
		 * Filter the rendered wake-up block before it is returned to the caller.
		 *
		 * Plugins can reformat, append disclaimers, or strip sections.
		 *
		 * @since 1.1.0
		 *
		 * @param string     $system_block Rendered block (header + entries + footer).
		 * @param array      $contexts     The memories that fed the block.
		 * @param int|string $agent_id     Agent identifier.
		 * @param string     $wing         Wing scope (may be empty).
		 * @param string     $room         Room scope (may be empty).
		 */
		$system_block = (string) apply_filters( 'wp_mcp_ai_wake_up_system_block', $system_block, $contexts, $agent_id, $wing, $room );

		self::record_retrieval_telemetry( $retrieval_path );

		return array(
			'success'         => true,
			'system_block'    => $system_block,
			'count'           => count( $rendered ),
			'truncated'       => $truncated,
			'tokens_used'     => $tokens_used,
			'token_budget'    => $token_budget,
			'wing'            => $wing,
			'room'            => $room,
			'agent_id'        => $agent_id,
			'retrieval_path'  => $retrieval_path,
			'memories_loaded' => array_map(
				static function ( $memory ) use ( $graph_via ) {
					$cid = isset( $memory['context_id'] ) ? $memory['context_id'] : '';
					return array(
						'context_id' => $cid,
						'title'      => isset( $memory['title'] ) ? $memory['title'] : '',
						'importance' => isset( $memory['importance'] ) ? $memory['importance'] : 'medium',
						'wing'       => isset( $memory['wing'] ) ? $memory['wing'] : '',
						'room'       => isset( $memory['room'] ) ? $memory['room'] : '',
						// Provenance: which retrieval signals matched this memory
						// (graph mode only — empty array when the transient
						// path serviced the request, since cosine search there
						// is single-signal). Mirrors mem0/Letta retrieval-log
						// observability conventions.
						'via'        => isset( $graph_via[ $cid ] ) ? $graph_via[ $cid ] : array(),
					);
				},
				array_slice( $contexts, 0, count( $rendered ) )
			),
		);
	}

	/**
	 * Render a single memory entry as a compact text block.
	 *
	 * @param array $memory          Formatted memory record.
	 * @param bool  $include_content Whether to include the full content.
	 * @return string
	 */
	private function render_memory_entry( $memory, $include_content ) {
		$title      = isset( $memory['title'] ) ? (string) $memory['title'] : '';
		$type       = isset( $memory['context_type'] ) ? (string) $memory['context_type'] : '';
		$importance = isset( $memory['importance'] ) ? (string) $memory['importance'] : 'medium';
		$wing       = isset( $memory['wing'] ) ? (string) $memory['wing'] : '';
		$room       = isset( $memory['room'] ) ? (string) $memory['room'] : '';
		$tags       = isset( $memory['tags'] ) && is_array( $memory['tags'] ) ? $memory['tags'] : array();

		$meta_parts = array_filter(
			array(
				$type ? sprintf( 'type=%s', $type ) : '',
				$importance ? sprintf( 'importance=%s', $importance ) : '',
				$wing ? sprintf( 'wing=%s', $wing ) : '',
				$room ? sprintf( 'room=%s', $room ) : '',
				! empty( $tags ) ? sprintf( 'tags=%s', implode( ',', array_map( 'strval', $tags ) ) ) : '',
			)
		);
		$meta_line  = ! empty( $meta_parts ) ? '[' . implode( ' ', $meta_parts ) . ']' : '';

		$lines = array();
		if ( '' !== $meta_line ) {
			$lines[] = $meta_line;
		}
		if ( '' !== $title ) {
			$lines[] = '# ' . $title;
		}

		if ( $include_content && ! empty( $memory['content'] ) ) {
			$content = (string) $memory['content'];
			// Compact whitespace, strip control chars.
			$content = preg_replace( '/\s+/u', ' ', $content );
			$content = trim( $content );
			if ( '' !== $content ) {
				$lines[] = $content;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Approximate token count for a string (~4 chars per token).
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private function estimate_tokens( $text ) {
		if ( '' === (string) $text ) {
			return 0;
		}
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/**
	 * Apply wake-up filters against a flattened memory record returned by
	 * retrieve_agent_memory.
	 *
	 * The graph path must enforce wing/room here as well: the graph anchors
	 * only boost scores for wing/room membership, they never exclude
	 * out-of-scope memories (every agent-owned memory remains a candidate
	 * via the agent anchor). Tag/date filters are not exposed by the wake-up
	 * schema in Phase 4a.
	 *
	 * @since 1.1.0
	 *
	 * @param array $record  Flattened memory record (from format_context_result).
	 * @param array $filters Filters built earlier in execute().
	 * @return bool True when the record satisfies the filters.
	 */
	private function matches_wake_filters( array $record, array $filters ) {
		if ( ! empty( $filters['wing'] ) ) {
			$record_wing = isset( $record['wing'] ) ? (string) $record['wing'] : '';
			if ( $record_wing !== $filters['wing'] ) {
				return false;
			}
		}

		if ( ! empty( $filters['room'] ) ) {
			$record_room = isset( $record['room'] ) ? (string) $record['room'] : '';
			if ( $record_room !== $filters['room'] ) {
				return false;
			}
		}

		if ( ! empty( $filters['context_types'] ) && is_array( $filters['context_types'] ) ) {
			$type = isset( $record['context_type'] ) ? (string) $record['context_type'] : '';
			if ( ! in_array( $type, $filters['context_types'], true ) ) {
				return false;
			}
		}

		if ( ! empty( $filters['importance'] ) && is_array( $filters['importance'] ) ) {
			$importance = isset( $record['importance'] ) ? (string) $record['importance'] : 'medium';
			if ( ! in_array( $importance, $filters['importance'], true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ai_model_management',
			'pattern_compatibility' => array( 'orchestrator', 'hierarchical' ),
			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Pure read of stored memory.
			'local-only',           // No external API calls.
			'idempotent',           // Same inputs produce same block.
			'cacheable',            // Results can be cached.
			'requires-capability',  // Needs user authentication.
		);
	}

	/**
	 * Record a retrieval-path telemetry tick.
	 *
	 * Maintains a 7-day rolling tally of `retrieval_path` values returned by
	 * this tool. Stored on the `wp_mcp_ai_wake_up_telemetry` option as a
	 * date-keyed array (`Y-m-d` => path => count). The orchestration dashboard
	 * reads this to show graph/transient mode mix.
	 *
	 * Older buckets (>7 days) are pruned each call so the option never grows
	 * unbounded.
	 *
	 * @since 1.1.0
	 *
	 * @param string $path Retrieval path: `graph` or `transient`.
	 * @return void
	 */
	public static function record_retrieval_telemetry( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path ) {
			return;
		}
		// Whitelist to keep the option schema tight.
		$allowed = array( 'graph', 'transient' );
		if ( ! in_array( $path, $allowed, true ) ) {
			return;
		}

		$option_key = 'wp_mcp_ai_wake_up_telemetry';
		$telemetry  = get_option( $option_key, array() );
		if ( ! is_array( $telemetry ) ) {
			$telemetry = array();
		}

		$today  = gmdate( 'Y-m-d' );
		$cutoff = gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) );

		// Increment today's bucket.
		if ( ! isset( $telemetry[ $today ] ) || ! is_array( $telemetry[ $today ] ) ) {
			$telemetry[ $today ] = array();
		}
		$current                      = isset( $telemetry[ $today ][ $path ] ) ? (int) $telemetry[ $today ][ $path ] : 0;
		$telemetry[ $today ][ $path ] = $current + 1;

		// Prune buckets older than the cutoff.
		foreach ( array_keys( $telemetry ) as $bucket_date ) {
			if ( ! is_string( $bucket_date ) || $bucket_date < $cutoff ) {
				unset( $telemetry[ $bucket_date ] );
			}
		}

		// `autoload=no` so this small stat never bloats every page load.
		update_option( $option_key, $telemetry, false );
	}
}
