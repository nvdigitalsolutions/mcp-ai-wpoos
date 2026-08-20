<?php
/**
 * WP-CLI command for managing user restrictions.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
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
 * List, lift, and add user restrictions from the command line.
 *
 * ## EXAMPLES
 *
 *     $ wp mcp-ai restrictions list
 *     $ wp mcp-ai restrictions list --type=rate_limit --format=json
 *     $ wp mcp-ai restrictions lift 42 --type=all
 *     $ wp mcp-ai restrictions add 42 --reason="Abuse" --expires-in=3600
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CLI_Restriction_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List active restrictions.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter by restriction type (rate_limit, token_overage, session_limit, manual).
	 *
	 * [--user=<id>]
	 * : Filter by user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai restrictions list
	 *     $ wp mcp-ai restrictions list --type=token_overage --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'table';

		$query = array(
			'per_page' => 100,
			'page'     => 1,
		);
		if ( isset( $assoc_args['type'] ) ) {
			$query['type'] = sanitize_key( $assoc_args['type'] );
		}
		if ( isset( $assoc_args['user'] ) ) {
			$query['user_id'] = absint( $assoc_args['user'] );
		}

		$data = WP_MCP_AI_Restriction_Registry::get_active( $query );

		if ( empty( $data['rows'] ) ) {
			$this->warning( __( 'No active restrictions.', 'mcp-ai-wpoos' ) );
			return;
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$released = ! empty( $row['released_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $row['released_at'] ) : __( 'Manual', 'mcp-ai-wpoos' );

			$rows[] = array(
				'User ID'   => $row['user_id'],
				'User'      => isset( $row['display_name'] ) ? $row['display_name'] : '-',
				'Type'      => isset( $row['type'] ) ? $row['type'] : '-',
				'Scope'     => isset( $row['scope'] ) ? $row['scope'] : '-',
				'Tool'      => isset( $row['tool_slug'] ) && '' !== $row['tool_slug'] ? $row['tool_slug'] : '-',
				'Triggered' => isset( $row['triggered_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $row['triggered_at'] ) : '-',
				'Releases'  => $released,
			);
		}

		$this->format_output( $rows, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of active restrictions */
				__( 'Found %d active restrictions.', 'mcp-ai-wpoos' ),
				count( $rows )
			)
		);
	}

	/**
	 * Lift one or all restrictions for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user-id>
	 * : The user ID to unblock.
	 *
	 * [--type=<type>]
	 * : Restriction type to lift. Default: all.
	 * ---
	 * default: all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai restrictions lift 42
	 *     $ wp mcp-ai restrictions lift 42 --type=token_overage
	 *
	 * @subcommand lift
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function lift( $args, $assoc_args ) {
		$user_id = absint( $args[0] ?? 0 );
		$type    = sanitize_key( $assoc_args['type'] ?? 'all' );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			$this->error( __( 'Invalid user ID.', 'mcp-ai-wpoos' ) );
		}

		$result = WP_MCP_AI_Restriction_Registry::lift( $user_id, $type, 0 );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: 1: user ID, 2: restriction type */
				__( 'Restriction (%2$s) lifted for user %1$d.', 'mcp-ai-wpoos' ),
				$user_id,
				$type
			)
		);
	}

	/**
	 * Add a manual restriction for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user-id>
	 * : The user ID to block.
	 *
	 * [--reason=<text>]
	 * : Reason for the block.
	 *
	 * [--expires-in=<seconds>]
	 * : Auto-release after N seconds (0 = until manually lifted).
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai restrictions add 42 --reason="Manual review"
	 *     $ wp mcp-ai restrictions add 42 --reason="Abuse" --expires-in=86400
	 *
	 * @subcommand add
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function add( $args, $assoc_args ) {
		$user_id    = absint( $args[0] ?? 0 );
		$reason     = sanitize_text_field( $assoc_args['reason'] ?? '' );
		$expires_in = absint( $assoc_args['expires_in'] ?? 0 );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			$this->error( __( 'Invalid user ID.', 'mcp-ai-wpoos' ) );
		}

		$details = array(
			'reason' => '' !== $reason ? $reason : __( 'Manually restricted via WP-CLI.', 'mcp-ai-wpoos' ),
		);
		if ( $expires_in > 0 ) {
			$details['released_at'] = time() + $expires_in;
		}

		$record = WP_MCP_AI_Restriction_Registry::add_manual( $user_id, $details, 0 );

		if ( is_wp_error( $record ) ) {
			$this->error( $record->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: %d: user ID */
				__( 'Manual restriction added for user %d.', 'mcp-ai-wpoos' ),
				$user_id
			)
		);
	}
}
