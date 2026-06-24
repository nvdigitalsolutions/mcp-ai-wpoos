<?php
declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Schema;
use WP_Post;
use function apply_filters;
use function current_time;
use function delete_transient;
use function do_action;
use function gmdate;
use function method_exists;

/**
 * Graph builder — orchestrates the extraction pipeline.
 *
 * Coordinates: Detector → StructuralExtractor → Db (batch upserts).
 * After all writes, recalculates degree counts and triggers
 * community detection via the Analyzer (Phase 3).
 *
 * @since 1.0.0
 */
class Builder {

	/**
	 * Run a full or incremental graph build.
	 *
	 * @param array<string,mixed> $args {
	 *     @type bool $incremental    Only process content changed since last build.
	 *     @type bool $reset          Truncate existing graph first.
	 * }
	 * @return array<string,mixed> Result summary.
	 */
	public static function build( array $args = array() ): array {
		$incremental = ! empty( $args['incremental'] );
		$reset       = ! empty( $args['reset'] );

		/**
		 * Fires before a graph build begins.
		 *
		 * @since 1.0.0
		 * @param array $args Build arguments.
		 */
		do_action( Schema::ACTION_BEFORE_BUILD, $args );

		Db::setMeta( 'build_status', 'running' );
		Db::setMeta( 'build_started', gmdate( 'Y-m-d H:i:s' ) );

		if ( $reset && ! $incremental ) {
			Db::truncateEdges();
			Db::truncateNodes();
		}

		// 1. Detect content.
		$detected = Detector::detect( $incremental );

		$postCount     = count( $detected['posts'] );
		$cctsDetected  = isset( $detected['ccts'] ) ? count( (array) $detected['ccts'] ) : 0;
		$termsDetected = isset( $detected['terms'] ) ? count( (array) $detected['terms'] ) : 0;
		$usersDetected = isset( $detected['users'] ) ? count( (array) $detected['users'] ) : 0;
		$mediaDetected = isset( $detected['media'] ) ? count( (array) $detected['media'] ) : 0;

		// 2. Structural extraction.
		$structural = StructuralExtractor::extract( $detected );
		$nodeCount  = Db::batchUpsertNodes( $structural['nodes'] );
		$edgeCount  = Db::batchUpsertEdges( $structural['edges'] );

		// 3. Recalculate degree counts.
		self::recalculateAllDegrees();

		// 4. Community detection (Phase 3 — conditional on Analyzer being available).
		if ( class_exists( 'NvoosGraphify\Graph\Analyzer' ) && method_exists( 'NvoosGraphify\Graph\Analyzer', 'detectCommunities' ) ) {
			Analyzer::detectCommunities();
		}

		// 5. Update build metadata.
		$completed = gmdate( 'Y-m-d H:i:s' );
		Db::setMeta( 'last_build_completed', $completed );
		Db::setMeta( 'build_status', 'idle' );

		// Invalidate report cache.
		delete_transient( Schema::TRANSIENT_PREFIX . 'report' );

		$summary = array(
			'success'         => true,
			'posts_processed' => $postCount,
			'posts_detected'  => $postCount,
			'ccts_detected'   => $cctsDetected,
			'terms_detected'  => $termsDetected,
			'users_detected'  => $usersDetected,
			'media_detected'  => $mediaDetected,
			'nodes_upserted'  => $nodeCount,
			'edges_upserted'  => $edgeCount,
			'build_completed' => $completed,
		);

		Db::setMeta( 'last_build_summary', $summary );

		/**
		 * Fires after a graph build completes.
		 *
		 * @since 1.0.0
		 * @param array $summary Build result summary.
		 */
		do_action( Schema::ACTION_AFTER_BUILD, $summary );

		return $summary;
	}

	/**
	 * Build a single post (incremental on save).
	 *
	 * @param WP_Post $post The post to process.
	 * @return void
	 */
	public function buildPost( WP_Post $post ): void {
		$detected = array(
			'posts'    => array( $post ),
			'ccts'     => array(),
			'terms'    => Detector::detectTerms( array( $post ) ),
			'users'    => Detector::detectUsers( array( $post ) ),
			'media'    => Detector::detectMedia( array( $post ) ),
			'external' => array(),
		);

		$structural = StructuralExtractor::extract( $detected );
		Db::batchUpsertNodes( $structural['nodes'] );
		Db::batchUpsertEdges( $structural['edges'] );
	}

	// ─── Degree recalculation ──────────────────────────────────

	/**
	 * Recalculate degree counts for every node.
	 *
	 * @return void
	 */
	private static function recalculateAllDegrees(): void {
		global $wpdb;
		$nodesTable = Db::nodesTable();
		$edgesTable = Db::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"UPDATE {$nodesTable} n
             SET n.degree = (
                 SELECT COUNT(*) FROM {$edgesTable} e
                 WHERE e.source_node_id = n.node_id OR e.target_node_id = n.node_id
             )"
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
