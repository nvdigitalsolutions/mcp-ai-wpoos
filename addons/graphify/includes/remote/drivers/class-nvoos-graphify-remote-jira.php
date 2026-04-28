<?php
/**
 * NV oOS Graphify — Atlassian Jira Remote Driver (Pro)
 *
 * Pulls Jira projects, issues, and users as graph nodes plus the relationships
 * between them as edges:
 *
 *   issue   IN_PROJECT    project
 *   issue   ASSIGNED_TO   user
 *   issue   REPORTED_BY   user
 *
 * Authentication: Atlassian Cloud Basic auth (email + API token) — the most
 * common deployment — is supported out of the box. OAuth2 (3LO) is also
 * supported via the shared NV_oOS_Graphify_OAuth_Broker when refresh tokens
 * are configured.
 *
 * Incremental sync uses Jira's JQL `updated >= "<timestamp>"` filter combined
 * with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jira (Atlassian) remote-source driver.
 *
 * @since 0.7.2
 */
class NV_oOS_Graphify_Remote_Jira extends NV_oOS_Graphify_Remote_Source_Base {

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'jira';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Jira (Atlassian)', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges', 'webhooks' );
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
			'site_url'      => array(
				'type'        => 'url',
				'label'       => __( 'Jira Site URL', 'nvoos-graphify' ),
				'description' => __( 'Atlassian Cloud site (e.g. https://your-org.atlassian.net).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'email'         => array(
				'type'        => 'text',
				'label'       => __( 'Account Email', 'nvoos-graphify' ),
				'description' => __( 'Email for Atlassian Cloud Basic auth.', 'nvoos-graphify' ),
			),
			'api_token'     => array(
				'type'        => 'password',
				'label'       => __( 'API Token', 'nvoos-graphify' ),
				'description' => __( 'Atlassian API token paired with the email above.', 'nvoos-graphify' ),
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
				'type'    => 'url',
				'label'   => __( 'OAuth Token URL', 'nvoos-graphify' ),
				'default' => 'https://auth.atlassian.com/oauth/token',
			),
			'jql'           => array(
				'type'        => 'text',
				'label'       => __( 'JQL Filter', 'nvoos-graphify' ),
				'description' => __( 'Optional JQL used to scope the issue search (e.g. "project = ENG").', 'nvoos-graphify' ),
				'default'     => '',
			),
			'max_items'     => array(
				'type'    => 'number',
				'label'   => __( 'Max Issues Per Sync', 'nvoos-graphify' ),
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
				'message' => __( 'No Jira site_url configured.', 'nvoos-graphify' ),
			);
		}
		$auth = $this->resolve_auth_headers();
		if ( is_wp_error( $auth ) ) {
			return array(
				'success' => false,
				'message' => $auth->get_error_message(),
			);
		}
		$result = $this->get_http()->get( $base . '/rest/api/3/myself', array( 'headers' => $auth ) );
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
				'message' => sprintf( __( 'Jira returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to Jira.', 'nvoos-graphify' ),
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
		$slug       = $this->get_slug();
		$max_items  = $this->resolve_max_items( $args );
		$nodes      = array();
		$seen_users = array();

		// Projects.
		$projects = $this->fetch_projects( $base, $auth );
		foreach ( $projects as $project ) {
			$nodes[] = $this->project_to_node( $project, $slug );
		}

		// Issues + their authors / assignees.
		$issues = $this->fetch_issues( $base, $auth, $max_items );
		foreach ( $issues as $issue ) {
			$nodes[] = $this->issue_to_node( $issue, $slug );

			$fields = isset( $issue['fields'] ) && is_array( $issue['fields'] ) ? $issue['fields'] : array();
			foreach ( array( 'reporter', 'assignee' ) as $person_field ) {
				if ( ! empty( $fields[ $person_field ] ) && is_array( $fields[ $person_field ] ) ) {
					$user    = $fields[ $person_field ];
					$account = isset( $user['accountId'] ) ? (string) $user['accountId'] : '';
					if ( '' === $account || isset( $seen_users[ $account ] ) ) {
						continue;
					}
					$seen_users[ $account ] = true;
					$nodes[]                = $this->user_to_node( $user, $slug );
				}
			}
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_updated_iso', gmdate( 'Y-m-d H:i' ) );
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

		$issues = $this->fetch_issues( $base, $auth, $max_items );
		foreach ( $issues as $issue ) {
			$issue_id = isset( $issue['id'] ) ? (string) $issue['id'] : '';
			if ( '' === $issue_id ) {
				continue;
			}
			$issue_node_id = $this->issue_node_id( $issue_id, $slug );
			$fields        = isset( $issue['fields'] ) && is_array( $issue['fields'] ) ? $issue['fields'] : array();

			// IN_PROJECT.
			if ( ! empty( $fields['project']['id'] ) ) {
				$edges[] = array(
					'source_node_id' => $issue_node_id,
					'target_node_id' => $this->project_node_id( (string) $fields['project']['id'], $slug ),
					'relation'       => 'IN_PROJECT',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}

			// REPORTED_BY / ASSIGNED_TO.
			$relation_map = array(
				'reporter' => 'REPORTED_BY',
				'assignee' => 'ASSIGNED_TO',
			);
			foreach ( $relation_map as $field_key => $relation ) {
				if ( ! empty( $fields[ $field_key ]['accountId'] ) ) {
					$edges[] = array(
						'source_node_id' => $issue_node_id,
						'target_node_id' => $this->user_node_id( (string) $fields[ $field_key ]['accountId'], $slug ),
						'relation'       => $relation,
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
	 * Convert a Jira project payload to a graph node.
	 *
	 * @param array  $project Project payload.
	 * @param string $slug    Source slug.
	 * @return array
	 */
	public function project_to_node( array $project, $slug ) {
		$id   = isset( $project['id'] ) ? (string) $project['id'] : '';
		$key  = isset( $project['key'] ) ? (string) $project['key'] : '';
		$name = isset( $project['name'] ) ? (string) $project['name'] : ( '' !== $key ? $key : 'project:' . $id );
		return array(
			'node_id'     => $this->project_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'project',
			'post_id'     => 0,
			'url'         => isset( $project['self'] ) ? esc_url_raw( (string) $project['self'] ) : '',
			'properties'  => array(
				'jira_project_id'  => sanitize_text_field( $id ),
				'jira_project_key' => sanitize_text_field( $key ),
			),
			'external_id' => 'jira:project:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Jira issue payload to a graph node.
	 *
	 * @param array  $issue Issue payload.
	 * @param string $slug  Source slug.
	 * @return array
	 */
	public function issue_to_node( array $issue, $slug ) {
		$id      = isset( $issue['id'] ) ? (string) $issue['id'] : '';
		$key     = isset( $issue['key'] ) ? (string) $issue['key'] : '';
		$fields  = isset( $issue['fields'] ) && is_array( $issue['fields'] ) ? $issue['fields'] : array();
		$summary = isset( $fields['summary'] ) ? (string) $fields['summary'] : ( '' !== $key ? $key : 'issue:' . $id );

		return array(
			'node_id'     => $this->issue_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $summary ),
			'type'        => 'issue',
			'post_id'     => 0,
			'url'         => isset( $issue['self'] ) ? esc_url_raw( (string) $issue['self'] ) : '',
			'properties'  => array(
				'jira_issue_id'  => sanitize_text_field( $id ),
				'jira_issue_key' => sanitize_text_field( $key ),
				'status'         => isset( $fields['status']['name'] ) ? sanitize_text_field( (string) $fields['status']['name'] ) : '',
				'issuetype'      => isset( $fields['issuetype']['name'] ) ? sanitize_text_field( (string) $fields['issuetype']['name'] ) : '',
			),
			'external_id' => 'jira:issue:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a Jira user payload to a graph node.
	 *
	 * @param array  $user User payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function user_to_node( array $user, $slug ) {
		$id    = isset( $user['accountId'] ) ? (string) $user['accountId'] : '';
		$name  = isset( $user['displayName'] ) ? (string) $user['displayName'] : ( '' !== $id ? $id : 'user' );
		$email = isset( $user['emailAddress'] ) ? sanitize_email( (string) $user['emailAddress'] ) : '';

		$out = array(
			'node_id'     => $this->user_node_id( $id, $slug ),
			'label'       => sanitize_text_field( $name ),
			'type'        => 'person',
			'post_id'     => 0,
			'url'         => '',
			'properties'  => array(
				'jira_account_id' => sanitize_text_field( $id ),
			),
			'external_id' => 'jira:user:' . sanitize_key( $id ),
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
	 * Build the node_id for a Jira project.
	 *
	 * @param string $id   Project ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function project_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_project_' . sanitize_key( $id );
	}

	/**
	 * Build the node_id for a Jira issue.
	 *
	 * @param string $id   Issue ID.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function issue_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_issue_' . sanitize_key( $id );
	}

	/**
	 * Build the node_id for a Jira user.
	 *
	 * @param string $id   accountId.
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function user_node_id( $id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_user_' . sanitize_key( $id );
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch all projects (Jira returns them in a single page).
	 *
	 * @param string $base Site base URL.
	 * @param array  $auth Auth headers.
	 * @return array<array>
	 */
	private function fetch_projects( $base, array $auth ) {
		$result = $this->get_http()->get( $base . '/rest/api/3/project/search?maxResults=100', array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['values'] ) || ! is_array( $body['values'] ) ) {
			return array();
		}
		return $body['values'];
	}

	/**
	 * Fetch issues with JQL + incremental cursor.
	 *
	 * @param string $base  Site base URL.
	 * @param array  $auth  Auth headers.
	 * @param int    $limit Max issues.
	 * @return array<array>
	 */
	private function fetch_issues( $base, array $auth, $limit ) {
		$config = $this->get_config();
		$jql    = isset( $config['jql'] ) ? trim( (string) $config['jql'] ) : '';
		$slug   = $this->get_slug();
		$since  = (string) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_updated_iso', '' );
		if ( '' !== $since ) {
			$jql = ( '' !== $jql ? '(' . $jql . ') AND ' : '' ) . 'updated >= "' . $since . '"';
		}
		$jql = '' !== $jql ? $jql : 'order by updated DESC';

		$query  = http_build_query(
			array(
				'jql'        => $jql,
				'maxResults' => max( 1, min( 100, (int) $limit ) ),
				'fields'     => 'summary,status,issuetype,project,reporter,assignee',
			)
		);
		$result = $this->get_http()->get( $base . '/rest/api/3/search?' . $query, array( 'headers' => $auth ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		if ( ! is_array( $body ) || empty( $body['issues'] ) || ! is_array( $body['issues'] ) ) {
			return array();
		}
		return $body['issues'];
	}

	/**
	 * Resolve the configured base URL (no trailing slash).
	 *
	 * @return string
	 */
	private function resolve_base_url() {
		$config = $this->get_config();
		$url    = isset( $config['site_url'] ) ? trim( (string) $config['site_url'] ) : '';
		return rtrim( esc_url_raw( $url ), '/' );
	}

	/**
	 * Resolve auth headers from configured credentials.
	 *
	 * Prefers Basic auth (email + api_token); falls back to OAuth2 access
	 * token (broker-refreshed when refresh_token is present).
	 *
	 * @return array|WP_Error
	 */
	private function resolve_auth_headers() {
		$config = $this->get_config();
		$email  = isset( $config['email'] ) ? trim( (string) $config['email'] ) : '';
		$token  = isset( $config['api_token'] ) ? (string) $config['api_token'] : '';
		if ( '' !== $email && '' !== $token ) {
			return array(
				'Authorization' => 'Basic ' . base64_encode( $email . ':' . $token ),
				'Accept'        => 'application/json',
			);
		}

		// OAuth2 path.
		$access = isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
		if ( ! empty( $config['refresh_token'] ) && class_exists( 'NV_oOS_Graphify_OAuth_Broker' ) ) {
			$resolved = NV_oOS_Graphify_OAuth_Broker::get_access_token( $config );
			if ( ! is_wp_error( $resolved ) ) {
				$access = (string) $resolved;
			}
		}
		if ( '' === $access ) {
			return new WP_Error( 'jira_no_auth', __( 'Configure either email + api_token or an OAuth2 access_token.', 'nvoos-graphify' ) );
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
