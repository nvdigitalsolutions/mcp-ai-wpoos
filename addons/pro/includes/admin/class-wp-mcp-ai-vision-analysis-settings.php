<?php
/**
 * WP MCP AI Vision Analysis — Admin Settings
 *
 * Provides the WordPress admin settings page for the Vision Analysis Toolkit.
 * All settings are stored under the main `wp_mcp_ai_settings` option with
 * `va_` prefixed keys (or `enable_vision_analysis_toolkit` for the toggle).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page handler for the Vision Analysis Toolkit.
 *
 * @since 1.1.68
 */
class WP_MCP_AI_Vision_Analysis_Settings {

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
	const SETTINGS_GROUP = 'wp_mcp_ai_vision_analysis_settings_group';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-vision-analysis';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page under the NV oOS admin menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Vision Analysis Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'Vision Analysis', 'mcp-ai-wpoos-pro' ),
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
			'wp_mcp_ai_va_general',
			__( 'General', 'mcp-ai-wpoos-pro' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_vision_analysis_toolkit',
			__( 'Enable Toolkit', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_general',
			array(
				'setting_key' => 'enable_vision_analysis_toolkit',
				'description' => __( 'Enable the Vision Analysis Toolkit and register the analyze_image_objects tool.', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Detector section.
		add_settings_section(
			'wp_mcp_ai_va_detector',
			__( 'Detector', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Dedicated object detectors own the count. Boxes are counted per category — this is the most accurate counting path.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'va_detection_model',
			__( 'Detection Model (HF)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_detector',
			array(
				'setting_key' => 'va_detection_model',
				'description' => __( 'HuggingFace object-detection model ID. Default: google/owlv2-base-patch16. Supports OWLv2, YOLO, DETR, and any HF object-detection pipeline.', 'mcp-ai-wpoos-pro' ),
				'placeholder' => 'google/owlv2-base-patch16',
			)
		);

		add_settings_field(
			'va_min_confidence',
			__( 'Min Confidence', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_detector',
			array(
				'setting_key' => 'va_min_confidence',
				'min'         => 0.05,
				'max'         => 1.0,
				'step'        => 0.05,
				'description' => __( 'Default minimum confidence threshold (0.0–1.0). Detections below this are filtered out. Default: 0.5.', 'mcp-ai-wpoos-pro' ),
			)
		);

		// VLM section.
		add_settings_section(
			'wp_mcp_ai_va_vlm',
			__( 'Vision Language Model (VLM)', 'mcp-ai-wpoos-pro' ),
			function () {
				echo '<p>' . esc_html__( 'Optional chat vision model for open-world counting and hybrid label normalization. It renames mislabeled categories — it never overrides detector counts.', 'mcp-ai-wpoos-pro' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'va_vlm_provider',
			__( 'VLM Provider', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_select' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_vlm',
			array(
				'setting_key' => 'va_vlm_provider',
				'description' => __( 'Preferred chat vision provider for vlm mode and hybrid normalization. "auto" picks the first provider with a configured API key.', 'mcp-ai-wpoos-pro' ),
				'options'     => array(
					'auto'      => __( 'Auto', 'mcp-ai-wpoos-pro' ),
					'openai'    => 'OpenAI',
					'anthropic' => 'Anthropic',
					'gemini'    => 'Gemini',
				),
			)
		);

		add_settings_field(
			'va_vlm_model',
			__( 'VLM Model', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_text' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_vlm',
			array(
				'setting_key' => 'va_vlm_model',
				'description' => __( 'Optional explicit chat vision model (e.g. "gpt-4o-mini", "claude-sonnet-5", "gemini-2.5-flash"). Empty uses the provider default.', 'mcp-ai-wpoos-pro' ),
				'placeholder' => '',
			)
		);

		// Output section.
		add_settings_section(
			'wp_mcp_ai_va_output',
			__( 'Output', 'mcp-ai-wpoos-pro' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'va_annotate_default',
			__( 'Annotate by Default', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkbox' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_output',
			array(
				'setting_key' => 'va_annotate_default',
				'description' => __( 'Return an annotated copy of the image with labeled bounding boxes by default. Requires detector boxes and the PHP GD extension.', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'va_max_image_bytes',
			__( 'Max Image Size (bytes)', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_number' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_va_output',
			array(
				'setting_key' => 'va_max_image_bytes',
				'min'         => 1048576,
				'max'         => 10485760,
				'step'        => 262144,
				'description' => __( 'Maximum base64 payload sent to inference providers. Oversized images are downscaled first. Default: 5242880 (5 MB).', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * Validates the submitted va_ fields and merges them into the main
	 * wp_mcp_ai_settings option, preserving all other keys.
	 *
	 * @param array $input Raw input values.
	 * @return array Merged settings array.
	 */
	public static function sanitize_settings( $input ) {
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		$current['enable_vision_analysis_toolkit'] = ! empty( $input['enable_vision_analysis_toolkit'] );
		$current['va_annotate_default']            = ! empty( $input['va_annotate_default'] );

		$current['va_detection_model'] = isset( $input['va_detection_model'] )
			? sanitize_text_field( $input['va_detection_model'] )
			: 'google/owlv2-base-patch16';

		$current['va_vlm_model'] = isset( $input['va_vlm_model'] )
			? sanitize_text_field( $input['va_vlm_model'] )
			: '';

		$allowed_providers          = array( 'auto', 'openai', 'anthropic', 'gemini' );
		$submitted_provider         = isset( $input['va_vlm_provider'] ) ? $input['va_vlm_provider'] : 'auto';
		$current['va_vlm_provider'] = in_array( $submitted_provider, $allowed_providers, true )
			? sanitize_text_field( $submitted_provider )
			: 'auto';

		$current['va_min_confidence'] = isset( $input['va_min_confidence'] )
			? max( 0.0, min( 1.0, (float) $input['va_min_confidence'] ) )
			: 0.5;

		$current['va_max_image_bytes'] = isset( $input['va_max_image_bytes'] )
			? max( 1048576, min( 10485760, absint( $input['va_max_image_bytes'] ) ) )
			: 5242880;

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
			<h1><?php esc_html_e( 'Vision Analysis Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure image object detection and counting for AI agents. Dedicated detectors count bounding boxes per category; an optional chat vision model adds open-world counting and label normalization.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Privacy note: when using hosted inference (HuggingFace, OpenAI, Anthropic, Gemini), image bytes leave this site. Configure a local Ollama vision model to keep images on-premises.', 'mcp-ai-wpoos-pro' ); ?>
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
			step="<?php echo esc_attr( $args['step'] ?? '1' ); ?>"
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
}
