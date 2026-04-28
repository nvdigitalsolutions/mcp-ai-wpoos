<?php
/**
 * NV oOS Graphify — ServiceNow Remote Driver (Pro)
 *
 * Pulls ServiceNow incidents, users (sys_user), and CMDB configuration items
 * (cmdb_ci) as graph nodes plus the relationships between them as edges:
 *
 *   incident   ASSIGNED_TO   user
 *   incident   REPORTED_BY   user
 *   incident   AFFECTS       cmdb_ci
 *
 * Authentication: ServiceNow Basic auth (instance username + password) is the
 * canonical option for server-to-server integrations. OAuth2 is also
 * supported via the shared NV_oOS_Graphify_OAuth_Broker.
 *
 * Incremental sync uses the `sysparm_query=sys_updated_on>=<iso>` filter
 * combined with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ServiceNow remote-source driver.
 *
 * @since 0.7.3
 */
class NV_oOS_Graphify_Remote_ServiceNow extends NV_oOS_Graphify_Remote_Source_Base {

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'servicenow';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'ServiceNow', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => false,
			'supports_oauth'         => true,
			'supports_pagination'    => true,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'instance_url'  => array(
				'type'        => 'url',
				'label'       => __( 'Instance URL', 'nvoos-graphify' ),
				'description' => __( 'ServiceNow instance (e.g. https://acme.service-now.com).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'username'      => array(
				'type'        => 'text',
				'label'       => __( 'Username', 'nvoos-graphify' ),
				'description' => __( 'Basic auth username.', 'nvoos-graphify' ),
			),
			'password'      => array(
				'type'  => 'password',
				'label' => __( 'Password', 'nvoos-graphify' ),
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
			'token_url'     => array(
				'type'        => 'url',
				'label'       => __( 'OAuth Token URL', 'nvoos-graphify' ),
				'description' => __( 'Defaults to {instance}/oauth_token.do.', 'nvoos-graphify' ),
			),
			'extra_query'   => array(
				'type'        => 'text',
				'label'       => __( 'Extra Incident Query', 'nvoos-graphify' ),
				'description' => __( 'Optional encoded query appended to incident search (e.g. "active=true").', 'nvoos-graphify' ),
				'default'     => '',
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
		$base = $this->resolve_base_url();
		if ( '' === $base ) {
			return array(
				'success' => false,
				'message' => __( 'No ServiceNow instance_url configured.', 'nvoos-graphify' ),
			);
		}
		$auth = $this->resolve_auth_headers();
		if ( is_wp_error( $auth ) ) {
			return array(
				'success' => false,
				'message' => $auth->get_error_message(),
			);
		}
		$result = $this->get_http()->get(
			$base . '/api/now/table/sys_user?sysparm_limit=1',
			array( 'headers' => $auth )
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
				'message' => sprintf( __( 'ServiceNow returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to ServiceNow.', 'nvoos-graphify' ),
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

		foreach ( $this->fetch_table( $base, $auth, 'incident', $max_items, $this->incident_query() ) as $incident ) {
			$nodes[] = $this->incident_to_node( $incident, $slug );
		}
		foreach ( $this->fetch_table( $base, $auth, 'sys_user', $max_items, $this->updated_query() ) as $user ) {
			$nodes[] = $this->user_to_node( $user, $slug );
		}
		foreach ( $this->fetch_table( $base, $auth, 'cmdb_ci', $max_items, $this->updated_query() ) as $ci ) {
			$nodes[] = $this->ci_to_node( $ci, $slug );
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_updated_iso', gmdate( 'Y-m-d H:i:s' ) );
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

		foreach ( $this->fetch_table( $base, $auth, 'incident', $max_items, $this->incident_query() ) as $incident ) {
			$incident_sys_id = isset( $incident['sys_id'] ) ? (string) $incident['sys_id'] : '';
			if ( '' === $incident_sys_id ) {
				continue;
			}
			$incident_node_id = $this->incident_node_id( $incident_sys_id, $slug );

			$relations = array(
				'assigned_to' => 'ASSIGNED_TO',
				'caller_id'   => 'REPORTED_BY',
				'cmdb_ci'     => 'AFFECTS',
			);
			foreach ( $relations as $field => $relation ) {
				$ref = $this->extract_reference( $incident, $field );
				if ( '' === $ref ) {
					continue;
				}
				$target = ( 'cmdb_ci' === $field )
					? $this->ci_node_id( $ref, $slug )
					: $this->user_node_id( $ref, $slug );

				$edges[] = array(
					'source_node_id' => $incident_node_id,
					'target_node_id' => $target,
					'relation'       => $relation,
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}
		}

		return $edges;
	}

	// -------------------------------------------------------------------------
	// Node mappers (public for testability)
	// -------------------------------------------------------------------------

	/**
	 * Convert a ServiceNow incident record to a graph node.
	 *
	 * @param array  $incident Record.
	 * @param string $slug     Source slug.
	 * @return array
	 */
	public function incident_to_node( array $incident, $slug ) {
		$sys_id = isset( $incident['sys_id'] ) ? (string) $incident['sys_id'] : '';
		$number = isset( $incident['number'] ) ? (string) $incident['number'] : '';
		$short  = isset( $incident['short_description'] ) ? (string) $incident['short_description'] : ( '' !== $number ? $number : ( '' !== $sys_id ? 'incident:' . $sys_id : 'incident' ) );

		return array(
			'node_id'     => $this->incident_node_id( $sys_id, $slug ),
			'label'       => sanitize_text_field( $short ),
			'type'        => 'incident',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'sn_sys_id' => sanitize_text_field( $sys_id ),
				'sn_number' => sanitize_text_field( $number ),
				'state'     => isset( $incident['state'] ) ? sanitize_text_field( (string) $incident['state'] ) : '',
				'priority'  => isset( $incident['priority'] ) ? sanitize_text_field( (string) $incident['priority'] ) : '',
				'category'  => isset( $incident['category'] ) ? sanitize_text_field( (string) $incident['category'] ) : '',
			),
			'external_id' => 'servicenow:incident:' . sanitize_key( $sys_id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a sys_user record to a graph node.
	 *
	 * @param array  $user Record.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function user_to_node( array $user, $slug ) {
		$sys_id = isset( $user['sys_id'] ) ? (string) $user['sys_id'] : '';
		$name   = isset( $user['name'] ) ? (string) $user['name'] : ( isset( $user['user_name'] ) ? (string) $user['user_name'] : ( '' !== $sys_id ? 'user:' . $sys_id : 'user' ) );
		$email  = isset( $user['email'] ) ? sanitize_email( (string) $user['email'] ) : '';

		$out = array(
			'node_id'     => $this->user_node_id( $sys_id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'person',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'sn_sys_id'   => sanitize_text_field( $sys_id ),
				'sn_username' => isset( $user['user_name'] ) ? sanitize_text_field( (string) $user['user_name'] ) : '',
			),
			'external_id' => 'servicenow:user:' . sanitize_key( $sys_id ),
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
	 * Convert a cmdb_ci record to a graph node.
	 *
	 * @param array  $ci   Record.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function ci_to_node( array $ci, $slug ) {
		$sys_id = isset( $ci['sys_id'] ) ? (string) $ci['sys_id'] : '';
		$name   = isset( $ci['name'] ) ? (string) $ci['name'] : ( '' !== $sys_id ? 'ci:' . $sys_id : 'configuration_item' );
		$class  = isset( $ci['sys_class_name'] ) ? (string) $ci['sys_class_name'] : 'cmdb_ci';

		return array(
			'node_id'     => $this->ci_node_id( $sys_id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'configuration_item',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'sn_sys_id'          => sanitize_text_field( $sys_id ),
				'sn_class_name'      => sanitize_text_field( $class ),
				'operational_status' => isset( $ci['operational_status'] ) ? sanitize_text_field( (string) $ci['operational_status'] ) : '',
			),
			'external_id' => 'servicenow:ci:' . sanitize_key( $sys_id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build the node_id for an incident.
	 *
	 * @param string $sys_id Sys_id.
	 * @param string $slug   Source slug.
	 * @return string
	 */
	public function incident_node_id( $sys_id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_incident_' . sanitize_key( $sys_id );
	}

	/**
	 * Build the node_id for a sys_user.
	 *
	 * @param string $sys_id Sys_id.
	 * @param string $slug   Source slug.
	 * @return string
	 */
	public function user_node_id( $sys_id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_user_' . sanitize_key( $sys_id );
	}

	/**
	 * Build the node_id for a cmdb_ci.
	 *
	 * @param string $sys_id Sys_id.
	 * @param string $slug   Source slug.
	 * @return string
	 */
	public function ci_node_id( $sys_id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_ci_' . sanitize_key( $sys_id );
	}

	// -------------------------------------------------------------------------
	// HTTP / query helpers
	// -------------------------------------------------------------------------

	/**
	 * Read a Table API endpoint with the supplied encoded query.
	 *
	 * @param string $base   Base URL.
	 * @param array  $auth   Auth headers.
	 * @param string $table  Table name.
	 * @param int    $limit  Max rows.
	 * @param string $query  Encoded query.
	 * @return array<array>
	 */
	private function fetch_table( $base, array $auth, $table, $limit, $query ) {
		$params = array(
			'sysparm_limit'                  => max( 1, min( 1000, (int) $limit ) ),
			'sysparm_display_value'          => 'false',
			'sysparm_exclude_reference_link' => 'true',
		);
		if ( '' !== $query ) {
			$params['sysparm_query'] = $query;
		}
		$url    = $base . '/api/now/table/' . rawurlencode( $table ) . '?' . http_build_query( $params );
		$result = $this->get_http()->get( $url, array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['result'] ) || ! is_array( $body['result'] ) ) {
			return array();
		}
		return $body['result'];
	}

	/**
	 * Build the incremental + extra query for the `incident` table.
	 *
	 * @return string
	 */
	private function incident_query() {
		$config = $this->get_config();
		$extra  = isset( $config['extra_query'] ) ? trim( (string) $config['extra_query'] ) : '';
		$base   = $this->updated_query();
		if ( '' !== $base && '' !== $extra ) {
			return $base . '^' . $extra;
		}
		return '' !== $base ? $base : $extra;
	}

	/**
	 * Build the `sys_updated_on >= <cursor>` query fragment.
	 *
	 * @return string
	 */
	private function updated_query() {
		$slug  = $this->get_slug();
		$since = (string) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_updated_iso', '' );
		if ( '' === $since ) {
			return '';
		}
		return 'sys_updated_on>=' . $since;
	}

	/**
	 * Extract a reference field from a record, supporting both the flat
	 * "sys_id" form (when sysparm_exclude_reference_link=true) and the
	 * `{value: <sys_id>}` form.
	 *
	 * @param array  $record Record.
	 * @param string $field  Field name.
	 * @return string
	 */
	private function extract_reference( array $record, $field ) {
		if ( ! isset( $record[ $field ] ) ) {
			return '';
		}
		$value = $record[ $field ];
		if ( is_array( $value ) ) {
			return isset( $value['value'] ) ? (string) $value['value'] : '';
		}
		return (string) $value;
	}

	/**
	 * Resolve the configured base URL (no trailing slash).
	 *
	 * @return string
	 */
	private function resolve_base_url() {
		$config = $this->get_config();
		$url    = isset( $config['instance_url'] ) ? trim( (string) $config['instance_url'] ) : '';
		return rtrim( esc_url_raw( $url ), '/' );
	}

	/**
	 * Resolve auth headers — Basic auth preferred, OAuth2 fallback.
	 *
	 * @return array|WP_Error
	 */
	private function resolve_auth_headers() {
		$config   = $this->get_config();
		$username = isset( $config['username'] ) ? trim( (string) $config['username'] ) : '';
		$password = isset( $config['password'] ) ? (string) $config['password'] : '';
		if ( '' !== $username && '' !== $password ) {
			return array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
				'Accept'        => 'application/json',
			);
		}

		$access = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		// Default token_url for ServiceNow if not configured.
		if ( empty( $config['token_url'] ) ) {
			$base = $this->resolve_base_url();
			if ( '' !== $base ) {
				$config['token_url'] = $base . '/oauth_token.do';
			}
		}
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( ! is_wp_error( $resolved ) ) {
				$access = (string) $resolved;
			}
		}
		if ( '' === $access ) {
			return new WP_Error( 'servicenow_no_auth', __( 'Configure either username + password or an OAuth2 access_token.', 'nvoos-graphify' ) );
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
