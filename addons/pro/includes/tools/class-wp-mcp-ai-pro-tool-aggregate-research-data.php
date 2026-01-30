<?php
/**
 * Aggregate Research Data Tool
 *
 * Multi-source data aggregation with deduplication and structured compilation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate Research Data Tool Class
 */
class WP_MCP_AI_Pro_Tool_Aggregate_Research_Data {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'aggregate_research_data';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'aggregate_research_data',
			'description'         => 'Aggregate data from multiple research sources with automatic deduplication and structured compilation. Perfect for market research, competitor analysis, and multi-source information gathering.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'sources'            => array(
						'type'        => 'array',
						'description' => 'Array of research sources to aggregate',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'url'     => array(
									'type'        => 'string',
									'description' => 'Source URL',
								),
								'title'   => array(
									'type'        => 'string',
									'description' => 'Source title',
								),
								'content' => array(
									'type'        => 'string',
									'description' => 'Source content or summary',
								),
								'date'    => array(
									'type'        => 'string',
									'description' => 'Publication date (optional)',
								),
								'author'  => array(
									'type'        => 'string',
									'description' => 'Author or organization (optional)',
								),
							),
							'required'   => array( 'url', 'content' ),
						),
					),
					'topic'              => array(
						'type'        => 'string',
						'description' => 'Main research topic or theme',
					),
					'deduplication_mode' => array(
						'type'        => 'string',
						'enum'        => array( 'strict', 'moderate', 'lenient' ),
						'description' => 'Deduplication sensitivity (default: moderate)',
						'default'     => 'moderate',
					),
					'output_format'      => array(
						'type'        => 'string',
						'enum'        => array( 'markdown', 'json', 'html' ),
						'description' => 'Output format (default: markdown)',
						'default'     => 'markdown',
					),
				),
				'required'   => array( 'sources', 'topic' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration' ),
		);
	}

	/**
	 * Execute tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate inputs.
		if ( empty( $arguments['sources'] ) || ! is_array( $arguments['sources'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Sources array is required',
			);
		}

		if ( empty( $arguments['topic'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Research topic is required',
			);
		}

		$sources       = $arguments['sources'];
		$topic         = sanitize_text_field( $arguments['topic'] );
		$dedup_mode    = isset( $arguments['deduplication_mode'] ) ? $arguments['deduplication_mode'] : 'moderate';
		$output_format = isset( $arguments['output_format'] ) ? $arguments['output_format'] : 'markdown';

		// Deduplicate sources.
		$unique_sources = $this->deduplicate_sources( $sources, $dedup_mode );

		// Extract key information.
		$aggregated_data = $this->aggregate_data( $unique_sources, $topic );

		// Format output.
		$formatted_output = $this->format_output( $aggregated_data, $topic, $output_format );

		return array(
			'success'          => true,
			'topic'            => $topic,
			'total_sources'    => count( $sources ),
			'unique_sources'   => count( $unique_sources ),
			'duplicates_found' => count( $sources ) - count( $unique_sources ),
			'aggregated_data'  => $aggregated_data,
			'formatted_output' => $formatted_output,
			'output_format'    => $output_format,
			'dedup_mode'       => $dedup_mode,
		);
	}

	/**
	 * Deduplicate sources
	 *
	 * @param array  $sources    Source array.
	 * @param string $dedup_mode Deduplication mode.
	 * @return array
	 */
	private function deduplicate_sources( $sources, $dedup_mode ) {
		$unique              = array();
		$seen_urls           = array();
		$seen_content_hashes = array();

		// Similarity thresholds based on mode.
		$thresholds = array(
			'strict'   => 0.95, // 95% similarity = duplicate.
			'moderate' => 0.85, // 85% similarity = duplicate.
			'lenient'  => 0.70, // 70% similarity = duplicate.
		);
		$threshold  = isset( $thresholds[ $dedup_mode ] ) ? $thresholds[ $dedup_mode ] : 0.85;

		foreach ( $sources as $source ) {
			// Skip if URL already seen.
			if ( ! empty( $source['url'] ) && in_array( $source['url'], $seen_urls, true ) ) {
				continue;
			}

			// Check content similarity.
			$content_hash = md5( $source['content'] );
			$is_duplicate = false;

			foreach ( $seen_content_hashes as $existing_hash => $existing_content ) {
				$similarity = $this->calculate_similarity( $source['content'], $existing_content );
				if ( $similarity >= $threshold ) {
					$is_duplicate = true;
					break;
				}
			}

			if ( ! $is_duplicate ) {
				$unique[] = $source;
				if ( ! empty( $source['url'] ) ) {
					$seen_urls[] = $source['url'];
				}
				$seen_content_hashes[ $content_hash ] = $source['content'];
			}
		}

		return $unique;
	}

	/**
	 * Calculate similarity between two strings
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 * @return float
	 */
	private function calculate_similarity( $str1, $str2 ) {
		similar_text( $str1, $str2, $percent );
		return $percent / 100;
	}

	/**
	 * Aggregate data from sources
	 *
	 * @param array  $sources Source array.
	 * @param string $topic   Research topic.
	 * @return array
	 */
	private function aggregate_data( $sources, $topic ) {
		$data = array(
			'summary'           => '',
			'key_points'        => array(),
			'common_themes'     => array(),
			'sources'           => array(),
			'publication_dates' => array(),
			'authors'           => array(),
		);

		foreach ( $sources as $source ) {
			// Store source info.
			$data['sources'][] = array(
				'url'     => isset( $source['url'] ) ? $source['url'] : '',
				'title'   => isset( $source['title'] ) ? $source['title'] : 'Untitled',
				'excerpt' => substr( $source['content'], 0, 200 ) . '...',
			);

			// Collect dates and authors.
			if ( ! empty( $source['date'] ) && ! in_array( $source['date'], $data['publication_dates'], true ) ) {
				$data['publication_dates'][] = $source['date'];
			}
			if ( ! empty( $source['author'] ) && ! in_array( $source['author'], $data['authors'], true ) ) {
				$data['authors'][] = $source['author'];
			}

			// Extract key points (sentences ending with important keywords).
			$key_patterns = array( 'important', 'significant', 'critical', 'essential', 'key finding', 'shows that', 'reveals that' );
			foreach ( $key_patterns as $pattern ) {
				if ( stripos( $source['content'], $pattern ) !== false ) {
					// Extract sentence containing the pattern.
					$sentences = preg_split( '/(?<=[.?!])\s+/', $source['content'], -1, PREG_SPLIT_NO_EMPTY );
					foreach ( $sentences as $sentence ) {
						if ( stripos( $sentence, $pattern ) !== false && ! in_array( $sentence, $data['key_points'], true ) ) {
							$data['key_points'][] = $sentence;
						}
					}
				}
			}
		}

		// Generate summary.
		$data['summary'] = sprintf(
			'Aggregated research on "%s" from %d unique sources.',
			$topic,
			count( $sources )
		);

		// Limit key points.
		$data['key_points'] = array_slice( $data['key_points'], 0, 10 );

		return $data;
	}

	/**
	 * Format output
	 *
	 * @param array  $data          Aggregated data.
	 * @param string $topic         Research topic.
	 * @param string $output_format Output format.
	 * @return string
	 */
	private function format_output( $data, $topic, $output_format ) {
		if ( 'json' === $output_format ) {
			return wp_json_encode( $data, JSON_PRETTY_PRINT );
		}

		if ( 'html' === $output_format ) {
			return $this->format_html( $data, $topic );
		}

		// Default: Markdown.
		return $this->format_markdown( $data, $topic );
	}

	/**
	 * Format as markdown
	 *
	 * @param array  $data  Aggregated data.
	 * @param string $topic Research topic.
	 * @return string
	 */
	private function format_markdown( $data, $topic ) {
		$md  = "# Research Compilation: {$topic}\n\n";
		$md .= "## Summary\n\n";
		$md .= "{$data['summary']}\n\n";

		if ( ! empty( $data['key_points'] ) ) {
			$md .= "## Key Findings\n\n";
			foreach ( $data['key_points'] as $point ) {
				$md .= "- {$point}\n";
			}
			$md .= "\n";
		}

		$md .= "## Sources\n\n";
		foreach ( $data['sources'] as $source ) {
			$title = ! empty( $source['title'] ) ? $source['title'] : 'Untitled';
			$url   = ! empty( $source['url'] ) ? $source['url'] : '#';
			$md   .= "### [{$title}]({$url})\n\n";
			$md   .= "{$source['excerpt']}\n\n";
		}

		if ( ! empty( $data['publication_dates'] ) ) {
			$md .= "## Publication Timeline\n\n";
			foreach ( $data['publication_dates'] as $date ) {
				$md .= "- {$date}\n";
			}
			$md .= "\n";
		}

		if ( ! empty( $data['authors'] ) ) {
			$md .= "## Contributors\n\n";
			foreach ( $data['authors'] as $author ) {
				$md .= "- {$author}\n";
			}
		}

		return $md;
	}

	/**
	 * Format as HTML
	 *
	 * @param array  $data  Aggregated data.
	 * @param string $topic Research topic.
	 * @return string
	 */
	private function format_html( $data, $topic ) {
		$html  = '<h1>Research Compilation: ' . esc_html( $topic ) . "</h1>\n";
		$html .= "<h2>Summary</h2>\n";
		$html .= '<p>' . esc_html( $data['summary'] ) . "</p>\n";

		if ( ! empty( $data['key_points'] ) ) {
			$html .= "<h2>Key Findings</h2>\n<ul>\n";
			foreach ( $data['key_points'] as $point ) {
				$html .= '<li>' . esc_html( $point ) . "</li>\n";
			}
			$html .= "</ul>\n";
		}

		$html .= "<h2>Sources</h2>\n";
		foreach ( $data['sources'] as $source ) {
			$title = ! empty( $source['title'] ) ? esc_html( $source['title'] ) : 'Untitled';
			$url   = ! empty( $source['url'] ) ? esc_url( $source['url'] ) : '#';
			$html .= "<h3><a href=\"{$url}\">{$title}</a></h3>\n";
			$html .= '<p>' . esc_html( $source['excerpt'] ) . "</p>\n";
		}

		return $html;
	}
}
