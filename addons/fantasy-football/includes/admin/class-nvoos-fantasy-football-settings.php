<?php
/**
 * NV oOS Fantasy Football — Admin Settings
 *
 * Provides the WordPress admin settings page for the Fantasy Football addon.
 * API credentials are configured in the main NV oOS Integrations settings page.
 *
 * @package NV_oOS_Fantasy_Football
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page handler.
 *
 * @since 0.1.0
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
	 * Add the settings page under the Settings menu.
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
			array( __CLASS__, 'render_general_description' ),
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
	}

	/**
	 * Render the general section description.
	 *
	 * @return void
	 */
	public static function render_general_description() {
		$integrations_url = admin_url( 'admin.php?page=wp-mcp-ai-settings#section-integrations' );
		echo '<p>';
		printf(
			/* translators: %s: Link to integrations settings page. */
			esc_html__( 'Yahoo and ESPN API credentials are configured in the %s.', 'nvoos-fantasy-football' ),
			'<a href="' . esc_url( $integrations_url ) . '">' . esc_html__( 'NV oOS Integrations settings', 'nvoos-fantasy-football' ) . '</a>'
		);
		echo '</p>';
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @since 0.1.0
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enabled'] = ! empty( $input['enabled'] );

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
}
