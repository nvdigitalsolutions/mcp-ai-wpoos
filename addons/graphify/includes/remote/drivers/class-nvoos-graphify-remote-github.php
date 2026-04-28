<?php
/**
 * NV oOS Graphify — GitHub Remote Driver (Pro)
 *
 * Pulls GitHub repository metadata, issues, pull requests, and contributors
 * as graph nodes plus the relationships between them:
 *
 *   user  CONTRIBUTES_TO  repo
 *   issue OPENED_BY       user
 *   pr    OPENED_BY       user
 *   issue / pr  IN_REPO   repo
 *
 * Authentication: a personal-access token (`token`) or OAuth2 access token via
 * the `Authorization: Bearer …` header. Incremental sync uses the `since`
 * query parameter on `/issues` combined with the per-source state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub remote-source driver.
 *
 * @since 0.7.1
 */
class NV_oOS_Graphify_Remote_GitHub extends NV_oOS_Graphify_Remote_Source_Base {

	const API_BASE = 'https://api.github.com';

	/**
	 * HTTP client (lazy).
	 *
	 * @var NV_oOS_Graphify_HTTP_Client|null
	 */
	private $http;

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'github';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'GitHub', 'nvoos-graphify' );
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
			'access_token' => array(
				'type'        => 'password',
				'label'       => __( 'GitHub Token', 'nvoos-graphify' ),
				'description' => __( 'Personal access token, fine-grained PAT, or OAuth access token.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'repos'        => array(
				'type'        => 'text',
				'label'       => __( 'Repositories', 'nvoos-graphify' ),
				'description' => __( 'Comma-separated owner/repo identifiers (e.g. octocat/Hello-World, my-org/site).', 'nvoos-graphify' ),
				'required'    => true,
			),
			'include_prs'  => array(
				'type'    => 'checkbox',
				'label'   => __( 'Include Pull Requests', 'nvoos-graphify' ),
				'default' => true,
			),
			'max_items'    => array(
				'type'    => 'number',
				'label'   => __( 'Max Items Per Repo', 'nvoos-graphify' ),
				'default' => 100,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		$token = $this->resolve_token();
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'No GitHub access_token configured.', 'nvoos-graphify' ),
			);
		}
		$result = $this->get_http()->get( self::API_BASE . '/user', array( 'headers' => $this->auth_headers( $token ) ) );
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
				'message' => sprintf( __( 'GitHub returned HTTP %d.', 'nvoos-graphify' ), (int) $result['status'] ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'Connected to GitHub.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$token = $this->resolve_token();
		if ( '' === $token ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$nodes     = array();

		foreach ( $this->resolve_repos() as $full_name ) {
			$repo = $this->fetch_repo( $full_name, $token );
			if ( ! is_array( $repo ) ) {
				continue;
			}
			$nodes[] = $this->repo_to_node( $repo, $slug );

			$contributors = $this->fetch_contributors( $full_name, $token, $max_items );
			foreach ( $contributors as $user ) {
				$nodes[] = $this->user_to_node( $user, $slug );
			}

			$issues = $this->fetch_issues( $full_name, $token, $max_items );
			foreach ( $issues as $issue ) {
				$is_pr = isset( $issue['pull_request'] );
				if ( $is_pr && ! $this->include_prs() ) {
					continue;
				}
				$nodes[] = $this->issue_to_node( $issue, $full_name, $slug, $is_pr );
				if ( ! empty( $issue['user'] ) && is_array( $issue['user'] ) ) {
					$nodes[] = $this->user_to_node( $issue['user'], $slug );
				}
			}
		}

		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_sync_iso', gmdate( 'c' ) );
		NV_oOS_Graphify_Remote_State_Store::mark_synced( $slug, gmdate( 'c' ) );
		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_edges( array $args = array() ) {
		$token = $this->resolve_token();
		if ( '' === $token ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_max_items( $args );
		$edges     = array();

		foreach ( $this->resolve_repos() as $full_name ) {
			$repo_node_id = $this->repo_node_id( $full_name, $slug );

			// Contributors → repo.
			$contributors = $this->fetch_contributors( $full_name, $token, $max_items );
			foreach ( $contributors as $user ) {
				if ( empty( $user['login'] ) ) {
					continue;
				}
				$edges[] = array(
					'source_node_id' => $this->user_node_id( (string) $user['login'], $slug ),
					'target_node_id' => $repo_node_id,
					'relation'       => 'CONTRIBUTES_TO',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);
			}

			// Issues / PRs → repo + author edges.
			$issues = $this->fetch_issues( $full_name, $token, $max_items );
			foreach ( $issues as $issue ) {
				$is_pr = isset( $issue['pull_request'] );
				if ( $is_pr && ! $this->include_prs() ) {
					continue;
				}
				$issue_id      = isset( $issue['id'] ) ? (string) $issue['id'] : '';
				$issue_node_id = $this->issue_node_id( $full_name, $issue_id, $slug, $is_pr );

				$edges[] = array(
					'source_node_id' => $issue_node_id,
					'target_node_id' => $repo_node_id,
					'relation'       => 'IN_REPO',
					'confidence'     => 1.0,
					'provenance'     => 'REMOTE',
					'source_slug'    => $slug,
				);

				if ( ! empty( $issue['user']['login'] ) ) {
					$edges[] = array(
						'source_node_id' => $issue_node_id,
						'target_node_id' => $this->user_node_id( (string) $issue['user']['login'], $slug ),
						'relation'       => 'OPENED_BY',
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
	 * Convert a GitHub repo payload to a graph node.
	 *
	 * @param array  $repo Repo payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function repo_to_node( array $repo, $slug ) {
		$full_name = isset( $repo['full_name'] ) ? (string) $repo['full_name'] : '';
		$id        = isset( $repo['id'] ) ? (string) $repo['id'] : md5( $full_name );
		return array(
			'node_id'     => $this->repo_node_id( $full_name, $slug ),
			'label'       => sanitize_text_field( $full_name ),
			'type'        => 'repository',
			'post_id'     => 0,
			'url'         => isset( $repo['html_url'] ) ? esc_url_raw( (string) $repo['html_url'] ) : '',
			'properties'  => array(
				'description' => isset( $repo['description'] ) ? sanitize_text_field( (string) $repo['description'] ) : '',
				'language'    => isset( $repo['language'] ) ? sanitize_text_field( (string) $repo['language'] ) : '',
				'stars'       => isset( $repo['stargazers_count'] ) ? (int) $repo['stargazers_count'] : 0,
				'forks'       => isset( $repo['forks_count'] ) ? (int) $repo['forks_count'] : 0,
			),
			'external_id' => 'github:repo:' . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a GitHub user payload to a graph node.
	 *
	 * @param array  $user User payload.
	 * @param string $slug Source slug.
	 * @return array
	 */
	public function user_to_node( array $user, $slug ) {
		$login = isset( $user['login'] ) ? (string) $user['login'] : '';
		return array(
			'node_id'     => $this->user_node_id( $login, $slug ),
			'label'       => sanitize_text_field( $login ),
			'type'        => 'person',
			'post_id'     => 0,
			'url'         => isset( $user['html_url'] ) ? esc_url_raw( (string) $user['html_url'] ) : '',
			'properties'  => array(
				'github_login' => sanitize_text_field( $login ),
				'avatar_url'   => isset( $user['avatar_url'] ) ? esc_url_raw( (string) $user['avatar_url'] ) : '',
			),
			'external_id' => 'github:user:' . sanitize_key( $login ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Convert a GitHub issue/PR payload to a graph node.
	 *
	 * @param array  $issue     Issue payload.
	 * @param string $full_name Repo full name.
	 * @param string $slug      Source slug.
	 * @param bool   $is_pr     Whether the record is a pull request.
	 * @return array
	 */
	public function issue_to_node( array $issue, $full_name, $slug, $is_pr ) {
		$id = isset( $issue['id'] ) ? (string) $issue['id'] : '';
		return array(
			'node_id'     => $this->issue_node_id( $full_name, $id, $slug, $is_pr ),
			'label'       => isset( $issue['title'] ) ? sanitize_text_field( (string) $issue['title'] ) : ( $is_pr ? 'pr:' : 'issue:' ) . $id,
			'type'        => $is_pr ? 'pull_request' : 'issue',
			'post_id'     => 0,
			'url'         => isset( $issue['html_url'] ) ? esc_url_raw( (string) $issue['html_url'] ) : '',
			'properties'  => array(
				'state'  => isset( $issue['state'] ) ? sanitize_text_field( (string) $issue['state'] ) : '',
				'number' => isset( $issue['number'] ) ? (int) $issue['number'] : 0,
				'is_pr'  => $is_pr,
			),
			'external_id' => ( $is_pr ? 'github:pr:' : 'github:issue:' ) . sanitize_key( $id ),
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build the node_id for a repository.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $slug      Source slug.
	 * @return string
	 */
	public function repo_node_id( $full_name, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_repo_' . sanitize_key( str_replace( '/', '__', $full_name ) );
	}

	/**
	 * Build the node_id for a user.
	 *
	 * @param string $login GitHub login.
	 * @param string $slug  Source slug.
	 * @return string
	 */
	public function user_node_id( $login, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_user_' . sanitize_key( $login );
	}

	/**
	 * Build the node_id for an issue/PR.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $id        Numeric ID (string).
	 * @param string $slug      Source slug.
	 * @param bool   $is_pr     Whether this is a pull request.
	 * @return string
	 */
	public function issue_node_id( $full_name, $id, $slug, $is_pr ) {
		$prefix = $is_pr ? '_pr_' : '_issue_';
		return 'remote_' . sanitize_key( $slug ) . $prefix . sanitize_key( str_replace( '/', '__', $full_name ) ) . '_' . sanitize_key( (string) $id );
	}

	/**
	 * Fetch a single repository.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $token     Token.
	 * @return array|null
	 */
	private function fetch_repo( $full_name, $token ) {
		$endpoint = self::API_BASE . '/repos/' . $this->encode_repo( $full_name );
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return null;
		}
		$body = json_decode( (string) $result['body'], true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Fetch repo contributors.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $token     Token.
	 * @param int    $limit     Limit.
	 * @return array<array>
	 */
	private function fetch_contributors( $full_name, $token, $limit ) {
		$endpoint = self::API_BASE . '/repos/' . $this->encode_repo( $full_name ) . '/contributors?per_page=' . max( 1, min( 100, (int) $limit ) );
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Fetch repo issues (and PRs since GitHub returns them via /issues).
	 *
	 * @param string $full_name owner/repo.
	 * @param string $token     Token.
	 * @param int    $limit     Limit.
	 * @return array<array>
	 */
	private function fetch_issues( $full_name, $token, $limit ) {
		$slug      = $this->get_slug();
		$since_iso = (string) NV_oOS_Graphify_Remote_State_Store::get( $slug, 'last_sync_iso', '' );
		$query     = 'state=all&per_page=' . max( 1, min( 100, (int) $limit ) );
		if ( '' !== $since_iso ) {
			$query .= '&since=' . rawurlencode( $since_iso );
		}
		$endpoint = self::API_BASE . '/repos/' . $this->encode_repo( $full_name ) . '/issues?' . $query;
		$result   = $this->get_http()->get( $endpoint, array( 'headers' => $this->auth_headers( $token ) ) );
		if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] >= 300 ) {
			return array();
		}
		$body = json_decode( (string) $result['body'], true );
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Resolve the access token from config.
	 *
	 * @return string
	 */
	private function resolve_token() {
		$config = $this->get_config();
		return isset( $config['access_token'] ) ? (string) $config['access_token'] : '';
	}

	/**
	 * Resolve the repos list.
	 *
	 * @return array<string>
	 */
	private function resolve_repos() {
		$config = $this->get_config();
		$raw    = isset( $config['repos'] ) ? (string) $config['repos'] : '';
		$out    = array();
		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece || false === strpos( $piece, '/' ) ) {
				continue;
			}
			$out[] = $piece;
		}
		return $out;
	}

	/**
	 * Whether to include pull requests.
	 *
	 * @return bool
	 */
	private function include_prs() {
		$config = $this->get_config();
		return ! isset( $config['include_prs'] ) || ! empty( $config['include_prs'] );
	}

	/**
	 * Resolve per-repo item limit.
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
	 * URL-encode a `owner/repo` identifier component-wise.
	 *
	 * @param string $full_name owner/repo.
	 * @return string
	 */
	private function encode_repo( $full_name ) {
		$parts = explode( '/', $full_name, 2 );
		$parts = array_map( 'rawurlencode', $parts );
		return implode( '/', $parts );
	}

	/**
	 * Build standard auth + Accept headers.
	 *
	 * @param string $token Token.
	 * @return array
	 */
	private function auth_headers( $token ) {
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/vnd.github+json',
			'User-Agent'    => 'NV-oOS-Graphify',
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
