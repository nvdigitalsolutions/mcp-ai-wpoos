<?php
/**
 * Tool to sync ESPN Fantasy Football league data to WordPress.
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
 * Tool for syncing ESPN Fantasy Football league data to WordPress ff_team CPT.
 */
class WP_MCP_AI_Tool_ESPN_Fantasy_Sync_League implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'espn_fantasy_sync_league';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ESPN Fantasy Sync League', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import and sync ESPN Fantasy Football league data to WordPress. Creates or updates ff_team posts for each team with current standings, rosters, and statistics. Requires Fantasy Football Toolkit to be enabled.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'league_id'    => array(
					'type'        => 'integer',
					'description' => __( 'ESPN Fantasy Football league ID.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'season'       => array(
					'type'        => 'integer',
					'description' => __( 'Season year. Defaults to current year.', 'mcp-ai-wpoos' ),
					'minimum'     => 2000,
					'maximum'     => 2100,
					'default'     => gmdate( 'Y' ),
				),
				'sync_rosters' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to sync full roster data. Default: true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to update existing team posts. Default: true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'espn_s2'      => array(
					'type'        => 'string',
					'description' => __( 'Optional ESPN S2 cookie for private leagues.', 'mcp-ai-wpoos' ),
				),
				'swid'         => array(
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
			'write',
			'state-changing',
			'rate-limited',
			'pii-data',
			'async',
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
				__( 'You must be authenticated to sync league data.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Require edit_posts capability for creating/updating posts.
		if ( $user_id && ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create or update team posts.', 'mcp-ai-wpoos' )
			);
		}

		// Check if Fantasy Football Toolkit is enabled.
		if ( ! $this->is_fantasy_football_enabled() ) {
			return new WP_Error(
				'wp_mcp_ai_ff_disabled',
				__( 'Fantasy Football Toolkit is not enabled. Please enable it in the plugin settings.', 'mcp-ai-wpoos' )
			);
		}

		// Check if ff_team post type exists.
		if ( ! post_type_exists( 'ff_team' ) ) {
			return new WP_Error(
				'wp_mcp_ai_ff_cpt_missing',
				__( 'Fantasy Team post type is not registered. Please ensure the Fantasy Football Toolkit is properly initialized.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['league_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_espn_missing_league_id',
				__( 'League ID is required.', 'mcp-ai-wpoos' )
			);
		}

		$league_id       = absint( $arguments['league_id'] );
		$season          = isset( $arguments['season'] ) ? absint( $arguments['season'] ) : absint( gmdate( 'Y' ) );
		$sync_rosters    = isset( $arguments['sync_rosters'] ) ? (bool) $arguments['sync_rosters'] : true;
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : true;

		$credentials = array();
		if ( ! empty( $arguments['espn_s2'] ) ) {
			$credentials['espn_s2'] = sanitize_text_field( $arguments['espn_s2'] );
		}
		if ( ! empty( $arguments['swid'] ) ) {
			$credentials['swid'] = sanitize_text_field( $arguments['swid'] );
		}

		$client = new WP_MCP_AI_ESPN_Fantasy_Client( $credentials );

		// Get league information.
		$league_data = $client->get_league( $league_id, $season );
		if ( is_wp_error( $league_data ) ) {
			return $league_data;
		}

		$league_name = isset( $league_data['settings']['name'] ) ? $league_data['settings']['name'] : "League {$league_id}";

		// Get teams.
		$teams = $client->get_teams( $league_id, $season );
		if ( is_wp_error( $teams ) ) {
			return $teams;
		}

		// Ensure teams is an array before iterating.
		if ( ! is_array( $teams ) ) {
			$teams = array();
		}

		$created_count = 0;
		$updated_count = 0;
		$skipped_count = 0;
		$errors        = array();

		foreach ( $teams as $team ) {
			$team_id = isset( $team['id'] ) ? absint( $team['id'] ) : 0;
			if ( ! $team_id ) {
				continue;
			}

			// Check if team already exists.
			$existing_post_id = $this->find_existing_team( $league_id, $team_id, $season );

			if ( $existing_post_id && ! $update_existing ) {
				$skipped_count++;
				continue;
			}

			// Get roster if requested.
			$roster_data = array();
			if ( $sync_rosters ) {
				$roster = $client->get_roster( $league_id, $season, $team_id );
				if ( ! is_wp_error( $roster ) ) {
					$roster_data = $roster;
				}
			}

			// Create or update team post.
			$result = $this->sync_team_post( $team, $league_name, $league_id, $season, $roster_data, $existing_post_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: team ID, 2: error message */
					__( 'Team %1$d: %2$s', 'mcp-ai-wpoos' ),
					$team_id,
					$result->get_error_message()
				);
			} elseif ( $existing_post_id ) {
				$updated_count++;
			} else {
				$created_count++;
			}
		}

		// Build response message.
		$message_parts = array();

		if ( $created_count > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of teams created */
				_n( 'Created %d team', 'Created %d teams', $created_count, 'mcp-ai-wpoos' ),
				$created_count
			);
		}

		if ( $updated_count > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of teams updated */
				_n( 'Updated %d team', 'Updated %d teams', $updated_count, 'mcp-ai-wpoos' ),
				$updated_count
			);
		}

		if ( $skipped_count > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of teams skipped */
				_n( 'Skipped %d team', 'Skipped %d teams', $skipped_count, 'mcp-ai-wpoos' ),
				$skipped_count
			);
		}

		$message = ! empty( $message_parts )
			? implode( ', ', $message_parts )
			: __( 'No teams synced.', 'mcp-ai-wpoos' );

		/* translators: %s: league name */
		$summary = sprintf( __( 'Synced league "%s".', 'mcp-ai-wpoos' ), $league_name );

		$result = array(
			'message'        => $summary . ' ' . $message,
			'summary'        => $summary,
			'league_name'    => $league_name,
			'league_id'      => $league_id,
			'season'         => $season,
			'teams_created'  => $created_count,
			'teams_updated'  => $updated_count,
			'teams_skipped'  => $skipped_count,
			'total_teams'    => count( $teams ),
		);

		if ( ! empty( $errors ) ) {
			$result['errors'] = $errors;
		}

		return $result;
	}

	/**
	 * Check if Fantasy Football Toolkit is enabled.
	 *
	 * @return bool True if enabled.
	 */
	protected function is_fantasy_football_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_fantasy_football'] );
	}

	/**
	 * Find existing team post.
	 *
	 * @param int $league_id League ID.
	 * @param int $team_id   Team ID.
	 * @param int $season    Season year.
	 * @return int|false Post ID if found, false otherwise.
	 */
	protected function find_existing_team( $league_id, $team_id, $season ) {
		$args = array(
			'post_type'      => 'ff_team',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_ff_provider',
					'value' => 'espn',
				),
				array(
					'key'   => '_ff_espn_league_id',
					'value' => $league_id,
				),
				array(
					'key'   => '_ff_espn_team_id',
					'value' => $team_id,
				),
				array(
					'key'   => '_ff_season',
					'value' => $season,
				),
			),
		);

		$posts = get_posts( $args );

		return ! empty( $posts ) ? $posts[0] : false;
	}

	/**
	 * Create or update team post.
	 *
	 * @param array  $team            Team data from ESPN.
	 * @param string $league_name     League name.
	 * @param int    $league_id       League ID.
	 * @param int    $season          Season year.
	 * @param array  $roster_data     Roster data.
	 * @param int    $existing_post_id Existing post ID or 0.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	protected function sync_team_post( $team, $league_name, $league_id, $season, $roster_data, $existing_post_id = 0 ) {
		$team_id   = isset( $team['id'] ) ? absint( $team['id'] ) : 0;
		$team_name = isset( $team['name'] ) ? sanitize_text_field( $team['name'] ) : "Team {$team_id}";
		$abbrev    = isset( $team['abbrev'] ) ? sanitize_text_field( $team['abbrev'] ) : '';

		$record = isset( $team['record']['overall'] ) ? $team['record']['overall'] : array();

		$post_data = array(
			'post_type'   => 'ff_team',
			'post_title'  => $team_name,
			'post_status' => 'publish',
			'post_content' => sprintf(
				/* translators: 1: team name, 2: league name */
				__( '%1$s from %2$s league (ESPN Fantasy Football)', 'mcp-ai-wpoos' ),
				$team_name,
				$league_name
			),
		);

		if ( $existing_post_id ) {
			$post_data['ID'] = $existing_post_id;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Update meta fields.
		update_post_meta( $post_id, '_ff_provider', 'espn' );
		update_post_meta( $post_id, '_ff_espn_league_id', $league_id );
		update_post_meta( $post_id, '_ff_espn_team_id', $team_id );
		update_post_meta( $post_id, '_ff_league_name', $league_name );
		update_post_meta( $post_id, '_ff_team_name', $team_name );
		update_post_meta( $post_id, '_ff_season', $season );
		update_post_meta( $post_id, '_ff_wins', isset( $record['wins'] ) ? absint( $record['wins'] ) : 0 );
		update_post_meta( $post_id, '_ff_losses', isset( $record['losses'] ) ? absint( $record['losses'] ) : 0 );
		update_post_meta( $post_id, '_ff_ties', isset( $record['ties'] ) ? absint( $record['ties'] ) : 0 );
		update_post_meta( $post_id, '_ff_points_for', isset( $record['pointsFor'] ) ? floatval( $record['pointsFor'] ) : 0 );
		update_post_meta( $post_id, '_ff_points_against', isset( $record['pointsAgainst'] ) ? floatval( $record['pointsAgainst'] ) : 0 );
		update_post_meta( $post_id, '_ff_rank', isset( $team['playoffSeed'] ) ? absint( $team['playoffSeed'] ) : 0 );

		// Logo and color.
		if ( isset( $team['logo'] ) ) {
			update_post_meta( $post_id, '_ff_logo_url', esc_url_raw( $team['logo'] ) );
		}

		// Store roster data as JSON.
		if ( ! empty( $roster_data ) ) {
			update_post_meta( $post_id, '_ff_roster_data', wp_json_encode( $roster_data ) );
		}

		// Update last sync timestamp.
		update_post_meta( $post_id, '_ff_last_sync', current_time( 'mysql' ) );

		return $post_id;
	}
}
