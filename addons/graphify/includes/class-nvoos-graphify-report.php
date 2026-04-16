<?php
/**
 * NV oOS Graphify Addon — Report Generation Engine
 *
 * Generates, caches, and exports structured knowledge graph reports
 * by aggregating data from the analyzer, cluster, and database classes.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report generation engine for the NV oOS Graphify knowledge graph.
 *
 * Orchestrates the analyzer and cluster classes to produce a comprehensive
 * graph report, caches the result as a WordPress transient, and provides
 * Markdown export.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Report {

	/**
	 * Cache expiration in seconds (1 hour).
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Transient key prefix for cached reports.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'nvoos_graphify_report_';

	/**
	 * Graph identifier.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private $graph_id;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $graph_id Knowledge graph identifier.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id = absint( $graph_id );
	}

	/**
	 * Generate a complete knowledge graph report.
	 *
	 * Aggregates data from the analyzer, cluster, and meta table into a
	 * structured array. The result is stored as a WordPress transient
	 * with a 1-hour expiration.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     Complete graph report.
	 *
	 *     @type string $generated_at  ISO datetime of generation.
	 *     @type int    $graph_id      Graph identifier.
	 *     @type array  $summary       Node/edge/community counts and build status.
	 *     @type array  $god_nodes     Top content pillars by degree.
	 *     @type array  $surprising    Cross-community or unexpected connections.
	 *     @type array  $communities   Detected communities with size and cohesion.
	 *     @type array  $knowledge_gaps Orphans, thin communities, ambiguous edges.
	 *     @type array  $recommendations Suggested links and content gaps.
	 *     @type array  $seo_insights  Topic clusters, cannibalization, orphan content.
	 *     @type array  $questions     AI-suggested questions about the graph.
	 * }
	 */
	public function generate() {
		$analyzer = new NV_oOS_Graphify_Analyzer( $this->graph_id );
		$cluster  = new NV_oOS_Graphify_Cluster( $this->graph_id );

		$summary         = $this->get_summary_stats();
		$god_nodes       = $analyzer->get_god_nodes( 10 );
		$surprising      = $analyzer->get_surprising_connections( 20 );
		$communities     = $cluster->get_communities();
		$knowledge_gaps  = $analyzer->get_knowledge_gaps();
		$recommendations = $analyzer->get_content_recommendations();
		$seo_insights    = $analyzer->get_seo_insights();

		$report = array(
			'generated_at'    => current_time( 'mysql' ),
			'graph_id'        => $this->graph_id,
			'summary'         => $summary,
			'god_nodes'       => $god_nodes,
			'surprising'      => $surprising,
			'communities'     => $communities,
			'knowledge_gaps'  => $knowledge_gaps,
			'recommendations' => $recommendations,
			'seo_insights'    => $seo_insights,
			'questions'       => $this->build_questions( $summary, $god_nodes, $knowledge_gaps, $communities ),
		);

		set_transient( self::TRANSIENT_PREFIX . $this->graph_id, $report, self::CACHE_EXPIRATION );

		return $report;
	}

	/**
	 * Return the cached report if available.
	 *
	 * @since 0.2.0
	 *
	 * @return array|null Cached report array or null when no cache exists.
	 */
	public function get_cached() {
		$cached = get_transient( self::TRANSIENT_PREFIX . $this->graph_id );

		if ( false === $cached ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Delete the cached report transient.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function invalidate() {
		delete_transient( self::TRANSIENT_PREFIX . $this->graph_id );
	}

	/**
	 * Convert the report to a Markdown string for export.
	 *
	 * Generates the report if no cached version exists, then formats
	 * every section as Markdown.
	 *
	 * @since 0.2.0
	 *
	 * @return string Markdown-formatted report.
	 */
	public function to_markdown() {
		$report = $this->get_cached();

		if ( null === $report ) {
			$report = $this->generate();
		}

		$lines = array();

		$lines[] = '# Knowledge Graph Report';
		$lines[] = '';
		$lines[] = sprintf( '*Generated: %s · Graph ID: %d*', esc_html( $report['generated_at'] ), $report['graph_id'] );
		$lines[] = '';

		// Summary.
		$lines[] = '## Summary';
		$lines[] = '';
		$lines[] = '| Metric | Value |';
		$lines[] = '|--------|-------|';

		$summary = $report['summary'];
		$lines[] = sprintf( '| Nodes | %d |', isset( $summary['node_count'] ) ? $summary['node_count'] : 0 );
		$lines[] = sprintf( '| Edges | %d |', isset( $summary['edge_count'] ) ? $summary['edge_count'] : 0 );
		$lines[] = sprintf( '| Communities | %d |', isset( $summary['community_count'] ) ? $summary['community_count'] : 0 );
		$lines[] = sprintf( '| Build Status | %s |', isset( $summary['build_status'] ) ? esc_html( $summary['build_status'] ) : 'unknown' );
		$lines[] = sprintf( '| Last Built | %s |', isset( $summary['last_built'] ) ? esc_html( $summary['last_built'] ) : 'never' );
		$lines[] = '';

		// God Nodes.
		$lines[] = '## Top Content Pillars (God Nodes)';
		$lines[] = '';

		if ( ! empty( $report['god_nodes'] ) ) {
			$rank = 1;
			foreach ( $report['god_nodes'] as $node ) {
				$label  = isset( $node['label'] ) ? esc_html( $node['label'] ) : 'unknown';
				$degree = isset( $node['degree'] ) ? (int) $node['degree'] : 0;
				$type   = isset( $node['node_type'] ) ? esc_html( $node['node_type'] ) : '';

				$lines[] = sprintf( '%d. **%s** — degree %d (%s)', $rank, $label, $degree, $type );
				++$rank;
			}
		} else {
			$lines[] = '*No god nodes detected.*';
		}
		$lines[] = '';

		// Communities.
		$lines[] = '## Communities';
		$lines[] = '';

		if ( ! empty( $report['communities'] ) ) {
			$lines[] = '| ID | Label | Size |';
			$lines[] = '|----|-------|------|';

			foreach ( $report['communities'] as $community ) {
				$lines[] = sprintf(
					'| %d | %s | %d |',
					isset( $community['id'] ) ? (int) $community['id'] : 0,
					isset( $community['label'] ) ? esc_html( $community['label'] ) : '',
					isset( $community['size'] ) ? (int) $community['size'] : 0
				);
			}
		} else {
			$lines[] = '*No communities detected.*';
		}
		$lines[] = '';

		// Surprising Connections.
		$lines[] = '## Surprising Connections';
		$lines[] = '';

		if ( ! empty( $report['surprising'] ) ) {
			foreach ( $report['surprising'] as $conn ) {
				$source   = $this->get_node_display_label( $conn, 'source' );
				$target   = $this->get_node_display_label( $conn, 'target' );
				$relation = isset( $conn['relation'] ) ? esc_html( $conn['relation'] ) : '';

				$lines[] = sprintf( '- **%s** → **%s** (%s)', $source, $target, $relation );
			}
		} else {
			$lines[] = '*No surprising connections found.*';
		}
		$lines[] = '';

		// Knowledge Gaps.
		$lines[] = '## Knowledge Gaps';
		$lines[] = '';

		$gaps = $report['knowledge_gaps'];

		$lines[] = '### Orphan Nodes';
		$lines[] = '';
		if ( ! empty( $gaps['orphan_nodes'] ) ) {
			foreach ( $gaps['orphan_nodes'] as $orphan ) {
				$lines[] = sprintf(
					'- %s (%s)',
					isset( $orphan['label'] ) ? esc_html( $orphan['label'] ) : 'unknown',
					isset( $orphan['node_type'] ) ? esc_html( $orphan['node_type'] ) : ''
				);
			}
		} else {
			$lines[] = '*None — all nodes are connected.*';
		}
		$lines[] = '';

		$lines[] = '### Thin Communities';
		$lines[] = '';
		if ( ! empty( $gaps['thin_communities'] ) ) {
			foreach ( $gaps['thin_communities'] as $thin ) {
				$lines[] = sprintf(
					'- Community %d: %d nodes',
					isset( $thin['community_id'] ) ? (int) $thin['community_id'] : 0,
					isset( $thin['size'] ) ? (int) $thin['size'] : 0
				);
			}
		} else {
			$lines[] = '*No thin communities detected.*';
		}
		$lines[] = '';

		$lines[] = '### Ambiguous Edges';
		$lines[] = '';
		if ( ! empty( $gaps['ambiguous_edges'] ) ) {
			$amb     = $gaps['ambiguous_edges'];
			$lines[] = sprintf(
				'- Total ambiguous edges: %d',
				isset( $amb['count'] ) ? (int) $amb['count'] : 0
			);
		} else {
			$lines[] = '*No ambiguous edges found.*';
		}
		$lines[] = '';

		// Recommendations.
		$lines[] = '## Recommendations';
		$lines[] = '';

		$recs = $report['recommendations'];

		$lines[] = '### Suggested Links';
		$lines[] = '';
		if ( ! empty( $recs['suggested_links'] ) ) {
			foreach ( $recs['suggested_links'] as $link ) {
				$from = $this->get_node_display_label( $link, 'source' );
				$to   = $this->get_node_display_label( $link, 'target' );

				$lines[] = sprintf( '- Link **%s** → **%s**', $from, $to );
			}
		} else {
			$lines[] = '*No link suggestions available.*';
		}
		$lines[] = '';

		$lines[] = '### Content Gaps';
		$lines[] = '';
		if ( ! empty( $recs['content_gaps'] ) ) {
			foreach ( $recs['content_gaps'] as $gap ) {
				$label   = isset( $gap['label'] ) ? esc_html( $gap['label'] ) : ( isset( $gap['community_id'] ) ? sprintf( 'Community %d', (int) $gap['community_id'] ) : 'unknown' );
				$lines[] = sprintf( '- %s', $label );
			}
		} else {
			$lines[] = '*No content gaps identified.*';
		}
		$lines[] = '';

		// SEO Insights.
		$lines[] = '## SEO Insights';
		$lines[] = '';

		$seo = $report['seo_insights'];

		$lines[] = '### Topic Clusters';
		$lines[] = '';
		if ( ! empty( $seo['topic_clusters'] ) ) {
			foreach ( $seo['topic_clusters'] as $tc ) {
				$lines[] = sprintf(
					'- **%s** — %d nodes, max degree %d',
					isset( $tc['label'] ) ? esc_html( $tc['label'] ) : 'unknown',
					isset( $tc['size'] ) ? (int) $tc['size'] : 0,
					isset( $tc['max_degree'] ) ? (int) $tc['max_degree'] : 0
				);
			}
		} else {
			$lines[] = '*No topic clusters found.*';
		}
		$lines[] = '';

		$lines[] = '### Cannibalization Risks';
		$lines[] = '';
		if ( ! empty( $seo['cannibalization'] ) ) {
			foreach ( $seo['cannibalization'] as $pair ) {
				$a = isset( $pair['node_a_label'] ) ? esc_html( $pair['node_a_label'] ) : 'unknown';
				$b = isset( $pair['node_b_label'] ) ? esc_html( $pair['node_b_label'] ) : 'unknown';

				$lines[] = sprintf( '- **%s** vs **%s**', $a, $b );
			}
		} else {
			$lines[] = '*No cannibalization risks detected.*';
		}
		$lines[] = '';

		$lines[] = '### Orphan Content';
		$lines[] = '';
		if ( ! empty( $seo['orphan_content'] ) ) {
			foreach ( $seo['orphan_content'] as $orphan ) {
				$lines[] = sprintf(
					'- %s',
					isset( $orphan['label'] ) ? esc_html( $orphan['label'] ) : 'unknown'
				);
			}
		} else {
			$lines[] = '*No orphan content found.*';
		}
		$lines[] = '';

		// Suggested Questions.
		$lines[] = '## Suggested Questions';
		$lines[] = '';

		if ( ! empty( $report['questions'] ) ) {
			$qi = 1;
			foreach ( $report['questions'] as $question ) {
				$lines[] = sprintf( '%d. %s', $qi, esc_html( $question ) );
				++$qi;
			}
		} else {
			$lines[] = '*No questions generated.*';
		}
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Get summary statistics from the graph meta table.
	 *
	 * This is a lightweight query suitable for admin dashboard widgets
	 * that does not require the analyzer or cluster classes.
	 *
	 * @since 0.2.0
	 *
	 * @return array {
	 *     Summary statistics.
	 *
	 *     @type int    $node_count      Total graph nodes.
	 *     @type int    $edge_count      Total graph edges.
	 *     @type int    $community_count Number of detected communities.
	 *     @type string $build_status    Current build status.
	 *     @type string $last_built      Datetime of last build.
	 * }
	 */
	public function get_summary_stats() {
		global $wpdb;

		$meta_table = NV_oOS_Graphify_Database::get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_count, edge_count, community_count, build_status, last_built FROM {$meta_table} WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->graph_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return array(
				'node_count'      => 0,
				'edge_count'      => 0,
				'community_count' => 0,
				'build_status'    => 'idle',
				'last_built'      => '',
			);
		}

		return array(
			'node_count'      => (int) $row['node_count'],
			'edge_count'      => (int) $row['edge_count'],
			'community_count' => (int) $row['community_count'],
			'build_status'    => sanitize_text_field( $row['build_status'] ),
			'last_built'      => sanitize_text_field( $row['last_built'] ),
		);
	}

	/**
	 * Get a display label for a node entry, falling back to the node ID.
	 *
	 * Looks for `{prefix}_label` first, then `{prefix}_node_id`, and
	 * returns 'unknown' if neither key exists.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $item   Data array containing label/node_id keys.
	 * @param string $prefix Key prefix, e.g. 'source' or 'target'.
	 * @return string Escaped display label.
	 */
	private function get_node_display_label( $item, $prefix ) {
		$label_key = $prefix . '_label';
		$id_key    = $prefix . '_node_id';

		if ( isset( $item[ $label_key ] ) && '' !== $item[ $label_key ] ) {
			return esc_html( $item[ $label_key ] );
		}

		if ( isset( $item[ $id_key ] ) && '' !== $item[ $id_key ] ) {
			return esc_html( $item[ $id_key ] );
		}

		return 'unknown';
	}

	/**
	 * Build dynamic questions based on report data.
	 *
	 * Generates 5–8 contextual questions that an AI assistant could
	 * answer using the knowledge graph, derived from actual report data.
	 *
	 * @since 0.2.0
	 *
	 * @param array $summary    Summary statistics.
	 * @param array $god_nodes  Top content pillars.
	 * @param array $gaps       Knowledge gaps data.
	 * @param array $communities Community list.
	 * @return string[] List of question strings.
	 */
	private function build_questions( $summary, $god_nodes, $gaps, $communities ) {
		$questions = array();

		// Always include a structural overview question.
		$questions[] = sprintf(
			'How are the %d nodes and %d edges distributed across the knowledge graph?',
			isset( $summary['node_count'] ) ? (int) $summary['node_count'] : 0,
			isset( $summary['edge_count'] ) ? (int) $summary['edge_count'] : 0
		);

		// God-node-based questions.
		if ( ! empty( $god_nodes ) ) {
			$top       = reset( $god_nodes );
			$top_label = isset( $top['label'] ) ? $top['label'] : 'the top content pillar';

			$questions[] = sprintf(
				'What content is connected to "%s" and why is it the most central topic?',
				$top_label
			);

			if ( count( $god_nodes ) >= 3 ) {
				$labels = array();
				$count  = 0;
				foreach ( $god_nodes as $gn ) {
					if ( $count >= 3 ) {
						break;
					}
					$labels[] = isset( $gn['label'] ) ? $gn['label'] : '';
					++$count;
				}
				$questions[] = sprintf(
					'What are the main content pillars of this site and how do %s relate to each other?',
					implode( ', ', $labels )
				);
			}
		}

		// Community-based questions.
		if ( ! empty( $communities ) && count( $communities ) >= 2 ) {
			$questions[] = sprintf(
				'What themes do the %d content communities represent?',
				count( $communities )
			);

			$smallest = end( $communities );
			if ( isset( $smallest['label'] ) && isset( $smallest['size'] ) ) {
				$questions[] = sprintf(
					'Why is the "%s" community so small (%d nodes) and how could it be strengthened?',
					$smallest['label'],
					(int) $smallest['size']
				);
			}
		}

		// Gap-based questions.
		if ( ! empty( $gaps['orphan_nodes'] ) ) {
			$orphan_count = count( $gaps['orphan_nodes'] );
			$questions[]  = sprintf(
				'Which %d posts have no internal links and what topics could connect them?',
				$orphan_count
			);
		}

		if ( ! empty( $gaps['thin_communities'] ) ) {
			$questions[] = 'What topics are underrepresented in the knowledge graph?';
		}

		// Ensure we have at least 5 questions.
		$generic = array(
			'What content should be created next to fill knowledge gaps?',
			'Are there any SEO cannibalization risks between existing posts?',
			'Which internal linking opportunities would strengthen the site structure?',
		);

		foreach ( $generic as $q ) {
			if ( count( $questions ) >= 8 ) {
				break;
			}
			if ( ! in_array( $q, $questions, true ) ) {
				$questions[] = $q;
			}
		}

		return array_slice( $questions, 0, 8 );
	}
}
