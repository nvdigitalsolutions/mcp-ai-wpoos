<?php
/**
 * WP-CLI graph stats command for the Content Graph AI addon.
 *
 * Reports knowledge-graph row counts from the parent plugin's schema
 * (`NvoosContentGraph\Graph\Db` table names) without depending on the
 * parent's `countNodes()`/`countEdges()` helpers, so missing tables are
 * reported honestly as `unavailable` instead of silently reading 0.
 *
 * @package NvoosContentGraphAi\Cli
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp nvoos-cg-ai graph stats` — knowledge-graph row counts.
 *
 * @since 1.1.0
 */
final class GraphCommand {

	/**
	 * Build the graph stats rows.
	 *
	 * @return array<int, array{Label: string, Value: string}>
	 */
	public static function get_stats(): array {
		if ( ! class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
			return array(
				array(
					'Label' => 'Graph',
					'Value' => 'unavailable',
				),
			);
		}

		return array(
			array(
				'Label' => 'Nodes',
				'Value' => self::table_count( \NvoosContentGraph\Graph\Db::nodesTable() ),
			),
			array(
				'Label' => 'Edges',
				'Value' => self::table_count( \NvoosContentGraph\Graph\Db::edgesTable() ),
			),
			array(
				'Label' => 'Embeddings Index',
				'Value' => self::table_count( \NvoosContentGraph\Graph\Db::embeddingsTable() ),
			),
		);
	}

	/**
	 * Count rows in a parent-plugin schema table.
	 *
	 * Returns 'unavailable' when the table does not exist so missing
	 * schema is never reported as an empty graph.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return string
	 */
	private static function table_count( string $table ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the parent plugin's schema constants, not user input.
		$wpdb->suppress_errors( true );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$wpdb->suppress_errors( false );
		// phpcs:enable

		return null === $count ? 'unavailable' : (string) (int) $count;
	}

	/**
	 * Display knowledge-graph row counts.
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
	 * ## EXAMPLES
	 *
	 *     # Show graph row counts.
	 *     $ wp nvoos-cg-ai graph stats
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $args );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, self::get_stats(), array( 'Label', 'Value' ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
