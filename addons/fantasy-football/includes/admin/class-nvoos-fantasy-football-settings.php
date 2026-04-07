<?php
/**
 * NV oOS Fantasy Football — Admin Settings
 *
 * Provides the WordPress admin settings page for the Fantasy Football addon,
 * including Yahoo and ESPN API credential management.
 *
 * @package NV_oOS_Fantasy_Football
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page handler.
 *
 * @since 1.0.0
 */
class NV_oOS_Fantasy_Football_Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page under the oOS menu or as a standalone page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Fantasy Football Settings', 'nvoos-fantasy-football' ),
			__( 'Fantasy Football', 'nvoos-fantasy-football' ),
			'manage_options',
			'fantasy-football-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_fantasy_football_settings',
			NV_oOS_Fantasy_Football::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// General section.
		add_settings_section(
			'nvoos_ff_general',
			__( 'General Settings', 'nvoos-fantasy-football' ),
			'__return_false',
			'fantasy-football-settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Addon', 'nvoos-fantasy-football' ),
			array( __CLASS__, 'render_checkbox' ),
			'fantasy-football-settings',
			'nvoos_ff_general',
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Fantasy Football tools in the oOS chat interface.', 'nvoos-fantasy-football' ),
			)
		);

		// Yahoo section.
		add_settings_section(
			'nvoos_ff_yahoo',
			__( 'Yahoo Fantasy Sports API', 'nvoos-fantasy-football' ),
			'__return_false',
			'fantasy-football-settings'
		);

		add_settings_field(
			'yahoo_client_id',
			__( 'Yahoo Client ID', 'nvoos-fantasy-football' ),
			array( __CLASS__, 'render_text' ),
			'fantasy-football-settings',
			'nvoos_ff_yahoo',
			array(
				'id'          => 'yahoo_client_id',
				'description' => sprintf(
					/* translators: %s: URL to Yahoo Developer */
					__( 'OAuth 2.0 Client ID (Consumer Key) from Yahoo Developer Network. Get your credentials from %s.', 'nvoos-fantasy-football' ),
					'<a href="https://developer.yahoo.com/apps/" target="_blank">Yahoo Developer Network</a>'
				),
			)
		);

		add_settings_field(
			'yahoo_client_secret',
			__( 'Yahoo Client Secret', 'nvoos-fantasy-football' ),
			array( __CLASS__, 'render_password' ),
			'fantasy-football-settings',
			'nvoos_ff_yahoo',
			array(
				'id'          => 'yahoo_client_secret',
				'description' => __( 'OAuth 2.0 Client Secret (Consumer Secret) from Yahoo Developer Network.', 'nvoos-fantasy-football' ),
			)
		);

		// ESPN section.
		add_settings_section(
			'nvoos_ff_espn',
			__( 'ESPN Fantasy Sports API', 'nvoos-fantasy-football' ),
			'__return_false',
			'fantasy-football-settings'
		);

		add_settings_field(
			'espn_fantasy_espn_s2',
			__( 'ESPN S2 Cookie', 'nvoos-fantasy-football' ),
			array( __CLASS__, 'render_password' ),
			'fantasy-football-settings',
			'nvoos_ff_espn',
			array(
				'id'          => 'espn_fantasy_espn_s2',
				'description' => sprintf(
					/* translators: %s: URL to ESPN authentication docs */
					__( 'ESPN S2 authentication cookie for accessing private leagues. See %s for how to obtain these cookies from your browser.', 'nvoos-fantasy-football' ),
					'<a href="https://github.com/cwendt94/espn-api/blob/master/README.md#espn-s2-and-swid" target="_blank">ESPN API Authentication Guide</a>'
				),
			)
		);

		add_settings_field(
			'espn_fantasy_swid',
			__( 'ESPN SWID Cookie', 'nvoos-fantasy-football' ),
			array( __CLASS__, 'render_password' ),
			'fantasy-football-settings',
			'nvoos_ff_espn',
			array(
				'id'          => 'espn_fantasy_swid',
				'description' => __( 'ESPN SWID authentication cookie for accessing private leagues. Extract from browser after logging into ESPN Fantasy.', 'nvoos-fantasy-football' ),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled']              = ! empty( $input['enabled'] );
		$sanitized['yahoo_client_id']      = sanitize_text_field( $input['yahoo_client_id'] ?? '' );
		$sanitized['yahoo_client_secret']  = sanitize_text_field( $input['yahoo_client_secret'] ?? '' );
		$sanitized['espn_fantasy_espn_s2'] = sanitize_text_field( $input['espn_fantasy_espn_s2'] ?? '' );
		$sanitized['espn_fantasy_swid']    = sanitize_text_field( $input['espn_fantasy_swid'] ?? '' );

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fantasy Football Settings', 'nvoos-fantasy-football' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_fantasy_football_settings' );
				do_settings_sections( 'fantasy-football-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_checkbox( $args ) {
		$settings = NV_oOS_Fantasy_Football::get_settings();
		$value    = ! empty( $settings[ $args['id'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Fantasy_Football::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?> />
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a text field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_text( $args ) {
		$settings = NV_oOS_Fantasy_Football::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="text"
			name="<?php echo esc_attr( NV_oOS_Fantasy_Football::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a password field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_password( $args ) {
		$settings = NV_oOS_Fantasy_Football::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="password"
			name="<?php echo esc_attr( NV_oOS_Fantasy_Football::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="new-password" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}
}
