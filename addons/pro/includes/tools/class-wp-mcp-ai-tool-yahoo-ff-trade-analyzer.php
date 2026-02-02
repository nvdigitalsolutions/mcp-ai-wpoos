<?php
/**
 * Tool for analyzing fantasy football trades with visualizations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes fantasy football trades and provides visual comparison using Chart.js.
 */
class WP_MCP_AI_Tool_Yahoo_FF_Trade_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_trade_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - Trade Analyzer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes fantasy football trade proposals by comparing player statistics and projections. Generates visual comparison charts showing fantasy points, trends, and trade value assessment.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'league_key'       => array(
					'type'        => 'string',
					'description' => __( 'Yahoo league key (e.g., "nfl.l.123456"). Required to get league-specific scoring.', 'mcp-ai-wpoos' ),
				),
				'team_a_players'   => array(
					'type'        => 'array',
					'description' => __( 'Array of player keys Team A is offering (giving away).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'minItems'    => 1,
				),
				'team_b_players'   => array(
					'type'        => 'array',
					'description' => __( 'Array of player keys Team B is offering (receiving by Team A).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'minItems'    => 1,
				),
				'weeks_to_analyze' => array(
					'type'        => 'integer',
					'description' => __( 'Number of past weeks to analyze for trends (default: 4).', 'mcp-ai-wpoos' ),
					'default'     => 4,
					'minimum'     => 1,
					'maximum'     => 17,
				),
				'include_chart'    => array(
					'type'        => 'boolean',
					'description' => __( 'Generate visual comparison chart using Chart.js (default: true).', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'chart_type'       => array(
					'type'        => 'string',
					'description' => __( 'Chart type for visualization: "bar" for comparison, "line" for trends.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'bar', 'line' ),
					'default'     => 'bar',
				),
			),
			'required'             => array( 'league_key', 'team_a_players', 'team_b_players' ),
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

		if ( empty( $arguments['team_a_players'] ) || ! is_array( $arguments['team_a_players'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_team_a', __( 'Team A players array is required.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['team_b_players'] ) || ! is_array( $arguments['team_b_players'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_team_b', __( 'Team B players array is required.', 'mcp-ai-wpoos' ) );
		}

		$league_key       = sanitize_text_field( $arguments['league_key'] );
		$team_a_players   = array_map( 'sanitize_text_field', $arguments['team_a_players'] );
		$team_b_players   = array_map( 'sanitize_text_field', $arguments['team_b_players'] );
		$weeks_to_analyze = isset( $arguments['weeks_to_analyze'] ) ? absint( $arguments['weeks_to_analyze'] ) : 4;
		$include_chart    = isset( $arguments['include_chart'] ) ? (bool) $arguments['include_chart'] : true;
		$chart_type       = isset( $arguments['chart_type'] ) ? sanitize_text_field( $arguments['chart_type'] ) : 'bar';

		// Get player stats for both teams.
		$team_a_stats = $this->get_players_stats( $league_key, $team_a_players, $weeks_to_analyze, $user_id );
		if ( is_wp_error( $team_a_stats ) ) {
			return $team_a_stats;
		}

		$team_b_stats = $this->get_players_stats( $league_key, $team_b_players, $weeks_to_analyze, $user_id );
		if ( is_wp_error( $team_b_stats ) ) {
			return $team_b_stats;
		}

		// Calculate trade values.
		$analysis = $this->analyze_trade( $team_a_stats, $team_b_stats );

		$result = array(
			'league_key'       => $league_key,
			'trade_analysis'   => $analysis,
			'team_a_total'     => $analysis['team_a_total_points'],
			'team_b_total'     => $analysis['team_b_total_points'],
			'point_difference' => $analysis['point_difference'],
			'recommendation'   => $analysis['recommendation'],
			'team_a_players'   => $team_a_stats,
			'team_b_players'   => $team_b_stats,
		);

		// Generate chart if requested.
		if ( $include_chart ) {
			$chart_html = $this->generate_trade_comparison_chart(
				$team_a_stats,
				$team_b_stats,
				$analysis,
				$chart_type
			);

			if ( ! is_wp_error( $chart_html ) ) {
				$result['chart_html']        = $chart_html;
				$result['has_visualization'] = true;
			}
		}

		return $result;
	}

	/**
	 * Get player statistics for multiple players.
	 *
	 * @param string $league_key      League key.
	 * @param array  $player_keys     Array of player keys.
	 * @param int    $weeks_to_analyze Number of weeks to analyze.
	 * @param int    $user_id         User ID.
	 * @return array|WP_Error Player stats or error.
	 */
	protected function get_players_stats( $league_key, $player_keys, $weeks_to_analyze, $user_id ) {
		$access_token = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', true );

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_authenticated',
				__( 'You must authenticate with Yahoo Fantasy Sports first.', 'mcp-ai-wpoos' )
			);
		}

		$players_stats = array();

		foreach ( $player_keys as $player_key ) {
			$player_key = sanitize_text_field( $player_key );

			// Get season stats.
			$season_stats = $this->fetch_player_stats( $league_key, $player_key, null, $access_token );

			if ( is_wp_error( $season_stats ) ) {
				return $season_stats;
			}

			$players_stats[] = $season_stats;
		}

		return $players_stats;
	}

	/**
	 * Fetch player statistics from Yahoo API.
	 *
	 * @param string   $league_key   League key.
	 * @param string   $player_key   Player key.
	 * @param int|null $week         Week number or null for season.
	 * @param string   $access_token Access token.
	 * @return array|WP_Error Player stats or error.
	 */
	protected function fetch_player_stats( $league_key, $player_key, $week, $access_token ) {
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
				__( 'Failed to retrieve player stats.', 'mcp-ai-wpoos' )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Yahoo API returned status %d.', 'mcp-ai-wpoos' ),
					(int) $status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_bad_json', __( 'Invalid API response.', 'mcp-ai-wpoos' ) );
		}

		return $this->parse_player_stats( $data );
	}

	/**
	 * Parse player stats from API response.
	 *
	 * @param array $data API response data.
	 * @return array Parsed player stats.
	 */
	protected function parse_player_stats( array $data ) {
		$result = array(
			'player_name'    => '',
			'position'       => '',
			'team'           => '',
			'fantasy_points' => 0,
		);

		if ( ! isset( $data['fantasy_content']['league'][1]['players'] ) ) {
			return $result;
		}

		$players = $data['fantasy_content']['league'][1]['players'];

		foreach ( $players as $player_data ) {
			if ( ! isset( $player_data['player'] ) ) {
				continue;
			}

			$player = $player_data['player'];

			if ( isset( $player[0]['name']['full'] ) ) {
				$result['player_name'] = sanitize_text_field( $player[0]['name']['full'] );
			}

			if ( isset( $player[0]['display_position'] ) ) {
				$result['position'] = sanitize_text_field( $player[0]['display_position'] );
			}

			if ( isset( $player[0]['editorial_team_abbr'] ) ) {
				$result['team'] = sanitize_text_field( $player[0]['editorial_team_abbr'] );
			}

			if ( isset( $player[1]['player_stats']['stats'] ) ) {
				foreach ( $player[1]['player_stats']['stats'] as $stat ) {
					if ( isset( $stat['stat']['stat_id'] ) && '0' === (string) $stat['stat']['stat_id'] ) {
						$result['fantasy_points'] = (float) ( $stat['stat']['value'] ?? 0 );
						break;
					}
				}
			}

			break;
		}

		return $result;
	}

	/**
	 * Analyze trade values.
	 *
	 * @param array $team_a_stats Team A player stats.
	 * @param array $team_b_stats Team B player stats.
	 * @return array Trade analysis.
	 */
	protected function analyze_trade( $team_a_stats, $team_b_stats ) {
		$team_a_total = 0;
		$team_b_total = 0;

		foreach ( $team_a_stats as $player ) {
			$team_a_total += $player['fantasy_points'];
		}

		foreach ( $team_b_stats as $player ) {
			$team_b_total += $player['fantasy_points'];
		}

		$difference = $team_b_total - $team_a_total;
		$percentage = $team_a_total > 0 ? ( $difference / $team_a_total ) * 100 : 0;

		// Determine recommendation.
		if ( abs( $percentage ) < 10 ) {
			$recommendation = __( 'Fair trade - Both sides are relatively equal in value.', 'mcp-ai-wpoos' );
			$verdict        = 'fair';
		} elseif ( $difference > 0 ) {
			$recommendation = sprintf(
				/* translators: %.1f percentage advantage */
				__( 'Favorable for Team A - You would gain approximately %.1f%% more value.', 'mcp-ai-wpoos' ),
				abs( $percentage )
			);
			$verdict = 'favorable';
		} else {
			$recommendation = sprintf(
				/* translators: %.1f percentage disadvantage */
				__( 'Unfavorable for Team A - You would lose approximately %.1f%% value.', 'mcp-ai-wpoos' ),
				abs( $percentage )
			);
			$verdict = 'unfavorable';
		}

		return array(
			'team_a_total_points' => round( $team_a_total, 2 ),
			'team_b_total_points' => round( $team_b_total, 2 ),
			'point_difference'    => round( $difference, 2 ),
			'percentage_diff'     => round( $percentage, 1 ),
			'recommendation'      => $recommendation,
			'verdict'             => $verdict,
		);
	}

	/**
	 * Generate trade comparison chart using Chart.js.
	 *
	 * @param array  $team_a_stats Team A player stats.
	 * @param array  $team_b_stats Team B player stats.
	 * @param array  $analysis     Trade analysis.
	 * @param string $chart_type   Chart type (bar or line).
	 * @return string|WP_Error HTML chart or error.
	 */
	protected function generate_trade_comparison_chart( $team_a_stats, $team_b_stats, $analysis, $chart_type ) {
		// Prepare chart data.
		$labels      = array();
		$team_a_data = array();
		$team_b_data = array();

		// Add Team A players.
		foreach ( $team_a_stats as $player ) {
			$labels[]      = $player['player_name'];
			$team_a_data[] = $player['fantasy_points'];
		}

		// Add Team B players.
		foreach ( $team_b_stats as $player ) {
			$labels[]      = $player['player_name'];
			$team_b_data[] = 0; // Placeholder for Team A side.
		}

		// Add Team B values.
		$team_a_count = count( $team_a_stats );
		for ( $i = 0; $i < $team_a_count; $i++ ) {
			$team_b_data[] = 0; // Placeholder for Team B side.
		}

		foreach ( $team_b_stats as $player ) {
			$team_b_data[] = $player['fantasy_points'];
		}

		// Build Chart.js configuration.
		$chart_config = array(
			'type'    => $chart_type,
			'data'    => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Team A (Giving)', 'mcp-ai-wpoos' ),
						'data'            => $team_a_data,
						'backgroundColor' => 'rgba(255, 99, 132, 0.5)',
						'borderColor'     => 'rgba(255, 99, 132, 1)',
						'borderWidth'     => 2,
					),
					array(
						'label'           => __( 'Team B (Receiving)', 'mcp-ai-wpoos' ),
						'data'            => $team_b_data,
						'backgroundColor' => 'rgba(54, 162, 235, 0.5)',
						'borderColor'     => 'rgba(54, 162, 235, 1)',
						'borderWidth'     => 2,
					),
				),
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'plugins'             => array(
					'title'   => array(
						'display' => true,
						'text'    => __( 'Fantasy Football Trade Analysis', 'mcp-ai-wpoos' ),
						'font'    => array(
							'size' => 18,
						),
					),
					'legend'  => array(
						'display'  => true,
						'position' => 'top',
					),
					'tooltip' => array(
						'enabled' => true,
					),
				),
				'scales'              => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Fantasy Points', 'mcp-ai-wpoos' ),
						),
					),
				),
			),
		);

		// Generate HTML.
		return $this->generate_chart_html( $chart_config, 900, 500 );
	}

	/**
	 * Generate HTML with embedded Chart.js code.
	 *
	 * @param array $config Chart.js configuration.
	 * @param int   $width  Canvas width.
	 * @param int   $height Canvas height.
	 * @return string Complete HTML document.
	 */
	protected function generate_chart_html( array $config, $width, $height ) {
		$chart_id    = 'trade-chart-' . wp_generate_password( 8, false );
		$config_json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$chartjs_url = esc_url( plugins_url( 'assets/js/vendor/chart.min.js', WP_MCP_AI_FILE ) );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML file for chart export.
		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Trade Analysis Chart</title>
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			min-height: 100vh;
			padding: 20px;
		}
		.chart-container {
			max-width: 100%;
			margin: 0 auto;
			background: white;
			padding: 24px;
			border-radius: 12px;
			box-shadow: 0 10px 40px rgba(0,0,0,0.2);
		}
		canvas {
			max-width: 100%;
			height: auto !important;
		}
	</style>
</head>
<body>
	<div class="chart-container">
		<canvas id="<?php echo esc_attr( $chart_id ); ?>" width="<?php echo esc_attr( $width ); ?>" height="<?php echo esc_attr( $height ); ?>"></canvas>
	</div>
	<script src="<?php echo esc_url( $chartjs_url ); ?>"></script>
	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const ctx = document.getElementById(<?php echo wp_json_encode( $chart_id ); ?>).getContext('2d');
			const chartConfig = <?php echo wp_json_encode( json_decode( $config_json, true ) ); ?>;
			new Chart(ctx, chartConfig);
		});
	</script>
</body>
</html>
		<?php
		$html = ob_get_clean();
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		return $html;
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
