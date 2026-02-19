<?php
/**
 * Image Production Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Image Production functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Image Production Settings Page
 */
class WP_MCP_AI_Image_Production_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_image_production_settings';
		$this->post_type   = 'mcp_ai_image_tpl';
		$this->page_title  = __( 'Image Production Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Image Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'image-production-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Add settings submenu page.
	 */
	public function add_settings_page() {
		// Image templates have their own CPT menu.
		add_submenu_page(
			'edit.php?post_type=mcp_ai_image_tpl',
			$this->page_title,
			$this->menu_title,
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add image production-specific settings.
		add_settings_field(
			'default_image_generator',
			__( 'Default Image Generator', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_image_generator_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_output_format',
			__( 'Default Output Format', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_output_format_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'max_image_dimensions',
			__( 'Max Image Dimensions', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_max_image_dimensions_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render default image generator field.
	 */
	public function render_default_image_generator_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_image_generator'] ) ? $options['default_image_generator'] : 'dalle';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_image_generator]" class="regular-text">
			<option value="dalle" <?php selected( $value, 'dalle' ); ?>>DALL-E</option>
			<option value="midjourney" <?php selected( $value, 'midjourney' ); ?>>Midjourney</option>
			<option value="stable_diffusion" <?php selected( $value, 'stable_diffusion' ); ?>>Stable Diffusion</option>
		</select>
		<p class="description"><?php esc_html_e( 'Default AI service for image generation', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render default output format field.
	 */
	public function render_default_output_format_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_output_format'] ) ? $options['default_output_format'] : 'png';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_output_format]" class="regular-text">
			<option value="jpg" <?php selected( $value, 'jpg' ); ?>>JPEG</option>
			<option value="png" <?php selected( $value, 'png' ); ?>>PNG</option>
			<option value="webp" <?php selected( $value, 'webp' ); ?>>WebP</option>
		</select>
		<p class="description"><?php esc_html_e( 'Default format for processed images', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render max image dimensions field.
	 */
	public function render_max_image_dimensions_field() {
		$options = get_option( $this->option_name, array() );
		$width   = isset( $options['max_image_width'] ) ? absint( $options['max_image_width'] ) : 2048;
		$height  = isset( $options['max_image_height'] ) ? absint( $options['max_image_height'] ) : 2048;

		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_image_width]" value="<?php echo esc_attr( $width ); ?>" min="100" max="8192" class="small-text" />
		<span>×</span>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_image_height]" value="<?php echo esc_attr( $height ); ?>" min="100" max="8192" class="small-text" />
		<span>px</span>
		<p class="description"><?php esc_html_e( 'Maximum dimensions for generated images', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant and default settings for Image Production.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Image Production Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'AI-powered image creation and editing toolkit with 15 professional tools for generating, enhancing, and optimizing images.', 'mcp-ai-wpoos-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Image Generation: Create images from text descriptions using DALL-E, Midjourney, or Stable Diffusion', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Background Removal: Automatically remove backgrounds from product photos', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Upscaling: Enhance image resolution using AI-powered upscaling', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Batch Processing: Apply transformations to multiple images at once', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Style Transfer: Apply artistic styles to existing images', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Format Conversion: Convert between formats and optimize for web delivery', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'generate_image_ai'              => __( 'Generate Image (AI)', 'mcp-ai-wpoos-pro' ),
			'generate_image_variations'      => __( 'Generate Image Variations', 'mcp-ai-wpoos-pro' ),
			'image_inpainting'               => __( 'Image Inpainting', 'mcp-ai-wpoos-pro' ),
			'text_to_image_prompt_optimizer' => __( 'Text to Image Prompt Optimizer', 'mcp-ai-wpoos-pro' ),
			'remove_image_background'        => __( 'Remove Image Background', 'mcp-ai-wpoos-pro' ),
			'upscale_image_ai'               => __( 'Upscale Image (AI)', 'mcp-ai-wpoos-pro' ),
			'enhance_image_quality'          => __( 'Enhance Image Quality', 'mcp-ai-wpoos-pro' ),
			'apply_artistic_style'           => __( 'Apply Artistic Style', 'mcp-ai-wpoos-pro' ),
			'colorize_image'                 => __( 'Colorize Image', 'mcp-ai-wpoos-pro' ),
			'compress_image'                 => __( 'Compress Image', 'mcp-ai-wpoos-pro' ),
			'convert_image_format'           => __( 'Convert Image Format', 'mcp-ai-wpoos-pro' ),
			'resize_image_smart'             => __( 'Resize Image (Smart)', 'mcp-ai-wpoos-pro' ),
			'batch_process_images'           => __( 'Batch Process Images', 'mcp-ai-wpoos-pro' ),
			'generate_responsive_images'     => __( 'Generate Responsive Images', 'mcp-ai-wpoos-pro' ),
			'optimize_for_web'               => __( 'Optimize for Web', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add image production-specific sanitization.
		if ( isset( $input['default_image_generator'] ) ) {
			$sanitized['default_image_generator'] = sanitize_text_field( $input['default_image_generator'] );
		}

		if ( isset( $input['default_output_format'] ) ) {
			$sanitized['default_output_format'] = sanitize_text_field( $input['default_output_format'] );
		}

		if ( isset( $input['max_image_width'] ) ) {
			$sanitized['max_image_width'] = absint( $input['max_image_width'] );
		}

		if ( isset( $input['max_image_height'] ) ) {
			$sanitized['max_image_height'] = absint( $input['max_image_height'] );
		}

		return $sanitized;
	}
}

// Initialize - instantiated in image-production-toolkit-init.php.
