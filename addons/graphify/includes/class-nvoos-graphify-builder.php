<?php
/**
 * NV oOS Graphify — Graph Builder
 *
 * Coordinates the full extraction pipeline:
 *   Detector → Structural Extractor → Semantic Extractor → DB (via batch upserts)
 *
 * Deduplicates nodes by node_id and edges by (source, target, relation),
 * keeping the highest-confidence version per provenance priority:
 *   EXTRACTED (1.0) > INFERRED (variable) > AMBIGUOUS (lowest)
 *
 * After all writes, recalculates degree counts for every affected node
 * and triggers community detection via the Analyzer.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates graph construction from WordPress content.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Builder {

	/**
	 * Run a full or incremental graph build.
	 *
	 * @since 0.5.0
	 *
	// phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop -- Nested parameter documentation uses { syntax.
	 * @param array $args {
	 *     @type bool   $incremental    Only process content changed since last build.
	 *     @type bool   $semantic       Whether to run semantic extraction.
	 *     @type bool   $async_semantic Dispatch semantic extraction to WP Cron.
	 *     @type bool   $reset          Truncate existing graph first.
	 * }
	 * @return array Result summary.
	 */
	public static function build( array $args = array() ) {
		$incremental    = ! empty( $args['incremental'] );
		$semantic       = isset( $args['semantic'] ) ? (bool) $args['semantic'] : true;
		$async_semantic = ! empty( $args['async_semantic'] );
		$reset          = ! empty( $args['reset'] );

		NV_oOS_Graphify_DB::set_meta( 'build_status', 'running' );
		NV_oOS_Graphify_DB::set_meta( 'build_started', gmdate( 'Y-m-d H:i:s' ) );

		if ( $reset && ! $incremental ) {
			NV_oOS_Graphify_DB::truncate_edges();
			NV_oOS_Graphify_DB::truncate_nodes();
		}

		// 1. Detect content.
		$detected = NV_oOS_Graphify_Detector::detect( $incremental );

		$post_count              = count( $detected['posts'] );
		$ccts_detected           = isset( $detected['ccts'] ) ? count( (array) $detected['ccts'] ) : 0;
		$terms_detected          = isset( $detected['terms'] ) ? count( (array) $detected['terms'] ) : 0;
		$users_detected          = isset( $detected['users'] ) ? count( (array) $detected['users'] ) : 0;
		$media_detected          = isset( $detected['media'] ) ? count( (array) $detected['media'] ) : 0;
		$external_detected       = isset( $detected['external'] ) ? count( (array) $detected['external'] ) : 0;
		$ccts_skipped_reason     = NV_oOS_Graphify_Detector::get_last_ccts_skip_reason();
		$external_skipped_reason = NV_oOS_Graphify_Detector::get_last_external_skip_reason();

		// 2. Structural extraction.
		$structural = NV_oOS_Graphify_Structural_Extractor::extract( $detected );
		$node_count = NV_oOS_Graphify_DB::batch_upsert_nodes( $structural['nodes'] );
		$edge_count = NV_oOS_Graphify_DB::batch_upsert_edges( $structural['edges'] );

		// 3. Semantic extraction (optional).
		$semantic_nodes = 0;
		$semantic_edges = 0;
		$has_semantic   = class_exists( 'NV_oOS_Graphify_Semantic_Extractor' );

		if ( $semantic && $has_semantic && ! empty( $detected['posts'] ) ) {
			$sem_result = NV_oOS_Graphify_Semantic_Extractor::extract( $detected['posts'], $async_semantic );
			if ( ! $async_semantic ) {
				$semantic_nodes = NV_oOS_Graphify_DB::batch_upsert_nodes( $sem_result['nodes'] );
				$semantic_edges = NV_oOS_Graphify_DB::batch_upsert_edges( $sem_result['edges'] );
			}
		}

		// 3b. Semantic extraction for JetEngine CCT items (same gating).
		if ( $semantic && $has_semantic && ! empty( $detected['ccts'] ) ) {
			$sem_cct_result = NV_oOS_Graphify_Semantic_Extractor::extract_ccts( $detected['ccts'], $async_semantic );
			if ( ! $async_semantic ) {
				$semantic_nodes += NV_oOS_Graphify_DB::batch_upsert_nodes( $sem_cct_result['nodes'] );
				$semantic_edges += NV_oOS_Graphify_DB::batch_upsert_edges( $sem_cct_result['edges'] );
			}
		}

		// 3c. Semantic extraction for external $wpdb table rows.
		if ( $semantic && $has_semantic && ! empty( $detected['external'] )
			&& method_exists( 'NV_oOS_Graphify_Semantic_Extractor', 'extract_external' )
		) {
			$sem_ext_result = NV_oOS_Graphify_Semantic_Extractor::extract_external( $detected['external'], $async_semantic );
			if ( ! $async_semantic && is_array( $sem_ext_result ) ) {
				$semantic_nodes += isset( $sem_ext_result['nodes'] ) ? NV_oOS_Graphify_DB::batch_upsert_nodes( $sem_ext_result['nodes'] ) : 0;
				$semantic_edges += isset( $sem_ext_result['edges'] ) ? NV_oOS_Graphify_DB::batch_upsert_edges( $sem_ext_result['edges'] ) : 0;
			}
		}

		// 3.5. Remote enrichment (async; skipped when no sources are configured).
		$remote_nodes = 0;
		$remote_edges = 0;
		$settings     = NV_oOS_Graphify::get_settings();
		if ( ! empty( $settings['remote_enrich_enabled'] ) ) {
			$enricher       = new NV_oOS_Graphify_Remote_Enricher();
			$enrich_async   = ! empty( $settings['remote_enrich_async'] );
			$enrich_summary = $enricher->enrich_all( $enrich_async );
			if ( ! $enrich_async && is_array( $enrich_summary ) ) {
				$remote_nodes = isset( $enrich_summary['nodes'] ) ? (int) $enrich_summary['nodes'] : 0;
				$remote_edges = isset( $enrich_summary['edges'] ) ? (int) $enrich_summary['edges'] : 0;
			}
		}

		// 4. Recalculate degree counts for all nodes.
		self::recalculate_all_degrees();

		// 5. Community detection.
		NV_oOS_Graphify_Analyzer::detect_communities();

		// 6. Update build metadata.
		$completed = gmdate( 'Y-m-d H:i:s' );
		NV_oOS_Graphify_DB::set_meta( 'last_build_completed', $completed );
		NV_oOS_Graphify_DB::set_meta( 'build_status', 'idle' );

		// Invalidate report cache.
		delete_transient( 'nvoos_graphify_report' );

		$summary = array(
			'success'                 => true,
			'posts_processed'         => $post_count,
			'posts_detected'          => $post_count,
			'ccts_detected'           => $ccts_detected,
			'terms_detected'          => $terms_detected,
			'users_detected'          => $users_detected,
			'media_detected'          => $media_detected,
			'external_detected'       => $external_detected,
			'ccts_skipped_reason'     => $ccts_skipped_reason,
			'external_skipped_reason' => $external_skipped_reason,
			'nodes_upserted'          => $node_count,
			'edges_upserted'          => $edge_count,
			'semantic_nodes'          => $semantic_nodes,
			'semantic_edges'          => $semantic_edges,
			'async_semantic'          => $async_semantic,
			'remote_nodes'            => $remote_nodes,
			'remote_edges'            => $remote_edges,
			'build_completed'         => $completed,
		);

		NV_oOS_Graphify_DB::set_meta( 'last_build_summary', $summary );

		/**
		 * Fires after a graph build completes.
		 *
		 * @since 0.5.0
		 *
		 * @param array $summary Build result summary.
		 */
		do_action( 'nvoos_graphify_build_complete', $summary );

		return $summary;
	}

	// -------------------------------------------------------------------------
	// Degree recalculation
	// -------------------------------------------------------------------------

	/**
	 * Recalculate degree counts for every node.
	 *
	 * This is O(n) on the node count; acceptable for the sizes typical of a
	 * WordPress site (< 10 000 nodes). Runs after each build.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private static function recalculate_all_degrees() {
		global $wpdb;
		$nodes_table = NV_oOS_Graphify_DB::nodes_table();
		$edges_table = NV_oOS_Graphify_DB::edges_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$nodes_table} n
			 SET n.degree = (
			     SELECT COUNT(*) FROM {$edges_table} e
			     WHERE e.source_node_id = n.node_id OR e.target_node_id = n.node_id
			 )"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
