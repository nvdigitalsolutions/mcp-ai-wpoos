<?php
/**
 * CCT writer for imported conversations (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_CCT_Writer`: byte-identical record
 * mapping (session key, user/author IDs, `import-{platform}` assistant
 * identity, model fallback, request/response payload encoders,
 * provenance metadata, request/response timestamps), the
 * `wp_mcp_ai_conversation_import_record` filter, the insert-vs-update
 * `write()` with the `wp_mcp_ai_import_write_exception` envelope, and
 * the prepared SQL `find_existing_ids()` dedupe lookup against the
 * `ai_chat_transcripts` CCT table.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - JetEngine availability resolves per install mode via
 *    `jetengine_available()` (`defined( 'WP_MCP_AI_PATH' ) &&
 *    class_exists( 'WP_MCP_AI_JetEngine_CCT' ) &&
 *    is_storage_available()`) — standalone installs degrade with the
 *    documented `wp_mcp_ai_import_jetengine_missing` error (the base
 *    collaborator only exists monolith).
	 *  - The importer version resolves per mode: base `WP_MCP_AI_VERSION`
	 *    monolith, `NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION` standalone
	 *    (the base's `defined()` guard would silently record an empty
	 *    version standalone).
	 *  - The conversation parameter is deliberately untyped on the
	 *    public surface: monolith installs flow the base
	 *    `WP_MCP_AI_Conversation_Import_Conversation` class through the
	 *    manager (base adapters), so the boundary validates via
	 *    `is_conversation_instance()` — the per-mode seam — instead of
	 *    a fixed type hint (same rationale as the detector's
	 *    `is_adapter_instance()`).
	 *  - `Throwable` and `WP_Error` are fully qualified.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Persists canonical conversations into the transcript CCT.
 *
 * @since 2.1.0
 */
class CctWriter {

	/**
	 * Build the CCT record for a canonical conversation.
	 *
	 * Public so tests and downstream filters can reuse the exact mapping.
	 *
	 * @param Conversation|\WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation (platform class standalone, base class monolith).
	 * @param int                                                       $user_id      WordPress user ID.
	 * @return array Record ready for `update_item()`.
	 */
	public function build_record( $conversation, $user_id ) {
		$user_id = absint( $user_id );

		$record = array(
			'session_key'      => $conversation->get_session_key(),
			'user_id'          => $user_id,
			'cct_author_id'    => $user_id,
			'assistant_id'     => 'import-' . $conversation->get_platform(),
			'assistant_model'  => '' !== $conversation->get_model() ? $conversation->get_model() : 'unknown-model',
			'request_payload'  => $conversation->encode_request_payload(),
			'response_payload' => $conversation->encode_response_payload(),
		);

		$imported_at = gmdate( 'c' );
		$version     = $this->importer_version();

		$metadata = $conversation->build_metadata( $imported_at, $version );
		$encoded  = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		if ( false !== $encoded ) {
			$record['metadata'] = $encoded;
		}

		if ( 0 !== $conversation->get_created_at() ) {
			$record['request_started_at'] = $conversation->get_created_at();
		}
		if ( 0 !== $conversation->get_updated_at() ) {
			$record['response_completed_at'] = $conversation->get_updated_at();
		}

		/**
		 * Filter the CCT record before an imported conversation is persisted.
		 *
		 * @since 2.1.0
		 *
		 * @param array        $record       Record ready for update_item().
		 * @param Conversation $conversation Canonical conversation.
		 * @param int          $user_id      Importing WordPress user ID.
		 */
		return apply_filters( 'wp_mcp_ai_conversation_import_record', $record, $conversation, $user_id );
	}

	/**
	 * Resolve the importer version string per install mode.
	 *
	 * @return string Version string.
	 */
	protected function importer_version() {
		return defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_VERSION : NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Write one canonical conversation to the CCT.
	 *
	 * @param Conversation|\WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation (platform class standalone, base class monolith).
	 * @param int                                                       $user_id      WordPress user ID.
	 * @param int                                                       $existing_id  Existing row ID for refresh, 0 for insert.
	 * @return array|\WP_Error {
	 *     Result on success.
	 *
	 *     @type int    $id     CCT row ID.
	 *     @type string $action "imported" or "updated".
	 * }
	 */
	public function write( $conversation, $user_id, $existing_id = 0 ) {
		if ( ! $this->is_conversation_instance( $conversation ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_invalid_conversation',
				__( 'The canonical conversation is invalid for this install mode.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$handler = $this->get_handler();
		if ( is_wp_error( $handler ) ) {
			return $handler;
		}

		$record = $this->build_record( $conversation, $user_id );

		$existing_id = absint( $existing_id );
		if ( 0 !== $existing_id ) {
			$record['_ID'] = $existing_id;
		}

		try {
			$result = $handler->update_item( $record );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'wp_mcp_ai_import_write_exception',
				/* translators: %s: exception message. */
				sprintf( __( 'CCT write threw: %s', 'nvoos-content-graph-ai-platform' ), $e->getMessage() )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row_id = $existing_id;
		if ( 0 === $row_id && is_numeric( $result ) ) {
			$row_id = absint( $result );
		}

		return array(
			'id'     => $row_id,
			'action' => 0 !== $existing_id ? 'updated' : 'imported',
		);
	}

	/**
	 * Look up existing CCT rows for a set of session keys.
	 *
	 * @param string[] $session_keys Session keys to look up.
	 * @return array|\WP_Error Map of session_key => row ID, or a WP_Error.
	 */
	public function find_existing_ids( array $session_keys ) {
		$session_keys = array_values( array_filter( array_map( 'strval', $session_keys ) ) );
		if ( empty( $session_keys ) ) {
			return array();
		}

		if ( ! $this->jetengine_available() ) {
			return new \WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversation lookups are unavailable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		global $wpdb;

		$table        = $wpdb->prefix . 'jet_cct_' . \WP_MCP_AI_JetEngine_CCT::SLUG;
		$placeholders = implode( ', ', array_fill( 0, count( $session_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; values are fully prepared. JetEngine CCT rows have no WordPress query-cache group, so caching here would add staleness risk for dedupe checks.
		$sql  = $wpdb->prepare( "SELECT `_ID`, `session_key` FROM {$table} WHERE `session_key` IN ({$placeholders})", $session_keys );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; JetEngine CCT rows have no WP query-cache group.

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_mcp_ai_import_lookup_failed',
				__( 'Could not look up existing imported conversations.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$map = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['session_key'] ) && isset( $row['_ID'] ) ) {
				$map[ (string) $row['session_key'] ] = absint( $row['_ID'] );
			}
		}

		return $map;
	}

	/**
	 * Resolve the JetEngine CCT item handler.
	 *
	 * @return object|\WP_Error
	 */
	protected function get_handler() {
		if ( ! $this->jetengine_available() ) {
			return new \WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversations cannot be written to the CCT.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$handler = \WP_MCP_AI_JetEngine_CCT::get_item_handler();

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_handler_missing',
				__( 'The transcript CCT item handler is unavailable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $handler;
	}

	/**
	 * Whether a candidate satisfies the canonical conversation class for
	 * this install mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `instanceof` — because monolith installs flow the base conversation
	 * class (base adapters) while standalone installs flow this package's
	 * class. Both share a byte-identical method surface.
	 *
	 * @param object $candidate Candidate conversation instance.
	 * @return bool
	 */
	protected function is_conversation_instance( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Conversation_Import_Conversation;
		}

		return $candidate instanceof Conversation;
	}

	/**
	 * Whether the JetEngine transcript CCT is available for this install
	 * mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `class_exists()` — because the monorepo classmap resolves the base
	 * CCT class standalone, where its JetEngine storage surface does not
	 * exist.
	 *
	 * @return bool
	 */
	protected function jetengine_available(): bool {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return false;
		}

		return (bool) \WP_MCP_AI_JetEngine_CCT::is_storage_available();
	}
}
