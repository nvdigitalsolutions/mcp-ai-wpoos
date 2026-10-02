<?php
/**
 * WP-CLI Password Vault commands for NV oOS Pro.
 *
 * SECURITY NOTE: This command is strictly metadata-only. It never reads,
 * decrypts, or renders secret material — no passwords, usernames, TOTP
 * secrets, notes, card/identity data, custom field values, or URIs are ever
 * fetched or printed. Only item metadata (id, name, type, folder, favorite,
 * timestamps) is exposed. There are no write verbs, so secrets can never be
 * created or updated through the shell (avoiding shell-history leaks), and
 * every render path uses an explicit metadata allowlist.
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
 * List and inspect NV oOS Pro Password Vault items (mcp_vault_item CPT) from
 * the command line.
 *
 * Read-only by design: the encrypted payload meta
 * (_vault_encrypted_data, _vault_*_encrypted, _vault_custom_fields,
 * _vault_uris, _bitwarden_item_id) is never read or output. Only the
 * metadata keys _vault_item_type, _vault_folder_id, and _vault_favorite are
 * queried, plus the post title and timestamps.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Vault_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Vault item post type.
	 *
	 * @var string
	 */
	const ITEM_POST_TYPE = 'mcp_vault_item';

	/**
	 * Vault folder post type.
	 *
	 * @var string
	 */
	const FOLDER_POST_TYPE = 'mcp_vault_folder';

	/**
	 * Output formats supported by this command.
	 *
	 * @var string[]
	 */
	const ALLOWED_FORMATS = array( 'table', 'json', 'yaml', 'csv' );

	/**
	 * List vault items (metadata only — never secret material).
	 *
	 * ## OPTIONS
	 *
	 * [--folder=<name>]
	 * : Only list items inside the folder with this name.
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
	 * [--fields=<fields>]
	 * : Limit the output to specific columns (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all vault items (metadata only).
	 *     $ wp mcp-ai vault list
	 *
	 *     # List items inside the "Finance" folder.
	 *     $ wp mcp-ai vault list --folder=Finance
	 *
	 *     # Export metadata as JSON.
	 *     $ wp mcp-ai vault list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$format = $this->get_format( $assoc_args );
		if ( ! in_array( $format, self::ALLOWED_FORMATS, true ) ) {
			$format = 'table';
		}

		$folder_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'folder', '' );
		$folder_filter = '' !== $folder_filter ? sanitize_text_field( (string) $folder_filter ) : '';

		$folder_names = $this->get_folder_map();

		$posts = get_posts(
			array(
				'post_type'        => self::ITEM_POST_TYPE,
				'posts_per_page'   => -1,
				'post_status'      => 'any',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$folder_id = (int) get_post_meta( $post->ID, '_vault_folder_id', true );
			$folder    = isset( $folder_names[ $folder_id ] ) ? $folder_names[ $folder_id ] : '';

			if ( '' !== $folder_filter && $folder_filter !== $folder ) {
				continue;
			}

			$items[] = array(
				'id'      => $post->ID,
				'name'    => $post->post_title,
				'type'    => (string) get_post_meta( $post->ID, '_vault_item_type', true ),
				'folder'  => $folder,
				'created' => $post->post_date,
				'updated' => $post->post_modified,
			);
		}

		if ( empty( $items ) ) {
			if ( '' !== $folder_filter ) {
				/* translators: %s: folder name */
				WP_CLI::warning( sprintf( __( 'No vault items found in folder "%s".', 'mcp-ai-wpoos-pro' ), $folder_filter ) );
			} else {
				WP_CLI::log( __( 'No vault items found.', 'mcp-ai-wpoos-pro' ) );
			}
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'type', 'folder', 'created', 'updated' ) );
	}

	/**
	 * Get metadata for a single vault item (never secret material).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The vault item post ID.
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
	 *     # Show metadata for vault item 42.
	 *     $ wp mcp-ai vault get 42
	 *
	 *     # Export metadata as JSON.
	 *     $ wp mcp-ai vault get 42 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$format = $this->get_format( $assoc_args );
		if ( ! in_array( $format, self::ALLOWED_FORMATS, true ) ) {
			$format = 'table';
		}

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid vault item ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || self::ITEM_POST_TYPE !== $post->post_type ) {
			/* translators: %d: vault item ID */
			WP_CLI::error( sprintf( __( 'Vault item %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$folder_names = $this->get_folder_map();
		$folder_id    = (int) get_post_meta( $id, '_vault_folder_id', true );
		$favorite     = '1' === (string) get_post_meta( $id, '_vault_favorite', true );

		// Metadata-only allowlist. No _vault_*_encrypted, _vault_custom_fields,
		// _vault_uris, or _bitwarden_item_id values are ever read or rendered.
		$data = array(
			'id'       => $post->ID,
			'name'     => $post->post_title,
			'type'     => (string) get_post_meta( $id, '_vault_item_type', true ),
			'folder'   => isset( $folder_names[ $folder_id ] ) ? $folder_names[ $folder_id ] : '',
			'favorite' => $favorite ? __( 'yes', 'mcp-ai-wpoos-pro' ) : __( 'no', 'mcp-ai-wpoos-pro' ),
			'created'  => $post->post_date,
			'updated'  => $post->post_modified,
		);

		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}

	/**
	 * Build a folder ID => folder name map for metadata display.
	 *
	 * Folder posts are stored with the private status, so post_status must be
	 * 'any' for them to be found. Only the post ID and title are used.
	 *
	 * @return array<int,string> Folder ID => folder name.
	 */
	private function get_folder_map() {
		$map = array();

		$folders = get_posts(
			array(
				'post_type'        => self::FOLDER_POST_TYPE,
				'posts_per_page'   => -1,
				'post_status'      => 'any',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $folders as $folder ) {
			$map[ $folder->ID ] = $folder->post_title;
		}

		return $map;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai vault', 'WP_MCP_AI_Pro_CLI_Vault_Command' );
}
