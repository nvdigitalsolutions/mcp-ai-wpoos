<?php

namespace App\Support;

/**
 * Save-time validation for a workflow graph.
 *
 * WordPress accepts any array on save (`WP_MCP_AI_Workflow_CPT::save_graph()`)
 * and leaves every problem to the client. The Laravel runtime validates
 * instead, because a graph that cannot be executed is worse than a rejected
 * save: H3's executor walks the graph, so a dangling edge or a cycle would
 * only surface at run time, after the user has left the page.
 *
 * The rules are the three structural ones, and nothing about node semantics —
 * the canvas owns the type vocabulary and round-trips unknown types through
 * `data.rawType`, so rejecting on `type` here would reject valid presets.
 *
 * Everything not named here is preserved byte-for-byte: node `position`,
 * `data`, edge ids and unknown keys all pass through untouched, so a saved
 * graph reloads identically.
 */
final class WorkflowGraphValidator
{
    /**
     * Validate and normalise a graph.
     *
     * @param mixed $graph Raw `graph` input.
     * @return array{errors: list<string>, graph: array{nodes: list<mixed>, edges: list<mixed>}}
     */
    public static function validate(mixed $graph): array
    {
        if (! is_array($graph)) {
            return [
                'errors' => ['The graph must be an object with `nodes` and `edges` arrays.'],
                'graph' => ['nodes' => [], 'edges' => []],
            ];
        }

        $nodes = array_values((array) ($graph['nodes'] ?? []));
        $edges = array_values((array) ($graph['edges'] ?? []));

        $errors = [];

        // 1. Every node carries a unique, non-empty id.
        $ids = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = sprintf('Node #%d is not an object.', $index + 1);
                continue;
            }

            $id = $node['id'] ?? null;

            if (! is_string($id) || '' === trim($id)) {
                $errors[] = sprintf('Node #%d has no id.', $index + 1);
                continue;
            }

            if (isset($ids[$id])) {
                $errors[] = sprintf('Duplicate node id "%s".', $id);
                continue;
            }

            $ids[$id] = true;
        }

        // 2. Every edge endpoint resolves. A graph with a bad node id cannot
        //    have meaningful edges, so stop here and report the nodes alone.
        if ([] === $errors) {
            foreach ($edges as $index => $edge) {
                if (! is_array($edge)) {
                    $errors[] = sprintf('Edge #%d is not an object.', $index + 1);
                    continue;
                }

                $source = $edge['source'] ?? null;
                $target = $edge['target'] ?? null;

                if (! is_string($source) || '' === $source || ! isset($ids[$source])) {
                    $errors[] = sprintf('Edge #%d references a missing source node.', $index + 1);
                }

                if (! is_string($target) || '' === $target || ! isset($ids[$target])) {
                    $errors[] = sprintf('Edge #%d references a missing target node.', $index + 1);
                }
            }
        }

        // 3. No cycle. Kahn's algorithm: repeatedly remove nodes with no
        //    unresolved incoming edge. Anything left over is in — or downstream
        //    of — a cycle, and the first such edge is named so the user knows
        //    where to look.
        if ([] === $errors && [] !== $nodes) {
            $indegree = array_fill_keys(array_keys($ids), 0);
            $outgoing = array_fill_keys(array_keys($ids), []);

            foreach ($edges as $edge) {
                $source = (string) $edge['source'];
                $target = (string) $edge['target'];

                ++$indegree[$target];
                $outgoing[$source][] = $target;
            }

            $queue = [];
            foreach ($indegree as $id => $degree) {
                if (0 === $degree) {
                    $queue[] = $id;
                }
            }

            $visited = 0;
            while ([] !== $queue) {
                $id = array_shift($queue);
                ++$visited;

                foreach ($outgoing[$id] as $target) {
                    --$indegree[$target];

                    if (0 === $indegree[$target]) {
                        $queue[] = $target;
                    }
                }
            }

            if ($visited < count($ids)) {
                $errors[] = self::describeCycle($edges, $indegree);
            }
        }

        return ['errors' => $errors, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
    }

    /**
     * Name an edge that participates in the cycle.
     *
     * A node with a non-zero indegree after Kahn's pass is either in the cycle
     * or downstream of it; its incoming edge is the most useful single line to
     * report.
     *
     * @param list<mixed>          $edges    Graph edges.
     * @param array<string, int>   $indegree Residual indegrees.
     */
    private static function describeCycle(array $edges, array $indegree): string
    {
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');

            if (($indegree[$target] ?? 0) > 0) {
                return sprintf('The graph contains a cycle (edge "%s" → "%s").', $source, $target);
            }
        }

        return 'The graph contains a cycle.';
    }
}
