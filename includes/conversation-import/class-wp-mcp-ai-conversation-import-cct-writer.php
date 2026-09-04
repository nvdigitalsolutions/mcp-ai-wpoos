<?php
/**
 * CCT writer for imported conversations.
 *
 * Maps canonical {@see WP_MCP_AI_Conversation_Import_Conversation} objects onto
 * rows of the `ai_chat_transcripts` JetEngine CCT (one row per conversation).
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists canonical conversations into the transcript CCT.
 */
class WP_MCP_AI_Conversation_Import_CCT_Writer {

	/**
	 * Build the CCT record for a canonical conversation.
	 *
	 * Public so tests and downstream filters can reuse the exact mapping.
	 *
	 * @param WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation.
	 * @param int                                        $user_id      WordPress user ID.
	 * @return array Record ready for `update_item()`.
	 */
	public function build_record( WP_MCP_AI_Conversation_Import_Conversation $conversation, $user_id ) {
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
		$version     = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '';

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
		 * @since 1.1.60
		 *
		 * @param array                                        $record       Record ready for update_item().
		 * @param WP_MCP_AI_Conversation_Import_Conversation   $conversation Canonical conversation.
		 * @param int                                          $user_id      Importing WordPress user ID.
		 */
		return apply_filters( 'wp_mcp_ai_conversation_import_record', $record, $conversation, $user_id );
	}

	/**
	 * Write one canonical conversation to the CCT.
	 *
	 * @param WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation.
	 * @param int                                        $user_id      WordPress user ID.
	 * @param int                                        $existing_id  Existing row ID for refresh, 0 for insert.
	 * @return array|\WP_Error {
	 *     Result on success.
	 *
	 *     @type int    $id     CCT row ID.
	 *     @type string $action "imported" or "updated".
	 * }
	 */
	public function write( WP_MCP_AI_Conversation_Import_Conversation $conversation, $user_id, $existing_id = 0 ) {
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
		} catch ( Throwable $e ) {
			return new WP_Error(
				'wp_mcp_ai_import_write_exception',
				/* translators: %s: exception message. */
				sprintf( __( 'CCT write threw: %s', 'mcp-ai-wpoos' ), $e->getMessage() )
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

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ! WP_MCP_AI_JetEngine_CCT::is_storage_available() ) {
			return new WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversation lookups are unavailable.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$table        = $wpdb->prefix . 'jet_cct_' . WP_MCP_AI_JetEngine_CCT::SLUG;
		$placeholders = implode( ', ', array_fill( 0, count( $session_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; values are fully prepared. JetEngine CCT rows have no WordPress query-cache group, so caching here would add staleness risk for dedupe checks.
		$sql  = $wpdb->prepare( "SELECT `_ID`, `session_key` FROM {$table} WHERE `session_key` IN ({$placeholders})", $session_keys );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; JetEngine CCT rows have no WP query-cache group.

		if ( null === $rows ) {
			return new WP_Error(
				'wp_mcp_ai_import_lookup_failed',
				__( 'Could not look up existing imported conversations.', 'mcp-ai-wpoos' )
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
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ! WP_MCP_AI_JetEngine_CCT::is_storage_available() ) {
			return new WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversations cannot be written to the CCT.', 'mcp-ai-wpoos' )
			);
		}

		$handler = WP_MCP_AI_JetEngine_CCT::get_item_handler();

		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_handler_missing',
				__( 'The transcript CCT item handler is unavailable.', 'mcp-ai-wpoos' )
			);
		}

		return $handler;
	}
}
