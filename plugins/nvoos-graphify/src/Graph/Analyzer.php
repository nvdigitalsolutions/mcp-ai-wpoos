<?php
/**
 * Graph Analyzer — higher-order analytics on node/edge data.
 *
 * Provides:
 *   - Louvain-inspired community detection with connected-components fallback
 *   - God nodes (top-N most-connected content pillars)
 *   - Surprising connections (cross-type/cross-community/peripheral-to-hub scoring)
 *   - Knowledge gaps (orphans, thin communities, ambiguity rate)
 *   - Content recommendations (missing intra-community links)
 *   - Shortest path (BFS between two nodes)
 *   - BFS/DFS subgraph traversal
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Graph\Db;

/**
 * Graph analytics engine.
 *
 * @since 1.0.0
 */
final class Analyzer
{
    /**
     * Community size threshold above which a community is split.
     * Expressed as a fraction of total nodes (e.g. 0.25 = 25%).
     *
     * @var float
     */
    public const OVERSIZED_THRESHOLD = 0.25;

    // ─── Community detection ────────────────────────────────────

    /**
     * Detect communities using a simplified Louvain-like algorithm and persist
     * the community_id assignment back to the nodes table.
     *
     * @since 1.0.0
     * @return int Number of communities detected.
     */
    public static function detectCommunities(): int
    {
        global $wpdb;
        $nodesTable = Db::nodesTable();
        $edgesTable = Db::edgesTable();

        // Load all nodes.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $nodes = $wpdb->get_results("SELECT node_id, degree FROM {$nodesTable}", ARRAY_A);
        $edges = $wpdb->get_results("SELECT source_node_id, target_node_id, confidence FROM {$edgesTable}", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($nodes)) {
            return 0;
        }

        // Use connected components for sparse graphs.
        if (count($edges) < 10) {
            $communities = self::connectedComponents($nodes, $edges);
        } else {
            $communities = self::louvain($nodes, $edges);
        }

        // Split oversized communities.
        $communities = self::splitOversized($communities, count($nodes));

        // Assign community labels (slug from highest-degree node label).
        $degreeMap = array();
        foreach ($nodes as $n) {
            $degreeMap[$n['node_id']] = (int) $n['degree'];
        }

        foreach ($communities as $communityId => $memberIds) {
            usort(
                $memberIds,
                function ($a, $b) use ($degreeMap): int {
                    return ($degreeMap[$b] ?? 0) - ($degreeMap[$a] ?? 0);
                }
            );
            foreach ($memberIds as $nodeId) {
                Db::setCommunity($nodeId, sanitize_key($communityId));
            }
        }

        Db::setMeta('community_count', count($communities));

        return count($communities);
    }

    /**
     * Simple connected-components partitioning (Union-Find).
     *
     * @since 1.0.0
     * @param array<int,array<string,mixed>> $nodes All nodes.
     * @param array<int,array<string,mixed>> $edges All edges.
     * @return array<string,string[]> community_id => [node_id, ...]
     */
    private static function connectedComponents(array $nodes, array $edges): array
    {
        $parent = array();
        foreach ($nodes as $n) {
            $parent[$n['node_id']] = $n['node_id'];
        }

        $find = static function ($x) use (&$parent, &$find) {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };

        $union = static function ($a, $b) use (&$parent, &$find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        foreach ($edges as $edge) {
            if (isset($parent[$edge['source_node_id']]) && isset($parent[$edge['target_node_id']])) {
                $union($edge['source_node_id'], $edge['target_node_id']);
            }
        }

        $communities = array();
        foreach ($nodes as $n) {
            $root = $find($n['node_id']);
            $communities['c_' . substr(md5($root), 0, 8)][] = $n['node_id'];
        }
        return $communities;
    }

    /**
     * Simplified Louvain modularity maximisation.
     *
     * @since 1.0.0
     * @param array<int,array<string,mixed>> $nodes All nodes.
     * @param array<int,array<string,mixed>> $edges All edges.
     * @return array<string,string[]>
     */
    private static function louvain(array $nodes, array $edges): array
    {
        // Build adjacency map.
        $adj         = array();
        $totalWeight = 0.0;
        foreach ($nodes as $n) {
            $adj[$n['node_id']] = array();
        }
        foreach ($edges as $edge) {
            $w = (float) $edge['confidence'];
            $s = $edge['source_node_id'];
            $t = $edge['target_node_id'];
            if (isset($adj[$s])) {
                $adj[$s][$t] = isset($adj[$s][$t]) ? $adj[$s][$t] + $w : $w;
            }
            if (isset($adj[$t])) {
                $adj[$t][$s] = isset($adj[$t][$s]) ? $adj[$t][$s] + $w : $w;
            }
            $totalWeight += $w;
        }

        if ($totalWeight <= 0) {
            return self::connectedComponents($nodes, $edges);
        }

        // Initialize: each node is its own community.
        $community = array();
        foreach ($nodes as $n) {
            $community[$n['node_id']] = $n['node_id'];
        }

        $nodeStrength = array();
        foreach ($adj as $nid => $neighbors) {
            $nodeStrength[$nid] = array_sum($neighbors);
        }

        $improved = true;
        $maxIter  = 20;
        $iter     = 0;
        while ($improved && $iter < $maxIter) {
            $improved = false;
            ++$iter;
            $nodeIds = array_keys($adj);
            shuffle($nodeIds);

            foreach ($nodeIds as $nid) {
                $currentComm = $community[$nid];
                $neighbors   = $adj[$nid];

                if (empty($neighbors)) {
                    continue;
                }

                $commWeights = array();
                foreach ($neighbors as $nbr => $w) {
                    if (! isset($community[$nbr])) {
                        continue;
                    }
                    $c                   = $community[$nbr];
                    $commWeights[$c]     = isset($commWeights[$c]) ? $commWeights[$c] + $w : $w;
                }

                if (empty($commWeights)) {
                    continue;
                }

                arsort($commWeights);
                $bestComm = key($commWeights);

                if ($bestComm !== $currentComm) {
                    $community[$nid] = $bestComm;
                    $improved        = true;
                }
            }
        }

        // Group by community label.
        $result = array();
        foreach ($community as $nid => $cid) {
            $commKey              = 'c_' . substr(md5($cid), 0, 8);
            $result[$commKey][]   = $nid;
        }
        return $result;
    }

    /**
     * Split any community that exceeds OVERSIZED_THRESHOLD fraction of total nodes.
     *
     * @since 1.0.0
     * @param array<string,string[]> $communities Existing communities.
     * @param int                    $totalNodes  Total node count.
     * @return array<string,string[]>
     */
    private static function splitOversized(array $communities, int $totalNodes): array
    {
        if ($totalNodes <= 0) {
            return $communities;
        }

        $result    = array();
        $threshold = (int) ceil($totalNodes * self::OVERSIZED_THRESHOLD);

        foreach ($communities as $cid => $members) {
            if (count($members) > $threshold) {
                $chunks = array_chunk($members, (int) ceil(count($members) / 2));
                foreach ($chunks as $i => $chunk) {
                    $result[$cid . '_s' . $i] = $chunk;
                }
            } else {
                $result[$cid] = $members;
            }
        }
        return $result;
    }

    // ─── God nodes ──────────────────────────────────────────────

    /**
     * Return the top-N nodes by degree (content pillars).
     *
     * @since 1.0.0
     * @param int    $limit Number of god nodes (default 10).
     * @param string $type  Optional node type filter.
     * @return array<int,object>
     */
    public static function getGodNodes(int $limit = 10, string $type = ''): array
    {
        return Db::listNodes(
            array(
                'limit'    => absint($limit),
                'order_by' => 'degree',
                'order'    => 'DESC',
                'type'     => $type,
            )
        );
    }

    // ─── Surprising connections ─────────────────────────────────

    /**
     * Return edges that are "surprising" — high-confidence but cross-type
     * or cross-community, or connecting peripheral nodes to hubs.
     *
     * @since 1.0.0
     * @param int $limit Max edges to return (default 10).
     * @return array<int,array<string,mixed>> Edge arrays with 'surprise_score' key.
     */
    public static function getSurprisingConnections(int $limit = 10): array
    {
        global $wpdb;
        $edgesTable = Db::edgesTable();
        $nodesTable = Db::nodesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $edges = $wpdb->get_results(
            "SELECT e.*,
                    sn.type AS source_type, sn.community_id AS source_comm, sn.degree AS source_degree,
                    tn.type AS target_type, tn.community_id AS target_comm, tn.degree AS target_degree
             FROM {$edgesTable} e
             JOIN {$nodesTable} sn ON sn.node_id = e.source_node_id
             JOIN {$nodesTable} tn ON tn.node_id = e.target_node_id
             WHERE e.provenance = 'INFERRED'
             LIMIT 500",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($edges)) {
            return array();
        }

        // Compute average degree for peripheral threshold.
        $avgDegree = 0;
        foreach ($edges as $e) {
            $avgDegree += (float) $e['source_degree'] + (float) $e['target_degree'];
        }
        $avgDegree = count($edges) > 0 ? $avgDegree / (2 * count($edges)) : 1;

        foreach ($edges as &$edge) {
            $score = (float) $edge['confidence'];

            // Cross-type bonus.
            if ($edge['source_type'] !== $edge['target_type']) {
                $score *= 1.3;
            }

            // Cross-community bonus.
            if ($edge['source_comm'] && $edge['target_comm'] && $edge['source_comm'] !== $edge['target_comm']) {
                $score *= 1.4;
            }

            // Peripheral-to-hub bonus.
            $sDeg = (float) $edge['source_degree'];
            $tDeg = (float) $edge['target_degree'];
            if (($sDeg < $avgDegree * 0.5 && $tDeg > $avgDegree * 2)
                || ($tDeg < $avgDegree * 0.5 && $sDeg > $avgDegree * 2)
            ) {
                $score *= 1.2;
            }

            $edge['surprise_score'] = round($score, 4);
        }
        unset($edge);

        usort(
            $edges,
            function ($a, $b): int {
                return $a['surprise_score'] < $b['surprise_score'] ? 1 : -1;
            }
        );

        return array_slice($edges, 0, absint($limit));
    }

    // ─── Knowledge gaps ─────────────────────────────────────────

    /**
     * Identify knowledge gaps: orphan nodes, thin communities, high ambiguity.
     *
     * @since 1.0.0
     * @return array{orphans: array, thin_communities: array, ambiguity_rate: float}
     */
    public static function getKnowledgeGaps(): array
    {
        global $wpdb;
        $nodesTable = Db::nodesTable();
        $edgesTable = Db::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orphans = $wpdb->get_results(
            "SELECT node_id, label, type FROM {$nodesTable} WHERE degree = 0 LIMIT 50",
            ARRAY_A
        );

        $thin = $wpdb->get_results(
            "SELECT community_id, COUNT(*) AS cnt FROM {$nodesTable}
             WHERE community_id != ''
             GROUP BY community_id HAVING cnt <= 2",
            ARRAY_A
        );

        $total     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$edgesTable}");
        $ambiguous = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$edgesTable} WHERE provenance = 'AMBIGUOUS'");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $ambiguityRate = $total > 0 ? round($ambiguous / $total, 4) : 0.0;

        return array(
            'orphans'          => $orphans,
            'thin_communities' => $thin,
            'ambiguity_rate'   => $ambiguityRate,
        );
    }

    // ─── Content recommendations ────────────────────────────────

    /**
     * Generate content recommendations: missing intra-community links.
     *
     * @since 1.0.0
     * @param int $limit Max recommendations (default 10).
     * @return array<int,array<string,mixed>>
     */
    public static function getRecommendations(int $limit = 10): array
    {
        global $wpdb;
        $nodesTable = Db::nodesTable();
        $edgesTable = Db::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidates = $wpdb->get_results(
            "SELECT a.node_id AS a_id, a.label AS a_label, b.node_id AS b_id, b.label AS b_label,
                    a.community_id AS community_id
             FROM {$nodesTable} a
             JOIN {$nodesTable} b ON b.community_id = a.community_id AND b.node_id > a.node_id
             WHERE a.community_id != ''
               AND a.post_id > 0
               AND b.post_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM {$edgesTable} e
                   WHERE (e.source_node_id = a.node_id AND e.target_node_id = b.node_id)
                      OR (e.source_node_id = b.node_id AND e.target_node_id = a.node_id)
               )
             LIMIT 200",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $recommendations = array();
        foreach ($candidates as $pair) {
            $recommendations[] = array(
                'type'         => 'missing_link',
                'message'      => sprintf(
                    /* translators: 1: source node label, 2: target node label */
                    __('Consider linking "%1$s" to "%2$s" — they share a knowledge community.', 'nvoos-graphify'),
                    esc_html($pair['a_label']),
                    esc_html($pair['b_label'])
                ),
                'source_node'  => $pair['a_id'],
                'target_node'  => $pair['b_id'],
                'community_id' => $pair['community_id'],
            );
            if (count($recommendations) >= absint($limit)) {
                break;
            }
        }

        return $recommendations;
    }

    // ─── Shortest path (BFS) ────────────────────────────────────

    /**
     * Find the shortest path between two nodes using BFS.
     *
     * @since 1.0.0
     * @param string $startNodeId Source node identifier.
     * @param string $endNodeId   Target node identifier.
     * @param int    $maxDepth    Maximum traversal depth (default 6).
     * @return string[]|null Array of node_ids forming the path, or null.
     */
    public static function shortestPath(string $startNodeId, string $endNodeId, int $maxDepth = 6): ?array
    {
        $start = sanitize_text_field($startNodeId);
        $end   = sanitize_text_field($endNodeId);

        if ($start === $end) {
            return array($start);
        }

        $queue   = array(array($start));
        $visited = array($start => true);

        while (! empty($queue)) {
            $path    = array_shift($queue);
            $current = end($path);

            if (count($path) > $maxDepth) {
                break;
            }

            $neighbors = Db::getNeighborIds($current);
            foreach ($neighbors as $neighbor) {
                if ($neighbor === $end) {
                    return array_merge($path, array($neighbor));
                }
                if (! isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[]            = array_merge($path, array($neighbor));
                }
            }
        }

        return null;
    }

    // ─── BFS / DFS subgraph traversal ───────────────────────────

    /**
     * Traverse the graph from a seed node and return a subgraph.
     *
     * @since 1.0.0
     * @param string $seedNodeId Starting node identifier.
     * @param int    $depth      Traversal depth (default 2).
     * @param string $mode       'bfs' or 'dfs' (default: 'bfs').
     * @param int    $maxNodes   Max nodes to return (default 50).
     * @return array{nodes: array, edges: array}
     */
    public static function traverse(string $seedNodeId, int $depth = 2, string $mode = 'bfs', int $maxNodes = 50): array
    {
        $seed     = sanitize_text_field($seedNodeId);
        $visited  = array();
        $nodeIds  = array();
        $edgeRows = array();

        if ('dfs' === $mode) {
            self::dfs($seed, absint($depth), $visited, $nodeIds, $edgeRows, absint($maxNodes));
        } else {
            self::bfs($seed, absint($depth), $visited, $nodeIds, $edgeRows, absint($maxNodes));
        }

        // Fetch full node rows.
        $nodes = array();
        foreach (array_unique($nodeIds) as $nid) {
            $node = Db::getNode($nid);
            if ($node) {
                $nodes[] = $node;
            }
        }

        return array(
            'nodes' => $nodes,
            'edges' => array_values($edgeRows),
        );
    }

    /**
     * BFS traversal helper.
     *
     * @since 1.0.0
     * @param string   $seed      Start node ID.
     * @param int      $depth     Max depth.
     * @param bool[]   $visited   (by ref) Visited set.
     * @param string[] $nodeIds   (by ref) Collected node IDs.
     * @param object[] $edges     (by ref) Collected edges.
     * @param int      $maxNodes  Max nodes.
     * @return void
     */
    private static function bfs(string $seed, int $depth, array &$visited, array &$nodeIds, array &$edges, int $maxNodes): void
    {
        $queue           = array(
            array(
                'id'    => $seed,
                'depth' => 0,
            ),
        );
        $visited[$seed]  = true;
        $nodeIds[]       = $seed;

        $nodeCount = count($nodeIds);
        while (! empty($queue) && $nodeCount < $maxNodes) {
            $item    = array_shift($queue);
            $current = $item['id'];
            $d       = $item['depth'];

            if ($d >= $depth) {
                continue;
            }

            $edgeRows = Db::getEdgesForNode($current);
            foreach ($edgeRows as $edge) {
                $edgeKey           = $edge->source_node_id . '|' . $edge->target_node_id . '|' . $edge->relation;
                $edges[$edgeKey]   = $edge;

                $neighbor = ($edge->source_node_id === $current) ? $edge->target_node_id : $edge->source_node_id;
                if (! isset($visited[$neighbor]) && $nodeCount < $maxNodes) {
                    $visited[$neighbor] = true;
                    $nodeIds[]          = $neighbor;
                    $nodeCount          = count($nodeIds);
                    $queue[]            = array(
                        'id'    => $neighbor,
                        'depth' => $d + 1,
                    );
                }
            }
        }
    }

    /**
     * DFS traversal helper (recursive).
     *
     * @since 1.0.0
     * @param string   $nodeId   Current node ID.
     * @param int      $depth    Remaining depth.
     * @param bool[]   $visited  (by ref) Visited set.
     * @param string[] $nodeIds  (by ref) Collected node IDs.
     * @param object[] $edges    (by ref) Collected edges.
     * @param int      $maxNodes Max nodes.
     * @return void
     */
    private static function dfs(string $nodeId, int $depth, array &$visited, array &$nodeIds, array &$edges, int $maxNodes): void
    {
        if (isset($visited[$nodeId]) || count($nodeIds) >= $maxNodes) {
            return;
        }

        $visited[$nodeId] = true;
        $nodeIds[]        = $nodeId;

        if ($depth <= 0) {
            return;
        }

        $edgeRows = Db::getEdgesForNode($nodeId);
        foreach ($edgeRows as $edge) {
            $edgeKey           = $edge->source_node_id . '|' . $edge->target_node_id . '|' . $edge->relation;
            $edges[$edgeKey]   = $edge;
            $neighbor          = ($edge->source_node_id === $nodeId) ? $edge->target_node_id : $edge->source_node_id;
            self::dfs($neighbor, $depth - 1, $visited, $nodeIds, $edges, $maxNodes);
        }
    }
}
