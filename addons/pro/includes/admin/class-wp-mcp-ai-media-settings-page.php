<?php
/**
 * Media Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Media Design & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Media Settings Page
 */
class WP_MCP_AI_Media_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_media_settings';
		$this->post_type   = 'mcp_ai_media_tpl';
		$this->page_title  = __( 'Media Toolkit Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'media-toolkit-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Add settings submenu page.
	 */
	public function add_settings_page() {
		// Media templates are under upload.php (Media menu).
		add_submenu_page(
			'upload.php',
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

		// Add media-specific settings.
		add_settings_field(
			'enable_design_page',
			__( 'Enable Design & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_design_page_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_ai_design',
			__( 'Enable AI Design Generation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_ai_design_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_template_category',
			__( 'Default Template Category', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_template_category_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_smart_tagging',
			__( 'Enable Smart Tagging', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_smart_tagging_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'max_collection_size',
			__( 'Max Collection Size', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_max_collection_size_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		// Sharp Image Processing Settings.
		add_settings_field(
			'sharp_processing_mode',
			__( 'Sharp Processing Mode', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_sharp_processing_mode_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'sharp_microservice_url',
			__( 'Sharp Microservice URL', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_sharp_microservice_url_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'sharp_default_quality',
			__( 'Default Image Quality', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_sharp_default_quality_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'sharp_enable_webp',
			__( 'Enable WebP Conversion', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_sharp_enable_webp_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		// OCR Settings.
		add_settings_field(
			'enable_ocr',
			__( 'Enable OCR Service', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_ocr_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'ocr_primary_provider',
			__( 'Primary OCR Provider', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ocr_primary_provider_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_image_preprocess',
			__( 'Enable Image Preprocessing', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_image_preprocess_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable design page field.
	 */
	public function render_enable_design_page_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_design_page'] ) ? (bool) $options['enable_design_page'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_design_page]"
				id="enable_design_page"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Design & Add page for media template design', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Design & Add page to create media templates using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable AI design field.
	 */
	public function render_enable_ai_design_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_ai_design'] ) ? (bool) $options['enable_ai_design'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_ai_design]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Allow AI-powered design generation for media', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render default template category field.
	 */
	public function render_default_template_category_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_template_category'] ) ? $options['default_template_category'] : 'social_media';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_template_category]" class="regular-text">
			<option value="social_media" <?php selected( $value, 'social_media' ); ?>><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="blog_graphics" <?php selected( $value, 'blog_graphics' ); ?>><?php esc_html_e( 'Blog Graphics', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="marketing" <?php selected( $value, 'marketing' ); ?>><?php esc_html_e( 'Marketing Materials', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="presentations" <?php selected( $value, 'presentations' ); ?>><?php esc_html_e( 'Presentations', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default category when browsing templates', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable smart tagging field.
	 */
	public function render_enable_smart_tagging_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_smart_tagging'] ) ? (bool) $options['enable_smart_tagging'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_smart_tagging]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Automatically tag uploaded media using AI', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render max collection size field.
	 */
	public function render_max_collection_size_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['max_collection_size'] ) ? absint( $options['max_collection_size'] ) : 100;

		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_collection_size]" value="<?php echo esc_attr( $value ); ?>" min="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Maximum number of items per media collection', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Media Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Advanced media management with AI-powered design generation, template library, collection management, high-performance image processing with Sharp, and OCR capabilities.', 'mcp-ai-wpoos-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Design Generation: Create graphics and designs using AI-powered tools', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Template Library: Access and manage design templates for various use cases', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Media Collections: Organize media files into collections for better management', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Sharp Image Processing: High-performance image optimization, resizing, and format conversion', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'OCR: Extract text from images using Tesseract, OpenAI, Gemini, or Ollama', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk Operations: Perform batch operations on multiple media files', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Smart Tagging: Automatically tag media with AI-powered content recognition', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Remote Media Sync: Synchronize media across multiple WordPress sites', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<?php $this->render_media_packages_status_section(); ?>
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
			'generate_design_ai'      => __( 'Generate Design (AI)', 'mcp-ai-wpoos-pro' ),
			'browse_template_library' => __( 'Browse Template Library', 'mcp-ai-wpoos-pro' ),
			'apply_template'          => __( 'Apply Template', 'mcp-ai-wpoos-pro' ),
			'create_media_collection' => __( 'Create Media Collection', 'mcp-ai-wpoos-pro' ),
			'add_to_collection'       => __( 'Add to Collection', 'mcp-ai-wpoos-pro' ),
			'bulk_tag_media'          => __( 'Bulk Tag Media', 'mcp-ai-wpoos-pro' ),
			'bulk_resize_media'       => __( 'Bulk Resize Media', 'mcp-ai-wpoos-pro' ),
			'bulk_compress_media'     => __( 'Bulk Compress Media', 'mcp-ai-wpoos-pro' ),
			'sync_media_to_remote'    => __( 'Sync Media to Remote', 'mcp-ai-wpoos-pro' ),
			'import_media_from_url'   => __( 'Import Media from URL', 'mcp-ai-wpoos-pro' ),
			'generate_media_report'   => __( 'Generate Media Report', 'mcp-ai-wpoos-pro' ),
			'find_unused_media'       => __( 'Find Unused Media', 'mcp-ai-wpoos-pro' ),
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

		// Add media-specific sanitization.
		if ( isset( $input['enable_design_page'] ) ) {
			$sanitized['enable_design_page'] = (bool) $input['enable_design_page'];
		} else {
			$sanitized['enable_design_page'] = false;
		}

		if ( isset( $input['enable_ai_design'] ) ) {
			$sanitized['enable_ai_design'] = (bool) $input['enable_ai_design'];
		} else {
			$sanitized['enable_ai_design'] = false;
		}

		if ( isset( $input['default_template_category'] ) ) {
			$sanitized['default_template_category'] = sanitize_text_field( $input['default_template_category'] );
		}

		if ( isset( $input['enable_smart_tagging'] ) ) {
			$sanitized['enable_smart_tagging'] = (bool) $input['enable_smart_tagging'];
		} else {
			$sanitized['enable_smart_tagging'] = false;
		}

		if ( isset( $input['max_collection_size'] ) ) {
			$sanitized['max_collection_size'] = absint( $input['max_collection_size'] );
		}

		// Sharp settings sanitization.
		if ( isset( $input['sharp_processing_mode'] ) ) {
			$sanitized['sharp_processing_mode'] = sanitize_text_field( $input['sharp_processing_mode'] );
		}

		if ( isset( $input['sharp_microservice_url'] ) ) {
			$sanitized['sharp_microservice_url'] = esc_url_raw( $input['sharp_microservice_url'] );
		}

		if ( isset( $input['sharp_default_quality'] ) ) {
			$sanitized['sharp_default_quality'] = absint( $input['sharp_default_quality'] );
		}

		if ( isset( $input['sharp_enable_webp'] ) ) {
			$sanitized['sharp_enable_webp'] = (bool) $input['sharp_enable_webp'];
		} else {
			$sanitized['sharp_enable_webp'] = false;
		}

		// OCR settings sanitization.
		if ( isset( $input['enable_ocr'] ) ) {
			$sanitized['enable_ocr'] = (bool) $input['enable_ocr'];
		} else {
			$sanitized['enable_ocr'] = false;
		}

		if ( isset( $input['ocr_primary_provider'] ) ) {
			$sanitized['ocr_primary_provider'] = sanitize_text_field( $input['ocr_primary_provider'] );
		}

		if ( isset( $input['enable_image_preprocess'] ) ) {
			$sanitized['enable_image_preprocess'] = (bool) $input['enable_image_preprocess'];
		} else {
			$sanitized['enable_image_preprocess'] = false;
		}

		return $sanitized;
	}

	/**
	 * Render Sharp processing mode field.
	 */
	public function render_sharp_processing_mode_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['sharp_processing_mode'] ) ? $options['sharp_processing_mode'] : 'local';

		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_processing_mode]" value="local" <?php checked( $value, 'local' ); ?> />
				<?php esc_html_e( 'Local Processing', 'mcp-ai-wpoos-pro' ); ?>
			</label>
			<p class="description" style="margin-left: 25px; margin-top: 5px;">
				<?php esc_html_e( 'Process images using locally installed Sharp (Node.js). Requires Sharp to be installed via npm.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_processing_mode]" value="microservice" <?php checked( $value, 'microservice' ); ?> />
				<?php esc_html_e( 'Microservice (Remote API)', 'mcp-ai-wpoos-pro' ); ?>
			</label>
			<p class="description" style="margin-left: 25px; margin-top: 5px;">
				<?php esc_html_e( 'Process images via an external Node.js microservice. Useful for offloading processing to a dedicated server.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Render Sharp microservice URL field.
	 */
	public function render_sharp_microservice_url_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['sharp_microservice_url'] ) ? $options['sharp_microservice_url'] : '';

		?>
		<input type="url" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_microservice_url]" value="<?php echo esc_url( $value ); ?>" class="regular-text" placeholder="https://sharp-service.example.com/process" />
		<p class="description">
			<?php esc_html_e( 'Full URL to your Sharp microservice endpoint. Only used when Microservice mode is selected.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Sharp default quality field.
	 */
	public function render_sharp_default_quality_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['sharp_default_quality'] ) ? absint( $options['sharp_default_quality'] ) : 80;

		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_default_quality]" value="<?php echo esc_attr( $value ); ?>" min="1" max="100" class="small-text" />
		<span>%</span>
		<p class="description">
			<?php esc_html_e( 'Default quality for lossy compression (1-100). Lower values = smaller file sizes but reduced quality.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Sharp enable WebP field.
	 */
	public function render_sharp_enable_webp_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['sharp_enable_webp'] ) ? (bool) $options['sharp_enable_webp'] : false;

		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_enable_webp]" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Automatically generate WebP versions of images for better performance', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render enable OCR field.
	 */
	public function render_enable_ocr_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_ocr'] ) ? (bool) $options['enable_ocr'] : true;

		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_ocr]" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Enable OCR text extraction from images and PDFs', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Uses Tesseract.js (Node.js), OpenAI Vision, Gemini Vision, or Ollama for OCR processing', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render OCR primary provider field.
	 */
	public function render_ocr_primary_provider_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['ocr_primary_provider'] ) ? $options['ocr_primary_provider'] : 'tesseract';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[ocr_primary_provider]">
			<option value="tesseract" <?php selected( $value, 'tesseract' ); ?>><?php esc_html_e( 'Tesseract.js (Local/Free)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI GPT-4 Vision (API)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="gemini" <?php selected( $value, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini Vision (API)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="ollama" <?php selected( $value, 'ollama' ); ?>><?php esc_html_e( 'Ollama Vision Models (Local)', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Primary provider for OCR. Will fallback to other providers if primary fails.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable image preprocessing field.
	 */
	public function render_enable_image_preprocess_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_image_preprocess'] ) ? (bool) $options['enable_image_preprocess'] : true;

		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_image_preprocess]" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Preprocess images before OCR (improves accuracy)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Applies grayscale, normalization, sharpening, and noise reduction for better OCR results', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render media packages status section
	 */
	protected function render_media_packages_status_section() {
		?>
		<h3 style="margin-top: 30px;"><?php esc_html_e( 'Pro Packages Status', 'mcp-ai-wpoos-pro' ); ?></h3>
		<p><?php esc_html_e( 'View the status and availability of Node.js packages used by Media Toolkit features.', 'mcp-ai-wpoos-pro' ); ?></p>

		<?php $this->render_nodejs_status_section(); ?>
		<?php $this->render_media_packages_table(); ?>
		<?php
	}

	/**
	 * Render Node.js status section for packages
	 */
	protected function render_nodejs_status_section() {
		$nodejs_available = $this->check_nodejs_available();
		$nodejs_version   = $this->get_nodejs_version();

		?>
		<div class="nodejs-status" style="background: #f9f9f9; padding: 15px; border-left: 4px solid <?php echo $nodejs_available ? '#46b450' : '#dc3232'; ?>; margin: 20px 0;">
			<h4 style="margin-top: 0;">
				<?php echo $nodejs_available ? '✅' : '❌'; ?>
				<?php esc_html_e( 'Node.js Runtime', 'mcp-ai-wpoos-pro' ); ?>
			</h4>
			
			<?php if ( $nodejs_available ) : ?>
				<p>
					<strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code><?php echo esc_html( $nodejs_version ); ?></code>
				</p>
				<?php
				$min_version = '18.17.0';
				if ( version_compare( $this->parse_node_version( $nodejs_version ), $min_version, '<' ) ) :
					?>
					<p style="color: #dc3232;">
						<strong><?php esc_html_e( 'Warning:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php
						printf(
							/* translators: 1: Current version, 2: Minimum required version */
							esc_html__( 'Your Node.js version (%1$s) is below the recommended minimum (%2$s). Some packages may not work correctly.', 'mcp-ai-wpoos-pro' ),
							esc_html( $nodejs_version ),
							esc_html( $min_version )
						);
						?>
					</p>
				<?php else : ?>
					<p style="color: #46b450;">
						<?php esc_html_e( 'Node.js version meets all requirements for media toolkit packages.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p style="color: #dc3232;">
					<?php esc_html_e( 'Node.js is not installed or not accessible. Some media processing features will not be available.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Installation:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<a href="https://nodejs.org/" target="_blank"><?php esc_html_e( 'Download Node.js', 'mcp-ai-wpoos-pro' ); ?></a>
					(<?php esc_html_e( 'Requires v18.17.0 or higher', 'mcp-ai-wpoos-pro' ); ?>)
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render media packages status table
	 */
	protected function render_media_packages_table() {
		$packages = $this->get_media_package_definitions();

		?>
		<h4><?php esc_html_e( 'Media Toolkit Package Availability', 'mcp-ai-wpoos-pro' ); ?></h4>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 25%;"><?php esc_html_e( 'Package', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></th>
					<th style="width: 45%;"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $packages as $package ) : ?>
					<?php
					$status = $this->check_package_status( $package['name'] );
					$icon   = $status['available'] ? '✅' : ( $package['required'] ? '❌' : '⚠️' );
					$color  = $status['available'] ? 'green' : ( $package['required'] ? 'red' : 'orange' );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $package['label'] ); ?></strong>
							<br>
							<code style="font-size: 11px;"><?php echo esc_html( $package['name'] ); ?></code>
						</td>
						<td>
							<span style="color: <?php echo esc_attr( $color ); ?>;">
								<?php echo esc_html( $icon ); ?>
								<?php echo $status['available'] ? esc_html__( 'Available', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Missing', 'mcp-ai-wpoos-pro' ); ?>
							</span>
						</td>
						<td>
							<?php if ( $status['available'] ) : ?>
								<span style="font-size: 11px;">
									<?php echo esc_html( ucfirst( $status['source'] ) ); ?>
								</span>
							<?php else : ?>
								<span style="color: #666; font-size: 11px;">
									<?php echo $package['required'] ? esc_html__( 'Required', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Optional', 'mcp-ai-wpoos-pro' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $package['description'] ); ?>
							<?php if ( ! $status['available'] && ! empty( $package['install_hint'] ) ) : ?>
								<br>
								<span style="font-size: 11px; color: #666;">
									<em><?php echo esc_html( $package['install_hint'] ); ?></em>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd;">
			<h4><?php esc_html_e( 'Installation Instructions', 'mcp-ai-wpoos-pro' ); ?></h4>
			<p><?php esc_html_e( 'Most packages are pre-packaged in the plugin. To install missing packages:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li>
					<?php esc_html_e( 'Ensure Node.js 18.17.0+ is installed:', 'mcp-ai-wpoos-pro' ); ?>
					<code>node --version</code>
				</li>
				<li>
					<?php esc_html_e( 'Navigate to the pro addon directory:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?></code>
				</li>
				<li>
					<?php esc_html_e( 'Install dependencies:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>npm install --include=optional --legacy-peer-deps</code>
				</li>
				<li>
					<?php esc_html_e( 'Build vendor bundles:', 'mcp-ai-wpoos-pro' ); ?>
					<br><code>npm run build</code>
				</li>
			</ol>
			<p>
				<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Sharp requires platform-specific binaries and is pre-packaged for Linux x64. Other platforms need to run the install command above.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get media package definitions
	 *
	 * @return array
	 */
	protected function get_media_package_definitions() {
		return array(
			// Image Processing.
			array(
				'name'         => 'sharp',
				'label'        => 'Sharp',
				'description'  => __( 'High-performance image processing (resize, convert, optimize). Pre-packaged for Linux x64.', 'mcp-ai-wpoos-pro' ),
				'required'     => true,
				'install_hint' => __( 'Requires Node.js 18.17.0+. Pre-packaged for Linux x64, other platforms need npm install.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'canvas',
				'label'        => 'Canvas',
				'description'  => __( 'HTML5 Canvas implementation for server-side image generation and manipulation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Requires system dependencies (cairo, pango, etc.) for compilation.', 'mcp-ai-wpoos-pro' ),
			),

			// OCR & Computer Vision.
			array(
				'name'         => 'tesseract.js',
				'label'        => 'Tesseract.js',
				'description'  => __( 'Optical Character Recognition (OCR) for extracting text from images.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For OCR functionality in media tools.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Check package status
	 *
	 * @param string $package_name Package name.
	 * @return array
	 */
	protected function check_package_status( $package_name ) {
		// Use the centralized helper function.
		if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
			return wp_mcp_ai_get_npm_package_status( $package_name );
		}

		// Fallback if helper not available.
		$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
		if ( is_dir( $vendor_path ) || file_exists( $vendor_path . '/package.json' ) ) {
			return array(
				'available' => true,
				'source'    => 'vendor',
			);
		}

		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
		if ( is_dir( $node_modules_path ) || file_exists( $node_modules_path . '/package.json' ) ) {
			return array(
				'available' => true,
				'source'    => 'node_modules',
			);
		}

		return array(
			'available' => false,
			'source'    => '',
		);
	}

	/**
	 * Check if Node.js is available
	 *
	 * @return bool
	 */
	protected function check_nodejs_available() {
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		return 0 === $return && ! empty( $output );
	}

	/**
	 * Get Node.js version
	 *
	 * @return string
	 */
	protected function get_nodejs_version() {
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 === $return && ! empty( $output ) ) {
			return trim( $output[0] );
		}

		return __( 'Not Available', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Parse Node.js version string
	 *
	 * @param string $version Version string (e.g., 'v18.17.0').
	 * @return string Parsed version (e.g., '18.17.0').
	 */
	protected function parse_node_version( $version ) {
		// Remove 'v' prefix if present.
		return ltrim( $version, 'v' );
	}
}

// Initialize.
new WP_MCP_AI_Media_Settings_Page();
