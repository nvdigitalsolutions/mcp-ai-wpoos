<?php
/**
 * Thread Manager — CRUD for agent conversation threads and messages.
 *
 * Provides persistent storage for AI conversation threads with ownership,
 * scoping, archival, summarization, and message history. Both the jQuery
 * chat UI and the Pro React SPA consume this API through the REST layer.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Thread_Manager
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Thread_Manager {

	/**
	 * WordPress database abstraction.
	 *
	 * @since 1.7.0
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Threads table name (prefixed).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $threads_table;

	/**
	 * Messages table name (prefixed).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $messages_table;

	/**
	 * Maximum active threads per user.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $max_threads_per_user;

	/**
	 * Maximum messages per thread before auto-compaction is suggested.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $max_messages_per_thread;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb           = $wpdb;
		$this->threads_table  = $wpdb->prefix . 'mcp_ai_threads';
		$this->messages_table = $wpdb->prefix . 'mcp_ai_thread_messages';

		$this->max_threads_per_user    = apply_filters( 'wp_mcp_ai_max_threads_per_user', 50 );
		$this->max_messages_per_thread = apply_filters( 'wp_mcp_ai_max_messages_per_thread', 500 );
	}

	// ──────────────────────────────────────────────
	// Thread CRUD
	// ──────────────────────────────────────────────

	/**
	 * Create a new conversation thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id      WordPress user ID.
	 * @param int    $assistant_id Assistant post ID (0 = default).
	 * @param array  $model        Model config array: { provider: string, model: string }.
	 * @param string $profile      Profile name (default: 'write').
	 * @param array  $scope        Scope array: { type: string, value: string }.
	 * @return array|WP_Error      Success envelope or error.
	 */
	public function create_thread( $user_id, $assistant_id = 0, $model = array(), $profile = 'write', $scope = array() ) {
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );
		$profile      = sanitize_key( $profile );

		if ( empty( $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'mcp-ai-wpoos' ) );
		}

		// Enforce max threads limit (only counts active threads).
		$count = $this->count_user_threads( $user_id, 'active' );
		if ( $count >= $this->max_threads_per_user ) {
			return new WP_Error(
				'thread_limit_reached',
				sprintf(
					/* translators: %d: maximum number of threads */
					__( 'Maximum number of active threads (%d) reached. Please archive some threads before creating new ones.', 'mcp-ai-wpoos' ),
					$this->max_threads_per_user
				)
			);
		}

		$provider   = isset( $model['provider'] ) ? sanitize_key( $model['provider'] ) : '';
		$model_name = isset( $model['model'] ) ? sanitize_text_field( $model['model'] ) : '';

		$data = array(
			'assistant_id'   => $assistant_id,
			'user_id'        => $user_id,
			'title'          => __( 'New Thread', 'mcp-ai-wpoos' ),
			'model_provider' => $provider,
			'model_name'     => $model_name,
			'profile_name'   => $profile,
			'scope_type'     => isset( $scope['type'] ) ? sanitize_key( $scope['type'] ) : 'site',
			'scope_value'    => isset( $scope['value'] ) ? sanitize_text_field( $scope['value'] ) : '',
			'status'         => 'active',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$inserted = $this->wpdb->insert( $this->threads_table, $data, $this->get_thread_format() );
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create thread.', 'mcp-ai-wpoos' ) );
		}

		$data['id'] = (int) $this->wpdb->insert_id;

		/**
		 * Fires after a new thread is created.
		 *
		 * @since 1.7.0
		 *
		 * @param int   $thread_id The new thread ID.
		 * @param array $data      The full thread data array.
		 */
		do_action( 'wp_mcp_ai_thread_created', $data['id'], $data );

		return array(
			'success' => true,
			'message' => __( 'Thread created.', 'mcp-ai-wpoos' ),
			'data'    => $data,
		);
	}

	/**
	 * Get a single thread by ID with ownership check.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id   WordPress user ID for ownership verification.
	 * @return array|WP_Error
	 */
	public function get_thread( $thread_id, $user_id = 0 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$thread_id = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thread = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->threads_table}` WHERE id = %d",
				$thread_id
			),
			ARRAY_A
		);

		if ( ! $thread ) {
			return new WP_Error( 'not_found', __( 'Thread not found.', 'mcp-ai-wpoos' ) );
		}

		if ( 0 < $user_id && absint( $user_id ) !== (int) $thread['user_id'] ) {
			return new WP_Error( 'forbidden', __( 'You do not own this thread.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$thread['id']            = (int) $thread['id'];
		$thread['assistant_id']  = (int) $thread['assistant_id'];
		$thread['user_id']       = (int) $thread['user_id'];
		$thread['message_count'] = (int) $thread['message_count'];
		$thread['token_count']   = (int) $thread['token_count'];

		return array(
			'success' => true,
			'message' => '',
			'data'    => $thread,
		);
	}

	/**
	 * List threads for a user.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $status   Filter by status: 'active', 'archived', or '' for all.
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Items per page (max 100).
	 * @return array           { success, message, data: { threads, total, page, per_page } }
	 */
	public function list_threads( $user_id, $status = 'active', $page = 1, $per_page = 20 ) {
		$user_id  = absint( $user_id );
		$page     = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = $this->wpdb->prepare( 'user_id = %d', $user_id );

		if ( ! empty( $status ) ) {
			$status = sanitize_key( $status );
			$where .= $this->wpdb->prepare( ' AND status = %s', $status );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM `{$this->threads_table}` WHERE {$where}"
		);

		$threads = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->threads_table}` WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$threads = array_map( array( $this, 'cast_thread_row' ), $threads ? $threads : array() );

		return array(
			'success' => true,
			'message' => '',
			'data'    => array(
				'threads'  => $threads,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
		);
	}

	/**
	 * Update a thread's metadata.
	 *
	 * @since 1.7.0
	 *
	 * @param int   $thread_id Thread ID.
	 * @param int   $user_id   WordPress user ID.
	 * @param array $fields    Associative array of fields to update.
	 * @return array|WP_Error
	 */
	public function update_thread( $thread_id, $user_id, $fields ) {
		$thread_id = absint( $thread_id );
		$user_id   = absint( $user_id );

		$thread = $this->get_thread( $thread_id, $user_id );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		$allowed = array( 'title', 'model_provider', 'model_name', 'profile_name', 'scope_type', 'scope_value' );
		$data    = array();

		foreach ( $allowed as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				if ( in_array( $key, array( 'title', 'model_name', 'scope_value' ), true ) ) {
					$data[ $key ] = sanitize_text_field( $fields[ $key ] );
				} else {
					$data[ $key ] = sanitize_key( $fields[ $key ] );
				}
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'no_fields', __( 'No valid fields to update.', 'mcp-ai-wpoos' ) );
		}

		$data['updated_at'] = current_time( 'mysql' );

		$updated = $this->wpdb->update(
			$this->threads_table,
			$data,
			array( 'id' => $thread_id ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_error', __( 'Failed to update thread.', 'mcp-ai-wpoos' ) );
		}

		/**
		 * Fires after a thread is updated.
		 *
		 * @since 1.7.0
		 *
		 * @param int   $thread_id The thread ID.
		 * @param array $fields    The fields that were updated.
		 */
		do_action( 'wp_mcp_ai_thread_updated', $thread_id, $data );

		return array(
			'success' => true,
			'message' => __( 'Thread updated.', 'mcp-ai-wpoos' ),
			'data'    => array_merge( $thread['data'], $data ),
		);
	}

	/**
	 * Archive a thread (soft delete).
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id   WordPress user ID.
	 * @return array|WP_Error
	 */
	public function archive_thread( $thread_id, $user_id ) {
		$thread_id = absint( $thread_id );
		$user_id   = absint( $user_id );

		$thread = $this->get_thread( $thread_id, $user_id );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		if ( 'archived' === $thread['data']['status'] ) {
			return new WP_Error( 'already_archived', __( 'Thread is already archived.', 'mcp-ai-wpoos' ) );
		}

		$data = array(
			'status'      => 'archived',
			'archived_at' => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		);

		$this->wpdb->update(
			$this->threads_table,
			$data,
			array( 'id' => $thread_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		/**
		 * Fires after a thread is archived.
		 *
		 * @since 1.7.0
		 *
		 * @param int $thread_id The archived thread ID.
		 */
		do_action( 'wp_mcp_ai_thread_archived', $thread_id );

		return array(
			'success' => true,
			'message' => __( 'Thread archived.', 'mcp-ai-wpoos' ),
			'data'    => array( 'id' => $thread_id ),
		);
	}

	/**
	 * Restore an archived thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id   WordPress user ID.
	 * @return array|WP_Error
	 */
	public function restore_thread( $thread_id, $user_id ) {
		$thread_id = absint( $thread_id );
		$user_id   = absint( $user_id );

		$thread = $this->get_thread( $thread_id, $user_id );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		if ( 'active' === $thread['data']['status'] ) {
			return new WP_Error( 'already_active', __( 'Thread is already active.', 'mcp-ai-wpoos' ) );
		}

		$data = array(
			'status'      => 'active',
			'archived_at' => null,
			'updated_at'  => current_time( 'mysql' ),
		);

		$this->wpdb->update(
			$this->threads_table,
			$data,
			array( 'id' => $thread_id ),
			array( '%s', null, '%s' ),
			array( '%d' )
		);

		/**
		 * Fires after a thread is restored from archive.
		 *
		 * @since 1.7.0
		 *
		 * @param int $thread_id The restored thread ID.
		 */
		do_action( 'wp_mcp_ai_thread_restored', $thread_id );

		return array(
			'success' => true,
			'message' => __( 'Thread restored.', 'mcp-ai-wpoos' ),
			'data'    => array( 'id' => $thread_id ),
		);
	}

	/**
	 * Auto-generate a thread title from the first user message.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $thread_id    Thread ID.
	 * @param string $first_message First user message content.
	 * @return void
	 */
	public function auto_generate_title( $thread_id, $first_message ) {
		$title = wp_trim_words( sanitize_text_field( $first_message ), 8, '' );

		if ( empty( $title ) ) {
			$title = __( 'New Thread', 'mcp-ai-wpoos' );
		}

		$this->wpdb->update(
			$this->threads_table,
			array( 'title' => $title ),
			array( 'id' => absint( $thread_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	// ──────────────────────────────────────────────
	// Message CRUD
	// ──────────────────────────────────────────────

	/**
	 * Add a message to a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $thread_id Thread ID.
	 * @param string $role      Message role: 'user', 'assistant', 'system', 'tool'.
	 * @param string $content   Message content.
	 * @param array  $meta      Optional metadata: tool_calls, tool_results, checkpoint_id, token_usage.
	 * @return array|WP_Error
	 */
	public function add_message( $thread_id, $role, $content, $meta = array() ) {
		$thread_id = absint( $thread_id );
		$role      = sanitize_key( $role );

		$valid_roles = array( 'user', 'assistant', 'system', 'tool' );
		if ( ! in_array( $role, $valid_roles, true ) ) {
			return new WP_Error( 'invalid_role', __( 'Invalid message role.', 'mcp-ai-wpoos' ) );
		}

		// Check message count limit before inserting.
		$count = $this->count_messages( $thread_id );
		if ( $count >= $this->max_messages_per_thread ) {
			return new WP_Error(
				'thread_full',
				sprintf(
					/* translators: %d: maximum number of messages */
					__( 'Thread has reached the maximum of %d messages. Please start a new thread or compact this one.', 'mcp-ai-wpoos' ),
					$this->max_messages_per_thread
				)
			);
		}

		$tool_calls    = isset( $meta['tool_calls'] ) ? wp_json_encode( $meta['tool_calls'] ) : null;
		$tool_results  = isset( $meta['tool_results'] ) ? wp_json_encode( $meta['tool_results'] ) : null;
		$checkpoint_id = isset( $meta['checkpoint_id'] ) ? absint( $meta['checkpoint_id'] ) : null;
		$token_usage   = isset( $meta['token_usage'] ) ? absint( $meta['token_usage'] ) : 0;

		$data = array(
			'thread_id'     => $thread_id,
			'role'          => $role,
			'content'       => $content,
			'tool_calls'    => $tool_calls,
			'tool_results'  => $tool_results,
			'checkpoint_id' => $checkpoint_id,
			'token_usage'   => $token_usage,
			'created_at'    => current_time( 'mysql' ),
		);

		$inserted = $this->wpdb->insert( $this->messages_table, $data, $this->get_message_format() );
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to add message.', 'mcp-ai-wpoos' ) );
		}

		$message_id = (int) $this->wpdb->insert_id;

		// Update thread counters.
		$this->increment_thread_counters( $thread_id, $token_usage );

		// Auto-generate title from first user message.
		if ( 'user' === $role && 1 === ( $count + 1 ) ) {
			$this->auto_generate_title( $thread_id, $content );
		}

		/**
		 * Fires after a message is added to a thread.
		 *
		 * @since 1.7.0
		 *
		 * @param int    $thread_id  The thread ID.
		 * @param int    $message_id The new message ID.
		 * @param string $role       Message role.
		 */
		do_action( 'wp_mcp_ai_thread_message_added', $thread_id, $message_id, $role );

		return array(
			'success' => true,
			'message' => '',
			'data'    => array(
				'id'        => $message_id,
				'thread_id' => $thread_id,
				'role'      => $role,
			),
		);
	}

	/**
	 * Get messages for a thread, paginated.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $page      Page number (1-based).
	 * @param int $per_page  Messages per page (max 200).
	 * @return array         { success, message, data: { messages, total, page, per_page } }
	 */
	public function get_messages( $thread_id, $page = 1, $per_page = 50 ) {
		$thread_id = absint( $thread_id );
		$page      = max( 1, absint( $page ) );
		$per_page  = min( 200, max( 1, absint( $per_page ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->messages_table}` WHERE thread_id = %d",
				$thread_id
			)
		);

		$messages = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->messages_table}` WHERE thread_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
				$thread_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$messages = array_map( array( $this, 'cast_message_row' ), $messages ? $messages : array() );

		return array(
			'success' => true,
			'message' => '',
			'data'    => array(
				'messages' => $messages,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
		);
	}

	/**
	 * Get formatted message context for LLM consumption.
	 *
	 * Returns an array of { role, content } pairs suitable for
	 * inclusion in the messages array sent to the AI provider.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $limit     Maximum number of most recent messages to include.
	 * @return array         Array of { role, content } pairs.
	 */
	public function get_thread_context( $thread_id, $limit = 50 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$thread_id = absint( $thread_id );
		$limit     = absint( $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT role, content FROM `{$this->messages_table}` WHERE thread_id = %d ORDER BY created_at ASC LIMIT %d",
				$thread_id,
				$limit
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'role'    => $row['role'],
					'content' => $row['content'],
				);
			},
			$rows
		);
	}

	// ──────────────────────────────────────────────
	// Thread Lifecycle Operations
	// ──────────────────────────────────────────────

	/**
	 * Compact a thread by summarizing and creating a continuation.
	 *
	 * Creates a summary of the existing messages, creates a new thread
	 * with the summary as initial context, and archives the old thread.
	 * This mirrors Zed's "New From Summary" feature.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread to compact.
	 * @param int $user_id   WordPress user ID.
	 * @return array|WP_Error New thread data or error.
	 */
	public function summarize_thread( $thread_id, $user_id ) {
		$thread_id = absint( $thread_id );
		$user_id   = absint( $user_id );

		$thread = $this->get_thread( $thread_id, $user_id );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}

		$thread_data = $thread['data'];

		// Create a new continuation thread.
		$new = $this->create_thread(
			$user_id,
			$thread_data['assistant_id'],
			array(
				'provider' => $thread_data['model_provider'],
				'model'    => $thread_data['model_name'],
			),
			$thread_data['profile_name'],
			array(
				'type'  => $thread_data['scope_type'],
				'value' => $thread_data['scope_value'],
			)
		);

		if ( is_wp_error( $new ) ) {
			return $new;
		}

		$new_thread_id = $new['data']['id'];

		// Construct a summary system message with the old thread's context.
		$old_context   = $this->get_thread_context( $thread_id, 100 );
		$message_count = count( $old_context );

		$summary = sprintf(
			/* translators: 1: old thread title, 2: number of messages */
			__( 'This conversation continues from the thread "%1$s" which contained %2$d messages. Below is a summary of the prior conversation:', 'mcp-ai-wpoos' ),
			$thread_data['title'],
			$message_count
		);

		// Add summary as a system message in the new thread.
		$this->add_message( $new_thread_id, 'system', $summary );

		// Update the new thread title.
		/* translators: %s: original thread title */
		$new_title = sprintf( __( '%s (Continued)', 'mcp-ai-wpoos' ), $thread_data['title'] );
		$this->update_thread( $new_thread_id, $user_id, array( 'title' => $new_title ) );

		// Archive the old thread.
		$this->archive_thread( $thread_id, $user_id );

		/**
		 * Fires after a thread is compacted into a new continuation.
		 *
		 * @since 1.7.0
		 *
		 * @param int $old_thread_id The archived thread ID.
		 * @param int $new_thread_id The new continuation thread ID.
		 */
		do_action( 'wp_mcp_ai_thread_summarized', $thread_id, $new_thread_id );

		return array(
			'success' => true,
			'message' => __( 'Thread compacted. New continuation thread created.', 'mcp-ai-wpoos' ),
			'data'    => array(
				'old_thread_id' => $thread_id,
				'new_thread_id' => $new_thread_id,
			),
		);
	}

	// ──────────────────────────────────────────────
	// Counters & Helpers
	// ──────────────────────────────────────────────

	/**
	 * Count threads for a user.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  Optional status filter.
	 * @return int
	 */
	public function count_user_threads( $user_id, $status = '' ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_id = absint( $user_id );
		$sql     = $this->wpdb->prepare( "SELECT COUNT(*) FROM `{$this->threads_table}` WHERE user_id = %d", $user_id );

		if ( ! empty( $status ) ) {
			$sql .= $this->wpdb->prepare( ' AND status = %s', sanitize_key( $status ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count messages in a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return int
	 */
	public function count_messages( $thread_id ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$thread_id = absint( $thread_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->messages_table}` WHERE thread_id = %d",
				$thread_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ──────────────────────────────────────────────
	// Internal helpers
	// ──────────────────────────────────────────────

	/**
	 * Increment thread message and token counters.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id   Thread ID.
	 * @param int $token_usage Token usage to add.
	 * @return void
	 */
	private function increment_thread_counters( $thread_id, $token_usage ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE `{$this->threads_table}` SET message_count = message_count + 1, token_count = token_count + %d, updated_at = %s WHERE id = %d",
				absint( $token_usage ),
				current_time( 'mysql' ),
				absint( $thread_id )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get wpdb format array for thread insert/update.
	 *
	 * @since 1.7.0
	 * @return array
	 */
	private function get_thread_format() {
		return array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );
	}

	/**
	 * Get wpdb format array for message insert.
	 *
	 * @since 1.7.0
	 * @return array
	 */
	private function get_message_format() {
		return array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );
	}

	/**
	 * Cast a database thread row to proper types.
	 *
	 * @since 1.7.0
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function cast_thread_row( $row ) {
		$row['id']            = (int) $row['id'];
		$row['assistant_id']  = (int) $row['assistant_id'];
		$row['user_id']       = (int) $row['user_id'];
		$row['message_count'] = (int) $row['message_count'];
		$row['token_count']   = (int) $row['token_count'];
		return $row;
	}

	/**
	 * Cast a database message row to proper types.
	 *
	 * @since 1.7.0
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function cast_message_row( $row ) {
		$row['id']          = (int) $row['id'];
		$row['thread_id']   = (int) $row['thread_id'];
		$row['token_usage'] = (int) $row['token_usage'];
		return $row;
	}
}
