<?php
/**
 * Transcript Repository
 *
 * Handles database operations for chat transcripts.
 * Part of Phase 1.3 separation of concerns refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transcript Repository class
 *
 * Responsible for:
 * - Transcript data access
 * - Transcript deletion
 * - Database table management
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Transcript_Repository {

	/**
	 * Get the transcript table name
	 *
	 * @return string Table name or empty string if not available.
	 */
	public function get_table_name() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return '';
		}

		$slug = WP_MCP_AI_JetEngine_CCT::get_slug();

		if ( '' === $slug ) {
			return '';
		}

		return $wpdb->prefix . 'jet_cct_' . $slug;
	}

	/**
	 * Check if the transcript table exists
	 *
	 * @return bool True if table exists, false otherwise.
	 */
	public function table_exists() {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table ) {
			return false;
		}

		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $result === $table;
	}

	/**
	 * Delete transcript entries for a session and user
	 *
	 * @param string $session_key Session key.
	 * @param int    $user_id     User ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_transcript( $session_key, $user_id ) {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table ) {
			return false;
		}

		if ( ! $this->table_exists() ) {
			return false;
		}

		// Delete all transcript entries for this session and user.
		$deleted = $wpdb->delete(
			$table,
			array(
				'session_key'   => $session_key,
				'cct_author_id' => $user_id,
			),
			array( '%s', '%d' )
		);

		return $deleted;
	}
}
