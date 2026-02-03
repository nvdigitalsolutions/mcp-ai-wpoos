<?php
/**
 * Tool to retrieve team roster from ESPN Fantasy Football.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-espn-fantasy-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Tool for retrieving ESPN Fantasy Football team roster.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Get_Roster implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_get_roster';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Get Roster', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve a team\'s roster from ESPN Fantasy Football including starting lineup, bench players, injured reserve, positions, and weekly points scored.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'league_id' => array(
					'type'        => 'integer',
					'description' => __( 'ESPN Fantasy Football league ID.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'team_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Team ID within the league.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'season'    => array(
					'type'        => 'integer',
					'description' => __( 'Season year. Defaults to current year.', 'mcp-ai-wpoos' ),
					'minimum'     => 2000,
					'maximum'     => 2100,
					'default'     => gmdate( 'Y' ),
				),
				'week'      => array(
					'type'        => 'integer',
					'description' => __( 'Optional. Specific week number to get roster for. If not provided, gets current week.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 18,
				),
				'espn_s2'   => array(
					'type'        => 'string',
					'description' => __( 'Optional ESPN S2 cookie for private leagues.', 'mcp-ai-wpoos' ),
				),
				'swid'      => array(
					'type'        => 'string',
					'description' => __( 'Optional SWID cookie for private leagues.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'league_id', 'team_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'read-only',
			'rate-limited',
			'cacheable',
			'pii-data',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to access ESPN Fantasy Football data.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access this data.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['league_id'] ) || empty( $arguments['team_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_espn_missing_params',
				__( 'League ID and Team ID are required.', 'mcp-ai-wpoos' )
			);
		}

		$league_id = absint( $arguments['league_id'] );
		$team_id   = absint( $arguments['team_id'] );
		$season    = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : absint( gmdate( 'Y' ) );
		$week      = isset( $arguments['week'] ) ? absint( $arguments['week'] ) : 0;

		$credentials = array();
		if ( ! empty( $arguments['espn_s2'] ) ) {
			$credentials['espn_s2'] = sanitize_text_field( $arguments['espn_s2'] );
		}
		if ( ! empty( $arguments['swid'] ) ) {
			$credentials['swid'] = sanitize_text_field( $arguments['swid'] );
		}

		$client = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );

		$options = array();
		if ( $week > 0 ) {
			$options['week'] = $week;
		}

		$roster = $client->get_roster( $league_id, $season, $team_id, $options );

		if ( is_wp_error( $roster ) ) {
			return $roster;
		}

		$formatted_roster = $this->format_roster_data( $roster );

		$week_text = $week > 0 ? sprintf( __( ' (Week %d)', 'mcp-ai-wpoos' ), $week ) : '';
		/* translators: 1: team ID, 2: optional week text */
		$message = sprintf(
			__( 'Retrieved roster for Team %1$d%2$s with %3$d players.', 'mcp-ai-wpoos' ),
			$team_id,
			$week_text,
			count( $formatted_roster['players'] )
		);

		return array_merge(
			array(
				'message' => $message,
				'summary' => $message,
			),
			$formatted_roster
		);
	}

	/**
	 * Format roster data.
	 *
	 * @param array $roster Raw roster data.
	 * @return array Formatted roster.
	 */
	protected function format_roster_data( $roster ) {
		$entries = isset( $roster['entries'] ) ? $roster['entries'] : array();

		$starters    = array();
		$bench       = array();
		$ir          = array();
		$total_points = 0;

		foreach ( $entries as $entry ) {
			$player_data = $this->format_player_entry( $entry );

			// Categorize by lineup slot.
			$lineup_slot = isset( $entry['lineupSlotId'] ) ? absint( $entry['lineupSlotId'] ) : 20;

			if ( $lineup_slot === 20 ) {
				// Bench.
				$bench[] = $player_data;
			} elseif ( $lineup_slot === 21 ) {
				// IR.
				$ir[] = $player_data;
			} else {
				// Starter.
				$starters[] = $player_data;
				$total_points += $player_data['points'];
			}
		}

		return array(
			'starters'      => $starters,
			'bench'         => $bench,
			'injured_reserve' => $ir,
			'total_starters' => count( $starters ),
			'total_bench'   => count( $bench ),
			'total_points'  => round( $total_points, 2 ),
			'players'       => array_merge( $starters, $bench, $ir ),
		);
	}

	/**
	 * Format individual player entry.
	 *
	 * @param array $entry Player entry data.
	 * @return array Formatted player data.
	 */
	protected function format_player_entry( $entry ) {
		$player = isset( $entry['playerPoolEntry']['player'] ) ? $entry['playerPoolEntry']['player'] : array();

		$player_id   = isset( $player['id'] ) ? absint( $player['id'] ) : 0;
		$player_name = $this->get_player_name( $player );
		$position    = $this->get_position_name( $entry );
		$team        = isset( $player['proTeamId'] ) ? $this->get_pro_team_abbrev( $player['proTeamId'] ) : '';

		// Get points for the week.
		$points = 0;
		if ( isset( $player['stats'] ) && is_array( $player['stats'] ) ) {
			foreach ( $player['stats'] as $stat ) {
				if ( isset( $stat['appliedTotal'] ) ) {
					$points = floatval( $stat['appliedTotal'] );
					break;
				}
			}
		}

		// Get injury status.
		$injury_status = isset( $player['injuryStatus'] ) ? sanitize_text_field( $player['injuryStatus'] ) : '';

		return array(
			'player_id'     => $player_id,
			'name'          => $player_name,
			'position'      => $position,
			'pro_team'      => $team,
			'points'        => round( $points, 2 ),
			'injury_status' => $injury_status,
			'lineup_slot'   => $this->get_lineup_slot_name( $entry ),
		);
	}

	/**
	 * Get player full name.
	 *
	 * @param array $player Player data.
	 * @return string Player name.
	 */
	protected function get_player_name( $player ) {
		$first = isset( $player['firstName'] ) ? sanitize_text_field( $player['firstName'] ) : '';
		$last  = isset( $player['lastName'] ) ? sanitize_text_field( $player['lastName'] ) : '';

		return trim( $first . ' ' . $last );
	}

	/**
	 * Get position name from entry.
	 *
	 * @param array $entry Player entry.
	 * @return string Position name.
	 */
	protected function get_position_name( $entry ) {
		$player = isset( $entry['playerPoolEntry']['player'] ) ? $entry['playerPoolEntry']['player'] : array();

		if ( isset( $player['defaultPositionId'] ) ) {
			return $this->map_position_id( absint( $player['defaultPositionId'] ) );
		}

		return 'UNKNOWN';
	}

	/**
	 * Get lineup slot name.
	 *
	 * @param array $entry Player entry.
	 * @return string Lineup slot name.
	 */
	protected function get_lineup_slot_name( $entry ) {
		$slot_id = isset( $entry['lineupSlotId'] ) ? absint( $entry['lineupSlotId'] ) : 20;

		$slots = array(
			0  => 'QB',
			2  => 'RB',
			4  => 'WR',
			6  => 'TE',
			16 => 'D/ST',
			17 => 'K',
			20 => 'Bench',
			21 => 'IR',
			23 => 'FLEX',
		);

		return isset( $slots[ $slot_id ] ) ? $slots[ $slot_id ] : 'UNKNOWN';
	}

	/**
	 * Map ESPN position ID to position name.
	 *
	 * @param int $position_id Position ID.
	 * @return string Position name.
	 */
	protected function map_position_id( $position_id ) {
		$positions = array(
			1  => 'QB',
			2  => 'RB',
			3  => 'WR',
			4  => 'TE',
			5  => 'K',
			16 => 'D/ST',
		);

		return isset( $positions[ $position_id ] ) ? $positions[ $position_id ] : 'UNKNOWN';
	}

	/**
	 * Get pro team abbreviation.
	 *
	 * @param int $team_id ESPN pro team ID.
	 * @return string Team abbreviation.
	 */
	protected function get_pro_team_abbrev( $team_id ) {
		$teams = array(
			1  => 'ATL',
			2  => 'BUF',
			3  => 'CHI',
			4  => 'CIN',
			5  => 'CLE',
			6  => 'DAL',
			7  => 'DEN',
			8  => 'DET',
			9  => 'GB',
			10 => 'TEN',
			11 => 'IND',
			12 => 'KC',
			13 => 'LV',
			14 => 'LAR',
			15 => 'MIA',
			16 => 'MIN',
			17 => 'NE',
			18 => 'NO',
			19 => 'NYG',
			20 => 'NYJ',
			21 => 'PHI',
			22 => 'ARI',
			23 => 'PIT',
			24 => 'LAC',
			25 => 'SF',
			26 => 'SEA',
			27 => 'TB',
			28 => 'WAS',
			29 => 'CAR',
			30 => 'JAX',
			33 => 'BAL',
			34 => 'HOU',
		);

		return isset( $teams[ $team_id ] ) ? $teams[ $team_id ] : '';
	}
}
