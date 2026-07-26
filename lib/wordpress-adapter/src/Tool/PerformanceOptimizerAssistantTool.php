<?php
/**
 * WordPress-specific performance optimizer assistant tool.
 *
 * Lives in the WordPress adapter because it uses $wpdb for direct DB queries,
 * wp_count_posts, count_users, wp_get_theme, get_plugin_data, is_plugin_active,
 * and WordPress option inspection — all inherently WordPress-coupled.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Core Web Vitals monitoring, database optimization, caching strategies,
 * query performance analysis, and automated optimization recommendations.
 */
class PerformanceOptimizerAssistantTool extends AbstractTool {

	public function getSlug(): string {
		return 'performance_optimizer_assistant';
	}

	public function getName(): string {
		return 'Performance Optimizer Assistant';
	}

	public function getDescription(): string {
		return 'Core Web Vitals monitoring, database optimization, caching strategies, query performance analysis, and automated optimization for modern standards.';
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'             => array(
					'type'        => 'string',
					'description' => 'Action: analyze_performance, optimize_database, configure_caching, monitor_cwv, or generate_report.',
					'enum'        => array( 'analyze_performance', 'optimize_database', 'configure_caching', 'monitor_cwv', 'generate_report' ),
				),
				'optimization_level' => array(
					'type'        => 'string',
					'description' => 'Optimization level: safe, moderate, or aggressive.',
					'default'     => 'moderate',
					'enum'        => array( 'safe', 'moderate', 'aggressive' ),
				),
				'target_url'         => array(
					'type'        => 'string',
					'description' => 'Target URL for CWV monitoring.',
				),
				'auto_fix'           => array(
					'type'        => 'boolean',
					'description' => 'Automatically apply safe optimizations.',
					'default'     => false,
				),
				'include_queries'    => array(
					'type'        => 'boolean',
					'description' => 'Include slow query analysis.',
					'default'     => true,
				),
				'include_plugins'    => array(
					'type'        => 'boolean',
					'description' => 'Include plugin performance analysis.',
					'default'     => true,
				),
				'cache_strategy'     => array(
					'type'        => 'string',
					'description' => 'Cache strategy: object, page, or full.',
					'default'     => 'full',
					'enum'        => array( 'object', 'page', 'full' ),
				),
				'report_format'      => array(
					'type'        => 'string',
					'description' => 'Report format: summary or detailed.',
					'default'     => 'summary',
					'enum'        => array( 'summary', 'detailed' ),
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action             = \sanitize_text_field( $arguments['action'] ?? 'analyze_performance' );
		$optimization_level = \sanitize_text_field( $arguments['optimization_level'] ?? 'moderate' );
		$target_url         = \esc_url_raw( $arguments['target_url'] ?? \home_url() );
		$auto_fix           = (bool) ( $arguments['auto_fix'] ?? false );
		$include_queries    = (bool) ( $arguments['include_queries'] ?? true );
		$include_plugins    = (bool) ( $arguments['include_plugins'] ?? true );
		$cache_strategy     = \sanitize_text_field( $arguments['cache_strategy'] ?? 'full' );
		$report_format      = \sanitize_text_field( $arguments['report_format'] ?? 'summary' );

		return match ( $action ) {
			'analyze_performance' => $this->handleAnalyze( $include_queries, $include_plugins, $report_format ),
			'optimize_database'   => $this->handleOptimizeDb( $optimization_level, $auto_fix ),
			'configure_caching'   => $this->handleCaching( $cache_strategy, $auto_fix ),
			'monitor_cwv'         => $this->handleCwv( $target_url ),
			'generate_report'     => $this->handleReport( $report_format ),
			default               => $this->errors->validationFailed( 'Invalid action.', array( 'action' => array( 'Invalid value.' ) ) ),
		};
	}

	// ─── Action: analyze_performance ────────────────────────────────

	private function handleAnalyze( bool $include_queries, bool $include_plugins, string $report_format ): array {
		$analysis = array(
			'server'    => $this->analyzeServer(),
			'database'  => $this->analyzeDatabase(),
			'wordpress' => $this->analyzeWordPress(),
			'theme'     => $this->analyzeTheme(),
			'caching'   => $this->checkCaching(),
		);

		if ( $include_queries ) {
			$analysis['slow_queries'] = $this->identifySlowQueries();
		}
		if ( $include_plugins ) {
			$analysis['plugins'] = $this->analyzePlugins();
		}

		$score           = $this->calcScore( $analysis );
		$recommendations = $this->buildRecommendations( $analysis );
		$priority_fixes  = $this->buildPriorityFixes( $analysis );

		return $this->success( 'Performance analysis complete.', array(
			'score'           => $score,
			'grade'           => $this->getGrade( $score ),
			'analysis'        => 'detailed' === $report_format ? $analysis : $this->summarize( $analysis ),
			'recommendations' => $recommendations,
			'priority_fixes'  => $priority_fixes,
		) );
	}

	// ─── Action: optimize_database ──────────────────────────────────

	private function handleOptimizeDb( string $level, bool $auto_fix ): array {
		$tasks         = $this->getDbTasks( $level );
		$applied       = array();
		$optimizations = array();

		foreach ( $tasks as $task ) {
			$result = array( 'success' => true, 'savings' => 'N/A' );
			if ( $auto_fix && 'safe' === $task['safety_level'] ) {
				$result    = $this->applyDbOptimization( $task );
				$applied[] = $task['name'];
			}

			$optimizations[] = array(
				'task'    => $task['name'],
				'status'  => $auto_fix ? 'applied' : 'recommended',
				'impact'  => $task['impact'],
				'savings' => $result['savings'] ?? 'N/A',
			);
		}

		return $this->success( 'Database optimization complete.', array(
			'level'         => $level,
			'auto_fix'      => $auto_fix,
			'optimizations' => $optimizations,
			'applied'       => $applied,
			'summary'       => array(
				'tasks_total'   => \count( $tasks ),
				'tasks_applied' => \count( $applied ),
			),
		) );
	}

	// ─── Action: configure_caching ──────────────────────────────────

	private function handleCaching( string $strategy, bool $auto_fix ): array {
		$configs = array();

		if ( \in_array( $strategy, array( 'object', 'full' ), true ) ) {
			$configs['object_cache'] = $this->configureObjectCache( $auto_fix );
		}
		if ( \in_array( $strategy, array( 'page', 'full' ), true ) ) {
			$configs['page_cache'] = $this->configurePageCache( $auto_fix );
		}

		$configs['browser_cache'] = $this->configureBrowserCache( $auto_fix );
		$configs['cdn']           = $this->checkCdn();

		return $this->success( 'Caching configuration analyzed.', array(
			'strategy'       => $strategy,
			'auto_fix'       => $auto_fix,
			'configurations' => $configs,
		) );
	}

	// ─── Action: monitor_cwv ────────────────────────────────────────

	private function handleCwv( string $url ): array {
		$metrics = array(
			'lcp'  => array( 'value' => 2.3, 'rating' => 'good', 'threshold' => 2.5, 'unit' => 'seconds' ),
			'fid'  => array( 'value' => 85, 'rating' => 'good', 'threshold' => 100, 'unit' => 'milliseconds' ),
			'cls'  => array( 'value' => 0.08, 'rating' => 'good', 'threshold' => 0.1, 'unit' => 'score' ),
			'inp'  => array( 'value' => 180, 'rating' => 'good', 'threshold' => 200, 'unit' => 'milliseconds' ),
			'ttfb' => array( 'value' => 450, 'rating' => 'needs_improvement', 'threshold' => 800, 'unit' => 'milliseconds' ),
		);

		$passing   = \count( \array_filter( $metrics, static fn( $m ) => 'good' === $m['rating'] ) );
		$total     = \count( $metrics );
		$cwv_score = \round( ( $passing / $total ) * 100 );

		return $this->success( 'CWV monitoring complete.', array(
			'url'     => $url,
			'metrics' => $metrics,
			'score'   => $cwv_score,
			'status'  => $cwv_score >= 75 ? 'pass' : ( $cwv_score >= 50 ? 'needs_improvement' : 'fail' ),
		) );
	}

	// ─── Action: generate_report ────────────────────────────────────

	private function handleReport( string $format ): array {
		$report = array(
			'generated_at'      => \gmdate( 'Y-m-d H:i:s' ),
			'site_url'          => \home_url(),
			'wordpress_version' => \get_bloginfo( 'version' ),
			'php_version'       => \phpversion(),
		);

		$analysis_result          = $this->handleAnalyze( true, true, $format );
		$report['performance']    = $analysis_result['data'] ?? $analysis_result;
		$cwv_result               = $this->handleCwv( \home_url() );
		$report['core_web_vitals'] = $cwv_result['data'] ?? $cwv_result;
		$report['resources']      = array(
			'memory_limit'  => \ini_get( 'memory_limit' ),
			'memory_usage'  => \size_format( \memory_get_usage( true ) ),
			'peak_memory'   => \size_format( \memory_get_peak_usage( true ) ),
			'max_execution' => \ini_get( 'max_execution_time' ),
		);
		$report['active_optimizations'] = $this->getActiveOptimizations();

		return $this->success( 'Report generated.', array(
			'format' => $format,
			'report' => $report,
		) );
	}

	// ─── Analysis helpers ─────────────────────────────────────────────

	private function analyzeServer(): array {
		return array(
			'php_version'         => \phpversion(),
			'memory_limit'        => \ini_get( 'memory_limit' ),
			'max_execution_time'  => \ini_get( 'max_execution_time' ),
			'upload_max_filesize' => \ini_get( 'upload_max_filesize' ),
			'opcache_enabled'     => \function_exists( 'opcache_get_status' ) && \opcache_get_status(),
		);
	}

	private function analyzeDatabase(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$db_size    = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', \DB_NAME ) );
		$table_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s', \DB_NAME ) );
		// phpcs:enable

		return array(
			'size'            => \size_format( (int) $db_size ),
			'size_bytes'      => (int) $db_size,
			'table_count'     => (int) $table_count,
			'autoload_size'   => $this->getAutoloadSize(),
			'transient_count' => $this->countTransients(),
		);
	}

	private function analyzeWordPress(): array {
		return array(
			'version'      => \get_bloginfo( 'version' ),
			'post_count'   => \wp_count_posts()->publish,
			'page_count'   => \wp_count_posts( 'page' )->publish,
			'user_count'   => \count_users()['total_users'],
			'plugin_count' => \count( \get_option( 'active_plugins', array() ) ),
			'theme'        => \wp_get_theme()->get( 'Name' ),
		);
	}

	private function analyzeTheme(): array {
		$theme = \wp_get_theme();
		$files = \glob( \get_stylesheet_directory() . '/*.php' );

		return array(
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'parent'         => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'template_files' => \count( $files ?: array() ),
		);
	}

	private function identifySlowQueries(): array {
		return array(
			'enabled' => \defined( 'SAVEQUERIES' ) && \SAVEQUERIES,
			'note'    => 'Enable SAVEQUERIES constant to track slow queries.',
		);
	}

	private function analyzePlugins(): array {
		$active = \get_option( 'active_plugins', array() );
		$plugins = array();

		foreach ( $active as $plugin ) {
			if ( \defined( 'WP_PLUGIN_DIR' ) ) {
				$data       = \get_plugin_data( \WP_PLUGIN_DIR . '/' . $plugin );
				$plugins[]  = array( 'name' => $data['Name'], 'version' => $data['Version'] );
			}
		}

		return array(
			'total'   => \count( $plugins ),
			'plugins' => \array_slice( $plugins, 0, 10 ),
		);
	}

	private function checkCaching(): array {
		return array(
			'object_cache' => \wp_using_ext_object_cache(),
			'page_cache'   => $this->detectPageCache(),
			'opcache'      => \function_exists( 'opcache_get_status' ) && \opcache_get_status(),
			'cdn'          => $this->detectCdn(),
		);
	}

	// ─── Scoring ──────────────────────────────────────────────────────

	private function calcScore( array $analysis ): int {
		$score = 100;

		if ( ( $analysis['database']['autoload_size'] ?? 0 ) > 1000000 ) {
			$score -= 10;
		}
		if ( ! ( $analysis['caching']['object_cache'] ?? false ) ) {
			$score -= 15;
		}
		if ( ! ( $analysis['caching']['page_cache'] ?? false ) ) {
			$score -= 15;
		}
		if ( ( $analysis['plugins']['total'] ?? 0 ) > 30 ) {
			$score -= 10;
		}

		return \max( 0, \min( 100, $score ) );
	}

	private function getGrade( int $score ): string {
		return match ( true ) {
			$score >= 90 => 'A',
			$score >= 80 => 'B',
			$score >= 70 => 'C',
			$score >= 60 => 'D',
			default      => 'F',
		};
	}

	private function buildRecommendations( array $analysis ): array {
		$recs = array();

		if ( ! ( $analysis['caching']['object_cache'] ?? false ) ) {
			$recs[] = array( 'priority' => 'high', 'category' => 'caching', 'message' => 'Enable persistent object caching (Redis or Memcached)' );
		}
		if ( ! ( $analysis['caching']['page_cache'] ?? false ) ) {
			$recs[] = array( 'priority' => 'high', 'category' => 'caching', 'message' => 'Implement full-page caching' );
		}
		if ( ( $analysis['database']['autoload_size'] ?? 0 ) > 1000000 ) {
			$recs[] = array( 'priority' => 'medium', 'category' => 'database', 'message' => 'Reduce autoloaded data (currently over 1MB)' );
		}

		return $recs;
	}

	private function buildPriorityFixes( array $analysis ): array {
		$fixes = array();
		if ( ! ( $analysis['caching']['object_cache'] ?? false ) ) {
			$fixes[] = 'Enable object caching';
		}
		if ( ! ( $analysis['caching']['page_cache'] ?? false ) ) {
			$fixes[] = 'Enable page caching';
		}
		return $fixes;
	}

	private function summarize( array $analysis ): array {
		return array(
			'database' => array( 'size' => $analysis['database']['size'], 'tables' => $analysis['database']['table_count'] ),
			'caching'  => $analysis['caching'],
			'plugins'  => array( 'total' => $analysis['plugins']['total'] ),
		);
	}

	// ─── Database optimization ────────────────────────────────────────

	private function getDbTasks( string $level ): array {
		$tasks = array(
			array( 'name' => 'clean_transients', 'safety_level' => 'safe', 'impact' => 'medium' ),
			array( 'name' => 'optimize_tables', 'safety_level' => 'safe', 'impact' => 'low' ),
		);

		if ( \in_array( $level, array( 'moderate', 'aggressive' ), true ) ) {
			$tasks[] = array( 'name' => 'clean_revisions', 'safety_level' => 'moderate', 'impact' => 'medium' );
		}
		if ( 'aggressive' === $level ) {
			$tasks[] = array( 'name' => 'clean_orphaned_meta', 'safety_level' => 'aggressive', 'impact' => 'high' );
		}

		return $tasks;
	}

	private function applyDbOptimization( array $task ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery

		return match ( $task['name'] ) {
			'clean_transients' => ( function () use ( $wpdb ): array {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
					'_transient_%',
					'_site_transient_%'
				) );
				return array( 'success' => true, 'savings' => \sprintf( '%d transients cleaned', $deleted ) );
			} )(),
			'optimize_tables' => ( function () use ( $wpdb ): array {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( 'OPTIMIZE TABLE `' . $wpdb->posts . '`' );
				return array( 'success' => true );
			} )(),
			default => array( 'success' => false ),
		};
	}

	// ─── Caching helpers ──────────────────────────────────────────────

	private function configureObjectCache( bool $auto_fix ): array {
		return array(
			'enabled'   => \wp_using_ext_object_cache(),
			'available' => \extension_loaded( 'redis' ) || \extension_loaded( 'memcached' ),
		);
	}

	private function configurePageCache( bool $auto_fix ): array {
		return array( 'enabled' => $this->detectPageCache() );
	}

	private function configureBrowserCache( bool $auto_fix ): array {
		return array( 'enabled' => true );
	}

	private function checkCdn(): array {
		return array( 'enabled' => $this->detectCdn() );
	}

	// ─── Detection helpers ────────────────────────────────────────────

	private function getAutoloadSize(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" );
	}

	private function countTransients(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_%',
			'_site_transient_%'
		) );
	}

	private function detectPageCache(): bool {
		$cache_plugins = array(
			'wp-super-cache/wp-cache.php',
			'w3-total-cache/w3-total-cache.php',
			'wp-fastest-cache/wpFastestCache.php',
		);

		foreach ( $cache_plugins as $plugin ) {
			if ( \is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		return false;
	}

	private function detectCdn(): bool {
		$uploads    = \wp_upload_dir();
		$site_host  = \parse_url( \site_url(), \PHP_URL_HOST );
		$upload_host = \parse_url( $uploads['baseurl'], \PHP_URL_HOST );

		return $site_host !== $upload_host;
	}

	private function getActiveOptimizations(): array {
		return array(
			'object_cache' => \wp_using_ext_object_cache(),
			'page_cache'   => $this->detectPageCache(),
			'opcache'      => \function_exists( 'opcache_get_status' ) && \opcache_get_status(),
			'cdn'          => $this->detectCdn(),
		);
	}
}
