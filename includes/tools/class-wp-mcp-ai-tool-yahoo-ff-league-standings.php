<?php
/**
 * Tool for visualizing Yahoo Fantasy Football league standings.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves and visualizes league standings with Chart.js.
 */
class WP_MCP_AI_Tool_Yahoo_FF_League_Standings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'yahoo_ff_league_standings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Yahoo Fantasy Football - League Standings', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves league standings from Yahoo Fantasy Football and generates interactive visualizations showing team rankings, points scored, and win-loss records.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'league_key'    => array(
					'type'        => 'string',
					'description' => __( 'Yahoo league key (e.g., "nfl.l.123456"). Required to identify the league.', 'mcp-ai-wpoos' ),
				),
				'include_chart' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate visual standings chart using Chart.js (default: true).', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'chart_type'    => array(
					'type'        => 'string',
					'description' => __( 'Chart type: "bar" for points comparison, "radar" for multi-metric analysis.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'bar', 'radar' ),
					'default'     => 'bar',
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

		if ( empty( $arguments['league_key'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_league_key', __( 'League key is required.', 'mcp-ai-wpoos' ) );
		}

		$league_key    = sanitize_text_field( $arguments['league_key'] );
		$include_chart = isset( $arguments['include_chart'] ) ? (bool) $arguments['include_chart'] : true;
		$chart_type    = isset( $arguments['chart_type'] ) ? sanitize_text_field( $arguments['chart_type'] ) : 'bar';

		// Get access token.
		$access_token = get_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', true );

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_authenticated',
				__( 'You must authenticate with Yahoo Fantasy Sports first.', 'mcp-ai-wpoos' )
			);
		}

		// Fetch standings from Yahoo API.
		$api_url = sprintf(
			'https://fantasysports.yahooapis.com/fantasy/v2/league/%s/standings',
			rawurlencode( $league_key )
		);

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
				__( 'Failed to retrieve league standings.', 'mcp-ai-wpoos' ),
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
			return new WP_Error( 'wp_mcp_ai_bad_json', __( 'Invalid API response.', 'mcp-ai-wpoos' ) );
		}

		// Parse standings data.
		$standings = $this->parse_standings( $data );

		$result = array(
			'league_key'   => $league_key,
			'standings'    => $standings,
			'total_teams'  => count( $standings ),
		);

		// Generate chart if requested.
		if ( $include_chart && ! empty( $standings ) ) {
			$chart_html = $this->generate_standings_chart( $standings, $chart_type, $league_key );

			if ( ! is_wp_error( $chart_html ) ) {
				$result['chart_html'] = $chart_html;
				$result['has_visualization'] = true;
			}
		}

		return $result;
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
	 * Parse standings from API response.
	 *
	 * @param array $data API response data.
	 * @return array Parsed standings.
	 */
	protected function parse_standings( array $data ) {
		$standings = array();

		if ( ! isset( $data['fantasy_content']['league'][1]['standings'][0]['teams'] ) ) {
			return $standings;
		}

		$teams = $data['fantasy_content']['league'][1]['standings'][0]['teams'];

		if ( ! is_array( $teams ) ) {
			return $standings;
		}

		foreach ( $teams as $team_data ) {
			if ( ! isset( $team_data['team'] ) || ! is_array( $team_data['team'] ) ) {
				continue;
			}

			$team = $team_data['team'];

			if ( ! isset( $team[0] ) || ! is_array( $team[0] ) ) {
				continue;
			}

			$team_info = $team[0];
			$standings_info = array();

			// Parse team standings.
			if ( isset( $team[2]['team_standings'] ) && is_array( $team[2]['team_standings'] ) ) {
				$standings_info = $team[2]['team_standings'];
			}

			$standings[] = array(
				'team_key'       => isset( $team_info['team_key'] ) ? sanitize_text_field( $team_info['team_key'] ) : '',
				'team_name'      => isset( $team_info['name'] ) ? sanitize_text_field( $team_info['name'] ) : '',
				'rank'           => isset( $standings_info['rank'] ) ? absint( $standings_info['rank'] ) : 0,
				'wins'           => isset( $standings_info['outcome_totals']['wins'] ) ? absint( $standings_info['outcome_totals']['wins'] ) : 0,
				'losses'         => isset( $standings_info['outcome_totals']['losses'] ) ? absint( $standings_info['outcome_totals']['losses'] ) : 0,
				'ties'           => isset( $standings_info['outcome_totals']['ties'] ) ? absint( $standings_info['outcome_totals']['ties'] ) : 0,
				'points_for'     => isset( $standings_info['points_for'] ) ? (float) $standings_info['points_for'] : 0,
				'points_against' => isset( $standings_info['points_against'] ) ? (float) $standings_info['points_against'] : 0,
			);
		}

		// Sort by rank.
		usort(
			$standings,
			function ( $a, $b ) {
				return $a['rank'] - $b['rank'];
			}
		);

		return $standings;
	}

	/**
	 * Generate standings chart using Chart.js.
	 *
	 * @param array  $standings  Standings data.
	 * @param string $chart_type Chart type.
	 * @param string $league_key League key.
	 * @return string|WP_Error HTML chart or error.
	 */
	protected function generate_standings_chart( $standings, $chart_type, $league_key ) {
		if ( 'radar' === $chart_type ) {
			return $this->generate_radar_chart( $standings );
		}

		return $this->generate_bar_chart( $standings );
	}

	/**
	 * Generate bar chart for standings.
	 *
	 * @param array $standings Standings data.
	 * @return string HTML chart.
	 */
	protected function generate_bar_chart( $standings ) {
		$labels       = array();
		$points_for   = array();
		$points_against = array();
		$wins         = array();

		foreach ( $standings as $team ) {
			$labels[]         = $team['team_name'];
			$points_for[]     = $team['points_for'];
			$points_against[] = $team['points_against'];
			$wins[]           = $team['wins'];
		}

		$chart_config = array(
			'type' => 'bar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Points For', 'mcp-ai-wpoos' ),
						'data'            => $points_for,
						'backgroundColor' => 'rgba(75, 192, 192, 0.5)',
						'borderColor'     => 'rgba(75, 192, 192, 1)',
						'borderWidth'     => 2,
					),
					array(
						'label'           => __( 'Points Against', 'mcp-ai-wpoos' ),
						'data'            => $points_against,
						'backgroundColor' => 'rgba(255, 99, 132, 0.5)',
						'borderColor'     => 'rgba(255, 99, 132, 1)',
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
						'text'    => __( 'League Standings - Points Comparison', 'mcp-ai-wpoos' ),
						'font'    => array(
							'size' => 18,
						),
					),
					'legend'  => array(
						'display'  => true,
						'position' => 'top',
					),
				),
				'scales'              => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Points', 'mcp-ai-wpoos' ),
						),
					),
				),
			),
		);

		return $this->generate_chart_html( $chart_config, 1000, 600 );
	}

	/**
	 * Generate radar chart for multi-metric analysis.
	 *
	 * @param array $standings Standings data.
	 * @return string HTML chart.
	 */
	protected function generate_radar_chart( $standings ) {
		// For radar chart, show top 5 teams.
		$top_teams = array_slice( $standings, 0, 5 );

		$labels   = array( __( 'Wins', 'mcp-ai-wpoos' ), __( 'Points For', 'mcp-ai-wpoos' ), __( 'Points Against', 'mcp-ai-wpoos' ) );
		$datasets = array();
		$colors   = array(
			'rgba(255, 99, 132, 0.5)',
			'rgba(54, 162, 235, 0.5)',
			'rgba(255, 206, 86, 0.5)',
			'rgba(75, 192, 192, 0.5)',
			'rgba(153, 102, 255, 0.5)',
		);

		foreach ( $top_teams as $index => $team ) {
			$datasets[] = array(
				'label'           => $team['team_name'],
				'data'            => array(
					$team['wins'],
					$team['points_for'] / 100, // Normalize for radar.
					$team['points_against'] / 100, // Normalize for radar.
				),
				'backgroundColor' => $colors[ $index % count( $colors ) ],
				'borderColor'     => str_replace( '0.5', '1', $colors[ $index % count( $colors ) ] ),
				'borderWidth'     => 2,
			);
		}

		$chart_config = array(
			'type' => 'radar',
			'data' => array(
				'labels'   => $labels,
				'datasets' => $datasets,
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'plugins'             => array(
					'title'   => array(
						'display' => true,
						'text'    => __( 'League Standings - Top 5 Teams Analysis', 'mcp-ai-wpoos' ),
						'font'    => array(
							'size' => 18,
						),
					),
					'legend'  => array(
						'display'  => true,
						'position' => 'top',
					),
				),
				'scales'              => array(
					'r' => array(
						'beginAtZero' => true,
					),
				),
			),
		);

		return $this->generate_chart_html( $chart_config, 900, 600 );
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
		$chart_id    = 'standings-chart-' . wp_generate_password( 8, false );
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
	<title>League Standings Chart</title>
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
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
