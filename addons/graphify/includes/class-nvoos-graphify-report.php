<?php
/**
 * Graph analysis report generation.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Report
 *
 * Aggregates analyzer results into a structured report, caches it as
 * a WordPress option, and renders Markdown for human consumption.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Report {

	/**
	 * WordPress option key used for the cached report.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_graphify_report';

	/**
	 * Database table names.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tables = NV_oOS_Graphify_DB::get_table_names();
	}

	/**
	 * Generate a full analysis report for a graph.
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 * @return array The complete report data.
	 */
	public function generate( $graph_id = 1 ) {
		global $wpdb;

		$graph_id = absint( $graph_id );
		$analyzer = new NV_oOS_Graphify_Analyzer( $graph_id );

		// Graph meta.
		$meta = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_count, edge_count, community_count, last_built, build_status
				 FROM %i WHERE graph_id = %d',
				$this->tables['meta'],
				$graph_id
			),
			ARRAY_A
		);

		if ( ! is_array( $meta ) ) {
			$meta = array(
				'node_count'      => 0,
				'edge_count'      => 0,
				'community_count' => 0,
				'last_built'      => null,
				'build_status'    => 'idle',
			);
		}

		// Content type breakdown.
		$type_breakdown = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_type, COUNT(*) AS cnt FROM %i WHERE graph_id = %d GROUP BY node_type ORDER BY cnt DESC',
				$this->tables['nodes'],
				$graph_id
			),
			ARRAY_A
		);

		$type_map = array();
		if ( is_array( $type_breakdown ) ) {
			foreach ( $type_breakdown as $row ) {
				$type_map[ $row['node_type'] ] = (int) $row['cnt'];
			}
		}

		// Community details.
		$community_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT community_id, COUNT(*) AS size
				 FROM %i
				 WHERE graph_id = %d AND community_id IS NOT NULL
				 GROUP BY community_id
				 ORDER BY size DESC',
				$this->tables['nodes'],
				$graph_id
			),
			ARRAY_A
		);

		$communities = array();
		if ( is_array( $community_rows ) ) {
			foreach ( $community_rows as $cr ) {
				$label = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT label FROM %i WHERE graph_id = %d AND community_id = %d ORDER BY degree DESC LIMIT 1',
						$this->tables['nodes'],
						$graph_id,
						(int) $cr['community_id']
					)
				);

				$communities[] = array(
					'community_id' => (int) $cr['community_id'],
					'label'        => $label ? $label : __( 'Unnamed', 'nvoos-graphify' ),
					'size'         => (int) $cr['size'],
				);
			}
		}

		// Assemble report.
		$report = array(
			'generated_at'            => current_time( 'mysql', true ),
			'graph_id'                => $graph_id,
			'summary'                 => array(
				'node_count'          => (int) $meta['node_count'],
				'edge_count'          => (int) $meta['edge_count'],
				'community_count'     => (int) $meta['community_count'],
				'last_built'          => $meta['last_built'],
				'build_status'        => $meta['build_status'],
				'content_type_breakdown' => $type_map,
			),
			'god_nodes'               => $analyzer->get_god_nodes(),
			'surprising_connections'  => $analyzer->get_surprising_connections(),
			'communities'             => $communities,
			'knowledge_gaps'          => $analyzer->get_knowledge_gaps(),
			'seo_recommendations'     => $analyzer->get_seo_insights(),
		);

		// Cache.
		update_option( self::OPTION_KEY, $report, false );

		return $report;
	}

	/**
	 * Return the cached report, if any.
	 *
	 * @return array|null Cached report data or null.
	 */
	public function get_cached_report() {
		$report = get_option( self::OPTION_KEY, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Render the current report as a Markdown string.
	 *
	 * @return string Markdown-formatted report.
	 */
	public function render_markdown() {
		$report = $this->get_cached_report();
		if ( null === $report ) {
			return __( 'No report available. Run a graph build first.', 'nvoos-graphify' );
		}

		$lines = array();

		// ---- Title ---------------------------------------------------------
		$lines[] = '# Graphify Knowledge-Graph Report';
		$lines[] = '';
		$lines[] = sprintf(
			'*Generated: %s | Graph ID: %d*',
			esc_html( $report['generated_at'] ),
			(int) $report['graph_id']
		);
		$lines[] = '';

		// ---- Summary -------------------------------------------------------
		$summary = $report['summary'];
		$lines[] = '## Summary';
		$lines[] = '';
		$lines[] = sprintf( '- **Nodes:** %d', (int) $summary['node_count'] );
		$lines[] = sprintf( '- **Edges:** %d', (int) $summary['edge_count'] );
		$lines[] = sprintf( '- **Communities:** %d', (int) $summary['community_count'] );
		$lines[] = sprintf( '- **Last Built:** %s', $summary['last_built'] ? esc_html( $summary['last_built'] ) : 'Never' );
		$lines[] = sprintf( '- **Status:** %s', esc_html( $summary['build_status'] ) );

		if ( ! empty( $summary['content_type_breakdown'] ) ) {
			$lines[] = '';
			$lines[] = '### Content Types';
			$lines[] = '';
			$lines[] = '| Type | Count |';
			$lines[] = '|------|-------|';
			foreach ( $summary['content_type_breakdown'] as $type => $count ) {
				$lines[] = sprintf( '| %s | %d |', esc_html( $type ), (int) $count );
			}
		}

		// ---- God Nodes -----------------------------------------------------
		if ( ! empty( $report['god_nodes'] ) ) {
			$lines[] = '';
			$lines[] = '## Top Hub Nodes';
			$lines[] = '';
			$lines[] = '| Node | Type | Degree | Community |';
			$lines[] = '|------|------|--------|-----------|';
			foreach ( $report['god_nodes'] as $gn ) {
				$lines[] = sprintf(
					'| %s | %s | %d | %s |',
					esc_html( $gn['label'] ),
					esc_html( $gn['node_type'] ),
					(int) $gn['degree'],
					null !== $gn['community_id'] ? (int) $gn['community_id'] : '—'
				);
			}
		}

		// ---- Surprising Connections ----------------------------------------
		if ( ! empty( $report['surprising_connections'] ) ) {
			$lines[] = '';
			$lines[] = '## Surprising Connections';
			$lines[] = '';
			foreach ( $report['surprising_connections'] as $sc ) {
				$lines[] = sprintf(
					'- **%s** —[%s]→ **%s** (score: %.2f)',
					esc_html( $sc['source_label'] ),
					esc_html( $sc['relation'] ),
					esc_html( $sc['target_label'] ),
					floatval( $sc['surprise_score'] )
				);
			}
		}

		// ---- Communities ---------------------------------------------------
		if ( ! empty( $report['communities'] ) ) {
			$lines[] = '';
			$lines[] = '## Communities';
			$lines[] = '';
			$lines[] = '| ID | Label | Size |';
			$lines[] = '|----|-------|------|';
			foreach ( $report['communities'] as $comm ) {
				$lines[] = sprintf(
					'| %d | %s | %d |',
					(int) $comm['community_id'],
					esc_html( $comm['label'] ),
					(int) $comm['size']
				);
			}
		}

		// ---- Knowledge Gaps ------------------------------------------------
		if ( ! empty( $report['knowledge_gaps'] ) ) {
			$gaps    = $report['knowledge_gaps'];
			$lines[] = '';
			$lines[] = '## Knowledge Gaps';
			$lines[] = '';

			if ( ! empty( $gaps['orphan_nodes'] ) ) {
				$lines[] = sprintf( '- **Orphan nodes:** %d', count( $gaps['orphan_nodes'] ) );
			}
			if ( ! empty( $gaps['thin_communities'] ) ) {
				$lines[] = sprintf( '- **Thin communities (< 3 members):** %d', count( $gaps['thin_communities'] ) );
			}
			if ( ! empty( $gaps['high_ambiguity'] ) ) {
				$lines[] = sprintf( '- **Ambiguous edges:** %d', count( $gaps['high_ambiguity'] ) );
			}
			if ( ! empty( $gaps['isolated_posts'] ) ) {
				$lines[] = sprintf( '- **Isolated posts:** %d', count( $gaps['isolated_posts'] ) );
				foreach ( $gaps['isolated_posts'] as $ip ) {
					$lines[] = sprintf( '  - %s', esc_html( $ip['label'] ) );
				}
			}
		}

		// ---- SEO Recommendations -------------------------------------------
		if ( ! empty( $report['seo_recommendations'] ) ) {
			$seo     = $report['seo_recommendations'];
			$lines[] = '';
			$lines[] = '## SEO Insights';

			if ( ! empty( $seo['pillar_candidates'] ) ) {
				$lines[] = '';
				$lines[] = '### Pillar Content Candidates';
				$lines[] = '';
				foreach ( $seo['pillar_candidates'] as $pc ) {
					$lines[] = sprintf(
						'- **%s** (degree %d)',
						esc_html( $pc['label'] ),
						(int) $pc['degree']
					);
				}
			}

			if ( ! empty( $seo['cannibalization_risks'] ) ) {
				$lines[] = '';
				$lines[] = '### Cannibalization Risks';
				$lines[] = '';
				foreach ( $seo['cannibalization_risks'] as $cr ) {
					$lines[] = sprintf(
						'- "%s" ↔ "%s" (similarity: %s)',
						esc_html( $cr['label_a'] ),
						esc_html( $cr['label_b'] ),
						esc_html( $cr['similarity'] )
					);
				}
			}
		}

		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Quick summary stats from graph meta (no analysis).
	 *
	 * Suitable for a dashboard widget where speed matters.
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 * @return array Summary stats.
	 */
	public function get_summary_stats( $graph_id = 1 ) {
		global $wpdb;

		$graph_id = absint( $graph_id );

		$meta = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT node_count, edge_count, community_count, last_built, build_status
				 FROM %i WHERE graph_id = %d',
				$this->tables['meta'],
				$graph_id
			),
			ARRAY_A
		);

		if ( ! is_array( $meta ) ) {
			return array(
				'node_count'      => 0,
				'edge_count'      => 0,
				'community_count' => 0,
				'last_built'      => null,
				'build_status'    => 'idle',
			);
		}

		return array(
			'node_count'      => (int) $meta['node_count'],
			'edge_count'      => (int) $meta['edge_count'],
			'community_count' => (int) $meta['community_count'],
			'last_built'      => $meta['last_built'],
			'build_status'    => $meta['build_status'],
		);
	}
}
