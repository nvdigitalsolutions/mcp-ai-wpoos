<?php
/**
 * WP-CLI PARA commands for NV oOS Pro.
 *
 * Read-only operator surface over the PARA area CPT (`mcp_ai_area`) and the
 * `WP_MCP_AI_PARA_Area_CPT::get_area()` presenter.
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
 * Inspect PARA areas from the command line.
 *
 * ## EXAMPLES
 *
 *     # List every PARA area.
 *     $ wp mcp-ai para list
 *
 *     # Show one area as JSON.
 *     $ wp mcp-ai para get 42 --format=json
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Para_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Assert the PARA subsystem is enabled, or exit.
	 *
	 * @return void
	 */
	private function assert_para_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) || ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			WP_CLI::error( __( 'The PARA subsystem is not enabled.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List PARA areas.
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
	 * : Comma-separated column list (ID, Title, Standard, Owner, Status, Updated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all areas.
	 *     $ wp mcp-ai para list
	 *
	 *     # Count areas.
	 *     $ wp mcp-ai para list --format=count
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();
		$this->assert_para_enabled();

		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields( $assoc_args, array( 'ID', 'Title', 'Standard', 'Owner', 'Status', 'Updated' ) );

		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_PARA_Area_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$owner_id = (int) get_post_meta( $post->ID, '_para_owner', true );
			$owner    = $owner_id ? get_userdata( $owner_id ) : null;

			$items[] = array(
				'ID'       => $post->ID,
				'Title'    => $post->post_title,
				'Standard' => (string) get_post_meta( $post->ID, '_para_standard', true ),
				'Owner'    => $owner ? $owner->display_name : '—',
				'Status'   => $post->post_status,
				'Updated'  => $post->post_modified_gmt,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No PARA areas found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Show one PARA area.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Area post ID.
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
	 *     # Show area 42.
	 *     $ wp mcp-ai para get 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_para_enabled();

		$id   = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$area = $id ? WP_MCP_AI_PARA_Area_CPT::get_area( $id ) : null;
		if ( ! $area ) {
			WP_CLI::error( __( 'Area not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->render_envelope( $area, $this->get_format( $assoc_args, 'table' ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai para', 'WP_MCP_AI_Pro_CLI_Para_Command' );
}
