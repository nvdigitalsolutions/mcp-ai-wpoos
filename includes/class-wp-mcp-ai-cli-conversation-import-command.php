<?php
/**
 * WP-CLI commands for conversation imports.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversation import WP-CLI command surface.
 */
class WP_MCP_AI_CLI_Conversation_Import_Command extends WP_CLI_Command {

	/**
	 * Import an external conversation export into the CCT.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : File path to the export (ZIP, JSON) or a media library attachment ID.
	 *
	 * [--format=<format>]
	 * : Optional format override: chatgpt, gemini, claude, sharegpt, openai_jsonl.
	 *
	 * [--sideload-media]
	 * : Sideload referenced export images into the media library.
	 *
	 * [--dry-run]
	 * : Preview the import without writing to the CCT.
	 *
	 * [--policy=<policy>]
	 * : Dedupe policy: skip (default) or refresh.
	 * ---
	 * default: skip
	 * options:
	 *   - skip
	 *   - refresh
	 * ---
	 *
	 * [--batch-size=<num>]
	 * : Conversations per batch. Default: 25.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--limit=<num>]
	 * : Maximum conversations to process. 0 means all. Default: 0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--resume-token=<token>]
	 * : Resume a previously interrupted run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai conversation-import import ./chatgpt-export.zip --dry-run
	 *     wp mcp-ai conversation-import import ./conversations.json --policy=refresh
	 *     wp mcp-ai conversation-import import 1234 --resume-token=import-20260820-abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $args ) || ! isset( $args[0] ) ) {
			WP_CLI::error( __( 'Please provide the export file path or attachment ID.', 'mcp-ai-wpoos' ) );
		}

		if ( ! function_exists( 'jet_engine' ) && ! class_exists( 'Jet_Engine' ) ) {
			WP_CLI::error( __( 'JetEngine is not active; conversation import requires the transcript CCT.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();

		$run_args = array(
			'source'         => $args[0],
			'user_id'        => 0,
			'dry_run'        => \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			'policy'         => \WP_CLI\Utils\get_flag_value( $assoc_args, 'policy', 'skip' ),
			'batch_size'     => absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 25 ) ),
			'limit'          => absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 ) ),
			'sideload_media' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'sideload-media', false ),
		);

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', '' );
		if ( '' !== $format ) {
			$run_args['format'] = $format;
		}

		$resume_token = \WP_CLI\Utils\get_flag_value( $assoc_args, 'resume-token', '' );
		if ( '' !== $resume_token ) {
			$run_args['resume_token'] = $resume_token;
		}

		$report = $manager->run( $run_args );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}

		$totals = $report['totals'];

		WP_CLI::success(
			sprintf(
				/* translators: 1: imported, 2: updated, 3: skipped, 4: failed. */
				__( 'Import finished: %1$d imported, %2$d updated, %3$d skipped, %4$d failed.', 'mcp-ai-wpoos' ),
				$totals['imported'],
				$totals['updated'],
				$totals['skipped'],
				$totals['failed']
			)
		);

		foreach ( $report['errors'] as $error ) {
			WP_CLI::warning( $error['message'] );
		}
	}

	/**
	 * Inspect an export file and report its detected format.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : File path to the export (ZIP, JSON) or a media library attachment ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai conversation-import detect ./conversations.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function detect( $args, $assoc_args ) {
		if ( empty( $args ) || ! isset( $args[0] ) ) {
			WP_CLI::error( __( 'Please provide the export file path or attachment ID.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Manager' ) ) {
			WP_CLI::error( __( 'JetEngine is not active; conversation import tooling is unavailable.', 'mcp-ai-wpoos' ) );
		}

		$source_path = wp_normalize_path( $args[0] );

		if ( is_numeric( $source_path ) ) {
			$source_path = get_attached_file( absint( $source_path ) );
			if ( false === $source_path ) {
				WP_CLI::error( __( 'The media library attachment could not be found.', 'mcp-ai-wpoos' ) );
			}
		}

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			WP_CLI::error( __( 'The import source file does not exist or is not readable.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$result  = $manager->inspect( $source_path );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: platform, 2: estimated count, 3: bytes. */
				__( 'Detected %1$s export with ~%2$d conversations (%3$s).', 'mcp-ai-wpoos' ),
				$result['platform'],
				$result['estimated_count'],
				size_format( $result['bytes'] )
			)
		);
	}

	/**
	 * Delete imported conversations from the CCT.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Source platform: chatgpt or gemini.
	 *
	 * [--user-id=<id>]
	 * : Restrict deletion to conversations imported by this user. Default: all.
	 *
	 * [--limit=<num>]
	 * : Maximum rows to delete. Default: 0 (safety cap 500).
	 *
	 * [--dry-run]
	 * : Count matching rows without deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai conversation-import delete chatgpt --dry-run
	 *     wp mcp-ai conversation-import delete gemini --limit=100
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		if ( empty( $args ) || ! isset( $args[0] ) ) {
			WP_CLI::error( __( 'Please provide the platform slug (chatgpt or gemini).', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Deleter' ) ) {
			WP_CLI::error( __( 'JetEngine is not active; conversation import tooling is unavailable.', 'mcp-ai-wpoos' ) );
		}

		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();
		$report  = $deleter->delete(
			sanitize_key( (string) $args[0] ),
			absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'user-id', 0 ) ),
			absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 ) ),
			\WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false )
		);

		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}

		if ( $report['dry_run'] ) {
			WP_CLI::success(
				sprintf(
					/* translators: 1: platform, 2: matching rows. */
					__( 'Dry run: %2$d imported %1$s conversation(s) would be deleted.', 'mcp-ai-wpoos' ),
					$report['platform'],
					$report['found']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: deleted count, 2: platform, 3: failed count. */
				__( 'Deleted %1$d imported %2$s conversation(s) (%3$d failed).', 'mcp-ai-wpoos' ),
				$report['deleted'],
				$report['platform'],
				$report['failed']
			)
		);
	}

	/**
	 * Show the checkpoint status of a running import.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : Run token returned by a previous import invocation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai conversation-import status import-20260820-abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( empty( $args ) || ! isset( $args[0] ) ) {
			WP_CLI::error( __( 'Please provide the run token.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Manager' ) ) {
			WP_CLI::error( __( 'JetEngine is not active; conversation import tooling is unavailable.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();
		$state   = $manager->get_status( sanitize_text_field( (string) $args[0] ) );

		if ( isset( $state['status'] ) && 'not_found' === $state['status'] ) {
			WP_CLI::warning( $state['message'] );
			return;
		}

		WP_CLI::line(
			sprintf(
				/* translators: 1: token, 2: status, 3: processed count. */
				__( 'Import %1$s: %2$s (%3$d conversations processed).', 'mcp-ai-wpoos' ),
				$state['token'],
				$state['status'],
				$state['processed']
			)
		);
	}
}
