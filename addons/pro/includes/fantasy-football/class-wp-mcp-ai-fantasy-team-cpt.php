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

		$league_key = get_post_meta( $post->ID, self::META_LEAGUE_KEY, true );
		$team_key   = get_post_meta( $post->ID, self::META_TEAM_KEY, true );
		?>
<table class="form-table">
<tr>
<th><label for="ff_league_key"><?php esc_html_e( 'League Key', 'mcp-ai-wpoos-pro' ); ?></label></th>
<td>
<input type="text" id="ff_league_key" name="ff_league_key" value="<?php echo esc_attr( $league_key ); ?>" class="regular-text" />
</td>
</tr>
<tr>
<th><label for="ff_team_key"><?php esc_html_e( 'Team Key', 'mcp-ai-wpoos-pro' ); ?></label></th>
<td>
<input type="text" id="ff_team_key" name="ff_team_key" value="<?php echo esc_attr( $team_key ); ?>" class="regular-text" />
</td>
</tr>
</table>
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
			'league_name' => __( 'League', 'mcp-ai-wpoos-pro' ),
			'season'      => __( 'Season', 'mcp-ai-wpoos-pro' ),
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
			case 'league_name':
				$league_name = get_post_meta( $post_id, self::META_LEAGUE_NAME, true );
				echo esc_html( $league_name ? $league_name : '—' );
				break;

			case 'season':
				$season = get_post_meta( $post_id, self::META_SEASON, true );
				echo esc_html( $season ? $season : '—' );
				break;
		}
	}
}
