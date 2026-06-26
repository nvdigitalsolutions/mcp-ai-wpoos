<?php
/**
 * WP-CLI command for managing chat threads.
 *
 * @package WP_MCP_AI
 * @since   1.1.30
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage agent conversation threads.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Thread_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List all threads.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Filter by user ID.
	 *
	 * [--assistant=<id>]
	 * : Filter by assistant post ID.
	 *
	 * [--status=<status>]
	 * : Filter by status (active, archived).
	 * ---
	 * default: active
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum threads (default: 20).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai thread list --assistant=123
	 *     $ wp mcp-ai thread list --status=archived --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) {
		$manager = $this->get_manager();

		global $wpdb;
		$table        = $manager->get_threads_table();
		$limit        = min( 100, absint( $assoc_args['limit'] ?? 20 ) );
		$status       = sanitize_key( $assoc_args['status'] ?? 'active' );
		$user_id      = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : 0;
		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : 0;
		$format       = $assoc_args['format'] ?? 'table';

		$where = $wpdb->prepare( 'WHERE status = %s', $status );
		if ( $user_id > 0 ) {
			$where .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		if ( $assistant_id > 0 ) {
			$where .= $wpdb->prepare( ' AND assistant_id = %d', $assistant_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$threads = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY updated_at DESC LIMIT {$limit}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $threads ) ) {
			$this->warning( __( 'No threads found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $threads as $t ) {
			$title_short = function_exists( 'mb_strimwidth' )
					? mb_strimwidth( $t->title, 0, 50, '…' )
					: ( strlen( $t->title ) > 50 ? substr( $t->title, 0, 49 ) . '…' : $t->title );

				$items[] = array(
					'ID'        => $t->id,
					'Title'     => $title_short,
					'Status'    => $t->status,
					'User'      => $t->user_id,
					'Assistant' => $t->assistant_id,
					'Model'     => $t->model_name ? $t->model_name : '-',
					'Messages'  => $t->message_count,
					'Updated'   => $t->updated_at,
				);
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of threads */
				__( 'Found %d threads.', 'mcp-ai-wpoos' ),
				count( $threads )
			)
		);
	}

	/**
	 * Get a single thread's details.
	 *
	 * ## OPTIONS
	 *
	 * <thread-id>
	 * : The thread ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai thread get 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) {
		$thread_id = absint( $args[0] ?? 0 );
		$format    = $assoc_args['format'] ?? 'table';

		if ( 0 === $thread_id ) {
			$this->error( __( 'Thread ID is required.', 'mcp-ai-wpoos' ) );
		}

		global $wpdb;
		$manager = $this->get_manager();
		$table   = $manager->get_threads_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $thread_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $thread ) {
			$this->error( __( 'Thread not found.', 'mcp-ai-wpoos' ) );
		}

		$items = array(
			array(
				'Field' => 'ID',
				'Value' => $thread->id,
			),
			array(
				'Field' => 'Title',
				'Value' => $thread->title,
			),
			array(
				'Field' => 'Status',
				'Value' => $thread->status,
			),
			array(
				'Field' => 'User ID',
				'Value' => $thread->user_id,
			),
			array(
				'Field' => 'Assistant ID',
				'Value' => $thread->assistant_id,
			),
			array(
				'Field' => 'Model',
				'Value' => $thread->model_name ? $thread->model_name : '-',
			),
			array(
				'Field' => 'Profile',
				'Value' => $thread->profile,
			),
			array(
				'Field' => 'Messages',
				'Value' => $thread->message_count,
			),
			array(
				'Field' => 'Created',
				'Value' => $thread->created_at,
			),
			array(
				'Field' => 'Updated',
				'Value' => $thread->updated_at,
			),
		);

		$this->format_output( $items, $format );
	}

	/**
	 * Delete a thread and all its messages.
	 *
	 * ## OPTIONS
	 *
	 * <thread-id>
	 * : The thread ID to delete.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai thread delete 42 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$thread_id = absint( $args[0] ?? 0 );

		if ( 0 === $thread_id ) {
			$this->error( __( 'Thread ID is required.', 'mcp-ai-wpoos' ) );
		}

		global $wpdb;
		$manager = $this->get_manager();
		$table   = $manager->get_threads_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $thread_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $thread ) {
			$this->error( __( 'Thread not found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->confirm(
			sprintf(
				/* translators: %1$d: thread ID, %2$s: thread title */
				__( 'Delete thread #%1$d "%2$s" and all messages?', 'mcp-ai-wpoos' ),
				$thread_id,
				$thread->title
			),
			$assoc_args
		) ) {
			$this->warning( __( 'Operation cancelled.', 'mcp-ai-wpoos' ) );
			return;
		}

		$msg_table  = $manager->get_messages_table();
		$ckpt_table = $manager->get_checkpoints_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $msg_table, array( 'thread_id' => $thread_id ), array( '%d' ) );
		$wpdb->delete( $ckpt_table, array( 'thread_id' => $thread_id ), array( '%d' ) );
		$wpdb->delete( $table, array( 'id' => $thread_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->success(
			sprintf(
				/* translators: %d: thread ID */
				__( 'Thread #%d deleted.', 'mcp-ai-wpoos' ),
				$thread_id
			)
		);
	}

	/**
	 * Compact (archive) a thread.
	 *
	 * ## OPTIONS
	 *
	 * <thread-id>
	 * : The thread ID to archive.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai thread compact 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function compact( $args, $assoc_args ) {
		$thread_id = absint( $args[0] ?? 0 );

		if ( 0 === $thread_id ) {
			$this->error( __( 'Thread ID is required.', 'mcp-ai-wpoos' ) );
		}

		global $wpdb;
		$manager = $this->get_manager();
		$table   = $manager->get_threads_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $thread_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $thread ) {
			$this->error( __( 'Thread not found.', 'mcp-ai-wpoos' ) );
		}

		if ( 'archived' === $thread->status ) {
			$this->warning( __( 'Thread is already archived.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( ! $this->confirm(
			sprintf(
				/* translators: %1$d: thread ID, %2$s: thread title */
				__( 'Archive thread #%1$d "%2$s"?', 'mcp-ai-wpoos' ),
				$thread_id,
				$thread->title
			),
			$assoc_args
		) ) {
			$this->warning( __( 'Operation cancelled.', 'mcp-ai-wpoos' ) );
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'status' => 'archived' ),
			array( 'id' => $thread_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->success(
			sprintf(
				/* translators: %d: thread ID */
				__( 'Thread #%d archived.', 'mcp-ai-wpoos' ),
				$thread_id
			)
		);
	}

	/**
	 * Get the thread manager instance.
	 *
	 * @return WP_MCP_AI_Thread_Manager
	 */
	private function get_manager() {
		if ( ! class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			$this->error( __( 'Thread manager is not available. Ensure the plugin is properly installed and database tables are created.', 'mcp-ai-wpoos' ) );
		}

		return new WP_MCP_AI_Thread_Manager();
	}
}

WP_CLI::add_command( 'mcp-ai thread', 'WP_MCP_AI_CLI_Thread_Command' );
