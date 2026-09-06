<?php
/**
 * Wake-Up Context Loader tool (D8 Cluster 2c-5 port of the base
 * plugin's WP_MCP_AI_Tool_Wake_Up_Context — byte-identical slug,
 * schema, error codes, envelope, filters, and token-budget shaping;
 * per-mode retrieve-tool seam).
 *
 * The base tool resolves retrieve_agent_memory from the base tool
 * registry. Standalone, the port checks the nvoos-core registry for an
 * ecosystem port of that slug and otherwise degrades to direct reads of
 * the base-identical transients ('mcp_ai_ctx_index_' . md5(agent),
 * 'mcp_ai_ctx_' . md5(agent_context)) with the same record shape,
 * filters, and expiry semantics, so the wake-up block stays available
 * without the base plugin.
 *
 * Inspired by the MemPalace project (https://github.com/MemPalace/mempalace),
 * which the WordPress plugin's hierarchical memory model is loosely modelled on.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Build a wake-up memory block for an assistant.
 *
 * Reuses the retrieve_agent_memory tool so the existing wing/room
 * filters and the hybrid retrieval scoring boosters all apply
 * identically. The output is intentionally compact and TPM-aware: a
 * configurable token budget is enforced and any truncation is reported
 * in the response.
 */
class WakeUpContextTool extends AbstractAiTool {

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

	public function getSlug(): string {
		return 'wake_up_context';
	}

	public function getName(): string {
		return __( 'Wake-Up Context Loader', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Retrieves the top-N most-relevant memories for an agent and returns them as a compact, labeled text block ready to prepend to the system prompt at session boot. Optionally scoped to a wing/room. Honours a token budget so it never blows past TPM limits.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier.', 'nvoos-content-graph-ai' ),
				),
				'wing'            => array(
					'type'        => 'string',
					'description' => __( 'Optional wing (project/person scope) to restrict the wake-up to.', 'nvoos-content-graph-ai' ),
				),
				'room'            => array(
					'type'        => 'string',
					'description' => __( 'Optional room (topic cluster) to restrict the wake-up to.', 'nvoos-content-graph-ai' ),
				),
				'query'           => array(
					'type'        => 'string',
					'description' => __( 'Optional natural-language query that biases ranking toward a current task. When omitted, the most-important and most-recent memories surface.', 'nvoos-content-graph-ai' ),
				),
				'top_n'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of memories to consider before token-budget pruning.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => self::DEFAULT_TOP_N,
				),
				'token_budget'    => array(
					'type'        => 'integer',
					'description' => __( 'Approximate maximum tokens for the rendered block (~4 chars per token). Records that would exceed the budget are dropped (lowest-priority first).', 'nvoos-content-graph-ai' ),
					'minimum'     => 50,
					'maximum'     => 8000,
					'default'     => self::DEFAULT_TOKEN_BUDGET,
				),
				'context_types'   => array(
					'type'        => 'array',
					'description' => __( 'Restrict wake-up to specific context types.', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'min_importance'  => array(
					'type'        => 'string',
					'description' => __( 'Minimum importance level for memories included in the wake-up block.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, include the full content of each memory in the rendered block. When false, only the title and metadata are rendered (smallest possible block).', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'mode'            => array(
					'type'        => 'string',
					'description' => __( 'Retrieval strategy. "auto" (default) uses graph traversal when the Graphify addon is active, and falls back to the transient + cosine path otherwise. "graph" forces graph traversal (errors if Graphify is unavailable). "transient" forces the legacy path even when Graphify is present.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'auto', 'graph', 'transient' ),
					'default'     => 'auto',
				),
			),
			'required'             => array( 'agent_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( empty( $arguments['agent_id'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Agent ID is required.', 'nvoos-content-graph-ai' ) );
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

		// Build retrieval call (per-mode seam).
		$retrieve = $this->get_retrieve_tool();
		if ( ! $retrieve && defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith without the base tool: base-identical error.
			return new \WP_Error( 'wp_mcp_ai_error', __( 'retrieve_agent_memory tool is not available.', 'nvoos-content-graph-ai' ) );
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
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Graph mode requested but the Graphify addon is not active.', 'nvoos-content-graph-ai' ) );
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
				$ranked = \NV_oOS_Graphify_Memory_Bridge::retrieve_graph(
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
					$single = $this->retrieve_memories(
						$retrieve,
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
			$result = $this->retrieve_memories( $retrieve, $retrieve_args, $context );
		}
		// WP_Error is a valid execute() return per the canonical envelope.
		if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['contexts'] ) ) {
			self::record_retrieval_telemetry( $retrieval_path );
			return array(
				'success'        => true,
				'message'        => __( 'No memories found for wake-up.', 'nvoos-content-graph-ai' ),
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
				'message'        => __( 'Token budget too small to render any memory entries.', 'nvoos-content-graph-ai' ),
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

	public function getCapabilityFlags(): array {
		return array(
			'read-only',            // Pure read of stored memory.
			'local-only',           // No external API calls.
			'idempotent',           // Same inputs produce same block.
			'cacheable',            // Results can be cached.
			'requires-capability',  // Needs user authentication.
		);
	}

	/**
	 * Resolve the retrieve_agent_memory tool (per-mode seam).
	 *
	 * Monolith: the base tool registry. Standalone: the nvoos-core
	 * registry when an ecosystem port of the slug is registered, else
	 * null so execute() degrades to the direct-transient path.
	 *
	 * @return object|null Tool instance or null when unavailable.
	 */
	private function get_retrieve_tool() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( 'retrieve_agent_memory' );
			if ( $tool ) {
				return $tool;
			}

			return null;
		}

		if ( class_exists( '\NvoosContentGraphAi\CoreBridge' ) ) {
			$bridge = CoreBridge::instance();
			if ( $bridge->tools->has( 'retrieve_agent_memory' ) ) {
				return $bridge->tools->get( 'retrieve_agent_memory' );
			}
		}

		return null;
	}

	/**
	 * Run a retrieve_agent_memory call (per-mode seam).
	 *
	 * Delegates to the resolved tool when available; standalone installs
	 * without the tool degrade to the direct-transient retrieval below.
	 *
	 * @param object|null $retrieve Resolved retrieve tool (may be null).
	 * @param array       $args     Retrieve arguments.
	 * @param array       $context  Execution context.
	 * @return array|\WP_Error Retrieve envelope.
	 */
	private function retrieve_memories( $retrieve, array $args, array $context ) {
		if ( $retrieve ) {
			return $retrieve->execute( $args, $context );
		}

		return $this->retrieve_standalone( $args );
	}

	/**
	 * Direct-transient retrieval fallback (standalone degraded seam).
	 *
	 * Replicates the base retrieve_agent_memory contract against the
	 * base-identical keys: single-context fetch by id, and the
	 * index-walk search with the same filters, expiry semantics,
	 * flattening, and importance/recency ranking.
	 *
	 * @param array $args Retrieve arguments (agent_id, limit, filters,
	 *                    query, context_id).
	 * @return array|\WP_Error Retrieve envelope.
	 */
	private function retrieve_standalone( array $args ) {
		$agent_id = isset( $args['agent_id'] ) ? $args['agent_id'] : '';
		$limit    = isset( $args['limit'] ) ? max( 1, min( 50, absint( $args['limit'] ) ) ) : 10;
		$query    = isset( $args['query'] ) ? (string) $args['query'] : '';
		$filters  = isset( $args['filters'] ) && is_array( $args['filters'] ) ? $args['filters'] : array();

		// Single-id path (mirrors retrieve_specific_context).
		if ( isset( $args['context_id'] ) && '' !== (string) $args['context_id'] ) {
			$context_id = sanitize_text_field( (string) $args['context_id'] );
			$record     = $this->retrieve_context( $agent_id, $context_id, false );

			if ( ! is_array( $record ) ) {
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Context not found or has expired.', 'nvoos-content-graph-ai' ) );
			}

			return array(
				'success'  => true,
				'message'  => __( 'Context retrieved successfully.', 'nvoos-content-graph-ai' ),
				'contexts' => array( $this->flatten_context( $record ) ),
				'count'    => 1,
			);
		}

		// Search path (mirrors the base search_contexts + rank pipeline).
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );

		if ( ! is_array( $context_index ) || empty( $context_index ) ) {
			return array(
				'success'  => true,
				'message'  => __( 'No contexts found for this agent.', 'nvoos-content-graph-ai' ),
				'contexts' => array(),
				'count'    => 0,
			);
		}

		$ranked = array();
		foreach ( $context_index as $ctx_id => $index_entry ) {
			// Check expiration via the index entry before bothering to load
			// the heavier per-context transient.
			if ( isset( $index_entry['expires_at'] ) ) {
				$expires_timestamp = strtotime( $index_entry['expires_at'] );
				if ( $expires_timestamp && time() > $expires_timestamp ) {
					continue;
				}
			}

			$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $ctx_id );
			$context_record = get_transient( $transient_key );
			if ( ! is_array( $context_record ) ) {
				continue;
			}

			$flat = $this->flatten_context( $context_record );
			if ( ! $this->matches_wake_filters( $flat, $filters ) ) {
				continue;
			}

			$ranked[] = array(
				'flat'   => $flat,
				'score'  => $this->importance_score( $flat ),
				'stored' => isset( $context_record['stored_at'] ) ? strtotime( (string) $context_record['stored_at'] ) : 0,
			);
		}

		// Importance first, then recency — mirrors the base sort_results.
		usort(
			$ranked,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] - $a['score'];
				}

				return $b['stored'] - $a['stored'];
			}
		);

		$ranked   = array_slice( $ranked, 0, $limit );
		$contexts = array();
		foreach ( $ranked as $entry ) {
			$contexts[] = $entry['flat'];
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				/* translators: %d: number of contexts found */
				_n( 'Found %d context.', 'Found %d contexts.', count( $contexts ), 'nvoos-content-graph-ai' ),
				count( $contexts )
			),
			'contexts' => $contexts,
			'count'    => count( $contexts ),
			'query'    => $query,
		);
	}

	/**
	 * Flatten a transient context record to the base retrieve tool's
	 * format_context_result shape.
	 *
	 * @param array $record Transient context record.
	 * @return array Formatted result.
	 */
	private function flatten_context( array $record ) {
		$result = array(
			'context_id'   => isset( $record['context_id'] ) ? $record['context_id'] : '',
			'context_type' => isset( $record['context_type'] ) ? $record['context_type'] : '',
			'title'        => isset( $record['data']['title'] ) ? $record['data']['title'] : '',
			'content'      => isset( $record['data']['content'] ) ? $record['data']['content'] : '',
			'metadata'     => isset( $record['data']['metadata'] ) ? $record['data']['metadata'] : array(),
			'tags'         => isset( $record['data']['tags'] ) ? $record['data']['tags'] : array(),
			'importance'   => isset( $record['data']['importance'] ) ? $record['data']['importance'] : 'medium',
			'wing'         => isset( $record['wing'] ) ? $record['wing'] : '',
			'room'         => isset( $record['room'] ) ? $record['room'] : '',
			'verbatim'     => ! empty( $record['verbatim'] ),
			'stored_at'    => isset( $record['stored_at'] ) ? $record['stored_at'] : '',
			'expires_at'   => isset( $record['expires_at'] ) ? $record['expires_at'] : '',
		);

		// Add source task if present.
		if ( isset( $record['data']['source_task'] ) ) {
			$result['source_task'] = $record['data']['source_task'];
		}

		return $result;
	}

	/**
	 * Map an importance label to the base sort_results score.
	 *
	 * @param array $record Flattened memory record.
	 * @return int Score (1-4, default 2 for medium).
	 */
	private function importance_score( array $record ) {
		$importance_order = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
		);

		$importance = isset( $record['importance'] ) ? (string) $record['importance'] : 'medium';

		return isset( $importance_order[ $importance ] ) ? $importance_order[ $importance ] : 2;
	}

	/**
	 * Retrieve a context record (per-mode seam).
	 *
	 * Monolith: the base Agent_Context_Manager. Standalone: the
	 * base-identical transient with the same expiry semantics.
	 *
	 * @param int|string $agent_id        Agent ID.
	 * @param string     $context_id      Context ID.
	 * @param bool       $include_expired Whether to include expired contexts.
	 * @return array|null Context record or null if not found/expired.
	 */
	private function retrieve_context( $agent_id, $context_id, $include_expired = false ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$manager = \WP_MCP_AI_Agent_Context_Manager::get_instance();
			return $manager->retrieve_context( $agent_id, $context_id, $include_expired );
		}

		$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );

		if ( ! $context_record ) {
			return null;
		}

		if ( ! $include_expired && isset( $context_record['expires_at'] ) ) {
			$expires_timestamp = strtotime( $context_record['expires_at'] );
			if ( $expires_timestamp && time() > $expires_timestamp ) {
				return null;
			}
		}

		return $context_record;
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
