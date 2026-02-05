<?php
/**
 * Fantasy Football Player custom post type.
 *
 * Tracks individual NFL players with stats, projections, and watchlist status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Fantasy Player custom post type for tracking NFL players.
 */
class WP_MCP_AI_Fantasy_Player_CPT {
	const POST_TYPE = 'ff_player';

	// Meta keys.
	const META_PLAYER_ID          = '_ff_player_id';
	const META_PROVIDER           = '_ff_provider'; // 'espn' or 'yahoo'.
	const META_ESPN_PLAYER_ID     = '_ff_espn_player_id';
	const META_YAHOO_PLAYER_ID    = '_ff_yahoo_player_id';
	const META_POSITION           = '_ff_position';
	const META_PRO_TEAM           = '_ff_pro_team';
	const META_PRO_TEAM_ABBREV    = '_ff_pro_team_abbrev';
	const META_PLAYER_STATUS      = '_ff_player_status'; // 'active', 'injured', 'out', etc.
	const META_INJURY_STATUS      = '_ff_injury_status';
	const META_SEASON             = '_ff_season';
	const META_TOTAL_POINTS       = '_ff_total_points';
	const META_AVERAGE_POINTS     = '_ff_average_points';
	const META_GAMES_PLAYED       = '_ff_games_played';
	const META_ON_WATCHLIST       = '_ff_on_watchlist';
	const META_WATCHLIST_NOTES    = '_ff_watchlist_notes';
	const META_LAST_SYNC          = '_ff_last_sync';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only initialize if fantasy football is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_fantasy_football'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'customize_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Register the Fantasy Player post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'           => _x( 'Fantasy Players', 'Post Type General Name', 'mcp-ai-wpoos-pro' ),
			'singular_name'  => _x( 'Fantasy Player', 'Post Type Singular Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'      => __( 'Fantasy Players', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar' => __( 'Fantasy Player', 'mcp-ai-wpoos-pro' ),
			'all_items'      => __( 'All Players', 'mcp-ai-wpoos-pro' ),
			'add_new_item'   => __( 'Add New Player', 'mcp-ai-wpoos-pro' ),
			'add_new'        => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'new_item'       => __( 'New Player', 'mcp-ai-wpoos-pro' ),
			'edit_item'      => __( 'Edit Player', 'mcp-ai-wpoos-pro' ),
			'update_item'    => __( 'Update Player', 'mcp-ai-wpoos-pro' ),
			'view_item'      => __( 'View Player', 'mcp-ai-wpoos-pro' ),
			'search_items'   => __( 'Search Players', 'mcp-ai-wpoos-pro' ),
			'not_found'      => __( 'Not found', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'label'             => __( 'Fantasy Player', 'mcp-ai-wpoos-pro' ),
			'description'       => __( 'Fantasy Football Players', 'mcp-ai-wpoos-pro' ),
			'labels'            => $labels,
			'supports'          => array( 'title', 'editor', 'thumbnail' ),
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => 'edit.php?post_type=ff_team',
			'menu_icon'         => 'dashicons-businessman',
			'show_in_admin_bar' => false,
			'can_export'        => true,
			'capability_type'   => 'post',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomy for player positions.
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'          => _x( 'Positions', 'Taxonomy General Name', 'mcp-ai-wpoos-pro' ),
			'singular_name' => _x( 'Position', 'Taxonomy Singular Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Positions', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Positions', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Position', 'mcp-ai-wpoos-pro' ),
			'view_item'     => __( 'View Position', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Position', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
		);

		register_taxonomy( 'ff_position', array( self::POST_TYPE ), $args );

		// Register default positions.
		$default_positions = array( 'QB', 'RB', 'WR', 'TE', 'K', 'D/ST' );
		foreach ( $default_positions as $position ) {
			if ( ! term_exists( $position, 'ff_position' ) ) {
				wp_insert_term( $position, 'ff_position' );
			}
		}
	}

	/**
	 * Register post meta fields.
	 */
	public static function register_meta() {
		$meta_keys = array(
			self::META_PLAYER_ID,
			self::META_PROVIDER,
			self::META_POSITION,
			self::META_PRO_TEAM,
			self::META_PRO_TEAM_ABBREV,
			self::META_PLAYER_STATUS,
			self::META_INJURY_STATUS,
			self::META_SEASON,
			self::META_WATCHLIST_NOTES,
			self::META_LAST_SYNC,
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
			self::META_ESPN_PLAYER_ID,
			self::META_YAHOO_PLAYER_ID,
			self::META_GAMES_PLAYED,
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
			self::META_TOTAL_POINTS,
			self::META_AVERAGE_POINTS,
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

		// Boolean meta.
		register_post_meta(
			self::POST_TYPE,
			self::META_ON_WATCHLIST,
			array(
				'type'   => 'boolean',
				'single' => true,
			)
		);
	}

	/**
	 * Register meta boxes.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'ff_player_info',
			__( 'Player Information', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_player_info_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'ff_player_watchlist',
			__( 'Watchlist', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_watchlist_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render player information meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_player_info_meta_box( $post ) {
		$provider        = get_post_meta( $post->ID, self::META_PROVIDER, true );
		$position        = get_post_meta( $post->ID, self::META_POSITION, true );
		$pro_team        = get_post_meta( $post->ID, self::META_PRO_TEAM, true );
		$pro_team_abbrev = get_post_meta( $post->ID, self::META_PRO_TEAM_ABBREV, true );
		$status          = get_post_meta( $post->ID, self::META_PLAYER_STATUS, true );
		$injury_status   = get_post_meta( $post->ID, self::META_INJURY_STATUS, true );
		$total_points    = get_post_meta( $post->ID, self::META_TOTAL_POINTS, true );
		$avg_points      = get_post_meta( $post->ID, self::META_AVERAGE_POINTS, true );
		$games_played    = get_post_meta( $post->ID, self::META_GAMES_PLAYED, true );

		$provider_label = 'espn' === $provider ? 'ESPN' : ( 'yahoo' === $provider ? 'Yahoo' : __( 'Unknown', 'mcp-ai-wpoos-pro' ) );
		?>
<table class="form-table">
	<tr>
		<th><?php esc_html_e( 'Provider', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><?php echo esc_html( $provider_label ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Position', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><strong><?php echo esc_html( $position ); ?></strong></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Team', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><?php echo esc_html( $pro_team_abbrev ? $pro_team_abbrev : $pro_team ); ?></td>
	</tr>
	<?php if ( $injury_status ) : ?>
	<tr>
		<th><?php esc_html_e( 'Injury Status', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><span style="color: #d63638; font-weight: bold;"><?php echo esc_html( strtoupper( $injury_status ) ); ?></span></td>
	</tr>
	<?php endif; ?>
	<tr>
		<th><?php esc_html_e( 'Total Points', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><?php echo esc_html( number_format_i18n( floatval( $total_points ), 2 ) ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Average Points', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><?php echo esc_html( number_format_i18n( floatval( $avg_points ), 2 ) ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Games Played', 'mcp-ai-wpoos-pro' ); ?></th>
		<td><?php echo esc_html( $games_played ); ?></td>
	</tr>
</table>
		<?php
	}

	/**
	 * Render watchlist meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_watchlist_meta_box( $post ) {
		wp_nonce_field( 'ff_player_watchlist', 'ff_player_watchlist_nonce' );

		$on_watchlist = get_post_meta( $post->ID, self::META_ON_WATCHLIST, true );
		$notes        = get_post_meta( $post->ID, self::META_WATCHLIST_NOTES, true );
		?>
		<p>
			<label>
				<input type="checkbox" name="ff_on_watchlist" value="1" <?php checked( $on_watchlist, true ); ?> />
				<?php esc_html_e( 'Add to Watchlist', 'mcp-ai-wpoos-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="ff_watchlist_notes"><?php esc_html_e( 'Notes:', 'mcp-ai-wpoos-pro' ); ?></label>
			<textarea id="ff_watchlist_notes" name="ff_watchlist_notes" rows="4" class="widefat"><?php echo esc_textarea( $notes ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Customize admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function customize_columns( $columns ) {
		$new_columns = array(
			'cb'           => $columns['cb'],
			'title'        => $columns['title'],
			'position'     => __( 'Position', 'mcp-ai-wpoos-pro' ),
			'team'         => __( 'Team', 'mcp-ai-wpoos-pro' ),
			'status'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
			'avg_points'   => __( 'Avg Points', 'mcp-ai-wpoos-pro' ),
			'watchlist'    => __( 'Watchlist', 'mcp-ai-wpoos-pro' ),
			'date'         => $columns['date'],
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
			case 'position':
				$position = get_post_meta( $post_id, self::META_POSITION, true );
				echo '<strong>' . esc_html( $position ? $position : '—' ) . '</strong>';
				break;

			case 'team':
				$team_abbrev = get_post_meta( $post_id, self::META_PRO_TEAM_ABBREV, true );
				echo esc_html( $team_abbrev ? $team_abbrev : '—' );
				break;

			case 'status':
				$injury_status = get_post_meta( $post_id, self::META_INJURY_STATUS, true );
				if ( $injury_status ) {
					echo '<span style="color: #d63638;">' . esc_html( strtoupper( $injury_status ) ) . '</span>';
				} else {
					echo '<span style="color: #00a32a;">Active</span>';
				}
				break;

			case 'avg_points':
				$avg_points = get_post_meta( $post_id, self::META_AVERAGE_POINTS, true );
				echo esc_html( $avg_points ? number_format_i18n( floatval( $avg_points ), 1 ) : '—' );
				break;

			case 'watchlist':
				$on_watchlist = get_post_meta( $post_id, self::META_ON_WATCHLIST, true );
				if ( $on_watchlist ) {
					echo '<span class="dashicons dashicons-star-filled" style="color: #f0b849;" title="' . esc_attr__( 'On Watchlist', 'mcp-ai-wpoos-pro' ) . '"></span>';
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Make columns sortable.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['avg_points'] = self::META_AVERAGE_POINTS;
		return $columns;
	}
}
