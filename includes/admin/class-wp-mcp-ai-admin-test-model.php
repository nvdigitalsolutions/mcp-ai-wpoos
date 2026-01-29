<?php
/**
 * Test Model Admin Page
 *
 * Provides an interface for administrators to test AI provider models with different professions.
 * Allows users to select a professional, provider, and model to validate configurations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test Model admin page handler.
 */
class WP_MCP_AI_Admin_Test_Model {

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_submenu_page() {
		$post_type = 'mcp_ai_profession';

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Test Model', 'mcp-ai-wpoos' ),
			__( 'Test Model', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-test-model',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the test model page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		// Enqueue chat shortcode assets (required dependency for professional selector).
		// Note: The professional selector script declares wp-mcp-ai-chat as a dependency
		// during registration, but we explicitly enqueue it here to ensure it's loaded
		// in admin context where the shortcode's normal enqueue hooks may not fire.
		$dependencies = array();
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue professional selector shortcode assets.
		if ( class_exists( 'WP_MCP_AI_Professional_Selector_Shortcode' ) ) {
			wp_enqueue_style( WP_MCP_AI_Professional_Selector_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Professional_Selector_Shortcode::SCRIPT_HANDLE );
			$dependencies[] = WP_MCP_AI_Professional_Selector_Shortcode::STYLE_HANDLE;
		}

		// Enqueue additional admin styles for this page.
		wp_enqueue_style(
			'wp-mcp-ai-admin-test-model',
			WP_MCP_AI_URL . 'assets/css/admin-test-model.css',
			$dependencies,
			$this->get_asset_version( 'assets/css/admin-test-model.css' )
		);
	}

	/**
	 * Render the test model page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		?>
		<div class="wrap wp-mcp-ai-test-model-page">
			<h1><?php echo esc_html__( 'Test AI Models with Professions', 'mcp-ai-wpoos' ); ?></h1>

			<div class="wp-mcp-ai-test-model-intro">
				<p class="description">
					<?php
					echo esc_html__(
						'Use this page to test different AI provider models with your configured professions. This tool helps you validate that your professional configurations work correctly with various AI models before deploying them to production.',
						'mcp-ai-wpoos'
					);
					?>
				</p>
			</div>

			<div class="wp-mcp-ai-test-model-instructions">
				<h2><?php echo esc_html__( 'How to Use', 'mcp-ai-wpoos' ); ?></h2>
				<ol>
					<li>
						<strong><?php echo esc_html__( 'Select an Assistant:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html__( 'Choose the assistant context that will handle the conversation.', 'mcp-ai-wpoos' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'Select a Professional:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html__( 'Pick the professional profile you want to test. Each professional has specific expertise, knowledge base, and tools.', 'mcp-ai-wpoos' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'Choose an AI Provider:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo esc_html__(
							'Select from available providers: OpenAI, Anthropic (Claude), Google Gemini, Hugging Face, Ollama (Local), LM Studio (Local), or Cloudflare Worker AI.',
							'mcp-ai-wpoos'
						);
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'Select a Model:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html__( 'Once you select a provider, available models will be loaded. Choose the specific model you want to test.', 'mcp-ai-wpoos' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'Start Chat:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html__( 'Click "Start Chat" to open the chat interface and begin testing your configuration.', 'mcp-ai-wpoos' ); ?>
					</li>
				</ol>

				<div class="wp-mcp-ai-test-model-tips">
					<h3><?php echo esc_html__( 'Testing Tips', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li><?php echo esc_html__( 'Test the same professional with different providers to compare response quality.', 'mcp-ai-wpoos' ); ?></li>
						<li><?php echo esc_html__( 'Verify that profession-specific tools are available and functioning correctly.', 'mcp-ai-wpoos' ); ?></li>
						<li><?php echo esc_html__( 'Check that the professional\'s knowledge base is properly loaded and accessible.', 'mcp-ai-wpoos' ); ?></li>
						<li><?php echo esc_html__( 'Test edge cases and complex queries to ensure robust performance.', 'mcp-ai-wpoos' ); ?></li>
						<li><?php echo esc_html__( 'Note any model-specific limitations or behaviors for documentation.', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="wp-mcp-ai-test-model-selector">
				<h2><?php echo esc_html__( 'Professional Selector', 'mcp-ai-wpoos' ); ?></h2>
				<?php
				// Render the professional selector shortcode.
				if ( shortcode_exists( 'mcp_ai_professional_selector' ) ) {
					// Enable all features for testing.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped.
					echo do_shortcode( '[mcp_ai_professional_selector show_temperature="true" enable_streaming="true" save_transcript="false" allow_sensitive_tools="true"]' );
				} else {
					?>
					<div class="notice notice-error">
						<p>
							<?php
							echo esc_html__(
								'The professional selector shortcode is not available. Please ensure the plugin is properly installed and activated.',
								'mcp-ai-wpoos'
							);
							?>
						</p>
					</div>
					<?php
				}
				?>
			</div>

			<div class="wp-mcp-ai-test-model-footer">
				<h3><?php echo esc_html__( 'Need Help?', 'mcp-ai-wpoos' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: %1$s: Link to documentation, %2$s: Link to create profession */
						esc_html__(
							'For more information about configuring professions and models, see our %1$s. If you haven\'t created any professions yet, %2$s.',
							'mcp-ai-wpoos'
						),
						'<a href="https://nvdigital.solutions/docs/mcp-ai-wpoos/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'mcp-ai-wpoos' ) . '</a>',
						'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create your first profession', 'mcp-ai-wpoos' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get asset version for cache busting.
	 *
	 * @param string $relative_path Asset path relative to plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$absolute_path = WP_MCP_AI_PATH . $relative_path;

		if ( file_exists( $absolute_path ) ) {
			$modified = filemtime( $absolute_path );
			if ( $modified ) {
				return WP_MCP_AI_VERSION . '.' . $modified;
			}
		}

		return WP_MCP_AI_VERSION;
	}
}
