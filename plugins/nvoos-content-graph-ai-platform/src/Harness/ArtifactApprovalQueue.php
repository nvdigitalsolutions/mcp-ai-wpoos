<?php
/**
 * Artifact Approval Queue — human-in-the-loop gate for artifact promotions.
 *
 * Phase G of the artifact-evolution plan. Pending `promote` items (candidate
 * + holdout verification) and `rollback` items (drift reports) wait here for
 * a human decision. Approving executes the action through the Phase F deploy
 * class; rejecting records the decision without executing anything. The queue
 * is option-backed, bounded, and TTL-expiring.
 *
 * Defaults are safe:
 *   - Nothing is ever queued automatically (the evolver enqueues only when
 *     `wp_mcp_ai_artifact_queue_for_approval` returns true).
 *   - Approve/reject require `edit_post` capability on the assistant.
 *   - Items expire after `wp_mcp_ai_artifact_approval_ttl` (default 7 days).
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Approval Queue class.
 *
 * @since 1.9.0
 */
class ArtifactApprovalQueue {

	/**
	 * Option key for the queue.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const OPTION_KEY = 'wp_mcp_ai_artifact_approval_queue';

	/**
	 * Default TTL for a pending item in seconds (7 days).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_TTL_SECONDS = 604800;

	/**
	 * Maximum pending items per assistant.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_PENDING_PER_ASSISTANT = 20;

	/**
	 * Maximum total items retained (FIFO).
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_TOTAL_ITEMS = 500;

	/**
	 * Allowed item actions.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const ALLOWED_ACTIONS = array( 'promote', 'rollback' );

	/**
	 * Allowed item statuses.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const ALLOWED_STATUSES = array( 'pending', 'approved', 'rejected' );

	/**
	 * Enqueue a pending approval item.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $action       Item action (promote|rollback).
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @param mixed  $payload      Action payload: candidate (promote) or drift report (rollback).
	 * @param array  $options      Optional. `candidate_hash`, `verification`, `reason`, `requester_id`, `ttl`.
	 * @return string|WP_Error Item ID on success, WP_Error on failure.
	 */
	public static function enqueue( $assistant_id, $action, $artifact_type, $payload, array $options = array() ) {
		$assistant_id  = absint( $assistant_id );
		$action        = sanitize_key( (string) $action );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_invalid_assistant',
				__( 'A valid assistant ID is required to queue an approval.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_invalid_action',
				sprintf(
					/* translators: %s: invalid action */
					__( 'Queue action "%s" is not supported.', 'nvoos-content-graph-ai-platform' ),
					$action
				)
			);
		}

		if ( '' === $artifact_type ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_invalid_type',
				__( 'A queue item requires an artifact type.', 'nvoos-content-graph-ai-platform' )
			);
		}

		self::purge_expired();

		$queue = self::load();

		$pending_here = 0;
		foreach ( $queue as $item ) {
			if ( (int) $item['assistant_id'] === $assistant_id && 'pending' === $item['status'] ) {
				++$pending_here;
			}
		}
		if ( $pending_here >= self::MAX_PENDING_PER_ASSISTANT ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_per_assistant_cap',
				__( 'This assistant already has the maximum number of pending approvals.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$requester_id = isset( $options['requester_id'] ) ? absint( $options['requester_id'] ) : get_current_user_id();

		/**
		 * Filters the default TTL for pending queue items.
		 *
		 * @since 1.9.0
		 *
		 * @param int $ttl Seconds until expiry. Default 604800 (7 days).
		 */
		$default_ttl = (int) apply_filters( 'wp_mcp_ai_artifact_approval_ttl', self::DEFAULT_TTL_SECONDS );
		$ttl         = isset( $options['ttl'] ) ? max( 60, (int) $options['ttl'] ) : max( 60, $default_ttl );

		$item = array(
			'id'             => md5( wp_json_encode( array( $assistant_id, $action, $artifact_type, $payload ) ) . microtime() . wp_rand() ),
			'assistant_id'   => $assistant_id,
			'action'         => $action,
			'artifact_type'  => $artifact_type,
			'payload'        => $payload,
			'candidate_hash' => isset( $options['candidate_hash'] ) ? sanitize_key( (string) $options['candidate_hash'] ) : '',
			'verification'   => isset( $options['verification'] ) && is_array( $options['verification'] ) ? $options['verification'] : array(),
			'reason'         => isset( $options['reason'] ) ? sanitize_text_field( (string) $options['reason'] ) : '',
			'status'         => 'pending',
			'requester_id'   => $requester_id,
			'created_at'     => time(),
			'expires_at'     => time() + $ttl,
		);

		array_unshift( $queue, $item );
		$queue = array_slice( $queue, 0, self::MAX_TOTAL_ITEMS );

		self::save( $queue );

		/**
		 * Fires after a new artifact approval item is queued.
		 *
		 * @since 1.9.0
		 *
		 * @param string $item_id Item ID.
		 * @param array  $item    Queue item.
		 */
		do_action( 'wp_mcp_ai_artifact_approval_queued', $item['id'], $item );

		return $item['id'];
	}

	/**
	 * Approve a pending item and execute its action.
	 *
	 * @since 1.9.0
	 *
	 * @param string $item_id     Item ID.
	 * @param int    $approver_id Approving user ID.
	 * @param string $note        Optional decision note.
	 * @return array|WP_Error Result envelope, or WP_Error on failure.
	 */
	public static function approve( $item_id, $approver_id = 0, $note = '' ) {
		$item = self::get_item( $item_id );

		if ( null === $item ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_item_not_found',
				__( 'The approval item does not exist.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( 'pending' !== $item['status'] ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_already_decided',
				__( 'This approval item has already been decided.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $item['assistant_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_forbidden',
				__( 'You are not allowed to approve artifacts for this assistant.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		// Execute the action through the Phase F deploy class.
		if ( 'promote' === $item['action'] ) {
			if ( ! class_exists( __NAMESPACE__ . '\\ArtifactDeploy' ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_queue_no_deploy',
					__( 'The artifact deploy subsystem is not available.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$promote_options = array();
			if ( '' !== $item['candidate_hash'] ) {
				$promote_options['candidate_hash'] = $item['candidate_hash'];
			}
			if ( ! empty( $item['verification'] ) ) {
				$promote_options['verification'] = $item['verification'];
			}

			$result = ArtifactDeploy::promote(
				(int) $item['assistant_id'],
				$item['artifact_type'],
				$item['payload'],
				$promote_options
			);
		} else {
			if ( ! class_exists( __NAMESPACE__ . '\\ArtifactDeploy' ) ) {
				return new \WP_Error(
					'wp_mcp_ai_artifact_queue_no_deploy',
					__( 'The artifact deploy subsystem is not available.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$result = ArtifactDeploy::rollback( (int) $item['assistant_id'], $item['artifact_type'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_decided( $item_id, 'approved', $approver_id, $note );

		return array(
			'item_id' => $item_id,
			'decided' => 'approved',
			'result'  => $result,
		);
	}

	/**
	 * Reject a pending item without executing its action.
	 *
	 * @since 1.9.0
	 *
	 * @param string $item_id     Item ID.
	 * @param int    $approver_id Rejecting user ID.
	 * @param string $note        Optional decision note.
	 * @return array|WP_Error Result envelope, or WP_Error on failure.
	 */
	public static function reject( $item_id, $approver_id = 0, $note = '' ) {
		$item = self::get_item( $item_id );

		if ( null === $item ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_item_not_found',
				__( 'The approval item does not exist.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( 'pending' !== $item['status'] ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_already_decided',
				__( 'This approval item has already been decided.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $item['assistant_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_artifact_queue_forbidden',
				__( 'You are not allowed to reject artifacts for this assistant.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		self::mark_decided( $item_id, 'rejected', $approver_id, $note );

		return array(
			'item_id' => $item_id,
			'decided' => 'rejected',
		);
	}

	/**
	 * List queue items for an assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID (0 = all).
	 * @param string $status       Optional status filter (pending|approved|rejected).
	 * @param int    $limit        Maximum items (1–500).
	 * @return array<int,array> Newest-first items.
	 */
	public static function list_items( $assistant_id = 0, $status = '', $limit = 50 ) {
		$assistant_id = absint( $assistant_id );
		$status       = sanitize_key( (string) $status );
		$limit        = max( 1, min( self::MAX_TOTAL_ITEMS, (int) $limit ) );

		self::purge_expired();

		$out = array();
		foreach ( self::load() as $item ) {
			if ( $assistant_id > 0 && (int) $item['assistant_id'] !== $assistant_id ) {
				continue;
			}
			if ( '' !== $status && $item['status'] !== $status ) {
				continue;
			}
			$out[] = $item;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Get a single queue item by ID.
	 *
	 * @since 1.9.0
	 *
	 * @param string $item_id Item ID.
	 * @return array|null Item, or null when absent.
	 */
	public static function get_item( $item_id ) {
		$item_id = sanitize_key( (string) $item_id );

		foreach ( self::load() as $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $item_id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Remove expired pending items.
	 *
	 * @since 1.9.0
	 *
	 * @return int Number of items purged.
	 */
	public static function purge_expired() {
		$queue = self::load();
		$now   = time();
		$kept  = array();

		foreach ( $queue as $item ) {
			$expires = (int) ( isset( $item['expires_at'] ) ? $item['expires_at'] : 0 );
			if ( 'pending' === $item['status'] && $expires > 0 && $expires < $now ) {
				continue;
			}
			$kept[] = $item;
		}

		$purged = count( $queue ) - count( $kept );
		if ( $purged > 0 ) {
			self::save( $kept );
		}

		return $purged;
	}

	/**
	 * Drop the whole queue (tests / uninstall).
	 *
	 * @since 1.9.0
	 *
	 * @return bool True when the option was deleted.
	 */
	public static function clear() {
		return (bool) delete_option( self::OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Load the queue option.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int,array> Queue items.
	 */
	private static function load() {
		$queue = get_option( self::OPTION_KEY, array() );

		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * Save the queue option.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int,array> $queue Queue items.
	 * @return void
	 */
	private static function save( array $queue ) {
		update_option( self::OPTION_KEY, $queue, false );
	}

	/**
	 * Mark an item decided and fire the decision action.
	 *
	 * @since 1.9.0
	 *
	 * @param string $item_id     Item ID.
	 * @param string $status      New status (approved|rejected).
	 * @param int    $approver_id Deciding user ID.
	 * @param string $note        Decision note.
	 * @return void
	 */
	private static function mark_decided( $item_id, $status, $approver_id, $note ) {
		$queue = self::load();

		foreach ( $queue as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $item_id ) {
				$queue[ $index ]['status']        = $status;
				$queue[ $index ]['decided_by']    = absint( $approver_id );
				$queue[ $index ]['decided_at']    = time();
				$queue[ $index ]['decision_note'] = sanitize_text_field( (string) $note );
				break;
			}
		}

		self::save( $queue );

		/**
		 * Fires after an artifact approval item is decided.
		 *
		 * @since 1.9.0
		 *
		 * @param string $item_id Item ID.
		 * @param string $status  New status (approved|rejected).
		 */
		do_action( 'wp_mcp_ai_artifact_approval_decided', $item_id, $status );
	}
}
