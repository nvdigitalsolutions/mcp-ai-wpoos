<?php
/**
 * WP-CLI QMS commands for NV oOS Pro.
 *
 * Read-only operator surface over the Quality Management System: the
 * feature gate (`WP_MCP_AI_QMS_Capabilities::is_enabled()`) and the
 * controlled-document records CPT (`mcp_ai_doc_record`).
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
 * Inspect the Quality Management System from the command line.
 *
 * ## EXAMPLES
 *
 *     # QMS status.
 *     $ wp mcp-ai qms status
 *
 *     # List controlled documents.
 *     $ wp mcp-ai qms list
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Qms_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Whether the QMS subsystem is enabled.
	 *
	 * @return bool
	 */
	private function qms_enabled() {
		return class_exists( 'WP_MCP_AI_QMS_Capabilities' ) && WP_MCP_AI_QMS_Capabilities::is_enabled();
	}

	/**
	 * Show QMS status.
	 *
	 * ## OPTIONS
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # QMS status.
	 *     $ wp mcp-ai qms status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();

		$count = 0;
		if ( post_type_exists( 'mcp_ai_doc_record' ) ) {
			$counts = wp_count_posts( 'mcp_ai_doc_record' );
			$count  = array_sum( array_map( 'absint', (array) $counts ) );
		}

		$this->render_envelope(
			array(
				'QMS Enabled' => $this->qms_enabled(),
				'Records CPT' => post_type_exists( 'mcp_ai_doc_record' ) ? 'mcp_ai_doc_record' : 'absent',
				'Doc Records' => $count,
			),
			$this->get_format( $assoc_args, 'table' )
		);
	}

	/**
	 * List controlled-document records.
	 *
	 * ## OPTIONS
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
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column list (ID, Title, Status, Updated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all controlled documents.
	 *     $ wp mcp-ai qms list
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai qms list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();

		if ( ! $this->qms_enabled() ) {
			WP_CLI::error( __( 'The QMS subsystem is not enabled (requires the Document Generation toolkit and the enable_qms_compliance setting).', 'mcp-ai-wpoos-pro' ) );
		}

		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields( $assoc_args, array( 'ID', 'Title', 'Status', 'Updated' ) );

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_doc_record',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'      => $post->ID,
				'Title'   => $post->post_title,
				'Status'  => $post->post_status,
				'Updated' => $post->post_modified_gmt,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No controlled-document records found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai qms', 'WP_MCP_AI_Pro_CLI_Qms_Command' );
}
