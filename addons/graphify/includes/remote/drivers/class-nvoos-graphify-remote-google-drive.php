<?php
/**
 * NV oOS Graphify — Google Drive Remote Driver (Pro)
 *
 * Pulls Google Drive file and folder metadata as graph nodes plus the
 * folder-membership relationships between them as edges:
 *
 *   file/folder  IN_FOLDER  folder
 *
 * Authentication: OAuth2 access token via the shared
 * NV_oOS_Graphify_OAuth_Broker (Drive scopes: drive.metadata.readonly or
 * drive.readonly). Incremental sync uses Drive's `modifiedTime` filter
 * combined with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Drive remote-source driver.
 *
 * @since 0.7.2
 */
class NV_oOS_Graphify_Remote_Google_Drive extends NV_oOS_Graphify_Remote_Source_Base {

	const API_BASE = 'https://www.googleapis.com/drive/v3';

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'google_drive';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Google Drive', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => true,
			'supports_oauth'         => true,
			'supports_pagination'    => true,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'access_token'  => array(
				'type'        => 'password',
				'label'       => __( 'Access Token', 'nvoos-graphify' ),
				'description' => __( 'OAuth2 access token (Drive scope).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'refresh_token' => array(
				'type'  => 'password',
				'label' => __( 'Refresh Token', 'nvoos-graphify' ),
			),
			'client_id'     => array(
				'type'  => 'text',
				'label' => __( 'OAuth Client ID', 'nvoos-graphify' ),
			),
			'client_secret' => array(
				'type'  => 'password',
				'label' => __( 'OAuth Client Secret', 'nvoos-graphify' ),
			),
			'token_url'     => array(
				'type'    => 'url',
				'label'   => __( 'OAuth Token URL', 'nvoos-graphify' ),
				'default' => 'https://oauth2.googleapis.com/token',
			),
			'query'         => array(
				'type'        => 'text',
				'label'       => __( 'Drive Query (q)', 'nvoos-graphify' ),
				'description' => __( 'Optional Drive search query (e.g. "trashed=false").', 'nvoos-graphify' ),
				'default'     => 'trashed=false',
			),
			'max_items'     => array(
				'type'    => 'number',
				'label'   => __( 'Max Items Per Sync', 'nvoos-graphify' ),
				'default' => 200,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$token = $this->resolve_token();
		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'message' => $token->get_error_message(),
			);
		}
		$result = $this->get_http()->get(
			self::API_BASE . '/about?fields=user',
			array( 'headers' => $this->auth_headers( $token ) )
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}
		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return array(
				'success' => false,
				/* translators: %d HTTP status. */
				'message' => sprintf( __( 'Google Drive returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to Google Drive.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$token = $this->resolve_token();
		if ( is_wp_error( $token ) ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$nodes     = array();

		$files = $this->fetch_files( $token, $max_items );
		foreach ( $files as $file ) {
			$nodes[] = $this->file_to_node( $file, $slug );
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_modified_iso', gmdate( 'c' ) );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $slug );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$token = $this->resolve_token();
		if ( is_wp_error( $token ) ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$edges     = array();

		$files = $this->fetch_files( $token, $max_items );
		foreach ( $files as $file ) {
			if ( empty( $file['id'] ) || empty( $file['parents'] ) || ! is_array( $file['parents'] ) ) {
				continue;
			}
			$child_id = $this->file_node_id( (string) $file['id'], $slug );
			foreach ( $file['parents'] as $parent_id ) {
				$edges[] = array(
					'source_node_id' => $child_id,
					'target_node_id' => $this->file_node_id( (string) $parent_id, $slug ),
					'relation'       => 'IN_FOLDER',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}
		}

		return $edges;
	}

	/**
	 * Convert a Drive file payload to a graph node.
	 *
	 * @param array  $file Raw Drive file metadata.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function file_to_node( array $file, $slug ) {
		$id        = isset( $file['id'] ) ? (string) $file['id'] : '';
		$mime      = isset( $file['mimeType'] ) ? (string) $file['mimeType'] : '';
		$is_folder = ( 'application/vnd.google-apps.folder' === $mime );
		$node_type = $is_folder ? 'folder' : 'document';
		$name      = isset( $file['name'] ) ? (string) $file['name'] : ( '' !== $id ? $node_type . ':' . $id : $node_type );

		return array(
			'node_id'     => $this->file_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => $node_type,
			'post_id'     => 0,
			'url'         => isset( $file['webViewLink'] ) ? esc_url_raw( (string) $file['webViewLink'] ) : '',
			'properties'  => array(
				'drive_id'      => sanitize_text_field( $id ),
				'mime_type'     => sanitize_text_field( $mime ),
				'modified_time' => isset( $file['modifiedTime'] ) ? sanitize_text_field( (string) $file['modifiedTime'] ) : '',
				'owners'        => $this->extract_owner_emails( $file ),
				'is_folder'     => $is_folder,
			),
			'external_id' => 'gdrive:' . ( $is_folder ? 'folder:' : 'file:' ) . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build the node_id for a Drive file or folder.
	 *
	 * @param string $id   Drive file/folder ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function file_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_drive_' . sanitize_key( $id );
	}

	/**
	 * Fetch a page of Drive files filtered by the configured query.
	 *
	 * @param string $token Access token.
	 * @param int    $limit Max items.
	 * @return array<array>
	 */
	private function fetch_files( $token, $limit ) {
		$config = $this->get_config();
		$q      = isset( $config['query'] ) ? trim( (string) $config['query'] ) : 'trashed=false';
		$slug   = $this->get_slug();
		$since  = (string) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_modified_iso', '' );
		if ( '' !== $since ) {
			$q = ( '' !== $q ? $q . ' and ' : '' ) . "modifiedTime > '" . $since . "'";
		}
		$query  = http_build_query(
			array(
				'q'        => $q,
				'pageSize' => max( 1, min( 1000, (int) $limit ) ),
				'fields'   => 'files(id,name,mimeType,modifiedTime,parents,webViewLink,owners(emailAddress))',
			)
		);
		$result = $this->get_http()->get( self::API_BASE . '/files?' . $query, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['files'] ) || ! is_array( $body['files'] ) ) {
			return array();
		}
		return $body['files'];
	}

	/**
	 * Extract owner email addresses from a Drive file payload.
	 *
	 * @param array $file Drive file payload.
	 * @return array<string>
	 */
	private function extract_owner_emails( array $file ) {
		$emails = array();
		if ( ! empty( $file['owners'] ) && is_array( $file['owners'] ) ) {
			foreach ( $file['owners'] as $owner ) {
				if ( ! empty( $owner['emailAddress'] ) ) {
					$emails[] = sanitize_email( (string) $owner['emailAddress'] );
				}
			}
		}
		return $emails;
	}

	/**
	 * Resolve a usable access token, refreshing via the OAuth broker when a
	 * refresh_token is configured.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_token() {
		$config       = $this->get_config();
		$access_token = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( is_wp_error( $resolved ) ) {
				if ( '' === $access_token ) {
					return $resolved;
				}
			} else {
				$access_token = (string) $resolved;
			}
		}
		if ( '' === $access_token ) {
			return new WP_Error( 'gdrive_no_token', __( 'No Google Drive access_token configured.', 'nvoos-graphify' ) );
		}
		return $access_token;
	}

	/**
	 * Build standard auth headers.
	 *
	 * @param string $token Access token.
	 * @return array
	 */
	private function auth_headers( $token ) {
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Resolve max items.
	 *
	 * @param array $args Caller args.
	 * @return int
	 */
	private function resolve_max_items( array $args ) {
		if ( isset( $args['max_items'] ) ) {
			return max( 1, (int) $args['max_items'] );
		}
		$config = $this->get_config();
		return isset( $config['max_items'] ) ? max( 1, (int) $config['max_items'] ) : 200;
	}

	/**
	 * Lazy HTTP client.
	 *
	 * @return NV_oOS_Graphify_HTTP_Client
	 */
	private function get_http() {
		if ( null === $this->http ) {
			$this->http = new NV_oOS_Graphify_HTTP_Client( $this->get_slug() );
		}
		return $this->http;
	}
}
