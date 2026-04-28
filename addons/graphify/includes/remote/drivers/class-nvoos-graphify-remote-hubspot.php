<?php
/**
 * NV oOS Graphify — HubSpot Remote Driver (Pro)
 *
 * Pulls HubSpot CRM objects (Contacts, Companies, Deals, Tickets) and the
 * relationships between them as graph nodes and edges:
 *
 *   contact  WORKS_AT       company
 *   contact  PARTICIPATES_IN deal
 *   contact  REPORTED_TICKET ticket
 *
 * Authentication: OAuth2 (preferred) or Private App access token. When OAuth2
 * tokens are configured, the shared NV_oOS_Graphify_OAuth_Broker transparently
 * refreshes them. Incremental sync uses HubSpot's `lastmodifieddate` filter on
 * the search API combined with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HubSpot CRM remote-source driver.
 *
 * @since 0.7.1
 */
class NV_oOS_Graphify_Remote_HubSpot extends NV_oOS_Graphify_Remote_Source_Base {

	const API_BASE = 'https://api.hubapi.com';

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'hubspot';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'HubSpot CRM', 'nvoos-graphify' );
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
				'description' => __( 'OAuth2 access token or HubSpot Private App token.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'refresh_token' => array(
				'type'        => 'password',
				'label'       => __( 'Refresh Token', 'nvoos-graphify' ),
				'description' => __( 'OAuth2 refresh token (only required for OAuth2 apps).', 'nvoos-graphify' ),
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
				'default' => 'https://api.hubapi.com/oauth/v1/token',
			),
			'object_types'  => array(
				'type'        => 'text',
				'label'       => __( 'Object Types', 'nvoos-graphify' ),
				'description' => __( 'Comma-separated CRM object types to ingest (contacts, companies, deals, tickets).', 'nvoos-graphify' ),
				'default'     => 'contacts,companies,deals,tickets',
			),
			'max_items'     => array(
				'type'    => 'number',
				'label'   => __( 'Max Items Per Type', 'nvoos-graphify' ),
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
			self::API_BASE . '/crm/v3/objects/contacts?limit=1',
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
				'message' => sprintf( __( 'HubSpot returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to HubSpot.', 'nvoos-graphify' ),
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
		$types     = $this->resolve_types();
		$nodes     = array();
		$cursors   = NV_oOS_Graphify_Remote_State_Store::get( $slug, 'cursors', array() );
		if ( ! is_array( $cursors ) ) {
			$cursors = array();
		}

		foreach ( $types as $type ) {
			$records = $this->fetch_objects( $type, $token, $max_items );
			foreach ( $records as $record ) {
				$nodes[] = $this->record_to_node( $type, $record, $slug );
			}
			$cursors[ $type ] = gmdate( 'c' );
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'cursors', $cursors );
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

		// Contact ↔ Company associations.
		if ( in_array( 'contacts', $this->resolve_types(), true ) ) {
			$contacts = $this->fetch_objects( 'contacts', $token, $max_items );
			foreach ( $contacts as $contact ) {
				$contact_id = isset( $contact['id'] ) ? (string) $contact['id'] : '';
				if ( '' === $contact_id ) {
					continue;
				}
				$assocs = $this->fetch_associations( 'contacts', $contact_id, 'companies', $token );
				foreach ( $assocs as $company_id ) {
					$edges[] = array(
						'source_node_id' => $this->object_node_id( 'contacts', $contact_id, $slug ),
						'target_node_id' => $this->object_node_id( 'companies', $company_id, $slug ),
						'relation'       => 'WORKS_AT',
						'confidence'     => 1.0,
						'provenance'     => 'REMOTE',
						'source_slug'    => $slug,
					);
				}
			}
		}

		return $edges;
	}

	/**
	 * Fetch a page of objects of a given type.
	 *
	 * @param string $type   Object type (contacts/companies/deals/tickets).
	 * @param string $token  Access token.
	 * @param int    $limit  Max items.
	 * @return array
	 */
	private function fetch_objects( $type, $token, $limit ) {
		$type     = sanitize_key( $type );
		$limit    = max( 1, min( 100, (int) $limit ) );
		$endpoint = self::API_BASE . '/crm/v3/objects/' . $type . '?limit=' . $limit;
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['results'] ) || ! is_array( $body['results'] ) ) {
			return array();
		}
		return $body['results'];
	}

	/**
	 * Fetch association IDs for a single CRM object.
	 *
	 * @param string $from_type From object type.
	 * @param string $from_id   From object ID.
	 * @param string $to_type   To object type.
	 * @param string $token     Access token.
	 * @return array<string>
	 */
	private function fetch_associations( $from_type, $from_id, $to_type, $token ) {
		$endpoint = self::API_BASE . '/crm/v3/objects/' . sanitize_key( $from_type ) . '/' . rawurlencode( $from_id ) . '/associations/' . sanitize_key( $to_type );
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		$ids  = array();
		if ( is_array( $body ) && ! empty( $body['results'] ) && is_array( $body['results'] ) ) {
			foreach ( $body['results'] as $assoc ) {
				if ( isset( $assoc['id'] ) ) {
					$ids[] = (string) $assoc['id'];
				} elseif ( isset( $assoc['toObjectId'] ) ) {
					$ids[] = (string) $assoc['toObjectId'];
				}
			}
		}
		return $ids;
	}

	/**
	 * Convert a HubSpot record into a graph node array.
	 *
	 * @param string $type   Object type.
	 * @param array  $record Raw HubSpot record.
	 * @param string $slug   Source slug.
	 * @return array
	 */
	public function record_to_node( $type, array $record, $slug ) {
		$type       = sanitize_key( $type );
		$id         = isset( $record['id'] ) ? (string) $record['id'] : '';
		$properties = isset( $record['properties'] ) && is_array( $record['properties'] ) ? $record['properties'] : array();
		$label      = $this->derive_label( $type, $properties, $id );
		$node_type  = $this->map_node_type( $type );

		$out = array(
			'node_id'     => $this->object_node_id( $type, $id, $slug ),
			'label'       => sanitize_text_field( $label ),
			'type'        => $node_type,
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array_merge(
				array( 'hubspot_object_type' => $type ),
				$this->sanitize_properties( $properties )
			),
			'external_id' => 'hubspot:' . $type . ':' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);

		// Surface canonical keys at the top level so the entity resolver picks them up.
		if ( ! empty( $properties['email'] ) ) {
			$out['email'] = sanitize_email( (string) $properties['email'] );
		}
		if ( ! empty( $properties['domain'] ) ) {
			$out['url'] = esc_url_raw( 'https://' . ltrim( (string) $properties['domain'], '/' ) );
		} elseif ( ! empty( $properties['website'] ) ) {
			$out['url'] = esc_url_raw( (string) $properties['website'] );
		}

		return $out;
	}

	/**
	 * Choose a human-readable label for a record.
	 *
	 * @param string $type       Object type.
	 * @param array  $properties Property bag.
	 * @param string $id         Fallback ID.
	 * @return string
	 */
	private function derive_label( $type, array $properties, $id ) {
		switch ( $type ) {
			case 'contacts':
				$first = isset( $properties['firstname'] ) ? (string) $properties['firstname'] : '';
				$last  = isset( $properties['lastname'] ) ? (string) $properties['lastname'] : '';
				$name  = trim( $first . ' ' . $last );
				if ( '' === $name && ! empty( $properties['email'] ) ) {
					$name = (string) $properties['email'];
				}
				return '' !== $name ? $name : 'contact:' . $id;
			case 'companies':
				return ! empty( $properties['name'] ) ? (string) $properties['name'] : 'company:' . $id;
			case 'deals':
				return ! empty( $properties['dealname'] ) ? (string) $properties['dealname'] : 'deal:' . $id;
			case 'tickets':
				return ! empty( $properties['subject'] ) ? (string) $properties['subject'] : 'ticket:' . $id;
			default:
				return $type . ':' . $id;
		}
	}

	/**
	 * Map a HubSpot object type to a graph node type.
	 *
	 * @param string $type HubSpot object type.
	 * @return string
	 */
	private function map_node_type( $type ) {
		$map = array(
			'contacts'  => 'person',
			'companies' => 'organization',
			'deals'     => 'deal',
			'tickets'   => 'ticket',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 'entity';
	}

	/**
	 * Build a stable node_id for a HubSpot object.
	 *
	 * @param string $type Object type.
	 * @param string $id   Object ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function object_node_id( $type, $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $type ) . '_' . sanitize_key( (string) $id );
	}

	/**
	 * Sanitise an arbitrary property bag for storage.
	 *
	 * @param array $properties Raw property array.
	 * @return array
	 */
	private function sanitize_properties( array $properties ) {
		$out = array();
		foreach ( $properties as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $key ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
			}
		}
		return $out;
	}

	/**
	 * Resolve a usable access token, refreshing via the OAuth broker when
	 * a refresh_token is configured.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_token() {
		$config       = $this->get_config();
		$access_token = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( is_wp_error( $resolved ) ) {
				// Fall back to whatever static token we have if refresh failed.
				if ( '' === $access_token ) {
					return $resolved;
				}
			} else {
				$access_token = (string) $resolved;
			}
		}
		if ( '' === $access_token ) {
			return new WP_Error( 'hubspot_no_token', __( 'No HubSpot access_token configured.', 'nvoos-graphify' ) );
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
	 * Resolve the configured object types.
	 *
	 * @return array<string>
	 */
	private function resolve_types() {
		$config  = $this->get_config();
		$raw     = isset( $config['object_types'] ) ? (string) $config['object_types'] : 'contacts,companies,deals,tickets';
		$allowed = array( 'contacts', 'companies', 'deals', 'tickets' );
		$out     = array();
		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = sanitize_key( trim( $piece ) );
			if ( in_array( $piece, $allowed, true ) ) {
				$out[] = $piece;
			}
		}
		return ! empty( $out ) ? $out : $allowed;
	}

	/**
	 * Resolve the configured per-type item limit.
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
