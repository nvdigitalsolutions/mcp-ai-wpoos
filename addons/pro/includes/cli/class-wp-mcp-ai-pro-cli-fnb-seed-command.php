<?php
/**
 * WP-CLI seeder for the Food & Beverage assistant packs (A1–A4).
 *
 * Imports the four canonical v1 `nvoos-assistant` bundles shipped in
 * `addons/pro/presets/assistant-packs/fnb/` through the shared portability
 * engine, so seeding behaves identically to every other import surface
 * (slug-first matching, credential redaction, denylist stripping).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.6.0
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
 * Seed the Surf Club Midigama F&B assistant packs.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Pro_CLI_Fnb_Seed_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Shipped F&B pack files, in seed order.
	 *
	 * @var array<int,string>
	 */
	const PACK_FILES = array(
		'a1-manager.json',
		'a2-kitchen-bar-stock.json',
		'a3-financial.json',
		'a4-content.json',
	);

	/**
	 * ACT-tier image-production slugs opt-in for A4 (spec A4-05: default off).
	 *
	 * @var array<int,string>
	 */
	const IMAGE_TOOLS = array(
		'generate_image_ai',
		'generate_image_variations',
		'image_inpainting',
		'text_to_image_prompt_optimizer',
	);

	/**
	 * Seed the four F&B assistant packs (A1–A4).
	 *
	 * Imports the canonical v1 bundles from
	 * `addons/pro/presets/assistant-packs/fnb/` via
	 * `WP_MCP_AI_Assistant_Portability`, the same engine used by the admin
	 * Import/Export page, REST and the `import_assistant` tool. Matching is
	 * slug-first, then title; default mode `skip` makes re-runs idempotent.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : Import mode for existing assistants.
	 * ---
	 * default: skip
	 * options:
	 *   - skip
	 *   - overwrite
	 *   - duplicate
	 * ---
	 *
	 * [--dry-run]
	 * : Validate every bundle and report what would happen without writing.
	 *
	 * [--include-image-tools]
	 * : Add the four image-production tools (generate_image_ai,
	 * generate_image_variations, image_inpainting, text_to_image_prompt_optimizer)
	 * to A4's allowlist. Default off per spec rule A4-05 and the Tools &
	 * permissions sheet (ACT tier).
	 *
	 * [--status=<status>]
	 * : Post status for created/updated assistants.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - private
	 * ---
	 *
	 * [--porcelain]
	 * : Print one `id\tstatus\ttitle` line per assistant and nothing else.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview without writing.
	 *     $ wp mcp-ai pro fnb seed-assistants --dry-run
	 *
	 *     # Import all four (idempotent).
	 *     $ wp mcp-ai pro fnb seed-assistants
	 *
	 *     # Re-import over existing assistants; enable A4 image tools.
	 *     $ wp mcp-ai pro fnb seed-assistants --overwrite --include-image-tools
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_fnb_toolkit', 'Food & Beverage' );

		$mode           = isset( $assoc_args['mode'] ) ? sanitize_key( $assoc_args['mode'] ) : 'skip';
		$mode           = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';
		$dry_run        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$include_images = \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-image-tools', false );
		$porcelain      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );
		$status         = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : 'publish';
		$status         = in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'publish';

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
			if ( ! file_exists( $engine_file ) ) {
				WP_CLI::error( __( 'Assistant portability engine not found. Update NV oOS to 1.1.80+.', 'mcp-ai-wpoos-pro' ) );
			}
			require_once $engine_file;
		}

		$packs_dir = WP_MCP_AI_PRO_PATH . 'presets/assistant-packs/fnb/';

		$totals = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		foreach ( self::PACK_FILES as $pack_file ) {
			$pack_path = $packs_dir . $pack_file;

			if ( ! is_readable( $pack_path ) ) {
				++$totals['errors'];
				/* translators: %s: pack file name */
				WP_CLI::warning( sprintf( __( 'Missing F&B assistant pack file: %s.', 'mcp-ai-wpoos-pro' ), $pack_file ) );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundle file under WP-CLI, not a remote URL.
			$json = file_get_contents( $pack_path );
			if ( false === $json ) {
				++$totals['errors'];
				/* translators: %s: pack file name */
				WP_CLI::warning( sprintf( __( 'Could not read F&B assistant pack file: %s.', 'mcp-ai-wpoos-pro' ), $pack_file ) );
				continue;
			}

			$bundle = WP_MCP_AI_Assistant_Portability::parse_import( $json );

			if ( is_wp_error( $bundle ) ) {
				++$totals['errors'];
				/* translators: 1: pack file name, 2: error message */
				WP_CLI::warning( sprintf( __( 'Invalid bundle %1$s: %2$s', 'mcp-ai-wpoos-pro' ), $pack_file, $bundle->get_error_message() ) );
				continue;
			}

			if ( $include_images && 'a4-content.json' === $pack_file ) {
				$bundle = $this->add_image_tools_to_a4( $bundle );
			}

			$report = WP_MCP_AI_Assistant_Portability::import_bundle(
				$bundle,
				array(
					'mode'            => $mode,
					'dry_run'         => $dry_run,
					'status_override' => $status,
				)
			);

			$totals['created'] += $report['created'];
			$totals['updated'] += $report['updated'];
			$totals['skipped'] += $report['skipped'];
			$totals['errors']  += $report['errors'];

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
		}

		if ( $porcelain ) {
			return;
		}

		if ( $totals['errors'] > 0 ) {
			WP_CLI::warning( sprintf( __( 'Completed with %d error(s).', 'mcp-ai-wpoos-pro' ), $totals['errors'] ) );
		}

		/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
		WP_CLI::success( sprintf( __( 'F&B assistant packs: %1$d created, %2$d updated, %3$d skipped, %4$d errors.', 'mcp-ai-wpoos-pro' ), $totals['created'], $totals['updated'], $totals['skipped'], $totals['errors'] ) );

		WP_CLI::log( __( 'Next steps:', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '1. NV oOS -> Settings -> Food & Beverage: set the Data, Assistant setup and Drafts folder IDs (or CSV paths).', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '2. Verify tool visibility per assistant (Assistants -> edit -> Tools).', 'mcp-ai-wpoos-pro' ) );
		WP_CLI::log( __( '3. Optional MCP exposure for Claude/ChatGPT: create an MCP App per assistant with the read+draft subset only.', 'mcp-ai-wpoos-pro' ) );
		if ( ! $include_images ) {
			WP_CLI::log( __( '4. A4 image tools remain off (spec A4-05). Enable post-demo with --include-image-tools.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Append the four image-production slugs to A4's tool allowlist.
	 *
	 * Idempotent: existing entries are not duplicated.
	 *
	 * @param array $bundle Parsed canonical bundle (single A4 assistant).
	 * @return array Bundle with image tools appended to A4's tool list.
	 */
	private function add_image_tools_to_a4( $bundle ) {
		foreach ( $bundle['assistants'] as $index => $assistant ) {
			$tools = isset( $assistant['meta']['_wp_mcp_ai_tools'] ) && is_array( $assistant['meta']['_wp_mcp_ai_tools'] )
				? $assistant['meta']['_wp_mcp_ai_tools']
				: array();

			$bundle['assistants'][ $index ]['meta']['_wp_mcp_ai_tools'] = array_values( array_unique( array_merge( $tools, self::IMAGE_TOOLS ) ) );
		}

		return $bundle;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai pro fnb seed-assistants', 'WP_MCP_AI_Pro_CLI_Fnb_Seed_Command' );
}
