<?php
/**
 * Fantasy Football Team custom post type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Fantasy Team custom post type for storing fantasy football team data.
 */
class WP_MCP_AI_Fantasy_Team_CPT {
	const POST_TYPE = 'ff_team';

	// Meta keys.
	const META_LEAGUE_KEY     = '_ff_league_key';
	const META_TEAM_KEY       = '_ff_team_key';
	const META_TEAM_NAME      = '_ff_team_name';
	const META_OWNER_NAME     = '_ff_owner_name';
	const META_LEAGUE_NAME    = '_ff_league_name';
	const META_SEASON         = '_ff_season';
	const META_WINS           = '_ff_wins';
	const META_LOSSES         = '_ff_losses';
	const META_TIES           = '_ff_ties';
	const META_POINTS_FOR     = '_ff_points_for';
	const META_POINTS_AGAINST = '_ff_points_against';
	const META_RANK           = '_ff_rank';
	const META_LOGO_URL       = '_ff_logo_url';
	const META_TEAM_COLOR     = '_ff_team_color';
	const META_ROSTER_DATA    = '_ff_roster_data';
	const META_LAST_SYNC      = '_ff_last_sync';

	// Provider-specific meta keys.
	const META_PROVIDER         = '_ff_provider'; // 'yahoo' or 'espn'.
	const META_ESPN_LEAGUE_ID   = '_ff_espn_league_id';
	const META_ESPN_TEAM_ID     = '_ff_espn_team_id';
	const META_YAHOO_LEAGUE_ID  = '_ff_yahoo_league_id';
	const META_YAHOO_TEAM_ID    = '_ff_yahoo_team_id';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		// When Pro addon is active (WP_MCP_AI_PRO_VERSION defined), features should work even in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			// Still show notice if accessing FF pages.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if fantasy football system is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_fantasy_football'] ) ) {
			// Show notice if trying to access FF pages when disabled.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_post' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'customize_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column_content' ), 10, 2 );
	}

	/**
	 * Show admin notice when fantasy football system is disabled but user tries to access FF pages.
	 */
	public static function show_disabled_notice() {
		// Only show on FF-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on a fantasy team post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_ff_page    = ( $post_type === self::POST_TYPE );
		if ( ! $is_ff_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Fantasy Football Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Fantasy Football toolkit is a Pro feature. Please activate the Pro add-on to use this feature.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if FF is disabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_fantasy_football'] ) ) {
			?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'Fantasy Football Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: Settings page URL */
						esc_html__( 'The Fantasy Football toolkit is currently disabled. Enable it in the %s.', 'mcp-ai-wpoos-pro' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) ) . '">' . esc_html__( 'settings page', 'mcp-ai-wpoos-pro' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Register the Fantasy Team post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'           => _x( 'Fantasy Teams', 'Post Type General Name', 'mcp-ai-wpoos-pro' ),
			'singular_name'  => _x( 'Fantasy Team', 'Post Type Singular Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'      => __( 'Fantasy Teams', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar' => __( 'Fantasy Team', 'mcp-ai-wpoos-pro' ),
			'all_items'      => __( 'All Teams', 'mcp-ai-wpoos-pro' ),
			'add_new_item'   => __( 'Add New Team', 'mcp-ai-wpoos-pro' ),
			'add_new'        => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'new_item'       => __( 'New Team', 'mcp-ai-wpoos-pro' ),
			'edit_item'      => __( 'Edit Team', 'mcp-ai-wpoos-pro' ),
			'update_item'    => __( 'Update Team', 'mcp-ai-wpoos-pro' ),
			'view_item'      => __( 'View Team', 'mcp-ai-wpoos-pro' ),
			'search_items'   => __( 'Search Teams', 'mcp-ai-wpoos-pro' ),
			'not_found'      => __( 'Not found', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'label'             => __( 'Fantasy Team', 'mcp-ai-wpoos-pro' ),
			'description'       => __( 'Fantasy Football Teams', 'mcp-ai-wpoos-pro' ),
			'labels'            => $labels,
			'supports'          => array( 'title', 'editor', 'thumbnail' ),
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'menu_icon'         => 'dashicons-awards',
			'show_in_admin_bar' => true,
			'can_export'        => true,
			'capability_type'   => 'post',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta fields.
	 */
	public static function register_meta() {
		$meta_keys = array(
			self::META_LEAGUE_KEY,
			self::META_TEAM_KEY,
			self::META_TEAM_NAME,
			self::META_OWNER_NAME,
			self::META_LEAGUE_NAME,
			self::META_SEASON,
			self::META_LOGO_URL,
			self::META_TEAM_COLOR,
			self::META_LAST_SYNC,
			self::META_PROVIDER,
		);

		foreach ( $meta_keys as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// Numeric meta.
		$numeric_keys = array(
			self::META_WINS,
			self::META_LOSSES,
			self::META_TIES,
			self::META_RANK,
			self::META_ESPN_LEAGUE_ID,
			self::META_ESPN_TEAM_ID,
			self::META_YAHOO_LEAGUE_ID,
			self::META_YAHOO_TEAM_ID,
		);

		foreach ( $numeric_keys as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'   => 'integer',
					'single' => true,
				)
			);
		}

		// Float meta.
		$float_keys = array(
			self::META_POINTS_FOR,
			self::META_POINTS_AGAINST,
		);

		foreach ( $float_keys as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'   => 'number',
					'single' => true,
				)
			);
		}
	}

	/**
	 * Register meta boxes.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'ff_team_info',
			__( 'Team Information', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_team_info_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render team information meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_team_info_meta_box( $post ) {
		wp_nonce_field( 'ff_team_meta_box', 'ff_team_meta_box_nonce' );

		$provider       = get_post_meta( $post->ID, self::META_PROVIDER, true );
		$league_name    = get_post_meta( $post->ID, self::META_LEAGUE_NAME, true );
		$team_name      = get_post_meta( $post->ID, self::META_TEAM_NAME, true );
		$season         = get_post_meta( $post->ID, self::META_SEASON, true );
		$wins           = get_post_meta( $post->ID, self::META_WINS, true );
		$losses         = get_post_meta( $post->ID, self::META_LOSSES, true );
		$ties           = get_post_meta( $post->ID, self::META_TIES, true );
		$points_for     = get_post_meta( $post->ID, self::META_POINTS_FOR, true );
		$points_against = get_post_meta( $post->ID, self::META_POINTS_AGAINST, true );
		$rank           = get_post_meta( $post->ID, self::META_RANK, true );
		$last_sync      = get_post_meta( $post->ID, self::META_LAST_SYNC, true );

		// Provider-specific IDs.
		$espn_league_id = get_post_meta( $post->ID, self::META_ESPN_LEAGUE_ID, true );
		$espn_team_id   = get_post_meta( $post->ID, self::META_ESPN_TEAM_ID, true );
		$yahoo_league_id = get_post_meta( $post->ID, self::META_YAHOO_LEAGUE_ID, true );
		$yahoo_team_id   = get_post_meta( $post->ID, self::META_YAHOO_TEAM_ID, true );

		$provider_label = 'espn' === $provider ? 'ESPN' : ( 'yahoo' === $provider ? 'Yahoo' : __( 'Unknown', 'mcp-ai-wpoos-pro' ) );
		?>
<div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-left: 4px solid #0073aa;">
	<h3 style="margin-top: 0;"><?php esc_html_e( 'Team Overview', 'mcp-ai-wpoos-pro' ); ?></h3>
	<p><strong><?php esc_html_e( 'Provider:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $provider_label ); ?></p>
	<?php if ( $league_name ) : ?>
	<p><strong><?php esc_html_e( 'League:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $league_name ); ?></p>
	<?php endif; ?>
	<?php if ( $season ) : ?>
	<p><strong><?php esc_html_e( 'Season:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $season ); ?></p>
	<?php endif; ?>
	<?php if ( $last_sync ) : ?>
	<p><strong><?php esc_html_e( 'Last Sync:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $last_sync ); ?></p>
	<?php endif; ?>
</div>

<table class="form-table">
	<?php if ( 'espn' === $provider ) : ?>
	<tr>
		<th><label><?php esc_html_e( 'ESPN League ID', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><code><?php echo esc_html( $espn_league_id ); ?></code></td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'ESPN Team ID', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><code><?php echo esc_html( $espn_team_id ); ?></code></td>
	</tr>
	<?php elseif ( 'yahoo' === $provider ) : ?>
	<tr>
		<th><label><?php esc_html_e( 'Yahoo League ID', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><code><?php echo esc_html( $yahoo_league_id ); ?></code></td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Yahoo Team ID', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><code><?php echo esc_html( $yahoo_team_id ); ?></code></td>
	</tr>
	<?php endif; ?>
	<tr>
		<th><label><?php esc_html_e( 'Record', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td>
			<?php
			printf(
				/* translators: 1: wins, 2: losses, 3: ties */
				esc_html__( '%1$d-%2$d-%3$d', 'mcp-ai-wpoos-pro' ),
				absint( $wins ),
				absint( $losses ),
				absint( $ties )
			);
			?>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Points For', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><?php echo esc_html( number_format_i18n( floatval( $points_for ), 2 ) ); ?></td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Points Against', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><?php echo esc_html( number_format_i18n( floatval( $points_against ), 2 ) ); ?></td>
	</tr>
	<?php if ( $rank ) : ?>
	<tr>
		<th><label><?php esc_html_e( 'Rank', 'mcp-ai-wpoos-pro' ); ?></label></th>
		<td><?php echo esc_html( $rank ); ?></td>
	</tr>
	<?php endif; ?>
</table>

<div style="margin-top: 20px;">
	<h4><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h4>
	<?php if ( 'espn' === $provider && $espn_league_id && $espn_team_id && $season ) : ?>
		<p>
			<a href="https://fantasy.espn.com/football/team?leagueId=<?php echo esc_attr( $espn_league_id ); ?>&teamId=<?php echo esc_attr( $espn_team_id ); ?>&seasonId=<?php echo esc_attr( $season ); ?>" target="_blank" class="button">
				<?php esc_html_e( 'View on ESPN', 'mcp-ai-wpoos-pro' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Save post meta data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (unused but required by WordPress hook).
	 */
	public static function save_post( $post_id, $post ) {
		// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Required by WordPress hook signature.
		if ( ! isset( $_POST['ff_team_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ff_team_meta_box_nonce'] ) ), 'ff_team_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ff_league_key'] ) ) {
			update_post_meta( $post_id, self::META_LEAGUE_KEY, sanitize_text_field( wp_unslash( $_POST['ff_league_key'] ) ) );
		}

		if ( isset( $_POST['ff_team_key'] ) ) {
			update_post_meta( $post_id, self::META_TEAM_KEY, sanitize_text_field( wp_unslash( $_POST['ff_team_key'] ) ) );
		}
	}

	/**
	 * Customize admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function customize_columns( $columns ) {
		$new_columns = array(
			'cb'          => $columns['cb'],
			'title'       => $columns['title'],
			'provider'    => __( 'Provider', 'mcp-ai-wpoos-pro' ),
			'league_name' => __( 'League', 'mcp-ai-wpoos-pro' ),
			'record'      => __( 'Record', 'mcp-ai-wpoos-pro' ),
			'points'      => __( 'Points', 'mcp-ai-wpoos-pro' ),
			'season'      => __( 'Season', 'mcp-ai-wpoos-pro' ),
			'last_sync'   => __( 'Last Sync', 'mcp-ai-wpoos-pro' ),
			'date'        => $columns['date'],
		);

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'provider':
				$provider = get_post_meta( $post_id, self::META_PROVIDER, true );
				if ( 'espn' === $provider ) {
					echo '<span class="dashicons dashicons-chart-line" title="ESPN" style="color: #e41e1e;"></span> ESPN';
				} elseif ( 'yahoo' === $provider ) {
					echo '<span class="dashicons dashicons-star-filled" title="Yahoo" style="color: #720e9e;"></span> Yahoo';
				} else {
					echo '—';
				}
				break;

			case 'league_name':
				$league_name = get_post_meta( $post_id, self::META_LEAGUE_NAME, true );
				echo esc_html( $league_name ? $league_name : '—' );
				break;

			case 'record':
				$wins   = get_post_meta( $post_id, self::META_WINS, true );
				$losses = get_post_meta( $post_id, self::META_LOSSES, true );
				$ties   = get_post_meta( $post_id, self::META_TIES, true );

				if ( $wins || $losses || $ties ) {
					printf(
						'<strong>%d-%d-%d</strong>',
						absint( $wins ),
						absint( $losses ),
						absint( $ties )
					);
				} else {
					echo '—';
				}
				break;

			case 'points':
				$points_for = get_post_meta( $post_id, self::META_POINTS_FOR, true );
				$points_against = get_post_meta( $post_id, self::META_POINTS_AGAINST, true );

				if ( $points_for || $points_against ) {
					printf(
						'<span title="Points For">%.2f</span> / <span title="Points Against">%.2f</span>',
						floatval( $points_for ),
						floatval( $points_against )
					);
				} else {
					echo '—';
				}
				break;

			case 'season':
				$season = get_post_meta( $post_id, self::META_SEASON, true );
				echo esc_html( $season ? $season : '—' );
				break;

			case 'last_sync':
				$last_sync = get_post_meta( $post_id, self::META_LAST_SYNC, true );
				if ( $last_sync ) {
					$timestamp = strtotime( $last_sync );
					if ( $timestamp ) {
						printf(
							'<span title="%s">%s</span>',
							esc_attr( $last_sync ),
							esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ago' )
						);
					} else {
						echo esc_html( $last_sync );
					}
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Get team provider (ESPN or Yahoo).
	 *
	 * @param int $post_id Post ID.
	 * @return string Provider name ('espn', 'yahoo', or empty string).
	 */
	public static function get_team_provider( $post_id ) {
		return get_post_meta( $post_id, self::META_PROVIDER, true );
	}

	/**
	 * Get ESPN league and team IDs.
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Array with 'league_id' and 'team_id' or false.
	 */
	public static function get_espn_ids( $post_id ) {
		$provider = get_post_meta( $post_id, self::META_PROVIDER, true );

		if ( 'espn' !== $provider ) {
			return false;
		}

		$league_id = get_post_meta( $post_id, self::META_ESPN_LEAGUE_ID, true );
		$team_id   = get_post_meta( $post_id, self::META_ESPN_TEAM_ID, true );

		if ( ! $league_id || ! $team_id ) {
			return false;
		}

		return array(
			'league_id' => absint( $league_id ),
			'team_id'   => absint( $team_id ),
		);
	}

	/**
	 * Get Yahoo league and team IDs.
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Array with 'league_id' and 'team_id' or false.
	 */
	public static function get_yahoo_ids( $post_id ) {
		$provider = get_post_meta( $post_id, self::META_PROVIDER, true );

		if ( 'yahoo' !== $provider ) {
			return false;
		}

		$league_id = get_post_meta( $post_id, self::META_YAHOO_LEAGUE_ID, true );
		$team_id   = get_post_meta( $post_id, self::META_YAHOO_TEAM_ID, true );

		if ( ! $league_id || ! $team_id ) {
			return false;
		}

		return array(
			'league_id' => absint( $league_id ),
			'team_id'   => absint( $team_id ),
		);
	}
}
