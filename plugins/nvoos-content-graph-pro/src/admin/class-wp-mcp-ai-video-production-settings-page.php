<?php
/**
 * Video Production Toolkit settings page (ecosystem port - Wave F2, video-production admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in monolith
 * installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root (the toolkit-settings/research-add base
 * classes resolve from the addon's already-ported `src/admin/` copies).
 *
 * Video Production Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Video Production Toolkit Settings Page Class
 */
class WP_MCP_AI_Video_Production_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'video_production';
		$this->toolkit_name     = __( 'Video Production Toolkit', 'nvoos-content-graph-pro' );
		$this->option_name      = 'wp_mcp_ai_video_production_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-video-production-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-video-alt3';

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
			<h2><?php esc_html_e( 'Video Production Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Professional video production toolkit with 12 AI-powered tools for video editing, transcription, subtitles, and media optimization.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Video Editing: Trim, split, merge, and apply effects to videos', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Transcription: Generate automatic transcriptions and subtitles', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Voice-Over: Text-to-speech synthesis for narration', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Optimization: Compress videos, convert formats, and optimize for platforms', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'AI Enhancement: Auto-generate thumbnails, highlight reels, and scene detection', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Collaboration: Share preview links and distribute videos to platforms', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Supported Formats', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'MP4, MOV, AVI, WebM, MKV', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'H.264, H.265, VP9 codecs', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Up to 4K resolution', 'nvoos-content-graph-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Video Production Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Output Format', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="default_output_format">
							<option value="mp4" selected><?php esc_html_e( 'MP4 (H.264)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="webm"><?php esc_html_e( 'WebM (VP9)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="mov"><?php esc_html_e( 'MOV (ProRes)', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format for exported videos', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Transcription Language', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="transcription_language">
							<option value="auto"><?php esc_html_e( 'Auto-detect', 'nvoos-content-graph-pro' ); ?></option>
							<option value="en"><?php esc_html_e( 'English', 'nvoos-content-graph-pro' ); ?></option>
							<option value="es"><?php esc_html_e( 'Spanish', 'nvoos-content-graph-pro' ); ?></option>
							<option value="fr"><?php esc_html_e( 'French', 'nvoos-content-graph-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Distributed Rendering', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_distributed_rendering" value="1" />
							<?php esc_html_e( 'Use remote sites for faster video processing', 'nvoos-content-graph-pro' ); ?>
						</label>
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
			'edit_video'                   => __( 'Edit Video', 'nvoos-content-graph-pro' ),
			'trim_video'                   => __( 'Trim Video', 'nvoos-content-graph-pro' ),
			'merge_videos'                 => __( 'Merge Videos', 'nvoos-content-graph-pro' ),
			'generate_video_transcription' => __( 'Generate Video Transcription', 'nvoos-content-graph-pro' ),
			'add_subtitles_to_video'       => __( 'Add Subtitles to Video', 'nvoos-content-graph-pro' ),
			'generate_voice_over'          => __( 'Generate Voice-Over', 'nvoos-content-graph-pro' ),
			'compress_video'               => __( 'Compress Video', 'nvoos-content-graph-pro' ),
			'convert_video_format'         => __( 'Convert Video Format', 'nvoos-content-graph-pro' ),
			'generate_video_thumbnail'     => __( 'Generate Video Thumbnail', 'nvoos-content-graph-pro' ),
			'create_highlight_reel'        => __( 'Create Highlight Reel', 'nvoos-content-graph-pro' ),
			'share_video_preview'          => __( 'Share Video Preview', 'nvoos-content-graph-pro' ),
			'distribute_video'             => __( 'Distribute Video', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Video_Production_Settings_Page();
}
