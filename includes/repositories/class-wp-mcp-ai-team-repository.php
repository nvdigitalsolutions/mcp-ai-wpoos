<?php
/**
 * Team Repository.
 *
 * Data access layer for teams.
 * Handles all database queries and caching for team data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles team data persistence and retrieval.
 */
class WP_MCP_AI_Team_Repository {
	/**
	 * Cache group for teams.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_teams';

	/**
	 * Cache expiration time (1 hour).
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Find all teams.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Post[] Array of team posts.
	 */
	public function find_all( $args = array() ) {
		$cache_key = 'all_teams_' . md5( wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$defaults = array(
			'post_type'      => WP_MCP_AI_Team_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$query_args = wp_parse_args( $args, $defaults );
		$query      = new WP_Query( $query_args );
		$teams      = $query->posts;

		wp_cache_set( $cache_key, $teams, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $teams;
	}

	/**
	 * Find one team by slug or ID.
	 *
	 * @param string|int $team Team slug or ID.
	 * @return WP_Post|null Team post or null if not found.
	 */
	public function find_one( $team ) {
		if ( is_numeric( $team ) ) {
			$post = get_post( absint( $team ) );

			if ( $post && WP_MCP_AI_Team_CPT::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				return $post;
			}

			return null;
		}

		$cache_key = 'team_' . sanitize_key( $team );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Team_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'name'           => sanitize_title( $team ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::CACHE_EXPIRATION );
			return $post;
		}

		return null;
	}

	/**
	 * Save a team.
	 *
	 * @param array $data Team data.
	 * @return int|WP_Error Team ID on success, WP_Error on failure.
	 */
	public function save( $data ) {
		$team_id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		// Prepare post data.
		$post_data = array(
			'post_type'   => WP_MCP_AI_Team_CPT::POST_TYPE,
			'post_status' => 'publish',
		);

		if ( $team_id ) {
			$post_data['ID'] = $team_id;
		}

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['description'] );
		}

		// Insert or update the post.
		if ( $team_id ) {
			$result = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$team_id = $result;

		// Update metadata.
		if ( isset( $data['members'] ) && is_array( $data['members'] ) ) {
			// Convert slugs to IDs if needed.
			$member_ids = array();
			foreach ( $data['members'] as $member ) {
				if ( is_numeric( $member ) ) {
					$member_ids[] = absint( $member );
				} else {
					// Try to find profession by slug.
					$profession = get_posts(
						array(
							'post_type'      => 'mcp_ai_profession',
							'name'           => sanitize_title( $member ),
							'posts_per_page' => 1,
							'fields'         => 'ids',
						)
					);
					if ( ! empty( $profession ) ) {
						$member_ids[] = $profession[0];
					}
				}
			}
			update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, $member_ids );
		}

		if ( isset( $data['default_provider'] ) ) {
			update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, sanitize_key( $data['default_provider'] ) );
		}

		if ( isset( $data['default_model'] ) ) {
			update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, sanitize_text_field( $data['default_model'] ) );
		}

		if ( isset( $data['default_temperature'] ) ) {
			update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DEFAULT_TEMPERATURE, floatval( $data['default_temperature'] ) );
		}

		// Clear cache.
		$this->clear_cache();

		return $team_id;
	}

	/**
	 * Clear cache for teams.
	 *
	 * @param int $post_id Optional post ID to clear specific cache.
	 */
	public function clear_cache( $post_id = null ) {
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && isset( $post->post_name ) ) {
				wp_cache_delete( 'team_' . $post->post_name, self::CACHE_GROUP );
			}
		}

		// Clear all teams cache.
		wp_cache_delete( 'all_teams_' . md5( wp_json_encode( array() ) ), self::CACHE_GROUP );
	}
}
