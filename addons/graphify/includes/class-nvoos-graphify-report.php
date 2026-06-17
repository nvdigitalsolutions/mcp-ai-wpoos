<?php
/**
 * NV oOS Graphify — Report Generator
 *
 * Assembles a structured knowledge-graph report from analyzer output and
 * caches it as a 1-hour transient. Supports Markdown export.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and caches knowledge graph analysis reports.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Report {

	/**
	 * Transient key for the cached report.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'nvoos_graphify_report';

	/**
	 * Cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Return the full graph report (from cache if available).
	 *
	 * @since 0.5.0
	 *
	 * @param bool $force_rebuild Force regeneration even if cached.
	 * @return array Report data.
	 */
	public static function get( $force_rebuild = false ) {
		if ( ! $force_rebuild ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$report = self::build();
		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		return $report;
	}

	/**
	 * Build the report data structure.
	 *
	 * @since 0.5.0
	 *
	 * @return array
	 */
	public static function build() {
		$stats      = NV_oOS_Graphify_DB::get_stats();
		$god_nodes  = NV_oOS_Graphify_Analyzer::get_god_nodes( 10 );
		$surprising = NV_oOS_Graphify_Analyzer::get_surprising_connections( 10 );
		$gaps       = NV_oOS_Graphify_Analyzer::get_knowledge_gaps();
		$recommends = NV_oOS_Graphify_Analyzer::get_recommendations( 10 );
		$build_meta = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', 'never' );

		// Build community index.
		$communities = array();
		if ( ! empty( $stats['nodes_by_type'] ) ) {
			global $wpdb;
			$nodes_table = NV_oOS_Graphify_DB::nodes_table();
			// Use %i identifier placeholder (WP 6.2+) for safe table-name quoting.
			$community_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT community_id, COUNT(*) AS cnt FROM %i WHERE community_id != %s GROUP BY community_id ORDER BY cnt DESC LIMIT 20',
					$nodes_table,
					''
				),
				ARRAY_A
			);
			$communities    = is_array( $community_rows ) ? $community_rows : array();
		}

		// Generate AI questions.
		$questions = self::generate_questions( $stats, $god_nodes, $gaps );

		return array(
			'generated_at'    => gmdate( 'Y-m-d H:i:s' ),
			'last_build'      => $build_meta,
			'stats'           => $stats,
			'god_nodes'       => $god_nodes,
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
	 * @since 0.5.0
	 *
	 * @param array $report Report data (from get() or build()).
	 * @return string Markdown text.
	 */
	public static function to_markdown( array $report ) {
		$md  = "# Knowledge Graph Report\n\n";
		$md .= '_Generated: ' . esc_html( $report['generated_at'] ) . ' — Last build: ' . esc_html( $report['last_build'] ) . "_\n\n";

		// Stats.
		$md .= "## Summary Statistics\n\n";
		$md .= '| Metric | Count |' . "\n";
		$md .= '|--------|-------|' . "\n";
		$md .= '| Nodes | ' . intval( $report['stats']['node_count'] ) . " |\n";
		$md .= '| Edges | ' . intval( $report['stats']['edge_count'] ) . " |\n";
		$md .= '| Communities | ' . intval( $report['stats']['community_count'] ) . " |\n\n";

		// God nodes.
		if ( ! empty( $report['god_nodes'] ) ) {
			$md .= "## Content Pillars (God Nodes)\n\n";
			foreach ( $report['god_nodes'] as $n ) {
				$label = is_object( $n ) ? esc_html( $n->label ) : esc_html( $n['label'] );
				$deg   = is_object( $n ) ? intval( $n->degree ) : intval( $n['degree'] );
				$md   .= "- **{$label}** ({$deg} connections)\n";
			}
			$md .= "\n";
		}

		// Communities.
		if ( ! empty( $report['communities'] ) ) {
			$md .= "## Topic Communities\n\n";
			foreach ( $report['communities'] as $c ) {
				$md .= '- Community `' . esc_html( $c['community_id'] ) . '`: ' . intval( $c['cnt'] ) . " nodes\n";
			}
			$md .= "\n";
		}

		// Surprising connections.
		if ( ! empty( $report['surprising'] ) ) {
			$md .= "## Surprising Connections\n\n";
			foreach ( $report['surprising'] as $edge ) {
				$score = round( floatval( $edge['surprise_score'] ), 3 );
				$md   .= "- `{$edge['source_node_id']}` → `{$edge['target_node_id']}` via _{$edge['relation']}_ (score: {$score})\n";
			}
			$md .= "\n";
		}

		// Knowledge gaps.
		$md .= "## Knowledge Gaps\n\n";
		$md .= '- Orphan nodes: ' . count( $report['gaps']['orphans'] ) . "\n";
		$md .= '- Thin communities: ' . count( $report['gaps']['thin_communities'] ) . "\n";
		$md .= '- Ambiguity rate: ' . round( floatval( $report['gaps']['ambiguity_rate'] ) * 100, 1 ) . "%\n\n";

		// Recommendations.
		if ( ! empty( $report['recommendations'] ) ) {
			$md .= "## Recommendations\n\n";
			foreach ( $report['recommendations'] as $rec ) {
				$md .= '- ' . esc_html( $rec['message'] ) . "\n";
			}
			$md .= "\n";
		}

		// Questions.
		if ( ! empty( $report['questions'] ) ) {
			$md .= "## AI-Generated Questions to Explore\n\n";
			foreach ( $report['questions'] as $q ) {
				$md .= '- ' . esc_html( $q ) . "\n";
			}
		}

		return $md;
	}

	// -------------------------------------------------------------------------
	// Question generation
	// -------------------------------------------------------------------------

	/**
	 * Generate contextual questions an AI can answer using the knowledge graph.
	 *
	 * @since 0.5.0
	 *
	 * @param array $stats     Graph stats.
	 * @param array $god_nodes God node rows.
	 * @param array $gaps      Knowledge gap data.
	 * @return string[]
	 */
	private static function generate_questions( array $stats, array $god_nodes, array $gaps ) {
		$questions = array(
			__( 'Which content is most central to this knowledge graph?', 'nvoos-graphify' ),
			__( 'What topic clusters exist in this site\'s content?', 'nvoos-graphify' ),
			__( 'Which content pieces are isolated (no connections)?', 'nvoos-graphify' ),
			__( 'What internal links are missing between related content?', 'nvoos-graphify' ),
		);

		if ( ! empty( $god_nodes ) ) {
			$top         = is_object( $god_nodes[0] ) ? $god_nodes[0]->label : $god_nodes[0]['label'];
			$questions[] = sprintf(
				/* translators: %s: top god node label */
				__( 'What content is connected to "%s" in the knowledge graph?', 'nvoos-graphify' ),
				sanitize_text_field( $top )
			);
		}

		if ( $gaps['ambiguity_rate'] > 0.2 ) {
			$questions[] = __( 'Which relationships in the knowledge graph need human review?', 'nvoos-graphify' );
		}

		if ( $stats['community_count'] > 0 ) {
			$questions[] = __( 'Can you describe the main topic communities in this site\'s content?', 'nvoos-graphify' );
		}

		return $questions;
	}
}
