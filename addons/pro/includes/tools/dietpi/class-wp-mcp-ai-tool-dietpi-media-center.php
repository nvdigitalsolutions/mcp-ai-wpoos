<?php
/**
 * DietPi Media Center Tool — Plex/Jellyfin control (list libraries, recently added, active streams, trigger scan).
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Media_Center' ) ) {

	/**
	 * Media Center tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Media_Center extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_media_center';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi Media Center', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Control Plex or Jellyfin media center: list libraries, browse recently added items, view active streams, and trigger library scans. Specify the "app" parameter to choose which media center to target.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'app'        => array(
						'type'        => 'string',
						'description' => __( 'Media center app to target.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'plex', 'jellyfin' ),
						'default'     => 'plex',
					),
					'action'     => array(
						'type'        => 'string',
						'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'list_libraries', 'recently_added', 'active_streams', 'refresh_library' ),
					),
					'library_id' => array(
						'type'        => 'integer',
						'description' => __( 'Library section ID (required for refresh_library, optional for recently_added).', 'mcp-ai-wpoos-pro' ),
					),
					'limit'      => array(
						'type'        => 'integer',
						'description' => __( 'Maximum items to return. Default: 20.', 'mcp-ai-wpoos-pro' ),
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					),
				),
				'required'   => array( 'action' ),
			);
		}

		/** {@inheritdoc} */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$app = $this->sanitize_string( $arguments, 'app', 'plex' );
			if ( ! $this->app_client()->is_app_configured( $app ) ) {
				/* translators: %s: app name (e.g. Plex, Jellyfin). */
				return new WP_Error( 'wp_mcp_ai_app_not_configured', sprintf( __( '%s is not configured.', 'mcp-ai-wpoos-pro' ), ucfirst( $app ) ) );
			}
			$action = $this->sanitize_string( $arguments, 'action' );
			$limit  = $this->sanitize_int( $arguments, 'limit', 20 );
			$client = $this->app_client();
			switch ( $action ) {
				case 'list_libraries':
					if ( 'plex' === $app ) {
						$result = $client->get( 'plex', '/library/sections', array(), 10 );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
						$libs = isset( $result['MediaContainer']['Directory'] ) ? $result['MediaContainer']['Directory'] : array();
						return $this->success(
							/* translators: %d: number of libraries found. */
							sprintf( __( 'Found %d libraries.', 'mcp-ai-wpoos-pro' ), count( $libs ) ),
							array(
								'libraries' => $libs,
								'app'       => 'plex',
							)
						);
					} else {
						$result = $client->get( 'jellyfin', '/Library/VirtualFolders', array(), 10 );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
						return $this->success(
							/* translators: %d: number of libraries found. */
							sprintf( __( 'Found %d libraries.', 'mcp-ai-wpoos-pro' ), count( $result ) ),
							array(
								'libraries' => $result,
								'app'       => 'jellyfin',
							)
						);
					}
				case 'recently_added':
					$lib_id = $this->sanitize_int( $arguments, 'library_id', 0 );
					if ( 'plex' === $app ) {
						$path   = $lib_id > 0 ? '/library/sections/' . $lib_id . '/recentlyAdded' : '/library/recentlyAdded';
						$result = $client->get( 'plex', $path, array(), 10 );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
						$items = isset( $result['MediaContainer']['Metadata'] ) ? $result['MediaContainer']['Metadata'] : array();
						$items = array_slice( $items, 0, $limit );
						return $this->success(
							/* translators: %d: number of recently added items. */
							sprintf( __( '%d recently added items.', 'mcp-ai-wpoos-pro' ), count( $items ) ),
							array(
								'items' => $items,
								'app'   => 'plex',
							)
						);
					} else {
						$params = array(
							'SortBy'    => 'DateCreated',
							'SortOrder' => 'Descending',
							'Limit'     => $limit,
						);
						if ( $lib_id > 0 ) {
							$params['ParentId'] = $lib_id;
						}
						$result = $client->get( 'jellyfin', '/Items', $params, 10 );
						return is_wp_error( $result ) ? $result : $this->success(
							/* translators: %d: number of recently added items. */
							sprintf( __( '%d recently added items.', 'mcp-ai-wpoos-pro' ), count( isset( $result['Items'] ) ? $result['Items'] : array() ) ),
							array(
								'items' => isset( $result['Items'] ) ? $result['Items'] : array(),
								'app'   => 'jellyfin',
							)
						);
					}
				case 'active_streams':
					if ( 'plex' === $app ) {
						$result = $client->get( 'plex', '/status/sessions', array(), 10 );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
						$streams = isset( $result['MediaContainer']['Metadata'] ) ? $result['MediaContainer']['Metadata'] : array();
						return $this->success(
							/* translators: %d: number of active streams. */
							sprintf( _n( '%d active stream.', '%d active streams.', count( $streams ), 'mcp-ai-wpoos-pro' ), count( $streams ) ),
							array(
								'streams' => $streams,
								'app'     => 'plex',
							)
						);
					} else {
						$result = $client->get( 'jellyfin', '/Sessions', array(), 10 );
						return is_wp_error( $result ) ? $result : $this->success(
							/* translators: %d: number of active sessions. */
							sprintf( _n( '%d active session.', '%d active sessions.', count( $result ) ), count( $result ) ),
							array(
								'sessions' => $result,
								'app'      => 'jellyfin',
							)
						);
					}
				case 'refresh_library':
					$lib_id = $this->sanitize_int( $arguments, 'library_id' );
					if ( $lib_id <= 0 ) {
						return new WP_Error( 'wp_mcp_ai_missing_library', __( 'library_id is required for refresh.', 'mcp-ai-wpoos-pro' ) );
					}
					if ( 'plex' === $app ) {
						$result = $client->post( 'plex', '/library/sections/' . $lib_id . '/refresh', array(), 10 );
						return is_wp_error( $result ) ? $result : $this->success( __( 'Library refresh triggered.', 'mcp-ai-wpoos-pro' ) );
					} else {
						$result = $client->post( 'jellyfin', '/Library/Refresh', array( 'LibraryId' => $lib_id ), 10 );
						return is_wp_error( $result ) ? $result : $this->success( __( 'Library refresh triggered.', 'mcp-ai-wpoos-pro' ) );
					}
				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
