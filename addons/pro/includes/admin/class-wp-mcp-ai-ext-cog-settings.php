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
					'gemini-3.5-flash'     => 'Gemini 3.5 Flash',
					'gemini-3.1-pro'       => 'Gemini 3.1 Pro',
				),
			)
		);

		// --- Vision Recognition section (new in 1.8.0) ---
		add_settings_section(
			'wp_mcp_ai_ext_cog_vision_recognition',
			__( 'Vision Recognition', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Configure HuggingFace vision models for product/brand detection and the taxonomized brand catalogue used for zero-shot classification.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'ext_cog_hf_detection_model',
			__( 'Detection Model (HF)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_hf_detection_model',
				'description' => __( 'HuggingFace object-detection model ID. Default: google/owlv2-base-patch16.  Supports OWLv2, YOLO, DETR, and any HF object-detection pipeline.', 'mcp-ai-wpoos-pro' ),
				'placeholder' => 'google/owlv2-base-patch16',
			)
		);

		add_settings_field(
			'ext_cog_hf_classification_model',
			__( 'Classification Model (HF)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_hf_classification_model',
				'description' => __( 'HuggingFace zero-shot image classification model ID. Default: patrickjohncyh/fashion-clip.  Also supports openai/clip-vit-large-patch14 and other CLIP variants.', 'mcp-ai-wpoos-pro' ),
				'placeholder' => 'patrickjohncyh/fashion-clip',
			)
		);

		add_settings_field(
			'ext_cog_hf_embedding_model',
			__( 'Embedding Model (HF)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_hf_embedding_model',
				'description' => __( 'HuggingFace image feature-extraction model ID. Default: facebook/dinov2-large.  Used for the "similarity" search mode in recognize_products.', 'mcp-ai-wpoos-pro' ),
				'placeholder' => 'facebook/dinov2-large',
			)
		);

		add_settings_field(
			'ext_cog_min_detection_confidence',
			__( 'Min Detection Confidence', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_min_detection_confidence',
				'min'         => 0.1,
				'max'         => 1.0,
				'step'        => 0.05,
				'description' => __( 'Default minimum confidence threshold (0.0–1.0). Detections below this are filtered out. Default: 0.5.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'ext_cog_enable_video_analysis',
			__( 'Enable Video Feed Analysis', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_enable_video_analysis',
				'description' => __( 'Enable the analyze_video_feed tool.  Uses more compute and may dispatch background jobs via Action Scheduler.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'ext_cog_max_video_frames',
			__( 'Max Video Frames', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_max_video_frames',
				'min'         => 1,
				'max'         => 600,
				'description' => __( 'Maximum number of frames to extract and analyze from a single video.  Default: 60.  Frames beyond 30 are dispatched to Action Scheduler background jobs.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'ext_cog_brand_catalog',
			__( 'Default Brand Catalogue', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_textarea' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ext_cog_vision_recognition',
			array(
				'setting_key' => 'ext_cog_brand_catalog',
				'description' => __( 'Comma-separated brand names used as fallback zero-shot labels when no explicit labels are provided at tool-call time.  The Product Brands taxonomy (under the Ext. Cognition admin menu) is the primary catalogue; this textarea is a quick-start alternative.', 'mcp-ai-wpoos-pro' ),
				'rows'        => 6,
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

		$allowed_models                  = array( 'auto', 'gpt-4o', 'gpt-4-vision-preview', 'gemini-3.5-flash', 'gemini-3.1-pro' );
			$submitted_model                 = isset( $input['ext_cog_vision_model'] ) ? $input['ext_cog_vision_model'] : 'auto';
			$current['ext_cog_vision_model'] = in_array( $submitted_model, $allowed_models, true )
				? sanitize_text_field( $submitted_model )
				: 'auto';

			// Vision recognition settings (1.8.0).
			$current['ext_cog_hf_detection_model']      = isset( $input['ext_cog_hf_detection_model'] ) ? sanitize_text_field( $input['ext_cog_hf_detection_model'] ) : '';
			$current['ext_cog_hf_classification_model'] = isset( $input['ext_cog_hf_classification_model'] ) ? sanitize_text_field( $input['ext_cog_hf_classification_model'] ) : '';
			$current['ext_cog_hf_embedding_model']      = isset( $input['ext_cog_hf_embedding_model'] ) ? sanitize_text_field( $input['ext_cog_hf_embedding_model'] ) : '';

			$current['ext_cog_min_detection_confidence'] = isset( $input['ext_cog_min_detection_confidence'] )
				? max( 0.1, min( 1.0, (float) $input['ext_cog_min_detection_confidence'] ) )
				: 0.5;

			$current['ext_cog_enable_video_analysis'] = ! empty( $input['ext_cog_enable_video_analysis'] );

			$current['ext_cog_max_video_frames'] = isset( $input['ext_cog_max_video_frames'] )
				? max( 1, min( 600, absint( $input['ext_cog_max_video_frames'] ) ) )
				: 60;

			$current['ext_cog_brand_catalog'] = isset( $input['ext_cog_brand_catalog'] )
				? sanitize_textarea_field( $input['ext_cog_brand_catalog'] )
				: '';

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

	/**
	 * Render a text field.
	 *
	 * @param array $args Field arguments including 'setting_key', 'placeholder', 'description'.
	 * @return void
	 */
	public static function render_text( $args ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$value = isset( $all[ $args['setting_key'] ] ) ? $all[ $args['setting_key'] ] : '';
		?>
		<input type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"
			class="regular-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments including 'setting_key', 'rows', 'description'.
	 * @return void
	 */
	public static function render_textarea( $args ) {
		$all   = get_option( self::OPTION_NAME, array() );
		$value = isset( $all[ $args['setting_key'] ] ) ? $all[ $args['setting_key'] ] : '';
		$rows  = isset( $args['rows'] ) ? absint( $args['rows'] ) : 5;
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME . '[' . $args['setting_key'] . ']' ); ?>"
			rows="<?php echo esc_attr( $rows ); ?>"
			class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Ext_Cog_Settings::init();
