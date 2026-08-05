<?php
/**
 * Tool for updating playlist rotation status.
 *
 * Allows AI assistants to promote, demote, or remove tracks from a playlist
 * based on performance metrics. Supports dry_run mode for previewing changes.
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
 * Updates the rotation status of tracks in a playlist.
 */
class WP_MCP_AI_Tool_Update_Playlist_Rotation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_playlist_rotation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Playlist Rotation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates the rotation status of tracks in a playlist — promotes, demotes, or removes tracks based on performance metrics. In dry_run mode (default), previews what would change without making modifications. In live mode, updates track metadata to reflect the new rotation status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'playlist_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the playlist to update rotation for.', 'mcp-ai-wpoos-pro' ),
				),
				'track_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Array of track IDs to update rotation for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
				),
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Rotation action to perform. "promote" moves tracks up in rotation priority, "demote" moves them down, "remove" takes them out of rotation.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'promote', 'demote', 'remove' ),
				),
				'reason'      => array(
					'type'        => 'string',
					'description' => __( 'Optional reason for the rotation change (logged in track metadata).', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, previews changes without actually modifying any data. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'playlist_id', 'track_ids', 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'post_type'             => 'dj_playlist',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'dj', 'music_producer', 'entertainer' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'requires-capability',
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
		return __( 'The Update Playlist Rotation tool requires the DJ Management Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Valid rotation actions.
	 *
	 * @since 2.7.0
	 * @var string[]
	 */
	const VALID_ACTIONS = array( 'promote', 'demote', 'remove' );

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Rotation update results or dry_run preview.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if DJ Management toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_dj_management_toolkit'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'DJ Management Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse and sanitize arguments.
		$playlist_id = isset( $arguments['playlist_id'] ) ? absint( $arguments['playlist_id'] ) : 0;
		$action      = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$reason      = isset( $arguments['reason'] ) ? sanitize_text_field( $arguments['reason'] ) : '';
		$dry_run     = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Validate playlist_id.
		if ( $playlist_id <= 0 ) {
			return new WP_Error(
				'tool_error',
				__( 'A valid playlist ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify playlist exists and is correct post type.
		$playlist = get_post( $playlist_id );
		if ( ! $playlist || 'dj_playlist' !== $playlist->post_type ) {
			return new WP_Error(
				'tool_error',
				__( 'Playlist not found or invalid playlist type.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$is_author       = absint( $playlist->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error(
				'tool_error',
				__( 'You do not have permission to update this playlist.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate action.
		if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
			return new WP_Error(
				'tool_error',
				sprintf(
					/* translators: %s: comma-separated list of valid actions */
					__( 'Invalid action. Valid actions are: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', self::VALID_ACTIONS )
				)
			);
		}

		// Parse and validate track_ids.
		$track_ids = isset( $arguments['track_ids'] ) ? $arguments['track_ids'] : array();
		if ( ! is_array( $track_ids ) || empty( $track_ids ) ) {
			return new WP_Error(
				'tool_error',
				__( 'At least one track ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize and deduplicate track IDs.
		$track_ids = array_unique( array_map( 'absint', $track_ids ) );
		$track_ids = array_filter(
			$track_ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $track_ids ) ) {
			return new WP_Error(
				'tool_error',
				__( 'No valid track IDs provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Resolve track information.
		$track_details = array();
		$not_found     = array();

		foreach ( $track_ids as $track_id ) {
			$track_post = get_post( $track_id );

			if ( ! $track_post || 'dj_track' !== $track_post->post_type ) {
				$not_found[] = $track_id;
				continue;
			}

			$current_rotation = get_post_meta( $track_id, '_rotation_status', true ) ? get_post_meta( $track_id, '_rotation_status', true ) : 'active';
			$current_priority = get_post_meta( $track_id, '_rotation_priority', true ) ? (int) get_post_meta( $track_id, '_rotation_priority', true ) : 0;
			$play_count       = get_post_meta( $track_id, '_play_count', true ) ? (int) get_post_meta( $track_id, '_play_count', true ) : 0;

			// Compute new rotation values based on action.
			$new_rotation = $current_rotation;
			$new_priority = $current_priority;

			switch ( $action ) {
				case 'promote':
					$new_rotation = 'promoted';
					$new_priority = max( 1, $current_priority - 10 );
					break;
				case 'demote':
					$new_rotation = 'demoted';
					$new_priority = $current_priority + 10;
					break;
				case 'remove':
					$new_rotation = 'removed';
					$new_priority = 0;
					break;
			}

			$track_details[] = array(
				'id'               => $track_id,
				'title'            => $track_post->post_title,
				'current_rotation' => $current_rotation,
				'new_rotation'     => $new_rotation,
				'current_priority' => $current_priority,
				'new_priority'     => $new_priority,
				'play_count'       => $play_count,
				'changed'          => ( $current_rotation !== $new_rotation || $current_priority !== $new_priority ),
			);
		}

		// Dry run: return preview of changes.
		if ( $dry_run ) {
			$changes_count = count(
				array_filter(
					$track_details,
					function ( $t ) {
						return $t['changed'];
					}
				)
			);

			return array(
				'success'        => true,
				'dry_run'        => true,
				'message'        => sprintf(
					/* translators: %1$d: number of tracks that would change, %2$d: total tracks processed */
					__( 'Dry run complete. %1$d of %2$d tracks would be updated.', 'mcp-ai-wpoos-pro' ),
					$changes_count,
					count( $track_details )
				),
				'playlist_id'    => $playlist_id,
				'playlist_title' => $playlist->post_title,
				'action'         => $action,
				'tracks'         => $track_details,
				'not_found'      => $not_found,
			);
		}

		// Live mode: apply changes.
		$updated = array();
		$skipped = array();

		foreach ( $track_details as $track ) {
			if ( ! $track['changed'] ) {
				$skipped[] = $track;
				continue;
			}

			update_post_meta( $track['id'], '_rotation_status', $track['new_rotation'] );
			update_post_meta( $track['id'], '_rotation_priority', $track['new_priority'] );
			update_post_meta( $track['id'], '_rotation_updated', current_time( 'mysql' ) );

			if ( ! empty( $reason ) ) {
				update_post_meta( $track['id'], '_rotation_reason', $reason );
			}

			// Log the rotation change in track history.
			$history = get_post_meta( $track['id'], '_rotation_history', true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}

			$history[] = array(
				'action'     => $action,
				'from'       => $track['current_rotation'],
				'to'         => $track['new_rotation'],
				'reason'     => $reason,
				'timestamp'  => current_time( 'mysql' ),
				'updated_by' => $current_user_id,
			);

			// Keep only the last 50 history entries.
			if ( count( $history ) > 50 ) {
				$history = array_slice( $history, -50 );
			}

			update_post_meta( $track['id'], '_rotation_history', $history );

			$updated[] = $track;
		}

		return array(
			'success'        => true,
			'dry_run'        => false,
			'message'        => sprintf(
				/* translators: %1$d: number of updated tracks, %2$d: total tracks processed */
				__( 'Rotation updated successfully. %1$d of %2$d tracks were modified.', 'mcp-ai-wpoos-pro' ),
				count( $updated ),
				count( $track_details )
			),
			'playlist_id'    => $playlist_id,
			'playlist_title' => $playlist->post_title,
			'action'         => $action,
			'reason'         => $reason,
			'updated'        => $updated,
			'skipped'        => $skipped,
			'not_found'      => $not_found,
		);
	}
}
