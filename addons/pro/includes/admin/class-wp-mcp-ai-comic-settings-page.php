<?php
/**
 * Comic Creation Settings Page
 *
 * Provides settings page for configuring AI image generation defaults,
 * panel dimensions, style presets, and export options for the Comic
 * Creation Toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class from image production for pattern reference.
// The CPT settings base is already available.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Comic Creation Settings Page
 */
class WP_MCP_AI_Comic_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_comic_creation_settings';
		$this->post_type   = 'mcp_ai_comic';
		$this->page_title  = __( 'Comic Creation Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Comic Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'comic-creation-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Add settings submenu page.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_comic',
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

		// Add comic creation-specific settings.
		add_settings_field(
			'default_comic_style',
			__( 'Default Comic Style', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_comic_style_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_panel_dimensions',
			__( 'Default Panel Dimensions', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_panel_dimensions_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_image_generator',
			__( 'Default Image Generator', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_image_generator_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_export_format',
			__( 'Default Export Format', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_export_format_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'speech_bubble_defaults',
			__( 'Speech Bubble Defaults', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_speech_bubble_defaults_field' ),
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

		add_settings_field(
			'default_page_layout',
			__( 'Default Page Layout', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_page_layout_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_mime_types',
			__( 'Allow Comic File Uploads', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_mime_types_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render default comic style field.
	 */
	public function render_default_comic_style_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_comic_style'] ) ? $options['default_comic_style'] : 'american-comic';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_comic_style]" class="regular-text">
			<option value="american-comic" <?php selected( $value, 'american-comic' ); ?>><?php esc_html_e( 'American Comic', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="manga" <?php selected( $value, 'manga' ); ?>><?php esc_html_e( 'Manga', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="webtoon" <?php selected( $value, 'webtoon' ); ?>><?php esc_html_e( 'Webtoon (Vertical Scroll)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="graphic-novel" <?php selected( $value, 'graphic-novel' ); ?>><?php esc_html_e( 'Graphic Novel', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="comic-strip" <?php selected( $value, 'comic-strip' ); ?>><?php esc_html_e( 'Comic Strip', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="noir" <?php selected( $value, 'noir' ); ?>><?php esc_html_e( 'Noir (B&W)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="silver-age" <?php selected( $value, 'silver-age' ); ?>><?php esc_html_e( 'Silver Age (Retro)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="euro-comic" <?php selected( $value, 'euro-comic' ); ?>><?php esc_html_e( 'European Comic', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default art style applied to newly created comics', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render default panel dimensions field.
	 */
	public function render_default_panel_dimensions_field() {
		$options = get_option( $this->option_name, array() );
		$width   = isset( $options['default_panel_width'] ) ? absint( $options['default_panel_width'] ) : 800;
		$height  = isset( $options['default_panel_height'] ) ? absint( $options['default_panel_height'] ) : 1200;

		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_panel_width]" value="<?php echo esc_attr( $width ); ?>" min="256" max="2048" class="small-text" />
		<span>×</span>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_panel_height]" value="<?php echo esc_attr( $height ); ?>" min="256" max="2048" class="small-text" />
		<span>px</span>
		<p class="description"><?php esc_html_e( 'Default dimensions for AI-generated comic panels (width × height). Webtoon style uses 800×2000.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
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
		<p class="description"><?php esc_html_e( 'Default AI service for comic panel image generation', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render default export format field.
	 */
	public function render_default_export_format_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_export_format'] ) ? $options['default_export_format'] : 'cbz';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_export_format]" class="regular-text">
			<option value="cbz" <?php selected( $value, 'cbz' ); ?>>CBZ (ZIP Archive — Recommended)</option>
			<option value="cbr" <?php selected( $value, 'cbr' ); ?>>CBR (RAR Archive — Legacy)</option>
		</select>
		<p class="description"><?php esc_html_e( 'Default format for exporting completed comics. CBZ is the open standard.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render speech bubble defaults field.
	 */
	public function render_speech_bubble_defaults_field() {
		$options    = get_option( $this->option_name, array() );
		$font       = isset( $options['speech_bubble_font'] ) ? $options['speech_bubble_font'] : 'ComicSans';
		$font_color = isset( $options['speech_bubble_font_color'] ) ? $options['speech_bubble_font_color'] : '#000000';
		$bg_color   = isset( $options['speech_bubble_bg_color'] ) ? $options['speech_bubble_bg_color'] : '#FFFFFF';

		?>
		<table class="form-table" style="margin:0; padding:0;">
			<tr>
				<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_font"><?php esc_html_e( 'Font', 'mcp-ai-wpoos-pro' ); ?></label></td>
				<td style="padding:2px 0;">
					<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_font]" id="speech_bubble_font" value="<?php echo esc_attr( $font ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_font_color"><?php esc_html_e( 'Text Color', 'mcp-ai-wpoos-pro' ); ?></label></td>
				<td style="padding:2px 0;">
					<input type="color" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_font_color]" id="speech_bubble_font_color" value="<?php echo esc_attr( $font_color ); ?>" />
				</td>
			</tr>
			<tr>
				<td style="padding:2px 10px 2px 0;"><label for="speech_bubble_bg_color"><?php esc_html_e( 'Bubble Color', 'mcp-ai-wpoos-pro' ); ?></label></td>
				<td style="padding:2px 0;">
					<input type="color" name="<?php echo esc_attr( $this->option_name ); ?>[speech_bubble_bg_color]" id="speech_bubble_bg_color" value="<?php echo esc_attr( $bg_color ); ?>" />
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Default appearance for speech bubbles and captions', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
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
			<?php esc_html_e( 'Enable the Research & Add page for comic creation with AI assistance', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create comic scripts and panels using AI.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render default page layout field.
	 */
	public function render_default_page_layout_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_page_layout'] ) ? $options['default_page_layout'] : 'single';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_page_layout]" class="regular-text">
			<option value="single" <?php selected( $value, 'single' ); ?>><?php esc_html_e( 'Single Page', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="double" <?php selected( $value, 'double' ); ?>><?php esc_html_e( 'Double Page Spread', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default page viewing mode for new comics', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable MIME types field.
	 */
	public function render_enable_mime_types_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_mime_types'] ) ? (bool) $options['enable_mime_types'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_mime_types]"
				id="enable_mime_types"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Allow CBR, CBZ, CB7, and CBT comic archive uploads in WordPress Media Library', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Adds comic archive file types to the list of allowed upload MIME types. Disable if your host blocks these formats.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant and default settings for Comic Creation.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Comic Creation Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p><?php esc_html_e( 'AI-powered comic book creation toolkit for generating scripts, characters, panels, and complete comic books with professional layouts and export.', 'mcp-ai-wpoos-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Script Generation: AI writes full comic scripts with scene breakdowns from a simple premise', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Character Consistency: Generate reference sheets and maintain visual consistency across panels', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Panel-by-Panel Generation: AI creates each panel image with precise camera angles and composition', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Speech Bubbles: Overlay editable speech bubbles and captions as metadata', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Page Layout: Compose panels into multi-panel page layouts', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'CBZ/CBR Export: Package finished comics as standard archive formats with ComicInfo.xml metadata', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '8 Art Styles: American Comic, Manga, Webtoon, Graphic Novel, Noir, Silver Age, Euro Comic, Comic Strip', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Workflow', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Create a Script — Write or AI-generate your story', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Define Characters — Generate consistent character reference images', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Generate Panels — AI creates each panel image from descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Add Bubbles — Place speech bubbles and captions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Layout Pages — Arrange panels into pages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Export — Download as CBZ/CBR for reading', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
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
			'generate_comic_script'       => __( 'Generate Comic Script', 'mcp-ai-wpoos-pro' ),
			'breakdown_comic_panels'      => __( 'Breakdown Comic Panels', 'mcp-ai-wpoos-pro' ),
			'generate_character_sheet'    => __( 'Generate Character Sheet', 'mcp-ai-wpoos-pro' ),
			'generate_comic_panel'        => __( 'Generate Comic Panel', 'mcp-ai-wpoos-pro' ),
			'create_comic_layout'         => __( 'Create Comic Layout', 'mcp-ai-wpoos-pro' ),
			'add_speech_bubbles'          => __( 'Add Speech Bubbles', 'mcp-ai-wpoos-pro' ),
			'export_comic_cbz'            => __( 'Export Comic (CBZ/CBR)', 'mcp-ai-wpoos-pro' ),
			'colorize_comic_panel'        => __( 'Colorize Comic Panel', 'mcp-ai-wpoos-pro' ),
			'ink_comic_panel'             => __( 'Ink Comic Panel', 'mcp-ai-wpoos-pro' ),
			'letter_comic_panel'          => __( 'Letter Comic Panel', 'mcp-ai-wpoos-pro' ),
			'upscale_comic_page'          => __( 'Upscale Comic Page', 'mcp-ai-wpoos-pro' ),
			'apply_comic_style'           => __( 'Apply Comic Style', 'mcp-ai-wpoos-pro' ),
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

		// Add comic creation-specific sanitization.
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
