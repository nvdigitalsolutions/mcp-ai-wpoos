<?php
/**
 * NV oOS Extended Cognition — Admin Settings
 *
 * Provides the WordPress admin settings page for the Extended Cognition Toolkit.
 *
 * @package NV_oOS_Ext_Cognition
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
class NV_oOS_Ext_Cognition_Settings {

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
	 * Add the settings page under Settings menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Extended Cognition Toolkit', 'nvoos-ext-cognition' ),
			__( 'Ext. Cognition', 'nvoos-ext-cognition' ),
			'manage_options',
			'nvoos-ext-cognition',
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
			'nvoos_ext_cog_settings_group',
			NV_oOS_Ext_Cognition::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// General section.
		add_settings_section(
			'nvoos_ext_cog_general',
			__( 'General', 'nvoos-ext-cognition' ),
			'__return_false',
			'nvoos-ext-cognition'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Toolkit', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_general',
			array(
				'id'          => 'enabled',
				'description' => __( 'Enable the Extended Cognition Toolkit and register all sensor tools.', 'nvoos-ext-cognition' ),
			)
		);

		add_settings_field(
			'guest_access',
			__( 'Guest Access', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_general',
			array(
				'id'          => 'guest_access',
				'description' => __( 'Allow non-logged-in users to trigger sensor captures. Off by default for privacy.', 'nvoos-ext-cognition' ),
			)
		);

		add_settings_field(
			'gdpr_consent',
			__( 'GDPR Consent Prompt', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_general',
			array(
				'id'          => 'gdpr_consent',
				'description' => __( 'Show a consent notice to users before the first sensor access in a session.', 'nvoos-ext-cognition' ),
			)
		);

		// Sensors section.
		add_settings_section(
			'nvoos_ext_cog_sensors',
			__( 'Enabled Sensors', 'nvoos-ext-cognition' ),
			function () {
				echo '<p>' . esc_html__( 'Choose which sensor types are available to AI agents. Disabled sensors will return an error if an AI attempts to use them.', 'nvoos-ext-cognition' ) . '</p>';
			},
			'nvoos-ext-cognition'
		);

		$sensor_fields = array(
			'sensor_camera'     => __( 'Camera (visual cortex)', 'nvoos-ext-cognition' ),
			'sensor_microphone' => __( 'Microphone (auditory cortex)', 'nvoos-ext-cognition' ),
			'sensor_screen'     => __( 'Screen Capture (metacognitive mirror)', 'nvoos-ext-cognition' ),
			'sensor_motion'     => __( 'Gyroscope / Motion (vestibular system)', 'nvoos-ext-cognition' ),
		);

		foreach ( $sensor_fields as $id => $label ) {
			add_settings_field(
				$id,
				$label,
				array( __CLASS__, 'render_checkbox' ),
				'nvoos-ext-cognition',
				'nvoos_ext_cog_sensors',
				array(
					'id'          => $id,
					'description' => '',
				)
			);
		}

		// Storage section.
		add_settings_section(
			'nvoos_ext_cog_storage',
			__( 'Storage & Privacy', 'nvoos-ext-cognition' ),
			'__return_false',
			'nvoos-ext-cognition'
		);

		add_settings_field(
			'store_captures',
			__( 'Store Captures by Default', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_checkbox' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_storage',
			array(
				'id'          => 'store_captures',
				'description' => __( 'If checked, captured frames/audio are saved to the media library by default. Individual tool calls can override this.', 'nvoos-ext-cognition' ),
			)
		);

		add_settings_field(
			'retention_days',
			__( 'Data Retention (days)', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_number' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_storage',
			array(
				'id'          => 'retention_days',
				'min'         => 1,
				'max'         => 365,
				'description' => __( 'Number of days to retain stored sensory captures before auto-deletion.', 'nvoos-ext-cognition' ),
			)
		);

		add_settings_field(
			'max_capture_size_kb',
			__( 'Max Capture Size (KB)', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_number' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_storage',
			array(
				'id'          => 'max_capture_size_kb',
				'min'         => 100,
				'max'         => 10240,
				'description' => __( 'Maximum allowed size per captured frame or audio transcript in kilobytes. Default: 2048 (2MB).', 'nvoos-ext-cognition' ),
			)
		);

		// Limits section.
		add_settings_section(
			'nvoos_ext_cog_limits',
			__( 'Rate Limits', 'nvoos-ext-cognition' ),
			'__return_false',
			'nvoos-ext-cognition'
		);

		add_settings_field(
			'rate_limit',
			__( 'Captures per Minute', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_number' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_limits',
			array(
				'id'          => 'rate_limit',
				'min'         => 1,
				'max'         => 60,
				'description' => __( 'Maximum number of sensor captures per minute per session per sensor type.', 'nvoos-ext-cognition' ),
			)
		);

		// Vision model section.
		add_settings_section(
			'nvoos_ext_cog_model',
			__( 'Vision Model', 'nvoos-ext-cognition' ),
			'__return_false',
			'nvoos-ext-cognition'
		);

		add_settings_field(
			'vision_model',
			__( 'Default Vision Model', 'nvoos-ext-cognition' ),
			array( __CLASS__, 'render_select' ),
			'nvoos-ext-cognition',
			'nvoos_ext_cog_model',
			array(
				'id'          => 'vision_model',
				'description' => __( 'Model used by ext_cog_analyze_sensory_input when model=auto.', 'nvoos-ext-cognition' ),
				'options'     => array(
					'auto'                 => __( 'Auto (use assistant\'s provider)', 'nvoos-ext-cognition' ),
					'gpt-4o'               => 'GPT-4o',
					'gpt-4-vision-preview' => 'GPT-4 Vision Preview',
					'gemini-pro-vision'    => 'Gemini Pro Vision',
					'gemini-1.5-pro'       => 'Gemini 1.5 Pro',
				),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public static function sanitize_settings( $input ) {
		return array(
			'enabled'             => ! empty( $input['enabled'] ),
			'sensor_camera'       => ! empty( $input['sensor_camera'] ),
			'sensor_microphone'   => ! empty( $input['sensor_microphone'] ),
			'sensor_screen'       => ! empty( $input['sensor_screen'] ),
			'sensor_motion'       => ! empty( $input['sensor_motion'] ),
			'guest_access'        => ! empty( $input['guest_access'] ),
			'store_captures'      => ! empty( $input['store_captures'] ),
			'gdpr_consent'        => ! empty( $input['gdpr_consent'] ),
			'retention_days'      => isset( $input['retention_days'] ) ? max( 1, min( 365, absint( $input['retention_days'] ) ) ) : 7,
			'rate_limit'          => isset( $input['rate_limit'] ) ? max( 1, min( 60, absint( $input['rate_limit'] ) ) ) : 10,
			'max_capture_size_kb' => isset( $input['max_capture_size_kb'] ) ? max( 100, min( 10240, absint( $input['max_capture_size_kb'] ) ) ) : 2048,
			'vision_model'        => in_array( $input['vision_model'] ?? 'auto', array( 'auto', 'gpt-4o', 'gpt-4-vision-preview', 'gemini-pro-vision', 'gemini-1.5-pro' ), true )
				? sanitize_text_field( $input['vision_model'] )
				: 'auto',
			'allowed_roles'       => isset( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] )
				? array_map( 'sanitize_text_field', $input['allowed_roles'] )
				: array( 'administrator', 'editor' ),
		);
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
			<h1><?php esc_html_e( 'Extended Cognition Toolkit Settings', 'nvoos-ext-cognition' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure sensory inputs for AI agents — camera, microphone, screen capture, and motion sensors. Based on Clark & Chalmers (1998) extended mind theory: the AI\'s cognition extends to any reliable perceptual resource it can actively sense.', 'nvoos-ext-cognition' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_ext_cog_settings_group' );
				do_settings_sections( 'nvoos-ext-cognition' );
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
		$settings = NV_oOS_Ext_Cognition::get_settings();
		$value    = ! empty( $settings[ $args['id'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( NV_oOS_Ext_Cognition::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
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
		$settings = NV_oOS_Ext_Cognition::get_settings();
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		?>
		<input type="number"
			name="<?php echo esc_attr( NV_oOS_Ext_Cognition::OPTION_KEY . '[' . $args['id'] . ']' ); ?>"
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
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function render_select( $args ) {
		$settings = NV_oOS_Ext_Cognition::get_settings();
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		?>
		<select name="<?php echo esc_attr( NV_oOS_Ext_Cognition::OPTION_KEY . '[' . $args['id'] . ']' ); ?>">
			<?php foreach ( $args['options'] as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}
}

// Initialize.
NV_oOS_Ext_Cognition_Settings::init();
