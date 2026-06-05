<?php
/**
 * Graph builder — orchestrates the full extraction pipeline.
 *
 * Coordinates: Detector → StructuralExtractor → SemanticExtractor
 * → Remote Enricher → Db batch upserts → degree recalculation
 * → community detection.
 *
 * Deduplicates nodes by node_id and edges by (source, target, relation),
 * keeping the highest-confidence version per provenance priority:
 *   EXTRACTED (1.0) > INFERRED (variable) > AMBIGUOUS (lowest)
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Detector;
use NvoosGraphify\Graph\StructuralExtractor;
use NvoosGraphify\Settings;

/**
 * Orchestrates graph construction from WordPress content.
 *
 * @since 1.0.0
 */
final class Builder
{
    /**
     * Run a full or incremental graph build.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args {
     *     @type bool $incremental    Only process content changed since last build.
     *     @type bool $semantic       Whether to run semantic extraction.
     *     @type bool $async_semantic Dispatch semantic extraction to WP Cron.
     *     @type bool $reset          Truncate existing graph first.
     * }
     * @return array<string,mixed> Result summary.
     */
    public static function build(array $args = array()): array
    {
        $incremental    = ! empty($args['incremental']);
        $semantic       = isset($args['semantic']) ? (bool) $args['semantic'] : true;
        $asyncSemantic  = ! empty($args['async_semantic']);
        $reset          = ! empty($args['reset']);

        Db::setMeta('build_status', 'running');
        Db::setMeta('build_started', gmdate('Y-m-d H:i:s'));

        if ($reset && ! $incremental) {
            Db::truncateEdges();
            Db::truncateNodes();
        }

        // 1. Detect content.
        $detected = Detector::detect($incremental);

        $postCount         = count($detected['posts']);
        $cctsDetected      = isset($detected['ccts']) ? count((array) $detected['ccts']) : 0;
        $termsDetected     = isset($detected['terms']) ? count((array) $detected['terms']) : 0;
        $usersDetected     = isset($detected['users']) ? count((array) $detected['users']) : 0;
        $mediaDetected     = isset($detected['media']) ? count((array) $detected['media']) : 0;
        $externalDetected  = isset($detected['external']) ? count((array) $detected['external']) : 0;
        $cctsSkipReason    = Detector::getLastCctsSkipReason();
        $externalSkipReason = Detector::getLastExternalSkipReason();

        // 2. Structural extraction.
        $structural = StructuralExtractor::extract($detected);
        $nodeCount  = Db::batchUpsertNodes($structural['nodes']);
        $edgeCount  = Db::batchUpsertEdges($structural['edges']);

        // 3. Semantic extraction (optional, requires SemanticExtractor).
        $semanticNodes = 0;
        $semanticEdges = 0;
        if ($semantic && ! empty($detected['posts']) && class_exists('NvoosGraphify\\Graph\\SemanticExtractor')) {
            $semResult = SemanticExtractor::extract($detected['posts'], $asyncSemantic);
            if (! $asyncSemantic) {
                $semanticNodes = Db::batchUpsertNodes($semResult['nodes']);
                $semanticEdges = Db::batchUpsertEdges($semResult['edges']);
            }
        }

        // 3b. Semantic extraction for JetEngine CCT items.
        if ($semantic && ! empty($detected['ccts']) && class_exists('NvoosGraphify\\Graph\\SemanticExtractor')) {
            $semCctResult = SemanticExtractor::extractCcts($detected['ccts'], $asyncSemantic);
            if (! $asyncSemantic) {
                $semanticNodes += Db::batchUpsertNodes($semCctResult['nodes']);
                $semanticEdges += Db::batchUpsertEdges($semCctResult['edges']);
            }
        }

        // 3c. Semantic extraction for external $wpdb table rows.
        if ($semantic && ! empty($detected['external'])
            && class_exists('NvoosGraphify\\Graph\\SemanticExtractor')
            && method_exists('NvoosGraphify\\Graph\\SemanticExtractor', 'extractExternal')
        ) {
            $semExtResult = SemanticExtractor::extractExternal($detected['external'], $asyncSemantic);
            if (! $asyncSemantic && is_array($semExtResult)) {
                $semanticNodes += isset($semExtResult['nodes']) ? Db::batchUpsertNodes($semExtResult['nodes']) : 0;
                $semanticEdges += isset($semExtResult['edges']) ? Db::batchUpsertEdges($semExtResult['edges']) : 0;
            }
        }

        // 3.5. Remote enrichment (async; skipped when no sources are configured).
        $remoteNodes = 0;
        $remoteEdges = 0;
        $allSettings = Settings::all();
        if (! empty($allSettings['remote_enrich_enabled']) && class_exists('NvoosGraphify\\Remote\\Enricher')) {
            $enricher       = new \NvoosGraphify\Remote\Enricher();
            $enrichAsync    = ! empty($allSettings['remote_enrich_async']);
            $enrichSummary  = $enricher->enrichAll($enrichAsync);
            if (! $enrichAsync && is_array($enrichSummary)) {
                $remoteNodes = isset($enrichSummary['nodes']) ? (int) $enrichSummary['nodes'] : 0;
                $remoteEdges = isset($enrichSummary['edges']) ? (int) $enrichSummary['edges'] : 0;
            }
        }

        // 4. Recalculate degree counts for all nodes.
        self::recalculateAllDegrees();

        // 5. Community detection (requires Analyzer).
        if (class_exists('NvoosGraphify\\Graph\\Analyzer')) {
            Analyzer::detectCommunities();
        }

        // 6. Update build metadata.
        $completed = gmdate('Y-m-d H:i:s');
        Db::setMeta('last_build_completed', $completed);
        Db::setMeta('build_status', 'idle');

        // Invalidate report cache.
        delete_transient('nvoos_graphify_report');

        $summary = array(
            'success'                 => true,
            'posts_processed'         => $postCount,
            'posts_detected'          => $postCount,
            'ccts_detected'           => $cctsDetected,
            'terms_detected'          => $termsDetected,
            'users_detected'          => $usersDetected,
            'media_detected'          => $mediaDetected,
            'external_detected'       => $externalDetected,
            'ccts_skipped_reason'     => $cctsSkipReason,
            'external_skipped_reason' => $externalSkipReason,
            'nodes_upserted'          => $nodeCount,
            'edges_upserted'          => $edgeCount,
            'semantic_nodes'          => $semanticNodes,
            'semantic_edges'          => $semanticEdges,
            'async_semantic'          => $asyncSemantic,
            'remote_nodes'            => $remoteNodes,
            'remote_edges'            => $remoteEdges,
            'build_completed'         => $completed,
        );

        Db::setMeta('last_build_summary', $summary);

        /**
         * Fires after a graph build completes.
         *
         * @since 0.5.0
         * @param array<string,mixed> $summary Build result summary.
         */
        do_action('nvoos_graphify_build_complete', $summary);

        return $summary;
    }

    /**
     * Build a single post (called from save_post hook).
     *
     * @since 1.0.0
     * @param \WP_Post $post The post to build.
     * @return void
     */
    public static function buildPost(\WP_Post $post): void
    {
        $detected = array(
            'posts'    => array($post),
            'ccts'     => array(),
            'terms'    => Detector::detectTerms(array($post)),
            'users'    => Detector::detectUsers(array($post)),
            'media'    => Detector::detectMedia(array($post)),
            'external' => array(),
        );

        $structural = StructuralExtractor::extract($detected);
        Db::batchUpsertNodes($structural['nodes']);
        Db::batchUpsertEdges($structural['edges']);

        self::recalculateAllDegrees();

        // Invalidate report cache.
        delete_transient('nvoos_graphify_report');
    }

    // ─── Degree recalculation ───────────────────────────────────

    /**
     * Recalculate degree counts for every node.
     *
     * O(n) on node count; acceptable for sizes typical of a
     * WordPress site (< 10,000 nodes). Runs after each build.
     *
     * @since 1.0.0
     * @return void
     */
    private static function recalculateAllDegrees(): void
    {
        global $wpdb;
        $nodesTable = Db::nodesTable();
        $edgesTable = Db::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$nodesTable} n
             SET n.degree = (
                 SELECT COUNT(*) FROM {$edgesTable} e
                 WHERE e.source_node_id = n.node_id OR e.target_node_id = n.node_id
             )"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
