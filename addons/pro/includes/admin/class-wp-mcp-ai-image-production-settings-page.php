<?php
/**
 * Image Production Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Image Production Toolkit Settings Page Class
 */
class WP_MCP_AI_Image_Production_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'image_production';
		$this->toolkit_name     = __( 'Image Production Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_image_production_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-image-production-toolkit-settings';
		$this->has_research     = false;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-format-image';

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
			<h2><?php esc_html_e( 'Image Production Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered image creation and editing toolkit with 15 professional tools for generating, enhancing, and optimizing images.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

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
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Image Production Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Image Generator', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_image_generator" class="regular-text">
							<option value="dalle">DALL-E</option>
							<option value="midjourney">Midjourney</option>
							<option value="stable_diffusion">Stable Diffusion</option>
						</select>
						<p class="description"><?php esc_html_e( 'Default AI service for image generation', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Output Format', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_output_format" class="regular-text">
							<option value="jpg">JPEG</option>
							<option value="png">PNG</option>
							<option value="webp">WebP</option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format for processed images', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Image Dimensions', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="max_image_width" value="2048" min="100" class="small-text" />
						<span>×</span>
						<input type="number" name="max_image_height" value="2048" min="100" class="small-text" />
						<span>px</span>
						<p class="description"><?php esc_html_e( 'Maximum dimensions for generated images', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
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
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Image_Production_Settings_Page();
}
