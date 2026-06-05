<?php
/**
 * Report generator — structured knowledge-graph reports with caching.
 *
 * Assembles a report from analyzer output and caches it as a 1-hour transient.
 * Supports Markdown export and AI question generation.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Graph\Analyzer;

/**
 * Generates and caches knowledge graph analysis reports.
 *
 * @since 1.0.0
 */
final class Report
{
    /**
     * Transient key for the cached report.
     *
     * @var string
     */
    public const CACHE_KEY = 'nvoos_graphify_report';

    /**
     * Cache TTL in seconds (1 hour).
     *
     * @var int
     */
    public const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Return the full graph report (from cache if available).
     *
     * @since 1.0.0
     * @param bool $forceRebuild Force regeneration even if cached.
     * @return array<string,mixed> Report data.
     */
    public static function get(bool $forceRebuild = false): array
    {
        if (! $forceRebuild) {
            $cached = get_transient(self::CACHE_KEY);
            if (false !== $cached) {
                return $cached;
            }
        }

        $report = self::build();
        set_transient(self::CACHE_KEY, $report, self::CACHE_TTL);
        return $report;
    }

    /**
     * Build the report data structure.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public static function build(): array
    {
        $stats      = Db::getStats();
        $godNodes   = Analyzer::getGodNodes(10);
        $surprising = Analyzer::getSurprisingConnections(10);
        $gaps       = Analyzer::getKnowledgeGaps();
        $recommends = Analyzer::getRecommendations(10);
        $buildMeta  = Db::getMeta('last_build_completed', 'never');

        // Build community index.
        $communities = array();
        if (! empty($stats['nodes_by_type'])) {
            global $wpdb;
            $nodesTable    = Db::nodesTable();
            $communityRows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT community_id, COUNT(*) AS cnt FROM %i WHERE community_id != %s GROUP BY community_id ORDER BY cnt DESC LIMIT 20',
                    $nodesTable,
                    ''
                ),
                ARRAY_A
            );
            $communities   = is_array($communityRows) ? $communityRows : array();
        }

        // Generate AI questions.
        $questions = self::generateQuestions($stats, $godNodes, $gaps);

        return array(
            'generated_at'    => gmdate('Y-m-d H:i:s'),
            'last_build'      => $buildMeta,
            'stats'           => $stats,
            'god_nodes'       => $godNodes,
            'surprising'      => $surprising,
            'communities'     => $communities,
            'gaps'            => $gaps,
            'recommendations' => $recommends,
            'questions'       => $questions,
        );
    }

    /**
     * Export the report as a Markdown string.
     *
     * @since 1.0.0
     * @param array<string,mixed> $report Report data (from get() or build()).
     * @return string Markdown text.
     */
    public static function toMarkdown(array $report): string
    {
        $md  = "# Knowledge Graph Report\n\n";
        $md .= '_Generated: ' . esc_html($report['generated_at']) . ' — Last build: ' . esc_html((string) $report['last_build']) . "_\n\n";

        // Stats.
        $md .= "## Summary Statistics\n\n";
        $md .= '| Metric | Count |' . "\n";
        $md .= '|--------|-------|' . "\n";
        $md .= '| Nodes | ' . (int) $report['stats']['node_count'] . " |\n";
        $md .= '| Edges | ' . (int) $report['stats']['edge_count'] . " |\n";
        $md .= '| Communities | ' . (int) $report['stats']['community_count'] . " |\n\n";

        // God nodes.
        if (! empty($report['god_nodes'])) {
            $md .= "## Content Pillars (God Nodes)\n\n";
            foreach ($report['god_nodes'] as $n) {
                $label = is_object($n) ? esc_html($n->label) : esc_html($n['label']);
                $deg   = is_object($n) ? (int) $n->degree : (int) $n['degree'];
                $md   .= "- **{$label}** ({$deg} connections)\n";
            }
            $md .= "\n";
        }

        // Communities.
        if (! empty($report['communities'])) {
            $md .= "## Topic Communities\n\n";
            foreach ($report['communities'] as $c) {
                $md .= '- Community `' . esc_html($c['community_id']) . '`: ' . (int) $c['cnt'] . " nodes\n";
            }
            $md .= "\n";
        }

        // Surprising connections.
        if (! empty($report['surprising'])) {
            $md .= "## Surprising Connections\n\n";
            foreach ($report['surprising'] as $edge) {
                $score = round((float) $edge['surprise_score'], 3);
                $md   .= "- `{$edge['source_node_id']}` → `{$edge['target_node_id']}` via _{$edge['relation']}_ (score: {$score})\n";
            }
            $md .= "\n";
        }

        // Knowledge gaps.
        $md .= "## Knowledge Gaps\n\n";
        $md .= '- Orphan nodes: ' . count($report['gaps']['orphans']) . "\n";
        $md .= '- Thin communities: ' . count($report['gaps']['thin_communities']) . "\n";
        $md .= '- Ambiguity rate: ' . round((float) $report['gaps']['ambiguity_rate'] * 100, 1) . "%\n\n";

        // Recommendations.
        if (! empty($report['recommendations'])) {
            $md .= "## Recommendations\n\n";
            foreach ($report['recommendations'] as $rec) {
                $md .= '- ' . esc_html($rec['message']) . "\n";
            }
            $md .= "\n";
        }

        // Questions.
        if (! empty($report['questions'])) {
            $md .= "## AI-Generated Questions to Explore\n\n";
            foreach ($report['questions'] as $q) {
                $md .= '- ' . esc_html($q) . "\n";
            }
        }

        return $md;
    }

    // ─── Question generation ────────────────────────────────────

    /**
     * Generate contextual questions an AI can answer using the knowledge graph.
     *
     * @since 1.0.0
     * @param array<string,mixed> $stats     Graph stats.
     * @param array               $godNodes  God node rows.
     * @param array<string,mixed> $gaps      Knowledge gap data.
     * @return string[]
     */
    private static function generateQuestions(array $stats, array $godNodes, array $gaps): array
    {
        $questions = array(
            __('Which content is most central to this knowledge graph?', 'nvoos-graphify'),
            __('What topic clusters exist in this site\'s content?', 'nvoos-graphify'),
            __('Which content pieces are isolated (no connections)?', 'nvoos-graphify'),
            __('What internal links are missing between related content?', 'nvoos-graphify'),
        );

        if (! empty($godNodes)) {
            $top         = is_object($godNodes[0]) ? $godNodes[0]->label : $godNodes[0]['label'];
            $questions[] = sprintf(
                /* translators: %s: top god node label */
                __('What content is connected to "%s" in the knowledge graph?', 'nvoos-graphify'),
                sanitize_text_field($top)
            );
        }

        if ($gaps['ambiguity_rate'] > 0.2) {
            $questions[] = __('Which relationships in the knowledge graph need human review?', 'nvoos-graphify');
        }

        if ($stats['community_count'] > 0) {
            $questions[] = __('Can you describe the main topic communities in this site\'s content?', 'nvoos-graphify');
        }

        return $questions;
    }
}
