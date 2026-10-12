<?php
/**
 * WP-CLI seeder for the GTmetrix Performance Optimizer assistant pack.
 *
 * Imports the canonical v1 `nvoos-assistant` bundle shipped in
 * `addons/pro/presets/assistant-packs/gtmetrix/` through the shared
 * portability engine, so seeding behaves identically to every other import
 * surface (slug-first matching, credential redaction, denylist stripping).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.2.5
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
 * Seed the GTmetrix Performance Optimizer assistant pack.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Pro_CLI_GTmetrix_Seed_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Shipped GTmetrix pack file.
	 *
	 * @var string
	 */
	const PACK_FILE = 'gtmetrix-performance-optimizer.json';

	/**
	 * Seed the GTmetrix Performance Optimizer assistant.
	 *
	 * Imports the canonical v1 bundle from
	 * `addons/pro/presets/assistant-packs/gtmetrix/` via
	 * `WP_MCP_AI_Assistant_Portability`. Matching is slug-first, then title;
	 * default mode `skip` makes re-runs idempotent.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : Import mode for the existing assistant.
	 * ---
	 * default: skip
	 * options:
	 *   - skip
	 *   - overwrite
	 *   - duplicate
	 * ---
	 *
	 * [--dry-run]
	 * : Validate the bundle and report what would happen without writing.
	 *
	 * [--status=<status>]
	 * : Post status for the created/updated assistant.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - private
	 * ---
	 *
	 * [--porcelain]
	 * : Print one `id\tstatus\ttitle` line and nothing else.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview without writing.
	 *     $ wp mcp-ai pro gtmetrix seed-assistant --dry-run
	 *
	 *     # Import (idempotent).
	 *     $ wp mcp-ai pro gtmetrix seed-assistant
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_gtmetrix_toolkit', 'GTmetrix Performance' );

		$mode      = isset( $assoc_args['mode'] ) ? sanitize_key( $assoc_args['mode'] ) : 'skip';
		$mode      = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';
		$dry_run   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$porcelain = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );
		$status    = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : 'publish';
		$status    = in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'publish';

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
			if ( ! file_exists( $engine_file ) ) {
				WP_CLI::error( __( 'Assistant portability engine not found. Update NV oOS to 1.1.80+.', 'mcp-ai-wpoos-pro' ) );
			}
			require_once $engine_file;
		}

		$pack_path = WP_MCP_AI_PRO_PATH . 'presets/assistant-packs/gtmetrix/' . self::PACK_FILE;

		if ( ! is_readable( $pack_path ) ) {
			WP_CLI::error( __( 'Missing GTmetrix assistant pack file.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundle file under WP-CLI, not a remote URL.
		$json = file_get_contents( $pack_path );
		if ( false === $json ) {
			WP_CLI::error( __( 'Could not read the GTmetrix assistant pack file.', 'mcp-ai-wpoos-pro' ) );
		}

		$bundle = WP_MCP_AI_Assistant_Portability::parse_import( $json );
		if ( is_wp_error( $bundle ) ) {
			WP_CLI::error( $bundle->get_error_message() );
		}

		$report = WP_MCP_AI_Assistant_Portability::import_bundle(
			$bundle,
			array(
				'mode'            => $mode,
				'dry_run'         => $dry_run,
				'status_override' => $status,
			)
		);

		foreach ( $report['items'] as $item ) {
			if ( $porcelain ) {
				WP_CLI::line( sprintf( '%d\t%s\t%s', isset( $item['assistant_id'] ) ? $item['assistant_id'] : 0, $item['status'], $item['title'] ) );
				continue;
			}

			switch ( $item['status'] ) {
				case 'error':
					WP_CLI::warning( sprintf( '%s: %s', $item['title'], $item['message'] ) );
					break;
				case 'skipped':
					/* translators: 1: assistant title, 2: existing ID */
					WP_CLI::log( sprintf( __( 'Skipped %1$s (existing ID %2$d). Use --overwrite to update.', 'mcp-ai-wpoos-pro' ), $item['title'], $item['assistant_id'] ) );
					break;
				case 'dry_run':
					/* translators: 1: assistant title, 2: action */
					WP_CLI::log( sprintf( __( 'Would %2$s %1$s.', 'mcp-ai-wpoos-pro' ), $item['title'], $item['action'] ) );
					break;
				default:
					/* translators: 1: assistant title, 2: action, 3: assistant ID */
					WP_CLI::success( sprintf( __( '%2$s %1$s (ID %3$d).', 'mcp-ai-wpoos-pro' ), $item['title'], $item['status'], $item['assistant_id'] ) );
					break;
			}
		}

		if ( $porcelain ) {
			return;
		}

		WP_CLI::log( __( 'Next steps:', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '1. Add your GTmetrix API key in NV oOS -> Settings -> API keys.', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '2. Configure core pages and the daily credit budget in the GTmetrix toolkit settings.', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '3. Install the gtmetrix_weekly_sweep schedule preset for regression tracking.', 'mcp-ai-wpoos-pro' ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai pro gtmetrix seed-assistant', 'WP_MCP_AI_Pro_CLI_GTmetrix_Seed_Command' );
}
