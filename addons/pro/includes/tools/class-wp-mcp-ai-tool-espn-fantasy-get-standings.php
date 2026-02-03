<?php
/**
 * Tool to retrieve ESPN Fantasy Football league standings.
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
 * Tool for retrieving ESPN Fantasy Football league standings.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Get_Standings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_get_standings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Get Standings', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve current ESPN Fantasy Football league standings sorted by wins and points, including rankings, records, and playoff positions.', 'mcp-ai-wpoos' );
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
				'season'    => array(
					'type'        => 'integer',
					'description' => __( 'Season year. Defaults to current year.', 'mcp-ai-wpoos' ),
					'minimum'     => 2000,
					'maximum'     => 2100,
					'default'     => gmdate( 'Y' ),
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
			'required'             => array( 'league_id' ),
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

		if ( empty( $arguments['league_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_espn_missing_league_id',
				__( 'League ID is required.', 'mcp-ai-wpoos' )
			);
		}

		$league_id = absint( $arguments['league_id'] );
		$season    = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : absint( gmdate( 'Y' ) );

		$credentials = array();
		if ( ! empty( $arguments['espn_s2'] ) ) {
			$credentials['espn_s2'] = sanitize_text_field( $arguments['espn_s2'] );
		}
		if ( ! empty( $arguments['swid'] ) ) {
			$credentials['swid'] = sanitize_text_field( $arguments['swid'] );
		}

		$client    = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );
		$standings = $client->get_standings( $league_id, $season );

		if ( is_wp_error( $standings ) ) {
			return $standings;
		}

		$formatted_standings = array();
		$rank                = 1;

		foreach ( $standings as $team ) {
			$formatted_standings[] = $this->format_standing( $team, $rank );
			$rank++;
		}

		/* translators: %d: number of teams */
		$message = sprintf(
			__( 'Retrieved standings for %d teams.', 'mcp-ai-wpoos' ),
			count( $formatted_standings )
		);

		return array_merge(
			array(
				'message'   => $message,
				'summary'   => $message,
				'standings' => $formatted_standings,
				'count'     => count( $formatted_standings ),
			)
		);
	}

	/**
	 * Format standing data.
	 *
	 * @param array $team Raw team data.
	 * @param int   $rank Team rank.
	 * @return array Formatted standing.
	 */
	protected function format_standing( $team, $rank ) {
		$record = isset( $team['record']['overall'] ) ? $team['record']['overall'] : array();

		$wins   = isset( $record['wins'] ) ? absint( $record['wins'] ) : 0;
		$losses = isset( $record['losses'] ) ? absint( $record['losses'] ) : 0;
		$ties   = isset( $record['ties'] ) ? absint( $record['ties'] ) : 0;

		$win_pct = ( $wins + $losses + $ties ) > 0
			? round( $wins / ( $wins + $losses + $ties ), 3 )
			: 0;

		return array(
			'rank'           => $rank,
			'team_id'        => isset( $team['id'] ) ? absint( $team['id'] ) : 0,
			'team_name'      => isset( $team['name'] ) ? sanitize_text_field( $team['name'] ) : '',
			'abbreviation'   => isset( $team['abbrev'] ) ? sanitize_text_field( $team['abbrev'] ) : '',
			'wins'           => $wins,
			'losses'         => $losses,
			'ties'           => $ties,
			'win_percentage' => $win_pct,
			'points_for'     => isset( $record['pointsFor'] ) ? floatval( $record['pointsFor'] ) : 0.0,
			'points_against' => isset( $record['pointsAgainst'] ) ? floatval( $record['pointsAgainst'] ) : 0.0,
			'streak_type'    => isset( $record['streakType'] ) ? sanitize_text_field( $record['streakType'] ) : '',
			'streak_length'  => isset( $record['streakLength'] ) ? absint( $record['streakLength'] ) : 0,
			'playoff_seed'   => isset( $team['playoffSeed'] ) ? absint( $team['playoffSeed'] ) : 0,
		);
	}
}
