<?php
/**
 * WP-CLI commands for assistant prompt versions (Proposal 091, Track B).
 *
 * Commands:
 *   wp mcp-ai assistant prompt-versions list <assistant_id>
 *   wp mcp-ai assistant prompt-versions restore <assistant_id> <version>
 *   wp mcp-ai assistant prompt-versions promote <assistant_id> <version>
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompt versions CLI command.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_CLI_Prompt_Versions_Command extends WP_CLI_Command {

	/**
	 * List prompt versions for an assistant.
	 *
	 * ## OPTIONS
	 *
	 * <assistant_id>
	 * : Assistant post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai assistant prompt-versions list 123
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function list_versions( $args, $assoc_args ) {
		$post_id    = absint( $args[0] );
		$versions   = WP_MCP_AI_Prompt_Versions::get_versions( $post_id );
		$production = WP_MCP_AI_Prompt_Versions::get_production( $post_id );

		if ( empty( $versions ) ) {
			WP_CLI::warning( __( 'No prompt versions found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$rows = array_map(
			function ( $entry ) use ( $production ) {
				return array(
					'Version'    => $entry['v'],
					'Production' => $entry['v'] === $production ? 'yes' : 'no',
					'Created'    => $entry['created'] ? gmdate( 'Y-m-d H:i:s', $entry['created'] ) : '',
					'Author'     => $entry['author'],
					'Chars'      => strlen( $entry['prompt'] ),
				);
			},
			$versions
		);

		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'Version', 'Production', 'Created', 'Author', 'Chars' ) );
	}

	/**
	 * Restore a prompt version (restores are themselves versioned).
	 *
	 * ## OPTIONS
	 *
	 * <assistant_id>
	 * : Assistant post ID.
	 *
	 * <version>
	 * : Version number to restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai assistant prompt-versions restore 123 3
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$result = WP_MCP_AI_Prompt_Versions::restore( absint( $args[0] ), absint( $args[1] ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success(
			sprintf(
				/* translators: 1: restored version, 2: new undo snapshot version. */
				__( 'Restored version %1$d (new undo snapshot: v%2$d).', 'mcp-ai-wpoos' ),
				$result['restored'],
				$result['version']
			)
		);
	}

	/**
	 * Promote a prompt version to production.
	 *
	 * ## OPTIONS
	 *
	 * <assistant_id>
	 * : Assistant post ID.
	 *
	 * <version>
	 * : Version number to promote.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai assistant prompt-versions promote 123 3
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function promote( $args, $assoc_args ) {
		$result = WP_MCP_AI_Prompt_Versions::promote( absint( $args[0] ), absint( $args[1] ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success(
			sprintf(
				/* translators: %d: promoted version number. */
				__( 'Promoted version %d to production.', 'mcp-ai-wpoos' ),
				$result['version']
			)
		);
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai assistant prompt-versions', 'WP_MCP_AI_CLI_Prompt_Versions_Command' );
}
