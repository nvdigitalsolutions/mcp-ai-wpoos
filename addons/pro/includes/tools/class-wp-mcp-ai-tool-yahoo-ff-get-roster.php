<?php
/**
 * Tool for retrieving Yahoo Fantasy Football roster information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves user's fantasy football team roster from Yahoo Fantasy Sports API.
 */
class WP_MCP_AI_Tool_Yahoo_FF_Get_Roster implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_get_roster';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - Get Roster', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves team roster from a Yahoo Fantasy Football league. Returns player details, positions, and current lineup status.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'league_key' => array(
					'type'        => 'string',
					'description' => __( 'Yahoo league key (e.g., "nfl.l.123456"). Required to identify the specific league.', 'mcp-ai-wpoos' ),
				),
				'team_key'   => array(
					'type'        => 'string',
					'description' => __( 'Yahoo team key (e.g., "nfl.l.123456.t.1"). If not provided, retrieves the user\'s team in the specified league.', 'mcp-ai-wpoos' ),
				),
				'week'       => array(
					'type'        => 'integer',
					'description' => __( 'Week number to retrieve roster for. Defaults to current week.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 18,
				),
			),
			'required'             => array( 'league_key' ),
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

		// Validate required parameters.
		if ( empty( $arguments['league_key'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_league_key', __( 'League key is required.', 'mcp-ai-wpoos' ) );
		}

		$league_key = sanitize_text_field( $arguments['league_key'] );
		$team_key   = isset( $arguments['team_key'] ) ? sanitize_text_field( $arguments['team_key'] ) : '';
		$week       = isset( $arguments['week'] ) ? absint( $arguments['week'] ) : null;

		// Check for valid access token.
		$access_token = $this->get_valid_access_token( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Build API URL.
		if ( empty( $team_key ) ) {
			// Get user's team in the league.
			$api_url = sprintf(
				'https://fantasysports.yahooapis.com/fantasy/v2/league/%s/teams;use_login=1',
				rawurlencode( $league_key )
			);
		} else {
			// Get specific team roster.
			$api_url = sprintf(
				'https://fantasysports.yahooapis.com/fantasy/v2/team/%s/roster',
				rawurlencode( $team_key )
			);

			if ( null !== $week ) {
				$api_url = add_query_arg( 'week', $week, $api_url );
			}
		}

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
				__( 'Failed to retrieve roster from Yahoo Fantasy Sports.', 'mcp-ai-wpoos' ),
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

		$roster_data = $this->parse_roster_response( $data );

		return array(
			'league_key'    => $league_key,
			'team_key'      => $roster_data['team_key'] ?? $team_key,
			'team_name'     => $roster_data['team_name'] ?? '',
			'week'          => $week,
			'roster'        => $roster_data['players'] ?? array(),
			'total_players' => count( $roster_data['players'] ?? array() ),
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
			// Token refresh would be handled here - reusing logic from get_leagues tool.
			return new WP_Error(
				'wp_mcp_ai_token_expired',
				__( 'Access token expired. Use the yahoo_ff_get_leagues tool to refresh your token, then try again.', 'mcp-ai-wpoos' )
			);
		}

		return $access_token;
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
	 * Parse roster from API response.
	 *
	 * @param array $data API response data.
	 * @return array Parsed roster data.
	 */
	protected function parse_roster_response( array $data ) {
		$result = array(
			'team_key'  => '',
			'team_name' => '',
			'players'   => array(),
		);

		if ( ! isset( $data['fantasy_content'] ) ) {
			return $result;
		}

		$content = $data['fantasy_content'];

		// Handle team response.
		if ( isset( $content['team'] ) && is_array( $content['team'] ) ) {
			$team = $content['team'];

			if ( isset( $team[0]['team_key'] ) ) {
				$result['team_key'] = sanitize_text_field( $team[0]['team_key'] );
			}

			if ( isset( $team[0]['name'] ) ) {
				$result['team_name'] = sanitize_text_field( $team[0]['name'] );
			}

			// Parse roster from team data.
			if ( isset( $team[1]['roster'] ) && is_array( $team[1]['roster'] ) ) {
				$roster = $team[1]['roster'];

				if ( isset( $roster[0]['players'] ) && is_array( $roster[0]['players'] ) ) {
					$result['players'] = $this->parse_players( $roster[0]['players'] );
				}
			}
		}

		// Handle league/teams response.
		if ( isset( $content['league'] ) && is_array( $content['league'] ) ) {
			$league = $content['league'];

			if ( isset( $league[1]['teams'] ) && is_array( $league[1]['teams'] ) ) {
				$teams = $league[1]['teams'];

				foreach ( $teams as $team_data ) {
					if ( ! is_array( $team_data ) || ! isset( $team_data['team'] ) ) {
						continue;
					}

					$team = $team_data['team'];

					if ( isset( $team[0]['team_key'] ) ) {
						$result['team_key'] = sanitize_text_field( $team[0]['team_key'] );
					}

					if ( isset( $team[0]['name'] ) ) {
						$result['team_name'] = sanitize_text_field( $team[0]['name'] );
					}

					// Only process first team (user's team).
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Parse players from roster data.
	 *
	 * @param array $players Raw players data.
	 * @return array Parsed players.
	 */
	protected function parse_players( array $players ) {
		$parsed = array();

		foreach ( $players as $player_data ) {
			if ( ! is_array( $player_data ) || ! isset( $player_data['player'] ) ) {
				continue;
			}

			$player = $player_data['player'];

			if ( ! is_array( $player ) || ! isset( $player[0] ) ) {
				continue;
			}

			$parsed[] = $this->sanitize_player_data( $player );
		}

		return $parsed;
	}

	/**
	 * Sanitize player data from API.
	 *
	 * @param array $player Raw player data.
	 * @return array Sanitized player data.
	 */
	protected function sanitize_player_data( array $player ) {
		$player_info = $player[0] ?? array();

		return array(
			'player_key'         => isset( $player_info['player_key'] ) ? sanitize_text_field( $player_info['player_key'] ) : '',
			'player_id'          => isset( $player_info['player_id'] ) ? absint( $player_info['player_id'] ) : 0,
			'name'               => isset( $player_info['name']['full'] ) ? sanitize_text_field( $player_info['name']['full'] ) : '',
			'first_name'         => isset( $player_info['name']['first'] ) ? sanitize_text_field( $player_info['name']['first'] ) : '',
			'last_name'          => isset( $player_info['name']['last'] ) ? sanitize_text_field( $player_info['name']['last'] ) : '',
			'position'           => isset( $player_info['display_position'] ) ? sanitize_text_field( $player_info['display_position'] ) : '',
			'eligible_positions' => isset( $player_info['eligible_positions'] ) ? $this->parse_positions( $player_info['eligible_positions'] ) : array(),
			'team'               => isset( $player_info['editorial_team_abbr'] ) ? sanitize_text_field( $player_info['editorial_team_abbr'] ) : '',
			'bye_week'           => isset( $player_info['bye_weeks']['week'] ) ? absint( $player_info['bye_weeks']['week'] ) : 0,
			'status'             => isset( $player_info['status'] ) ? sanitize_text_field( $player_info['status'] ) : '',
			'selected_position'  => isset( $player[1]['selected_position'] ) ? sanitize_text_field( $player[1]['selected_position'][0]['position'] ) : '',
			'is_editable'        => isset( $player_info['is_editable'] ) ? (bool) $player_info['is_editable'] : false,
		);
	}

	/**
	 * Parse eligible positions array.
	 *
	 * @param array $positions Raw positions data.
	 * @return array Sanitized positions.
	 */
	protected function parse_positions( $positions ) {
		if ( ! is_array( $positions ) ) {
			return array();
		}

		$parsed = array();

		foreach ( $positions as $pos ) {
			if ( isset( $pos['position'] ) ) {
				$parsed[] = sanitize_text_field( $pos['position'] );
			}
		}

		return $parsed;
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
