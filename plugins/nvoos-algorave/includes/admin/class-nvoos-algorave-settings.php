<?php
/**
 * NV oOS Algorave — Admin Settings
 *
 * Provides the WordPress admin settings page for the Algorave plugin.
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
			__( 'Enable Plugin', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Algorave live coding features.', 'nvoos-algorave' ),
			)
		);

		add_settings_field(
			'strudel_cdn',
			__( 'Enable Strudel Engine', 'nvoos-algorave' ),
			array( __CLASS__, 'render_checkbox' ),
			'algorave-settings',
			'nvoos_algorave_general',
			array(
				'id'          => 'strudel_cdn',
				'description' => __( 'Load the bundled Strudel live coding library for TidalCycles mini-notation support.', 'nvoos-algorave' ),
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
				'description' => __( 'Allow non-logged-in users to view and use the [algorave_live_coder] live coder (e.g. for public performances). The Tone.js raw-eval engine remains disabled for guests even when NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL is defined; guests can only use the sandboxed Strudel engine.', 'nvoos-algorave' ),
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
	}

	/**
	 * Sanitize settings on save.
	 *
	 * Consumer addons may extend the sanitized array through the
	 * `nvoos_algorave/sanitize_settings` filter.
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

		return apply_filters( 'nvoos_algorave/sanitize_settings', $sanitized, $input );
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
}

// Initialize.
NV_oOS_Algorave_Settings::init();
