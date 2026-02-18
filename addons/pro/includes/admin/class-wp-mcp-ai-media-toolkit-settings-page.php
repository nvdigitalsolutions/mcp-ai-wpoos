<?php
/**
 * Media Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Media Toolkit Settings Page Class
 */
class WP_MCP_AI_Media_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'media';
		$this->toolkit_name     = __( 'Media Settings', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_media_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-media-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-admin-media';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Media Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Advanced media management with AI-powered design generation, template library, collection management, and high-performance image optimization powered by Sharp.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Design Generation: Create graphics and designs using AI-powered tools', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Template Library: Access and manage design templates for various use cases', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Media Collections: Organize media files into collections for better management', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Sharp Image Processing: High-performance image optimization, resizing, and modern format conversion (WebP, AVIF)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk Operations: Perform batch operations on multiple media files', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Smart Tagging: Automatically tag media with AI-powered content recognition', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Remote Media Sync: Synchronize media across multiple WordPress sites', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Sharp Image Processing', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Sharp is a high-performance Node.js image processing library built on libvips. It provides significantly faster image processing than ImageMagick or GD for operations like:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'High-speed image resizing and optimization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Modern format conversion (WebP, AVIF) with superior compression', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Advanced image operations (blur, sharpen, rotate, crop)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Batch processing with concurrent operations', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Processing Modes', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'The Media Toolkit supports two Sharp processing modes:', 'mcp-ai-wpoos-pro' ); ?></p>
			<table class="widefat" style="max-width: 800px; margin: 15px 0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mode', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Best For', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Local Processing', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Process images directly on this server using locally installed Sharp via Node.js', 'mcp-ai-wpoos-pro' ); ?></td>
						<td><?php esc_html_e( 'Single-server setups, development environments, small-to-medium workloads', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Microservice Mode', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Offload image processing to a dedicated Sharp microservice API running on a separate server', 'mcp-ai-wpoos-pro' ); ?></td>
						<td><?php esc_html_e( 'Production environments, high-volume processing, distributed architectures, load balancing', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Requirements & Status', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat" style="max-width: 800px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Component', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>Node.js</strong></td>
						<td>Runtime</td>
						<td><?php echo $this->check_nodejs_available() ? '<span style="color: green;">✓ Available (' . esc_html( $this->get_nodejs_version() ) . ')</span>' : '<span style="color: orange;">⚠ Not Detected</span>'; ?></td>
						<td><?php esc_html_e( 'Required for local Sharp processing', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>Sharp Package</strong></td>
						<td>NPM (Bundled)</td>
						<td><?php echo $this->check_sharp_library_exists() ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Pre-bundled for Linux x64 in assets/vendor/sharp/', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>Sharp Dependencies</strong></td>
						<td>NPM (detect-libc, color, semver)</td>
						<td><?php echo $this->check_sharp_dependencies_exist() ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Required JavaScript dependencies for Sharp', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>Platform Binaries</strong></td>
						<td>Native (libvips)</td>
						<td><?php echo $this->check_sharp_platform_binaries() ? '<span style="color: green;">✓ Available (' . esc_html( $this->get_detected_platform() ) . ')</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Platform-specific binaries for image processing', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>Sharp Ready</strong></td>
						<td>Full Stack</td>
						<td><?php echo $this->check_sharp_fully_ready() ? '<span style="color: green;">✓ Ready</span>' : '<span style="color: orange;">⚠ Incomplete</span>'; ?></td>
						<td><?php esc_html_e( 'All components available for local processing', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
				</tbody>
			</table>
			
			<p style="margin-top: 15px;">
				<strong><?php esc_html_e( 'Installation Status:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<?php if ( $this->check_sharp_fully_ready() ) : ?>
					<span style="color: green; font-size: 14px;">✓ <?php esc_html_e( 'Sharp is fully configured and ready to use for local image processing!', 'mcp-ai-wpoos-pro' ); ?></span>
				<?php elseif ( $this->check_sharp_library_exists() ) : ?>
					<span style="color: orange; font-size: 14px;">⚠ <?php esc_html_e( 'Sharp is partially installed. Some dependencies or binaries may be missing. Run:', 'mcp-ai-wpoos-pro' ); ?></span>
					<br><code style="display: block; margin: 10px 0; padding: 10px; background: #f5f5f5;">cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?><br>npm install --include=optional<br>npm run build</code>
				<?php else : ?>
					<span style="color: orange; font-size: 14px;">⚠ <?php esc_html_e( 'Sharp is not installed. To enable local processing, install Sharp by running:', 'mcp-ai-wpoos-pro' ); ?></span>
					<br><code style="display: block; margin: 10px 0; padding: 10px; background: #f5f5f5;">cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?><br>npm install --include=optional<br>npm run build</code>
				<?php endif; ?>
				<br>
				<span style="color: #666; font-size: 13px;">
					<?php esc_html_e( 'Alternatively, configure microservice mode in the Configuration tab to use a remote Sharp API without local installation.', 'mcp-ai-wpoos-pro' ); ?>
				</span>
			</p>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Blog and marketing graphics creation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Social media content generation and optimization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk image optimization for site performance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Automated WebP/AVIF conversion for modern browsers', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Media library organization with collections', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Multi-site media synchronization', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><code>addons/pro/docs/SHARP_SETUP_GUIDE.md</code> - Complete Sharp installation guide</li>
				<li><code>addons/pro/docs/INTEGRATION_BEST_PRACTICES.md</code> - Sharp integration patterns</li>
				<li><code>docs/tools/pro/media-toolkit.md</code> - Tool reference</li>
			</ul>

			<p style="margin-top: 15px;">
				<?php
				printf(
					/* translators: %s: Link to configuration tab */
					esc_html__( 'Configure Sharp processing mode and settings in the %s tab.', 'mcp-ai-wpoos-pro' ),
					'<a href="' . esc_url( add_query_arg( 'tab', 'configuration', admin_url( 'admin.php?page=' . $this->page_slug ) ) ) . '"><strong>' . esc_html__( 'Configuration', 'mcp-ai-wpoos-pro' ) . '</strong></a>'
				);
				?>
			</p>

			<?php $this->render_media_packages_status_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		$options = get_option( $this->option_name, array() );
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Media Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable AI Design Generation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_ai_design]" value="1" <?php checked( $options['enable_ai_design'] ?? false, 1 ); ?> />
							<?php esc_html_e( 'Allow AI-powered design generation for media', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Template Category', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_template_category]" class="regular-text">
							<option value="social_media" <?php selected( $options['default_template_category'] ?? 'social_media', 'social_media' ); ?>><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="blog_graphics" <?php selected( $options['default_template_category'] ?? 'social_media', 'blog_graphics' ); ?>><?php esc_html_e( 'Blog Graphics', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="marketing" <?php selected( $options['default_template_category'] ?? 'social_media', 'marketing' ); ?>><?php esc_html_e( 'Marketing Materials', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="presentations" <?php selected( $options['default_template_category'] ?? 'social_media', 'presentations' ); ?>><?php esc_html_e( 'Presentations', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default category when browsing templates', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Smart Tagging', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_smart_tagging]" value="1" <?php checked( $options['enable_smart_tagging'] ?? false, 1 ); ?> />
							<?php esc_html_e( 'Automatically tag uploaded media using AI', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Remote Media Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_remote_sync]" value="1" <?php checked( $options['enable_remote_sync'] ?? false, 1 ); ?> />
							<?php esc_html_e( 'Synchronize media with remote WordPress sites (requires Remote Sites toolkit)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Collection Size', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_collection_size]" value="<?php echo esc_attr( $options['max_collection_size'] ?? '100' ); ?>" min="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum number of items per media collection', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sharp Image Processing Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure how Sharp image processing operates. Sharp provides high-performance image optimization, resizing, and format conversion.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sharp Processing Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_processing_mode]" value="local" <?php checked( $options['sharp_processing_mode'] ?? 'local', 'local' ); ?> />
								<?php esc_html_e( 'Local Processing', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description" style="margin-left: 25px; margin-top: 5px;">
								<?php esc_html_e( 'Process images using locally installed Sharp (Node.js). Requires Sharp to be installed via npm.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<br>
							<label>
								<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_processing_mode]" value="microservice" <?php checked( $options['sharp_processing_mode'] ?? 'local', 'microservice' ); ?> />
								<?php esc_html_e( 'Microservice (Remote API)', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description" style="margin-left: 25px; margin-top: 5px;">
								<?php esc_html_e( 'Process images via an external Node.js microservice. Useful for offloading processing to a dedicated server.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr class="sharp-microservice-settings" style="<?php echo ( isset( $options['sharp_processing_mode'] ) && 'microservice' === $options['sharp_processing_mode'] ) ? '' : 'display: none;'; ?>">
					<th scope="row"><?php esc_html_e( 'Microservice URL', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="url" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_microservice_url]" value="<?php echo esc_url( $options['sharp_microservice_url'] ?? '' ); ?>" class="regular-text" placeholder="https://sharp-service.example.com/process" />
						<p class="description">
							<?php esc_html_e( 'Full URL to your Sharp microservice endpoint (e.g., https://sharp.yourdomain.com/process)', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr class="sharp-microservice-settings" style="<?php echo ( isset( $options['sharp_processing_mode'] ) && 'microservice' === $options['sharp_processing_mode'] ) ? '' : 'display: none;'; ?>">
					<th scope="row"><?php esc_html_e( 'Microservice API Key', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_microservice_api_key]" value="<?php echo esc_attr( $options['sharp_microservice_api_key'] ?? '' ); ?>" class="regular-text" placeholder="Your API key" autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Optional authentication key for the microservice (if required)', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Processing Timeout', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_timeout]" value="<?php echo esc_attr( $options['sharp_timeout'] ?? '60' ); ?>" min="10" max="300" class="small-text" />
						<span><?php esc_html_e( 'seconds', 'mcp-ai-wpoos-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Maximum time to wait for Sharp processing to complete. Increase for very large images.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Image Quality', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_default_quality]" value="<?php echo esc_attr( $options['sharp_default_quality'] ?? '80' ); ?>" min="1" max="100" class="small-text" />
						<span>%</span>
						<p class="description">
							<?php esc_html_e( 'Default quality for lossy compression (1-100). Lower values = smaller file sizes but reduced quality.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable WebP Conversion', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_enable_webp]" value="1" <?php checked( $options['sharp_enable_webp'] ?? false, 1 ); ?> />
							<?php esc_html_e( 'Automatically generate WebP versions of images for better performance', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Concurrent Operations', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[sharp_max_concurrent]" value="<?php echo esc_attr( $options['sharp_max_concurrent'] ?? '3' ); ?>" min="1" max="10" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Maximum number of simultaneous Sharp operations. Lower values reduce server load.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'OCR & Image Preprocessing Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure OCR (Optical Character Recognition) and image preprocessing services for extracting text from images and scanned PDFs.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable OCR Service', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_ocr]" value="1" <?php checked( $options['enable_ocr'] ?? true, 1 ); ?> />
							<?php esc_html_e( 'Enable OCR text extraction from images and PDFs', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uses Tesseract.js (Node.js), OpenAI Vision, or Gemini Vision for OCR processing', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Primary OCR Provider', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[ocr_primary_provider]">
							<option value="tesseract" <?php selected( $options['ocr_primary_provider'] ?? 'tesseract', 'tesseract' ); ?>><?php esc_html_e( 'Tesseract.js (Local/Free)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="openai" <?php selected( $options['ocr_primary_provider'] ?? 'tesseract', 'openai' ); ?>><?php esc_html_e( 'OpenAI GPT-4 Vision (API)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="gemini" <?php selected( $options['ocr_primary_provider'] ?? 'tesseract', 'gemini' ); ?>><?php esc_html_e( 'Google Gemini Vision (API)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="ollama" <?php selected( $options['ocr_primary_provider'] ?? 'tesseract', 'ollama' ); ?>><?php esc_html_e( 'Ollama Vision Models (Local)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Primary provider for OCR. Will fallback to other providers if primary fails.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Image Preprocessing', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_image_preprocess]" value="1" <?php checked( $options['enable_image_preprocess'] ?? true, 1 ); ?> />
							<?php esc_html_e( 'Preprocess images before OCR (improves accuracy)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Applies grayscale, normalization, sharpening, and noise reduction for better OCR results', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OCR Max Image Dimension', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[ocr_max_dimension]" value="<?php echo esc_attr( $options['ocr_max_dimension'] ?? '2048' ); ?>" min="512" max="4096" class="small-text" />
						<span><?php esc_html_e( 'pixels', 'mcp-ai-wpoos-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Maximum image width/height for OCR processing. Images will be resized if larger.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Batch Processing Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings for batch image operations and collection processing.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Batch Processing Chunk Size', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[batch_chunk_size]" value="<?php echo esc_attr( $options['batch_chunk_size'] ?? '10' ); ?>" min="1" max="50" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Number of images to process in each batch. Lower values reduce memory usage.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Background Processing', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_background_processing]" value="1" <?php checked( $options['enable_background_processing'] ?? true, 1 ); ?> />
							<?php esc_html_e( 'Process large batches in the background using WordPress cron', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Batch Operation Timeout', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[batch_timeout]" value="<?php echo esc_attr( $options['batch_timeout'] ?? '300' ); ?>" min="60" max="3600" class="small-text" />
						<span><?php esc_html_e( 'seconds', 'mcp-ai-wpoos-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Maximum time for batch operations. Increase for large batches.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Storage & Cleanup Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure storage locations and automatic cleanup policies for processed media.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Temporary Files Location', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[temp_storage_path]" value="<?php echo esc_attr( $options['temp_storage_path'] ?? 'wp-content/uploads/media-temp' ); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'Relative path from WordPress root for temporary processing files', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Cleanup Temporary Files', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[auto_cleanup_temp]" value="1" <?php checked( $options['auto_cleanup_temp'] ?? true, 1 ); ?> />
							<?php esc_html_e( 'Automatically delete temporary files after processing', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Temp Files Retention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[temp_retention_hours]" value="<?php echo esc_attr( $options['temp_retention_hours'] ?? '24' ); ?>" min="1" max="168" class="small-text" />
						<span><?php esc_html_e( 'hours', 'mcp-ai-wpoos-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'How long to keep temporary files before automatic cleanup (1-168 hours)', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Toggle microservice settings visibility
				$('input[name="<?php echo esc_js( $this->option_name ); ?>[sharp_processing_mode]"]').on('change', function() {
					if ($(this).val() === 'microservice') {
						$('.sharp-microservice-settings').show();
					} else {
						$('.sharp-microservice-settings').hide();
					}
				});

				// Trigger on page load to show/hide based on saved value
				$('input[name="<?php echo esc_js( $this->option_name ); ?>[sharp_processing_mode]"]:checked').trigger('change');
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Media Template Tools.
			'list_media_templates'    => __( 'List Media Templates', 'mcp-ai-wpoos-pro' ),
			'create_media_template'   => __( 'Create Media Template', 'mcp-ai-wpoos-pro' ),
			'apply_media_template'    => __( 'Apply Media Template', 'mcp-ai-wpoos-pro' ),

			// Media Collection Tools.
			'create_media_collection' => __( 'Create Media Collection', 'mcp-ai-wpoos-pro' ),
			'process_collection'      => __( 'Process Collection', 'mcp-ai-wpoos-pro' ),
			'apply_collection_template' => __( 'Apply Collection Template', 'mcp-ai-wpoos-pro' ),

			// Sharp Image Processing.
			'optimize_image_sharp'    => __( 'Optimize Image with Sharp', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check if Node.js is available
	 *
	 * @return bool
	 */
	private function check_nodejs_available() {
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
	private function get_nodejs_version() {
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 === $return && ! empty( $output ) ) {
			return trim( $output[0] );
		}

		return __( 'Not Available', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Check if Sharp library exists
	 *
	 * @return bool
	 */
	private function check_sharp_library_exists() {
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/lib/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/lib/index.js';

		return file_exists( $vendor_path ) || file_exists( $node_modules_path );
	}

	/**
	 * Check if Sharp dependencies exist
	 *
	 * @return bool
	 */
	private function check_sharp_dependencies_exist() {
		// Check in vendor directory.
		$vendor_base = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/node_modules/';
		if ( is_dir( $vendor_base . 'detect-libc' ) &&
		     is_dir( $vendor_base . 'color' ) &&
		     is_dir( $vendor_base . 'semver' ) ) {
			return true;
		}

		// Check in node_modules directory.
		$node_modules_base = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/node_modules/';
		return is_dir( $node_modules_base . 'detect-libc' ) &&
		       is_dir( $node_modules_base . 'color' ) &&
		       is_dir( $node_modules_base . 'semver' );
	}

	/**
	 * Check if Sharp platform binaries exist
	 *
	 * @return bool
	 */
	private function check_sharp_platform_binaries() {
		// Check in vendor directory.
		$vendor_binaries = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/node_modules/@img';
		if ( is_dir( $vendor_binaries ) ) {
			// Check if any platform binaries exist.
			$binaries = glob( $vendor_binaries . '/sharp-*' );
			if ( ! empty( $binaries ) ) {
				return true;
			}
		}

		// Check in node_modules directory.
		$node_modules_binaries = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/node_modules/@img';
		if ( is_dir( $node_modules_binaries ) ) {
			$binaries = glob( $node_modules_binaries . '/sharp-*' );
			return ! empty( $binaries );
		}

		return false;
	}

	/**
	 * Get detected platform for Sharp binaries
	 *
	 * @return string
	 */
	private function get_detected_platform() {
		// Check vendor directory first.
		$vendor_binaries = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/node_modules/@img';
		if ( is_dir( $vendor_binaries ) ) {
			$binaries = glob( $vendor_binaries . '/sharp-*' );
			if ( ! empty( $binaries ) ) {
				$platform_dir = basename( $binaries[0] );
				// Extract platform from directory name (e.g., 'sharp-linux-x64' -> 'Linux x64').
				$platform = str_replace( 'sharp-', '', $platform_dir );
				$platform = str_replace( '-', ' ', $platform );
				return ucwords( $platform );
			}
		}

		// Check node_modules directory.
		$node_modules_binaries = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/node_modules/@img';
		if ( is_dir( $node_modules_binaries ) ) {
			$binaries = glob( $node_modules_binaries . '/sharp-*' );
			if ( ! empty( $binaries ) ) {
				$platform_dir = basename( $binaries[0] );
				$platform     = str_replace( 'sharp-', '', $platform_dir );
				$platform     = str_replace( '-', ' ', $platform );
				return ucwords( $platform );
			}
		}

		return __( 'Unknown', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Check if Sharp is fully ready (all components available)
	 *
	 * @return bool
	 */
	private function check_sharp_fully_ready() {
		return $this->check_nodejs_available() &&
		       $this->check_sharp_library_exists() &&
		       $this->check_sharp_dependencies_exist() &&
		       $this->check_sharp_platform_binaries();
	}

	/**
	 * Render media packages status section
	 */
	protected function render_media_packages_status_section() {
		?>
		<h3 style="margin-top: 30px;"><?php esc_html_e( 'Pro Packages Status', 'mcp-ai-wpoos-pro' ); ?></h3>
		<p><?php esc_html_e( 'View the status and availability of Node.js packages used by Media Toolkit features.', 'mcp-ai-wpoos-pro' ); ?></p>

		<?php $this->render_media_packages_table(); ?>
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
					$status = $this->check_media_package_status( $package['name'] );
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

			// Data Visualization.
			array(
				'name'         => 'chart.js',
				'label'        => 'Chart.js',
				'description'  => __( 'Data visualization and chart generation (line, bar, pie, etc.).', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Enhances data visualization capabilities.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'name'         => 'd3',
				'label'        => 'D3.js',
				'description'  => __( 'Advanced data visualization and custom chart generation.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For complex custom visualizations.', 'mcp-ai-wpoos-pro' ),
			),

			// OCR & Computer Vision.
			array(
				'name'         => 'tesseract.js',
				'label'        => 'Tesseract.js',
				'description'  => __( 'Optical Character Recognition (OCR) for extracting text from images.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'For OCR functionality in media tools.', 'mcp-ai-wpoos-pro' ),
			),

			// Optional Advanced Packages.
			array(
				'name'         => 'ffmpeg-static',
				'label'        => 'FFmpeg Static',
				'description'  => __( 'Static FFmpeg binary for video processing and conversion.', 'mcp-ai-wpoos-pro' ),
				'required'     => false,
				'install_hint' => __( 'Optional - for video processing tools.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Check media package status
	 *
	 * @param string $package_name Package name.
	 * @return array
	 */
	protected function check_media_package_status( $package_name ) {
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
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Media_Toolkit_Settings_Page();
}
