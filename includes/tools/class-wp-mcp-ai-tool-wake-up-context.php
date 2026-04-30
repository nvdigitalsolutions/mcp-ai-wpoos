<?php
/**
 * Tool that produces a "wake-up" context block for assistant boot.
 *
 * MemPalace-inspired Phase 2 enhancement. Retrieves the top-N most-relevant
 * memories for a given agent (optionally scoped to a wing/room) and returns
 * them formatted as a labeled block ready to be prepended to the system
 * prompt.
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
			),
			'required'             => array( 'agent_id' ),
			'additionalProperties' => false,
		);
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
			return array(
				'success' => false,
				'message' => __( 'Agent ID is required.', 'mcp-ai-wpoos' ),
			);
		}

		$agent_id        = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$wing            = isset( $arguments['wing'] ) ? sanitize_text_field( $arguments['wing'] ) : '';
		$room            = isset( $arguments['room'] ) ? sanitize_text_field( $arguments['room'] ) : '';
		$query           = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$top_n           = isset( $arguments['top_n'] ) ? max( 1, min( 50, absint( $arguments['top_n'] ) ) ) : self::DEFAULT_TOP_N;
		$token_budget    = isset( $arguments['token_budget'] ) ? max( 50, min( 8000, absint( $arguments['token_budget'] ) ) ) : self::DEFAULT_TOKEN_BUDGET;
		$include_content = isset( $arguments['include_content'] ) ? (bool) $arguments['include_content'] : true;

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
			return array(
				'success' => false,
				'message' => __( 'retrieve_agent_memory tool is not available.', 'mcp-ai-wpoos' ),
			);
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

		$result = $retrieve->execute( $retrieve_args, $context );
		if ( empty( $result['success'] ) || empty( $result['contexts'] ) ) {
			return array(
				'success'         => true,
				'message'         => __( 'No memories found for wake-up.', 'mcp-ai-wpoos' ),
				'system_block'    => '',
				'count'           => 0,
				'truncated'       => 0,
				'tokens_used'     => 0,
				'token_budget'    => $token_budget,
				'wing'            => $wing,
				'room'            => $room,
				'agent_id'        => $agent_id,
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
			return array(
				'success'      => true,
				'message'      => __( 'Token budget too small to render any memory entries.', 'mcp-ai-wpoos' ),
				'system_block' => '',
				'count'        => 0,
				'truncated'    => $truncated,
				'tokens_used'  => 0,
				'token_budget' => $token_budget,
				'wing'         => $wing,
				'room'         => $room,
				'agent_id'     => $agent_id,
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
			'memories_loaded' => array_map(
				static function ( $memory ) {
					return array(
						'context_id' => isset( $memory['context_id'] ) ? $memory['context_id'] : '',
						'title'      => isset( $memory['title'] ) ? $memory['title'] : '',
						'importance' => isset( $memory['importance'] ) ? $memory['importance'] : 'medium',
						'wing'       => isset( $memory['wing'] ) ? $memory['wing'] : '',
						'room'       => isset( $memory['room'] ) ? $memory['room'] : '',
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
			'safe'              => true,
			'external-api'      => false,
			'read-only'         => true,  // Pure read of stored memory.
			'idempotent'        => true,  // Same inputs produce same block.
			'cacheable'         => true,
			'requires-auth'     => true,
			'blocking'          => false,
			'uses-network'      => false,
			'modifies-wp'       => false,
			'expensive'         => false,
			'requires-approval' => false,
		);
	}
}
