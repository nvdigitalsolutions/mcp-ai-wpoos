<?php
/**
 * Tool for listing trending/popular tracks from the DJ music library.
 *
 * Allows AI assistants to query trending tracks filtered by genre,
 * BPM range, or time period.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists trending/popular tracks from the DJ music library.
 */
class WP_MCP_AI_Tool_Get_Trending_Tracks implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_trending_tracks';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Trending Tracks', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists trending/popular tracks from the DJ music library, optionally filtered by genre, BPM range, or time period. Returns structured results with title, artist, BPM, genre, play count, and last played date.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'genre'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by music genre (e.g. "house", "hip-hop", "techno").', 'mcp-ai-wpoos-pro' ),
				),
				'min_bpm' => array(
					'type'        => 'integer',
					'description' => __( 'Minimum BPM to filter tracks.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'max_bpm' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum BPM to filter tracks.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'period'  => array(
					'type'        => 'string',
					'description' => __( 'Time period for trending data. "week" returns tracks popular this week, "month" this month, "year" this year.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'week', 'month', 'year' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tracks to return. Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'dj_management',
			'post_type'             => 'dj_track',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'dj', 'music_producer', 'entertainer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the DJ Management Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.7.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_dj_management_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.7.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Trending Tracks tool requires the DJ Management Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Trending tracks results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if DJ Management toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_dj_management_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'DJ Management Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse and sanitize arguments.
		$genre   = isset( $arguments['genre'] ) ? sanitize_text_field( $arguments['genre'] ) : '';
		$min_bpm = isset( $arguments['min_bpm'] ) ? absint( $arguments['min_bpm'] ) : 0;
		$max_bpm = isset( $arguments['max_bpm'] ) ? absint( $arguments['max_bpm'] ) : 0;
		$period  = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : '';
		$limit   = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 200 ) {
			$limit = 200;
		}

		// Build WP_Query arguments for dj_track post type.
		$query_args = array(
			'post_type'      => 'dj_track',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_play_count',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		// Apply time period filter via date_query.
		if ( in_array( $period, array( 'week', 'month', 'year' ), true ) ) {
			$date_query = array(
				'after' => '',
			);

			switch ( $period ) {
				case 'week':
					$date_query['after'] = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
					break;
				case 'month':
					$date_query['after'] = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
					break;
				case 'year':
					$date_query['after'] = gmdate( 'Y-m-d H:i:s', strtotime( '-365 days' ) );
					break;
			}

			$query_args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $date_query['after'],
				),
			);
		}

		// Build meta_query for BPM and genre filters.
		$meta_query = array();

		if ( $min_bpm > 0 || $max_bpm > 0 ) {
			$bpm_meta = array(
				'key'  => '_bpm',
				'type' => 'NUMERIC',
			);

			if ( $min_bpm > 0 ) {
				$bpm_meta['value']   = $min_bpm;
				$bpm_meta['compare'] = '>=';
			}

			if ( $max_bpm > 0 ) {
				if ( $min_bpm > 0 ) {
					// Both min and max BPM specified: use BETWEEN.
					$meta_query[] = array(
						'key'     => '_bpm',
						'value'   => array( $min_bpm, $max_bpm ),
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					);
				} else {
					// Only max BPM.
					$meta_query[] = array(
						'key'     => '_bpm',
						'value'   => $max_bpm,
						'type'    => 'NUMERIC',
						'compare' => '<=',
					);
				}
			} else {
				// Only min BPM.
				$meta_query[] = $bpm_meta;
			}
		}

		if ( ! empty( $genre ) ) {
			$meta_query[] = array(
				'key'     => '_genre',
				'value'   => $genre,
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		$tracks   = array();
		$post_ids = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id    = get_the_ID();
				$post_ids[] = $post_id;

				$tracks[] = array(
					'id'          => $post_id,
					'title'       => get_the_title(),
					'artist'      => get_post_meta( $post_id, '_artist', true ) ? get_post_meta( $post_id, '_artist', true ) : '',
					'bpm'         => get_post_meta( $post_id, '_bpm', true ) ? (float) get_post_meta( $post_id, '_bpm', true ) : 0.0,
					'genre'       => get_post_meta( $post_id, '_genre', true ) ? get_post_meta( $post_id, '_genre', true ) : '',
					'key'         => get_post_meta( $post_id, '_key', true ) ? get_post_meta( $post_id, '_key', true ) : '',
					'play_count'  => get_post_meta( $post_id, '_play_count', true ) ? (int) get_post_meta( $post_id, '_play_count', true ) : 0,
					'last_played' => get_post_meta( $post_id, '_last_played', true ) ? get_post_meta( $post_id, '_last_played', true ) : '',
					'energy'      => get_post_meta( $post_id, '_energy_level', true ) ? get_post_meta( $post_id, '_energy_level', true ) : '',
				);
			}
			wp_reset_postdata();
		}

		// If no dj_track posts were found, it's likely the post type doesn't exist yet.
		if ( empty( $post_ids ) && ! post_type_exists( 'dj_track' ) ) {
			return array(
				'success'      => true,
				'message'      => __( 'No DJ track library has been set up yet. Use the Manage Music Library tool to add tracks, or create a "dj_track" custom post type before populating your music library. Once tracks are added, trending data will be available here.', 'mcp-ai-wpoos-pro' ),
				'tracks'       => array(),
				'total'        => 0,
				'setup_needed' => true,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of tracks found */
				_n(
					'Found %d trending track.',
					'Found %d trending tracks.',
					count( $tracks ),
					'mcp-ai-wpoos-pro'
				),
				count( $tracks )
			),
			'tracks'  => $tracks,
			'total'   => count( $tracks ),
			'filters' => array(
				'genre'   => $genre,
				'min_bpm' => $min_bpm,
				'max_bpm' => $max_bpm,
				'period'  => $period,
				'limit'   => $limit,
			),
		);
	}
}
