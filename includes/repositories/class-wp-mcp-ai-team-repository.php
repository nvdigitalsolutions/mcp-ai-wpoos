<?php
/**
 * Team Repository.
 *
 * Data access layer for teams.
 * Handles all database queries and caching for team data.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
			$member_ids      = array();
			$missing_members = array();
			foreach ( $data['members'] as $member ) {
				if ( is_numeric( $member ) ) {
					$member_ids[] = absint( $member );
				} else {
					// Try to find profession by slug.
					$profession = get_posts(
						array(
							'post_type'      => 'mcp_ai_profession',
							'name'           => sanitize_title( $member ),
							'post_status'    => 'publish',
							'posts_per_page' => 1,
							'fields'         => 'ids',
						)
					);
					if ( ! empty( $profession ) ) {
						$member_ids[] = $profession[0];
					} else {
						// Log missing profession for debugging.
						$missing_members[] = $member;
						WP_MCP_AI_Logger::log_event(
							'warning',
							sprintf( 'Team %s references profession "%s" which does not exist in database.', $team_id, $member )
						);
					}
				}
			}

			// Log warning if some members couldn't be resolved.
			if ( ! empty( $missing_members ) ) {
				WP_MCP_AI_Logger::log_event(
					'warning',
					sprintf( 'Team ID %d saved with %d/%d members. Missing professions: %s', $team_id, count( $member_ids ), count( $data['members'] ), implode( ', ', $missing_members ) )
				);
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

	/**
	 * Re-link team member IDs after a destructive profession Replace All.
	 *
	 * Teams store resolved profession post IDs in their members meta. The
	 * "Replace All Professions" reseed force-deletes every profession post
	 * and re-creates fresh ones with new IDs, orphaning each team's member
	 * list — the members endpoint then resolves zero members while the
	 * teams catalogue still counts the raw meta entries. This re-resolves
	 * every stored member ID through the pre-delete ID → slug map to the
	 * re-seeded profession posts, keeps IDs that still resolve to a live
	 * profession, and drops the rest.
	 *
	 * @since 1.9.6
	 * @param array<int, string> $old_slug_by_id Pre-delete map of profession post ID → post_name.
	 * @return int Number of teams whose member meta was updated.
	 */
	public static function relink_members_after_profession_replace( array $old_slug_by_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) && file_exists( WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) && file_exists( WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php';
		}

		if ( empty( $old_slug_by_id ) || ! post_type_exists( WP_MCP_AI_Profession_CPT::POST_TYPE ) || ! post_type_exists( WP_MCP_AI_Team_CPT::POST_TYPE ) ) {
			return 0;
		}

		// Fresh slug → ID map of the re-seeded professions.
		$new_id_by_slug = array();
		$fresh          = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $fresh as $profession ) {
			if ( ! empty( $profession->post_name ) ) {
				$new_id_by_slug[ $profession->post_name ] = $profession->ID;
			}
		}

		$teams    = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Team_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$relinked = 0;

		foreach ( $teams as $team ) {
			$members = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
			if ( ! is_array( $members ) || empty( $members ) ) {
				continue;
			}

			$remapped = array();
			foreach ( $members as $member_id ) {
				$member_id = absint( $member_id );
				if ( isset( $old_slug_by_id[ $member_id ] ) && isset( $new_id_by_slug[ $old_slug_by_id[ $member_id ] ] ) ) {
					$remapped[] = $new_id_by_slug[ $old_slug_by_id[ $member_id ] ];
				} elseif ( 'mcp_ai_profession' === get_post_type( $member_id ) ) {
					// Not part of the replace set — still a live profession.
					$remapped[] = $member_id;
				}
			}

			$remapped   = array_values( array_unique( $remapped ) );
			$normalized = array_values( array_unique( array_map( 'absint', $members ) ) );
			if ( $remapped !== $normalized ) {
				update_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, $remapped );
				++$relinked;
			}
		}

		return $relinked;
	}
}
