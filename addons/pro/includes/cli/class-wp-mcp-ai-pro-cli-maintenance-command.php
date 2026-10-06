<?php
/**
 * WP-CLI maintenance window commands for NV oOS Pro.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Manage NV oOS Pro maintenance windows (mcp_ai_maintenance CPT) from the
 * command line.
 *
 * ## EXAMPLES
 *
 *     # List scheduled maintenance windows.
 *     $ wp mcp-ai maintenance list --status=scheduled
 *
 *     # Cancel a maintenance window without a confirmation prompt.
 *     $ wp mcp-ai maintenance cancel 42 --yes
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Maintenance_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all maintenance windows.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by maintenance window status.
	 * ---
	 * options:
	 *   - scheduled
	 *   - in_progress
	 *   - completed
	 *   - cancelled
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to display.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all maintenance windows.
	 *     $ wp mcp-ai maintenance list
	 *
	 *     # List only in-progress windows.
	 *     $ wp mcp-ai maintenance list --status=in_progress
	 *
	 *     # Export maintenance window IDs.
	 *     $ wp mcp-ai maintenance list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) );
		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields( $assoc_args, array( 'ID', 'title', 'status', 'start', 'end' ) );

		$query_args = array(
			'post_type'      => 'mcp_ai_maintenance',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $status ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_mcp_ai_maintenance_status',
					'value' => $status,
				),
			);
		}

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No maintenance windows found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'     => $post->ID,
				'title'  => $post->post_title,
				'status' => get_post_meta( $post->ID, '_mcp_ai_maintenance_status', true ),
				'start'  => get_post_meta( $post->ID, '_mcp_ai_maintenance_start', true ),
				'end'    => get_post_meta( $post->ID, '_mcp_ai_maintenance_end', true ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single maintenance window.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The maintenance window post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get maintenance window 42.
	 *     $ wp mcp-ai maintenance get 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid maintenance window ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_maintenance' !== $post->post_type ) {
			/* translators: %d: maintenance window ID */
			WP_CLI::error( sprintf( __( 'Maintenance window %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$data = array(
			'ID'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'created' => $post->post_date,
			'updated' => $post->post_modified,
		);

		// Append all _mcp_ai_maintenance_* meta.
		foreach ( get_post_meta( $id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, '_mcp_ai_maintenance_' ) ) {
				$data[ $meta_key ] = $values[0] ?? '';
			}
		}

		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Cancel a maintenance window.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The maintenance window post ID.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Cancel maintenance window 42 (prompts for confirmation).
	 *     $ wp mcp-ai maintenance cancel 42
	 *
	 *     # Cancel without a prompt.
	 *     $ wp mcp-ai maintenance cancel 42 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function cancel( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid maintenance window ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_maintenance' !== $post->post_type ) {
			/* translators: %d: maintenance window ID */
			WP_CLI::error( sprintf( __( 'Maintenance window %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		/* translators: 1: maintenance window title, 2: maintenance window ID */
		$this->confirm(
			sprintf( __( 'Cancel maintenance window "%1$s" (ID %2$d)?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ),
			$assoc_args
		);

		if ( class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
			$cancelled = WP_MCP_AI_Maintenance_CPT::transition_status(
				$id,
				WP_MCP_AI_Maintenance_CPT::STATUS_CANCELLED
			);
		} else {
			// Defensive fallback when the backing class is unavailable.
			update_post_meta( $id, '_mcp_ai_maintenance_status', 'cancelled' );
			$cancelled = true;
		}

		if ( ! $cancelled ) {
			WP_CLI::error( __( 'Cannot cancel this maintenance window in its current state.', 'mcp-ai-wpoos-pro' ) );
		}

		/* translators: 1: maintenance window title, 2: maintenance window ID */
		WP_CLI::success( sprintf( __( 'Cancelled maintenance window "%1$s" (ID %2$d).', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai maintenance', 'WP_MCP_AI_Pro_CLI_Maintenance_Command' );
}
