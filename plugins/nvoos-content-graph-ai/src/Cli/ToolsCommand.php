<?php
/**
 * WP-CLI tools command for the Content Graph AI addon.
 *
 * Lists the tools exposed to agents in the active install mode: the base
 * plugin's `WP_MCP_AI_Tool_Registry` in monolith installs and the
 * nvoos/core registry (via `CoreBridge`) standalone — the same seam used
 * by the REST `ToolsController`.
 *
 * @package NvoosContentGraphAi\Cli
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Cli;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp nvoos-cg-ai tools list` — registered tool inventory.
 *
 * @since 1.1.0
 */
final class ToolsCommand {

	/**
	 * Build the tool rows, optionally filtered by a partial slug match.
	 *
	 * @param string $filter Partial slug filter (case-insensitive).
	 * @return array<int, array{Slug: string, Name: string, Group: string}>
	 */
	public static function get_tools( string $filter = '' ): array {
		$rows = array();

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			// Monolith: the base registry owns the agent tool surface.
			$tools = \WP_MCP_AI_Tool_Registry::get_instance()->get_tools();

			foreach ( is_array( $tools ) ? $tools : array() as $tool ) {
				if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
					continue;
				}

				$slug  = $tool->get_slug();
				$rows[] = array(
					'Slug'  => $slug,
					'Name'  => method_exists( $tool, 'get_name' ) ? (string) $tool->get_name() : $slug,
					'Group' => 'base',
				);
			}
		} else {
			// Standalone: the nvoos/core registry via CoreBridge.
			foreach ( CoreBridge::instance()->tools->enabled() as $slug => $tool ) {
				$rows[] = array(
					'Slug'  => $slug,
					'Name'  => $tool->getName(),
					'Group' => 0 === strpos( $slug, 'nvoos_content_graph_' ) ? 'graph' : 'ai',
				);
			}
		}

		if ( '' !== $filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filter ): bool {
						return false !== stripos( $row['Slug'], $filter );
					}
				)
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['Slug'], $b['Slug'] );
			}
		);

		return $rows;
	}

	/**
	 * List the tools exposed to agents.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *
	 * [--filter=<search>]
	 * : Only show tools whose slug contains this value.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all registered tools.
	 *     $ wp nvoos-cg-ai tools list
	 *
	 *     # Only graph tools.
	 *     $ wp nvoos-cg-ai tools list --filter=nvoos_content_graph
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $args );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$filter = sanitize_text_field( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'filter', '' ) );

		\WP_CLI\Utils\format_items( $format, self::get_tools( $filter ), array( 'Slug', 'Name', 'Group' ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
