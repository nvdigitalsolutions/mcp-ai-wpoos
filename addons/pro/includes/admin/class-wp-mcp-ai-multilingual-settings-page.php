<?php
/**
 * Multilingual Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Multilingual Toolkit Settings Page Class
 */
class WP_MCP_AI_Multilingual_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'multilingual';
		$this->toolkit_name     = __( 'Multilingual Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_multilingual_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-multilingual-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-translation';

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
			<h2><?php esc_html_e( 'Multilingual Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive multilingual management toolkit with 10 tools for translation, localization, and global content management.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Translation: Translate content using AI with context preservation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Translation Memory: Build and leverage translation databases', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Glossary Management: Maintain terminology consistency across languages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk Operations: Translate multiple posts and detect languages automatically', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Quality Assurance: Validate translations and check localization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'WPML Integration: Seamless integration with WPML plugin', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Supported Languages', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( '100+ languages supported including:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'European: Spanish, French, German, Italian, Portuguese, Russian', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Asian: Chinese (Simplified & Traditional), Japanese, Korean, Hindi', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Middle Eastern: Arabic, Hebrew, Persian', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Multilingual Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Target Language', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_target_language">
							<option value="es"><?php esc_html_e( 'Spanish', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="fr"><?php esc_html_e( 'French', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="de"><?php esc_html_e( 'German', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="zh"><?php esc_html_e( 'Chinese', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default language for translations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Translation Provider', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="translation_provider">
							<option value="openai"><?php esc_html_e( 'OpenAI GPT-4', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="google"><?php esc_html_e( 'Google Translate', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="deepl"><?php esc_html_e( 'DeepL', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Translation Memory', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_translation_memory" value="1" checked />
							<?php esc_html_e( 'Store and reuse previous translations', 'mcp-ai-wpoos-pro' ); ?>
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
			'translate_content_ai'           => __( 'Translate Content (AI)', 'mcp-ai-wpoos-pro' ),
			'bulk_translate_posts'           => __( 'Bulk Translate Posts', 'mcp-ai-wpoos-pro' ),
			'detect_content_language'        => __( 'Detect Content Language', 'mcp-ai-wpoos-pro' ),
			'manage_translation_memory'      => __( 'Manage Translation Memory', 'mcp-ai-wpoos-pro' ),
			'sync_language_versions'         => __( 'Sync Language Versions', 'mcp-ai-wpoos-pro' ),
			'validate_translations'          => __( 'Validate Translations', 'mcp-ai-wpoos-pro' ),
			'export_translation_package'     => __( 'Export Translation Package', 'mcp-ai-wpoos-pro' ),
			'glossary_management'            => __( 'Glossary Management', 'mcp-ai-wpoos-pro' ),
			'wpml_integration'               => __( 'WPML Integration', 'mcp-ai-wpoos-pro' ),
			'localization_checker'           => __( 'Localization Checker', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Multilingual_Settings_Page();
}
