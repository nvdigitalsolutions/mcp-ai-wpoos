<?php
/**
 * NV oOS Algorave — Admin Settings
 *
 * Provides the WordPress admin settings page for the Algorave addon.
 *
 * @package NV_oOS_Algorave
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
class NV_oOS_Algorave_Settings {

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
	 * Add the settings page under the Algorave Patterns menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . NV_oOS_Algorave_Pattern_CPT::POST_TYPE,
			__( 'Algorave Settings', 'nvoos-algorave' ),
			__( 'Settings', 'nvoos-algorave' ),
			'manage_options',
			'algorave-settings',
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
			'nvoos_algorave_settings',
			NV_oOS_Algorave::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// General section.
		add_settings_section(
			'nvoos_algorave_general',
			__( 'General Settings', 'nvoos-algorave' ),
			'__return_false',
			'algorave-settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Addon', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Algorave live coding tools.', 'nvoos-algorave' ),
			)
		);

		add_settings_field(
			'strudel_cdn',
			__( 'Load Strudel via CDN', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'strudel_cdn',
				'description' => __( 'Load the Strudel live coding library from CDN for TidalCycles mini-notation support.', 'nvoos-algorave' ),
			)
		);

		add_settings_field(
			'visualizer_enabled',
			__( 'Audio Visualizer', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'visualizer_enabled',
				'description' => __( 'Enable the audio waveform/spectrum visualizer.', 'nvoos-algorave' ),
			)
		);

		add_settings_field(
			'guest_access',
			__( 'Guest Access', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'guest_access',
				'description' => __( 'Allow non-logged-in users to use the live coder (performances).', 'nvoos-algorave' ),
			)
		);

		// Defaults section.
		add_settings_section(
			'nvoos_algorave_defaults',
			__( 'Default Values', 'nvoos-algorave' ),
			'__return_false',
			'algorave-settings'
		);

		add_settings_field(
			'default_bpm',
			__( 'Default BPM', 'nvoos-algorave' ),
			array( __CLASS__, 'render_number' ),
			'algorave-settings',
			'nvoos_algorave_defaults',
			array(
				'id'          => 'default_bpm',
				'min'         => 20,
				'max'         => 300,
				'description' => __( 'Default beats per minute for new patterns.', 'nvoos-algorave' ),
			)
		);

		add_settings_field(
			'default_scale',
			__( 'Default Scale', 'nvoos-algorave' ),
			array( __CLASS__, 'render_text' ),
			'algorave-settings',
			'nvoos_algorave_defaults',
			array(
				'id'          => 'default_scale',
				'description' => __( 'Default musical scale (e.g. "C minor", "A major").', 'nvoos-algorave' ),
			)
		);

		// AI section.
		add_settings_section(
			'nvoos_algorave_ai',
			__( 'AI Music Generation', 'nvoos-algorave' ),
			'__return_false',
			'algorave-settings'
		);

		add_settings_field(
			'ai_provider',
			__( 'AI Provider', 'nvoos-algorave' ),
			array( __CLASS__, 'render_select' ),
			'algorave-settings',
			'nvoos_algorave_ai',
			array(
				'id'      => 'ai_provider',
				'options' => array(
					''          => __( '— Use oOS Default —', 'nvoos-algorave' ),
					'lyria'     => __( 'Google Lyria (Gemini)', 'nvoos-algorave' ),
					'replicate' => __( 'Replicate', 'nvoos-algorave' ),
				),
			)
		);

		add_settings_field(
			'ai_api_key',
			__( 'AI API Key', 'nvoos-algorave' ),
			array( __CLASS__, 'render_password' ),
			'algorave-settings',
			'nvoos_algorave_ai',
			array(
				'id'          => 'ai_api_key',
				'description' => __( 'API key for the selected AI music generation provider (optional — uses oOS keys by default).', 'nvoos-algorave' ),
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

		$sanitized['enabled']            = ! empty( $input['enabled'] );
		$sanitized['strudel_cdn']        = ! empty( $input['strudel_cdn'] );
		$sanitized['visualizer_enabled'] = ! empty( $input['visualizer_enabled'] );
		$sanitized['guest_access']       = ! empty( $input['guest_access'] );
		$sanitized['default_bpm']        = isset( $input['default_bpm'] ) ? max( 20, min( 300, absint( $input['default_bpm'] ) ) ) : 120;
		$sanitized['default_scale']      = sanitize_text_field( $input['default_scale'] ?? 'C minor' );
		$sanitized['ai_provider']        = sanitize_text_field( $input['ai_provider'] ?? '' );
		$sanitized['ai_api_key']         = sanitize_text_field( $input['ai_api_key'] ?? '' );

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
			<h1><?php esc_html_e( 'Algorave Settings', 'nvoos-algorave' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_algorave_settings' );
				do_settings_sections( 'algorave-settings' );
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
		$settings = NV_oOS_Algorave::get_settings();
		$value    = ! empty( $settings[ $args['id'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?> />
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a number field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_number( $args ) {
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="number"
			name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( $args['min'] ?? '' ); ?>"
			max="<?php echo esc_attr( $args['max'] ?? '' ); ?>"
			class="small-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a text field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_text( $args ) {
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="text"
			name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
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
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<input type="password"
			name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_Algorave::get_settings();
		$value    = $settings[ $args['id'] ] ?? '';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Algorave::OPTION_KEY . '[' . $args['id'] . ']' ); ?>">
			<?php foreach ( $args['options'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

// Initialize.
NV_oOS_Algorave_Settings::init();
