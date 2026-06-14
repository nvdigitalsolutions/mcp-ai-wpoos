<?php
/**
 * Thread Manager — Manages AI conversation threads.
 *
 * Provides CRUD operations for threads, messages, checkpoints, and diffs.
 * Threads are the core conversation unit in the NV oOS SPA.
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Thread_Manager
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Thread_Manager {

	/**
	 * Threads table name (without prefix).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const TABLE_THREADS = 'mcp_ai_threads';

	/**
	 * Messages table name (without prefix).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const TABLE_MESSAGES = 'mcp_ai_thread_messages';

	/**
	 * Checkpoints table name (without prefix).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const TABLE_CHECKPOINTS = 'mcp_ai_thread_checkpoints';

	/**
	 * Valid thread statuses.
	 *
	 * @since 1.7.0
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'active', 'archived' );

	/**
	 * Get the full threads table name.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_threads_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_THREADS;
	}

	/**
	 * Get the full messages table name.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_messages_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_MESSAGES;
	}

	/**
	 * Get the full checkpoints table name.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_checkpoints_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_CHECKPOINTS;
	}

	/**
	 * Create the database tables.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$threads_table    = $wpdb->prefix . self::TABLE_THREADS;
		$messages_table   = $wpdb->prefix . self::TABLE_MESSAGES;
		$checkpoints_table = $wpdb->prefix . self::TABLE_CHECKPOINTS;
		$charset_collate  = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$threads_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			assistant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			model_provider VARCHAR(50) NOT NULL DEFAULT '',
			model_name VARCHAR(100) NOT NULL DEFAULT '',
			profile VARCHAR(50) NOT NULL DEFAULT 'write',
			scope_type VARCHAR(50) NOT NULL DEFAULT 'General',
			scope_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_status (user_id, status),
			KEY assistant_id (assistant_id),
			KEY updated_at (updated_at)
		) {$charset_collate};

		CREATE TABLE {$messages_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			thread_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'user',
			content LONGTEXT NOT NULL,
			checkpoint_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY thread_id (thread_id),
			KEY checkpoint_id (checkpoint_id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$checkpoints_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			thread_id BIGINT(20) UNSIGNED NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			diff_data LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY thread_id (thread_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the database tables.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . self::TABLE_CHECKPOINTS,
			$wpdb->prefix . self::TABLE_MESSAGES,
			$wpdb->prefix . self::TABLE_THREADS,
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * List threads for a user.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $status   Thread status filter ('active', 'archived', 'all').
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Items per page.
	 * @return array{success: bool, data: array{threads: array, total: int}}|WP_Error
	 */
	public function list_threads( $user_id, $status = 'active', $page = 1, $per_page = 50 ) {
		global $wpdb;

		$threads_table = $this->get_threads_table();
		$user_id       = absint( $user_id );
		$page          = max( 1, absint( $page ) );
		$per_page      = max( 1, min( 100, absint( $per_page ) ) );
		$offset        = ( $page - 1 ) * $per_page;

		$where = $wpdb->prepare( 'WHERE user_id = %d', $user_id );

		if ( 'all' !== $status && in_array( $status, self::VALID_STATUSES, true ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$threads_table} {$where}"
		);

		$threads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, status, model_provider, model_name, profile, scope_type, scope_id, assistant_id, message_count, user_id, created_at, updated_at
				FROM {$threads_table}
				{$where}
				ORDER BY updated_at DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( null === $threads ) {
			$threads = array();
		}

		// Normalize model_name for the SPA.
		$threads = array_map( array( $this, 'format_thread' ), $threads );

		return array(
			'success' => true,
			'data'    => array(
				'threads' => $threads,
				'total'   => $total,
			),
		);
	}

	/**
	 * Get a single thread by ID.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array|null Thread data or null if not found.
	 */
	public function get_thread( $thread_id ) {
		global $wpdb;

		$threads_table = $this->get_threads_table();
		$thread_id     = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$thread = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, status, model_provider, model_name, profile, scope_type, scope_id, assistant_id, message_count, user_id, created_at, updated_at
				FROM {$threads_table}
				WHERE id = %d",
				$thread_id
			),
			ARRAY_A
		);

		return $thread ? $this->format_thread( $thread ) : null;
	}

	/**
	 * Create a new thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id      WordPress user ID.
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $model        Model info { provider, model }.
	 * @param string $profile      Profile name.
	 * @param array  $scope        Scope info { type, id }.
	 * @return array{success: bool, data: array}|WP_Error
	 */
	public function create_thread( $user_id, $assistant_id = 0, $model = array(), $profile = 'write', $scope = array() ) {
		global $wpdb;

		$threads_table  = $this->get_threads_table();
		$user_id        = absint( $user_id );
		$assistant_id   = absint( $assistant_id );
		$profile        = sanitize_text_field( $profile );
		$model_provider = isset( $model['provider'] ) ? sanitize_text_field( $model['provider'] ) : '';
		$model_name_val = isset( $model['model'] ) ? sanitize_text_field( $model['model'] ) : '';
		$scope_type     = isset( $scope['type'] ) ? sanitize_text_field( $scope['type'] ) : 'General';
		$scope_id       = isset( $scope['id'] ) ? absint( $scope['id'] ) : 0;

		// Generate a default title.
		$title = sprintf(
			/* translators: 1: profile name, 2: date */
			__( '%1$s — %2$s', 'mcp-ai-wpoos' ),
			ucfirst( $profile ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->insert(
			$threads_table,
			array(
				'user_id'        => $user_id,
				'assistant_id'   => $assistant_id,
				'title'          => $title,
				'status'         => 'active',
				'model_provider' => $model_provider,
				'model_name'     => $model_name_val,
				'profile'        => $profile,
				'scope_type'     => $scope_type,
				'scope_id'       => $scope_id,
				'message_count'  => 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_thread_create_failed',
				__( 'Failed to create thread.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$thread_id = $wpdb->insert_id;
		$thread    = $this->get_thread( $thread_id );

		return array(
			'success' => true,
			'data'    => $thread,
		);
	}

	/**
	 * Archive a thread (soft delete).
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array{success: bool}|WP_Error
	 */
	public function archive_thread( $thread_id ) {
		global $wpdb;

		$threads_table = $this->get_threads_table();
		$thread_id     = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update(
			$threads_table,
			array( 'status' => 'archived' ),
			array( 'id' => $thread_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_thread_archive_failed',
				__( 'Failed to archive thread.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Restore an archived thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array{success: bool}|WP_Error
	 */
	public function restore_thread( $thread_id ) {
		global $wpdb;

		$threads_table = $this->get_threads_table();
		$thread_id     = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update(
			$threads_table,
			array( 'status' => 'active' ),
			array( 'id' => $thread_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_thread_restore_failed',
				__( 'Failed to restore thread.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Summarize (compact) a thread — creates a new thread with summary, archives the old one.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array{success: bool, data: array{new_thread_id: int}}|WP_Error
	 */
	public function summarize_thread( $thread_id ) {
		global $wpdb;

		$thread_id = absint( $thread_id );
		$thread    = $this->get_thread( $thread_id );

		if ( ! $thread ) {
			return new WP_Error(
				'wp_mcp_ai_thread_not_found',
				__( 'Thread not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// Archive the old thread.
		$this->archive_thread( $thread_id );

		// Create a new thread as a continuation.
		$new_title = sprintf(
			/* translators: %s: original thread title */
			__( '%s (continued)', 'mcp-ai-wpoos' ),
			$thread['title']
		);

		$threads_table = $this->get_threads_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->insert(
			$threads_table,
			array(
				'user_id'        => $thread['user_id'] ?? get_current_user_id(),
				'assistant_id'   => $thread['assistant_id'] ?? 0,
				'title'          => $new_title,
				'status'         => 'active',
				'model_provider' => $thread['model_provider'] ?? '',
				'model_name'     => $thread['model_name'] ?? '',
				'profile'        => $thread['profile'] ?? 'write',
				'scope_type'     => $thread['scope_type'] ?? 'General',
				'scope_id'       => $thread['scope_id'] ?? 0,
				'message_count'  => 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_thread_summarize_failed',
				__( 'Failed to create summarized thread.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'new_thread_id' => (int) $wpdb->insert_id,
			),
		);
	}

	/**
	 * Add a message to a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $thread_id     Thread ID.
	 * @param string $role          Message role ('user', 'assistant', 'system').
	 * @param string $content       Message content.
	 * @param int    $checkpoint_id Optional checkpoint ID.
	 * @return array{success: bool, data: array{message_id: int}}|WP_Error
	 */
	public function add_message( $thread_id, $role, $content, $checkpoint_id = 0 ) {
		global $wpdb;

		$messages_table = $this->get_messages_table();
		$threads_table  = $this->get_threads_table();
		$thread_id      = absint( $thread_id );
		$checkpoint_id  = absint( $checkpoint_id );
		$role           = sanitize_text_field( $role );

		// Verify thread exists.
		$thread = $this->get_thread( $thread_id );
		if ( ! $thread ) {
			return new WP_Error(
				'wp_mcp_ai_thread_not_found',
				__( 'Thread not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->insert(
			$messages_table,
			array(
				'thread_id'     => $thread_id,
				'role'          => $role,
				'content'       => $content,
				'checkpoint_id' => $checkpoint_id,
			),
			array( '%d', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_message_add_failed',
				__( 'Failed to add message.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Update message count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$threads_table} SET message_count = message_count + 1 WHERE id = %d",
				$thread_id
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'message_id' => (int) $wpdb->insert_id,
			),
		);
	}

	/**
	 * Get messages for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $page      Page number (1-based).
	 * @param int $per_page  Items per page.
	 * @return array{success: bool, data: array{messages: array, total: int}}|WP_Error
	 */
	public function get_messages( $thread_id, $page = 1, $per_page = 100 ) {
		global $wpdb;

		$messages_table = $this->get_messages_table();
		$thread_id      = absint( $thread_id );
		$page           = max( 1, absint( $page ) );
		$per_page       = max( 1, min( 200, absint( $per_page ) ) );
		$offset         = ( $page - 1 ) * $per_page;

		// Verify thread exists.
		$thread = $this->get_thread( $thread_id );
		if ( ! $thread ) {
			return new WP_Error(
				'wp_mcp_ai_thread_not_found',
				__( 'Thread not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messages_table} WHERE thread_id = %d",
				$thread_id
			)
		);

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, content, checkpoint_id, created_at
				FROM {$messages_table}
				WHERE thread_id = %d
				ORDER BY created_at ASC
				LIMIT %d OFFSET %d",
				$thread_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( null === $messages ) {
			$messages = array();
		}

		return array(
			'success' => true,
			'data'    => array(
				'messages' => $messages,
				'total'    => $total,
			),
		);
	}

	/**
	 * Get thread context (recent messages) for AI model context.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $limit     Maximum number of recent messages to return.
	 * @return array Array of message objects with role and content.
	 */
	public function get_thread_context( $thread_id, $limit = 10 ) {
		global $wpdb;

		$messages_table = $this->get_messages_table();
		$thread_id      = absint( $thread_id );
		$limit          = absint( $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$messages_table}
				WHERE thread_id = %d
				ORDER BY created_at DESC
				LIMIT %d",
				$thread_id,
				$limit
			),
			ARRAY_A
		);

		if ( null === $messages ) {
			return array();
		}

		// Reverse to chronological order.
		return array_reverse( $messages );
	}

	/**
	 * Create a checkpoint for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $thread_id    Thread ID.
	 * @param string $label        Checkpoint label.
	 * @param array  $affected_ids Optional affected entity IDs.
	 * @return array{success: bool, data: array{checkpoint_id: int}}|WP_Error
	 */
	public function create_checkpoint( $thread_id, $label = '', $affected_ids = array() ) {
		global $wpdb;

		$checkpoints_table = $this->get_checkpoints_table();
		$thread_id         = absint( $thread_id );
		$label             = sanitize_text_field( $label );

		// Build diff data from affected IDs.
		$diff_data = wp_json_encode(
			array(
				'affected_ids' => $affected_ids,
				'created_at'   => current_time( 'mysql' ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->insert(
			$checkpoints_table,
			array(
				'thread_id' => $thread_id,
				'label'     => $label,
				'diff_data' => $diff_data,
			),
			array( '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_checkpoint_create_failed',
				__( 'Failed to create checkpoint.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'checkpoint_id' => (int) $wpdb->insert_id,
			),
		);
	}

	/**
	 * Get checkpoints for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array{success: bool, data: array{checkpoints: array}}|WP_Error
	 */
	public function get_checkpoints( $thread_id ) {
		global $wpdb;

		$checkpoints_table = $this->get_checkpoints_table();
		$thread_id         = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$checkpoints = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, thread_id, label, diff_data, created_at
				FROM {$checkpoints_table}
				WHERE thread_id = %d
				ORDER BY created_at DESC",
				$thread_id
			),
			ARRAY_A
		);

		if ( null === $checkpoints ) {
			$checkpoints = array();
		}

		// Decode diff_data for the response.
		$checkpoints = array_map(
			function ( $cp ) {
				$cp['diff_data'] = json_decode( $cp['diff_data'], true );
				return $cp;
			},
			$checkpoints
		);

		return array(
			'success' => true,
			'data'    => array(
				'checkpoints' => $checkpoints,
			),
		);
	}

	/**
	 * Get diff data for a checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id    Thread ID.
	 * @param int $checkpoint_id Checkpoint ID.
	 * @return array{success: bool, data: array{changes: array}}|WP_Error
	 */
	public function get_checkpoint_diff( $thread_id, $checkpoint_id ) {
		global $wpdb;

		$checkpoints_table = $this->get_checkpoints_table();
		$thread_id         = absint( $thread_id );
		$checkpoint_id     = absint( $checkpoint_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$checkpoint = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT diff_data FROM {$checkpoints_table} WHERE id = %d AND thread_id = %d",
				$checkpoint_id,
				$thread_id
			),
			ARRAY_A
		);

		if ( ! $checkpoint ) {
			return new WP_Error(
				'wp_mcp_ai_checkpoint_not_found',
				__( 'Checkpoint not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$diff_data = json_decode( $checkpoint['diff_data'], true );

		return array(
			'success' => true,
			'data'    => array(
				'changes' => $diff_data['affected_ids'] ?? array(),
			),
		);
	}

	/**
	 * Restore a checkpoint — deletes messages after the checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id     Thread ID.
	 * @param int $checkpoint_id Checkpoint ID.
	 * @return array{success: bool}|WP_Error
	 */
	public function restore_checkpoint( $thread_id, $checkpoint_id ) {
		global $wpdb;

		$messages_table  = $this->get_messages_table();
		$thread_id       = absint( $thread_id );
		$checkpoint_id   = absint( $checkpoint_id );

		// Delete messages created after the checkpoint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$messages_table} WHERE thread_id = %d AND checkpoint_id > %d",
				$thread_id,
				$checkpoint_id
			)
		);

		// Update message count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messages_table} WHERE thread_id = %d",
				$thread_id
			)
		);

		$threads_table = $this->get_threads_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$threads_table,
			array( 'message_count' => (int) $count ),
			array( 'id' => $thread_id ),
			array( '%d' ),
			array( '%d' )
		);

		return array( 'success' => true );
	}

	/**
	 * Format a thread row for the API response.
	 *
	 * @since 1.7.0
	 *
	 * @param array $row Raw database row.
	 * @return array Formatted thread.
	 */
	private function format_thread( $row ) {
		return array(
			'id'             => (int) $row['id'],
			'title'          => $row['title'],
			'status'         => $row['status'],
			'model_name'     => $row['model_name'] ?: 'Default',
			'model_provider' => $row['model_provider'] ?? '',
			'profile'        => $row['profile'] ?? 'write',
			'scope_type'     => $row['scope_type'] ?? 'General',
			'scope_id'       => (int) ( $row['scope_id'] ?? 0 ),
			'assistant_id'   => (int) ( $row['assistant_id'] ?? 0 ),
			'message_count'  => (int) ( $row['message_count'] ?? 0 ),
			'user_id'        => (int) ( $row['user_id'] ?? 0 ),
			'created_at'     => $row['created_at'] ?? '',
			'updated_at'     => $row['updated_at'] ?? '',
		);
	}
}
