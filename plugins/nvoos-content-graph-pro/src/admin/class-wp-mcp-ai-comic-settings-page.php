<?php
/**
 * WP_MCP_AI_Comic_Settings_Page (ecosystem port - Wave F2, comic-creation admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the `src/` root for the toolkit-settings-base require.
 *
 * Comic Creation toolkit settings page.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Comic_Creation_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Comic Creation Settings Page
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Comic_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->toolkit_slug = 'comic_creation'; // Kebab-converts to 'comic-creation' for MCP server lookup.
		$this->toolkit_name = __( 'Comic Creation', 'nvoos-content-graph-pro' );
		$this->option_name  = 'wp_mcp_ai_comic_creation_settings';
		$this->page_slug    = 'comic-creation-settings';
		$this->icon         = 'dashicons-format-image';
		$this->has_research = true;

		parent::__construct();
	}

	/**
	 * Get toolkit slug.
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name.
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab.
	 *
	 * @since 2.0.0
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Comic Creation Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			<p><?php esc_html_e( 'AI-powered comic book creation toolkit for generating scripts, characters, panels, and complete comic books with professional layouts and export.', 'nvoos-content-graph-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Script Generation: AI writes full comic scripts with scene breakdowns from a simple premise', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Character Consistency: Generate reference sheets and maintain visual consistency across panels', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Panel-by-Panel Generation: AI creates each panel image with precise camera angles and composition', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Speech Bubbles: Overlay editable speech bubbles and captions as metadata', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Page Layout: Compose panels into multi-panel page layouts', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'CBZ/CBR Export: Package finished comics as standard archive formats with ComicInfo.xml metadata', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( '8 Art Styles: American Comic, Manga, Webtoon, Graphic Novel, Noir, Silver Age, Euro Comic, Comic Strip', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Workflow', 'nvoos-content-graph-pro' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Create a Script — Write or AI-generate your story', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Define Characters — Generate consistent character reference images', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Generate Panels — AI creates each panel image from descriptions', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Add Bubbles — Place speech bubbles and captions', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Layout Pages — Arrange panels into pages', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Export — Download as CBZ/CBR for reading', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render configuration tab content.
	 *
	 * @since 2.0.0
	 */
	protected function render_configuration_tab() {
		$options       = get_option( $this->option_name, array() );
		$assistant_id  = isset( $options['research_assistant_id'] ) ? absint( $options['research_assistant_id'] ) : 0;
		$comic_style   = isset( $options['default_comic_style'] ) ? $options['default_comic_style'] : 'american-comic';
		$panel_width   = isset( $options['default_panel_width'] ) ? absint( $options['default_panel_width'] ) : 800;
		$panel_height  = isset( $options['default_panel_height'] ) ? absint( $options['default_panel_height'] ) : 1200;
		$image_gen     = isset( $options['default_image_generator'] ) ? $options['default_image_generator'] : 'dalle';
		$export_format = isset( $options['default_export_format'] ) ? $options['default_export_format'] : 'cbz';
		$font          = isset( $options['speech_bubble_font'] ) ? $options['speech_bubble_font'] : 'ComicSans';
		$font_color    = isset( $options['speech_bubble_font_color'] ) ? $options['speech_bubble_font_color'] : '#000000';
		$bg_color      = isset( $options['speech_bubble_bg_color'] ) ? $options['speech_bubble_bg_color'] : '#FFFFFF';
		$page_layout   = isset( $options['default_page_layout'] ) ? $options['default_page_layout'] : 'single';
		$research_on   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : false;
		$mime_types_on = isset( $options['enable_mime_types'] ) ? (bool) $options['enable_mime_types'] : true;
		?>
		<h2><?php esc_html_e( 'Comic Creation Settings', 'nvoos-content-graph-pro' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Configure the AI assistant and default settings for Comic Creation.', 'nvoos-content-graph-pro' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="research_assistant_id"><?php esc_html_e( 'AI Assistant', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					$assistants = get_posts(
						array(
							'post_type'      => 'mcp_ai_assistant',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[research_assistant_id]" id="research_assistant_id">
						<option value="0"><?php esc_html_e( '— Use default assistant —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $assistants as $assistant ) : ?>
							<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assistant_id, $assistant->ID ); ?>>
								<?php echo esc_html( $assistant->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Select the AI assistant to use for comic creation.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_comic_style"><?php esc_html_e( 'Default Comic Style', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[default_comic_style]" id="default_comic_style" class="regular-text">
						<option value="american-comic" <?php selected( $comic_style, 'american-comic' ); ?>><?php esc_html_e( 'American Comic', 'nvoos-content-graph-pro' ); ?></option>
						<option value="manga" <?php selected( $comic_style, 'manga' ); ?>><?php esc_html_e( 'Manga', 'nvoos-content-graph-pro' ); ?></option>
						<option value="webtoon" <?php selected( $comic_style, 'webtoon' ); ?>><?php esc_html_e( 'Webtoon (Vertical Scroll)', 'nvoos-content-graph-pro' ); ?></option>
						<option value="graphic-novel" <?php selected( $comic_style, 'graphic-novel' ); ?>><?php esc_html_e( 'Graphic Novel', 'nvoos-content-graph-pro' ); ?></option>
						<option value="comic-strip" <?php selected( $comic_style, 'comic-strip' ); ?>><?php esc_html_e( 'Comic Strip', 'nvoos-content-graph-pro' ); ?></option>
						<option value="noir" <?php selected( $comic_style, 'noir' ); ?>><?php esc_html_e( 'Noir (B&W)', 'nvoos-content-graph-pro' ); ?></option>
						<option value="silver-age" <?php selected( $comic_style, 'silver-age' ); ?>><?php esc_html_e( 'Silver Age (Retro)', 'nvoos-content-graph-pro' ); ?></option>
						<option value="euro-comic" <?php selected( $comic_style, 'euro-comic' ); ?>><?php esc_html_e( 'European Comic', 'nvoos-content-graph-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Default art style applied to newly created comics.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label><?php esc_html_e( 'Default Panel Dimensions', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_panel_width]" value="<?php echo esc_attr( $panel_width ); ?>" min="256" max="2048" class="small-text" />
					<span>×</span>
					<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_panel_height]" value="<?php echo esc_attr( $panel_height ); ?>" min="256" max="2048" class="small-text" />
					<span>px</span>
					<p class="description"><?php esc_html_e( 'Default dimensions for AI-generated comic panels (width × height). Webtoon style uses 800×2000.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_image_generator"><?php esc_html_e( 'Default Image Generator', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[default_image_generator]" id="default_image_generator" class="regular-text">
						<option value="dalle" <?php selected( $image_gen, 'dalle' ); ?>>DALL-E</option>
						<option value="midjourney" <?php selected( $image_gen, 'midjourney' ); ?>>Midjourney</option>
						<option value="stable_diffusion" <?php selected( $image_gen, 'stable_diffusion' ); ?>>Stable Diffusion</option>
					</select>
					<p class="description"><?php esc_html_e( 'Default AI service for comic panel image generation.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_export_format"><?php esc_html_e( 'Default Export Format', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[default_export_format]" id="default_export_format" class="regular-text">
						<option value="cbz" <?php selected( $export_format, 'cbz' ); ?>>CBZ (ZIP Archive — Recommended)</option>
						<option value="cbr" <?php selected( $export_format, 'cbr' ); ?>>CBR (RAR Archive — Legacy)</option>
					</select>
					<p class="description"><?php esc_html_e( 'Default format for exporting completed comics. CBZ is the open standard.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label><?php esc_html_e( 'Speech Bubble Defaults', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<table class="form-table" style="margin:0; padding:0;">
						<tr>
							<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_font"><?php esc_html_e( 'Font', 'nvoos-content-graph-pro' ); ?></label></td>
							<td style="padding:2px 0;">
								<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_font]" id="speech_bubble_font" value="<?php echo esc_attr( $font ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_font_color"><?php esc_html_e( 'Text Color', 'nvoos-content-graph-pro' ); ?></label></td>
							<td style="padding:2px 0;">
								<input type="color" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_font_color]" id="speech_bubble_font_color" value="<?php echo esc_attr( $font_color ); ?>" />
							</td>
						</tr>
						<tr>
							<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_bg_color"><?php esc_html_e( 'Bubble Color', 'nvoos-content-graph-pro' ); ?></label></td>
							<td style="padding:2px 0;">
								<input type="color" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_bg_color]" id="speech_bubble_bg_color" value="<?php echo esc_attr( $bg_color ); ?>" />
							</td>
						</tr>
					</table>
					<p class="description"><?php esc_html_e( 'Default appearance for speech bubbles and captions.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_page_layout"><?php esc_html_e( 'Default Page Layout', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[default_page_layout]" id="default_page_layout" class="regular-text">
						<option value="single" <?php selected( $page_layout, 'single' ); ?>><?php esc_html_e( 'Single Page', 'nvoos-content-graph-pro' ); ?></option>
						<option value="double" <?php selected( $page_layout, 'double' ); ?>><?php esc_html_e( 'Double Page Spread', 'nvoos-content-graph-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Default page viewing mode for new comics.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Research & Add', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]" id="enable_research" value="1" <?php checked( $research_on, true ); ?> />
						<?php esc_html_e( 'Enable the Research & Add page for comic creation with AI assistance', 'nvoos-content-graph-pro' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, users can access the Research & Add page to create comic scripts and panels using AI.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Comic File Uploads', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_mime_types]" id="enable_mime_types" value="1" <?php checked( $mime_types_on, true ); ?> />
						<?php esc_html_e( 'Allow CBR, CBZ, CB7, and CBT comic archive uploads in WordPress Media Library', 'nvoos-content-graph-pro' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Adds comic archive file types to the list of allowed upload MIME types. Disable if your host blocks these formats.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'generate_comic_script'    => __( 'Generate Comic Script', 'nvoos-content-graph-pro' ),
			'breakdown_comic_panels'   => __( 'Breakdown Comic Panels', 'nvoos-content-graph-pro' ),
			'generate_character_sheet' => __( 'Generate Character Sheet', 'nvoos-content-graph-pro' ),
			'generate_comic_panel'     => __( 'Generate Comic Panel', 'nvoos-content-graph-pro' ),
			'create_comic_layout'      => __( 'Create Comic Layout', 'nvoos-content-graph-pro' ),
			'add_speech_bubbles'       => __( 'Add Speech Bubbles', 'nvoos-content-graph-pro' ),
			'export_comic_cbz'         => __( 'Export Comic (CBZ/CBR)', 'nvoos-content-graph-pro' ),
			'colorize_comic_panel'     => __( 'Colorize Comic Panel', 'nvoos-content-graph-pro' ),
			'ink_comic_panel'          => __( 'Ink Comic Panel', 'nvoos-content-graph-pro' ),
			'letter_comic_panel'       => __( 'Letter Comic Panel', 'nvoos-content-graph-pro' ),
			'upscale_comic_page'       => __( 'Upscale Comic Page', 'nvoos-content-graph-pro' ),
			'apply_comic_style'        => __( 'Apply Comic Style', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 2.0.0
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();

		if ( isset( $input['research_assistant_id'] ) ) {
			$sanitized['research_assistant_id'] = absint( $input['research_assistant_id'] );
		}

		if ( isset( $input['default_comic_style'] ) ) {
			$sanitized['default_comic_style'] = sanitize_text_field( $input['default_comic_style'] );
		}

		if ( isset( $input['default_panel_width'] ) ) {
			$sanitized['default_panel_width'] = absint( $input['default_panel_width'] );
		}

		if ( isset( $input['default_panel_height'] ) ) {
			$sanitized['default_panel_height'] = absint( $input['default_panel_height'] );
		}

		if ( isset( $input['default_image_generator'] ) ) {
			$sanitized['default_image_generator'] = sanitize_text_field( $input['default_image_generator'] );
		}

		if ( isset( $input['default_export_format'] ) ) {
			$sanitized['default_export_format'] = sanitize_text_field( $input['default_export_format'] );
		}

		if ( isset( $input['speech_bubble_font'] ) ) {
			$sanitized['speech_bubble_font'] = sanitize_text_field( $input['speech_bubble_font'] );
		}

		if ( isset( $input['speech_bubble_font_color'] ) ) {
			$sanitized['speech_bubble_font_color'] = sanitize_hex_color( $input['speech_bubble_font_color'] );
		}

		if ( isset( $input['speech_bubble_bg_color'] ) ) {
			$sanitized['speech_bubble_bg_color'] = sanitize_hex_color( $input['speech_bubble_bg_color'] );
		}

		if ( isset( $input['default_page_layout'] ) ) {
			$sanitized['default_page_layout'] = sanitize_text_field( $input['default_page_layout'] );
		}

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		if ( isset( $input['enable_mime_types'] ) ) {
			$sanitized['enable_mime_types'] = (bool) $input['enable_mime_types'];
		} else {
			$sanitized['enable_mime_types'] = false;
		}

		return $sanitized;
	}
}
