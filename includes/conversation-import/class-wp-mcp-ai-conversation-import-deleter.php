<?php
/**
 * Deleter for imported conversation rows.
 *
 * Removes rows previously written by the conversation import pipeline from
 * the `ai_chat_transcripts` JetEngine CCT, scoped by platform and optionally
 * by importing user.
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
 * Deletes imported conversation CCT rows.
 */
class WP_MCP_AI_Conversation_Import_Deleter {

	const MAX_DELETES_PER_RUN = 500;

	/**
	 * Count imported conversation rows, optionally scoped by platform.
	 *
	 * Consumed by dashboards and analytics surfaces to report how much of the
	 * transcript CCT originates from external imports.
	 *
	 * @param string $platform Optional platform slug ('' = all platforms).
	 * @return int|\WP_Error Row count, or a WP_Error.
	 */
	public function count_imported( $platform = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ! WP_MCP_AI_JetEngine_CCT::is_storage_available() ) {
			return new WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversation counts are unavailable.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . WP_MCP_AI_JetEngine_CCT::SLUG;

		if ( '' !== sanitize_key( (string) $platform ) ) {
			$like = $wpdb->esc_like( 'import-' . sanitize_key( (string) $platform ) . '-' ) . '%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; value fully prepared. CCT rows have no WP query-cache group.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE `session_key` LIKE %s",
					$like
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$like = $wpdb->esc_like( 'import-' ) . '%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; value fully prepared. CCT rows have no WP query-cache group.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE `session_key` LIKE %s",
					$like
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		if ( null === $count ) {
			return new WP_Error(
				'wp_mcp_ai_import_count_failed',
				__( 'Could not count imported conversations.', 'mcp-ai-wpoos' )
			);
		}

		return absint( $count );
	}

	/**
	 * Look up CCT row IDs for imported conversations.
	 *
	 * @param string $platform Platform slug (e.g. "chatgpt").
	 * @param int    $user_id  Optional importing user ID (0 = all).
	 * @param int    $limit    Max rows to return (0 = safety cap).
	 * @return array|\WP_Error Row IDs, or a WP_Error.
	 */
	public function find_ids( $platform, $user_id = 0, $limit = 0 ) {
		$platform = sanitize_key( (string) $platform );
		if ( '' === $platform ) {
			return new WP_Error(
				'wp_mcp_ai_import_delete_invalid_platform',
				__( 'Provide the platform slug of the imported conversations to delete.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ! WP_MCP_AI_JetEngine_CCT::is_storage_available() ) {
			return new WP_Error(
				'wp_mcp_ai_import_jetengine_missing',
				__( 'JetEngine is not active; imported conversation lookups are unavailable.', 'mcp-ai-wpoos' )
			);
		}

		global $wpdb;

		$limit = absint( $limit );
		if ( $limit <= 0 ) {
			$limit = self::MAX_DELETES_PER_RUN;
		}
		$limit = min( $limit, self::MAX_DELETES_PER_RUN );

		$table = $wpdb->prefix . 'jet_cct_' . WP_MCP_AI_JetEngine_CCT::SLUG;
		$like  = $wpdb->esc_like( 'import-' . $platform . '-' ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; values fully prepared. CCT rows have no WP query-cache group.
		if ( absint( $user_id ) > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT `_ID` FROM {$table} WHERE `session_key` LIKE %s AND `cct_author_id` = %d ORDER BY `_ID` DESC LIMIT %d",
				$like,
				absint( $user_id ),
				$limit
			);
			$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		} else {
			$sql = $wpdb->prepare(
				"SELECT `_ID` FROM {$table} WHERE `session_key` LIKE %s ORDER BY `_ID` DESC LIMIT %d",
				$like,
				$limit
			);
			$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( null === $ids ) {
			return new WP_Error(
				'wp_mcp_ai_import_delete_lookup_failed',
				__( 'Could not look up imported conversations for deletion.', 'mcp-ai-wpoos' )
			);
		}

		return array_map( 'absint', $ids );
	}

	/**
	 * Delete imported conversation rows for a platform.
	 *
	 * @param string $platform Platform slug (e.g. "chatgpt").
	 * @param int    $user_id  Optional importing user ID (0 = all).
	 * @param int    $limit    Max rows to delete (0 = safety cap).
	 * @param bool   $dry_run  Count only; do not delete.
	 * @return array|\WP_Error Report array, or a WP_Error.
	 */
	public function delete( $platform, $user_id = 0, $limit = 0, $dry_run = false ) {
		$ids = $this->find_ids( $platform, $user_id, $limit );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$report = array(
			'platform' => sanitize_key( (string) $platform ),
			'found'    => count( $ids ),
			'deleted'  => 0,
			'dry_run'  => (bool) $dry_run,
			'failed'   => 0,
		);

		if ( $dry_run || empty( $ids ) ) {
			return $report;
		}

		$handler = WP_MCP_AI_JetEngine_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'delete_item' ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_handler_missing',
				__( 'The transcript CCT item handler is unavailable.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( $ids as $id ) {
			$deleted = $handler->delete_item( $id );
			if ( $deleted ) {
				++$report['deleted'];
			} else {
				++$report['failed'];
			}
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'Imported conversations deleted',
				array(
					'platform' => $report['platform'],
					'deleted'  => $report['deleted'],
					'failed'   => $report['failed'],
				)
			);
		}

		return $report;
	}

	/**
	 * Delete a single imported conversation row by session key.
	 *
	 * @param string $session_key Import session key (e.g. "import-chatgpt-abc123").
	 * @return bool True when a row was deleted.
	 */
	public function delete_by_session_key( $session_key ) {
		$session_key = sanitize_text_field( (string) $session_key );
		if ( '' === $session_key || 0 !== strpos( $session_key, 'import-' ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ! WP_MCP_AI_JetEngine_CCT::is_storage_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . WP_MCP_AI_JetEngine_CCT::SLUG;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; value fully prepared. CCT rows have no WP query-cache group.
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `_ID` FROM {$table} WHERE `session_key` = %s LIMIT 1",
				$session_key
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( empty( $row_id ) ) {
			return false;
		}

		$handler = WP_MCP_AI_JetEngine_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'delete_item' ) ) {
			return false;
		}

		return (bool) $handler->delete_item( absint( $row_id ) );
	}
}
