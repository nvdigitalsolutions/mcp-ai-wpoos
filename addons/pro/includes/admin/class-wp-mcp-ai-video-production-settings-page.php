<?php
/**
 * Video Production Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Video Production Toolkit Settings Page Class
 */
class WP_MCP_AI_Video_Production_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'video_production';
		$this->toolkit_name     = __( 'Video Production Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_video_production_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-video-production-toolkit-settings';
		$this->has_research     = false;
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
			<h2><?php esc_html_e( 'Video Production Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Professional video production toolkit with 12 AI-powered tools for video editing, transcription, subtitles, and media optimization.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Video Editing: Trim, split, merge, and apply effects to videos', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Transcription: Generate automatic transcriptions and subtitles', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Voice-Over: Text-to-speech synthesis for narration', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Optimization: Compress videos, convert formats, and optimize for platforms', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'AI Enhancement: Auto-generate thumbnails, highlight reels, and scene detection', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Collaboration: Share preview links and distribute videos to platforms', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Supported Formats', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'MP4, MOV, AVI, WebM, MKV', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'H.264, H.265, VP9 codecs', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Up to 4K resolution', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Video Production Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Output Format', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_output_format">
							<option value="mp4" selected><?php esc_html_e( 'MP4 (H.264)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="webm"><?php esc_html_e( 'WebM (VP9)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="mov"><?php esc_html_e( 'MOV (ProRes)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format for exported videos', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Transcription Language', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="transcription_language">
							<option value="auto"><?php esc_html_e( 'Auto-detect', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="en"><?php esc_html_e( 'English', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="es"><?php esc_html_e( 'Spanish', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="fr"><?php esc_html_e( 'French', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Distributed Rendering', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_distributed_rendering" value="1" />
							<?php esc_html_e( 'Use remote sites for faster video processing', 'mcp-ai-wpoos-pro' ); ?>
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
			'edit_video'                   => __( 'Edit Video', 'mcp-ai-wpoos-pro' ),
			'trim_video'                   => __( 'Trim Video', 'mcp-ai-wpoos-pro' ),
			'merge_videos'                 => __( 'Merge Videos', 'mcp-ai-wpoos-pro' ),
			'generate_video_transcription' => __( 'Generate Video Transcription', 'mcp-ai-wpoos-pro' ),
			'add_subtitles_to_video'       => __( 'Add Subtitles to Video', 'mcp-ai-wpoos-pro' ),
			'generate_voice_over'          => __( 'Generate Voice-Over', 'mcp-ai-wpoos-pro' ),
			'compress_video'               => __( 'Compress Video', 'mcp-ai-wpoos-pro' ),
			'convert_video_format'         => __( 'Convert Video Format', 'mcp-ai-wpoos-pro' ),
			'generate_video_thumbnail'     => __( 'Generate Video Thumbnail', 'mcp-ai-wpoos-pro' ),
			'create_highlight_reel'        => __( 'Create Highlight Reel', 'mcp-ai-wpoos-pro' ),
			'share_video_preview'          => __( 'Share Video Preview', 'mcp-ai-wpoos-pro' ),
			'distribute_video'             => __( 'Distribute Video', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Video_Production_Settings_Page();
}
