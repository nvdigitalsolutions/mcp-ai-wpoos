<?php
/**
 * GitHub API Client for WP oOS
 *
 * Provides methods to interact with the GitHub REST API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Github_Client' ) ) {
	/**
	 * GitHub API client for repository and codespace operations.
	 */
	class WP_MCP_AI_Github_Client {
		const GITHUB_API_BASE = 'https://api.github.com';

		/**
		 * Access token for GitHub API.
		 *
		 * @var string
		 */
		private $access_token;

		/**
		 * Constructor.
		 *
		 * @param string|null $access_token Optional. GitHub access token.
		 */
		public function __construct( $access_token = null ) {
			if ( $access_token ) {
				$this->access_token = $access_token;
			} else {
				$settings             = WP_MCP_AI_Admin_Settings::get_settings();
				$this->access_token = isset( $settings['github_access_token'] ) ? $settings['github_access_token'] : '';
			}
		}

		/**
		 * Make a request to the GitHub API.
		 *
		 * @param string $endpoint API endpoint (without base URL).
		 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $body     Request body for POST/PUT requests.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		private function request( $endpoint, $method = 'GET', $body = array() ) {
			if ( empty( $this->access_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_github_no_token',
					__( 'GitHub access token is not configured.', 'wp-mcp-ai' )
				);
			}

			$url  = self::GITHUB_API_BASE . $endpoint;
			$args = array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Accept'        => 'application/vnd.github+json',
					'Authorization' => 'Bearer ' . $this->access_token,
					'User-Agent'    => 'WP-MCP-AI-Plugin',
				),
			);

			if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body']                   = wp_json_encode( $body );
				$args['headers']['Content-Type'] = 'application/json';
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Handle rate limiting.
			if ( 403 === $status_code ) {
				$headers = wp_remote_retrieve_headers( $response );
				if ( isset( $headers['X-RateLimit-Remaining'] ) && '0' === $headers['X-RateLimit-Remaining'] ) {
					return new WP_Error(
						'wp_mcp_ai_github_rate_limit',
						__( 'GitHub API rate limit exceeded. Please try again later.', 'wp-mcp-ai' )
					);
				}
			}

			if ( $status_code >= 400 ) {
				$error_data = json_decode( $response_body, true );
				$message    = isset( $error_data['message'] ) ? $error_data['message'] : __( 'GitHub API request failed.', 'wp-mcp-ai' );

				return new WP_Error(
					'wp_mcp_ai_github_api_error',
					$message,
					array( 'status' => $status_code )
				);
			}

			$data = json_decode( $response_body, true );

			return is_array( $data ) ? $data : array();
		}

		/**
		 * Get the authenticated user's information.
		 *
		 * @return array|WP_Error User data or WP_Error on failure.
		 */
		public function get_user() {
			return $this->request( '/user' );
		}

		/**
		 * List repositories for the authenticated user.
		 *
		 * @param array $args Query arguments (per_page, page, sort, direction, type).
		 * @return array|WP_Error List of repositories or WP_Error on failure.
		 */
		public function list_repositories( $args = array() ) {
			$defaults = array(
				'per_page'  => 30,
				'page'      => 1,
				'sort'      => 'updated',
				'direction' => 'desc',
				'type'      => 'all',
			);

			$args     = wp_parse_args( $args, $defaults );
			$endpoint = '/user/repos?' . http_build_query( $args );

			return $this->request( $endpoint );
		}

		/**
		 * Get a specific repository.
		 *
		 * @param string $owner Repository owner.
		 * @param string $repo  Repository name.
		 * @return array|WP_Error Repository data or WP_Error on failure.
		 */
		public function get_repository( $owner, $repo ) {
			return $this->request( "/repos/{$owner}/{$repo}" );
		}

		/**
		 * List branches for a repository.
		 *
		 * @param string $owner Repository owner.
		 * @param string $repo  Repository name.
		 * @param array  $args  Query arguments (per_page, page).
		 * @return array|WP_Error List of branches or WP_Error on failure.
		 */
		public function list_branches( $owner, $repo, $args = array() ) {
			$defaults = array(
				'per_page' => 30,
				'page'     => 1,
			);

			$args     = wp_parse_args( $args, $defaults );
			$endpoint = "/repos/{$owner}/{$repo}/branches?" . http_build_query( $args );

			return $this->request( $endpoint );
		}

		/**
		 * Create a new branch.
		 *
		 * @param string $owner      Repository owner.
		 * @param string $repo       Repository name.
		 * @param string $branch     New branch name.
		 * @param string $source_sha SHA of the commit to branch from.
		 * @return array|WP_Error Reference data or WP_Error on failure.
		 */
		public function create_branch( $owner, $repo, $branch, $source_sha ) {
			$endpoint = "/repos/{$owner}/{$repo}/git/refs";
			$body     = array(
				'ref' => "refs/heads/{$branch}",
				'sha' => $source_sha,
			);

			return $this->request( $endpoint, 'POST', $body );
		}

		/**
		 * Get the default branch for a repository.
		 *
		 * @param string $owner Repository owner.
		 * @param string $repo  Repository name.
		 * @return string|WP_Error Default branch name or WP_Error on failure.
		 */
		public function get_default_branch( $owner, $repo ) {
			$repository = $this->get_repository( $owner, $repo );

			if ( is_wp_error( $repository ) ) {
				return $repository;
			}

			return isset( $repository['default_branch'] ) ? $repository['default_branch'] : 'main';
		}

		/**
		 * Get the latest commit SHA for a branch.
		 *
		 * @param string $owner  Repository owner.
		 * @param string $repo   Repository name.
		 * @param string $branch Branch name.
		 * @return string|WP_Error Commit SHA or WP_Error on failure.
		 */
		public function get_branch_sha( $owner, $repo, $branch ) {
			$endpoint = "/repos/{$owner}/{$repo}/git/refs/heads/{$branch}";
			$ref      = $this->request( $endpoint );

			if ( is_wp_error( $ref ) ) {
				return $ref;
			}

			return isset( $ref['object']['sha'] ) ? $ref['object']['sha'] : new WP_Error(
				'wp_mcp_ai_github_no_sha',
				__( 'Could not determine branch SHA.', 'wp-mcp-ai' )
			);
		}

		/**
		 * List codespaces for the authenticated user.
		 *
		 * @param array $args Query arguments (per_page, page, repository_id).
		 * @return array|WP_Error List of codespaces or WP_Error on failure.
		 */
		public function list_codespaces( $args = array() ) {
			$defaults = array(
				'per_page' => 30,
				'page'     => 1,
			);

			$args     = wp_parse_args( $args, $defaults );
			$endpoint = '/user/codespaces?' . http_build_query( $args );

			return $this->request( $endpoint );
		}

		/**
		 * Create a new codespace.
		 *
		 * @param string $owner           Repository owner.
		 * @param string $repo            Repository name.
		 * @param array  $codespace_args  Codespace configuration (ref, machine, location, etc.).
		 * @return array|WP_Error Codespace data or WP_Error on failure.
		 */
		public function create_codespace( $owner, $repo, $codespace_args = array() ) {
			$endpoint = "/repos/{$owner}/{$repo}/codespaces";
			$defaults = array(
				'ref'              => 'main',
				'machine'          => 'basicLinux32gb',
				'retention_period_minutes' => 10080, // 7 days.
			);

			$body = wp_parse_args( $codespace_args, $defaults );

			return $this->request( $endpoint, 'POST', $body );
		}

		/**
		 * Get a specific codespace.
		 *
		 * @param string $codespace_name Codespace name.
		 * @return array|WP_Error Codespace data or WP_Error on failure.
		 */
		public function get_codespace( $codespace_name ) {
			return $this->request( "/user/codespaces/{$codespace_name}" );
		}

		/**
		 * Start a codespace.
		 *
		 * @param string $codespace_name Codespace name.
		 * @return array|WP_Error Codespace data or WP_Error on failure.
		 */
		public function start_codespace( $codespace_name ) {
			return $this->request( "/user/codespaces/{$codespace_name}/start", 'POST' );
		}

		/**
		 * Stop a codespace.
		 *
		 * @param string $codespace_name Codespace name.
		 * @return array|WP_Error Codespace data or WP_Error on failure.
		 */
		public function stop_codespace( $codespace_name ) {
			return $this->request( "/user/codespaces/{$codespace_name}/stop", 'POST' );
		}

		/**
		 * Delete a codespace.
		 *
		 * @param string $codespace_name Codespace name.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function delete_codespace( $codespace_name ) {
			$result = $this->request( "/user/codespaces/{$codespace_name}", 'DELETE' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		/**
		 * Get contents of a file or directory in a repository.
		 *
		 * @param string $owner Repository owner.
		 * @param string $repo  Repository name.
		 * @param string $path  File or directory path.
		 * @param string $ref   Branch, tag, or commit SHA.
		 * @return array|WP_Error File/directory contents or WP_Error on failure.
		 */
		public function get_contents( $owner, $repo, $path = '', $ref = '' ) {
			$endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";

			if ( $ref ) {
				$endpoint .= '?ref=' . urlencode( $ref );
			}

			return $this->request( $endpoint );
		}

		/**
		 * Create or update a file in a repository.
		 *
		 * @param string $owner   Repository owner.
		 * @param string $repo    Repository name.
		 * @param string $path    File path.
		 * @param string $message Commit message.
		 * @param string $content File content (will be base64 encoded).
		 * @param string $branch  Branch name.
		 * @param string $sha     SHA of the file being replaced (for updates).
		 * @return array|WP_Error Commit data or WP_Error on failure.
		 */
		public function create_or_update_file( $owner, $repo, $path, $message, $content, $branch, $sha = '' ) {
			$endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";
			$body     = array(
				'message' => $message,
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'branch'  => $branch,
			);

			if ( $sha ) {
				$body['sha'] = $sha;
			}

			return $this->request( $endpoint, 'PUT', $body );
		}
	}
}
