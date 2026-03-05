<?php
/**
 * Tool for checking content freshness and identifying outdated posts.
 *
 * Analyzes post dates, content relevance, and identifies posts that
 * need updating based on time-sensitive information.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Content Freshness Checker Tool
 *
 * Identifies outdated content and suggests updates for content maintenance.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Content_Freshness_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'content_freshness_checker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Content Freshness Checker', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes content to identify outdated posts that need updates. Checks for time-sensitive information, broken links, and stale data.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to check freshness.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'post_types'         => array(
					'type'        => 'array',
					'description' => __( 'Post types to analyze (for bulk checking).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array( 'post' ),
				),
				'age_threshold_days' => array(
					'type'        => 'integer',
					'description' => __( 'Number of days to consider content potentially outdated.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'default'     => 365,
				),
				'check_links'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to check for broken external links.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'limit'              => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of posts to check (for bulk operation).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
			),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'content_strategist', 'seo_specialist' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'cacheable',
			'external-api',
			'may-timeout',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook.
		$this->do_before_execute( $arguments, $context );

		// Check if single post or bulk.
		if ( ! empty( $arguments['post_id'] ) ) {
			$result = $this->check_single_post( $arguments );
		} else {
			$result = $this->check_multiple_posts( $arguments );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Check freshness of a single post.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Freshness analysis results.
	 */
	private function check_single_post( $arguments ) {
		$post_id = $arguments['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'mcp-ai-wpoos' )
			);
		}

		// Check cache.
		if ( $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$analysis = $this->analyze_post_freshness( $post, $arguments );

		// Cache result.
		if ( $this->should_cache() ) {
			$this->set_cached_result( $arguments, $analysis, HOUR_IN_SECONDS );
		}

		return $analysis;
	}

	/**
	 * Check freshness of multiple posts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Bulk freshness analysis results.
	 */
	private function check_multiple_posts( $arguments ) {
		$post_types = $arguments['post_types'] ?? array( 'post' );
		$limit      = $arguments['limit'] ?? 10;

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'ASC',
		);

		$posts = get_posts( $query_args );

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = $this->analyze_post_freshness( $post, $arguments );
		}

		// Sort by freshness score (lowest/most outdated first).
		usort(
			$results,
			function ( $a, $b ) {
				return ( $a['freshness_score'] ?? 100 ) <=> ( $b['freshness_score'] ?? 100 );
			}
		);

		return array(
			'checked_count' => count( $results ),
			'posts'         => $results,
			'summary'       => $this->generate_summary( $results ),
		);
	}

	/**
	 * Analyze freshness of a single post.
	 *
	 * @param WP_Post $post      Post object.
	 * @param array   $arguments Tool arguments.
	 * @return array Freshness analysis.
	 */
	private function analyze_post_freshness( $post, $arguments ) {
		$age_threshold = $arguments['age_threshold_days'] ?? 365;
		$check_links   = $arguments['check_links'] ?? false;

		// Calculate post age.
		$post_date     = strtotime( $post->post_date );
		$modified_date = strtotime( $post->post_modified );
		$current_time  = time();
		$age_days      = floor( ( $current_time - $modified_date ) / DAY_IN_SECONDS );

		// Initialize scores.
		$scores = array(
			'age_score'          => $this->calculate_age_score( $age_days, $age_threshold ),
			'modification_score' => $this->calculate_modification_score( $post_date, $modified_date ),
			'content_score'      => $this->analyze_content_freshness( $post->post_content ),
		);

		// Check links if requested.
		$broken_links = array();
		if ( $check_links ) {
			$broken_links         = $this->check_links_in_content( $post->post_content );
			$scores['link_score'] = empty( $broken_links ) ? 100 : max( 0, 100 - ( count( $broken_links ) * 10 ) );
		}

		// Calculate overall freshness score (0-100, higher is fresher).
		$freshness_score = $this->calculate_overall_score( $scores );

		// Determine status.
		$status = $this->determine_freshness_status( $freshness_score, $age_days );

		return array(
			'post_id'         => $post->ID,
			'title'           => $post->post_title,
			'url'             => get_permalink( $post->ID ),
			'freshness_score' => $freshness_score,
			'status'          => $status,
			'age_days'        => $age_days,
			'last_modified'   => $post->post_modified,
			'needs_update'    => $freshness_score < 60,
			'issues'          => $this->identify_issues( $scores, $broken_links, $age_days ),
			'scores'          => $scores,
			'broken_links'    => $broken_links,
		);
	}

	/**
	 * Calculate age score (100 = fresh, 0 = very old).
	 *
	 * @param int $age_days      Age in days.
	 * @param int $age_threshold Threshold in days.
	 * @return int Score 0-100.
	 */
	private function calculate_age_score( $age_days, $age_threshold ) {
		if ( $age_days <= $age_threshold / 2 ) {
			return 100;
		} elseif ( $age_days <= $age_threshold ) {
			return 75;
		} elseif ( $age_days <= $age_threshold * 2 ) {
			return 50;
		} else {
			return max( 0, 100 - ( $age_days / 10 ) );
		}
	}

	/**
	 * Calculate modification score (100 = frequently updated).
	 *
	 * @param int $post_date     Original post date timestamp.
	 * @param int $modified_date Last modified timestamp.
	 * @return int Score 0-100.
	 */
	private function calculate_modification_score( $post_date, $modified_date ) {
		$days_between = floor( ( $modified_date - $post_date ) / DAY_IN_SECONDS );

		if ( $days_between < 7 ) {
			return 50; // Never updated or very recently published.
		} elseif ( $days_between < 30 ) {
			return 75;
		} else {
			return 100; // Has been updated.
		}
	}

	/**
	 * Analyze content for freshness indicators.
	 *
	 * @param string $content Post content.
	 * @return int Score 0-100.
	 */
	private function analyze_content_freshness( $content ) {
		$score = 100;

		// Check for year references that might be outdated.
		$current_year = gmdate( 'Y' );
		$last_year    = $current_year - 1;
		$old_years    = $current_year - 2;

		// Look for specific year mentions.
		if ( preg_match_all( '/\b(20\d{2})\b/', $content, $matches ) ) {
			foreach ( $matches[1] as $year ) {
				if ( (int) $year < $old_years ) {
					$score -= 10;
				} elseif ( (int) $year === $last_year ) {
					$score -= 5;
				}
			}
		}

		// Check for time-sensitive words.
		$time_sensitive_words = array(
			'today',
			'yesterday',
			'this week',
			'last week',
			'this month',
			'last month',
			'currently',
			'recent',
			'latest',
			'upcoming',
		);

		$content_lower = strtolower( $content );
		foreach ( $time_sensitive_words as $word ) {
			if ( strpos( $content_lower, $word ) !== false ) {
				$score -= 3;
			}
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Check for broken links in content.
	 *
	 * @param string $content Post content.
	 * @return array Array of broken links.
	 */
	private function check_links_in_content( $content ) {
		// Extract links from content.
		preg_match_all( '/<a\s+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		$links        = $matches[1] ?? array();
		$broken_links = array();

		foreach ( $links as $link ) {
			// Only check external links.
			if ( strpos( $link, home_url() ) === false && strpos( $link, 'http' ) === 0 ) {
				// Simple check - in production, you'd want more robust link checking.
				$response = wp_remote_head(
					$link,
					array(
						'timeout'     => 5,
						'redirection' => 3,
					)
				);

				if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
					$broken_links[] = array(
						'url'  => $link,
						'code' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
					);
				}
			}
		}

		return $broken_links;
	}

	/**
	 * Calculate overall freshness score.
	 *
	 * @param array $scores Individual scores.
	 * @return int Overall score 0-100.
	 */
	private function calculate_overall_score( $scores ) {
		$weights = array(
			'age_score'          => 0.4,
			'modification_score' => 0.3,
			'content_score'      => 0.3,
			'link_score'         => 0.2,
		);

		$total_score  = 0;
		$total_weight = 0;

		foreach ( $scores as $key => $score ) {
			if ( isset( $weights[ $key ] ) ) {
				$total_score  += $score * $weights[ $key ];
				$total_weight += $weights[ $key ];
			}
		}

		return $total_weight > 0 ? round( $total_score / $total_weight ) : 0;
	}

	/**
	 * Determine freshness status.
	 *
	 * @param int $freshness_score Freshness score.
	 * @param int $age_days        Age in days.
	 * @return string Status.
	 */
	private function determine_freshness_status( $freshness_score, $age_days ) {
		if ( $freshness_score >= 80 ) {
			return 'fresh';
		} elseif ( $freshness_score >= 60 ) {
			return 'moderate';
		} elseif ( $freshness_score >= 40 ) {
			return 'stale';
		} else {
			return 'outdated';
		}
	}

	/**
	 * Identify specific issues with content freshness.
	 *
	 * @param array $scores       Individual scores.
	 * @param array $broken_links Broken links.
	 * @param int   $age_days     Age in days.
	 * @return array List of issues.
	 */
	private function identify_issues( $scores, $broken_links, $age_days ) {
		$issues = array();

		if ( $age_days > 730 ) {
			$issues[] = __( 'Content is over 2 years old', 'mcp-ai-wpoos' );
		} elseif ( $age_days > 365 ) {
			$issues[] = __( 'Content is over 1 year old', 'mcp-ai-wpoos' );
		}

		if ( isset( $scores['modification_score'] ) && $scores['modification_score'] < 60 ) {
			$issues[] = __( 'Content has not been updated since publication', 'mcp-ai-wpoos' );
		}

		if ( isset( $scores['content_score'] ) && $scores['content_score'] < 60 ) {
			$issues[] = __( 'Content contains time-sensitive references', 'mcp-ai-wpoos' );
		}

		if ( ! empty( $broken_links ) ) {
			$issues[] = sprintf(
				/* translators: %d: number of broken links */
				_n( '%d broken external link found', '%d broken external links found', count( $broken_links ), 'mcp-ai-wpoos' ),
				count( $broken_links )
			);
		}

		return $issues;
	}

	/**
	 * Generate summary of bulk check results.
	 *
	 * @param array $results Analysis results.
	 * @return array Summary statistics.
	 */
	private function generate_summary( $results ) {
		$summary = array(
			'total'    => count( $results ),
			'fresh'    => 0,
			'moderate' => 0,
			'stale'    => 0,
			'outdated' => 0,
		);

		foreach ( $results as $result ) {
			$status = $result['status'] ?? 'moderate';
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		$summary['needs_update'] = $summary['stale'] + $summary['outdated'];

		return $summary;
	}
}
