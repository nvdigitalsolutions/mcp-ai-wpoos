<?php
/**
 * Tool for retrieving Yahoo Fantasy Football player statistics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves player statistics from Yahoo Fantasy Sports API.
 */
class WP_MCP_AI_Tool_Yahoo_FF_Get_Player_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_get_player_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - Get Player Stats', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves player statistics for fantasy football from Yahoo Fantasy Sports API. Returns weekly and season stats including fantasy points.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Yahoo league key (e.g., "nfl.l.123456"). Required to get league-specific scoring.', 'mcp-ai-wpoos' ),
				),
				'player_key' => array(
					'type'        => 'string',
					'description' => __( 'Yahoo player key (e.g., "nfl.p.12345"). Required to identify specific player.', 'mcp-ai-wpoos' ),
				),
				'week'       => array(
					'type'        => 'integer',
					'description' => __( 'Week number to retrieve stats for. If omitted, retrieves season stats.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 18,
				),
			),
			'required'             => array( 'league_key', 'player_key' ),
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

		if ( empty( $arguments['player_key'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_player_key', __( 'Player key is required.', 'mcp-ai-wpoos' ) );
		}

		$league_key = sanitize_text_field( $arguments['league_key'] );
		$player_key = sanitize_text_field( $arguments['player_key'] );
		$week       = isset( $arguments['week'] ) ? absint( $arguments['week'] ) : null;

		// Check for valid access token.
		$access_token = $this->get_valid_access_token( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Build API URL.
		$api_url = sprintf(
			'https://fantasysports.yahooapis.com/fantasy/v2/league/%s/players;player_keys=%s/stats',
			rawurlencode( $league_key ),
			rawurlencode( $player_key )
		);

		if ( null !== $week ) {
			$api_url = add_query_arg( 'type', 'week', $api_url );
			$api_url = add_query_arg( 'week', $week, $api_url );
		} else {
			$api_url = add_query_arg( 'type', 'season', $api_url );
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
				__( 'Failed to retrieve player stats from Yahoo Fantasy Sports.', 'mcp-ai-wpoos' ),
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

		$stats_data = $this->parse_stats_response( $data );

		return array(
			'league_key'     => $league_key,
			'player_key'     => $player_key,
			'player_name'    => $stats_data['player_name'] ?? '',
			'position'       => $stats_data['position'] ?? '',
			'team'           => $stats_data['team'] ?? '',
			'week'           => $week,
			'stats_type'     => $week ? 'week' : 'season',
			'fantasy_points' => $stats_data['fantasy_points'] ?? 0,
			'stats'          => $stats_data['stats'] ?? array(),
		);
	}

	/**
	 * Get valid access token.
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
	 * Parse stats from API response.
	 *
	 * @param array $data API response data.
	 * @return array Parsed stats data.
	 */
	protected function parse_stats_response( array $data ) {
		$result = array(
			'player_name'    => '',
			'position'       => '',
			'team'           => '',
			'fantasy_points' => 0,
			'stats'          => array(),
		);

		if ( ! isset( $data['fantasy_content']['league'] ) ) {
			return $result;
		}

		$league = $data['fantasy_content']['league'];

		if ( ! isset( $league[1]['players'] ) || ! is_array( $league[1]['players'] ) ) {
			return $result;
		}

		$players = $league[1]['players'];

		foreach ( $players as $player_data ) {
			if ( ! is_array( $player_data ) || ! isset( $player_data['player'] ) ) {
				continue;
			}

			$player = $player_data['player'];

			if ( ! is_array( $player ) || ! isset( $player[0] ) ) {
				continue;
			}

			$player_info = $player[0];

			// Get player basic info.
			if ( isset( $player_info['name']['full'] ) ) {
				$result['player_name'] = sanitize_text_field( $player_info['name']['full'] );
			}

			if ( isset( $player_info['display_position'] ) ) {
				$result['position'] = sanitize_text_field( $player_info['display_position'] );
			}

			if ( isset( $player_info['editorial_team_abbr'] ) ) {
				$result['team'] = sanitize_text_field( $player_info['editorial_team_abbr'] );
			}

			// Get player stats.
			if ( isset( $player[1]['player_stats'] ) && is_array( $player[1]['player_stats'] ) ) {
				$player_stats = $player[1]['player_stats'];

				if ( isset( $player_stats['stats'] ) && is_array( $player_stats['stats'] ) ) {
					$result['stats'] = $this->parse_stat_values( $player_stats['stats'] );
				}

				// Get fantasy points if available.
				if ( isset( $player_stats['stats'] ) && is_array( $player_stats['stats'] ) ) {
					foreach ( $player_stats['stats'] as $stat ) {
						if ( is_array( $stat ) && isset( $stat['stat'] ) ) {
							$stat_data = $stat['stat'];
							// Stat ID 0 is typically fantasy points.
							if ( isset( $stat_data['stat_id'] ) && '0' === (string) $stat_data['stat_id'] ) {
								$result['fantasy_points'] = (float) ( $stat_data['value'] ?? 0 );
								break;
							}
						}
					}
				}
			}

			// Only process first player.
			break;
		}

		return $result;
	}

	/**
	 * Parse stat values from stats array.
	 *
	 * @param array $stats Raw stats data.
	 * @return array Parsed stat values.
	 */
	protected function parse_stat_values( array $stats ) {
		$parsed = array();

		foreach ( $stats as $stat_item ) {
			if ( ! is_array( $stat_item ) || ! isset( $stat_item['stat'] ) ) {
				continue;
			}

			$stat = $stat_item['stat'];

			if ( ! isset( $stat['stat_id'] ) || ! isset( $stat['value'] ) ) {
				continue;
			}

			$stat_id   = absint( $stat['stat_id'] );
			$stat_name = $this->get_stat_name( $stat_id );

			$parsed[] = array(
				'stat_id'   => $stat_id,
				'stat_name' => $stat_name,
				'value'     => is_numeric( $stat['value'] ) ? (float) $stat['value'] : sanitize_text_field( $stat['value'] ),
			);
		}

		return $parsed;
	}

	/**
	 * Get human-readable stat name from stat ID.
	 *
	 * @param int $stat_id Stat ID.
	 * @return string Stat name.
	 */
	protected function get_stat_name( $stat_id ) {
		$stat_names = array(
			0  => __( 'Fantasy Points', 'mcp-ai-wpoos' ),
			4  => __( 'Passing Yards', 'mcp-ai-wpoos' ),
			5  => __( 'Passing Touchdowns', 'mcp-ai-wpoos' ),
			6  => __( 'Interceptions', 'mcp-ai-wpoos' ),
			9  => __( 'Rushing Yards', 'mcp-ai-wpoos' ),
			10 => __( 'Rushing Touchdowns', 'mcp-ai-wpoos' ),
			11 => __( 'Receptions', 'mcp-ai-wpoos' ),
			12 => __( 'Receiving Yards', 'mcp-ai-wpoos' ),
			13 => __( 'Receiving Touchdowns', 'mcp-ai-wpoos' ),
			15 => __( 'Return Touchdowns', 'mcp-ai-wpoos' ),
			16 => __( '2-Point Conversions', 'mcp-ai-wpoos' ),
			18 => __( 'Fumbles Lost', 'mcp-ai-wpoos' ),
			57 => __( 'Offensive Fumbles Recovered', 'mcp-ai-wpoos' ),
		);

		return isset( $stat_names[ $stat_id ] ) ? $stat_names[ $stat_id ] : sprintf(
			/* translators: %d: Stat ID */
			__( 'Stat %d', 'mcp-ai-wpoos' ),
			$stat_id
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
