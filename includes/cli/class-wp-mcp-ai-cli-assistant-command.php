<?php
/**
 * WP-CLI assistant management commands for NV oOS.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage NV oOS assistants from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_Assistant_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List all assistants.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by post status.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - publish
	 *   - draft
	 *   - trash
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
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
	 * ## EXAMPLES
	 *
	 *     # List all published assistants.
	 *     $ wp mcp-ai assistant list --status=publish
	 *
	 *     # Export the list as JSON.
	 *     $ wp mcp-ai assistant list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$status = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'any' );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$query_args = array(
			'post_type'      => 'mcp_ai_assistant',
			'posts_per_page' => -1,
			'post_status'    => sanitize_key( $status ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No assistants found.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $posts, 'ID' ) ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$model = get_post_meta( $post->ID, 'mcp_ai_model', true );
			$items[] = array(
				'ID'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'model'  => $model ? $model : '',
				'date'   => $post->post_date,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'title', 'status', 'model', 'date' ) );
	}

	/**
	 * Get details for a single assistant.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The assistant post ID.
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show table of details for assistant 42.
	 *     $ wp mcp-ai assistant get 42
	 *
	 *     # Get the raw JSON.
	 *     $ wp mcp-ai assistant get 42 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Assistant %d not found.', 'mcp-ai-wpoos' ), $id ) );
		}

		$all_meta = get_post_meta( $id );
		$meta     = array();
		foreach ( $all_meta as $key => $values ) {
			if ( 0 === strpos( $key, 'mcp_ai_' ) ) {
				$clean_key        = substr( $key, strlen( 'mcp_ai_' ) );
				$meta[ $clean_key ] = $values[0] ?? '';
			}
		}

		$data = array_merge(
			array(
				'ID'      => $post->ID,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'created' => $post->post_date,
				'updated' => $post->post_modified,
			),
			$meta
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				if ( is_scalar( $value ) ) {
					WP_CLI::line( "{$key}: {$value}" );
				} else {
					WP_CLI::line( "{$key}: " . wp_json_encode( $value ) );
				}
			}
			return;
		}

		// Default: table.
		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Create a new assistant.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Title for the new assistant.
	 *
	 * [--status=<status>]
	 * : Initial post status.
	 * ---
	 * default: draft
	 * options:
	 *   - draft
	 *   - publish
	 * ---
	 *
	 * [--model=<model>]
	 * : AI model to assign (e.g. gpt-4o, gemini-2.0-flash).
	 *
	 * [--system-prompt=<prompt>]
	 * : System / persona prompt text for the assistant.
	 *
	 * [--porcelain]
	 * : Output only the created assistant ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a draft assistant.
	 *     $ wp mcp-ai assistant create --title="Support Bot"
	 *
	 *     # Create and immediately publish with a specific model.
	 *     $ wp mcp-ai assistant create --title="Sales Bot" --status=publish --model=gpt-4o
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {
		$title         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', '' );
		$status        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'draft' );
		$model         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'model', '' );
		$system_prompt = \WP_CLI\Utils\get_flag_value( $assoc_args, 'system-prompt', '' );
		$porcelain     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		$title = sanitize_text_field( $title );

		if ( '' === $title ) {
			WP_CLI::error( __( 'Please provide a --title for the assistant.', 'mcp-ai-wpoos' ) );
		}

		$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

		$post_data = array(
			'post_type'    => 'mcp_ai_assistant',
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => '',
		);

		$id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		if ( $model ) {
			update_post_meta( $id, 'mcp_ai_model', sanitize_text_field( $model ) );
		}

		if ( $system_prompt ) {
			update_post_meta( $id, 'mcp_ai_system_prompt', sanitize_textarea_field( $system_prompt ) );
		}

		if ( $porcelain ) {
			WP_CLI::line( $id );
			return;
		}

		/* translators: 1: assistant title, 2: assistant post ID */
		WP_CLI::success( sprintf( __( 'Created assistant "%1$s" (ID: %2$d).', 'mcp-ai-wpoos' ), $title, $id ) );
	}

	/**
	 * Delete an assistant.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The assistant post ID.
	 *
	 * [--force]
	 * : Permanently delete without moving to trash.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Trash assistant 42.
	 *     $ wp mcp-ai assistant delete 42
	 *
	 *     # Permanently delete assistant 42 without prompting.
	 *     $ wp mcp-ai assistant delete 42 --force --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$id    = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$yes   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Assistant %d not found.', 'mcp-ai-wpoos' ), $id ) );
		}

		if ( ! $yes ) {
			$action = $force
				? /* translators: 1: assistant title, 2: assistant post ID */
				  sprintf( __( 'Permanently delete "%1$s" (ID %2$d)?', 'mcp-ai-wpoos' ), $post->post_title, $id )
				: /* translators: 1: assistant title, 2: assistant post ID */
				  sprintf( __( 'Trash assistant "%1$s" (ID %2$d)?', 'mcp-ai-wpoos' ), $post->post_title, $id );

			WP_CLI::confirm( $action );
		}

		$result = wp_delete_post( $id, (bool) $force );

		if ( ! $result ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Failed to delete assistant %d.', 'mcp-ai-wpoos' ), $id ) );
		}

		if ( $force ) {
			/* translators: 1: assistant title, 2: assistant post ID */
			WP_CLI::success( sprintf( __( 'Permanently deleted assistant "%1$s" (ID %2$d).', 'mcp-ai-wpoos' ), $post->post_title, $id ) );
		} else {
			/* translators: 1: assistant title, 2: assistant post ID */
			WP_CLI::success( sprintf( __( 'Moved assistant "%1$s" (ID %2$d) to trash.', 'mcp-ai-wpoos' ), $post->post_title, $id ) );
		}
	}

	/**
	 * Export an assistant's configuration as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The assistant post ID.
	 *
	 * [--file=<path>]
	 * : Write the export to a file instead of stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     # Print assistant config to stdout.
	 *     $ wp mcp-ai assistant export 42
	 *
	 *     # Write config to a file.
	 *     $ wp mcp-ai assistant export 42 --file=assistant-42.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function export( $args, $assoc_args ) {
		$id   = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$file = \WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Assistant %d not found.', 'mcp-ai-wpoos' ), $id ) );
		}

		$all_meta = get_post_meta( $id );
		$meta     = array();
		foreach ( $all_meta as $key => $values ) {
			if ( 0 === strpos( $key, 'mcp_ai_' ) ) {
				$clean_key        = substr( $key, strlen( 'mcp_ai_' ) );
				$meta[ $clean_key ] = $values[0] ?? '';
			}
		}

		// Exclude credential hashes from the export.
		unset( $meta['credentials'] );

		$export = array(
			'version'   => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			'exported'  => gmdate( 'c' ),
			'assistant' => array(
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'content' => $post->post_content,
				'meta'    => $meta,
			),
		);

		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( $file ) {
			$file = wp_normalize_path( $file );

			// WordPress.org compliance: restrict file writes to the uploads directory.
			$upload_dir      = wp_upload_dir();
			$uploads_basedir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

			// Ensure the parent directory exists within uploads (create if needed).
			$parent_dir = dirname( $file );
			if ( ! is_dir( $parent_dir ) ) {
				// Only create the directory if it's inside uploads.
				$normalized_parent = wp_normalize_path( $parent_dir ) . '/';
				if ( 0 !== strpos( $normalized_parent, $uploads_basedir ) ) {
					/* translators: %s: uploads directory path */
					WP_CLI::error( sprintf( __( 'For security, export files must be saved inside the uploads directory (%s).', 'mcp-ai-wpoos' ), $uploads_basedir ) );
				}
				wp_mkdir_p( $parent_dir );
			}

			// Resolve the real path now that the directory exists and verify it's within uploads.
			$real_parent = realpath( $parent_dir );
			if ( false === $real_parent || 0 !== strpos( wp_normalize_path( $real_parent ) . '/', $uploads_basedir ) ) {
				/* translators: %s: uploads directory path */
				WP_CLI::error( sprintf( __( 'For security, export files must be saved inside the uploads directory (%s).', 'mcp-ai-wpoos' ), $uploads_basedir ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI command writing to restricted uploads path.
			if ( false === file_put_contents( $file, $json ) ) {
				/* translators: %s: file path */
				WP_CLI::error( sprintf( __( 'Could not write to file: %s', 'mcp-ai-wpoos' ), $file ) );
			}
			/* translators: 1: assistant title, 2: file path */
			WP_CLI::success( sprintf( __( 'Exported assistant "%1$s" to %2$s.', 'mcp-ai-wpoos' ), $post->post_title, $file ) );
			return;
		}

		WP_CLI::line( $json );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai assistant', 'WP_MCP_AI_CLI_Assistant_Command' );
}
