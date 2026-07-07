<?php
/**
 * Tool for creating custom playlists.
 *
 * Allows AI assistants to create and manage DJ playlists.
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
		return __( 'Create a new playlist or update an existing playlist. If playlist_id is provided, updates the existing playlist instead of creating a new one. Supports event-specific and genre-based playlists with tracks, ordering, and metadata. Use this tool for both creating new playlists and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'playlist_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Optional playlist ID. If provided, updates the existing playlist instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
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
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Playlist name is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if this is an update operation.
		$playlist_id       = isset( $arguments['playlist_id'] ) ? absint( $arguments['playlist_id'] ) : 0;
		$is_update         = false;
		$existing_playlist = null;

		if ( $playlist_id ) {
			// Verify playlist exists and user has permission to update it.
			$existing_playlist = get_post( $playlist_id );

			if ( ! $existing_playlist || 'dj_playlist' !== $existing_playlist->post_type ) {
				return array(
					'success' => false,
					'error'   => __( 'Playlist not found.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_playlist->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return array(
					'success' => false,
					'error'   => __( 'You do not have permission to update this playlist.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$is_update = true;
		}

		// Sanitize inputs.
		$name            = sanitize_text_field( $arguments['name'] );
		$description     = ! empty( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$event_type      = ! empty( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : '';
		$genre           = ! empty( $arguments['genre'] ) ? sanitize_text_field( $arguments['genre'] ) : '';
		$mood            = ! empty( $arguments['mood'] ) ? sanitize_text_field( $arguments['mood'] ) : '';
		$tracks          = ! empty( $arguments['tracks'] ) ? $arguments['tracks'] : array();
		$target_duration = ! empty( $arguments['target_duration'] ) ? absint( $arguments['target_duration'] ) : 0;

		if ( $is_update ) {
			// Update existing playlist post.
			$post_data = array(
				'ID'           => $playlist_id,
				'post_title'   => $name,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}
		} else {
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
		}

		// Store playlist metadata.
		update_post_meta( $playlist_id, '_event_type', $event_type );
		update_post_meta( $playlist_id, '_genre', $genre );
		update_post_meta( $playlist_id, '_mood', $mood );
		update_post_meta( $playlist_id, '_target_duration', $target_duration );
		if ( ! $is_update ) {
			update_post_meta( $playlist_id, '_created_date', current_time( 'mysql' ) );
		}

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
			'updated'     => $is_update,
			'message'     => sprintf(
				/* translators: %s: playlist name */
				$is_update ? __( 'Playlist "%s" updated successfully.', 'mcp-ai-wpoos-pro' ) : __( 'Playlist "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
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
	public function get_capability_flags() {
		return array( 'write' );
	}
}
