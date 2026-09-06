<?php
/**
 * Agent memory CCT bridge for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's `WP_MCP_AI_Agent_Memory_CCT_Bridge`
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Mirrors `wp_mcp_ai_memory_stored` /
 * `wp_mcp_ai_memory_deleted` events into the durable JetEngine
 * `ai_agent_memories` CCT with the same record mapping, tier
 * auto-classification, content-hash normalisation, upsert-by-context_id
 * semantics, and warning throttling.
 *
 * Decoupling (documented, additive):
 * - `bootstrap()` is called standalone-only by `Plugin.php` — the base
 *   plugin owns the same listeners in monolith installs and a second
 *   subscription would double-mirror every memory write.
 * - The CCT class resolves per install mode (base
 *   `WP_MCP_AI_JetEngine_Agent_Memories_CCT` monolith / the ported
 *   `AgentMemoriesCct` standalone).
 * - In CG-AI standalone the `wp_mcp_ai_memory_stored` event is not
 *   emitted by anything yet — the bridge is dormant until the memory /
 *   tools wave ports the `store_agent_context` pipeline (tracked gap).
 *
 * @package NvoosContentGraphAi\Memory
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors memory lifecycle events into the durable CCT.
 *
 * @since 1.1.0
 */
class AgentMemoryCctBridge {

	/**
	 * Subscribe to the memory lifecycle hooks.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_action( 'wp_mcp_ai_memory_stored', array( __CLASS__, 'on_memory_stored' ), 20, 1 );
		add_action( 'wp_mcp_ai_memory_deleted', array( __CLASS__, 'on_memory_deleted' ), 20, 1 );
	}

	/**
	 * Auto-classify a context_type into one of the four standard memory tiers.
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
		 * @param array $record CCT record ready for update_item().
		 * @param array $event  Original `wp_mcp_ai_memory_stored` event payload.
		 */
		$record = apply_filters( 'wp_mcp_ai_memory_cct_record', $record, $event );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Resolve the content hash for a stored-memory event.
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
		// don't fragment the dedup window.
		$normalised = self::normalise_for_hash( $content );

		return hash( 'sha256', $normalised );
	}

	/**
	 * Normalise text for content-hash computation.
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
	 * @param array $event Event payload from `wp_mcp_ai_memory_stored`.
	 * @return void
	 */
	public static function on_memory_stored( $event ): void {
		if ( ! is_array( $event ) || empty( $event['context_id'] ) ) {
			return;
		}

		$cct_class = self::cct_class();
		if ( ! class_exists( $cct_class ) ) {
			self::warn_once(
				'jetengine_cct_class_missing',
				__( 'Agent memory CCT bridge: agent memories CCT class is missing — memory not mirrored to JetEngine CCT.', 'nvoos-content-graph-ai' ),
				array(
					'context_id' => isset( $event['context_id'] ) ? (string) $event['context_id'] : '',
					'agent_id'   => isset( $event['agent_id'] ) ? (string) $event['agent_id'] : '',
				)
			);
			return;
		}

		$handler = $cct_class::get_item_handler();

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			self::warn_once(
				'jetengine_handler_unavailable',
				__( 'Agent memory CCT bridge: JetEngine item handler unavailable — memory not mirrored to JetEngine CCT.', 'nvoos-content-graph-ai' ),
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
		$existing_id = self::find_existing_id_for_context( (string) $event['context_id'], $cct_class );
		if ( $existing_id ) {
			$record['_ID'] = $existing_id;
		}

		try {
			$result = $handler->update_item( $record );
			if ( is_wp_error( $result ) ) {
				self::log_error(
					'Agent memory CCT bridge: failed to mirror memory.',
					array(
						'context_id'    => $event['context_id'],
						'agent_id'      => isset( $event['agent_id'] ) ? $event['agent_id'] : '',
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);
			}
		} catch ( \Throwable $exception ) {
			self::log_error(
				'Agent memory CCT bridge: exception while mirroring memory.',
				array(
					'context_id' => $event['context_id'],
					'message'    => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * Listener: tear down the CCT row when its transient counterpart is deleted.
	 *
	 * @param array $event Event payload from `wp_mcp_ai_memory_deleted`.
	 * @return void
	 */
	public static function on_memory_deleted( $event ): void {
		if ( ! is_array( $event ) || empty( $event['context_id'] ) ) {
			return;
		}

		$cct_class = self::cct_class();
		if ( ! class_exists( $cct_class ) ) {
			return;
		}

		$handler = $cct_class::get_item_handler();

		if ( ! is_object( $handler ) ) {
			return;
		}

		$existing_id = self::find_existing_id_for_context( (string) $event['context_id'], $cct_class );

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
		} catch ( \Throwable $exception ) {
			self::log_error(
				'Agent memory CCT bridge: exception while deleting mirror row.',
				array(
					'context_id' => $event['context_id'],
					'message'    => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * Locate an existing CCT row by `context_id`.
	 *
	 * @param string $context_id Stable memory identifier.
	 * @param string $cct_class  Resolved CCT class name.
	 * @return int|null Row `_ID` when found, null otherwise.
	 */
	protected static function find_existing_id_for_context( $context_id, $cct_class ) {
		static $cache = array();

		if ( '' === $context_id ) {
			return null;
		}

		if ( array_key_exists( $context_id, $cache ) ) {
			return $cache[ $context_id ];
		}

		global $wpdb;
		$slug = $cct_class::get_slug();
		// Table name is constructed from the JetEngine convention
		// `{prefix}jet_cct_{slug}` and `$slug` comes from a class constant.
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		// Confirm the table exists before querying (CCT may not be registered yet).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query on JetEngine CCT table; table name from class constant.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$cache[ $context_id ] = null;
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on JetEngine CCT table; table name from class constant, not user input.
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
	 * Resolve the CCT class name for the active install mode.
	 *
	 * @return string
	 */
	protected static function cct_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return 'WP_MCP_AI_JetEngine_Agent_Memories_CCT';
		}

		return __NAMESPACE__ . '\\AgentMemoriesCct';
	}

	/**
	 * Forward an error to the logger when available.
	 *
	 * The base logger is monolith-only; standalone logging lands with the
	 * platform wave (documented seam).
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Structured context.
	 * @return void
	 */
	protected static function log_error( $message, array $context = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}

	/**
	 * Per-request flag set used by `warn_once`.
	 *
	 * @var array<string,bool>
	 */
	protected static $warned_reasons = array();

	/**
	 * Reset the rate-limited warning state (test seam).
	 *
	 * @return void
	 */
	public static function reset_warn_state(): void {
		self::$warned_reasons = array();
	}

	/**
	 * Emit a single warning per (reason) per request.
	 *
	 * @param string $reason  Stable reason key (used for de-duplication).
	 * @param string $message Human-readable warning message.
	 * @param array  $context Additional structured context for the log entry.
	 * @return void
	 */
	protected static function warn_once( $reason, $message, array $context = array() ): void {
		$reason = (string) $reason;
		if ( '' === $reason || isset( self::$warned_reasons[ $reason ] ) ) {
			return;
		}
		self::$warned_reasons[ $reason ] = true;

		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		$context['reason'] = $reason;
		\WP_MCP_AI_Logger::log_warning( $message, $context );
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

		$cct_class = self::cct_class();
		if ( class_exists( $cct_class ) ) {
			global $wpdb;
			$slug  = $cct_class::get_slug();
			$table = $wpdb->prefix . 'jet_cct_' . $slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct diagnostic query on JetEngine CCT table; table name from class constant.
			$found                          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$status['agent_memories_table'] = ( $found === $table );
		}

		return $status;
	}
}
