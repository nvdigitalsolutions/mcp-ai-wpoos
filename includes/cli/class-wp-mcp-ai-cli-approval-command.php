<?php
/**
 * WP-CLI command for managing approval queue items.
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
 * Manage the human-in-the-loop approval queue.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Approval_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List pending approval requests.
	 *
	 * ## OPTIONS
	 *
	 * [--assistant=<id>]
	 * : Filter by assistant post ID.
	 *
	 * [--limit=<number>]
	 * : Maximum items (default: 20).
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
	 *     $ wp mcp-ai approval list
	 *     $ wp mcp-ai approval list --assistant=42 --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$queue  = $this->get_queue();
		$format = $assoc_args['format'] ?? 'table';

		$query_args = array( 'limit' => min( 100, absint( $assoc_args['limit'] ?? 20 ) ) );
		if ( isset( $assoc_args['assistant'] ) ) {
			$query_args['assistant_id'] = absint( $assoc_args['assistant'] );
		}

		$items = $queue->get_pending( $query_args );

		if ( empty( $items ) ) {
			$this->warning( __( 'No pending approvals.', 'mcp-ai-wpoos' ) );
			return;
		}

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'ID'        => $item['id'] ?? $item['ID'] ?? 0,
				'Tool'      => $item['tool'] ?? $item['tool_slug'] ?? '-',
				'Assistant' => $item['assistant_id'] ?? $item['assistant'] ?? '-',
				'Requester' => $item['requester_id'] ?? $item['requester'] ?? '-',
				'Reason'    => function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $item['reason'] ?? '', 0, 60, '…' ) : ( strlen( (string) ( $item['reason'] ?? '' ) ) > 60 ? substr( (string) ( $item['reason'] ?? '' ), 0, 59 ) . '…' : (string) ( $item['reason'] ?? '' ) ),
				'Created'   => $item['created_at'] ?? $item['date'] ?? '-',
			);
		}

		$this->format_output( $rows, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of pending approvals */
				__( 'Found %d pending approvals.', 'mcp-ai-wpoos' ),
				count( $items )
			)
		);
	}

	/**
	 * Approve a pending approval request.
	 *
	 * ## OPTIONS
	 *
	 * <approval-id>
	 * : The approval post ID to approve.
	 *
	 * [--note=<text>]
	 * : Optional approver note.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai approval approve 123
	 *     $ wp mcp-ai approval approve 123 --note="Looks good, proceed."
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function approve( $args, $assoc_args ) {
		$approval_id = absint( $args[0] ?? 0 );
		$note        = sanitize_textarea_field( (string) ( $assoc_args['note'] ?? '' ) );

		if ( 0 === $approval_id ) {
			$this->error( __( 'Approval ID is required.', 'mcp-ai-wpoos' ) );
		}

		$queue  = $this->get_queue();
		$result = $queue->approve( $approval_id, get_current_user_id(), $note );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: %d: approval ID */
				__( 'Approval #%d approved.', 'mcp-ai-wpoos' ),
				$approval_id
			)
		);
	}

	/**
	 * Reject (deny) a pending approval request.
	 *
	 * ## OPTIONS
	 *
	 * <approval-id>
	 * : The approval post ID to reject.
	 *
	 * [--note=<text>]
	 * : Reason for rejection.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai approval reject 123 --note="Not authorized for this operation."
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reject( $args, $assoc_args ) {
		$approval_id = absint( $args[0] ?? 0 );
		$note        = sanitize_textarea_field( (string) ( $assoc_args['note'] ?? '' ) );

		if ( 0 === $approval_id ) {
			$this->error( __( 'Approval ID is required.', 'mcp-ai-wpoos' ) );
		}

		$queue  = $this->get_queue();
		$result = $queue->deny( $approval_id, get_current_user_id(), $note );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: %d: approval ID */
				__( 'Approval #%d rejected.', 'mcp-ai-wpoos' ),
				$approval_id
			)
		);
	}

	/**
	 * Get the approval queue instance.
	 *
	 * @return WP_MCP_AI_Approval_Queue
	 */
	private function get_queue() {
		if ( ! class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			$this->error( __( 'Approval queue is not available.', 'mcp-ai-wpoos' ) );
		}

		return WP_MCP_AI_Approval_Queue::get_instance();
	}
}

WP_CLI::add_command( 'mcp-ai approval', 'WP_MCP_AI_CLI_Approval_Command' );
