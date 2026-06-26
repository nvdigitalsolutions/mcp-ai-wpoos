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
	public function list( $args, $assoc_args ) {
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
			$model   = get_post_meta( $post->ID, 'mcp_ai_model', true );
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
				$clean_key          = substr( $key, strlen( 'mcp_ai_' ) );
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

		$this->require_capability( 'manage_options' );

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

		$this->require_capability( 'manage_options' );

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
	 * [--file=<filename>]
	 * : Write the export to a file inside the plugin-specific uploads directory
	 * (wp-content/uploads/mcp-ai/exports/) instead of stdout. Only a filename
	 * is accepted; path separators are stripped for security.
	 *
	 * ## EXAMPLES
	 *
	 *     # Print assistant config to stdout.
	 *     $ wp mcp-ai assistant export 42
	 *
	 *     # Write config to a file in uploads/mcp-ai/exports/.
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
				$clean_key          = substr( $key, strlen( 'mcp_ai_' ) );
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
			// WordPress.org compliance: ALL file writes are restricted to the
			// plugin-specific uploads subdirectory wp-content/uploads/mcp-ai/exports/.
			// Only the basename of the user-supplied --file argument is used as the
			// filename; directory traversal is impossible because sanitize_file_name()
			// strips path separators and basename() removes any leading path.
			$upload_dir = wp_upload_dir();
			$export_dir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) ) . 'mcp-ai/exports/';

			// Strip any path separators from the user-supplied filename for security.
			$safe_filename = sanitize_file_name( basename( $file ) );

			if ( empty( $safe_filename ) ) {
				WP_CLI::error( __( 'Invalid filename provided.', 'mcp-ai-wpoos' ) );
			}

			// Create the export directory if it doesn't exist and protect it from direct web access.
			if ( ! is_dir( $export_dir ) ) {
				wp_mkdir_p( $export_dir );
			}
			// Prevent direct HTTP access to exported files.
			$htaccess_file = $export_dir . '.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI-only code writing web-server deny rule to plugin uploads subdirectory.
				file_put_contents( $htaccess_file, "Deny from all\n" );
			}
			$index_file = $export_dir . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI-only code writing directory listing guard to plugin uploads subdirectory.
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
			}

			$file = $export_dir . $safe_filename;

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

	/**
	 * Update an existing assistant.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The assistant post ID.
	 *
	 * [--title=<title>]
	 * : New title for the assistant.
	 *
	 * [--status=<status>]
	 * : New post status.
	 * ---
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
	 * ## EXAMPLES
	 *
	 *     # Change the title of assistant 42.
	 *     $ wp mcp-ai assistant update 42 --title="Support Bot v2"
	 *
	 *     # Publish assistant 42 and set its model.
	 *     $ wp mcp-ai assistant update 42 --status=publish --model=gpt-4o
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$id            = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$title         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', null );
		$status        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', null );
		$model         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'model', null );
		$system_prompt = \WP_CLI\Utils\get_flag_value( $assoc_args, 'system-prompt', null );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid assistant ID.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			/* translators: %d: assistant ID */
			WP_CLI::error( sprintf( __( 'Assistant %d not found.', 'mcp-ai-wpoos' ), $id ) );
		}

		// At least one field must be provided to update.
		if ( null === $title && null === $status && null === $model && null === $system_prompt ) {
			WP_CLI::error( __( 'Please provide at least one field to update (--title, --status, --model, or --system-prompt).', 'mcp-ai-wpoos' ) );
		}

		$post_data = array(
			'ID' => $id,
		);

		if ( null !== $title ) {
			$title = sanitize_text_field( $title );

			if ( '' === $title ) {
				WP_CLI::error( __( 'Title cannot be empty.', 'mcp-ai-wpoos' ) );
			}

			$post_data['post_title'] = $title;
		}

		if ( null !== $status ) {
			$status                   = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
			$post_data['post_status'] = $status;
		}

		// Update the post if title or status changed.
		if ( isset( $post_data['post_title'] ) || isset( $post_data['post_status'] ) ) {
			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
		}

		// Update meta fields.
		if ( null !== $model ) {
			update_post_meta( $id, 'mcp_ai_model', sanitize_text_field( $model ) );
		}

		if ( null !== $system_prompt ) {
			update_post_meta( $id, 'mcp_ai_system_prompt', sanitize_textarea_field( $system_prompt ) );
		}

		// Re-fetch the post to get the current title for the success message.
		$updated_post  = get_post( $id );
		$updated_title = $updated_post ? $updated_post->post_title : $post->post_title;

		/* translators: 1: assistant title, 2: assistant post ID */
		WP_CLI::success( sprintf( __( 'Updated assistant "%1$s" (ID: %2$d).', 'mcp-ai-wpoos' ), $updated_title, $id ) );
	}

	/**
	 * Import an assistant from a JSON file or stdin.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<filename>]
	 * : Read the import from a file inside the plugin-specific exports directory
	 * (wp-content/uploads/mcp-ai/exports/). Only a filename is accepted; path
	 * separators are stripped for security. Mutually exclusive with --stdin.
	 *
	 * [--stdin]
	 * : Read JSON from standard input. Mutually exclusive with --file.
	 *
	 * [--porcelain]
	 * : Output only the created assistant ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import from a file in the exports directory.
	 *     $ wp mcp-ai assistant import --file=assistant-42.json
	 *
	 *     # Import from stdin.
	 *     $ wp mcp-ai assistant import --stdin < assistant.json
	 *
	 *     # Import and get only the new ID.
	 *     $ wp mcp-ai assistant import --file=assistant-42.json --porcelain
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		$file      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );
		$use_stdin = \WP_CLI\Utils\get_flag_value( $assoc_args, 'stdin', false );
		$porcelain = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		if ( $file && $use_stdin ) {
			WP_CLI::error( __( 'Please provide either --file or --stdin, not both.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $file && ! $use_stdin ) {
			WP_CLI::error( __( 'Please provide --file=<filename> or --stdin.', 'mcp-ai-wpoos' ) );
		}

		if ( $file ) {
			$upload_dir    = wp_upload_dir();
			$export_dir    = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) ) . 'mcp-ai/exports/';
			$safe_filename = sanitize_file_name( basename( $file ) );

			if ( empty( $safe_filename ) ) {
				WP_CLI::error( __( 'Invalid filename provided.', 'mcp-ai-wpoos' ) );
			}

			$file_path = $export_dir . $safe_filename;

			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				/* translators: %s: file path */
				WP_CLI::error( sprintf( __( 'File not found or not readable: %s', 'mcp-ai-wpoos' ), $file_path ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI command reading from restricted uploads path.
			$raw = file_get_contents( $file_path );
			if ( false === $raw ) {
				/* translators: %s: file path */
				WP_CLI::error( sprintf( __( 'Could not read file: %s', 'mcp-ai-wpoos' ), $file_path ) );
			}

			$json = $raw;
		} else {
			// Read from stdin.
			$json = '';
			while ( ! feof( STDIN ) ) {
				$line = fgets( STDIN );
				if ( false !== $line ) {
					$json .= $line;
				}
			}
		}

		// Decode and validate the JSON.
		$data = json_decode( $json, true );

		if ( null === $data || ! is_array( $data ) ) {
			WP_CLI::error( __( 'Invalid JSON provided. Could not decode.', 'mcp-ai-wpoos' ) );
		}

		// Validate the expected export structure.
		if ( ! isset( $data['assistant'] ) || ! is_array( $data['assistant'] ) ) {
			WP_CLI::error( __( 'Invalid export format: missing "assistant" key.', 'mcp-ai-wpoos' ) );
		}

		$assistant_data = $data['assistant'];

		if ( ! isset( $assistant_data['title'] ) || '' === trim( (string) $assistant_data['title'] ) ) {
			WP_CLI::error( __( 'Invalid export format: assistant title is required.', 'mcp-ai-wpoos' ) );
		}

		$title   = sanitize_text_field( $assistant_data['title'] );
		$status  = isset( $assistant_data['status'] ) ? sanitize_key( $assistant_data['status'] ) : 'draft';
		$content = isset( $assistant_data['content'] ) ? wp_kses_post( $assistant_data['content'] ) : '';

		$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

		$post_data = array(
			'post_type'    => 'mcp_ai_assistant',
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => $content,
		);

		$new_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $new_id ) ) {
			WP_CLI::error( $new_id->get_error_message() );
		}

		// Import meta fields, excluding credentials (just like export skips them).
		if ( isset( $assistant_data['meta'] ) && is_array( $assistant_data['meta'] ) ) {
			foreach ( $assistant_data['meta'] as $meta_key => $meta_value ) {
				$meta_key = sanitize_key( $meta_key );

				// Skip credential hashes.
				if ( 'credentials' === $meta_key ) {
					continue;
				}

				if ( '' !== $meta_key ) {
					// Meta keys are stored without the mcp_ai_ prefix in the export;
					// add it back when saving.
					update_post_meta( $new_id, 'mcp_ai_' . $meta_key, sanitize_text_field( $meta_value ) );
				}
			}
		}

		if ( $porcelain ) {
			WP_CLI::line( $new_id );
			return;
		}

		/* translators: 1: assistant title, 2: assistant post ID */
		WP_CLI::success( sprintf( __( 'Imported assistant "%1$s" (ID: %2$d).', 'mcp-ai-wpoos' ), $title, $new_id ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai assistant', 'WP_MCP_AI_CLI_Assistant_Command' );
}
