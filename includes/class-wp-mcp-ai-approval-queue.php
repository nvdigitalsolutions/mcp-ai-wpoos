<?php
/**
 * Approval Queue — Human-in-the-Loop (HITL) pending-action store.
 *
 * When a tool or agentic step marks itself as `requires_approval`, the running
 * workflow pauses, a record is inserted into this queue, and a new SSE frame
 * type `approval_request` is emitted to the connected client. Execution
 * resumes only when an authorised user approves or denies the request via:
 *
 *   - The inline approval widget rendered by the chat UI, or
 *   - The **NV oOS → Approvals** admin queue page.
 *
 * ## Storage
 *
 * Approval records are stored as WordPress posts under the `mcp_ai_approval`
 * CPT. This keeps them durable, audit-friendly, and JetEngine-CCT-syncable
 * (like transcripts) without adding a custom DB table.
 *
 * ## Lifecycle
 *
 *   pending → approved | denied | expired
 *
 * Expired records are cleaned up by a weekly WP-Cron job.
 *
 * ## Non-breaking guarantee
 *
 * All existing tools default to `requires_approval = false`. The flag is
 * surfaced through the existing `WP_MCP_AI_Tool_Capability_Flags_Interface`
 * with a new `'requires-approval'` capability flag — old tools simply don't
 * return it, so they are unaffected.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HITL Approval Queue manager.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Approval_Queue {

	/**
	 * Custom post type for approval records.
	 */
	const CPT = 'mcp_ai_approval';

	/**
	 * Post meta key: serialized approval context.
	 */
	const META_CONTEXT = '_wp_mcp_ai_approval_context';

	/**
	 * Post meta key: tool slug awaiting approval.
	 */
	const META_TOOL = '_wp_mcp_ai_approval_tool';

	/**
	 * Post meta key: tool arguments (JSON-encoded).
	 */
	const META_ARGUMENTS = '_wp_mcp_ai_approval_arguments';

	/**
	 * Post meta key: session/chat correlation ID.
	 */
	const META_SESSION = '_wp_mcp_ai_approval_session';

	/**
	 * Post meta key: assistant ID.
	 */
	const META_ASSISTANT = '_wp_mcp_ai_approval_assistant_id';

	/**
	 * Post meta key: requesting user ID.
	 */
	const META_REQUESTER = '_wp_mcp_ai_approval_requester_id';

	/**
	 * Post meta key: expiry timestamp (Unix).
	 */
	const META_EXPIRES = '_wp_mcp_ai_approval_expires_at';

	/**
	 * WP-Cron hook for cleanup.
	 */
	const CRON_CLEANUP_HOOK = 'wp_mcp_ai_approval_cleanup';

	/**
	 * Default TTL for a pending approval (seconds). 24 hours.
	 */
	const DEFAULT_TTL_SECONDS = 86400;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	// ── CPT Registration ──────────────────────────────────────────────────────

	/**
	 * Register the `mcp_ai_approval` CPT.
	 *
	 * Called from the init hook by the bootstrap loader.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => __( 'Approval Requests', 'mcp-ai-wpoos' ),
				'labels'              => array(
					'name'          => __( 'Approval Requests', 'mcp-ai-wpoos' ),
					'singular_name' => __( 'Approval Request', 'mcp-ai-wpoos' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'custom-fields' ),
				'delete_with_user'    => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	// ── Queue operations ──────────────────────────────────────────────────────

	/**
	 * Insert a new pending approval request.
	 *
	 * @param array $data {
	 *   @type string $tool         Tool slug awaiting approval.
	 *   @type array  $arguments    Tool arguments.
	 *   @type int    $assistant_id Assistant post ID.
	 *   @type int    $requester_id WordPress user ID of the person who triggered this.
	 *   @type string $session_id   Chat session correlation ID.
	 *   @type string $reason       Human-readable reason for requesting approval.
	 *   @type int    $ttl          Seconds until this request expires (default: 86400).
	 * }
	 * @return int|WP_Error New approval post ID or WP_Error.
	 */
	public function enqueue( array $data ) {
		$tool         = sanitize_key( (string) ( $data['tool'] ?? '' ) );
		$arguments    = isset( $data['arguments'] ) && is_array( $data['arguments'] ) ? $data['arguments'] : array();
		$assistant_id = (int) ( $data['assistant_id'] ?? 0 );
		$requester_id = (int) ( $data['requester_id'] ?? get_current_user_id() );
		$session_id   = sanitize_text_field( (string) ( $data['session_id'] ?? '' ) );
		$reason       = sanitize_text_field( (string) ( $data['reason'] ?? '' ) );
		$ttl          = isset( $data['ttl'] ) ? max( 60, (int) $data['ttl'] ) : self::DEFAULT_TTL_SECONDS;

		if ( '' === $tool ) {
			return new WP_Error( 'approval_missing_tool', __( 'Tool slug is required.', 'mcp-ai-wpoos' ) );
		}

		$title = sprintf(
			/* translators: 1: tool slug, 2: date */
			__( 'Approval: %1$s (%2$s)', 'mcp-ai-wpoos' ),
			$tool,
			wp_date( 'Y-m-d H:i' )
		);

		$post_id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_title'  => $title,
			'post_status' => 'pending',
			'post_author' => $requester_id,
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_TOOL, $tool );
		update_post_meta( $post_id, self::META_ARGUMENTS, wp_json_encode( $arguments ) );
		update_post_meta( $post_id, self::META_ASSISTANT, $assistant_id );
		update_post_meta( $post_id, self::META_REQUESTER, $requester_id );
		update_post_meta( $post_id, self::META_SESSION, $session_id );
		update_post_meta( $post_id, self::META_EXPIRES, time() + $ttl );
		update_post_meta( $post_id, self::META_CONTEXT, wp_json_encode( array(
			'reason'        => $reason,
			'created_at'    => time(),
		) ) );

		/**
		 * Fires after a new approval request is queued.
		 *
		 * @param int   $post_id      Approval record post ID.
		 * @param array $data         Original data array.
		 */
		do_action( 'wp_mcp_ai_approval_queued', $post_id, $data );

		return $post_id;
	}

	/**
	 * Approve a pending request.
	 *
	 * @param int    $approval_id Approval post ID.
	 * @param int    $approver_id User ID approving the request.
	 * @param string $note        Optional approver note.
	 * @return bool|WP_Error
	 */
	public function approve( $approval_id, $approver_id = 0, $note = '' ) {
		return $this->transition( $approval_id, 'publish', $approver_id, $note );
	}

	/**
	 * Deny a pending request.
	 *
	 * @param int    $approval_id Approval post ID.
	 * @param int    $approver_id User ID denying the request.
	 * @param string $note        Reason for denial.
	 * @return bool|WP_Error
	 */
	public function deny( $approval_id, $approver_id = 0, $note = '' ) {
		return $this->transition( $approval_id, 'private', $approver_id, $note );
	}

	/**
	 * Transition an approval record to a new status.
	 *
	 * Statuses used: `pending` → `publish` (approved) | `private` (denied) | `trash` (expired).
	 *
	 * @param int    $approval_id Approval post ID.
	 * @param string $new_status  Target post status.
	 * @param int    $actor_id    User ID performing the action.
	 * @param string $note        Actor note.
	 * @return bool|WP_Error
	 */
	private function transition( $approval_id, $new_status, $actor_id, $note ) {
		$approval_id = (int) $approval_id;
		$post        = get_post( $approval_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return new WP_Error( 'approval_not_found', __( 'Approval record not found.', 'mcp-ai-wpoos' ) );
		}

		if ( 'pending' !== $post->post_status ) {
			return new WP_Error( 'approval_already_resolved', __( 'This approval request has already been resolved.', 'mcp-ai-wpoos' ) );
		}

		$actor_id = $actor_id > 0 ? (int) $actor_id : get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) && (int) get_current_user_id() !== $actor_id ) {
			return new WP_Error( 'approval_forbidden', __( 'You do not have permission to resolve this approval.', 'mcp-ai-wpoos' ) );
		}

		$result = wp_update_post( array(
			'ID'          => $approval_id,
			'post_status' => $new_status,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Store audit trail.
		update_post_meta( $approval_id, '_wp_mcp_ai_approval_resolved_by', $actor_id );
		update_post_meta( $approval_id, '_wp_mcp_ai_approval_resolved_at', time() );
		if ( '' !== $note ) {
			update_post_meta( $approval_id, '_wp_mcp_ai_approval_note', sanitize_textarea_field( $note ) );
		}

		$action_name = 'publish' === $new_status ? 'wp_mcp_ai_approval_approved' : 'wp_mcp_ai_approval_denied';

		/**
		 * Fires when an approval is approved or denied.
		 *
		 * @param int    $approval_id Approval post ID.
		 * @param int    $actor_id    User who resolved it.
		 * @param string $note        Actor note.
		 */
		do_action( $action_name, $approval_id, $actor_id, $note );

		return true;
	}

	/**
	 * Get a single approval record as an array.
	 *
	 * @param int $approval_id Approval post ID.
	 * @return array|null
	 */
	public function get( $approval_id ) {
		$approval_id = (int) $approval_id;
		$post        = get_post( $approval_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		return $this->post_to_array( $post );
	}

	/**
	 * Get pending approvals, optionally filtered.
	 *
	 * @param array $args {
	 *   @type int    $assistant_id  Filter by assistant.
	 *   @type int    $requester_id  Filter by requesting user.
	 *   @type string $session_id    Filter by chat session.
	 *   @type int    $limit         Max records (default 20).
	 * }
	 * @return array
	 */
	public function get_pending( array $args = array() ) {
		$query_args = array(
			'post_type'      => self::CPT,
			'post_status'    => 'pending',
			'posts_per_page' => min( 100, (int) ( $args['limit'] ?? 20 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();
		if ( ! empty( $args['assistant_id'] ) ) {
			$meta_query[] = array( 'key' => self::META_ASSISTANT, 'value' => (int) $args['assistant_id'] );
		}
		if ( ! empty( $args['requester_id'] ) ) {
			$meta_query[] = array( 'key' => self::META_REQUESTER, 'value' => (int) $args['requester_id'] );
		}
		if ( ! empty( $args['session_id'] ) ) {
			$meta_query[] = array( 'key' => self::META_SESSION, 'value' => sanitize_text_field( (string) $args['session_id'] ) );
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$posts = get_posts( $query_args );
		return array_map( array( $this, 'post_to_array' ), $posts );
	}

	/**
	 * Map a WP_Post to a public approval record array.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private function post_to_array( $post ) {
		$context   = json_decode( get_post_meta( $post->ID, self::META_CONTEXT, true ), true );
		$arguments = json_decode( get_post_meta( $post->ID, self::META_ARGUMENTS, true ), true );
		$status_map = array(
			'pending' => 'pending',
			'publish' => 'approved',
			'private' => 'denied',
			'trash'   => 'expired',
		);

		return array(
			'id'           => $post->ID,
			'status'       => $status_map[ $post->post_status ] ?? $post->post_status,
			'tool'         => (string) get_post_meta( $post->ID, self::META_TOOL, true ),
			'arguments'    => is_array( $arguments ) ? $arguments : array(),
			'assistant_id' => (int) get_post_meta( $post->ID, self::META_ASSISTANT, true ),
			'requester_id' => (int) get_post_meta( $post->ID, self::META_REQUESTER, true ),
			'session_id'   => (string) get_post_meta( $post->ID, self::META_SESSION, true ),
			'reason'       => is_array( $context ) ? ( (string) ( $context['reason'] ?? '' ) ) : '',
			'created_at'   => is_array( $context ) ? ( (int) ( $context['created_at'] ?? 0 ) ) : 0,
			'expires_at'   => (int) get_post_meta( $post->ID, self::META_EXPIRES, true ),
			'resolved_by'  => (int) get_post_meta( $post->ID, '_wp_mcp_ai_approval_resolved_by', true ),
			'resolved_at'  => (int) get_post_meta( $post->ID, '_wp_mcp_ai_approval_resolved_at', true ),
			'note'         => (string) get_post_meta( $post->ID, '_wp_mcp_ai_approval_note', true ),
		);
	}

	// ── Cleanup cron ──────────────────────────────────────────────────────────

	/**
	 * Register the weekly cleanup cron.
	 */
	public static function register_cron() {
		add_action( self::CRON_CLEANUP_HOOK, array( 'WP_MCP_AI_Approval_Queue', 'run_cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_CLEANUP_HOOK );
		}
	}

	/**
	 * Delete expired approval records.
	 */
	public static function run_cleanup() {
		global $wpdb;

		// Find post IDs with expired timestamp in meta.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s
			   AND CAST(meta_value AS UNSIGNED) < %d
			 LIMIT 200",
			self::META_EXPIRES,
			time()
		) );

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post && self::CPT === $post->post_type && 'pending' === $post->post_status ) {
				wp_trash_post( $id );
			}
		}

		/**
		 * Fires after expired approvals are cleaned up.
		 *
		 * @param int[] $ids Post IDs that were trashed.
		 */
		do_action( 'wp_mcp_ai_approvals_cleanup_done', $ids );
	}
}
