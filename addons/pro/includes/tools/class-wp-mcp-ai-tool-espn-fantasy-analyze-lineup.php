<?php
/**
 * Tool to analyze optimal lineup for ESPN Fantasy Football.
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
 * Tool for analyzing optimal lineup for ESPN Fantasy Football teams.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Analyze_Lineup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_analyze_lineup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Analyze Lineup', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyze team roster to calculate the optimal lineup based on actual points scored. Shows best possible lineup, points left on bench, and lineup optimization suggestions. Perfect for reviewing "what if" scenarios after games.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Week number to analyze. Required.', 'mcp-ai-wpoos' ),
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
			'required'             => array( 'league_id', 'team_id', 'week' ),
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
				__( 'You must be authenticated to analyze lineup data.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access this data.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['league_id'] ) || empty( $arguments['team_id'] ) || empty( $arguments['week'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_espn_missing_params',
				__( 'League ID, Team ID, and Week are required.', 'mcp-ai-wpoos' )
			);
		}

		$league_id = absint( $arguments['league_id'] );
		$team_id   = absint( $arguments['team_id'] );
		$season    = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : absint( gmdate( 'Y' ) );
		$week      = absint( $arguments['week'] );

		$credentials = array();
		if ( ! empty( $arguments['espn_s2'] ) ) {
			$credentials['espn_s2'] = sanitize_text_field( $arguments['espn_s2'] );
		}
		if ( ! empty( $arguments['swid'] ) ) {
			$credentials['swid'] = sanitize_text_field( $arguments['swid'] );
		}

		$client = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );

		// Get roster for the specific week.
		$roster = $client->get_roster( $league_id, $season, $team_id, array( 'week' => $week ) );

		if ( is_wp_error( $roster ) ) {
			return $roster;
		}

		// Analyze the lineup.
		$analysis = $this->analyze_roster( $roster );

		/* translators: 1: week number, 2: points difference */
		$message = sprintf(
			__( 'Lineup analysis for Week %1$d: You could have scored %.2f more points with an optimal lineup.', 'mcp-ai-wpoos' ),
			$week,
			$analysis['points_left_on_bench']
		);

		return array_merge(
			array(
				'message' => $message,
				'summary' => $message,
				'week'    => $week,
			),
			$analysis
		);
	}

	/**
	 * Analyze roster to find optimal lineup.
	 *
	 * @param array $roster Raw roster data.
	 * @return array Analysis results.
	 */
	protected function analyze_roster( $roster ) {
		$entries = isset( $roster['entries'] ) ? $roster['entries'] : array();

		// Extract all players with their positions and points.
		$all_players = array();
		foreach ( $entries as $entry ) {
			$player = $this->extract_player_data( $entry );
			if ( $player ) {
				$all_players[] = $player;
			}
		}

		// Get league roster requirements (standard ESPN format).
		$roster_requirements = $this->get_roster_requirements();

		// Calculate actual lineup score.
		$actual_starters = array_filter(
			$all_players,
			function ( $player ) {
				return 'Bench' !== $player['lineup_slot'] && 'IR' !== $player['lineup_slot'];
			}
		);
		// Safely calculate score, ensuring we have valid arrays before using array_sum and array_column.
		$actual_points   = is_array( $actual_starters ) ? array_column( $actual_starters, 'points' ) : array();
		$actual_score    = is_array( $actual_points ) ? array_sum( $actual_points ) : 0;

		// Calculate optimal lineup.
		$optimal_lineup  = $this->calculate_optimal_lineup( $all_players, $roster_requirements );
		// Safely calculate optimal score, ensuring we have valid arrays.
		$optimal_points  = is_array( $optimal_lineup ) ? array_column( $optimal_lineup, 'points' ) : array();
		$optimal_score   = is_array( $optimal_points ) ? array_sum( $optimal_points ) : 0;

		// Find changes needed.
		$changes = $this->find_lineup_changes( $actual_starters, $optimal_lineup );

		$points_left_on_bench = $optimal_score - $actual_score;

		return array(
			'actual_score'          => round( $actual_score, 2 ),
			'optimal_score'         => round( $optimal_score, 2 ),
			'points_left_on_bench'  => round( $points_left_on_bench, 2 ),
			'efficiency_percentage' => $optimal_score > 0 ? round( ( $actual_score / $optimal_score ) * 100, 1 ) : 100,
			'actual_lineup'         => $actual_starters,
			'optimal_lineup'        => $optimal_lineup,
			'suggested_changes'     => $changes,
			'total_changes_needed'  => count( $changes ),
		);
	}

	/**
	 * Extract player data from entry.
	 *
	 * @param array $entry Player entry.
	 * @return array|null Player data or null.
	 */
	protected function extract_player_data( $entry ) {
		$player = isset( $entry['playerPoolEntry']['player'] ) ? $entry['playerPoolEntry']['player'] : array();

		if ( empty( $player ) ) {
			return null;
		}

		$first_name = isset( $player['firstName'] ) ? sanitize_text_field( $player['firstName'] ) : '';
		$last_name  = isset( $player['lastName'] ) ? sanitize_text_field( $player['lastName'] ) : '';
		$name       = trim( $first_name . ' ' . $last_name );

		$points = 0;
		if ( isset( $player['stats'] ) && is_array( $player['stats'] ) ) {
			foreach ( $player['stats'] as $stat ) {
				if ( isset( $stat['appliedTotal'] ) ) {
					$points = floatval( $stat['appliedTotal'] );
					break;
				}
			}
		}

		$position_id  = isset( $player['defaultPositionId'] ) ? absint( $player['defaultPositionId'] ) : 0;
		$lineup_slot  = isset( $entry['lineupSlotId'] ) ? absint( $entry['lineupSlotId'] ) : 20;
		$eligible_positions = $this->get_eligible_positions( $position_id );

		return array(
			'name'           => $name,
			'position'       => $this->map_position_id( $position_id ),
			'eligible_slots' => $eligible_positions,
			'points'         => $points,
			'lineup_slot'    => $this->get_lineup_slot_name( $lineup_slot ),
			'was_starter'    => $lineup_slot !== 20 && $lineup_slot !== 21,
		);
	}

	/**
	 * Get roster requirements for standard ESPN league.
	 *
	 * @return array Roster requirements.
	 */
	protected function get_roster_requirements() {
		return array(
			'QB'   => 1,
			'RB'   => 2,
			'WR'   => 2,
			'TE'   => 1,
			'FLEX' => 1, // RB/WR/TE.
			'D/ST' => 1,
			'K'    => 1,
		);
	}

	/**
	 * Calculate optimal lineup.
	 *
	 * @param array $players              All players.
	 * @param array $roster_requirements  Roster requirements.
	 * @return array Optimal lineup.
	 */
	protected function calculate_optimal_lineup( $players, $roster_requirements ) {
		// Ensure players is an array before sorting.
		if ( ! is_array( $players ) ) {
			return array();
		}

		// Sort players by points (highest first).
		usort(
			$players,
			function ( $a, $b ) {
				return $b['points'] <=> $a['points'];
			}
		);

		$lineup   = array();
		$used     = array();
		$slots    = $roster_requirements;

		// Fill dedicated position slots first.
		foreach ( array( 'QB', 'TE', 'D/ST', 'K' ) as $position ) {
			if ( empty( $slots[ $position ] ) ) {
				continue;
			}

			for ( $i = 0; $i < $slots[ $position ]; $i++ ) {
				foreach ( $players as $idx => $player ) {
					if ( in_array( $idx, $used, true ) ) {
						continue;
					}

					if ( $player['position'] === $position ) {
						$lineup[]  = $player;
						$used[]    = $idx;
						break;
					}
				}
			}
		}

		// Fill RB slots.
		if ( ! empty( $slots['RB'] ) ) {
			for ( $i = 0; $i < $slots['RB']; $i++ ) {
				foreach ( $players as $idx => $player ) {
					if ( in_array( $idx, $used, true ) ) {
						continue;
					}

					if ( $player['position'] === 'RB' ) {
						$lineup[]  = $player;
						$used[]    = $idx;
						break;
					}
				}
			}
		}

		// Fill WR slots.
		if ( ! empty( $slots['WR'] ) ) {
			for ( $i = 0; $i < $slots['WR']; $i++ ) {
				foreach ( $players as $idx => $player ) {
					if ( in_array( $idx, $used, true ) ) {
						continue;
					}

					if ( $player['position'] === 'WR' ) {
						$lineup[]  = $player;
						$used[]    = $idx;
						break;
					}
				}
			}
		}

		// Fill FLEX (best remaining RB/WR/TE).
		if ( ! empty( $slots['FLEX'] ) ) {
			foreach ( $players as $idx => $player ) {
				if ( in_array( $idx, $used, true ) ) {
					continue;
				}

				if ( in_array( $player['position'], array( 'RB', 'WR', 'TE' ), true ) ) {
					$lineup[] = $player;
					$used[]   = $idx;
					break;
				}
			}
		}

		return $lineup;
	}

	/**
	 * Find lineup changes between actual and optimal.
	 *
	 * @param array $actual  Actual starters.
	 * @param array $optimal Optimal lineup.
	 * @return array Suggested changes.
	 */
	protected function find_lineup_changes( $actual, $optimal ) {
		$changes          = array();
		$actual_names     = array_column( $actual, 'name' );
		$optimal_names    = array_column( $optimal, 'name' );

		// Find players who should have been benched.
		$should_bench = array_diff( $actual_names, $optimal_names );

		// Find players who should have started.
		$should_start = array_diff( $optimal_names, $actual_names );

		foreach ( $should_start as $name ) {
			$player = current(
				array_filter(
					$optimal,
					function ( $p ) use ( $name ) {
						return $p['name'] === $name;
					}
				)
			);

			if ( $player ) {
				$changes[] = sprintf(
					/* translators: 1: player name, 2: position, 3: points */
					__( 'Start %1$s (%2$s) - scored %.2f points', 'mcp-ai-wpoos' ),
					$player['name'],
					$player['position'],
					$player['points']
				);
			}
		}

		return $changes;
	}

	/**
	 * Get eligible positions for a player.
	 *
	 * @param int $position_id Position ID.
	 * @return array Eligible positions.
	 */
	protected function get_eligible_positions( $position_id ) {
		$positions = array( $this->map_position_id( $position_id ) );

		// Add FLEX eligibility for RB/WR/TE.
		if ( in_array( $position_id, array( 2, 3, 4 ), true ) ) {
			$positions[] = 'FLEX';
		}

		return $positions;
	}

	/**
	 * Map position ID to name.
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
	 * Get lineup slot name.
	 *
	 * @param int $slot_id Lineup slot ID.
	 * @return string Slot name.
	 */
	protected function get_lineup_slot_name( $slot_id ) {
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
}
