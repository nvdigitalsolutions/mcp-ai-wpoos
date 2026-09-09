<?php
/**
 * DJ-management tool batch (ecosystem port - Wave F2, dj-management toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/dj-management/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated batch - the D8-compat interface copies resolve via the entry's spl autoloader; the
 * import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root).
 *
 * Tool for managing music library.
 *
 * Allows AI assistants to organize and catalog DJ music libraries.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages and organizes music library.
 */
class WP_MCP_AI_Tool_Manage_Music_Library implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_music_library';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Music Library', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages the DJ music library. Add, update, or search tracks with metadata including genre, BPM, key, and tags.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'add', 'update', 'search', 'delete' ),
				),
				'track_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Track ID (required for update/delete)', 'nvoos-content-graph-pro' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Track title (required for add)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'artist'       => array(
					'type'        => 'string',
					'description' => __( 'Artist name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'album'        => array(
					'type'        => 'string',
					'description' => __( 'Album name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'genre'        => array(
					'type'        => 'string',
					'description' => __( 'Music genre (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'bpm'          => array(
					'type'        => 'number',
					'description' => __( 'Beats per minute (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'key'          => array(
					'type'        => 'string',
					'description' => __( 'Musical key (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 10,
				),
				'duration'     => array(
					'type'        => 'integer',
					'description' => __( 'Track duration in seconds (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'year'         => array(
					'type'        => 'integer',
					'description' => __( 'Release year (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1900,
					'maximum'     => 2100,
				),
				'tags'         => array(
					'type'        => 'array',
					'description' => __( 'Tags for categorization (optional)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'file_path'    => array(
					'type'        => 'string',
					'description' => __( 'File path or URL (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'search_query' => array(
					'type'        => 'string',
					'description' => __( 'Search query (required for search action)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
			),
			'required'             => array( 'action' ),
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
		// Validate action.
		if ( empty( $arguments['action'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Action parameter is required.', 'nvoos-content-graph-pro' )
			);
		}

		$action = sanitize_text_field( $arguments['action'] );

		switch ( $action ) {
			case 'add':
				return $this->add_track( $arguments );
			case 'update':
				return $this->update_track( $arguments );
			case 'search':
				return $this->search_tracks( $arguments );
			case 'delete':
				return $this->delete_track( $arguments );
			default:
				return new WP_Error(
					'tool_error',
					__( 'Invalid action.', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * Add a new track to the library.
	 *
	 * @param array $arguments Arguments.
	 * @return array Result.
	 */
	private function add_track( $arguments ) {
		if ( empty( $arguments['title'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Track title is required for add action.', 'nvoos-content-graph-pro' )
			);
		}

		$title  = sanitize_text_field( $arguments['title'] );
		$artist = ! empty( $arguments['artist'] ) ? sanitize_text_field( $arguments['artist'] ) : '';

		$post_data = array(
			'post_title'  => $title . ( $artist ? ' - ' . $artist : '' ),
			'post_status' => 'publish',
			'post_type'   => 'dj_track',
		);

		$track_id = wp_insert_post( $post_data );

		if ( is_wp_error( $track_id ) ) {
			return new WP_Error(
				'tool_error',
				$track_id->get_error_message()
			);
		}

		// Store metadata.
		$this->save_track_metadata( $track_id, $arguments );

		return array(
			'success'  => true,
			'track_id' => $track_id,
			'message'  => sprintf(
				/* translators: %s: track title */
				__( 'Track "%s" added to library.', 'nvoos-content-graph-pro' ),
				$title
			),
		);
	}

	/**
	 * Update an existing track.
	 *
	 * @param array $arguments Arguments.
	 * @return array Result.
	 */
	private function update_track( $arguments ) {
		if ( empty( $arguments['track_id'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Track ID is required for update action.', 'nvoos-content-graph-pro' )
			);
		}

		$track_id = absint( $arguments['track_id'] );

		if ( ! get_post( $track_id ) || get_post_type( $track_id ) !== 'dj_track' ) {
			return new WP_Error(
				'tool_error',
				__( 'Invalid track ID.', 'nvoos-content-graph-pro' )
			);
		}

		$this->save_track_metadata( $track_id, $arguments );

		return array(
			'success' => true,
			'message' => __( 'Track updated successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Search tracks in the library.
	 *
	 * @param array $arguments Arguments.
	 * @return array Result.
	 */
	private function search_tracks( $arguments ) {
		$search_query = ! empty( $arguments['search_query'] ) ? sanitize_text_field( $arguments['search_query'] ) : '';

		$query_args = array(
			'post_type'      => 'dj_track',
			'posts_per_page' => 50,
			's'              => $search_query,
		);

		$tracks_query = new WP_Query( $query_args );
		$tracks       = array();

		if ( $tracks_query->have_posts() ) {
			while ( $tracks_query->have_posts() ) {
				$tracks_query->the_post();
				$track_id = get_the_ID();

				$tracks[] = array(
					'id'       => $track_id,
					'title'    => get_post_meta( $track_id, '_title', true ),
					'artist'   => get_post_meta( $track_id, '_artist', true ),
					'genre'    => get_post_meta( $track_id, '_genre', true ),
					'bpm'      => get_post_meta( $track_id, '_bpm', true ),
					'key'      => get_post_meta( $track_id, '_key', true ),
					'duration' => get_post_meta( $track_id, '_duration', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'count'   => count( $tracks ),
			'tracks'  => $tracks,
		);
	}

	/**
	 * Delete a track from the library.
	 *
	 * @param array $arguments Arguments.
	 * @return array Result.
	 */
	private function delete_track( $arguments ) {
		if ( empty( $arguments['track_id'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Track ID is required for delete action.', 'nvoos-content-graph-pro' )
			);
		}

		$track_id = absint( $arguments['track_id'] );

		if ( ! get_post( $track_id ) || get_post_type( $track_id ) !== 'dj_track' ) {
			return new WP_Error(
				'tool_error',
				__( 'Invalid track ID.', 'nvoos-content-graph-pro' )
			);
		}

		wp_delete_post( $track_id, true );

		return array(
			'success' => true,
			'message' => __( 'Track deleted successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Save track metadata.
	 *
	 * @param int   $track_id Track ID.
	 * @param array $data Track data.
	 */
	private function save_track_metadata( $track_id, $data ) {
		$fields = array( 'title', 'artist', 'album', 'genre', 'bpm', 'key', 'duration', 'year', 'file_path' );

		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = 'bpm' === $field || 'duration' === $field || 'year' === $field ? absint( $data[ $field ] ) : sanitize_text_field( $data[ $field ] );
				update_post_meta( $track_id, '_' . $field, $value );
			}
		}

		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $data['tags'] );
			update_post_meta( $track_id, '_tags', $tags );
		}
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
