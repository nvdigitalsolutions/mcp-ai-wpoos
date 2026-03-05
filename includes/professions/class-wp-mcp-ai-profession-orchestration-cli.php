<?php
/**
 * WP-CLI Commands for Profession Orchestration.
 *
 * Provides CLI commands for seeding and managing orchestration configurations.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for profession orchestration.
 */
class WP_MCP_AI_Profession_Orchestration_CLI {

	/**
	 * Seed orchestration configurations for all professions.
	 *
	 * Seeds agent roles and task patterns for professions using research-backed
	 * heuristics. This command is idempotent and tracks version to prevent
	 * duplicate seeding.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Force re-seeding even if already done.
	 *
	 * ## EXAMPLES
	 *
	 *     # Seed orchestration configurations
	 *     $ wp profession seed-orchestration
	 *     Success: Seeded 203 agent roles and 6 task patterns.
	 *
	 *     # Force re-seeding
	 *     $ wp profession seed-orchestration --force
	 *     Success: Re-seeded 203 agent roles and 6 task patterns.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function seed_orchestration( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php';
		}

		$force = isset( $assoc_args['force'] ) && $assoc_args['force'];

		WP_CLI::log( 'Starting orchestration configuration seeding...' );

		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$result = $seeder->seed_all( $force );

		if ( ! $result['success'] ) {
			WP_CLI::warning( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( ! empty( $result['errors'] ) ) {
			WP_CLI::warning( sprintf( 'Encountered %d errors:', count( $result['errors'] ) ) );
			foreach ( $result['errors'] as $error ) {
				WP_CLI::log( '  - ' . $error );
			}
		}
	}

	/**
	 * Show orchestration statistics.
	 *
	 * Displays counts of professions by agent role and other orchestration metrics.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp profession orchestration-stats
	 *
	 * @when after_wp_load
	 */
	public function orchestration_stats() {
		$roles = array( 'planner', 'executor', 'critic', 'specialist', 'generalist' );
		$stats = array();

		foreach ( $roles as $role ) {
			$count = count(
				get_posts(
					array(
						'post_type'      => 'mcp_ai_profession',
						'posts_per_page' => -1,
						'post_status'    => 'publish',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter profession CPT by configuration meta; no alternative index-based query available.
							array(
								'key'   => WP_MCP_AI_Profession_CPT::META_AGENT_ROLE,
								'value' => $role,
							),
						),
						'fields'         => 'ids',
					)
				)
			);

			$stats[ $role ] = $count;
		}

		// Count professions with task patterns.
		$with_patterns = count(
			get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter profession CPT by configuration meta; no alternative index-based query available.
						array(
							'key'     => WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS,
							'value'   => '{}',
							'compare' => '!=',
						),
					),
					'fields'         => 'ids',
				)
			)
		);

		WP_CLI::log( 'Orchestration Statistics:' );
		WP_CLI::log( '' );
		WP_CLI::log( 'Agent Roles:' );
		foreach ( $stats as $role => $count ) {
			WP_CLI::log( sprintf( '  %-12s: %d', ucfirst( $role ), $count ) );
		}
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Professions with task patterns: %d', $with_patterns ) );

		$version = get_option( WP_MCP_AI_Profession_Orchestration_Seeder::VERSION_OPTION, 'Not seeded' );
		WP_CLI::log( sprintf( 'Seeder version: %s', $version ) );
	}
}
