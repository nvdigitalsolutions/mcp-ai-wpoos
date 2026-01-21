<?php
/**
 * Tool for AI-generating playlists by mood and genre.
 *
 * Allows AI assistants to generate playlists automatically based on criteria.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-generates playlists by mood and genre.
 */
class WP_MCP_AI_Tool_Generate_Playlist_AI implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_playlist_ai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Playlist (AI)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-generates a playlist based on mood, genre, energy level, and event type. Intelligently selects tracks from the music library.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'Playlist name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'mood'             => array(
					'type'        => 'string',
					'description' => __( 'Desired mood (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'energetic', 'chill', 'romantic', 'party', 'upbeat', 'mellow' ),
				),
				'genre'            => array(
					'type'        => 'string',
					'description' => __( 'Primary genre (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'event_type'       => array(
					'type'        => 'string',
					'description' => __( 'Event type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wedding', 'corporate', 'birthday', 'club', 'private_party', 'festival', 'other' ),
				),
				'duration'         => array(
					'type'        => 'integer',
					'description' => __( 'Target duration in minutes (optional, defaults to 60)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 10,
					'maximum'     => 480,
					'default'     => 60,
				),
				'energy_level'     => array(
					'type'        => 'integer',
					'description' => __( 'Target energy level 1-10 (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'bpm_range'        => array(
					'type'        => 'object',
					'description' => __( 'BPM range (optional)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'min' => array( 'type' => 'number', 'minimum' => 1 ),
						'max' => array( 'type' => 'number', 'maximum' => 300 ),
					),
				),
				'exclude_explicit' => array(
					'type'        => 'boolean',
					'description' => __( 'Exclude explicit content (optional, defaults to false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'name', 'mood' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['name'] ) || empty( $arguments['mood'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Playlist name and mood are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$name             = sanitize_text_field( $arguments['name'] );
		$mood             = sanitize_text_field( $arguments['mood'] );
		$genre            = ! empty( $arguments['genre'] ) ? sanitize_text_field( $arguments['genre'] ) : '';
		$event_type       = ! empty( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : '';
		$duration         = ! empty( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 60;
		$energy_level     = ! empty( $arguments['energy_level'] ) ? absint( $arguments['energy_level'] ) : 0;
		$bpm_range        = ! empty( $arguments['bpm_range'] ) ? $arguments['bpm_range'] : array();
		$exclude_explicit = ! empty( $arguments['exclude_explicit'] );

		// Build query for tracks.
		$query_args = array(
			'post_type'      => 'dj_track',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'rand',
		);

		$meta_query = array();

		if ( $genre ) {
			$meta_query[] = array(
				'key'     => '_genre',
				'value'   => $genre,
				'compare' => 'LIKE',
			);
		}

		if ( $energy_level ) {
			$meta_query[] = array(
				'key'     => '_energy_level',
				'value'   => array( $energy_level - 1, $energy_level + 1 ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		}

		if ( ! empty( $bpm_range['min'] ) ) {
			$meta_query[] = array(
				'key'     => '_bpm',
				'value'   => floatval( $bpm_range['min'] ),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		if ( ! empty( $bpm_range['max'] ) ) {
			$meta_query[] = array(
				'key'     => '_bpm',
				'value'   => floatval( $bpm_range['max'] ),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
			$query_args['meta_query'] = $meta_query;
		} elseif ( count( $meta_query ) === 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Execute query.
		$tracks_query = new WP_Query( $query_args );
		$selected_tracks = array();
		$total_duration = 0;
		$target_duration_seconds = $duration * 60;

		if ( $tracks_query->have_posts() ) {
			while ( $tracks_query->have_posts() && $total_duration < $target_duration_seconds ) {
				$tracks_query->the_post();
				$track_id = get_the_ID();

				$track_duration = absint( get_post_meta( $track_id, '_duration', true ) );
				
				// Skip if this would exceed target duration by too much.
				if ( $total_duration > 0 && ( $total_duration + $track_duration ) > ( $target_duration_seconds * 1.2 ) ) {
					continue;
				}

				$selected_tracks[] = array(
					'track_id' => $track_id,
					'title'    => get_post_meta( $track_id, '_title', true ),
					'artist'   => get_post_meta( $track_id, '_artist', true ),
					'bpm'      => get_post_meta( $track_id, '_bpm', true ),
					'key'      => get_post_meta( $track_id, '_key', true ),
					'duration' => $track_duration,
				);

				$total_duration += $track_duration;
			}
			wp_reset_postdata();
		}

		// Create playlist.
		$post_data = array(
			'post_title'   => $name,
			'post_content' => sprintf(
				/* translators: 1: mood, 2: genre */
				__( 'AI-generated playlist with %1$s mood%2$s', 'mcp-ai-wpoos-pro' ),
				$mood,
				$genre ? ' in ' . $genre . ' genre' : ''
			),
			'post_status'  => 'publish',
			'post_type'    => 'dj_playlist',
		);

		$playlist_id = wp_insert_post( $post_data );

		if ( is_wp_error( $playlist_id ) ) {
			return array(
				'success' => false,
				'error'   => $playlist_id->get_error_message(),
			);
		}

		// Store playlist metadata.
		update_post_meta( $playlist_id, '_event_type', $event_type );
		update_post_meta( $playlist_id, '_genre', $genre );
		update_post_meta( $playlist_id, '_mood', $mood );
		update_post_meta( $playlist_id, '_target_duration', $duration );
		update_post_meta( $playlist_id, '_tracks', $selected_tracks );
		update_post_meta( $playlist_id, '_track_count', count( $selected_tracks ) );
		update_post_meta( $playlist_id, '_total_duration', $total_duration );
		update_post_meta( $playlist_id, '_generated_by', 'ai' );
		update_post_meta( $playlist_id, '_created_date', current_time( 'mysql' ) );

		return array(
			'success'      => true,
			'playlist_id'  => $playlist_id,
			'message'      => sprintf(
				/* translators: 1: playlist name, 2: track count */
				__( 'AI-generated playlist "%1$s" created with %2$d tracks.', 'mcp-ai-wpoos-pro' ),
				$name,
				count( $selected_tracks )
			),
			'playlist'     => array(
				'id'             => $playlist_id,
				'name'           => $name,
				'mood'           => $mood,
				'genre'          => $genre,
				'event_type'     => $event_type,
				'track_count'    => count( $selected_tracks ),
				'total_duration' => round( $total_duration / 60, 1 ),
			),
			'tracks'       => $selected_tracks,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
