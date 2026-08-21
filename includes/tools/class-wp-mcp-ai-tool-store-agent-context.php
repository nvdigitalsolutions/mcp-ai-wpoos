<?php
/**
 * Tool for storing agent context/memory.
 *
 * Allows AI assistants to store important context for future retrieval.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 4/5).
 * The hierarchical `wing` / `room` scoping fields are inspired by the
 * MemPalace project (https://github.com/MemPalace/mempalace).
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
 * Stores important context for an agent to remember.
 *
 * This tool enables AI models to persist context, learnings, and important
 * information that should be remembered across sessions. Stored context can
 * be retrieved later using retrieve_agent_memory tool.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Store_Agent_Context implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum characters of ingested content to store in a context record.
	 *
	 * Keeping this limit prevents context records from bloating WordPress transients
	 * while still capturing the most meaningful portion of the source content.
	 *
	 * @var int
	 */
	const MAX_INGESTED_CONTENT_LENGTH = 8000;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'store_agent_context';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Store Agent Context', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Stores important context, learnings, or information for an agent to remember. Supports automatic content ingestion from Vector Stores, WordPress posts/pages, and URLs. Use this to persist knowledge across sessions, track important facts, or maintain agent memory. Context can be retrieved later using retrieve_agent_memory.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'       => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier', 'mcp-ai-wpoos' ),
				),
				'context_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of context being stored', 'mcp-ai-wpoos' ),
					'enum'        => array(
						'learning',
						'fact',
						'preference',
						'pattern',
						'workflow',
						'decision',
						'result',
						'insight',
						'note',
						'generic',
					),
				),
				'context_data'   => array(
					'type'        => 'object',
					'description' => __( 'The context data to store', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Short title or summary of the context', 'mcp-ai-wpoos' ),
						),
						'content'     => array(
							'type'        => 'string',
							'description' => __( 'The main content or information to store', 'mcp-ai-wpoos' ),
						),
						'metadata'    => array(
							'type'        => 'object',
							'description' => __( 'Additional metadata about the context', 'mcp-ai-wpoos' ),
						),
						'tags'        => array(
							'type'        => 'array',
							'description' => __( 'Tags for categorization and retrieval', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'importance'  => array(
							'type'        => 'string',
							'description' => __( 'Importance level: low, medium, high, critical', 'mcp-ai-wpoos' ),
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
							'default'     => 'medium',
						),
						'source_task' => array(
							'type'        => 'string',
							'description' => __( 'ID or reference to the task that generated this context', 'mcp-ai-wpoos' ),
						),
					),
					'required'    => array( 'title', 'content' ),
				),
				'wing'           => array(
					'type'        => 'string',
					'description' => __( 'Optional hierarchical scope for the project, person, or domain this memory belongs to (e.g. "client-acme", "personal", "team-alpha"). Inspired by MemPalace "wings". Used to scope retrieval before semantic ranking. When omitted, memory is unscoped and visible to all retrievals for the agent.', 'mcp-ai-wpoos' ),
				),
				'room'           => array(
					'type'        => 'string',
					'description' => __( 'Optional sub-scope within a wing for a topic cluster (e.g. "auth-flows", "billing", "onboarding"). Inspired by MemPalace "rooms". Used together with wing to narrow retrieval before semantic ranking.', 'mcp-ai-wpoos' ),
				),
				'verbatim'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true, content is stored exactly as provided and downstream pre-store transforms (e.g. summarization filters) are skipped. Use for raw quotes, decisions, transcripts, or any text that must not be paraphrased. Defaults to false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'ttl'            => array(
					'type'        => 'integer',
					'description' => __( 'Time to live in seconds (default: 30 days)', 'mcp-ai-wpoos' ),
					'default'     => 2592000, // 30 days.
					'minimum'     => 3600,    // 1 hour minimum.
					'maximum'     => 31536000, // 1 year maximum.
				),
				'content_source' => array(
					'type'        => 'object',
					'description' => __( 'Optional content source to automatically ingest and analyse before storing. Supports Vector Store IDs, WordPress post IDs, and URLs.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'type'            => array(
							'type'        => 'string',
							'description' => __( 'Source type: vector_store, post, or url', 'mcp-ai-wpoos' ),
							'enum'        => array( 'vector_store', 'post', 'url' ),
						),
						'vector_store_id' => array(
							'type'        => 'string',
							'description' => __( 'OpenAI Vector Store ID to ingest metadata and file information from', 'mcp-ai-wpoos' ),
						),
						'post_id'         => array(
							'type'        => 'integer',
							'description' => __( 'WordPress post or page ID to extract content from', 'mcp-ai-wpoos' ),
						),
						'url'             => array(
							'type'        => 'string',
							'description' => __( 'URL to fetch and analyse as context content', 'mcp-ai-wpoos' ),
						),
					),
					'required'    => array( 'type' ),
				),
			),
			'required'             => array( 'agent_id', 'context_type', 'context_data' ),
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
		// Check user permissions before storing context data.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to store agent context.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate required parameters.
		if ( empty( $arguments['agent_id'] ) ) {
			return new WP_Error(
				'store_agent_context_missing_agent_id',
				__( 'Agent ID is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['context_type'] ) ) {
			return new WP_Error(
				'store_agent_context_missing_context_type',
				__( 'Context type is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['context_data'] ) || ! is_array( $arguments['context_data'] ) ) {
			return new WP_Error(
				'store_agent_context_invalid_context_data',
				__( 'Context data is required and must be an object.', 'mcp-ai-wpoos' )
			);
		}

		// Sanitize inputs.
		$agent_id     = is_numeric( $arguments['agent_id'] ) ? absint( $arguments['agent_id'] ) : sanitize_text_field( $arguments['agent_id'] );
		$context_type = sanitize_key( $arguments['context_type'] );
		$context_data = $this->sanitize_context_data( $arguments['context_data'] );
		$ttl          = isset( $arguments['ttl'] ) ? absint( $arguments['ttl'] ) : 2592000; // 30 days default.

		// Agent identity resolution (memory-layer fix #1): when the caller
		// passes a virtual / non-numeric agent key, resolve it to the
		// canonical assistant post ID carried in the execution context so the
		// record lands in the same bucket the chat-memory drawer recalls
		// from. The alias is persisted so future stores resolve identically
		// even without context.
		$original_agent_id = $agent_id;
		$agent_resolution  = array(
			'agent_id'  => $agent_id,
			'original'  => (string) $agent_id,
			'resolved'  => false,
			'canonical' => is_numeric( $agent_id ),
		);
		if ( class_exists( 'WP_MCP_AI_Agent_Identity_Resolver' ) ) {
			$agent_resolution = WP_MCP_AI_Agent_Identity_Resolver::resolve( $agent_id, $context );
			$agent_id         = $agent_resolution['agent_id'];
		}

		// Sanitize MemPalace-inspired hierarchical scope and verbatim discipline fields.
		$wing     = isset( $arguments['wing'] ) ? sanitize_text_field( $arguments['wing'] ) : '';
		$room     = isset( $arguments['room'] ) ? sanitize_text_field( $arguments['room'] ) : '';
		$verbatim = ! empty( $arguments['verbatim'] );

		// Validate TTL bounds.
		$ttl = max( 3600, min( 31536000, $ttl ) ); // Between 1 hour and 1 year.

		// Handle optional content source ingestion.
		$ingested_source = null;
		if ( ! empty( $arguments['content_source'] ) && is_array( $arguments['content_source'] ) ) {
			$ingestion_result = $this->ingest_content_source( $arguments['content_source'], $context );
			if ( is_wp_error( $ingestion_result ) ) {
					return $ingestion_result;
			}
			if ( is_array( $ingestion_result ) ) {
				$ingested_source = $ingestion_result;
				// Merge ingested content into context_data when fields are not already set.
				if ( ! empty( $ingested_source['content'] ) && empty( $context_data['content'] ) ) {
					$context_data['content'] = wp_kses_post( $ingested_source['content'] );
				} elseif ( ! empty( $ingested_source['content'] ) ) {
					// Append ingested content as additional context.
					$context_data['content'] .= "\n\n--- Ingested Source ---\n" . wp_kses_post( $ingested_source['content'] );
				}
				if ( ! empty( $ingested_source['title'] ) && empty( $context_data['title'] ) ) {
					$context_data['title'] = sanitize_text_field( $ingested_source['title'] );
				}
				// Merge source metadata into the context metadata.
				if ( ! empty( $ingested_source['metadata'] ) && is_array( $ingested_source['metadata'] ) ) {
					$existing_meta            = isset( $context_data['metadata'] ) && is_array( $context_data['metadata'] ) ? $context_data['metadata'] : array();
					$context_data['metadata'] = array_merge( $ingested_source['metadata'], $existing_meta );
				}
				// Add source-derived tags.
				if ( ! empty( $ingested_source['tags'] ) && is_array( $ingested_source['tags'] ) ) {
					$existing_tags        = isset( $context_data['tags'] ) && is_array( $context_data['tags'] ) ? $context_data['tags'] : array();
					$context_data['tags'] = array_values( array_unique( array_merge( $existing_tags, $ingested_source['tags'] ) ) );
				}
			}
		}

		// Validate context_data has required fields.
		if ( empty( $context_data['title'] ) || empty( $context_data['content'] ) ) {
			return new WP_Error(
				'store_agent_context_missing_title_content',
				__( 'Context data must include title and content fields.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Filter the context data before it is persisted.
		 *
		 * This is the canonical hook for pre-store transforms (e.g. summarization,
		 * redaction, PII scrubbing). Listeners MUST respect the verbatim discipline:
		 * when $verbatim is true, the data must be returned unchanged.
		 *
		 * @since 1.1.0
		 *
		 * @param array      $context_data Sanitized context data (title, content, metadata, tags, importance, ...).
		 * @param bool       $verbatim     When true, transforms must be a no-op.
		 * @param string     $context_type The context type (learning, fact, ...).
		 * @param int|string $agent_id     The agent identifier.
		 * @param array      $arguments    The full original arguments passed to the tool.
		 * @param array      $context      The execution context provided by the registry.
		 */
		$transformed_context_data = apply_filters(
			'wp_mcp_ai_memory_pre_store_transform',
			$context_data,
			$verbatim,
			$context_type,
			$agent_id,
			$arguments,
			$context
		);

		// Verbatim contract: ignore any transformation when verbatim is requested.
		if ( $verbatim ) {
			$transformed_context_data = $context_data;
		}

		if ( is_array( $transformed_context_data ) && ! empty( $transformed_context_data['title'] ) && ! empty( $transformed_context_data['content'] ) ) {
			$context_data = $transformed_context_data;
		} elseif ( ! $verbatim ) {
			// A listener returned invalid data and we are not in verbatim mode.
			// Help plugin developers diagnose their filter implementation.
			_doing_it_wrong(
				'wp_mcp_ai_memory_pre_store_transform',
				esc_html__( 'Filter listeners must return an array with non-empty "title" and "content" keys. Returning unmodified context data instead.', 'mcp-ai-wpoos' ),
				'1.1.0'
			);
		}

		// Generate unique context ID.
		$context_id = 'ctx_' . wp_generate_password( 12, false );

		// Memory Layer 2026 Phase 5 — contradiction detection (additive, gated).
		//
		// Runs AFTER the privacy filter + pre-store transform but BEFORE persistence,
		// so the detector sees the same content that will be written. Wrapped in a
		// guarded block so a misbehaving detector NEVER blocks the store: every
		// failure is logged and the write proceeds unchanged.
		if ( (bool) apply_filters( 'wp_mcp_ai_memory_contradiction_detection_on_store', true )
			&& class_exists( 'WP_MCP_AI_Memory_Contradiction_Detector' )
		) {
			try {
				WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
					array_merge(
						$context_data,
						array(
							'context_id' => $context_id,
							'agent_id'   => $agent_id,
							'wing'       => $wing,
							'room'       => $room,
						)
					)
				);
			} catch ( Throwable $contradiction_exception ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'store_agent_context: contradiction detector threw; store proceeds unaffected.',
						array(
							'context_id' => $context_id,
							'agent_id'   => $agent_id,
							'message'    => $contradiction_exception->getMessage(),
						)
					);
				}
			}
		}

		// Prepare context record.
		$context_record = array(
			'context_id'   => $context_id,
			'agent_id'     => $agent_id,
			'context_type' => $context_type,
			'data'         => $context_data,
			'stored_at'    => current_time( 'mysql' ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'ttl'          => $ttl,
			'wing'         => $wing,
			'room'         => $room,
			'verbatim'     => $verbatim,
		);

		// Store context using transient (WordPress built-in caching).
		// Use a compound key to allow retrieval by agent_id.
		$transient_key = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		set_transient( $transient_key, $context_record, $ttl );

		// Also maintain an index of context IDs for this agent.
		$index_key     = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		$context_index = get_transient( $index_key );
		if ( ! is_array( $context_index ) ) {
			$context_index = array();
		}

		// Add to index with expiry time.
		$context_index[ $context_id ] = array(
			'type'       => $context_type,
			'title'      => $context_data['title'],
			'stored_at'  => current_time( 'mysql' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'importance' => isset( $context_data['importance'] ) ? $context_data['importance'] : 'medium',
			'tags'       => isset( $context_data['tags'] ) ? $context_data['tags'] : array(),
			'wing'       => $wing,
			'room'       => $room,
			'verbatim'   => $verbatim,
		);

		// Store index with same TTL.
		set_transient( $index_key, $context_index, $ttl );

		// Invalidate dashboard memory stats cache to show updated data immediately.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );

		// Derive source pointers from the caller-supplied content_source for the event payload.
		$src_post_id = 0;
		$src_url     = '';
		$src_type    = '';
		if ( ! empty( $arguments['content_source'] ) && is_array( $arguments['content_source'] ) ) {
			$src_type = isset( $arguments['content_source']['type'] ) ? sanitize_key( $arguments['content_source']['type'] ) : '';
			if ( 'post' === $src_type && ! empty( $arguments['content_source']['post_id'] ) ) {
				$src_post_id = absint( $arguments['content_source']['post_id'] );
			}
			if ( 'url' === $src_type && ! empty( $arguments['content_source']['url'] ) ) {
				$src_url = esc_url_raw( $arguments['content_source']['url'] );
			}
		}

		/**
		 * Fires after a memory has been persisted to the transient store.
		 *
		 * Subscribers can use this to mirror the memory into a secondary store
		 * (e.g. the Graphify knowledge-graph addon, an external vector DB, or
		 * an analytics pipeline). The transient remains the source of truth in
		 * Phase 4a — listeners are advisory and must be tolerant of failure.
		 *
		 * Payload keys:
		 *   - context_id      string   Stable identifier (`ctx_*`).
		 *   - agent_id        int|str  Resolved agent id (canonical post ID when the caller passed a virtual key).
		 *   - original_agent_id int|str Caller-supplied agent id before resolution (same as agent_id when unresolved).
		 *   - agent_id_resolved bool   Whether the virtual key was remapped to a canonical ID.
		 *   - context_type    string   Sanitized type slug (e.g. `learning`, `fact`).
		 *   - content         string   Final stored content (post-transform unless verbatim).
		 *   - title           string   Final stored title.
		 *   - importance      string   `low|medium|high|critical` (defaults to `medium`).
		 *   - tags            array    Normalised tag list.
		 *   - wing            string   MemPalace wing scope (may be empty).
		 *   - room            string   MemPalace room scope (may be empty).
		 *   - verbatim        bool     Verbatim discipline flag.
		 *   - source_post_id  int      WordPress post ID (0 when not derived from a post).
		 *   - source_url      string   Source URL (empty when not URL-derived).
		 *   - source_type     string   `vector_store|post|url|''` from content_source.
		 *   - stored_at       string   MySQL timestamp.
		 *   - expires_at      string   MySQL timestamp.
		 *   - ttl             int      Time-to-live in seconds.
		 *
		 * @since 1.1.0
		 *
		 * @param array $payload Normalised event payload (see above).
		 */
		do_action(
			'wp_mcp_ai_memory_stored',
			array(
				'context_id'        => $context_id,
				'agent_id'          => $agent_id,
				'original_agent_id' => $original_agent_id,
				'agent_id_resolved' => (bool) $agent_resolution['resolved'],
				'context_type'      => $context_type,
				'content'           => isset( $context_data['content'] ) ? (string) $context_data['content'] : '',
				'title'             => isset( $context_data['title'] ) ? (string) $context_data['title'] : '',
				'importance'        => isset( $context_data['importance'] ) ? (string) $context_data['importance'] : 'medium',
				'tags'              => isset( $context_data['tags'] ) && is_array( $context_data['tags'] ) ? array_values( $context_data['tags'] ) : array(),
				'wing'              => $wing,
				'room'              => $room,
				'verbatim'          => $verbatim,
				'source_post_id'    => $src_post_id,
				'source_url'        => $src_url,
				'source_type'       => $src_type,
				'stored_at'         => $context_record['stored_at'],
				'expires_at'        => $context_record['expires_at'],
				'ttl'               => $ttl,
			)
		);

		// Fix #2 — echo the resolved identity back so the assistant can
		// detect "I saved under X, the drawer watches Y" immediately.
		return array(
			'success'           => true,
			'message'           => __( 'Context stored successfully.', 'mcp-ai-wpoos' ),
			'context_id'        => $context_id,
			'agent_id'          => $agent_id,
			'original_agent_id' => $original_agent_id,
			'agent_id_resolved' => (bool) $agent_resolution['resolved'],
			'wing'              => $wing,
			'room'              => $room,
			'verbatim'          => $verbatim,
			'stored_at'         => $context_record['stored_at'],
			'expires_at'        => $context_record['expires_at'],
			'ttl_seconds'       => $ttl,
			'ttl_human'         => $this->format_ttl( $ttl ),
			'storage'         => array(
				'method' => 'WordPress Transient',
				'key'    => $transient_key,
			),
			'ingested_source' => $ingested_source ? array(
				'type'    => isset( $ingested_source['source_type'] ) ? $ingested_source['source_type'] : 'unknown',
				'summary' => isset( $ingested_source['summary'] ) ? $ingested_source['summary'] : '',
			) : null,
			'next_steps'      => array(
				/* translators: %s: context_id value */
				sprintf( __( 'Retrieve this context later using retrieve_agent_memory with context_id: "%s"', 'mcp-ai-wpoos' ), $context_id ),
				/* translators: %s: agent_id value */
				sprintf( __( 'Or search all contexts for agent_id: "%s" using retrieve_agent_memory', 'mcp-ai-wpoos' ), $agent_id ),
			),
		);
	}

	/**
	 * Ingest content from a specified source and return normalised content data.
	 *
	 * Supports three source types:
	 *  - vector_store: fetches metadata and file list from an OpenAI Vector Store.
	 *  - post: extracts title and content from a WordPress post or page.
	 *  - url: fetches and strips HTML from a remote URL.
	 *
	 * @param array $source  Content source definition with 'type' key.
	 * @param array $context Execution context (may carry assistant_config).
	 * @return array|WP_Error Normalised content array or WP_Error on failure.
	 */
	private function ingest_content_source( array $source, array $context = array() ) {
		$source_type = isset( $source['type'] ) ? sanitize_key( $source['type'] ) : '';

		switch ( $source_type ) {
			case 'vector_store':
				return $this->ingest_vector_store( $source, $context );

			case 'post':
				return $this->ingest_wp_post( $source );

			case 'url':
				return $this->ingest_url( $source );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_source_type',
					/* translators: %s: invalid source type value */
					sprintf( __( 'Invalid content source type: %s. Supported types: vector_store, post, url.', 'mcp-ai-wpoos' ), esc_html( $source_type ) )
				);
		}
	}

	/**
	 * Ingest metadata from an OpenAI Vector Store.
	 *
	 * Fetches store metadata (name, status, file counts) and the list of
	 * associated file IDs, producing a human-readable summary that can be
	 * stored as agent context.
	 *
	 * @param array $source  Source config with optional 'vector_store_id' key.
	 * @param array $context Execution context.
	 * @return array|WP_Error Normalised content array or WP_Error.
	 */
	private function ingest_vector_store( array $source, array $context ) {
		// Resolve vector store ID from source or assistant context.
		$vector_store_id = '';
		if ( ! empty( $source['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $source['vector_store_id'] );
		} elseif ( ! empty( $context['assistant_config']['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $context['assistant_config']['vector_store_id'] );
		}

		if ( empty( $vector_store_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_vector_store_id',
				__( 'A vector_store_id is required when using the vector_store content source type.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_client',
				__( 'OpenAI client is unavailable.', 'mcp-ai-wpoos' )
			);
		}

		$client = new WP_MCP_AI_OpenAI_Client();

		// Fetch vector store metadata.
		$store_data = $client->retrieve_vector_store( $vector_store_id );
		if ( is_wp_error( $store_data ) ) {
			return $store_data;
		}

		$store_name  = isset( $store_data['name'] ) ? sanitize_text_field( $store_data['name'] ) : $vector_store_id;
		$status      = isset( $store_data['status'] ) ? sanitize_text_field( $store_data['status'] ) : 'unknown';
		$file_counts = isset( $store_data['file_counts'] ) && is_array( $store_data['file_counts'] ) ? $store_data['file_counts'] : array();
		$total_files = isset( $file_counts['total'] ) ? absint( $file_counts['total'] ) : 0;
		$in_progress = isset( $file_counts['in_progress'] ) ? absint( $file_counts['in_progress'] ) : 0;
		$completed   = isset( $file_counts['completed'] ) ? absint( $file_counts['completed'] ) : 0;
		$failed      = isset( $file_counts['failed'] ) ? absint( $file_counts['failed'] ) : 0;
		$metadata    = isset( $store_data['metadata'] ) && is_array( $store_data['metadata'] ) ? $store_data['metadata'] : array();
		$created_at  = isset( $store_data['created_at'] ) ? absint( $store_data['created_at'] ) : 0;
		$expires_at  = isset( $store_data['expires_at'] ) ? absint( $store_data['expires_at'] ) : 0;

		// Fetch file list (up to 20 for context).
		$files_data = $client->list_vector_store_files( $vector_store_id, array( 'limit' => 20 ) );
		$file_ids   = array();
		if ( ! is_wp_error( $files_data ) && isset( $files_data['data'] ) && is_array( $files_data['data'] ) ) {
			foreach ( $files_data['data'] as $file ) {
				if ( isset( $file['id'] ) ) {
					$file_ids[] = sanitize_text_field( $file['id'] );
				}
			}
		}

		// Build human-readable content summary.
		$lines = array(
			/* translators: 1: store name, 2: store ID */
			sprintf( __( 'Vector Store: %1$s (ID: %2$s)', 'mcp-ai-wpoos' ), $store_name, $vector_store_id ),
			/* translators: %s: status */
			sprintf( __( 'Status: %s', 'mcp-ai-wpoos' ), $status ),
			/* translators: 1: total, 2: completed, 3: in-progress, 4: failed */
			sprintf( __( 'Files: %1$d total (%2$d completed, %3$d in-progress, %4$d failed)', 'mcp-ai-wpoos' ), $total_files, $completed, $in_progress, $failed ),
		);

		if ( $created_at > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: formatted date */
				__( 'Created: %s', 'mcp-ai-wpoos' ),
				gmdate( 'Y-m-d H:i:s', $created_at )
			);
		}

		if ( $expires_at > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: formatted date */
				__( 'Expires: %s', 'mcp-ai-wpoos' ),
				gmdate( 'Y-m-d H:i:s', $expires_at )
			);
		}

		if ( ! empty( $file_ids ) ) {
			$lines[] = __( 'File IDs:', 'mcp-ai-wpoos' ) . ' ' . implode( ', ', $file_ids );
		}

		if ( ! empty( $metadata ) ) {
			$lines[] = __( 'Metadata:', 'mcp-ai-wpoos' ) . ' ' . wp_json_encode( $metadata );
		}

		$content = implode( "\n", $lines );
		$summary = sprintf(
			/* translators: 1: store name, 2: total file count */
			__( 'Vector Store "%1$s" with %2$d files ingested', 'mcp-ai-wpoos' ),
			$store_name,
			$total_files
		);

		return array(
			'source_type' => 'vector_store',
			'title'       => $store_name,
			'content'     => $content,
			'summary'     => $summary,
			'metadata'    => array_merge(
				$metadata,
				array(
					'vector_store_id' => $vector_store_id,
					'status'          => $status,
					'total_files'     => $total_files,
					'file_ids'        => $file_ids,
				)
			),
			'tags'        => array( 'vector-store', 'openai', sanitize_key( $status ) ),
		);
	}

	/**
	 * Ingest content from a WordPress post or page.
	 *
	 * Extracts the post title, content, excerpt, and metadata, returning
	 * a structured array suitable for context storage.
	 *
	 * @param array $source Source config with 'post_id' key.
	 * @return array|WP_Error Normalised content array or WP_Error.
	 */
	private function ingest_wp_post( array $source ) {
		if ( empty( $source['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_post_id',
				__( 'A post_id is required when using the post content source type.', 'mcp-ai-wpoos' )
			);
		}

		$post_id = absint( $source['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post ID %d not found or is not published.', 'mcp-ai-wpoos' ), $post_id )
			);
		}

		// Strip shortcodes and HTML tags to get plain-text content.
		$raw_content   = do_shortcode( $post->post_content );
		$plain_content = wp_strip_all_tags( $raw_content );
		$plain_content = preg_replace( '/\s+/', ' ', $plain_content );
		$plain_content = trim( $plain_content );

		// Truncate to a reasonable size for context storage.
		if ( mb_strlen( $plain_content ) > self::MAX_INGESTED_CONTENT_LENGTH ) {
			$plain_content = mb_substr( $plain_content, 0, self::MAX_INGESTED_CONTENT_LENGTH ) . '…';
		}

		$title     = get_the_title( $post );
		$excerpt   = has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';
		$post_type = $post->post_type;
		$author    = get_the_author_meta( 'display_name', $post->post_author );
		$date      = get_the_date( 'Y-m-d', $post );
		$permalink = get_permalink( $post_id );
		$tags      = array();
		$wp_tags   = get_the_tags( $post_id );
		if ( is_array( $wp_tags ) ) {
			foreach ( $wp_tags as $tag ) {
				$tags[] = sanitize_key( $tag->slug );
			}
		}
		$categories = array();
		$wp_cats    = get_the_category( $post_id );
		if ( is_array( $wp_cats ) ) {
			foreach ( $wp_cats as $cat ) {
				$categories[] = sanitize_text_field( $cat->name );
			}
		}

		$lines   = array();
		$lines[] = __( 'Title:', 'mcp-ai-wpoos' ) . ' ' . $title;
		$lines[] = __( 'Type:', 'mcp-ai-wpoos' ) . ' ' . $post_type;
		$lines[] = __( 'Author:', 'mcp-ai-wpoos' ) . ' ' . $author;
		$lines[] = __( 'Date:', 'mcp-ai-wpoos' ) . ' ' . $date;
		if ( $permalink ) {
			$lines[] = __( 'URL:', 'mcp-ai-wpoos' ) . ' ' . esc_url_raw( $permalink );
		}
		if ( $excerpt ) {
			$lines[] = __( 'Excerpt:', 'mcp-ai-wpoos' ) . ' ' . $excerpt;
		}
		if ( ! empty( $categories ) ) {
			$lines[] = __( 'Categories:', 'mcp-ai-wpoos' ) . ' ' . implode( ', ', $categories );
		}
		if ( $plain_content ) {
			$lines[] = '';
			$lines[] = __( 'Content:', 'mcp-ai-wpoos' );
			$lines[] = $plain_content;
		}

		$content = implode( "\n", $lines );
		$summary = sprintf(
			/* translators: 1: post title, 2: post type */
			__( 'WordPress %2$s "%1$s" ingested', 'mcp-ai-wpoos' ),
			$title,
			$post_type
		);

		return array(
			'source_type' => 'post',
			'title'       => $title,
			'content'     => $content,
			'summary'     => $summary,
			'metadata'    => array(
				'post_id'    => $post_id,
				'post_type'  => $post_type,
				'author'     => $author,
				'date'       => $date,
				'permalink'  => $permalink ? esc_url_raw( $permalink ) : '',
				'categories' => $categories,
			),
			'tags'        => array_merge( array( $post_type, 'wordpress-content' ), $tags ),
		);
	}

	/**
	 * Ingest content from a remote URL.
	 *
	 * Fetches the URL, strips HTML to plain text, and returns a normalised
	 * content array. Limited to MAX_INGESTED_CONTENT_LENGTH characters to keep context manageable.
	 *
	 * @param array $source Source config with 'url' key.
	 * @return array|WP_Error Normalised content array or WP_Error.
	 */
	private function ingest_url( array $source ) {
		if ( empty( $source['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_url',
				__( 'A url is required when using the url content source type.', 'mcp-ai-wpoos' )
			);
		}

		$url = esc_url_raw( $source['url'] );
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'The provided URL is invalid.', 'mcp-ai-wpoos' )
			);
		}

		// Only allow http/https.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url_scheme',
				__( 'Only http and https URLs are supported.', 'mcp-ai-wpoos' )
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent' => 'WP-MCP-AI-Context-Ingester/' . ( defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0' ) . ' (+' . esc_url( home_url( '/' ) ) . ')',
					'Accept'     => 'text/html,application/xhtml+xml,*/*;q=0.8',
				),
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_url_fetch_failed',
				/* translators: %s: error message */
				sprintf( __( 'Failed to fetch URL: %s', 'mcp-ai-wpoos' ), $response->get_error_message() )
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_url_fetch_error',
				/* translators: 1: HTTP status code, 2: URL */
				sprintf( __( 'URL returned HTTP %1$d: %2$s', 'mcp-ai-wpoos' ), $http_code, $url )
			);
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error(
				'wp_mcp_ai_url_empty_body',
				__( 'URL returned an empty response body.', 'mcp-ai-wpoos' )
			);
		}

		// Extract a readable title from the HTML <title> tag.
		$page_title = '';
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $title_matches ) ) {
			$page_title = sanitize_text_field( html_entity_decode( $title_matches[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( empty( $page_title ) ) {
			$page_title = $url;
		}

		// Strip scripts and styles before converting to plain text.
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

		$plain = wp_strip_all_tags( $html );
		$plain = preg_replace( '/\s+/', ' ', $plain );
		$plain = trim( $plain );

		// Truncate to keep context manageable.
		if ( mb_strlen( $plain ) > self::MAX_INGESTED_CONTENT_LENGTH ) {
			$plain = mb_substr( $plain, 0, self::MAX_INGESTED_CONTENT_LENGTH ) . '…';
		}

		$content = sprintf(
			/* translators: 1: page title, 2: URL, 3: content */
			"%s\nURL: %s\n\n%s",
			__( 'Source:', 'mcp-ai-wpoos' ) . ' ' . $page_title,
			$url,
			$plain
		);

		$summary = sprintf(
			/* translators: %s: page title or URL */
			__( 'Web page "%s" ingested', 'mcp-ai-wpoos' ),
			$page_title
		);

		return array(
			'source_type' => 'url',
			'title'       => $page_title,
			'content'     => $content,
			'summary'     => $summary,
			'metadata'    => array(
				'url'         => $url,
				'http_status' => $http_code,
			),
			'tags'        => array( 'web-content', 'url-ingested' ),
		);
	}

	/**
	 * Sanitize context data recursively.
	 *
	 * @param array $data Context data to sanitize.
	 * @return array Sanitized data.
	 */
	private function sanitize_context_data( $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context_data( $value );
			} elseif ( is_string( $value ) ) {
				// Allow HTML in content field for formatting.
				if ( 'content' === $key ) {
					$sanitized[ $key ] = wp_kses_post( $value );
				} else {
					$sanitized[ $key ] = sanitize_text_field( $value );
				}
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Format TTL into human-readable string.
	 *
	 * @param int $seconds TTL in seconds.
	 * @return string Human-readable time.
	 */
	private function format_ttl( $seconds ) {
		$days = floor( $seconds / 86400 );
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			return sprintf( _n( '%d day', '%d days', $days, 'mcp-ai-wpoos' ), $days );
		}

		$hours = floor( $seconds / 3600 );
		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'mcp-ai-wpoos' ), $hours );
		}

		$minutes = floor( $seconds / 60 );
		/* translators: %d: number of minutes */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'mcp-ai-wpoos' ), $minutes );
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
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
			'external-api',         // May fetch user-provided URLs via wp_remote_get.
			'network-dependent',    // May fetch user-provided URLs.
			'write',                // Writes context data.
			'state-changing',       // Stores data in transients.
			'requires-capability',  // Needs user authentication.
		);
	}
}
