<?php
/**
 * WP MCP AI Extended Cognition — Admin Settings
 *
 * Provides the WordPress admin settings page for the Extended Cognition Toolkit.
 * All settings are stored under the main `wp_mcp_ai_settings` option with
 * `ext_cog_` prefixed keys (or `enable_extended_cognition_toolkit` for the toggle).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page handler for the Extended Cognition Toolkit.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ext_Cog_Settings {

	/**
	 * WordPress settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_settings';

	/**
	 * Settings group name used with settings_fields().
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'wp_mcp_ai_ext_cog_settings_group';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-ext-cognition';

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
			__( 'Extended Cognition Toolkit', 'mcp-ai-wpoos' ),
			__( 'Ext. Cognition', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
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
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		// General section.
		add_settings_section(
			'wp_mcp_ai_ext_cog_general',
			__( 'General', 'mcp-ai-wpoos' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_extended_cognition_toolkit',
			__( 'Enable Toolkit', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_general',
			array(
				'setting_key' => 'enable_extended_cognition_toolkit',
				'description' => __( 'Enable the Extended Cognition Toolkit and register all sensor tools.', 'mcp-ai-wpoos' ),
			)
		);

		add_settings_field(
			'ext_cog_guest_access',
			__( 'Guest Access', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_general',
			array(
				'setting_key' => 'ext_cog_guest_access',
				'description' => __( 'Allow non-logged-in users to trigger sensor captures. Off by default for privacy.', 'mcp-ai-wpoos' ),
			)
		);

		add_settings_field(
			'ext_cog_gdpr_consent',
			__( 'GDPR Consent Prompt', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_general',
			array(
				'setting_key' => 'ext_cog_gdpr_consent',
				'description' => __( 'Show a consent notice to users before the first sensor access in a session.', 'mcp-ai-wpoos' ),
			)
		);

		// Sensors section.
		add_settings_section(
			'wp_mcp_ai_ext_cog_sensors',
			__( 'Enabled Sensors', 'mcp-ai-wpoos' ),
			function () {
				echo '<p>' . esc_html__( 'Choose which sensor types are available to AI agents. Disabled sensors will return an error if an AI attempts to use them.', 'mcp-ai-wpoos' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$sensor_fields = array(
			'ext_cog_sensor_camera'     => __( 'Camera (visual cortex)', 'mcp-ai-wpoos' ),
			'ext_cog_sensor_microphone' => __( 'Microphone (auditory cortex)', 'mcp-ai-wpoos' ),
			'ext_cog_sensor_screen'     => __( 'Screen Capture (metacognitive mirror)', 'mcp-ai-wpoos' ),
			'ext_cog_sensor_motion'     => __( 'Gyroscope / Motion (vestibular system)', 'mcp-ai-wpoos' ),
		);

		foreach ( $sensor_fields as $setting_key => $label ) {
			add_settings_field(
				$setting_key,
				$label,
				array( __CLASS__, 'render_checkbox' ),
				self::PAGE_SLUG,
				'wp_mcp_ai_ext_cog_sensors',
				array(
					'setting_key' => $setting_key,
					'description' => '',
				)
			);
		}

		// Storage section.
		add_settings_section(
			'wp_mcp_ai_ext_cog_storage',
			__( 'Storage & Privacy', 'mcp-ai-wpoos' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'ext_cog_store_captures',
			__( 'Store Captures by Default', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_storage',
			array(
				'setting_key' => 'ext_cog_store_captures',
				'description' => __( 'If checked, captured frames/audio are saved to the media library by default. Individual tool calls can override this.', 'mcp-ai-wpoos' ),
			)
		);

		add_settings_field(
			'ext_cog_retention_days',
			__( 'Data Retention (days)', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_storage',
			array(
				'setting_key' => 'ext_cog_retention_days',
				'min'         => 1,
				'max'         => 365,
				'description' => __( 'Number of days to retain stored sensory captures before auto-deletion.', 'mcp-ai-wpoos' ),
			)
		);

		add_settings_field(
			'ext_cog_max_capture_size_kb',
			__( 'Max Capture Size (KB)', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_storage',
			array(
				'setting_key' => 'ext_cog_max_capture_size_kb',
				'min'         => 100,
				'max'         => 10240,
				'description' => __( 'Maximum allowed size per captured frame or audio transcript in kilobytes. Default: 2048 (2MB).', 'mcp-ai-wpoos' ),
			)
		);

		// Limits section.
		add_settings_section(
			'wp_mcp_ai_ext_cog_limits',
			__( 'Rate Limits', 'mcp-ai-wpoos' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'ext_cog_rate_limit',
			__( 'Captures per Minute', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_limits',
			array(
				'setting_key' => 'ext_cog_rate_limit',
				'min'         => 1,
				'max'         => 60,
				'description' => __( 'Maximum number of sensor captures per minute per session per sensor type.', 'mcp-ai-wpoos' ),
			)
		);

		// Vision model section.
		add_settings_section(
			'wp_mcp_ai_ext_cog_model',
			__( 'Vision Model', 'mcp-ai-wpoos' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'ext_cog_vision_model',
			__( 'Default Vision Model', 'mcp-ai-wpoos' ),
			array( __CLASS__, 'render_select' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_model',
			array(
				'setting_key' => 'ext_cog_vision_model',
				'description' => __( 'Model used by ext_cog_analyze_sensory_input when model=auto.', 'mcp-ai-wpoos' ),
				'options'     => array(
					'auto'                 => __( 'Auto (use assistant\'s provider)', 'mcp-ai-wpoos' ),
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
	 * Validates the submitted ext_cog fields and merges them into the main
	 * wp_mcp_ai_settings option, preserving all non-ext-cog keys.
	 *
	 * @param array $input Raw input values.
	 * @return array Merged settings array.
	 */
	public static function sanitize_settings( $input ) {
		// Preserve existing non-ext-cog settings.
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		$current['enable_extended_cognition_toolkit'] = ! empty( $input['enable_extended_cognition_toolkit'] );
		$current['ext_cog_sensor_camera']             = ! empty( $input['ext_cog_sensor_camera'] );
		$current['ext_cog_sensor_microphone']         = ! empty( $input['ext_cog_sensor_microphone'] );
		$current['ext_cog_sensor_screen']             = ! empty( $input['ext_cog_sensor_screen'] );
		$current['ext_cog_sensor_motion']             = ! empty( $input['ext_cog_sensor_motion'] );
		$current['ext_cog_guest_access']              = ! empty( $input['ext_cog_guest_access'] );
		$current['ext_cog_store_captures']            = ! empty( $input['ext_cog_store_captures'] );
		$current['ext_cog_gdpr_consent']              = ! empty( $input['ext_cog_gdpr_consent'] );

		$current['ext_cog_retention_days'] = isset( $input['ext_cog_retention_days'] )
			? max( 1, min( 365, absint( $input['ext_cog_retention_days'] ) ) )
			: 7;

		$current['ext_cog_rate_limit'] = isset( $input['ext_cog_rate_limit'] )
			? max( 1, min( 60, absint( $input['ext_cog_rate_limit'] ) ) )
			: 10;

		$current['ext_cog_max_capture_size_kb'] = isset( $input['ext_cog_max_capture_size_kb'] )
			? max( 100, min( 10240, absint( $input['ext_cog_max_capture_size_kb'] ) ) )
			: 2048;

		$allowed_models                  = array( 'auto', 'gpt-4o', 'gpt-4-vision-preview', 'gemini-pro-vision', 'gemini-1.5-pro' );
		$submitted_model                 = isset( $input['ext_cog_vision_model'] ) ? $input['ext_cog_vision_model'] : 'auto';
		$current['ext_cog_vision_model'] = in_array( $submitted_model, $allowed_models, true )
			? sanitize_text_field( $submitted_model )
			: 'auto';

		if ( isset( $input['ext_cog_allowed_roles'] ) && is_array( $input['ext_cog_allowed_roles'] ) ) {
			$current['ext_cog_allowed_roles'] = array_map( 'sanitize_text_field', $input['ext_cog_allowed_roles'] );
		} elseif ( ! isset( $current['ext_cog_allowed_roles'] ) ) {
			$current['ext_cog_allowed_roles'] = array( 'administrator', 'editor' );
		}

		return $current;
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
			<h1><?php esc_html_e( 'Extended Cognition Toolkit Settings', 'mcp-ai-wpoos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure sensory inputs for AI agents — camera, microphone, screen capture, and motion sensors. Based on Clark & Chalmers (1998) extended mind theory: the AI\'s cognition extends to any reliable perceptual resource it can actively sense.', 'mcp-ai-wpoos' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments including 'setting_key' and 'description'.
	 * @return void
	 */
	public static function render_checkbox( $args ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$value = ! empty( $all[ $args['setting_key'] ] );
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
				value="1"
				<?php checked( $value ); ?> />
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a number field.
	 *
	 * @param array $args Field arguments including 'setting_key', 'min', 'max', 'description'.
	 * @return void
	 */
	public static function render_number( $args ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$value = isset( $all[ $args['setting_key'] ] ) ? $all[ $args['setting_key'] ] : '';
		?>
		<input type="number"
			name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
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
	 * @param array $args Field arguments including 'setting_key', 'options', 'description'.
	 * @return void
	 */
	public static function render_select( $args ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$value = isset( $all[ $args['setting_key'] ] ) ? $all[ $args['setting_key'] ] : '';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>">
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
WP_MCP_AI_Ext_Cog_Settings::init();
