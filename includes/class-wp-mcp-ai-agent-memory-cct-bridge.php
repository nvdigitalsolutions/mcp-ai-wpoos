<?php
/**
 * Dual-write bridge from agent-memory transients to the JetEngine CCT.
 *
 * Phase 4b-2: subscribes to `wp_mcp_ai_memory_stored` and
 * `wp_mcp_ai_memory_deleted` to mirror agent memory into the durable
 * `ai_agent_memories` Custom Content Type. The transient store remains the
 * primary read path — this bridge is advisory and tolerates failure
 * (logged, never thrown). The mirror exists so memory survives object-cache
 * evictions and is visible to JetEngine UI / REST / export tooling.
 *
 * Industry-standard mapping persisted on every write:
 *   - `memory_tier` (Letta/Cognee) — auto-classified from `context_type` when
 *     the caller didn't supply it, otherwise honoured verbatim.
 *   - `transaction_time` (Zep) — equal to the transient `stored_at`.
 *   - `valid_from` / `valid_until` (Zep bi-temporal) — default to
 *     `stored_at` / `expires_at`; callers can override via the
 *     `wp_mcp_ai_memory_cct_record` filter.
 *   - `source` (mem0/Letta) — defaults to the firing tool name when known.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirror agent-memory writes to the durable JetEngine CCT.
 */
class WP_MCP_AI_Agent_Memory_CCT_Bridge {

	/**
	 * Subscribe to the memory lifecycle hooks.
	 */
	public static function bootstrap() {
		add_action( 'wp_mcp_ai_memory_stored', array( __CLASS__, 'on_memory_stored' ), 20, 1 );
		add_action( 'wp_mcp_ai_memory_deleted', array( __CLASS__, 'on_memory_deleted' ), 20, 1 );
	}

	/**
	 * Auto-classify a context_type into one of the four standard memory tiers.
	 *
	 * Mirrors Letta's "core vs archival" split and Cognee's
	 * working/episodic/semantic/procedural taxonomy. The mapping is
	 * intentionally conservative: anything that smells like a tool-call log
	 * goes to `procedural`, time-bounded session items to `episodic`, factual
	 * statements to `semantic`, and live working state to `working`.
	 *
	 * Callers can override the auto-classification by passing an explicit
	 * `memory_tier` in the event payload (Phase 4b-3 will surface this on
	 * `store_agent_context`).
	 *
	 * @param string $context_type Sanitized context type slug.
	 * @return string One of working|episodic|semantic|procedural.
	 */
	public static function classify_tier( $context_type ) {
		$context_type = is_string( $context_type ) ? strtolower( $context_type ) : '';

		$procedural = array( 'tool_call', 'tool_history', 'procedure', 'workflow', 'skill', 'action' );
		$episodic   = array( 'session', 'conversation', 'episode', 'event', 'decision', 'learning', 'observation' );
		$working    = array( 'working', 'scratch', 'scratchpad', 'draft' );

		if ( in_array( $context_type, $procedural, true ) ) {
			return 'procedural';
		}
		if ( in_array( $context_type, $episodic, true ) ) {
			return 'episodic';
		}
		if ( in_array( $context_type, $working, true ) ) {
			return 'working';
		}

		// Default tier for facts, preferences, identities, knowledge, merged
		// memories, and anything else that should persist beyond a session.
		return 'semantic';
	}

	/**
	 * Build the CCT record from a `wp_mcp_ai_memory_stored` event payload.
	 *
	 * Public so the test suite and downstream listeners can reuse the exact
	 * mapping logic without reaching into private state.
	 *
	 * @param array $event Event payload as documented on the action hook.
	 * @return array Record ready for `update_item()`.
	 */
	public static function build_record_from_event( array $event ) {
		$context_id   = isset( $event['context_id'] ) ? (string) $event['context_id'] : '';
		$agent_id     = isset( $event['agent_id'] ) ? (string) $event['agent_id'] : '';
		$context_type = isset( $event['context_type'] ) ? (string) $event['context_type'] : '';

		$memory_tier = isset( $event['memory_tier'] ) && '' !== $event['memory_tier']
			? sanitize_key( (string) $event['memory_tier'] )
			: self::classify_tier( $context_type );

		$tags = array();
		if ( isset( $event['tags'] ) && is_array( $event['tags'] ) ) {
			foreach ( $event['tags'] as $tag ) {
				if ( is_scalar( $tag ) && '' !== (string) $tag ) {
					$tags[] = sanitize_text_field( (string) $tag );
				}
			}
		}

		$stored_at  = isset( $event['stored_at'] ) ? (string) $event['stored_at'] : current_time( 'mysql' );
		$expires_at = isset( $event['expires_at'] ) ? (string) $event['expires_at'] : '';

		// Default bi-temporal validity = stored_at .. expires_at (Zep convention).
		$valid_from  = $stored_at;
		$valid_until = $expires_at;

		$source = isset( $event['source'] ) && '' !== $event['source']
			? sanitize_text_field( (string) $event['source'] )
			: 'store_agent_context';

		$record = array(
			'cct_status'       => 'publish',
			'context_id'       => sanitize_text_field( $context_id ),
			'agent_id'         => sanitize_text_field( $agent_id ),
			'memory_tier'      => sanitize_key( $memory_tier ),
			'context_type'     => sanitize_text_field( $context_type ),
			'wing'             => isset( $event['wing'] ) ? sanitize_text_field( (string) $event['wing'] ) : '',
			'room'             => isset( $event['room'] ) ? sanitize_text_field( (string) $event['room'] ) : '',
			'title'            => isset( $event['title'] ) ? sanitize_text_field( (string) $event['title'] ) : '',
			'content'          => isset( $event['content'] ) ? wp_kses_post( (string) $event['content'] ) : '',
			'tags'             => wp_json_encode( $tags ),
			'importance'       => isset( $event['importance'] ) ? sanitize_key( (string) $event['importance'] ) : 'medium',
			'verbatim'         => ! empty( $event['verbatim'] ) ? 1 : 0,
			'transaction_time' => $stored_at,
			'valid_from'       => $valid_from,
			'valid_until'      => $valid_until,
			'expires_at'       => $expires_at,
			'ttl_seconds'      => isset( $event['ttl'] ) ? absint( $event['ttl'] ) : 0,
			'source'           => $source,
			'source_post_id'   => isset( $event['source_post_id'] ) ? absint( $event['source_post_id'] ) : 0,
			'source_url'       => isset( $event['source_url'] ) ? esc_url_raw( (string) $event['source_url'] ) : '',
			'source_type'      => isset( $event['source_type'] ) ? sanitize_key( (string) $event['source_type'] ) : '',
			'embedding_id'     => isset( $event['embedding_id'] ) ? sanitize_text_field( (string) $event['embedding_id'] ) : '',
			'graph_node_id'    => isset( $event['graph_node_id'] ) ? sanitize_text_field( (string) $event['graph_node_id'] ) : '',
			'metadata'         => isset( $event['metadata'] ) && is_array( $event['metadata'] ) ? wp_json_encode( $event['metadata'] ) : '',
			// MemPalace Capture Framework Phase A — privacy / consent envelope.
			'sensitivity'      => isset( $event['sensitivity'] ) ? sanitize_key( (string) $event['sensitivity'] ) : '',
			'consent_basis'    => isset( $event['consent_basis'] ) ? sanitize_key( (string) $event['consent_basis'] ) : '',
			'subject_refs'     => isset( $event['subject_refs'] ) && is_array( $event['subject_refs'] )
				? wp_json_encode( array_values( array_filter( array_map( 'sanitize_text_field', $event['subject_refs'] ) ) ) )
				: '',
			'attachments'      => isset( $event['attachments'] ) && is_array( $event['attachments'] )
				? wp_json_encode( $event['attachments'] )
				: '',
			// Memory Layer 2026 Enhancements Phase 2 — schema v2 fields.
			// Each field is optional in the event payload; legacy callers that
			// don't pass these continue to work — sensible defaults are written
			// and read consumers (Phase 5 decay sweep, Phase 3 dedup) fall back
			// gracefully when the field is empty on legacy rows.
			'content_hash'     => self::resolve_content_hash( $event ),
			'confidence_score' => isset( $event['confidence_score'] ) && is_numeric( $event['confidence_score'] )
				? (string) max( 0.0, min( 1.0, (float) $event['confidence_score'] ) )
				: '1.0',
			'last_accessed_at' => isset( $event['last_accessed_at'] ) && '' !== $event['last_accessed_at']
				? sanitize_text_field( (string) $event['last_accessed_at'] )
				: $stored_at,
			'superseded_by'    => isset( $event['superseded_by'] ) ? sanitize_text_field( (string) $event['superseded_by'] ) : '',
			'auto_captured'    => ! empty( $event['auto_captured'] ) ? 1 : 0,
		);

		/**
		 * Filter the agent-memory CCT record before it is persisted.
		 *
		 * Useful for site-specific PII redaction, custom provenance fields,
		 * or attaching a precomputed embedding/graph node ID.
		 *
		 * @since 1.1.0
		 *
		 * @param array $record CCT record ready for update_item().
		 * @param array $event  Original `wp_mcp_ai_memory_stored` event payload.
		 */
		$record = apply_filters( 'wp_mcp_ai_memory_cct_record', $record, $event );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Resolve the content hash for a stored-memory event.
	 *
	 * Uses the caller-supplied `content_hash` when present (e.g. from Phase 3's
	 * auto-capture service which computes it before the dedup window check).
	 * Otherwise computes a SHA-256 over normalised content so legacy callers
	 * still produce hashable records for downstream contradiction detection.
	 *
	 * @since 1.1.20
	 *
	 * @param array $event Memory event payload.
	 * @return string Lower-case 64-char hex SHA-256, or '' when no content is available.
	 */
	protected static function resolve_content_hash( array $event ) {
		if ( isset( $event['content_hash'] ) && is_string( $event['content_hash'] ) && '' !== $event['content_hash'] ) {
			return sanitize_text_field( $event['content_hash'] );
		}

		$content = isset( $event['content'] ) ? (string) $event['content'] : '';
		if ( '' === $content ) {
			return '';
		}

		// Normalise before hashing so trivial whitespace / case differences
		// don't fragment the dedup window in Phase 3.
		$normalised = self::normalise_for_hash( $content );

		return hash( 'sha256', $normalised );
	}

	/**
	 * Normalise text for content-hash computation.
	 *
	 * Lower-cases (where multibyte support is available), collapses runs of
	 * whitespace to single spaces, and trims. Stateless; safe to call from any
	 * thread or hook context.
	 *
	 * @since 1.1.20
	 *
	 * @param string $text Raw text.
	 * @return string Normalised text.
	 */
	public static function normalise_for_hash( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}

		$text = preg_replace( '/\s+/u', ' ', $text );

		return null === $text ? '' : trim( $text );
	}

	/**
	 * Listener: mirror a stored memory into the CCT.
	 *
	 * Tolerant of every failure mode — JetEngine missing, CCT not registered,
	 * handler unavailable, or the underlying DB write rejecting the record.
	 *
	 * @param array $event Event payload from `wp_mcp_ai_memory_stored`.
	 * @return void
	 */
	public static function on_memory_stored( $event ) {
		if ( ! is_array( $event ) || empty( $event['context_id'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			self::warn_once(
				'jetengine_cct_class_missing',
				__( 'Agent memory CCT bridge: WP_MCP_AI_JetEngine_Agent_Memories_CCT class is missing — memory not mirrored to JetEngine CCT.', 'mcp-ai-wpoos' ),
				array(
					'context_id' => isset( $event['context_id'] ) ? (string) $event['context_id'] : '',
					'agent_id'   => isset( $event['agent_id'] ) ? (string) $event['agent_id'] : '',
				)
			);
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			self::warn_once(
				'jetengine_handler_unavailable',
				__( 'Agent memory CCT bridge: JetEngine item handler unavailable — memory not mirrored to JetEngine CCT.', 'mcp-ai-wpoos' ),
				array_merge(
					array(
						'context_id' => isset( $event['context_id'] ) ? (string) $event['context_id'] : '',
						'agent_id'   => isset( $event['agent_id'] ) ? (string) $event['agent_id'] : '',
					),
					self::collect_jetengine_status()
				)
			);
			return;
		}

		$record = self::build_record_from_event( $event );

		if ( empty( $record ) ) {
			return;
		}

		// If a row already exists for this context_id (e.g. a follow-up store
		// from the same context), update it rather than creating a duplicate.
		$existing_id = self::find_existing_id_for_context( (string) $event['context_id'] );
		if ( $existing_id ) {
			$record['_ID'] = $existing_id;
		}

		try {
			$result = $handler->update_item( $record );
			if ( is_wp_error( $result ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Agent memory CCT bridge: failed to mirror memory.',
					array(
						'context_id'    => $event['context_id'],
						'agent_id'      => isset( $event['agent_id'] ) ? $event['agent_id'] : '',
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Agent memory CCT bridge: exception while mirroring memory.',
					array(
						'context_id' => $event['context_id'],
						'message'    => $exception->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Listener: tear down the CCT row when its transient counterpart is deleted.
	 *
	 * @param array $event Event payload from `wp_mcp_ai_memory_deleted`. Must
	 *                     include `context_id`; `agent_id` is informational.
	 * @return void
	 */
	public static function on_memory_deleted( $event ) {
		if ( ! is_array( $event ) || empty( $event['context_id'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();

		if ( ! is_object( $handler ) ) {
			return;
		}

		$existing_id = self::find_existing_id_for_context( (string) $event['context_id'] );

		if ( ! $existing_id ) {
			return;
		}

		try {
			if ( method_exists( $handler, 'delete_item' ) ) {
				$handler->delete_item( $existing_id );
			} elseif ( method_exists( $handler, 'update_item' ) ) {
				// Fallback for older JetEngine: soft-delete by emptying content.
				$handler->update_item(
					array(
						'_ID'         => $existing_id,
						'cct_status'  => 'trash',
						'valid_until' => current_time( 'mysql' ),
					)
				);
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Agent memory CCT bridge: exception while deleting mirror row.',
					array(
						'context_id' => $event['context_id'],
						'message'    => $exception->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Locate an existing CCT row by `context_id`.
	 *
	 * Direct DB query is necessary because JetEngine doesn't expose a "find
	 * by meta" path on the CCT handler in earlier versions. Result is cached
	 * for the request lifetime to avoid duplicate queries during update bursts.
	 *
	 * @param string $context_id Stable memory identifier.
	 * @return int|null Row `_ID` when found, null otherwise.
	 */
	protected static function find_existing_id_for_context( $context_id ) {
		static $cache = array();

		if ( '' === $context_id ) {
			return null;
		}

		if ( array_key_exists( $context_id, $cache ) ) {
			return $cache[ $context_id ];
		}

		global $wpdb;
		$slug = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		// Table name is constructed from the JetEngine convention
		// `{prefix}jet_cct_{slug}` and `$slug` comes from a class constant.
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		// Confirm the table exists before querying (CCT may not be registered yet).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Direct query on JetEngine CCT table; table name from class constant. WP_Query does not support SHOW TABLES.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$cache[ $context_id ] = null;
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on JetEngine CCT table; table name from class constant, not user input.
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class constant, not user input.
				"SELECT _ID FROM `{$table}` WHERE context_id = %s LIMIT 1",
				$context_id
			)
		);

		$cache[ $context_id ] = $row_id ? (int) $row_id : null;
		return $cache[ $context_id ];
	}

	/**
	 * Per-request flag set used by `warn_once`.
	 *
	 * @var array<string,bool>
	 */
	protected static $warned_reasons = array();

	/**
	 * Reset the rate-limited warning state. Used by tests to assert
	 * "logs exactly once per request" semantics across multiple cases.
	 *
	 * @return void
	 */
	public static function reset_warn_state() {
		self::$warned_reasons = array();
	}

	/**
	 * Emit a single warning per (reason) per request to the activity log.
	 *
	 * Prevents a 50-item mining batch from spamming `wp_mcp_ai_recent_errors`
	 * with the same "JetEngine handler missing" message 50 times.
	 *
	 * @param string $reason  Stable reason key (used for de-duplication).
	 * @param string $message Human-readable warning message.
	 * @param array  $context Additional structured context for the log entry.
	 * @return void
	 */
	protected static function warn_once( $reason, $message, array $context = array() ) {
		$reason = (string) $reason;
		if ( '' === $reason || isset( self::$warned_reasons[ $reason ] ) ) {
			return;
		}
		self::$warned_reasons[ $reason ] = true;

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		$context['reason'] = $reason;
		WP_MCP_AI_Logger::log_warning( $message, $context );
	}

	/**
	 * Collect a snapshot of the JetEngine module status to attach to bridge
	 * warnings. All keys are best-effort; absence is normal when JetEngine
	 * isn't installed.
	 *
	 * @return array<string,mixed>
	 */
	protected static function collect_jetengine_status() {
		$status = array(
			'jet_engine_loaded'    => function_exists( 'jet_engine' ),
			'data_stores_active'   => false,
			'cct_module_loaded'    => false,
			'agent_memories_table' => false,
		);

		if ( $status['jet_engine_loaded'] ) {
			$engine = jet_engine();
			if ( ! empty( $engine->modules ) && method_exists( $engine->modules, 'is_module_active' ) ) {
				$status['data_stores_active'] = (bool) $engine->modules->is_module_active( 'data-stores' );
			}
			if ( class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
				$status['cct_module_loaded'] = true;
			}
		}

		if ( class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			global $wpdb;
			$slug  = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
			$table = $wpdb->prefix . 'jet_cct_' . $slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct diagnostic query on JetEngine CCT table; table name from class constant. WP_Query does not support SHOW TABLES.
			$found                          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$status['agent_memories_table'] = ( $found === $table );
		}

		return $status;
	}
}

WP_MCP_AI_Agent_Memory_CCT_Bridge::bootstrap();
