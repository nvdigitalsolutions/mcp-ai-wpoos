<?php
/**
 * NV oOS Graphify — Microsoft 365 (Graph) Remote Driver (Pro)
 *
 * Pulls SharePoint sites, document drives, and files from Microsoft Graph as
 * graph nodes plus their containment relationships as edges:
 *
 *   drive  IN_SITE     site
 *   item   IN_DRIVE    drive
 *
 * Authentication: OAuth2 access token via the shared
 * NV_oOS_Graphify_OAuth_Broker (e.g. application or delegated tokens with
 * Sites.Read.All / Files.Read.All). Incremental sync uses Graph's
 * `lastModifiedDateTime` filter combined with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microsoft 365 / SharePoint remote-source driver.
 *
 * @since 0.7.3
 */
class NV_oOS_Graphify_Remote_M365 extends NV_oOS_Graphify_Remote_Source_Base {

	const API_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'm365';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Microsoft 365 / SharePoint', 'nvoos-graphify' );
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
				'description' => __( 'OAuth2 access token (Graph API).', 'nvoos-graphify' ),
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
			'tenant_id'     => array(
				'type'        => 'text',
				'label'       => __( 'Tenant ID', 'nvoos-graphify' ),
				'description' => __( 'Azure AD tenant ID (used to derive token URL when not set).', 'nvoos-graphify' ),
			),
			'token_url'     => array(
				'type'        => 'url',
				'label'       => __( 'OAuth Token URL', 'nvoos-graphify' ),
				'description' => __( 'Defaults to https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token.', 'nvoos-graphify' ),
			),
			'site_search'   => array(
				'type'        => 'text',
				'label'       => __( 'Site Search Filter', 'nvoos-graphify' ),
				'description' => __( 'Optional Graph $search keyword for /sites (default "*" returns all visible sites).', 'nvoos-graphify' ),
				'default'     => '*',
			),
			'max_items'     => array(
				'type'    => 'number',
				'label'   => __( 'Max Items Per Type', 'nvoos-graphify' ),
				'default' => 100,
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
			self::API_BASE . '/me',
			array( 'headers' => $this->auth_headers( $token ) )
		);
		// /me only works for delegated tokens. Fall back to /sites?search=* for app-only tokens.
		if ( is_wp_error( $result ) || $result['status'] >= 400 ) {
			$result = $this->get_http()->get(
				self::API_BASE . '/sites?search=*&$top=1',
				array( 'headers' => $this->auth_headers( $token ) )
			);
		}
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
				'message' => sprintf( __( 'Microsoft Graph returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to Microsoft Graph.', 'nvoos-graphify' ),
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

		$sites = $this->fetch_sites( $token, $max_items );
		foreach ( $sites as $site ) {
			$nodes[] = $this->site_to_node( $site, $slug );
			$site_id = isset( $site['id'] ) ? (string) $site['id'] : '';
			if ( '' === $site_id ) {
				continue;
			}
			foreach ( $this->fetch_drives_for_site( $token, $site_id, $max_items ) as $drive ) {
				$nodes[]  = $this->drive_to_node( $drive, $site_id, $slug );
				$drive_id = isset( $drive['id'] ) ? (string) $drive['id'] : '';
				if ( '' === $drive_id ) {
					continue;
				}
				foreach ( $this->fetch_drive_items( $token, $drive_id, $max_items ) as $item ) {
					$nodes[] = $this->item_to_node( $item, $drive_id, $slug );
				}
			}
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

		foreach ( $this->fetch_sites( $token, $max_items ) as $site ) {
			$site_id = isset( $site['id'] ) ? (string) $site['id'] : '';
			if ( '' === $site_id ) {
				continue;
			}
			foreach ( $this->fetch_drives_for_site( $token, $site_id, $max_items ) as $drive ) {
				$drive_id = isset( $drive['id'] ) ? (string) $drive['id'] : '';
				if ( '' === $drive_id ) {
					continue;
				}
				$edges[] = array(
					'source_node_id' => $this->drive_node_id( $drive_id, $slug ),
					'target_node_id' => $this->site_node_id( $site_id, $slug ),
					'relation'       => 'IN_SITE',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
				foreach ( $this->fetch_drive_items( $token, $drive_id, $max_items ) as $item ) {
					$item_id = isset( $item['id'] ) ? (string) $item['id'] : '';
					if ( '' === $item_id ) {
						continue;
					}
					$edges[] = array(
						'source_node_id' => $this->item_node_id( $item_id, $slug ),
						'target_node_id' => $this->drive_node_id( $drive_id, $slug ),
						'relation'       => 'IN_DRIVE',
						'confidence'     => 1.0,
						'provenance'     => 'REMOTE',
						'source_slug'    => $slug,
					);
				}
			}
		}

		return $edges;
	}

	// -------------------------------------------------------------------------
	// Node mappers (public for testability)
	// -------------------------------------------------------------------------

	/**
	 * Convert a Graph site payload to a graph node.
	 *
	 * @param array  $site Site payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function site_to_node( array $site, $slug ) {
		$id   = isset( $site['id'] ) ? (string) $site['id'] : '';
		$name = isset( $site['displayName'] ) ? (string) $site['displayName'] : ( isset( $site['name'] ) ? (string) $site['name'] : ( '' !== $id ? 'site:' . $id : 'site' ) );
		return array(
			'node_id'     => $this->site_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'site',
			'post_id'     => 0,
			'url'         => isset( $site['webUrl'] ) ? esc_url_raw( (string) $site['webUrl'] ) : '',
			'properties'  => array(
				'm365_site_id' => sanitize_text_field( $id ),
				'web_url'      => isset( $site['webUrl'] ) ? esc_url_raw( (string) $site['webUrl'] ) : '',
			),
			'external_id' => 'm365:site:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Graph drive payload to a graph node.
	 *
	 * @param array  $drive   Drive payload.
	 * @param string $site_id Parent site ID.
	 * @param string $slug    Source slug.
	 * @return array
	 */
	public function drive_to_node( array $drive, $site_id, $slug ) {
		$id   = isset( $drive['id'] ) ? (string) $drive['id'] : '';
		$name = isset( $drive['name'] ) ? (string) $drive['name'] : ( '' !== $id ? 'drive:' . $id : 'drive' );
		return array(
			'node_id'     => $this->drive_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'drive',
			'post_id'     => 0,
			'url'         => isset( $drive['webUrl'] ) ? esc_url_raw( (string) $drive['webUrl'] ) : '',
			'properties'  => array(
				'm365_drive_id' => sanitize_text_field( $id ),
				'm365_site_id'  => sanitize_text_field( (string) $site_id ),
				'drive_type'    => isset( $drive['driveType'] ) ? sanitize_text_field( (string) $drive['driveType'] ) : '',
			),
			'external_id' => 'm365:drive:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Graph drive-item payload to a graph node.
	 *
	 * @param array  $item     Item payload.
	 * @param string $drive_id Parent drive ID.
	 * @param string $slug     Source slug.
	 * @return array
	 */
	public function item_to_node( array $item, $drive_id, $slug ) {
		$id        = isset( $item['id'] ) ? (string) $item['id'] : '';
		$name      = isset( $item['name'] ) ? (string) $item['name'] : ( '' !== $id ? 'item:' . $id : 'item' );
		$is_folder = ! empty( $item['folder'] );
		$type      = $is_folder ? 'folder' : 'document';
		return array(
			'node_id'     => $this->item_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => $type,
			'post_id'     => 0,
			'url'         => isset( $item['webUrl'] ) ? esc_url_raw( (string) $item['webUrl'] ) : '',
			'properties'  => array(
				'm365_item_id'  => sanitize_text_field( $id ),
				'm365_drive_id' => sanitize_text_field( (string) $drive_id ),
				'is_folder'     => $is_folder,
				'modified_time' => isset( $item['lastModifiedDateTime'] ) ? sanitize_text_field( (string) $item['lastModifiedDateTime'] ) : '',
				'mime_type'     => isset( $item['file']['mimeType'] ) ? sanitize_text_field( (string) $item['file']['mimeType'] ) : '',
			),
			'external_id' => 'm365:item:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build the node_id for a site.
	 *
	 * @param string $id   Site ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function site_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_site_' . $this->hash_id( $id );
	}

	/**
	 * Build the node_id for a drive.
	 *
	 * @param string $id   Drive ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function drive_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_drive_' . $this->hash_id( $id );
	}

	/**
	 * Build the node_id for a drive-item.
	 *
	 * @param string $id   Item ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function item_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_item_' . $this->hash_id( $id );
	}

	/**
	 * Graph IDs contain commas, hyphens, and colons. Hash them to keep
	 * the node_id short and key-safe.
	 *
	 * @param string $id Raw Graph ID.
	 * @return string
	 */
	private function hash_id( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return 'na';
		}
		return substr( md5( $id ), 0, 16 );
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch sites visible to the configured token.
	 *
	 * @param string $token Access token.
	 * @param int    $limit Max items.
	 * @return array<array>
	 */
	private function fetch_sites( $token, $limit ) {
		$config = $this->get_config();
		$search = isset( $config['site_search'] ) ? trim( (string) $config['site_search'] ) : '*';
		$query  = http_build_query(
			array(
				'search' => '' !== $search ? $search : '*',
				'$top'   => max( 1, min( 200, (int) $limit ) ),
			)
		);
		$result = $this->get_http()->get( self::API_BASE . '/sites?' . $query, array( 'headers' => $this->auth_headers( $token ) ) );
		return $this->extract_value( $result );
	}

	/**
	 * Fetch document drives for a site.
	 *
	 * @param string $token   Access token.
	 * @param string $site_id Graph site ID.
	 * @param int    $limit   Max items.
	 * @return array<array>
	 */
	private function fetch_drives_for_site( $token, $site_id, $limit ) {
		$query  = http_build_query( array( '$top' => max( 1, min( 200, (int) $limit ) ) ) );
		$result = $this->get_http()->get(
			self::API_BASE . '/sites/' . rawurlencode( $site_id ) . '/drives?' . $query,
			array( 'headers' => $this->auth_headers( $token ) )
		);
		return $this->extract_value( $result );
	}

	/**
	 * Fetch root-level drive items, filtered by `lastModifiedDateTime` cursor.
	 *
	 * @param string $token    Access token.
	 * @param string $drive_id Graph drive ID.
	 * @param int    $limit    Max items.
	 * @return array<array>
	 */
	private function fetch_drive_items( $token, $drive_id, $limit ) {
		$slug   = $this->get_slug();
		$since  = (string) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_modified_iso', '' );
		$params = array( '$top' => max( 1, min( 200, (int) $limit ) ) );
		if ( '' !== $since ) {
			$params['$filter'] = 'lastModifiedDateTime gt ' . $since;
		}
		$result = $this->get_http()->get(
			self::API_BASE . '/drives/' . rawurlencode( $drive_id ) . '/root/children?' . http_build_query( $params ),
			array( 'headers' => $this->auth_headers( $token ) )
		);
		return $this->extract_value( $result );
	}

	/**
	 * Pull `value` array from a Graph collection response.
	 *
	 * @param array|WP_Error $result HTTP response.
	 * @return array<array>
	 */
	private function extract_value( $result ) {
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['value'] ) || ! is_array( $body['value'] ) ) {
			return array();
		}
		return $body['value'];
	}

	/**
	 * Resolve a usable access token.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_token() {
		$config = $this->get_config();
		$access = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		// Default token_url from tenant_id.
		if ( empty( $config['token_url'] ) && ! empty( $config['tenant_id'] ) ) {
			$config['token_url'] = 'https://login.microsoftonline.com/' . rawurlencode( (string) $config['tenant_id'] ) . '/oauth2/v2.0/token';
		}
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( ! is_wp_error( $resolved ) ) {
				$access = (string) $resolved;
			} elseif ( '' === $access ) {
				return $resolved;
			}
		}
		if ( '' === $access ) {
			return new WP_Error( 'm365_no_token', __( 'No Microsoft Graph access_token configured.', 'nvoos-graphify' ) );
		}
		return $access;
	}

	/**
	 * Standard auth headers.
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
		return isset( $config['max_items'] ) ? max( 1, (int) $config['max_items'] ) : 100;
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
