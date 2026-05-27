<?php
/**
 * WP-CLI project management commands for NV oOS Pro.
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
 * Manage NV oOS Pro projects (mcp_ai_project CPT) from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Project_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List all projects.
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
	 * ## EXAMPLES
	 *
	 *     # List all projects.
	 *     $ wp mcp-ai project list
	 *
	 *     # List only published projects.
	 *     $ wp mcp-ai project list --status=publish
	 *
	 *     # Export project IDs.
	 *     $ wp mcp-ai project list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'any' ) );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'posts_per_page' => -1,
				'post_status'    => $status,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No projects found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $posts, 'ID' ) ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'date'   => $post->post_date,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'title', 'status', 'date' ) );
	}

	/**
	 * Get details for a single project.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The project post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
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
	 *     # Get project 42.
	 *     $ wp mcp-ai project get 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid project ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_project' !== $post->post_type ) {
			/* translators: %d: project ID */
			WP_CLI::error( sprintf( __( 'Project %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$data = array(
			'ID'      => $post->ID,
			'title'   => $post->post_title,
			'status'  => $post->post_status,
			'content' => $post->post_content,
			'created' => $post->post_date,
			'updated' => $post->post_modified,
		);

		// Append all _project_* meta.
		foreach ( get_post_meta( $id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, '_project_' ) ) {
				$data[ $meta_key ] = $values[0] ?? '';
			}
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
			}
			return;
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
	 * Create a new project.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Project title.
	 *
	 * [--status=<status>]
	 * : Post status.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 * ---
	 *
	 * [--description=<text>]
	 * : Project description (post_content).
	 *
	 * [--porcelain]
	 * : Output the new post ID only.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a project.
	 *     $ wp mcp-ai project create --title="Relaunch Website"
	 *
	 *     # Capture the new ID.
	 *     $ PID=$(wp mcp-ai project create --title="Relaunch Website" --porcelain)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$title       = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', '' ) );
		$status      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'publish' );
		$description = \WP_CLI\Utils\get_flag_value( $assoc_args, 'description', '' );
		$porcelain   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		if ( '' === $title ) {
			WP_CLI::error( __( 'Please provide a --title for the project.', 'mcp-ai-wpoos-pro' ) );
		}

		$status = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'publish';

		$id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_project',
				'post_title'   => $title,
				'post_status'  => $status,
				'post_content' => sanitize_textarea_field( $description ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		if ( $porcelain ) {
			WP_CLI::line( $id );
			return;
		}

		/* translators: 1: project title, 2: project post ID */
		WP_CLI::success( sprintf( __( 'Created project "%1$s" (ID: %2$d).', 'mcp-ai-wpoos-pro' ), $title, $id ) );
	}

	/**
	 * Update an existing project.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The project post ID.
	 *
	 * [--title=<title>]
	 * : New project title.
	 *
	 * [--status=<status>]
	 * : New post status.
	 * ---
	 * options:
	 *   - publish
	 *   - draft
	 * ---
	 *
	 * [--description=<text>]
	 * : New project description (post_content).
	 *
	 * ## EXAMPLES
	 *
	 *     # Rename a project.
	 *     $ wp mcp-ai project update 42 --title="New Name"
	 *
	 *     # Change status to draft.
	 *     $ wp mcp-ai project update 42 --status=draft
	 *
	 *     # Update title and description.
	 *     $ wp mcp-ai project update 42 --title="Relaunch" --description="Phase 2 scope"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id          = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$title       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', null );
		$status      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', null );
		$description = \WP_CLI\Utils\get_flag_value( $assoc_args, 'description', null );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid project ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_project' !== $post->post_type ) {
			/* translators: %d: project ID */
			WP_CLI::error( sprintf( __( 'Project %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$update_data = array(
			'ID' => $id,
		);

		if ( null !== $title ) {
			$title = sanitize_text_field( $title );
			if ( '' === $title ) {
				WP_CLI::error( __( 'Project title cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}
			$update_data['post_title'] = $title;
		}

		if ( null !== $status ) {
			$status                     = sanitize_key( $status );
			$status                     = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'publish';
			$update_data['post_status'] = $status;
		}

		if ( null !== $description ) {
			$update_data['post_content'] = sanitize_textarea_field( $description );
		}

		if ( 1 === count( $update_data ) ) {
			WP_CLI::warning( __( 'No fields provided to update.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$result = wp_update_post( $update_data, true );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 0 === $result ) {
			/* translators: %d: project ID */
			WP_CLI::error( sprintf( __( 'Failed to update project %d.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		/* translators: 1: updated post title, 2: project ID */
		WP_CLI::success( sprintf( __( 'Updated project "%1$s" (ID: %2$d).', 'mcp-ai-wpoos-pro' ), get_the_title( $result ), $result ) );
	}

	/**
	 * Delete a project.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The project post ID.
	 *
	 * [--force]
	 * : Permanently delete without trashing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Trash project 42.
	 *     $ wp mcp-ai project delete 42
	 *
	 *     # Permanently delete.
	 *     $ wp mcp-ai project delete 42 --force --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id    = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$yes   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid project ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_project' !== $post->post_type ) {
			/* translators: %d: project ID */
			WP_CLI::error( sprintf( __( 'Project %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		if ( ! $yes ) {
			$action = $force
				? /* translators: 1: project title, 2: project ID */
					sprintf( __( 'Permanently delete project "%1$s" (ID %2$d)?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id )
				: /* translators: 1: project title, 2: project ID */
					sprintf( __( 'Move project "%1$s" (ID %2$d) to trash?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id );
			WP_CLI::confirm( $action );
		}

		$result = wp_delete_post( $id, (bool) $force );

		if ( ! $result ) {
			/* translators: %d: project ID */
			WP_CLI::error( sprintf( __( 'Failed to delete project %d.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		if ( $force ) {
			/* translators: 1: project title, 2: project ID */
			WP_CLI::success( sprintf( __( 'Permanently deleted project "%1$s" (ID %2$d).', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
		} else {
			/* translators: 1: project title, 2: project ID */
			WP_CLI::success( sprintf( __( 'Moved project "%1$s" (ID %2$d) to trash.', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
		}
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai project', 'WP_MCP_AI_Pro_CLI_Project_Command' );
}
