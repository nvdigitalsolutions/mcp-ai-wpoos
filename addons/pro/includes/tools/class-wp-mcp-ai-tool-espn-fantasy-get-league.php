<?php
/**
 * Tool to retrieve ESPN Fantasy Football league information.
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
 * Tool for retrieving ESPN Fantasy Football league information.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Get_League implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_get_league';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Get League', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve ESPN Fantasy Football league information including league name, size, scoring settings, current week, and playoff configuration. Works with both public and private leagues (requires authentication for private leagues).', 'mcp-ai-wpoos' );
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
					'description' => __( 'ESPN Fantasy Football league ID. Found in the league URL (e.g., 387659).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'season'    => array(
					'type'        => 'integer',
					'description' => __( 'Season year (e.g., 2024). Defaults to current year.', 'mcp-ai-wpoos' ),
					'minimum'     => 2000,
					'maximum'     => 2100,
					'default'     => gmdate( 'Y' ),
				),
				'espn_s2'   => array(
					'type'        => 'string',
					'description' => __( 'Optional ESPN S2 cookie for private league access. Can also be configured in settings.', 'mcp-ai-wpoos' ),
				),
				'swid'      => array(
					'type'        => 'string',
					'description' => __( 'Optional SWID cookie for private league access. Can also be configured in settings.', 'mcp-ai-wpoos' ),
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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Require authentication.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to access ESPN Fantasy Football data.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Check capabilities.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to access this data.', 'mcp-ai-wpoos' )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Validate required parameters.
		if ( empty( $arguments['league_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_espn_missing_league_id',
				__( 'League ID is required.', 'mcp-ai-wpoos' )
			);
		}

		$league_id = absint( $arguments['league_id'] );
		$season    = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : absint( gmdate( 'Y' ) );

		// Validate season range.
		if ( $season < 2000 || $season > 2100 ) {
			return new WP_Error(
				'wp_mcp_ai_espn_invalid_season',
				__( 'Season must be between 2000 and 2100.', 'mcp-ai-wpoos' )
			);
		}

		// Create client with optional credentials.
		$credentials = array();
		if ( ! empty( $arguments['espn_s2'] ) ) {
			$credentials['espn_s2'] = sanitize_text_field( $arguments['espn_s2'] );
		}
		if ( ! empty( $arguments['swid'] ) ) {
			$credentials['swid'] = sanitize_text_field( $arguments['swid'] );
		}

		$client = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );

		// Get league data.
		$league_data = $client->get_league( $league_id, $season );

		if ( is_wp_error( $league_data ) ) {
			return $league_data;
		}

		// Format response with useful league information.
		$formatted = $this->format_league_data( $league_data );

		// Create summary message.
		$league_name = isset( $formatted['name'] ) ? $formatted['name'] : __( 'Unknown League', 'mcp-ai-wpoos' );
		/* translators: 1: league name, 2: season year */
		$message = sprintf(
			__( 'Retrieved league information for "%1$s" (%2$d season).', 'mcp-ai-wpoos' ),
			$league_name,
			$season
		);

		$result = array_merge(
			array(
				'message' => $message,
				'summary' => $message,
			),
			$formatted
		);

		/**
		 * Filter the ESPN Fantasy league result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		return apply_filters( 'wp_mcp_ai_espn_fantasy_get_league_result', $result, $arguments, $context );
	}

	/**
	 * Format raw league data into a clean structure.
	 *
	 * @param array $league_data Raw ESPN API league data.
	 * @return array Formatted league data.
	 */
	protected function format_league_data( $league_data ) {
		$settings = isset( $league_data['settings'] ) ? $league_data['settings'] : array();

		return array(
			'league_id'         => isset( $league_data['id'] ) ? absint( $league_data['id'] ) : 0,
			'name'              => isset( $settings['name'] ) ? sanitize_text_field( $settings['name'] ) : '',
			'season'            => isset( $league_data['seasonId'] ) ? absint( $league_data['seasonId'] ) : 0,
			'size'              => isset( $settings['size'] ) ? absint( $settings['size'] ) : 0,
			'current_week'      => isset( $league_data['scoringPeriodId'] ) ? absint( $league_data['scoringPeriodId'] ) : 0,
			'current_matchup'   => isset( $league_data['currentMatchupPeriod'] ) ? absint( $league_data['currentMatchupPeriod'] ) : 0,
			'final_scoring_period' => isset( $league_data['finalScoringPeriod'] ) ? absint( $league_data['finalScoringPeriod'] ) : 0,
			'is_public'         => isset( $settings['isPublic'] ) ? (bool) $settings['isPublic'] : false,
			'scoring_type'      => $this->get_scoring_type( $settings ),
			'roster_settings'   => $this->get_roster_settings( $settings ),
			'playoff_settings'  => $this->get_playoff_settings( $settings ),
			'acquisition_settings' => $this->get_acquisition_settings( $settings ),
		);
	}

	/**
	 * Get scoring type from settings.
	 *
	 * @param array $settings League settings.
	 * @return string Scoring type description.
	 */
	protected function get_scoring_type( $settings ) {
		$scoring_id = isset( $settings['scoringSettings']['scoringType'] ) ? $settings['scoringSettings']['scoringType'] : 'UNKNOWN';

		$types = array(
			'H2H_POINTS' => __( 'Head-to-Head Points', 'mcp-ai-wpoos' ),
			'STANDARD'   => __( 'Standard Scoring', 'mcp-ai-wpoos' ),
			'PPR'        => __( 'Points Per Reception', 'mcp-ai-wpoos' ),
			'HALF_PPR'   => __( 'Half PPR', 'mcp-ai-wpoos' ),
		);

		return isset( $types[ $scoring_id ] ) ? $types[ $scoring_id ] : $scoring_id;
	}

	/**
	 * Get roster settings.
	 *
	 * @param array $settings League settings.
	 * @return array Roster configuration.
	 */
	protected function get_roster_settings( $settings ) {
		$roster = isset( $settings['rosterSettings'] ) ? $settings['rosterSettings'] : array();

		return array(
			'roster_size' => isset( $roster['lineupSlotCounts'] ) ? array_sum( $roster['lineupSlotCounts'] ) : 0,
			'positions'   => isset( $roster['lineupSlotCounts'] ) ? $roster['lineupSlotCounts'] : array(),
		);
	}

	/**
	 * Get playoff settings.
	 *
	 * @param array $settings League settings.
	 * @return array Playoff configuration.
	 */
	protected function get_playoff_settings( $settings ) {
		$schedule = isset( $settings['scheduleSettings'] ) ? $settings['scheduleSettings'] : array();

		return array(
			'playoff_teams'      => isset( $schedule['playoffTeamCount'] ) ? absint( $schedule['playoffTeamCount'] ) : 0,
			'playoff_start_week' => isset( $schedule['playoffSeedingRule'] ) ? absint( $schedule['playoffSeedingRule'] ) : 0,
		);
	}

	/**
	 * Get acquisition settings (waivers, trades, etc.).
	 *
	 * @param array $settings League settings.
	 * @return array Acquisition settings.
	 */
	protected function get_acquisition_settings( $settings ) {
		$acquisition = isset( $settings['acquisitionSettings'] ) ? $settings['acquisitionSettings'] : array();

		return array(
			'waivers_enabled'   => isset( $acquisition['isWaiverOrderContinuous'] ) ? (bool) $acquisition['isWaiverOrderContinuous'] : false,
			'trades_enabled'    => isset( $acquisition['isUsingAcquisitionBudget'] ) ? (bool) $acquisition['isUsingAcquisitionBudget'] : false,
			'acquisition_limit' => isset( $acquisition['acquisitionLimit'] ) ? absint( $acquisition['acquisitionLimit'] ) : 0,
		);
	}
}
