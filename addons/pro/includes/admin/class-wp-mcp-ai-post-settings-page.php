<?php
/**
 * Post Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Post Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Post Settings Page
 */
class WP_MCP_AI_Post_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_post_settings';
		$this->post_type   = 'post';
		$this->page_title  = __( 'Post Research Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Research Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'post-research-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add post-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research_post"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for post research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create posts using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Post Research & Add Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered post creation and management tools for WordPress posts. Use AI assistance to research, plan, and create high-quality blog posts with optimized content and SEO.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'AI Research: Research topics and gather information for post content', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Content Generation: Create comprehensive post content with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'SEO Optimization: Automatically optimize posts for search engines', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Interactive Chat Interface: Collaborate with AI to refine post content', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Draft Management: Save and continue post creation across sessions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Excerpt Generation: Automatically generate engaging post excerpts', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list for this CPT.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'wp_create_post'        => __( 'Create Post', 'mcp-ai-wpoos-pro' ),
			'wp_save_post'          => __( 'Save Post', 'mcp-ai-wpoos-pro' ),
			'wp_get_recent_posts'   => __( 'Get Recent Posts', 'mcp-ai-wpoos-pro' ),
			'research_post'         => __( 'Research Post Topic', 'mcp-ai-wpoos-pro' ),
			'generate_post_excerpt' => __( 'Generate Post Excerpt', 'mcp-ai-wpoos-pro' ),
			'wp_search_content'     => __( 'Search Content', 'mcp-ai-wpoos-pro' ),
			'wp_get_post_meta'      => __( 'Get Post Metadata', 'mcp-ai-wpoos-pro' ),
			'wp_update_post_meta'   => __( 'Update Post Metadata', 'mcp-ai-wpoos-pro' ),
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

		// Add post-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Post_Settings_Page();
