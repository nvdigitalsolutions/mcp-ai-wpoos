<?php
/**
 * Job-Source adapter: HITL Approvals
 *
 * Bridges WP_MCP_AI_Approval_Queue into the cron-status Tasks Drawer by
 * implementing Interface_WP_MCP_AI_Cron_Status_Job_Source.
 *
 * Pending approval requests are stored as `mcp_ai_approval` CPT posts
 * (post_status = 'pending'). Each post represents one approval that is
 * waiting for a human operator to approve or deny.
 *
 * @package   WP_MCP_AI
 * @since     1.9.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HITL Approvals job-source adapter.
 *
 * @since 1.9.3
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class WP_MCP_AI_Job_Source_Hitl_Approvals implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * Maximum number of approval posts to query per request.
	 */
	const QUERY_LIMIT = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'hitl_approvals';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Queries pending `mcp_ai_approval` posts filtered to the requesting
	 * user (or all for admins), and returns normalized records keyed by
	 * approval post ID (as the job_id).
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			return array();
		}

		$user_id  = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
		$is_admin = user_can( $user_id, 'manage_options' );

		$query_args = array(
			'post_type'      => WP_MCP_AI_Approval_Queue::CPT,
			'post_status'    => 'pending',
			'posts_per_page' => self::QUERY_LIMIT,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Non-admins see only their own requests.
		if ( ! $is_admin ) {
			$query_args['meta_key']   = WP_MCP_AI_Approval_Queue::META_REQUESTER;
			$query_args['meta_value'] = $user_id;
		}

		// Optional assistant scope.
		if ( null !== $assistant_id ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => WP_MCP_AI_Approval_Queue::META_ASSISTANT,
					'value' => (string) $assistant_id,
				),
			);
			// If we already added a meta_key for user, wrap both in an AND clause.
			if ( ! $is_admin && isset( $query_args['meta_key'] ) ) {
				$query_args['meta_query'] = array(
					'relation' => 'AND',
					array(
						'key'   => WP_MCP_AI_Approval_Queue::META_REQUESTER,
						'value' => $user_id,
					),
					array(
						'key'   => WP_MCP_AI_Approval_Queue::META_ASSISTANT,
						'value' => (string) $assistant_id,
					),
				);
				unset( $query_args['meta_key'], $query_args['meta_value'] );
			}
		}

		$post_ids = get_posts( $query_args );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$records = array();

		foreach ( $post_ids as $post_id ) {
			$post_id      = (int) $post_id;
			$requester_id = (int) get_post_meta( $post_id, WP_MCP_AI_Approval_Queue::META_REQUESTER, true );
			$asst_id      = (string) get_post_meta( $post_id, WP_MCP_AI_Approval_Queue::META_ASSISTANT, true );
			$tool         = (string) get_post_meta( $post_id, WP_MCP_AI_Approval_Queue::META_TOOL, true );
			$expires_at   = (int) get_post_meta( $post_id, WP_MCP_AI_Approval_Queue::META_EXPIRES, true );
			$context_raw  = get_post_meta( $post_id, WP_MCP_AI_Approval_Queue::META_CONTEXT, true );
			$context      = $context_raw ? json_decode( $context_raw, true ) : array();
			$created_at   = is_array( $context ) && isset( $context['created_at'] ) ? (int) $context['created_at'] : (int) get_post_timestamp( $post_id );

			$job_id             = 'approval_' . $post_id;
			$records[ $job_id ] = array(
				'job_id'       => $job_id,
				'kind'         => 'hitl_approval',
				'status'       => 'pending',
				'created_by'   => $requester_id,
				'assistant_id' => $asst_id,
				'started_at'   => $created_at,
				'updated_at'   => $created_at,
				'eta'          => $expires_at > 0 ? $expires_at : null,
				'progress'     => null,
				'message'      => $tool ? sprintf(
					/* translators: %s: tool slug awaiting approval */
					__( 'Awaiting approval for %s', 'mcp-ai-wpoos' ),
					$tool
				) : __( 'Awaiting approval', 'mcp-ai-wpoos' ),
				'cancellable'  => false,
				'retryable'    => false,
				'source'       => 'hitl_approvals',
			);
		}

		return $records;
	}
}
