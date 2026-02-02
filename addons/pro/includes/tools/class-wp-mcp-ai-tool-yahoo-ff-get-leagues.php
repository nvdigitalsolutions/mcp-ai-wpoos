<?php
/**
 * Tool for retrieving Yahoo Fantasy Football league information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves user's fantasy football leagues from Yahoo Fantasy Sports API.
 */
class WP_MCP_AI_Tool_Yahoo_FF_Get_Leagues implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_get_leagues';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - Get Leagues', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a list of the user\'s fantasy football leagues from Yahoo Fantasy Sports. Returns league details including name, ID, season, scoring type, and standings.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'season'   => array(
					'type'        => 'integer',
					'description' => __( 'NFL season year (e.g., 2025). Defaults to current season.', 'mcp-ai-wpoos' ),
					'minimum'     => 2000,
					'maximum'     => 2099,
				),
				'game_key' => array(
					'type'        => 'string',
					'description' => __( 'Yahoo game key (e.g., "nfl" for NFL). Defaults to "nfl".', 'mcp-ai-wpoos' ),
					'default'     => 'nfl',
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access Yahoo Fantasy Sports data.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check for valid access token.
		$access_token = $this->get_valid_access_token( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$season   = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : (int) gmdate( 'Y' );
		$game_key = isset( $arguments['game_key'] ) ? sanitize_key( $arguments['game_key'] ) : 'nfl';

		// Construct API endpoint.
		$api_url = sprintf(
			'https://fantasysports.yahooapis.com/fantasy/v2/users;use_login=1/games;game_keys=%s/leagues',
			rawurlencode( $game_key )
		);

		// Make API request.
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_request_failed',
				__( 'Failed to retrieve leagues from Yahoo Fantasy Sports.', 'mcp-ai-wpoos' ),
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			return $this->handle_api_error( $response, $status_code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_bad_json', __( 'The Yahoo API response could not be decoded.', 'mcp-ai-wpoos' ) );
		}

		$leagues = $this->parse_leagues_response( $data );

		return array(
			'season'        => $season,
			'game_key'      => $game_key,
			'leagues'       => $leagues,
			'total_leagues' => count( $leagues ),
		);
	}

	/**
	 * Get valid access token, refreshing if necessary.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error Access token or error.
	 */
	protected function get_valid_access_token( $user_id ) {
		$access_token  = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', true );
		$refresh_token = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', true );
		$expires_at    = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_token_expires', true );

		if ( empty( $access_token ) || empty( $refresh_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_authenticated',
				__( 'You must authenticate with Yahoo Fantasy Sports first. Use the yahoo_ff_auth tool to get started.', 'mcp-ai-wpoos' )
			);
		}

		// Check if token is expired.
		if ( ! empty( $expires_at ) && time() > (int) $expires_at ) {
			// Attempt to refresh token.
			$new_token = $this->refresh_access_token( $user_id, $refresh_token );

			if ( is_wp_error( $new_token ) ) {
				return $new_token;
			}

			$access_token = $new_token;
		}

		return $access_token;
	}

	/**
	 * Refresh expired access token.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $refresh_token Refresh token.
	 * @return string|WP_Error New access token or error.
	 */
	protected function refresh_access_token( $user_id, $refresh_token ) {
		$client_id     = get_option( 'wp_mcp_ai_yahoo_client_id' );
		$client_secret = get_option( 'wp_mcp_ai_yahoo_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Yahoo API credentials are not configured.', 'mcp-ai-wpoos' )
			);
		}

		$response = wp_remote_post(
			'https://api.login.yahoo.com/oauth2/get_token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_token_refresh_failed',
				__( 'Failed to refresh access token.', 'mcp-ai-wpoos' ),
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token' );
			delete_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token' );

			return new WP_Error(
				'wp_mcp_ai_token_refresh_failed',
				__( 'Failed to refresh access token. Please re-authenticate using the yahoo_ff_auth tool.', 'mcp-ai-wpoos' )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_token_response',
				__( 'Invalid token refresh response.', 'mcp-ai-wpoos' )
			);
		}

		// Store new tokens.
		update_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', sanitize_text_field( $data['access_token'] ) );

		if ( ! empty( $data['refresh_token'] ) ) {
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', sanitize_text_field( $data['refresh_token'] ) );
		}

		if ( ! empty( $data['expires_in'] ) ) {
			$expires_at = time() + absint( $data['expires_in'] );
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_token_expires', $expires_at );
		}

		return sanitize_text_field( $data['access_token'] );
	}

	/**
	 * Handle API error response.
	 *
	 * @param array|WP_Error $response    API response.
	 * @param int            $status_code HTTP status code.
	 * @return WP_Error Error object.
	 */
	protected function handle_api_error( $response, $status_code ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$error_message = sprintf(
			/* translators: %d: HTTP status code */
			__( 'Yahoo API returned status %d.', 'mcp-ai-wpoos' ),
			(int) $status_code
		);

		if ( is_array( $data ) && ! empty( $data['error'] ) ) {
			if ( is_string( $data['error'] ) ) {
				$error_message .= ' ' . sanitize_text_field( $data['error'] );
			} elseif ( is_array( $data['error'] ) && ! empty( $data['error']['description'] ) ) {
				$error_message .= ' ' . sanitize_text_field( $data['error']['description'] );
			}
		}

		return new WP_Error( 'wp_mcp_ai_api_error', $error_message );
	}

	/**
	 * Parse leagues from API response.
	 *
	 * @param array $data API response data.
	 * @return array Parsed leagues.
	 */
	protected function parse_leagues_response( array $data ) {
		$leagues = array();

		// Yahoo API returns nested structure.
		if ( ! isset( $data['fantasy_content']['users'] ) ) {
			return $leagues;
		}

		$users = $data['fantasy_content']['users'];

		if ( ! is_array( $users ) ) {
			return $leagues;
		}

		foreach ( $users as $user_data ) {
			if ( ! is_array( $user_data ) || ! isset( $user_data['user'] ) ) {
				continue;
			}

			$user = $user_data['user'];

			if ( ! isset( $user[1]['games'] ) ) {
				continue;
			}

			$games = $user[1]['games'];

			if ( ! is_array( $games ) ) {
				continue;
			}

			foreach ( $games as $game_data ) {
				if ( ! is_array( $game_data ) || ! isset( $game_data['game'] ) ) {
					continue;
				}

				$game = $game_data['game'];

				if ( ! isset( $game[1]['leagues'] ) ) {
					continue;
				}

				$league_data = $game[1]['leagues'];

				if ( ! is_array( $league_data ) ) {
					continue;
				}

				foreach ( $league_data as $league_item ) {
					if ( ! is_array( $league_item ) || ! isset( $league_item['league'] ) ) {
						continue;
					}

					$league = $league_item['league'];

					if ( ! is_array( $league ) ) {
						continue;
					}

					$leagues[] = $this->sanitize_league_data( $league );
				}
			}
		}

		return $leagues;
	}

	/**
	 * Sanitize league data from API.
	 *
	 * @param array $league Raw league data.
	 * @return array Sanitized league data.
	 */
	protected function sanitize_league_data( array $league ) {
		return array(
			'league_id'    => isset( $league[0]['league_id'] ) ? sanitize_text_field( $league[0]['league_id'] ) : '',
			'league_key'   => isset( $league[0]['league_key'] ) ? sanitize_text_field( $league[0]['league_key'] ) : '',
			'name'         => isset( $league[0]['name'] ) ? sanitize_text_field( $league[0]['name'] ) : '',
			'season'       => isset( $league[0]['season'] ) ? absint( $league[0]['season'] ) : 0,
			'scoring_type' => isset( $league[0]['scoring_type'] ) ? sanitize_text_field( $league[0]['scoring_type'] ) : '',
			'league_type'  => isset( $league[0]['league_type'] ) ? sanitize_text_field( $league[0]['league_type'] ) : '',
			'num_teams'    => isset( $league[0]['num_teams'] ) ? absint( $league[0]['num_teams'] ) : 0,
			'current_week' => isset( $league[0]['current_week'] ) ? absint( $league[0]['current_week'] ) : 0,
			'start_week'   => isset( $league[0]['start_week'] ) ? absint( $league[0]['start_week'] ) : 0,
			'end_week'     => isset( $league[0]['end_week'] ) ? absint( $league[0]['end_week'] ) : 0,
			'is_finished'  => isset( $league[0]['is_finished'] ) ? (bool) $league[0]['is_finished'] : false,
			'url'          => isset( $league[0]['url'] ) ? esc_url_raw( $league[0]['url'] ) : '',
		);
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
			'toolkit'               => 'fantasy_football',
			'pattern_compatibility' => array( 'event_driven' ),
			'profession_tags'       => array( 'fantasy_sports_manager', 'sports_analyst' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'external-api',
			'requires-credentials',
			'requires-capability',
			'network-dependent',
		);
	}
}
