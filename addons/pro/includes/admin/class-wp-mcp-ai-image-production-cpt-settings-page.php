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

		add_settings_field(
			'nodejs_available',
			__( 'Node.js Status', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_nodejs_status_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		// OCR Settings.
		add_settings_field(
			'ocr_provider',
			__( 'OCR Provider', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_provider_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'ocr_fallback_provider',
			__( 'OCR Fallback Provider', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_fallback_provider_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'ocr_preprocessing',
			__( 'OCR Preprocessing', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_preprocessing_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'ocr_timeout',
			__( 'OCR Timeout', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_timeout_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'ocr_max_pages_default',
			__( 'OCR Max Pages Default', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_max_pages_default_field' ),
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
	 * Render Node.js status field.
	 */
	public function render_nodejs_status_field() {
		$nodejs_available      = $this->check_nodejs_available();
		$npm_packages          = $this->check_npm_packages_installed();
		$optional_npm_packages = $this->check_optional_npm_packages_installed();

		?>
		<p>
			<strong><?php esc_html_e( 'Node.js:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $nodejs_available ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: orange;">⚠ <?php esc_html_e( 'Not Available (PHP fallbacks will be used)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Core NPM Packages:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $npm_packages ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Available (sharp, canvas, qrcode via vendor or node_modules)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: orange;">⚠ <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos-pro' ); ?></span>
				<br>
				<code>cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?> && npm install</code>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Optional Packages:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $optional_npm_packages ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Available (gif-encoder for GIF creation)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: gray;">○ <?php esc_html_e( 'Not Available (optional - GIF generation)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Core packages enable advanced image processing features. Optional packages enhance functionality when available. PHP GD library and ImageMagick are used when Node.js packages are unavailable.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Check if Node.js is available.
	 *
	 * @return bool
	 */
	protected function check_nodejs_available() {
		// Simple check - try to run node --version.
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		return 0 === $return && ! empty( $output );
	}

	/**
	 * Check if NPM packages are installed.
	 *
	 * Checks CDN availability, vendor directory, and node_modules.
	 *
	 * @return bool
	 */
	protected function check_npm_packages_installed() {
		// Use the centralized helper function for CDN-aware package checking.
		$has_sharp = wp_mcp_ai_is_npm_package_available( 'sharp' );

		$has_canvas = wp_mcp_ai_is_npm_package_available( 'canvas' );

		$has_qrcode = wp_mcp_ai_is_npm_package_available( 'qrcode' );

		return $has_sharp && $has_canvas && $has_qrcode;
	}

	/**
	 * Check if optional NPM packages are installed.
	 *
	 * Checks CDN availability, vendor directory, and node_modules.
	 *
	 * @return bool
	 */
	protected function check_optional_npm_packages_installed() {
		return wp_mcp_ai_is_npm_package_available( 'gif-encoder' );
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for image template research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create image templates using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render OCR provider field.
	 */
	public function render_ocr_provider_field() {
		$options       = get_option( $this->option_name, array() );
		$value         = isset( $options['ocr_provider'] ) ? $options['ocr_provider'] : 'auto';
		$main_settings = get_option( 'wp_mcp_ai_settings', array() );

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[ocr_provider]" class="regular-text">
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Auto (Detect Best Available)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="openai" <?php selected( $value, 'openai' ); ?> <?php disabled( empty( $main_settings['openai_api_key'] ) ); ?>>
				<?php esc_html_e( 'OpenAI GPT-4 Vision', 'mcp-ai-wpoos-pro' ); ?>
				<?php if ( empty( $main_settings['openai_api_key'] ) ) : ?>
					<?php esc_html_e( '(API Key Required)', 'mcp-ai-wpoos-pro' ); ?>
				<?php endif; ?>
			</option>
			<option value="gemini" <?php selected( $value, 'gemini' ); ?> <?php disabled( empty( $main_settings['gemini_api_key'] ) ); ?>>
				<?php esc_html_e( 'Google Gemini Vision', 'mcp-ai-wpoos-pro' ); ?>
				<?php if ( empty( $main_settings['gemini_api_key'] ) ) : ?>
					<?php esc_html_e( '(API Key Required)', 'mcp-ai-wpoos-pro' ); ?>
				<?php endif; ?>
			</option>
			<option value="ollama" <?php selected( $value, 'ollama' ); ?> <?php disabled( empty( $main_settings['ollama_endpoint'] ) ); ?>>
				<?php esc_html_e( 'Ollama Vision Models (Local)', 'mcp-ai-wpoos-pro' ); ?>
				<?php if ( empty( $main_settings['ollama_endpoint'] ) ) : ?>
					<?php esc_html_e( '(Endpoint Required)', 'mcp-ai-wpoos-pro' ); ?>
				<?php endif; ?>
			</option>
			<option value="tesseract" <?php selected( $value, 'tesseract' ); ?>>
				<?php esc_html_e( 'Tesseract OCR (System)', 'mcp-ai-wpoos-pro' ); ?>
			</option>
		</select>
		<p class="description">
			<?php
			esc_html_e( 'Select the OCR provider for extracting text from images. Used by image text extraction tools. Auto mode automatically selects the best available provider.', 'mcp-ai-wpoos-pro' );
			echo '<br>';
			/* translators: %s: Settings page URL */
			printf(
				esc_html__( 'Configure API keys in %s', 'mcp-ai-wpoos-pro' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ) . '">' . esc_html__( 'Provider Settings', 'mcp-ai-wpoos-pro' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render OCR fallback provider field.
	 */
	public function render_ocr_fallback_provider_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['ocr_fallback_provider'] ) ? $options['ocr_fallback_provider'] : 'auto';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[ocr_fallback_provider]" class="regular-text">
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Auto (Try All Available)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI GPT-4 Vision', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="gemini" <?php selected( $value, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini Vision', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="ollama" <?php selected( $value, 'ollama' ); ?>><?php esc_html_e( 'Ollama Vision Models', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="tesseract" <?php selected( $value, 'tesseract' ); ?>><?php esc_html_e( 'Tesseract OCR', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'None (No Fallback)', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'If the primary provider fails, this provider will be used as fallback. Auto mode tries all available providers in order.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render OCR preprocessing field.
	 */
	public function render_ocr_preprocessing_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['ocr_preprocessing'] ) ? (bool) $options['ocr_preprocessing'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[ocr_preprocessing]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable image preprocessing (grayscale, contrast, noise reduction)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Preprocessing improves OCR accuracy for low-quality images. Disable if images are already optimized.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render OCR timeout field.
	 */
	public function render_ocr_timeout_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['ocr_timeout'] ) ? absint( $options['ocr_timeout'] ) : 300;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[ocr_timeout]"
			value="<?php echo esc_attr( $value ); ?>"
			min="30"
			max="600"
			step="30"
			class="small-text"
		/>
		<?php esc_html_e( 'seconds', 'mcp-ai-wpoos-pro' ); ?>
		<p class="description">
			<?php esc_html_e( 'Maximum time to wait for OCR processing before timing out. Range: 30-600 seconds.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render OCR max pages default field.
	 */
	public function render_ocr_max_pages_default_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['ocr_max_pages_default'] ) ? absint( $options['ocr_max_pages_default'] ) : 10;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[ocr_max_pages_default]"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			max="100"
			step="1"
			class="small-text"
		/>
		<?php esc_html_e( 'pages', 'mcp-ai-wpoos-pro' ); ?>
		<p class="description">
			<?php esc_html_e( 'Default maximum number of pages to process with OCR for multi-page images/PDFs. OCR is resource-intensive; limiting pages prevents timeouts. Individual tools can override this setting. Set to 0 for unlimited (not recommended).', 'mcp-ai-wpoos-pro' ); ?>
		</p>
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

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_research'] = false;
		}

		// OCR settings sanitization.
		if ( isset( $input['ocr_provider'] ) ) {
			$sanitized['ocr_provider'] = sanitize_text_field( $input['ocr_provider'] );
		}

		if ( isset( $input['ocr_fallback_provider'] ) ) {
			$sanitized['ocr_fallback_provider'] = sanitize_text_field( $input['ocr_fallback_provider'] );
		}

		if ( isset( $input['ocr_preprocessing'] ) ) {
			$sanitized['ocr_preprocessing'] = (bool) $input['ocr_preprocessing'];
		} else {
			// Checkbox not checked.
			$sanitized['ocr_preprocessing'] = false;
		}

		if ( isset( $input['ocr_timeout'] ) ) {
			$sanitized['ocr_timeout'] = absint( $input['ocr_timeout'] );
		}

		if ( isset( $input['ocr_max_pages_default'] ) ) {
			$value = absint( $input['ocr_max_pages_default'] );
			// Enforce min/max bounds.
			$sanitized['ocr_max_pages_default'] = min( 100, max( 0, $value ) );
		}

		return $sanitized;
	}
}

// Initialize - instantiated in image-production-toolkit-init.php.
