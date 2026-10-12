<?php
/**
 * GTmetrix Toolkit — Snapshot Store (CPT-backed).
 *
 * Persists report snapshots as a `mcp_ai_gtmetrix_report` custom post type so
 * scores, metrics and audit subsets survive GTmetrix's 1-month report
 * retention. Caches reads in 5-minute transients.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-store.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-settings.php';

/**
 * CPT-backed report snapshot store.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Snapshot_Store implements WP_MCP_AI_GTmetrix_Store {

	const CPT = 'mcp_ai_gtmetrix_report';

	/**
	 * Register the snapshot CPT and prune cron.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
				'label'        => __( 'GTmetrix Reports', 'mcp-ai-wpoos-pro' ),
				'capabilities' => array(
					'create_posts' => 'manage_options',
				),
				'map_meta_cap' => true,
			)
		);

		if ( ! wp_next_scheduled( 'wp_mcp_ai_gtmetrix_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_mcp_ai_gtmetrix_prune' );
		}
	}

	/**
	 * Save a report snapshot.
	 *
	 * @param array $report Report attributes (see the proposal Appendix A.2).
	 * @return int|WP_Error Snapshot post ID or WP_Error.
	 */
	public function save_report( $report ) {
		if ( empty( $report['id'] ) || empty( $report['url'] ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'Report snapshot requires an id and url.', 'mcp-ai-wpoos-pro' ) );
		}

		$existing = get_posts(
			// phpcs:disable WordPress.DB.SlowDBQuery -- bounded lookups on a small private CPT.
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_key'       => '_gtmetrix_report_id',
				'meta_value'     => $report['id'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
			// phpcs:enable WordPress.DB.SlowDBQuery
		);

		$post_id = ! empty( $existing ) ? absint( $existing[0] ) : 0;
		$title   = sprintf( '%s — %s', $report['url'], gmdate( 'Y-m-d H:i', isset( $report['created'] ) ? absint( $report['created'] ) : time() ) );

		$post_data = array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => $title,
		);
		if ( 0 < $post_id ) {
			$post_data['ID'] = $post_id;
			wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_save_failed', __( 'The GTmetrix snapshot could not be saved.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $post_id, '_gtmetrix_report_id', sanitize_text_field( $report['id'] ) );
		update_post_meta( $post_id, '_gtmetrix_url', esc_url_raw( $report['url'] ) );
		update_post_meta( $post_id, '_gtmetrix_report', $report );

		// Keep the URL → page_id map current for retest/sweep.
		$this->update_page_map( $report );

		set_transient( $this->cache_key( $report['id'] ), $report, 5 * MINUTE_IN_SECONDS );

		return $post_id;
	}

	/**
	 * Get a snapshot by GTmetrix report ID.
	 *
	 * @param string $report_id GTmetrix report ID.
	 * @return array|WP_Error
	 */
	public function get_report( $report_id ) {
		$cached = get_transient( $this->cache_key( $report_id ) );
		if ( false !== $cached ) {
			return $cached;
		}

		$posts = get_posts(
			// phpcs:disable WordPress.DB.SlowDBQuery -- bounded lookups on a small private CPT.
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'meta_key'       => '_gtmetrix_report_id',
				'meta_value'     => $report_id,
				'posts_per_page' => 1,
			)
			// phpcs:enable WordPress.DB.SlowDBQuery
		);

		if ( empty( $posts ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_snapshot_missing', __( 'No local snapshot for this GTmetrix report.', 'mcp-ai-wpoos-pro' ) );
		}

		$report = get_post_meta( $posts[0]->ID, '_gtmetrix_report', true );
		if ( ! is_array( $report ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_snapshot_missing', __( 'The local snapshot is unreadable.', 'mcp-ai-wpoos-pro' ) );
		}

		set_transient( $this->cache_key( $report_id ), $report, 5 * MINUTE_IN_SECONDS );

		return $report;
	}

	/**
	 * Latest snapshot for a URL.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error
	 */
	public function get_latest_for_url( $url ) {
		$list = $this->list_for_url( $url, 1 );

		if ( empty( $list ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_snapshot_missing', __( 'No local snapshot for this URL.', 'mcp-ai-wpoos-pro' ) );
		}

		return $list[0];
	}

	/**
	 * Snapshots for a URL, newest first.
	 *
	 * @param string $url   Page URL.
	 * @param int    $limit Maximum entries.
	 * @return array
	 */
	public function list_for_url( $url, $limit = 10 ) {
		$posts = get_posts(
			// phpcs:disable WordPress.DB.SlowDBQuery -- bounded lookups on a small private CPT.
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'meta_key'       => '_gtmetrix_url',
				'meta_value'     => esc_url_raw( $url ),
				'posts_per_page' => absint( $limit ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
			// phpcs:enable WordPress.DB.SlowDBQuery
		);

		$snapshots = array();
		foreach ( $posts as $post ) {
			$report = get_post_meta( $post->ID, '_gtmetrix_report', true );
			if ( is_array( $report ) ) {
				$snapshots[] = $report;
			}
		}

		return $snapshots;
	}

	/**
	 * Remove snapshots older than the retention window.
	 *
	 * @param int $retention_days Retention window in days.
	 * @return int Number of removed snapshots.
	 */
	public function prune( $retention_days ) {
		$cutoff = time() - ( absint( $retention_days ) * DAY_IN_SECONDS );

		$posts = get_posts(
			// phpcs:disable WordPress.DB.SlowDBQuery, WordPress.WP.PostsPerPage -- bounded prune batch on a private CPT.
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', $cutoff ),
					),
				),
			)
			// phpcs:enable WordPress.DB.SlowDBQuery, WordPress.WP.PostsPerPage
		);

		$removed = 0;
		foreach ( $posts as $post_id ) {
			if ( wp_delete_post( absint( $post_id ), true ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Transient cache key for a report ID.
	 *
	 * @param string $report_id GTmetrix report ID.
	 * @return string
	 */
	private function cache_key( $report_id ) {
		return 'gtmetrix_report_' . md5( (string) $report_id );
	}

	/**
	 * Update the WP-side URL → page_id map.
	 *
	 * @param array $report Report snapshot.
	 * @return void
	 */
	private function update_page_map( $report ) {
		if ( empty( $report['page'] ) ) {
			return;
		}

		$map = get_option( 'wp_mcp_ai_gtmetrix_pages', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ $report['url'] ] = $report['page'];
		update_option( 'wp_mcp_ai_gtmetrix_pages', $map, false );
	}

	/**
	 * Resolve a GTmetrix page_id for a URL from the WP-side map.
	 *
	 * @param string $url Page URL.
	 * @return string Empty when unknown.
	 */
	public static function page_id_for_url( $url ) {
		$map = get_option( 'wp_mcp_ai_gtmetrix_pages', array() );

		return is_array( $map ) && isset( $map[ $url ] ) ? (string) $map[ $url ] : '';
	}
}
