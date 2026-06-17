<?php
/**
 * NV oOS Graphify — Zendesk Remote Driver (Pro)
 *
 * Pulls Zendesk Support tickets, users, and organizations as graph nodes plus
 * the relationships between them as edges:
 *
 *   ticket  REPORTED_BY   user
 *   ticket  ASSIGNED_TO   user
 *   user    MEMBER_OF     organization
 *
 * Authentication: Zendesk API token Basic auth (email/token + api_token) — the
 * canonical option for server-to-server integrations. OAuth2 access tokens
 * are also supported via the shared NV_oOS_Graphify_OAuth_Broker.
 *
 * Incremental sync uses Zendesk's incremental export endpoints with a
 * UNIX-timestamp cursor stored in the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zendesk remote-source driver.
 *
 * @since 0.7.2
 */
class NV_oOS_Graphify_Remote_Zendesk extends NV_oOS_Graphify_Remote_Source_Base {

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'zendesk';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Zendesk Support', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges', 'webhooks', 'push_tickets' );
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
			'subdomain'     => array(
				'type'        => 'text',
				'label'       => __( 'Zendesk Subdomain', 'nvoos-graphify' ),
				'description' => __( 'Just the subdomain (e.g. "acme" for acme.zendesk.com).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'email'         => array(
				'type'        => 'text',
				'label'       => __( 'Account Email', 'nvoos-graphify' ),
				'description' => __( 'Email used with the API token (Basic auth).', 'nvoos-graphify' ),
			),
			'api_token'     => array(
				'type'  => 'password',
				'label' => __( 'API Token', 'nvoos-graphify' ),
			),
			'access_token'  => array(
				'type'  => 'password',
				'label' => __( 'OAuth2 Access Token', 'nvoos-graphify' ),
			),
			'refresh_token' => array(
				'type'  => 'password',
				'label' => __( 'OAuth2 Refresh Token', 'nvoos-graphify' ),
			),
			'client_id'     => array(
				'type'  => 'text',
				'label' => __( 'OAuth Client ID', 'nvoos-graphify' ),
			),
			'client_secret' => array(
				'type'  => 'password',
				'label' => __( 'OAuth Client Secret', 'nvoos-graphify' ),
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
		$base = $this->resolve_base_url();
		if ( '' === $base ) {
			return array(
				'success' => false,
				'message' => __( 'No Zendesk subdomain configured.', 'nvoos-graphify' ),
			);
		}
		$auth = $this->resolve_auth_headers();
		if ( is_wp_error( $auth ) ) {
			return array(
				'success' => false,
				'message' => $auth->get_error_message(),
			);
		}
		$result = $this->get_http()->get( $base . '/api/v2/users/me.json', array( 'headers' => $auth ) );
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
				'message' => sprintf( __( 'Zendesk returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to Zendesk.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$base = $this->resolve_base_url();
		$auth = $this->resolve_auth_headers();
		if ( '' === $base || is_wp_error( $auth ) ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$nodes     = array();

		// Tickets, users, organizations.
		foreach ( $this->fetch_tickets( $base, $auth, $max_items ) as $ticket ) {
			$nodes[] = $this->ticket_to_node( $ticket, $slug );
		}
		foreach ( $this->fetch_users( $base, $auth, $max_items ) as $user ) {
			$nodes[] = $this->user_to_node( $user, $slug );
		}
		foreach ( $this->fetch_organizations( $base, $auth, $max_items ) as $org ) {
			$nodes[] = $this->org_to_node( $org, $slug );
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_sync_unix', (int) time() );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $slug );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$base = $this->resolve_base_url();
		$auth = $this->resolve_auth_headers();
		if ( '' === $base || is_wp_error( $auth ) ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$edges     = array();

		// Ticket → user (requester / assignee).
		foreach ( $this->fetch_tickets( $base, $auth, $max_items ) as $ticket ) {
			$ticket_id = isset( $ticket['id'] ) ? (string) $ticket['id'] : '';
			if ( '' === $ticket_id ) {
				continue;
			}
			$ticket_node_id = $this->ticket_node_id( $ticket_id, $slug );

			if ( ! empty( $ticket['requester_id'] ) ) {
				$edges[] = array(
					'source_node_id' => $ticket_node_id,
					'target_node_id' => $this->user_node_id( (string) $ticket['requester_id'], $slug ),
					'relation'       => 'REPORTED_BY',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}
			if ( ! empty( $ticket['assignee_id'] ) ) {
				$edges[] = array(
					'source_node_id' => $ticket_node_id,
					'target_node_id' => $this->user_node_id( (string) $ticket['assignee_id'], $slug ),
					'relation'       => 'ASSIGNED_TO',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}
		}

		// User → organization membership.
		foreach ( $this->fetch_users( $base, $auth, $max_items ) as $user ) {
			if ( empty( $user['id'] ) || empty( $user['organization_id'] ) ) {
				continue;
			}
			$edges[] = array(
				'source_node_id' => $this->user_node_id( (string) $user['id'], $slug ),
				'target_node_id' => $this->org_node_id( (string) $user['organization_id'], $slug ),
				'relation'       => 'MEMBER_OF',
				'confidence'     => 1.0,
				'provenance'     => 'REMOTE',
				'source_slug'    => $slug,
			);
		}

		return $edges;
	}

	// -------------------------------------------------------------------------
	// Node mappers (public for testability)
	// -------------------------------------------------------------------------

	/**
	 * Convert a Zendesk ticket payload to a graph node.
	 *
	 * @param array  $ticket Ticket payload.
	 * @param string $slug   Source slug.
	 * @return array
	 */
	public function ticket_to_node( array $ticket, $slug ) {
		$id      = isset( $ticket['id'] ) ? (string) $ticket['id'] : '';
		$subject = isset( $ticket['subject'] ) ? (string) $ticket['subject'] : ( '' !== $id ? 'ticket:' . $id : 'ticket' );
		return array(
			'node_id'     => $this->ticket_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $subject ),
			'type'        => 'ticket',
			'post_id'     => 0,
			'url'         => isset( $ticket['url'] ) ? esc_url_raw( (string) $ticket['url'] ) : '',
			'properties'  => array(
				'zendesk_ticket_id' => sanitize_text_field( $id ),
				'status'            => isset( $ticket['status'] ) ? sanitize_text_field( (string) $ticket['status'] ) : '',
				'priority'          => isset( $ticket['priority'] ) ? sanitize_text_field( (string) $ticket['priority'] ) : '',
				'type'              => isset( $ticket['type'] ) ? sanitize_text_field( (string) $ticket['type'] ) : '',
			),
			'external_id' => 'zendesk:ticket:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Zendesk user payload to a graph node.
	 *
	 * @param array  $user User payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function user_to_node( array $user, $slug ) {
		$id    = isset( $user['id'] ) ? (string) $user['id'] : '';
		$name  = isset( $user['name'] ) ? (string) $user['name'] : ( '' !== $id ? 'user:' . $id : 'user' );
		$email = isset( $user['email'] ) ? sanitize_email( (string) $user['email'] ) : '';

		$out = array(
			'node_id'     => $this->user_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'person',
			'post_id'     => 0,
			'url'         => isset( $user['url'] ) ? esc_url_raw( (string) $user['url'] ) : '',
			'properties'  => array(
				'zendesk_user_id' => sanitize_text_field( $id ),
				'role'            => isset( $user['role'] ) ? sanitize_text_field( (string) $user['role'] ) : '',
			),
			'external_id' => 'zendesk:user:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
		if ( '' !== $email ) {
			$out['email']               = $email;
			$out['properties']['email'] = $email;
		}
		return $out;
	}

	/**
	 * Convert a Zendesk organization payload to a graph node.
	 *
	 * @param array  $org  Organization payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function org_to_node( array $org, $slug ) {
		$id   = isset( $org['id'] ) ? (string) $org['id'] : '';
		$name = isset( $org['name'] ) ? (string) $org['name'] : ( '' !== $id ? 'org:' . $id : 'organization' );
		$out  = array(
			'node_id'     => $this->org_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'organization',
			'post_id'     => 0,
			'url'         => isset( $org['url'] ) ? esc_url_raw( (string) $org['url'] ) : '',
			'properties'  => array(
				'zendesk_org_id' => sanitize_text_field( $id ),
			),
			'external_id' => 'zendesk:org:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
		if ( ! empty( $org['domain_names'] ) && is_array( $org['domain_names'] ) ) {
			$out['properties']['domain_names'] = array_map( 'sanitize_text_field', $org['domain_names'] );
			$first                             = reset( $org['domain_names'] );
			if ( is_string( $first ) && '' !== $first ) {
				$out['url'] = esc_url_raw( 'https://' . ltrim( $first, '/' ) );
			}
		}
		return $out;
	}

	/**
	 * Build the node_id for a Zendesk ticket.
	 *
	 * @param string $id   Ticket ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function ticket_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_ticket_' . sanitize_key( $id );
	}

	/**
	 * Build the node_id for a Zendesk user.
	 *
	 * @param string $id   User ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function user_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_user_' . sanitize_key( $id );
	}

	/**
	 * Build the node_id for a Zendesk organization.
	 *
	 * @param string $id   Org ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function org_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_org_' . sanitize_key( $id );
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch tickets via incremental export.
	 *
	 * @param string $base  Base URL.
	 * @param array  $auth  Auth headers.
	 * @param int    $limit Max items (per page; Zendesk caps at 1000).
	 * @return array<array>
	 */
	private function fetch_tickets( $base, array $auth, $limit ) {
		$slug   = $this->get_slug();
		$cursor = (int) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_sync_unix', 0 );
		$query  = http_build_query(
			array(
				'start_time' => max( 0, $cursor ),
				'per_page'   => max( 1, min( 1000, (int) $limit ) ),
			)
		);
		$result = $this->get_http()->get( $base . '/api/v2/incremental/tickets.json?' . $query, array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['tickets'] ) || ! is_array( $body['tickets'] ) ) {
			return array();
		}
		return $body['tickets'];
	}

	/**
	 * Fetch users via incremental export.
	 *
	 * @param string $base  Base URL.
	 * @param array  $auth  Auth headers.
	 * @param int    $limit Max items.
	 * @return array<array>
	 */
	private function fetch_users( $base, array $auth, $limit ) {
		$slug   = $this->get_slug();
		$cursor = (int) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_sync_unix', 0 );
		$query  = http_build_query(
			array(
				'start_time' => max( 0, $cursor ),
				'per_page'   => max( 1, min( 1000, (int) $limit ) ),
			)
		);
		$result = $this->get_http()->get( $base . '/api/v2/incremental/users.json?' . $query, array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['users'] ) || ! is_array( $body['users'] ) ) {
			return array();
		}
		return $body['users'];
	}

	/**
	 * Fetch organizations (no incremental endpoint — page through full list).
	 *
	 * @param string $base  Base URL.
	 * @param array  $auth  Auth headers.
	 * @param int    $limit Max items.
	 * @return array<array>
	 */
	private function fetch_organizations( $base, array $auth, $limit ) {
		$query  = http_build_query( array( 'per_page' => max( 1, min( 100, (int) $limit ) ) ) );
		$result = $this->get_http()->get( $base . '/api/v2/organizations.json?' . $query, array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['organizations'] ) || ! is_array( $body['organizations'] ) ) {
			return array();
		}
		return $body['organizations'];
	}

	/**
	 * Resolve the Zendesk base URL from the configured subdomain.
	 *
	 * @return string
	 */
	private function resolve_base_url() {
		$config    = $this->get_config();
		$subdomain = isset( $config['subdomain'] ) ? sanitize_key( str_replace( '.', '-', (string) $config['subdomain'] ) ) : '';
		if ( '' === $subdomain ) {
			return '';
		}
		return 'https://' . $subdomain . '.zendesk.com';
	}

	/**
	 * Resolve auth headers.
	 *
	 * Prefers Basic auth (email/token + api_token), falls back to OAuth2.
	 *
	 * @return array|WP_Error
	 */
	private function resolve_auth_headers() {
		$config = $this->get_config();
		$email  = isset( $config['email'] ) ? trim( (string) $config['email'] ) : '';
		$token  = isset( $config['api_token'] ) ? (string) $config['api_token'] : '';
		if ( '' !== $email && '' !== $token ) {
			return array(
				'Authorization' => 'Basic ' . base64_encode( $email . '/token:' . $token ),
				'Accept'        => 'application/json',
			);
		}

		$access = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( ! is_wp_error( $resolved ) ) {
				$access = (string) $resolved;
			}
		}
		if ( '' === $access ) {
			return new WP_Error( 'zendesk_no_auth', __( 'Configure either email + api_token or an OAuth2 access_token.', 'nvoos-graphify' ) );
		}
		return array(
			'Authorization' => 'Bearer ' . $access,
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
	 * Push a new ticket to Zendesk.
	 *
	 * Creates a ticket in Zendesk Support via the REST API.
	 *
	 * @since 2.6.0
	 * @param array $ticket_data Ticket fields:
	 *   - subject  (string) Required.
	 *   - body     (string) Ticket description.
	 *   - priority (string) 'urgent','high','normal','low'. Default 'normal'.
	 *   - type     (string) 'problem','incident','question','task'. Default 'incident'.
	 *   - tags     (array)  Array of tag strings.
	 *   - email    (string) Requester email.
	 *   - name     (string) Requester name.
	 * @return array|WP_Error Response with 'ticket_id' or error.
	 */
	public function push_ticket_create( array $ticket_data ) {
		$base = $this->resolve_base_url();
		$auth = $this->resolve_auth_headers();
		if ( '' === $base || is_wp_error( $auth ) ) {
			return new WP_Error(
				'zendesk_config',
				__( 'Zendesk connection not configured.', 'nvoos-graphify' )
			);
		}

		$subject = isset( $ticket_data['subject'] ) ? sanitize_text_field( $ticket_data['subject'] ) : __( 'New Ticket', 'nvoos-graphify' );

		$payload = array(
			'ticket' => array(
				'subject'  => $subject,
				'comment'  => array(
					'body' => isset( $ticket_data['body'] ) ? sanitize_textarea_field( $ticket_data['body'] ) : '',
				),
				'priority' => isset( $ticket_data['priority'] ) ? sanitize_text_field( $ticket_data['priority'] ) : 'normal',
				'type'     => isset( $ticket_data['type'] ) ? sanitize_text_field( $ticket_data['type'] ) : 'incident',
			),
		);

		// Requester email.
		if ( ! empty( $ticket_data['email'] ) ) {
			$payload['ticket']['requester'] = array(
				'name'  => isset( $ticket_data['name'] ) ? sanitize_text_field( $ticket_data['name'] ) : '',
				'email' => sanitize_email( $ticket_data['email'] ),
			);
		}

		// Tags.
		if ( ! empty( $ticket_data['tags'] ) && is_array( $ticket_data['tags'] ) ) {
			$payload['ticket']['tags'] = array_map( 'sanitize_text_field', $ticket_data['tags'] );
		}

		/**
		 * Filter the Zendesk ticket payload before creation.
		 *
		 * @since 2.6.0
		 * @param array $payload     Full API payload.
		 * @param array $ticket_data Original ticket data.
		 */
		$payload = apply_filters( 'nvoos_graphify_zendesk_create_payload', $payload, $ticket_data );

		$result = $this->get_http()->post(
			$base . '/api/v2/tickets.json',
			$payload,
			array( 'headers' => $auth )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return new WP_Error(
				'zendesk_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: response body */
					__( 'Zendesk returned HTTP %1$d: %2$s', 'nvoos-graphify' ),
					(int) $result['status'],
					(string) $result['body']
				)
			);
		}

		$body = json_decode( (string) $result['body'], true );
		$zd_ticket_id = isset( $body['ticket']['id'] ) ? (int) $body['ticket']['id'] : 0;

		return array(
			'success'         => true,
			'zendesk_ticket_id' => $zd_ticket_id,
			'ticket_data'     => $body['ticket'] ?? array(),
		);
	}

	/**
	 * Push a ticket update to Zendesk.
	 *
	 * Updates ticket status, priority, adds a comment, or changes assignee.
	 *
	 * @since 2.6.0
	 * @param int   $zd_ticket_id Zendesk ticket ID.
	 * @param array $updates      Fields to update:
	 *   - status   (string) 'new','open','pending','solved','closed'.
	 *   - priority (string) 'urgent','high','normal','low'.
	 *   - comment  (string) Public comment to add.
	 *   - assignee_id (int) Zendesk user ID.
	 * @return array|WP_Error
	 */
	public function push_ticket_update( $zd_ticket_id, array $updates ) {
		$base = $this->resolve_base_url();
		$auth = $this->resolve_auth_headers();
		if ( '' === $base || is_wp_error( $auth ) ) {
			return new WP_Error(
				'zendesk_config',
				__( 'Zendesk connection not configured.', 'nvoos-graphify' )
			);
		}

		$zd_ticket_id = absint( $zd_ticket_id );
		if ( ! $zd_ticket_id ) {
			return new WP_Error(
				'invalid_id',
				__( 'A valid Zendesk ticket ID is required.', 'nvoos-graphify' )
			);
		}

		$payload = array( 'ticket' => array() );

		if ( isset( $updates['status'] ) ) {
			$payload['ticket']['status'] = sanitize_text_field( $updates['status'] );
		}
		if ( isset( $updates['priority'] ) ) {
			$payload['ticket']['priority'] = sanitize_text_field( $updates['priority'] );
		}
		if ( isset( $updates['assignee_id'] ) ) {
			$payload['ticket']['assignee_id'] = absint( $updates['assignee_id'] );
		}
		if ( isset( $updates['comment'] ) && ! empty( $updates['comment'] ) ) {
			$payload['ticket']['comment'] = array(
				'body'   => sanitize_textarea_field( $updates['comment'] ),
				'public' => true,
			);
		}

		if ( empty( $payload['ticket'] ) ) {
			return new WP_Error(
				'no_updates',
				__( 'No valid update fields provided.', 'nvoos-graphify' )
			);
		}

		$result = $this->get_http()->put(
			$base . '/api/v2/tickets/' . $zd_ticket_id . '.json',
			$payload,
			array( 'headers' => $auth )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return new WP_Error(
				'zendesk_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: response body */
					__( 'Zendesk returned HTTP %1$d: %2$s', 'nvoos-graphify' ),
					(int) $result['status'],
					(string) $result['body']
				)
			);
		}

		return array(
			'success'         => true,
			'zendesk_ticket_id' => $zd_ticket_id,
		);
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
