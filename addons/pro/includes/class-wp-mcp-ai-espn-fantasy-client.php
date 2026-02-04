<?php
/**
 * ESPN Fantasy Football API client wrapper.
 *
 * Provides methods to interact with ESPN's Fantasy Football v3 API.
 * Supports both public and private leagues with cookie-based authentication.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required classes are loaded.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
}

if ( ! class_exists( 'WP_MCP_AI_HTTP' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http-helper.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
}

if ( ! class_exists( 'WP_MCP_AI_ESPN_Fantasy_Client' ) ) {
	/**
	 * ESPN Fantasy Football API client.
	 *
	 * Handles all communication with ESPN's Fantasy Football v3 API including
	 * authentication, caching, rate limiting, and error handling.
	 *
	 * ESPN API Base URL: https://fantasy.espn.com/apis/v3/games/ffl/seasons/{seasonId}/segments/0/leagues/{leagueId}
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_ESPN_Fantasy_Client {
		/**
		 * ESPN Fantasy Football API base endpoint.
		 *
		 * @var string
		 */
		const API_ENDPOINT = 'https://fantasy.espn.com/apis/v3/games/ffl/seasons';

		/**
		 * Cache duration in seconds (15 minutes).
		 *
		 * @var int
		 */
		const CACHE_DURATION = 900;

		/**
		 * Rate limit: maximum requests per minute.
		 *
		 * @var int
		 */
		const RATE_LIMIT = 20;

		/**
		 * ESPN S2 cookie for private league authentication.
		 *
		 * @var string|null
		 */
		protected $espn_s2 = null;

		/**
		 * SWID cookie for private league authentication.
		 *
		 * @var string|null
		 */
		protected $swid = null;

		/**
		 * Constructor.
		 *
		 * @param array $credentials Optional. Array with 'espn_s2' and 'swid' keys.
		 */
		public function __construct( $credentials = array() ) {
			if ( ! empty( $credentials['espn_s2'] ) ) {
				$this->espn_s2 = sanitize_text_field( $credentials['espn_s2'] );
			}
			if ( ! empty( $credentials['swid'] ) ) {
				$this->swid = sanitize_text_field( $credentials['swid'] );
			}

			// Try to load credentials from settings if not provided.
			if ( empty( $this->espn_s2 ) || empty( $this->swid ) ) {
				$this->load_credentials_from_settings();
			}
		}

		/**
		 * Load authentication credentials from WordPress settings.
		 */
		protected function load_credentials_from_settings() {
			if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				return;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $this->espn_s2 ) && ! empty( $settings['espn_fantasy_espn_s2'] ) ) {
				$this->espn_s2 = $settings['espn_fantasy_espn_s2'];
			}

			if ( empty( $this->swid ) && ! empty( $settings['espn_fantasy_swid'] ) ) {
				$this->swid = $settings['espn_fantasy_swid'];
			}
		}

		/**
		 * Set authentication cookies for private leagues.
		 *
		 * @param string $espn_s2 ESPN S2 cookie value.
		 * @param string $swid    SWID cookie value.
		 */
		public function set_cookies( $espn_s2, $swid ) {
			$this->espn_s2 = sanitize_text_field( $espn_s2 );
			$this->swid    = sanitize_text_field( $swid );
		}

		/**
		 * Check if private league authentication is configured.
		 *
		 * @return bool True if credentials are set.
		 */
		public function has_credentials() {
			return ! empty( $this->espn_s2 ) && ! empty( $this->swid );
		}

		/**
		 * Get league information.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year (e.g., 2024).
		 * @param array $options Optional. Additional options.
		 * @return array|WP_Error League data or error.
		 */
		public function get_league( $league_id, $season_id, $options = array() ) {
			$league_id = absint( $league_id );
			$season_id = absint( $season_id );

			if ( empty( $league_id ) || empty( $season_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID and Season ID are required.', 'mcp-ai-wpoos' )
				);
			}

			$cache_key = "espn_league_{$league_id}_{$season_id}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array( 'view' => 'mSettings' );

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->cache_response( $cache_key, $response );

			return $response;
		}

		/**
		 * Get all teams in a league.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year.
		 * @param array $options Optional. Additional options.
		 * @return array|WP_Error Teams data or error.
		 */
		public function get_teams( $league_id, $season_id, $options = array() ) {
			$league_id = absint( $league_id );
			$season_id = absint( $season_id );

			if ( empty( $league_id ) || empty( $season_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID and Season ID are required.', 'mcp-ai-wpoos' )
				);
			}

			$cache_key = "espn_teams_{$league_id}_{$season_id}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array( 'view' => 'mTeam' );

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$teams = isset( $response['teams'] ) ? $response['teams'] : array();

			$this->cache_response( $cache_key, $teams );

			return $teams;
		}

		/**
		 * Get team roster.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year.
		 * @param int $team_id   Team ID.
		 * @param array $options Optional. Additional options like 'week'.
		 * @return array|WP_Error Roster data or error.
		 */
		public function get_roster( $league_id, $season_id, $team_id, $options = array() ) {
			$league_id = absint( $league_id );
			$season_id = absint( $season_id );
			$team_id   = absint( $team_id );

			if ( empty( $league_id ) || empty( $season_id ) || empty( $team_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID, Season ID, and Team ID are required.', 'mcp-ai-wpoos' )
				);
			}

			$week      = isset( $options['week'] ) ? absint( $options['week'] ) : 0;
			$cache_key = "espn_roster_{$league_id}_{$season_id}_{$team_id}_{$week}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array( 'view' => 'mRoster' );

			if ( $week > 0 ) {
				$params['scoringPeriodId'] = $week;
			}

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract roster for specific team.
			$roster = array();
			if ( isset( $response['teams'] ) && is_array( $response['teams'] ) ) {
				foreach ( $response['teams'] as $team ) {
					if ( isset( $team['id'] ) && absint( $team['id'] ) === $team_id ) {
						$roster = isset( $team['roster'] ) ? $team['roster'] : array();
						break;
					}
				}
			}

			$this->cache_response( $cache_key, $roster );

			return $roster;
		}

		/**
		 * Get league standings.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year.
		 * @param array $options Optional. Additional options.
		 * @return array|WP_Error Standings data or error.
		 */
		public function get_standings( $league_id, $season_id, $options = array() ) {
			$league_id = absint( $league_id );
			$season_id = absint( $season_id );

			if ( empty( $league_id ) || empty( $season_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID and Season ID are required.', 'mcp-ai-wpoos' )
				);
			}

			$cache_key = "espn_standings_{$league_id}_{$season_id}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array( 'view' => 'mStandings' );

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$standings = isset( $response['teams'] ) ? $response['teams'] : array();

			// Sort by standings.
			if ( is_array( $standings ) ) {
				usort(
					$standings,
					function ( $a, $b ) {
						$a_wins = isset( $a['record']['overall']['wins'] ) ? $a['record']['overall']['wins'] : 0;
						$b_wins = isset( $b['record']['overall']['wins'] ) ? $b['record']['overall']['wins'] : 0;

						if ( $a_wins === $b_wins ) {
							$a_points = isset( $a['record']['overall']['pointsFor'] ) ? $a['record']['overall']['pointsFor'] : 0;
							$b_points = isset( $b['record']['overall']['pointsFor'] ) ? $b['record']['overall']['pointsFor'] : 0;
							return $b_points <=> $a_points;
						}

						return $b_wins <=> $a_wins;
					}
				);
			}

			$this->cache_response( $cache_key, $standings );

			return $standings;
		}

		/**
		 * Get matchup information for a specific week.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year.
		 * @param int $week      Week number.
		 * @param array $options Optional. Additional options.
		 * @return array|WP_Error Matchup data or error.
		 */
		public function get_matchup( $league_id, $season_id, $week, $options = array() ) {
			$league_id = absint( $league_id );
			$season_id = absint( $season_id );
			$week      = absint( $week );

			if ( empty( $league_id ) || empty( $season_id ) || empty( $week ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID, Season ID, and Week are required.', 'mcp-ai-wpoos' )
				);
			}

			$cache_key = "espn_matchup_{$league_id}_{$season_id}_{$week}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array(
				'view'            => 'mMatchup',
				'scoringPeriodId' => $week,
			);

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$matchups = isset( $response['schedule'] ) ? $response['schedule'] : array();

			// Filter to current week.
			if ( is_array( $matchups ) ) {
				$matchups = array_filter(
					$matchups,
					function ( $matchup ) use ( $week ) {
						return isset( $matchup['matchupPeriodId'] ) && absint( $matchup['matchupPeriodId'] ) === $week;
					}
				);
			}

			$this->cache_response( $cache_key, $matchups );

			return $matchups;
		}

		/**
		 * Get boxscore for a specific matchup period.
		 *
		 * @param int $league_id         League ID.
		 * @param int $season_id         Season year.
		 * @param int $matchup_period_id Matchup period ID.
		 * @param int $scoring_period_id Optional. Scoring period ID.
		 * @param array $options         Optional. Additional options.
		 * @return array|WP_Error Boxscore data or error.
		 */
		public function get_boxscore( $league_id, $season_id, $matchup_period_id, $scoring_period_id = 0, $options = array() ) {
			$league_id         = absint( $league_id );
			$season_id         = absint( $season_id );
			$matchup_period_id = absint( $matchup_period_id );
			$scoring_period_id = absint( $scoring_period_id );

			if ( empty( $league_id ) || empty( $season_id ) || empty( $matchup_period_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_params',
					__( 'League ID, Season ID, and Matchup Period ID are required.', 'mcp-ai-wpoos' )
				);
			}

			$cache_key = "espn_boxscore_{$league_id}_{$season_id}_{$matchup_period_id}_{$scoring_period_id}";
			$cached    = $this->get_cached_response( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}

			$endpoint = $this->build_endpoint( $league_id, $season_id );
			$params   = array(
				'view'            => 'mBoxscore',
				'matchupPeriodId' => $matchup_period_id,
			);

			if ( $scoring_period_id > 0 ) {
				$params['scoringPeriodId'] = $scoring_period_id;
			}

			$response = $this->make_request( $endpoint, $params, $options );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$boxscores = isset( $response['schedule'] ) ? $response['schedule'] : array();

			$this->cache_response( $cache_key, $boxscores );

			return $boxscores;
		}

		/**
		 * Build ESPN API endpoint URL.
		 *
		 * @param int $league_id League ID.
		 * @param int $season_id Season year.
		 * @return string Endpoint URL.
		 */
		protected function build_endpoint( $league_id, $season_id ) {
			return sprintf(
				'%s/%d/segments/0/leagues/%d',
				self::API_ENDPOINT,
				$season_id,
				$league_id
			);
		}

		/**
		 * Make HTTP request to ESPN API.
		 *
		 * @param string $endpoint Full endpoint URL.
		 * @param array  $params   Query parameters.
		 * @param array  $options  Request options.
		 * @return array|WP_Error Response data or error.
		 */
		protected function make_request( $endpoint, $params = array(), $options = array() ) {
			// Check rate limiting.
			if ( ! $this->check_rate_limit() ) {
				return new WP_Error(
					'wp_mcp_ai_espn_rate_limit',
					__( 'Rate limit exceeded. Please wait before making another request.', 'mcp-ai-wpoos' )
				);
			}

			// Build URL with parameters.
			$url = add_query_arg( $params, $endpoint );

			// Prepare request arguments.
			$args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
			);

			// Add authentication cookies if available.
			if ( $this->has_credentials() ) {
				$args['headers']['Cookie'] = sprintf(
					'espn_s2=%s; SWID=%s',
					$this->espn_s2,
					$this->swid
				);
			}

			// Make request.
			$response = wp_remote_get( $url, $args );

			// Track request for rate limiting.
			$this->track_request();

			// Handle errors.
			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log( 'ESPN Fantasy API request failed: ' . $response->get_error_message() );
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== $status_code ) {
				WP_MCP_AI_Logger::log( sprintf( 'ESPN Fantasy API returned status %d: %s', $status_code, $body ) );

				$error_message = __( 'ESPN API request failed.', 'mcp-ai-wpoos' );

				if ( 401 === $status_code || 403 === $status_code ) {
					$error_message = __( 'Authentication failed. This may be a private league requiring credentials.', 'mcp-ai-wpoos' );
				} elseif ( 404 === $status_code ) {
					$error_message = __( 'League not found. Please check your league ID and season.', 'mcp-ai-wpoos' );
				} elseif ( 429 === $status_code ) {
					$error_message = __( 'Rate limit exceeded by ESPN. Please try again later.', 'mcp-ai-wpoos' );
				}

				return new WP_Error(
					'wp_mcp_ai_espn_api_error',
					$error_message,
					array( 'status' => $status_code )
				);
			}

			// Decode JSON response.
			$data = json_decode( $body, true );

			if ( null === $data ) {
				WP_MCP_AI_Logger::log( 'ESPN Fantasy API returned invalid JSON: ' . $body );
				return new WP_Error(
					'wp_mcp_ai_espn_invalid_json',
					__( 'Invalid JSON response from ESPN API.', 'mcp-ai-wpoos' )
				);
			}

			return $data;
		}

		/**
		 * Check if rate limit allows a new request.
		 *
		 * @return bool True if request is allowed.
		 */
		protected function check_rate_limit() {
			$rate_key  = 'espn_fantasy_rate_limit';
			$requests  = get_transient( $rate_key );
			$max_limit = apply_filters( 'wp_mcp_ai_espn_rate_limit', self::RATE_LIMIT );

			if ( false === $requests ) {
				return true;
			}

			return absint( $requests ) < $max_limit;
		}

		/**
		 * Track a request for rate limiting.
		 */
		protected function track_request() {
			$rate_key = 'espn_fantasy_rate_limit';
			$requests = get_transient( $rate_key );

			if ( false === $requests ) {
				$requests = 0;
			}

			$requests++;
			set_transient( $rate_key, $requests, MINUTE_IN_SECONDS );
		}

		/**
		 * Get cached response.
		 *
		 * @param string $cache_key Cache key.
		 * @return mixed|false Cached data or false if not found.
		 */
		protected function get_cached_response( $cache_key ) {
			if ( ! $this->is_cache_enabled() ) {
				return false;
			}

			$cache_key = $this->get_cache_key( $cache_key );
			return get_transient( $cache_key );
		}

		/**
		 * Cache a response.
		 *
		 * @param string $cache_key Cache key.
		 * @param mixed  $data      Data to cache.
		 */
		protected function cache_response( $cache_key, $data ) {
			if ( ! $this->is_cache_enabled() ) {
				return;
			}

			$cache_key = $this->get_cache_key( $cache_key );
			$duration  = $this->get_cache_duration();

			set_transient( $cache_key, $data, $duration );
		}

		/**
		 * Get full cache key with prefix.
		 *
		 * @param string $key Base cache key.
		 * @return string Full cache key.
		 */
		protected function get_cache_key( $key ) {
			return 'wp_mcp_ai_' . sanitize_key( $key );
		}

		/**
		 * Check if caching is enabled.
		 *
		 * @return bool True if caching is enabled.
		 */
		protected function is_cache_enabled() {
			return apply_filters( 'wp_mcp_ai_espn_cache_enabled', true );
		}

		/**
		 * Get cache duration.
		 *
		 * @return int Cache duration in seconds.
		 */
		protected function get_cache_duration() {
			return apply_filters( 'wp_mcp_ai_espn_cache_duration', self::CACHE_DURATION );
		}

		/**
		 * Clear all ESPN Fantasy caches.
		 */
		public function clear_cache() {
			global $wpdb;

			// Delete all transients matching our pattern.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options}
					WHERE option_name LIKE %s
					OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_wp_mcp_ai_espn_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_wp_mcp_ai_espn_' ) . '%'
				)
			);

			// Also clear rate limit.
			delete_transient( 'espn_fantasy_rate_limit' );
		}
	}
}
