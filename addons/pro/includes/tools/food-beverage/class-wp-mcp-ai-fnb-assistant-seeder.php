<?php
/**
 * Food & Beverage Management Toolkit — Assistant Pack Seeder.
 *
 * Shared service that imports the four canonical v1 `nvoos-assistant` bundles
 * shipped in `addons/pro/presets/assistant-packs/fnb/` (A1 Manager, A2 Kitchen
 * & Bar Stock, A3 Financial, A4 Content) through the shared portability
 * engine. Used by both the WP-CLI seeder (`wp mcp-ai pro fnb seed-assistants`)
 * and the admin settings-page button, so the two surfaces behave identically
 * (slug-first matching, credential redaction, denylist stripping).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds the Surf Club Midigama F&B assistant packs (A1–A4).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Assistant_Seeder {

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
	 * Path to the shipped F&B assistant pack directory.
	 *
	 * @return string
	 */
	public static function packs_dir() {
		return WP_MCP_AI_PRO_PATH . 'presets/assistant-packs/fnb/';
	}

	/**
	 * Seed the four F&B assistant packs.
	 *
	 * Imports each canonical v1 bundle through
	 * `WP_MCP_AI_Assistant_Portability`. Matching is slug-first, then title;
	 * the default mode `skip` makes re-runs idempotent.
	 *
	 * @param array $options Seed options.
	 *                       - mode (string)        skip|overwrite|duplicate. Default skip.
	 *                       - dry_run (bool)       Validate + report without writing. Default false.
	 *                       - status (string)      publish|draft|private. Default publish.
	 *                       - include_image_tools (bool) Add image tools to A4. Default false.
	 * @return array|WP_Error Aggregated report:
	 *                         array{
	 *                           created:int, updated:int, skipped:int, errors:int,
	 *                           items:array<int,array>
	 *                         } or WP_Error when a hard setup failure occurred.
	 */
	public static function seed( $options = array() ) {
		$mode           = isset( $options['mode'] ) ? sanitize_key( $options['mode'] ) : 'skip';
		$mode           = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';
		$dry_run        = ! empty( $options['dry_run'] );
		$status         = isset( $options['status'] ) ? sanitize_key( $options['status'] ) : 'publish';
		$status         = in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'publish';
		$include_images = ! empty( $options['include_image_tools'] );

		self::maybe_load_portability_engine();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_missing_portability',
				__( 'Assistant portability engine not found. Update NV oOS to 1.1.80+.', 'mcp-ai-wpoos-pro' )
			);
		}

		$totals = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'items'   => array(),
		);

		foreach ( self::PACK_FILES as $pack_file ) {
			$pack_path = self::packs_dir() . $pack_file;

			if ( ! is_readable( $pack_path ) ) {
				++$totals['errors'];
				$totals['items'][] = array(
					'status'  => 'error',
					'title'   => $pack_file,
					/* translators: %s: pack file name */
					'message' => sprintf( __( 'Missing F&B assistant pack file: %s.', 'mcp-ai-wpoos-pro' ), $pack_file ),
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundle file, not a remote URL.
			$json = file_get_contents( $pack_path );
			if ( false === $json ) {
				++$totals['errors'];
				$totals['items'][] = array(
					'status'  => 'error',
					'title'   => $pack_file,
					/* translators: %s: pack file name */
					'message' => sprintf( __( 'Could not read F&B assistant pack file: %s.', 'mcp-ai-wpoos-pro' ), $pack_file ),
				);
				continue;
			}

			$bundle = WP_MCP_AI_Assistant_Portability::parse_import( $json );

			if ( is_wp_error( $bundle ) ) {
				++$totals['errors'];
				$totals['items'][] = array(
					'status'  => 'error',
					'title'   => $pack_file,
					/* translators: 1: pack file name, 2: error message */
					'message' => sprintf( __( 'Invalid bundle %1$s: %2$s', 'mcp-ai-wpoos-pro' ), $pack_file, $bundle->get_error_message() ),
				);
				continue;
			}

			if ( $include_images && 'a4-content.json' === $pack_file ) {
				$bundle = self::add_image_tools_to_a4( $bundle );
			}

			$report = WP_MCP_AI_Assistant_Portability::import_bundle(
				$bundle,
				array(
					'mode'            => $mode,
					'dry_run'         => $dry_run,
					'status_override' => $status,
				)
			);

			$totals['created'] += isset( $report['created'] ) ? (int) $report['created'] : 0;
			$totals['updated'] += isset( $report['updated'] ) ? (int) $report['updated'] : 0;
			$totals['skipped'] += isset( $report['skipped'] ) ? (int) $report['skipped'] : 0;
			$totals['errors']  += isset( $report['errors'] ) ? (int) $report['errors'] : 0;

			if ( ! empty( $report['items'] ) && is_array( $report['items'] ) ) {
				$totals['items'] = array_merge( $totals['items'], $report['items'] );
			}
		}

		return $totals;
	}

	/**
	 * Load the shared assistant portability engine on demand.
	 *
	 * @return void
	 */
	public static function maybe_load_portability_engine() {
		if ( class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			return;
		}

		$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
		if ( file_exists( $engine_file ) ) {
			require_once $engine_file;
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
	private static function add_image_tools_to_a4( $bundle ) {
		foreach ( $bundle['assistants'] as $index => $assistant ) {
			$tools = isset( $assistant['meta']['_wp_mcp_ai_tools'] ) && is_array( $assistant['meta']['_wp_mcp_ai_tools'] )
				? $assistant['meta']['_wp_mcp_ai_tools']
				: array();

			$bundle['assistants'][ $index ]['meta']['_wp_mcp_ai_tools'] = array_values( array_unique( array_merge( $tools, self::IMAGE_TOOLS ) ) );
		}

		return $bundle;
	}
}