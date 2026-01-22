<?php
/**
 * Tool for creating custom playlists.
 *
 * Allows AI assistants to create and manage DJ playlists.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates custom DJ playlists.
 */
class WP_MCP_AI_Tool_Create_Playlist implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_playlist';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Playlist', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a custom DJ playlist with tracks, ordering, and metadata. Supports event-specific and genre-based playlists.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Playlist name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'Playlist description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'event_type'      => array(
					'type'        => 'string',
					'description' => __( 'Event type this playlist is for (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wedding', 'corporate', 'birthday', 'club', 'private_party', 'festival', 'other' ),
				),
				'genre'           => array(
					'type'        => 'string',
					'description' => __( 'Primary genre (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'mood'            => array(
					'type'        => 'string',
					'description' => __( 'Playlist mood (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'energetic', 'chill', 'romantic', 'party', 'upbeat', 'mellow' ),
				),
				'tracks'          => array(
					'type'        => 'array',
					'description' => __( 'Array of track objects (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'    => array( 'type' => 'string' ),
							'artist'   => array( 'type' => 'string' ),
							'bpm'      => array( 'type' => 'number' ),
							'key'      => array( 'type' => 'string' ),
							'duration' => array( 'type' => 'number' ),
						),
					),
				),
				'target_duration' => array(
					'type'        => 'number',
					'description' => __( 'Target playlist duration in minutes (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Playlist name is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$name            = sanitize_text_field( $arguments['name'] );
		$description     = ! empty( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$event_type      = ! empty( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : '';
		$genre           = ! empty( $arguments['genre'] ) ? sanitize_text_field( $arguments['genre'] ) : '';
		$mood            = ! empty( $arguments['mood'] ) ? sanitize_text_field( $arguments['mood'] ) : '';
		$tracks          = ! empty( $arguments['tracks'] ) ? $arguments['tracks'] : array();
		$target_duration = ! empty( $arguments['target_duration'] ) ? absint( $arguments['target_duration'] ) : 0;

		// Create playlist post.
		$post_data = array(
			'post_title'   => $name,
			'post_content' => $description,
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
		update_post_meta( $playlist_id, '_target_duration', $target_duration );
		update_post_meta( $playlist_id, '_created_date', current_time( 'mysql' ) );

		// Process and store tracks.
		$processed_tracks = array();
		$total_duration   = 0;

		foreach ( $tracks as $track ) {
			$processed_track = array(
				'title'    => ! empty( $track['title'] ) ? sanitize_text_field( $track['title'] ) : '',
				'artist'   => ! empty( $track['artist'] ) ? sanitize_text_field( $track['artist'] ) : '',
				'bpm'      => ! empty( $track['bpm'] ) ? floatval( $track['bpm'] ) : 0,
				'key'      => ! empty( $track['key'] ) ? sanitize_text_field( $track['key'] ) : '',
				'duration' => ! empty( $track['duration'] ) ? absint( $track['duration'] ) : 0,
			);

			$processed_tracks[] = $processed_track;
			$total_duration    += $processed_track['duration'];
		}

		update_post_meta( $playlist_id, '_tracks', $processed_tracks );
		update_post_meta( $playlist_id, '_track_count', count( $processed_tracks ) );
		update_post_meta( $playlist_id, '_total_duration', $total_duration );

		return array(
			'success'     => true,
			'playlist_id' => $playlist_id,
			'message'     => sprintf(
				/* translators: %s: playlist name */
				__( 'Playlist "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$name
			),
			'playlist'    => array(
				'id'              => $playlist_id,
				'name'            => $name,
				'event_type'      => $event_type,
				'genre'           => $genre,
				'mood'            => $mood,
				'track_count'     => count( $processed_tracks ),
				'total_duration'  => $total_duration,
				'target_duration' => $target_duration,
			),
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
