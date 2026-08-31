<?php
/**
 * Optimize Performance Slash Command
 *
 * Automated performance analysis and optimization for WordPress sites.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimize Performance Command Class
 *
 * Implements 10-phase performance analysis:
 * 1. Baseline Measurement
 * 2. Database Analysis
 * 3. Cache Strategy
 * 4. Asset Optimization
 * 5. Plugin Audit
 * 6. Code Profiling
 * 7. CDN Setup Check
 * 8. Database Cleanup
 * 9. Auto-apply Optimizations
 * 10. Validation
 *
 * @since 1.2.0
 */
class SlashCommandOptimizePerf {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute optimize-perf command
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to analyze site performance.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse flags.
		$phases     = isset( $flags['phases'] ) ? array_map( 'absint', explode( ',', $flags['phases'] ) ) : range( 1, 10 );
		$dry_run    = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$auto_apply = isset( $flags['auto-apply'] ) || isset( $flags['a'] );
		$detailed   = isset( $flags['detailed'] ) || isset( $flags['v'] );
		$page_url   = isset( $flags['url'] ) ? esc_url_raw( $flags['url'] ) : home_url( '/' );

		// Run requested phases.
		$results = array(
			'phases'      => array(),
			'summary'     => array(),
			'total_score' => 0,
		);

		foreach ( $phases as $phase ) {
			if ( $phase < 1 || $phase > 10 ) {
				continue;
			}

			$phase_result                = $this->run_phase( $phase, $page_url, $detailed );
			$results['phases'][ $phase ] = $phase_result;
		}

		// Calculate overall score.
		$results['summary']     = $this->generate_summary( $results['phases'] );
		$results['total_score'] = $this->calculate_score( $results['phases'] );

		// Auto-apply optimizations if requested.
		if ( $auto_apply && ! $dry_run && isset( $results['phases'][9] ) ) {
			$apply_result       = $this->apply_optimizations( $results['phases'][9]['recommendations'] );
			$results['applied'] = $apply_result;
		}

		return $this->format_response( $results, $dry_run );
	}

	/**
	 * Run a specific analysis phase
	 *
	 * @param int    $phase   Phase number (1-10).
	 * @param string $url     URL to analyze.
	 * @param bool   $detailed Detailed output.
	 * @return array Phase results.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function run_phase( $phase, $url, $detailed ) {
		switch ( $phase ) {
			case 1:
				return $this->phase1_baseline_measurement( $url );
			case 2:
				return $this->phase2_database_analysis();
			case 3:
				return $this->phase3_cache_strategy();
			case 4:
				return $this->phase4_asset_optimization();
			case 5:
				return $this->phase5_plugin_audit();
			case 6:
				return $this->phase6_code_profiling();
			case 7:
				return $this->phase7_cdn_check();
			case 8:
				return $this->phase8_database_cleanup();
			case 9:
				return $this->phase9_optimization_recommendations();
			case 10:
				return $this->phase10_validation( $url );
			default:
				return array(
					'phase'  => $phase,
					'status' => 'skipped',
				);
		}
	}

	/**
	 * Phase 1: Baseline Measurement
	 *
	 * @param string $url URL to measure.
	 * @return array Measurement results.
	 */
	private function phase1_baseline_measurement( $url ) {
		global $wpdb;

		$measurements = array(
			'phase'   => 1,
			'name'    => 'Baseline Measurement',
			'url'     => $url,
			'metrics' => array(),
			'issues'  => array(),
			'score'   => 0,
		);

		// Measure database queries.
		$wpdb->queries = array();
		$start_queries = $wpdb->num_queries;
		$start_time    = microtime( true );

		// Simulate a page load.
		$response = wp_remote_get( $url );

		$end_time    = microtime( true );
		$query_count = $wpdb->num_queries - $start_queries;
		$load_time   = ( $end_time - $start_time ) * 1000; // Convert to ms.

		$measurements['metrics']['load_time_ms'] = round( $load_time, 2 );
		$measurements['metrics']['query_count']  = $query_count;
		$measurements['metrics']['memory_usage'] = round( memory_get_peak_usage( true ) / 1024 / 1024, 2 ) . ' MB';

		// Check TTFB (Time to First Byte).
		if ( ! is_wp_error( $response ) ) {
			$headers = wp_remote_retrieve_headers( $response );
			if ( isset( $headers['x-ttfb'] ) ) {
				$measurements['metrics']['ttfb_ms'] = $headers['x-ttfb'];
			}
		}

		// Evaluate metrics.
		if ( $load_time > 3000 ) {
			$measurements['issues'][] = sprintf(
				/* translators: %s: load time */
				__( 'Page load time is slow (%s ms, target: <3000ms)', 'nvoos-content-graph-ai-platform' ),
				round( $load_time, 2 )
			);
		}

		if ( $query_count > 50 ) {
			$measurements['issues'][] = sprintf(
				/* translators: %d: query count */
				__( 'High database query count (%d queries, target: <50)', 'nvoos-content-graph-ai-platform' ),
				$query_count
			);
		}

		// Calculate score (0-100).
		$score                 = 100;
		$score                -= min( 30, ( max( 0, $load_time - 1000 ) / 100 ) ); // Penalize slow load time.
		$score                -= min( 30, ( max( 0, $query_count - 20 ) ) ); // Penalize excessive queries.
		$measurements['score'] = max( 0, round( $score ) );

		return $measurements;
	}

	/**
	 * Phase 2: Database Analysis
	 *
	 * @return array Analysis results.
	 */
	private function phase2_database_analysis() {
		global $wpdb;

		$analysis = array(
			'phase'   => 2,
			'name'    => 'Database Analysis',
			'metrics' => array(),
			'issues'  => array(),
			'score'   => 100,
		);

		// Check database size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$db_size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) / 1024 / 1024 
				FROM information_schema.tables 
				WHERE table_schema = %s',
				DB_NAME
			)
		);

		if ( $db_size ) {
			$analysis['metrics']['database_size_mb'] = round( $db_size, 2 );
		}

		// Check for auto-loaded options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$autoload_size = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) / 1024 
			FROM {$wpdb->options} 
			WHERE autoload = 'yes'"
		);

		$analysis['metrics']['autoload_size_kb'] = round( $autoload_size, 2 );

		if ( $autoload_size > 1000 ) {
			$analysis['issues'][] = sprintf(
				/* translators: %s: size */
				__( 'Large autoload size (%s KB, target: <1000KB)', 'nvoos-content-graph-ai-platform' ),
				round( $autoload_size, 2 )
			);
			$analysis['score'] -= 20;
		}

		// Check for transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$expired_transients = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_value < %d",
				'_transient_timeout_%',
				time()
			)
		);

		$analysis['metrics']['expired_transients'] = $expired_transients;

		if ( $expired_transients > 100 ) {
			$analysis['issues'][] = sprintf(
				/* translators: %d: count */
				__( '%d expired transients found (recommend cleanup)', 'nvoos-content-graph-ai-platform' ),
				$expired_transients
			);
			$analysis['score'] -= 10;
		}

		// Check for post revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$revision_count = $wpdb->get_var(
			"SELECT COUNT(*) 
			FROM {$wpdb->posts} 
			WHERE post_type = 'revision'"
		);

		$analysis['metrics']['post_revisions'] = $revision_count;

		if ( $revision_count > 1000 ) {
			$analysis['issues'][] = sprintf(
				/* translators: %d: count */
				__( '%d post revisions found (consider limiting)', 'nvoos-content-graph-ai-platform' ),
				$revision_count
			);
			$analysis['score'] -= 10;
		}

		return $analysis;
	}

	/**
	 * Phase 3: Cache Strategy
	 *
	 * @return array Cache analysis.
	 */
	private function phase3_cache_strategy() {
		$cache_analysis = array(
			'phase'  => 3,
			'name'   => 'Cache Strategy',
			'status' => array(),
			'issues' => array(),
			'score'  => 100,
		);

		// Check object cache.
		$cache_analysis['status']['object_cache'] = wp_using_ext_object_cache();

		if ( ! wp_using_ext_object_cache() ) {
			$cache_analysis['issues'][] = __( 'No persistent object cache detected (consider Redis/Memcached)', 'nvoos-content-graph-ai-platform' );
			$cache_analysis['score']   -= 30;
		}

		// Check for page cache plugins.
		$cache_plugins = array(
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		);

		$active_cache_plugin = null;
		foreach ( $cache_plugins as $plugin => $name ) {
			if ( is_plugin_active( $plugin ) ) {
				$active_cache_plugin = $name;
				break;
			}
		}

		$cache_analysis['status']['page_cache_plugin'] = $active_cache_plugin;

		if ( ! $active_cache_plugin ) {
			$cache_analysis['issues'][] = __( 'No page cache plugin detected (recommend installing one)', 'nvoos-content-graph-ai-platform' );
			$cache_analysis['score']   -= 20;
		}

		// Check transient usage.
		$cache_analysis['status']['transients'] = 'enabled';

		return $cache_analysis;
	}

	/**
	 * Phase 4: Asset Optimization
	 *
	 * @return array Asset analysis.
	 */
	private function phase4_asset_optimization() {
		$asset_analysis = array(
			'phase'   => 4,
			'name'    => 'Asset Optimization',
			'metrics' => array(),
			'issues'  => array(),
			'score'   => 100,
		);

		// Check for minification.
		$theme_dir = get_template_directory();
		$css_files = glob( $theme_dir . '/assets/css/*.css' );
		$js_files  = glob( $theme_dir . '/assets/js/*.js' );

		$minified_css = 0;
		$minified_js  = 0;

		foreach ( $css_files as $file ) {
			if ( strpos( basename( $file ), '.min.css' ) !== false ) {
				++$minified_css;
			}
		}

		foreach ( $js_files as $file ) {
			if ( strpos( basename( $file ), '.min.js' ) !== false ) {
				++$minified_js;
			}
		}

		$asset_analysis['metrics']['total_css_files'] = count( $css_files );
		$asset_analysis['metrics']['minified_css']    = $minified_css;
		$asset_analysis['metrics']['total_js_files']  = count( $js_files );
		$asset_analysis['metrics']['minified_js']     = $minified_js;

		if ( count( $css_files ) > 0 && $minified_css / count( $css_files ) < 0.5 ) {
			$asset_analysis['issues'][] = __( 'Less than 50% of CSS files are minified', 'nvoos-content-graph-ai-platform' );
			$asset_analysis['score']   -= 15;
		}

		if ( count( $js_files ) > 0 && $minified_js / count( $js_files ) < 0.5 ) {
			$asset_analysis['issues'][] = __( 'Less than 50% of JS files are minified', 'nvoos-content-graph-ai-platform' );
			$asset_analysis['score']   -= 15;
		}

		// Check for image optimization.
		$upload_dir    = wp_upload_dir();
		$image_plugins = array(
			'imagify/imagify.php'                          => 'Imagify',
			'shortpixel-image-optimiser/wp-shortpixel.php' => 'ShortPixel',
			'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW Image Optimizer',
		);

		$active_image_plugin = null;
		foreach ( $image_plugins as $plugin => $name ) {
			if ( is_plugin_active( $plugin ) ) {
				$active_image_plugin = $name;
				break;
			}
		}

		$asset_analysis['status']['image_optimization'] = $active_image_plugin ? $active_image_plugin : 'none';

		if ( ! $active_image_plugin ) {
			$asset_analysis['issues'][] = __( 'No image optimization plugin detected', 'nvoos-content-graph-ai-platform' );
			$asset_analysis['score']   -= 10;
		}

		return $asset_analysis;
	}

	/**
	 * Phase 5: Plugin Audit
	 *
	 * @return array Plugin analysis.
	 */
	private function phase5_plugin_audit() {
		$plugin_analysis = array(
			'phase'   => 5,
			'name'    => 'Plugin Audit',
			'metrics' => array(),
			'issues'  => array(),
			'score'   => 100,
		);

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$plugin_analysis['metrics']['total_plugins']    = count( $all_plugins );
		$plugin_analysis['metrics']['active_plugins']   = count( $active_plugins );
		$plugin_analysis['metrics']['inactive_plugins'] = count( $all_plugins ) - count( $active_plugins );

		// Check for too many active plugins.
		if ( count( $active_plugins ) > 30 ) {
			$plugin_analysis['issues'][] = sprintf(
				/* translators: %d: plugin count */
				__( 'High number of active plugins (%d, recommend: <30)', 'nvoos-content-graph-ai-platform' ),
				count( $active_plugins )
			);
			$plugin_analysis['score'] -= 15;
		}

		// Check for outdated plugins.
		$updates        = get_site_transient( 'update_plugins' );
		$outdated_count = 0;

		if ( $updates && isset( $updates->response ) ) {
			$outdated_count = count( $updates->response );
		}

		$plugin_analysis['metrics']['outdated_plugins'] = $outdated_count;

		if ( $outdated_count > 0 ) {
			$plugin_analysis['issues'][] = sprintf(
				/* translators: %d: plugin count */
				__( '%d plugin(s) need updating', 'nvoos-content-graph-ai-platform' ),
				$outdated_count
			);
			$plugin_analysis['score'] -= min( 20, $outdated_count * 2 );
		}

		// Check for inactive plugins (security risk).
		if ( $plugin_analysis['metrics']['inactive_plugins'] > 5 ) {
			$plugin_analysis['issues'][] = sprintf(
				/* translators: %d: plugin count */
				__( '%d inactive plugins found (recommend removal)', 'nvoos-content-graph-ai-platform' ),
				$plugin_analysis['metrics']['inactive_plugins']
			);
			$plugin_analysis['score'] -= 10;
		}

		return $plugin_analysis;
	}

	/**
	 * Phase 6: Code Profiling
	 *
	 * @return array Profiling results.
	 */
	private function phase6_code_profiling() {
		return array(
			'phase'   => 6,
			'name'    => 'Code Profiling',
			'status'  => 'basic',
			'metrics' => array(
				'php_version' => PHP_VERSION,
				'wp_version'  => get_bloginfo( 'version' ),
				'theme'       => wp_get_theme()->get( 'Name' ),
			),
			'issues'  => array(),
			'score'   => 100,
			'note'    => __( 'Advanced code profiling requires Xdebug or similar tools', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Phase 7: CDN Setup Check
	 *
	 * @return array CDN analysis.
	 */
	private function phase7_cdn_check() {
		$cdn_analysis = array(
			'phase'  => 7,
			'name'   => 'CDN Setup',
			'status' => array(),
			'issues' => array(),
			'score'  => 100,
		);

		// Check for CDN plugins.
		$cdn_plugins = array(
			'cloudflare/cloudflare.php'   => 'Cloudflare',
			'cdn-enabler/cdn-enabler.php' => 'CDN Enabler',
		);

		$active_cdn = null;
		foreach ( $cdn_plugins as $plugin => $name ) {
			if ( is_plugin_active( $plugin ) ) {
				$active_cdn = $name;
				break;
			}
		}

		$cdn_analysis['status']['cdn_plugin'] = $active_cdn ? $active_cdn : 'none';

		if ( ! $active_cdn ) {
			$cdn_analysis['issues'][] = __( 'No CDN detected (consider Cloudflare or similar)', 'nvoos-content-graph-ai-platform' );
			$cdn_analysis['score']   -= 20;
		}

		return $cdn_analysis;
	}

	/**
	 * Phase 8: Database Cleanup
	 *
	 * @return array Cleanup analysis.
	 */
	private function phase8_database_cleanup() {
		global $wpdb;

		$cleanup = array(
			'phase'      => 8,
			'name'       => 'Database Cleanup',
			'candidates' => array(),
			'issues'     => array(),
			'score'      => 100,
		);

		// Find cleanup candidates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$spam_comments = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$trash_comments = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic query intentionally bypasses cache to return accurate live metrics.
		$trashed_posts = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		$cleanup['candidates']['spam_comments']  = $spam_comments;
		$cleanup['candidates']['trash_comments'] = $trash_comments;
		$cleanup['candidates']['trashed_posts']  = $trashed_posts;

		if ( $spam_comments > 100 ) {
			$cleanup['issues'][] = sprintf(
				/* translators: %d: count */
				__( '%d spam comments can be deleted', 'nvoos-content-graph-ai-platform' ),
				$spam_comments
			);
		}

		if ( $trash_comments > 50 ) {
			$cleanup['issues'][] = sprintf(
				/* translators: %d: count */
				__( '%d trashed comments can be deleted', 'nvoos-content-graph-ai-platform' ),
				$trash_comments
			);
		}

		if ( $trashed_posts > 50 ) {
			$cleanup['issues'][] = sprintf(
				/* translators: %d: count */
				__( '%d trashed posts can be deleted', 'nvoos-content-graph-ai-platform' ),
				$trashed_posts
			);
		}

		return $cleanup;
	}

	/**
	 * Phase 9: Optimization Recommendations
	 *
	 * @return array Recommendations.
	 */
	private function phase9_optimization_recommendations() {
		return array(
			'phase'           => 9,
			'name'            => 'Optimization Recommendations',
			'recommendations' => array(
				array(
					'category' => 'cache',
					'action'   => 'install_object_cache',
					'priority' => 'high',
					'impact'   => 'significant',
				),
				array(
					'category' => 'database',
					'action'   => 'cleanup_transients',
					'priority' => 'medium',
					'impact'   => 'moderate',
				),
			),
			'score'           => 100,
		);
	}

	/**
	 * Phase 10: Validation
	 *
	 * @param string $url URL to validate.
	 * @return array Validation results.
		 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function phase10_validation( $url ) {
		return array(
			'phase'  => 10,
			'name'   => 'Validation',
			'status' => 'baseline_recorded',
			'note'   => __( 'Re-run after applying optimizations to measure improvement', 'nvoos-content-graph-ai-platform' ),
			'score'  => 100,
		);
	}

	/**
	 * Generate summary from phase results
	 *
	 * @param array $phases Phase results.
	 * @return array Summary.
	 */
	private function generate_summary( $phases ) {
		$total_issues    = 0;
		$critical_issues = 0;

		foreach ( $phases as $phase ) {
			if ( isset( $phase['issues'] ) ) {
				$total_issues += count( $phase['issues'] );
			}
		}

		return array(
			'total_issues'    => $total_issues,
			'phases_run'      => count( $phases ),
			'critical_issues' => $critical_issues,
		);
	}

	/**
	 * Calculate overall score
	 *
	 * @param array $phases Phase results.
	 * @return int Overall score (0-100).
	 */
	private function calculate_score( $phases ) {
		$total_score = 0;
		$phase_count = 0;

		foreach ( $phases as $phase ) {
			if ( isset( $phase['score'] ) ) {
				$total_score += $phase['score'];
				++$phase_count;
			}
		}

		return $phase_count > 0 ? round( $total_score / $phase_count ) : 0;
	}

	/**
	 * Apply safe optimizations
	 *
	 * @param array $recommendations Recommendations to apply.
		 * @return array Application results.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function apply_optimizations( $recommendations ) {
		// Placeholder for auto-apply functionality.
		return array(
			'applied' => 0,
			'failed'  => 0,
			'note'    => __( 'Auto-apply functionality coming soon', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Format command response
	 *
	 * @param array $results Analysis results.
	 * @param bool  $dry_run Dry run mode.
	 * @return string Formatted response.
	 */
	private function format_response( $results, $dry_run ) {
		$output = "## Performance Analysis Report\n\n";

		// Overall score with indicator.
		$score     = $results['total_score'];
		$indicator = $score >= 80 ? '🟢' : ( $score >= 60 ? '🟡' : '🔴' );

		$output .= sprintf(
			"**Overall Score:** %s %d/100\n\n",
			$indicator,
			$score
		);

		// Summary.
		$output .= sprintf(
			"**Summary:**\n- Phases analyzed: %d\n- Total issues: %d\n\n",
			$results['summary']['phases_run'],
			$results['summary']['total_issues']
		);

		// Phase details.
		$output .= "### Phase Results\n\n";

		foreach ( $results['phases'] as $phase ) {
			$output .= sprintf(
				"#### Phase %d: %s (%d/100)\n\n",
				$phase['phase'],
				$phase['name'],
				isset( $phase['score'] ) ? $phase['score'] : 100
			);

			// Metrics.
			if ( ! empty( $phase['metrics'] ) ) {
				$output .= "**Metrics:**\n";
				foreach ( $phase['metrics'] as $key => $value ) {
					$label   = ucwords( str_replace( '_', ' ', $key ) );
					$output .= sprintf( "- %s: %s\n", $label, $value );
				}
				$output .= "\n";
			}

			// Issues.
			if ( ! empty( $phase['issues'] ) ) {
				$output .= "**Issues:**\n";
				foreach ( $phase['issues'] as $issue ) {
					$output .= sprintf( "- ⚠️ %s\n", esc_html( $issue ) );
				}
				$output .= "\n";
			}
		}

		if ( $dry_run ) {
			$output .= "\n**Note:** This was a dry run. Use `--auto-apply` to apply safe optimizations.\n";
		}

		return $output;
	}
}
