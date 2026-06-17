<?php
/**
 * PARA Lifecycle Service.
 *
 * Automates PARA bucket transitions:
 * - When a Project's status becomes `completed` or `cancelled`, the project is
 *   reassigned to the Archives bucket.
 * - When a QMS document is marked obsolete, it is archived (via cross-bridge).
 * - Daily sweep detects dormant Areas (no recent activity) and surfaces them
 *   for review via an admin notice (advisory only — never auto-archives areas).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle automation for PARA-classified posts.
 */
class WP_MCP_AI_PARA_Lifecycle {

	const CRON_HOOK = 'wp_mcp_ai_para_lifecycle_sweep';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// React to project status changes (the project CPT writes _project_status meta).
		add_action( 'updated_post_meta', array( __CLASS__, 'on_project_status_change' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_project_status_change' ), 10, 4 );

		// Daily sweep cron.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sweep' ) );

		// Schedule cron.
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );

		// Register deactivation cleanup hook (called from plugin deactivation).
		add_action( 'wp_mcp_ai_pro_deactivate', array( __CLASS__, 'unschedule_cron' ) );
	}

	/**
	 * Schedule the daily sweep.
	 */
	public static function schedule_cron() {
		if ( ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron.
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Auto-archive a project when its status becomes completed/cancelled.
	 *
	 * Hooked to updated/added_post_meta — filters for the relevant key.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function on_project_status_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( '_project_status' !== $meta_key ) {
			return;
		}
		if ( ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'mcp_ai_project' !== $post->post_type ) {
			return;
		}
		$value = is_string( $meta_value ) ? sanitize_key( $meta_value ) : '';
		if ( in_array( $value, array( 'completed', 'cancelled' ), true ) ) {
			$current = WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $post_id );
			if ( 'archives' === $current ) {
				return;
			}
			$reason = sprintf(
				/* translators: %s: project status. */
				__( 'Auto-archived: project status changed to %s.', 'mcp-ai-wpoos-pro' ),
				$value
			);
			WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'archives', $reason );
		}
	}

	/**
	 * Daily sweep:
	 *  - Detect dormant Areas and store an advisory list in a transient.
	 *  - Identify orphaned Resources unreferenced for 90+ days.
	 */
	public static function run_sweep() {
		if ( ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			return;
		}

		$dormancy_days = (int) apply_filters( 'wp_mcp_ai_para_dormancy_days', 30 );
		$resource_days = (int) apply_filters( 'wp_mcp_ai_para_resource_dormancy_days', 90 );

		$dormant_areas      = self::find_dormant_areas( $dormancy_days );
		$dormant_resources  = self::find_dormant_resources( $resource_days );
		$archive_candidates = self::find_archive_candidates();

		set_transient(
			'wp_mcp_ai_para_review_summary',
			array(
				'generated_at'       => time(),
				'dormant_areas'      => $dormant_areas,
				'dormant_resources'  => $dormant_resources,
				'archive_candidates' => $archive_candidates,
			),
			DAY_IN_SECONDS
		);

		/**
		 * Fires after the daily PARA lifecycle sweep completes.
		 *
		 * @since 1.2.0
		 *
		 * @param array $summary Sweep summary.
		 */
		do_action(
			'wp_mcp_ai_para_sweep_complete',
			array(
				'dormant_areas'      => $dormant_areas,
				'dormant_resources'  => $dormant_resources,
				'archive_candidates' => $archive_candidates,
			)
		);
	}

	/**
	 * Find Areas that have not been reviewed/touched recently.
	 *
	 * @param int $days Threshold in days.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_dormant_areas( $days ) {
		$days      = max( 1, (int) $days );
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$areas = get_posts(
			array(
				'post_type'      => 'mcp_ai_area',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		$dormant = array();
		foreach ( $areas as $area_id ) {
			$last_modified = get_post_field( 'post_modified_gmt', $area_id );
			$last_reviewed = (string) get_post_meta( $area_id, '_para_last_reviewed', true );
			$most_recent   = $last_reviewed ? max( $last_modified, $last_reviewed ) : $last_modified;
			if ( $most_recent && $most_recent < $threshold ) {
				$dormant[] = array(
					'id'            => (int) $area_id,
					'title'         => get_the_title( $area_id ),
					'last_activity' => $most_recent,
				);
			}
		}
		return $dormant;
	}

	/**
	 * Find Resources unreferenced for the threshold.
	 *
	 * @param int $days Days threshold.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_dormant_resources( $days ) {
		$days      = max( 1, (int) $days );
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$resource_term = get_term_by( 'slug', 'resources', WP_MCP_AI_PARA_Taxonomy::TAXONOMY );
		if ( ! $resource_term || is_wp_error( $resource_term ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_PARA_Taxonomy::get_object_types(),
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => WP_MCP_AI_PARA_Taxonomy::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => array( (int) $resource_term->term_id ),
						'include_children' => true,
					),
				),
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => $threshold,
					),
				),
			)
		);

		$out = array();
		foreach ( $query->posts as $pid ) {
			$out[] = array(
				'id'            => (int) $pid,
				'title'         => get_the_title( $pid ),
				'last_modified' => get_post_field( 'post_modified_gmt', $pid ),
			);
		}
		return $out;
	}

	/**
	 * Find candidate posts that could be archived.
	 *
	 * Currently: completed/cancelled projects not yet archived.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_archive_candidates() {
		$archives_term = get_term_by( 'slug', 'archives', WP_MCP_AI_PARA_Taxonomy::TAXONOMY );
		$archives_id   = ( $archives_term && ! is_wp_error( $archives_term ) ) ? (int) $archives_term->term_id : 0;

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_project',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_project_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$candidates = array();
		foreach ( $query->posts as $pid ) {
			$bucket = WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $pid );
			if ( 'archives' !== $bucket ) {
				$candidates[] = array(
					'id'     => (int) $pid,
					'title'  => get_the_title( $pid ),
					'reason' => __( 'Project completed/cancelled but not archived.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}
		unset( $archives_id );
		return $candidates;
	}

	/**
	 * Get the cached weekly review summary (or generate fresh).
	 *
	 * @return array
	 */
	public static function get_weekly_review_summary() {
		$cached = get_transient( 'wp_mcp_ai_para_review_summary' );
		if ( false !== $cached ) {
			return $cached;
		}
		self::run_sweep();
		$cached = get_transient( 'wp_mcp_ai_para_review_summary' );
		return is_array( $cached ) ? $cached : array(
			'generated_at'       => time(),
			'dormant_areas'      => array(),
			'dormant_resources'  => array(),
			'archive_candidates' => array(),
		);
	}
}
