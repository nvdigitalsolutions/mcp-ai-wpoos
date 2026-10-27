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

	/**
	 * Run a RAG hit-test over a golden set (Proposal 091, Track C).
	 *
	 * Retrieval-only evaluation (no LLM calls): reports context_precision,
	 * context_recall, MRR and hit@k per query and as a summary.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the golden-set JSONL file
	 *   ({ "query": "…", "gold_node_ids": ["…"] } per line).
	 *
	 * [--top-k=<number>]
	 * : Results per query.
	 * ---
	 * default: 5
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp nvoos-cg-ai rag hit-test ./golden.jsonl --top-k=5
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function hit_test( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! \file_exists( (string) $args[0] ) ) {
			\WP_CLI::error( 'Golden-set file not found.' );
		}

		$raw = \file_get_contents( (string) $args[0] );
		if ( false === $raw ) {
			\WP_CLI::error( 'Could not read golden-set file.' );
		}

		$golden = \NvoosContentGraphAi\Embeddings\HitTestRunner::parseGolden( $raw );
		if ( array() === $golden ) {
			\WP_CLI::error( 'No valid golden entries found in the file.' );
		}

		if ( ! \class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			\WP_CLI::error( 'Content Graph AI runtime is not available (CoreBridge missing).' );
		}

		$topK   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'top-k', 5 );
		$retriever = static fn( string $query, int $k ): mixed => \NvoosContentGraphAi\CoreBridge::instance()->rag->searchHybrid( $query, $k );

		$report = ( new \NvoosContentGraphAi\Embeddings\HitTestRunner( $retriever ) )->evaluate( $golden, \max( 1, $topK ) );

		\WP_CLI::log( 'Hit-test summary:' );
		foreach ( $report['summary'] as $metric => $value ) {
			\WP_CLI::log( sprintf( '  %s: %s', $metric, $value ) );
		}

		\WP_CLI\Utils\format_items(
			\WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$report['rows'],
			array( 'query', 'context_precision', 'context_recall', 'mrr', 'hit' )
		);
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
