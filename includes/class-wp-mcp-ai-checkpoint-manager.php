<?php
/**
 * Checkpoint Manager — State snapshots for safe agentic editing.
 *
 * Creates WordPress entity state snapshots before each agentic turn.
 * Supports restoration to any previous checkpoint and diff computation.
 * Mirrors Zed's checkpoint system with per-edit state capture.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Checkpoint_Manager
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Checkpoint_Manager {

	/**
	 * WordPress database abstraction.
	 *
	 * @since 1.7.0
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Checkpoints table name (prefixed).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $table;

	/**
	 * Maximum checkpoints per thread.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $max_checkpoints;

	/**
	 * Checkpoint TTL in seconds (default: 24 hours).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $ttl;

	/**
	 * Tracked entity types for state capture.
	 *
	 * @since 1.7.0
	 * @var array
	 */
	private $tracked_entity_types = array( 'post', 'option', 'term', 'user', 'comment' );

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'mcp_ai_checkpoints';

		$this->max_checkpoints = apply_filters( 'wp_mcp_ai_max_checkpoints_per_thread', 50 );
		$this->ttl             = apply_filters( 'wp_mcp_ai_checkpoint_ttl_seconds', 86400 ); // 24 hours.

		$this->tracked_entity_types = apply_filters( 'wp_mcp_ai_checkpoint_entity_types', $this->tracked_entity_types );
	}

	// ──────────────────────────────────────────────
	// Checkpoint CRUD
	// ──────────────────────────────────────────────

	/**
	 * Create a checkpoint capturing entity state.
	 *
	 * Called before each agentic turn to snapshot the entities
	 * that may be modified during the turn.
	 *
	 * @since 1.7.0
	 *
	 * @param int   $thread_id     Thread ID.
	 * @param int   $message_id    Message ID before which checkpoint is taken (0 = manual).
	 * @param array $affected_ids  Array of {type, id} entities to snapshot.
	 * @param string $label        Optional human-readable label.
	 * @return array|WP_Error
	 */
	public function create_checkpoint( $thread_id, $message_id = 0, $affected_ids = array(), $label = '' ) {
		$thread_id  = absint( $thread_id );
		$message_id = absint( $message_id );
		$label      = sanitize_text_field( $label );

		if ( empty( $label ) ) {
			$label = sprintf(
				/* translators: %s: date/time of checkpoint */
				__( 'Checkpoint — %s', 'mcp-ai-wpoos' ),
				current_time( 'mysql' )
			);
		}

		// Enforce max checkpoints limit.
		$count = $this->count_checkpoints( $thread_id );
		if ( $count >= $this->max_checkpoints ) {
			// Prune the oldest checkpoint to make room.
			$this->prune_oldest( $thread_id );
		}

		// Capture entity states.
		$state = array();
		foreach ( $affected_ids as $entity ) {
			if ( ! isset( $entity['type'], $entity['id'] ) ) {
				continue;
			}
			$state[] = $this->capture_entity_state( $entity['type'], $entity['id'] );
		}

		$data = array(
			'thread_id'         => $thread_id,
			'message_id'        => $message_id > 0 ? $message_id : null,
			'label'             => $label,
			'state_snapshot'    => wp_json_encode( $state ),
			'affected_entities' => wp_json_encode( $affected_ids ),
			'created_at'        => current_time( 'mysql' ),
		);

		$inserted = $this->wpdb->insert(
			$this->table,
			$data,
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create checkpoint.', 'mcp-ai-wpoos' ) );
		}

		$checkpoint_id = (int) $this->wpdb->insert_id;

		/**
		 * Fires after a checkpoint is created.
		 *
		 * @since 1.7.0
		 *
		 * @param int   $thread_id     Thread ID.
		 * @param int   $checkpoint_id Checkpoint ID.
		 * @param array $affected_ids  Entities captured.
		 */
		do_action( 'wp_mcp_ai_checkpoint_created', $thread_id, $checkpoint_id, $affected_ids );

		return array(
			'success' => true,
			'message' => __( 'Checkpoint created.', 'mcp-ai-wpoos' ),
			'data'    => array(
				'id'               => $checkpoint_id,
				'thread_id'        => $thread_id,
				'label'            => $label,
				'affected_entities' => $affected_ids,
				'created_at'       => $data['created_at'],
			),
		);
	}

	/**
	 * Get a single checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param int $checkpoint_id Checkpoint ID.
	 * @return array|WP_Error
	 */
	public function get_checkpoint( $checkpoint_id ) {
		$checkpoint_id = absint( $checkpoint_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d", $checkpoint_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Checkpoint not found.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => $this->cast_row( $row ),
		);
	}

	/**
	 * List checkpoints for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return array
	 */
	public function list_checkpoints( $thread_id ) {
		$thread_id = absint( $thread_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->table}` WHERE thread_id = %d ORDER BY created_at DESC LIMIT %d",
				$thread_id,
				$this->max_checkpoints
			),
			ARRAY_A
		);

		$checkpoints = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$checkpoints[] = $this->cast_row( $row );
			}
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => array(
				'checkpoints' => $checkpoints,
				'thread_id'   => $thread_id,
			),
		);
	}

	// ──────────────────────────────────────────────
	// Restore
	// ──────────────────────────────────────────────

	/**
	 * Restore entity state to a previous checkpoint.
	 *
	 * This rewinds all captured entities to their state at the
	 * time the checkpoint was created. Checkpoints created AFTER
	 * the restored one are pruned.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id     Thread ID.
	 * @param int $checkpoint_id Checkpoint ID to restore to.
	 * @return array|WP_Error
	 */
	public function restore_checkpoint( $thread_id, $checkpoint_id ) {
		$thread_id     = absint( $thread_id );
		$checkpoint_id = absint( $checkpoint_id );

		$checkpoint = $this->get_checkpoint( $checkpoint_id );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$cp_data = $checkpoint['data'];
		if ( (int) $cp_data['thread_id'] !== $thread_id ) {
			return new WP_Error( 'mismatch', __( 'Checkpoint does not belong to this thread.', 'mcp-ai-wpoos' ) );
		}

		$state = json_decode( $cp_data['state_snapshot'], true );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'corrupt', __( 'Checkpoint state data is corrupt.', 'mcp-ai-wpoos' ) );
		}

		// Restore each entity.
		$restored = array();
		foreach ( $state as $entity ) {
			if ( ! isset( $entity['type'], $entity['id'] ) ) {
				continue;
			}
			$result = $this->restore_entity_state( $entity );
			if ( ! is_wp_error( $result ) ) {
				$restored[] = $entity['type'] . ':' . $entity['id'];
			}
		}

		// Prune checkpoints created after this one.
		$this->prune_checkpoints_after( $thread_id, $checkpoint_id );

		/**
		 * Fires after a checkpoint is restored.
		 *
		 * @since 1.7.0
		 *
		 * @param int   $thread_id     Thread ID.
		 * @param int   $checkpoint_id Restored checkpoint ID.
		 * @param array $restored      Array of restored entity references.
		 */
		do_action( 'wp_mcp_ai_checkpoint_restored', $thread_id, $checkpoint_id, $restored );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of restored entities */
				_n(
					'%d entity restored.',
					'%d entities restored.',
					count( $restored ),
					'mcp-ai-wpoos'
				),
				count( $restored )
			),
			'data'    => array(
				'thread_id'     => $thread_id,
				'checkpoint_id' => $checkpoint_id,
				'restored'      => $restored,
			),
		);
	}

	// ──────────────────────────────────────────────
	// Diff
	// ──────────────────────────────────────────────

	/**
	 * Compute the differences since a checkpoint.
	 *
	 * Compares the current entity state against the state captured
	 * at the checkpoint, returning before/after pairs.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id     Thread ID.
	 * @param int $checkpoint_id Checkpoint ID.
	 * @return array|WP_Error    Array of { type, id, before, after } diffs.
	 */
	public function diff( $thread_id, $checkpoint_id ) {
		$thread_id     = absint( $thread_id );
		$checkpoint_id = absint( $checkpoint_id );

		$checkpoint = $this->get_checkpoint( $checkpoint_id );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$cp_data  = $checkpoint['data'];
		$captured = json_decode( $cp_data['state_snapshot'], true );

		if ( ! is_array( $captured ) ) {
			return new WP_Error( 'corrupt', __( 'Checkpoint state data is corrupt.', 'mcp-ai-wpoos' ) );
		}

		$diffs = array();

		foreach ( $captured as $entity ) {
			if ( ! isset( $entity['type'], $entity['id'], $entity['state'] ) ) {
				continue;
			}

			$current_state = $this->get_current_entity_state( $entity['type'], $entity['id'] );

			// Only include if state actually changed.
			if ( $entity['state'] !== $current_state ) {
				$name = $this->get_entity_display_name( $entity['type'], $entity['id'] );
				$diffs[] = array(
					'type'   => $entity['type'],
					'id'     => $entity['id'],
					'name'   => $name,
					'before' => $entity['state'],
					'after'  => $current_state,
				);
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of changed entities */
				_n(
					'%d entity changed.',
					'%d entities changed.',
					count( $diffs ),
					'mcp-ai-wpoos'
				),
				count( $diffs )
			),
			'data'    => array(
				'thread_id'     => $thread_id,
				'checkpoint_id' => $checkpoint_id,
				'changes'       => $diffs,
			),
		);
	}

	// ──────────────────────────────────────────────
	// Entity State Capture
	// ──────────────────────────────────────────────

	/**
	 * Capture an entity's current state.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Entity type: post, option, term, user, comment.
	 * @param mixed  $id   Entity ID (int for posts/terms/users, string for options).
	 * @return array       { type, id, state } or empty array if not found.
	 */
	private function capture_entity_state( $type, $id ) {
		$type = sanitize_key( $type );
		$state = $this->get_current_entity_state( $type, $id );

		return array(
			'type'  => $type,
			'id'    => $id,
			'state' => $state,
		);
	}

	/**
	 * Get an entity's current state as a serializable value.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Entity type.
	 * @param mixed  $id   Entity ID.
	 * @return mixed       Serializable state or null.
	 */
	private function get_current_entity_state( $type, $id ) {
		switch ( $type ) {
			case 'post':
				$post = get_post( absint( $id ) );
				if ( ! $post ) {
					return null;
				}
				// Store key fields only — not the full post_content for large posts.
				return array(
					'post_title'     => $post->post_title,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_status'    => $post->post_status,
					'post_type'      => $post->post_type,
					'post_modified'  => $post->post_modified,
					'menu_order'     => $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				);

			case 'option':
				return get_option( sanitize_key( $id ), null );

			case 'term':
				$term = get_term( absint( $id ) );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				return array(
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $term->parent,
				);

			case 'user':
				$user = get_userdata( absint( $id ) );
				if ( ! $user ) {
					return null;
				}
				return array(
					'display_name' => $user->display_name,
					'user_email'   => $user->user_email,
					'user_url'     => $user->user_url,
					'role'         => $user->roles,
				);

			case 'comment':
				$comment = get_comment( absint( $id ) );
				if ( ! $comment ) {
					return null;
				}
				return array(
					'comment_content'  => $comment->comment_content,
					'comment_approved' => $comment->comment_approved,
				);

			default:
				/**
				 * Filter to capture state for custom entity types.
				 *
				 * @since 1.7.0
				 *
				 * @param mixed  $state Default state (null).
				 * @param string $type  Entity type.
				 * @param mixed  $id    Entity ID.
				 */
				return apply_filters( 'wp_mcp_ai_checkpoint_capture_entity_state', null, $type, $id );
		}
	}

	/**
	 * Restore an entity to a previously captured state.
	 *
	 * @since 1.7.0
	 *
	 * @param array $entity { type, id, state }.
	 * @return bool|WP_Error
	 */
	private function restore_entity_state( $entity ) {
		if ( ! isset( $entity['type'], $entity['id'], $entity['state'] ) ) {
			return new WP_Error( 'invalid_entity', __( 'Invalid entity data for restore.', 'mcp-ai-wpoos' ) );
		}

		if ( null === $entity['state'] ) {
			// Entity didn't exist at checkpoint time — delete it now.
			return $this->delete_entity( $entity['type'], $entity['id'] );
		}

		$type  = $entity['type'];
		$id    = $entity['id'];
		$state = $entity['state'];

		switch ( $type ) {
			case 'post':
				$post_data = array_merge( array( 'ID' => absint( $id ) ), $state );
				$result = wp_update_post( $post_data, true );
				return ! is_wp_error( $result );

			case 'option':
				if ( null === $state ) {
					return delete_option( sanitize_key( $id ) );
				}
				return update_option( sanitize_key( $id ), $state );

			case 'term':
				$state['name'] = isset( $state['name'] ) ? $state['name'] : '';
				return ! is_wp_error( wp_update_term( absint( $id ), '', $state ) );

			case 'user':
				$state['ID'] = absint( $id );
				$result = wp_update_user( $state );
				return ! is_wp_error( $result );

			case 'comment':
				$comment_data = array_merge( array( 'comment_ID' => absint( $id ) ), $state );
				$result = wp_update_comment( $comment_data );
				return ! is_wp_error( $result ) && 1 === $result;

			default:
				/**
				 * Action to restore state for custom entity types.
				 *
				 * @since 1.7.0
				 *
				 * @param string $type  Entity type.
				 * @param mixed  $id    Entity ID.
				 * @param mixed  $state Captured state.
				 */
				do_action( 'wp_mcp_ai_checkpoint_restore_entity', $type, $id, $state );
				return true;
		}
	}

	/**
	 * Delete an entity that didn't exist at checkpoint time.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Entity type.
	 * @param mixed  $id   Entity ID.
	 * @return bool
	 */
	private function delete_entity( $type, $id ) {
		switch ( $type ) {
			case 'post':
				return (bool) wp_delete_post( absint( $id ), true );
			case 'option':
				return delete_option( sanitize_key( $id ) );
			case 'term':
				return ! is_wp_error( wp_delete_term( absint( $id ), '' ) );
			case 'user':
				return (bool) wp_delete_user( absint( $id ) );
			case 'comment':
				return wp_delete_comment( absint( $id ), true );
			default:
				return true;
		}
	}

	/**
	 * Get a human-readable display name for an entity.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Entity type.
	 * @param mixed  $id   Entity ID.
	 * @return string
	 */
	private function get_entity_display_name( $type, $id ) {
		switch ( $type ) {
			case 'post':
				$title = get_the_title( absint( $id ) );
				return $title ? $title : sprintf( 'Post #%d', absint( $id ) );
			case 'option':
				return sanitize_key( $id );
			case 'term':
				$term = get_term( absint( $id ) );
				return $term && ! is_wp_error( $term ) ? $term->name : sprintf( 'Term #%d', absint( $id ) );
			case 'user':
				$user = get_userdata( absint( $id ) );
				return $user ? $user->display_name : sprintf( 'User #%d', absint( $id ) );
			case 'comment':
				return sprintf( 'Comment #%d', absint( $id ) );
			default:
				return sprintf( '%s:%s', $type, $id );
		}
	}

	// ──────────────────────────────────────────────
	// Pruning
	// ──────────────────────────────────────────────

	/**
	 * Count checkpoints for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return int
	 */
	private function count_checkpoints( $thread_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$this->table}` WHERE thread_id = %d", absint( $thread_id ) )
		);
	}

	/**
	 * Prune the oldest checkpoint for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id Thread ID.
	 * @return void
	 */
	private function prune_oldest( $thread_id ) {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$this->table}` WHERE thread_id = %d ORDER BY created_at ASC LIMIT 1",
				absint( $thread_id )
			)
		);
	}

	/**
	 * Delete checkpoints created after a specific checkpoint.
	 *
	 * Used during restore to clean up invalidated future state.
	 *
	 * @since 1.7.0
	 *
	 * @param int $thread_id     Thread ID.
	 * @param int $checkpoint_id Checkpoint ID to keep (delete newer).
	 * @return void
	 */
	private function prune_checkpoints_after( $thread_id, $checkpoint_id ) {
		// Get the timestamp of the checkpoint we're restoring to.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$created_at = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT created_at FROM `{$this->table}` WHERE id = %d",
				absint( $checkpoint_id )
			)
		);

		if ( ! $created_at ) {
			return;
		}

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$this->table}` WHERE thread_id = %d AND created_at > %s",
				absint( $thread_id ),
				$created_at
			)
		);
	}

	/**
	 * Delete expired checkpoints (called via WordPress cron).
	 *
	 * @since 1.7.0
	 * @return int Number of pruned checkpoints.
	 */
	public function prune_expired() {
		$expiry = gmdate( 'Y-m-d H:i:s', time() - $this->ttl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$this->table}` WHERE created_at < %s",
				$expiry
			)
		);
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Cast a database row to proper types.
	 *
	 * @since 1.7.0
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function cast_row( $row ) {
		return array(
			'id'                => (int) $row['id'],
			'thread_id'         => (int) $row['thread_id'],
			'message_id'        => $row['message_id'] ? (int) $row['message_id'] : null,
			'label'             => $row['label'],
			'state_snapshot'    => $row['state_snapshot'],
			'affected_entities' => $row['affected_entities'],
			'created_at'        => $row['created_at'],
		);
	}
}
