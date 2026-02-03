<?php
/**
 * Tool to retrieve all teams in an ESPN Fantasy Football league.
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
 * Tool for retrieving all teams in an ESPN Fantasy Football league.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Get_Teams implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_get_teams';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Get Teams', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve all teams in an ESPN Fantasy Football league including team names, owners, win/loss records, points for/against, and playoff positions.', 'mcp-ai-wpoos' );
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
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

		$client = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );
		$teams  = $client->get_teams( $league_id, $season );

		if ( is_wp_error( $teams ) ) {
			return $teams;
		}

		$formatted_teams = array_map( array( $this, 'format_team_data' ), $teams );

		/* translators: %d: number of teams */
		$message = sprintf(
			__( 'Retrieved %d teams from the league.', 'mcp-ai-wpoos' ),
			count( $formatted_teams )
		);

		return $this->format_collection_response( $formatted_teams, $message );
	}

	/**
	 * Format team data.
	 *
	 * @param array $team Raw team data.
	 * @return array Formatted team data.
	 */
	protected function format_team_data( $team ) {
		$record = isset( $team['record']['overall'] ) ? $team['record']['overall'] : array();

		return array(
			'team_id'        => isset( $team['id'] ) ? absint( $team['id'] ) : 0,
			'name'           => isset( $team['name'] ) ? sanitize_text_field( $team['name'] ) : '',
			'abbreviation'   => isset( $team['abbrev'] ) ? sanitize_text_field( $team['abbrev'] ) : '',
			'logo_url'       => isset( $team['logo'] ) ? esc_url_raw( $team['logo'] ) : '',
			'owner'          => $this->get_owner_name( $team ),
			'wins'           => isset( $record['wins'] ) ? absint( $record['wins'] ) : 0,
			'losses'         => isset( $record['losses'] ) ? absint( $record['losses'] ) : 0,
			'ties'           => isset( $record['ties'] ) ? absint( $record['ties'] ) : 0,
			'points_for'     => isset( $record['pointsFor'] ) ? floatval( $record['pointsFor'] ) : 0.0,
			'points_against' => isset( $record['pointsAgainst'] ) ? floatval( $record['pointsAgainst'] ) : 0.0,
			'streak_length'  => isset( $record['streakLength'] ) ? absint( $record['streakLength'] ) : 0,
			'streak_type'    => isset( $record['streakType'] ) ? sanitize_text_field( $record['streakType'] ) : '',
			'playoff_seed'   => isset( $team['playoffSeed'] ) ? absint( $team['playoffSeed'] ) : 0,
		);
	}

	/**
	 * Get team owner name.
	 *
	 * @param array $team Team data.
	 * @return string Owner name.
	 */
	protected function get_owner_name( $team ) {
		if ( empty( $team['owners'] ) || ! is_array( $team['owners'] ) ) {
			return '';
		}

		// ESPN typically stores owner ID in the owners array.
		// For privacy, we'll return a generic indicator.
		return sprintf(
			/* translators: %d: number of owners */
			_n( '%d owner', '%d owners', count( $team['owners'] ), 'mcp-ai-wpoos' ),
			count( $team['owners'] )
		);
	}
}
