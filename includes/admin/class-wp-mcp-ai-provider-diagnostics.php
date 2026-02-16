<?php
/**
 * NV oOS Provider Connectivity Diagnostic Page
 *
 * Test connectivity and configuration for all AI providers:
 * OpenAI, Anthropic, Google Gemini, Ollama (local AI), LM Studio, and Google Maps Platform.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Provider_Diagnostics' ) ) {
	/**
	 * Diagnostic page for AI provider connectivity.
	 */
	class WP_MCP_AI_Provider_Diagnostics {
		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private static $page_hook = '';

		/**
		 * Initialize the diagnostic page.
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_provider', array( __CLASS__, 'handle_test_provider' ) );
		}

		/**
		 * Register diagnostic page under Tools menu.
		 *
		 * Note: Located under Tools menu to ensure easy access for troubleshooting.
		 */
		public static function register_page() {
			self::$page_hook = add_submenu_page(
				'tools.php',
				__( 'Provider Connectivity Diagnostic', 'mcp-ai-wpoos' ),
				__( 'NV oOS Provider Test', 'mcp-ai-wpoos' ),
				'manage_options',
				'wp-mcp-ai-provider-diagnostic',
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the diagnostic page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public static function enqueue_assets( $hook ) {
			if ( self::$page_hook !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-provider-diagnostic',
				WP_MCP_AI_URL . 'assets/css/admin-settings.css',
				array(),
				WP_MCP_AI_VERSION
			);
		}

		/**
		 * Render the diagnostic page.
		 */
		public static function render_page() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'AI Provider Connectivity Diagnostics', 'mcp-ai-wpoos' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Test connectivity and configuration for all AI providers including OpenAI, Anthropic, Google Gemini, Ollama, LM Studio, and Google Maps Platform.', 'mcp-ai-wpoos' ); ?>
				</p>

				<!-- OpenAI -->
				<div class="card">
					<h2><?php esc_html_e( '1. OpenAI', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['openai_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['openai_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['default_model'] ) ? $settings['default_model'] : 'gpt-4o-mini' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Request Timeout', 'mcp-ai-wpoos' ); ?></th>
								<td><?php echo esc_html( isset( $settings['request_timeout'] ) ? $settings['request_timeout'] : 30 ); ?> <?php esc_html_e( 'seconds', 'mcp-ai-wpoos' ); ?></td>
							</tr>
						</tbody>
					</table>

					<div id="openai-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="openai"
						<?php echo esc_attr( empty( $settings['openai_api_key'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test OpenAI Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['openai_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your OpenAI API key in settings to enable testing.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<!-- Anthropic (Claude) -->
				<div class="card">
					<h2><?php esc_html_e( '2. Anthropic (Claude)', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['anthropic_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['anthropic_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-sonnet-4-5' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Provider Status', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['enable_anthropic'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="anthropic-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="anthropic"
						<?php echo esc_attr( empty( $settings['anthropic_api_key'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Anthropic Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['anthropic_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Anthropic API key in settings to enable testing.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Anthropic provides Claude models, known for their strong reasoning capabilities and large context windows. Claude 3.5 Sonnet offers excellent performance for complex tasks.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Google Gemini -->
				<div class="card">
					<h2><?php esc_html_e( '3. Google Gemini', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['gemini_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['gemini_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Default Model', 'mcp-ai-wpoos' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : 'gemini-2.5-flash' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'High Token Fallback Model', 'mcp-ai-wpoos' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['high_token_fallback_model'] ) ? $settings['high_token_fallback_model'] : 'gemini-2.5-flash' ); ?></code></td>
							</tr>
						</tbody>
					</table>

					<div id="gemini-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="gemini"
						<?php echo esc_attr( empty( $settings['gemini_api_key'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Gemini Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['gemini_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Google Gemini API key in settings to enable testing.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<!-- Hugging Face -->
				<div class="card">
					<h2><?php esc_html_e( '4. Hugging Face', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['huggingface_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['huggingface_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Endpoint URL', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['huggingface_endpoint_url'] ) ) : ?>
										<code><?php echo esc_html( $settings['huggingface_endpoint_url'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['huggingface_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['huggingface_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="huggingface-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="huggingface"
						<?php echo esc_attr( empty( $settings['huggingface_api_key'] ) || empty( $settings['huggingface_endpoint_url'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Hugging Face Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['huggingface_api_key'] ) || empty( $settings['huggingface_endpoint_url'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Hugging Face API key and endpoint URL in settings to enable testing.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<!-- Ollama (Local AI) -->
				<div class="card">
					<h2><?php esc_html_e( '5. Ollama (Local AI)', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Endpoint URL', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['ollama_endpoint_url'] ) ) : ?>
										<code><?php echo esc_html( $settings['ollama_endpoint_url'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['ollama_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['ollama_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="ollama-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="ollama"
						<?php echo esc_attr( empty( $settings['ollama_endpoint_url'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Ollama Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['ollama_endpoint_url'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Ollama endpoint URL in settings. Typically http://localhost:11434', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Note: Ollama must be running on your local machine or accessible network.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- LM Studio (Local AI) -->
				<div class="card">
					<h2><?php esc_html_e( '6. LM Studio (Local AI)', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Endpoint URL', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) : ?>
										<code><?php echo esc_html( $settings['lm_studio_endpoint_url'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['lm_studio_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['lm_studio_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="lm_studio-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="lm_studio"
						<?php echo esc_attr( empty( $settings['lm_studio_endpoint_url'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test LM Studio Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['lm_studio_endpoint_url'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your LM Studio endpoint URL in settings. Typically http://127.0.0.1:1234', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Note: LM Studio must be running with the local server enabled.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Cloudflare Workers AI -->
				<div class="card">
					<h2><?php esc_html_e( '7. Cloudflare Workers AI', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Provider Enabled', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['enable_cloudflare'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Enabled', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'API Token Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['cloudflare_api_token'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['cloudflare_api_token'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Account ID', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['cloudflare_account_id'] ) ) : ?>
										<code><?php echo esc_html( $settings['cloudflare_account_id'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['cloudflare_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['cloudflare_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="cloudflare-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="cloudflare"
						<?php echo esc_attr( empty( $settings['enable_cloudflare'] ) || empty( $settings['cloudflare_api_token'] ) || empty( $settings['cloudflare_account_id'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Cloudflare Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['enable_cloudflare'] ) || empty( $settings['cloudflare_api_token'] ) || empty( $settings['cloudflare_account_id'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Cloudflare Workers AI settings in the Providers tab. You need to enable the provider, set your API token, and account ID.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Cloudflare Workers AI provides access to models like Llama, Mistral, and more running on Cloudflare\'s edge network.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Embedded LLM (Pro) -->
				<?php
				// Only show Embedded LLM section if Pro version is active.
				if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) :
					?>
				<div class="card">
					<h2><?php esc_html_e( '8. Embedded LLM (Local AI - Pro)', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Provider Enabled', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['enable_embedded'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Enabled', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['embedded_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['embedded_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'mcp-ai-wpoos' ); ?>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Model Type', 'mcp-ai-wpoos' ); ?></th>
								<td><?php esc_html_e( 'WebLLM (Client-side, runs in browser using WebGPU/WebAssembly)', 'mcp-ai-wpoos' ); ?></td>
							</tr>
						</tbody>
					</table>

					<div id="embedded-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="embedded"
						<?php echo esc_attr( empty( $settings['enable_embedded'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Embedded LLM Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['enable_embedded'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Enable Embedded LLM in the Providers tab to use client-side AI models that run directly in the browser.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=embedded' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Embedded LLM runs small language models directly in the user\'s browser using WebGPU/WebAssembly. Models are loaded from CDN on-demand. Fully private, no server resources or API calls required.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<!-- Google Maps Platform -->
				<div class="card">
					<h2><?php esc_html_e( '9. Google Maps Platform', 'mcp-ai-wpoos' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['google_maps_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'mcp-ai-wpoos' ); ?></span>
										<code><?php echo esc_html( substr( $settings['google_maps_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Available APIs', 'mcp-ai-wpoos' ); ?></th>
								<td><?php esc_html_e( 'Geocoding API, Places API, Maps JavaScript API', 'mcp-ai-wpoos' ); ?></td>
							</tr>
						</tbody>
					</table>

					<div id="google_maps-test-result" style="margin: 15px 0;"></div>

					<button
						type="button"
						class="button button-primary test-provider"
						data-provider="google_maps"
						<?php echo esc_attr( empty( $settings['google_maps_api_key'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test Google Maps Connection', 'mcp-ai-wpoos' ); ?>
					</button>

					<?php if ( empty( $settings['google_maps_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Google Maps API key in settings to enable testing.', 'mcp-ai-wpoos' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Note: This test verifies API key validity using the Geocoding API. Ensure Geocoding API and Places API are enabled in Google Cloud Console.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Provider Summary -->
				<div class="card">
					<h2><?php esc_html_e( '10. Provider Summary', 'mcp-ai-wpoos' ); ?></h2>
					<?php
					$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';
					$configured       = array();

					if ( ! empty( $settings['openai_api_key'] ) ) {
						$configured[] = 'OpenAI';
					}
					if ( ! empty( $settings['anthropic_api_key'] ) ) {
						$configured[] = 'Anthropic';
					}
					if ( ! empty( $settings['gemini_api_key'] ) ) {
						$configured[] = 'Gemini';
					}
					if ( ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) ) {
						$configured[] = 'Hugging Face';
					}
					if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
						$configured[] = 'Ollama';
					}
					if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) {
						$configured[] = 'LM Studio';
					}
					if ( ! empty( $settings['enable_cloudflare'] ) && ! empty( $settings['cloudflare_api_token'] ) && ! empty( $settings['cloudflare_account_id'] ) ) {
						$configured[] = 'Cloudflare Workers AI';
					}
					if ( ! empty( $settings['google_maps_api_key'] ) ) {
						$configured[] = 'Google Maps';
					}
					?>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Default Provider', 'mcp-ai-wpoos' ); ?></th>
								<td><code><?php echo esc_html( ucfirst( str_replace( '_', ' ', $default_provider ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Configured Providers', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $configured ) ) : ?>
										<?php echo esc_html( implode( ', ', $configured ) ); ?>
										(<?php echo count( $configured ); ?> <?php esc_html_e( 'total', 'mcp-ai-wpoos' ); ?>)
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'No providers configured', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'High Token Model Switch', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['enable_high_token_model_switch'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos' ); ?></span>
										→ <code><?php echo esc_html( isset( $settings['high_token_fallback_model'] ) ? $settings['high_token_fallback_model'] : 'gemini-2.5-flash' ); ?></code>
									<?php else : ?>
										<span style="color: gray;">— <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<?php if ( empty( $configured ) ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<strong><?php esc_html_e( 'No AI providers configured!', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Configure at least one provider to use the AI assistant features.', 'mcp-ai-wpoos' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
									<?php esc_html_e( 'Configure Now', 'mcp-ai-wpoos' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<!-- Troubleshooting -->
				<div class="card">
					<h2><?php esc_html_e( '11. Troubleshooting Guide', 'mcp-ai-wpoos' ); ?></h2>

					<h3><?php esc_html_e( 'Common Issues:', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li>
							<strong><?php esc_html_e( 'OpenAI connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check OpenAI account billing and usage limits', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure server can connect to api.openai.com (not blocked by firewall)', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Gemini connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check Google AI Studio for API quota limits', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure Generative Language API is enabled in Google Cloud Console', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Anthropic connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check Anthropic Console for API quota and usage limits', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure server can connect to api.anthropic.com (not blocked by firewall)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Verify the selected Claude model is available with your API tier', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Hugging Face connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check endpoint URL is correct (typically https://router.huggingface.co/v1)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure selected model is available and accessible with your API key', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check Hugging Face account for usage limits and model access', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Ollama connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify Ollama is running (ollama serve)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check endpoint URL is correct (typically http://localhost:11434)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure selected model is installed (ollama list)', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check firewall settings if running on different machine', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'LM Studio connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify LM Studio local server is running', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check endpoint URL matches LM Studio server address', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure a model is loaded in LM Studio', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check CORS settings if accessing from different origin', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Cloudflare Workers AI connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify the Cloudflare provider is enabled in settings', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check API token is correct and has Workers AI permissions', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure account ID matches your Cloudflare account', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Verify Workers AI is enabled for your Cloudflare account', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check that selected model is available in your region', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Google Maps connection fails:', 'mcp-ai-wpoos' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Check that Geocoding API and Places API are enabled in Google Cloud Console', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Ensure billing is enabled for your Google Cloud project', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Verify API key restrictions (if any) allow requests from your server', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</li>
					</ul>

					<h3><?php esc_html_e( 'Useful Links:', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>"><?php esc_html_e( 'Plugin Settings', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-mcp-ai-mcp-diagnostic' ) ); ?>"><?php esc_html_e( 'MCP Server Diagnostic', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://platform.openai.com/api-keys" target="_blank"><?php esc_html_e( 'OpenAI API Keys', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://console.anthropic.com/" target="_blank"><?php esc_html_e( 'Anthropic Console', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://aistudio.google.com/app/apikey" target="_blank"><?php esc_html_e( 'Google AI Studio', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank"><?php esc_html_e( 'Google Cloud Console - Maps API', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://dash.cloudflare.com/" target="_blank"><?php esc_html_e( 'Cloudflare Dashboard', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://ollama.com/" target="_blank"><?php esc_html_e( 'Ollama Documentation', 'mcp-ai-wpoos' ); ?></a></li>
						<li><a href="https://lmstudio.ai/" target="_blank"><?php esc_html_e( 'LM Studio Download', 'mcp-ai-wpoos' ); ?></a></li>
					</ul>
				</div>
			</div>

			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for provider diagnostics real-time testing functionality on this admin page only
			?>
			<script type="text/javascript">
			/* global ajaxurl */
			jQuery(document).ready(function($) {
				// Ensure ajaxurl is defined (should be by WordPress, but adding as fallback).
				var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

				// Test provider connection.
				$('.test-provider').on('click', function() {
					var button = $(this);
					var provider = button.data('provider');
					var resultDiv = $('#' + provider + '-test-result');

					button.prop('disabled', true).text('<?php esc_attr_e( 'Testing...', 'mcp-ai-wpoos' ); ?>');
					resultDiv.html('<p><?php esc_html_e( 'Testing connection...', 'mcp-ai-wpoos' ); ?></p>');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						data: {
							action: 'wp_mcp_ai_test_provider',
							nonce: '<?php echo esc_js( wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' ) ); ?>',
							provider: provider
						},
						success: function(response) {
							if (response.success) {
								var message = response.data.message;
								var details = '';

								if (response.data.details) {
									details = '<ul>';
									$.each(response.data.details, function(key, value) {
										details += '<li><strong>' + key + ':</strong> ' + value + '</li>';
									});
									details += '</ul>';
								}

								resultDiv.html(
									'<div class="notice notice-success inline"><p><strong>' +
									'<?php esc_html_e( 'Success!', 'mcp-ai-wpoos' ); ?>' +
									'</strong> ' + message + '</p>' + details + '</div>'
								);
							} else {
								var errorMessage = (response.data && response.data.message) ? response.data.message : '<?php esc_js( __( 'Unknown error occurred', 'mcp-ai-wpoos' ) ); ?>';
								resultDiv.html(
									'<div class="notice notice-error inline"><p><strong>' +
									'<?php esc_html_e( 'Error!', 'mcp-ai-wpoos' ); ?>' +
									'</strong> ' + errorMessage + '</p></div>'
								);
							}
						},
						error: function(xhr, status, error) {
							resultDiv.html(
								'<div class="notice notice-error inline"><p><strong>' +
								'<?php esc_html_e( 'Error!', 'mcp-ai-wpoos' ); ?>' +
								'</strong> ' + error + '</p></div>'
							);
						},
						complete: function() {
							var providerName = provider.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
							button.prop('disabled', false).text('<?php esc_attr_e( 'Test', 'mcp-ai-wpoos' ); ?> ' + providerName + ' <?php esc_attr_e( 'Connection', 'mcp-ai-wpoos' ); ?>');
						}
					});
				});
			});
			</script>
			<?php
		}

		/**
		 * Handle AJAX request to test a provider.
		 */
		public static function handle_test_provider() {
			check_ajax_referer( 'wp-mcp-ai-provider-diagnostic', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';

			if ( empty( $provider ) ) {
				wp_send_json_error( array( 'message' => __( 'Provider parameter is required.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			switch ( $provider ) {
				case 'openai':
					self::test_openai( $settings );
					break;

				case 'anthropic':
					self::test_anthropic( $settings );
					break;

				case 'gemini':
					self::test_gemini( $settings );
					break;

				case 'huggingface':
					self::test_huggingface( $settings );
					break;

				case 'ollama':
					self::test_ollama( $settings );
					break;

				case 'lm_studio':
					self::test_lm_studio( $settings );
					break;

				case 'cloudflare':
					self::test_cloudflare( $settings );
					break;

				case 'embedded':
					self::test_embedded( $settings );
					break;

				case 'google_maps':
					self::test_google_maps( $settings );
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'mcp-ai-wpoos' ) ) );
					break;
			}
		}

		/**
		 * Test OpenAI connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_openai( $settings ) {
			if ( empty( $settings['openai_api_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'OpenAI client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_OpenAI_Client();

				// Simple test: list models.
				$response = wp_remote_get(
					'https://api.openai.com/v1/models',
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $settings['openai_api_key'],
							'Content-Type'  => 'application/json',
						),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: error message */
								__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
								$response->get_error_message()
							),
						)
					);
					return;
				}

				$response_code = wp_remote_retrieve_response_code( $response );

				if ( 200 !== $response_code ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %d: HTTP status code */
								__( 'API returned error code: %d', 'mcp-ai-wpoos' ),
								$response_code
							),
						)
					);
					return;
				}

				$body        = json_decode( wp_remote_retrieve_body( $response ), true );
				$model_count = isset( $body['data'] ) ? count( $body['data'] ) : 0;

				wp_send_json_success(
					array(
						'message' => __( 'OpenAI connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Models Available', 'mcp-ai-wpoos' ) => $model_count,
							__( 'Default Model', 'mcp-ai-wpoos' ) => isset( $settings['default_model'] ) ? $settings['default_model'] : 'gpt-4o-mini',
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Anthropic connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_anthropic( $settings ) {
			if ( empty( $settings['anthropic_api_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Anthropic API key is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Anthropic client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				// Test by making a simple completion request.
				$api_key = $settings['anthropic_api_key'];
				$model   = isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-sonnet-4-5';

				$response = wp_remote_post(
					'https://api.anthropic.com/v1/messages',
					array(
						'headers' => array(
							'Content-Type'      => 'application/json',
							'x-api-key'         => $api_key,
							'anthropic-version' => '2023-06-01',
						),
						'body'    => wp_json_encode(
							array(
								'model'      => $model,
								'max_tokens' => 10,
								'messages'   => array(
									array(
										'role'    => 'user',
										'content' => 'Hello',
									),
								),
							)
						),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: error message */
								__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
								$response->get_error_message()
							),
						)
					);
					return;
				}

				$response_code = wp_remote_retrieve_response_code( $response );

				if ( 200 !== $response_code ) {
					$error_body    = json_decode( wp_remote_retrieve_body( $response ), true );
					$error_message = isset( $error_body['error']['message'] ) ? $error_body['error']['message'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'API returned error code: %d', 'mcp-ai-wpoos' ),
						$response_code
					);

					wp_send_json_error(
						array(
							'message' => $error_message,
						)
					);
					return;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				wp_send_json_success(
					array(
						'message' => __( 'Anthropic connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Model', 'mcp-ai-wpoos' ) => $model,
							__( 'API Version', 'mcp-ai-wpoos' ) => '2023-06-01',
							__( 'Response Status', 'mcp-ai-wpoos' ) => isset( $body['stop_reason'] ) ? $body['stop_reason'] : __( 'OK', 'mcp-ai-wpoos' ),
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Gemini connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_gemini( $settings ) {
			if ( empty( $settings['gemini_api_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Gemini client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				// Test by listing models.
				$url = 'https://generativelanguage.googleapis.com/v1beta/models';

				$response = wp_remote_get(
					$url,
					array(
						'headers' => array(
							'x-goog-api-key' => $settings['gemini_api_key'],
						),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: error message */
								__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
								$response->get_error_message()
							),
						)
					);
					return;
				}

				$response_code = wp_remote_retrieve_response_code( $response );

				if ( 200 !== $response_code ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %d: HTTP status code */
								__( 'API returned error code: %d', 'mcp-ai-wpoos' ),
								$response_code
							),
						)
					);
					return;
				}

				$body        = json_decode( wp_remote_retrieve_body( $response ), true );
				$model_count = isset( $body['models'] ) ? count( $body['models'] ) : 0;

				wp_send_json_success(
					array(
						'message' => __( 'Gemini connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Models Available', 'mcp-ai-wpoos' ) => $model_count,
							__( 'Default Model', 'mcp-ai-wpoos' ) => isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : 'gemini-2.5-flash',
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Ollama connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_ollama( $settings ) {
			if ( empty( $settings['ollama_endpoint_url'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Ollama endpoint URL is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Ollama client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_Ollama_Client();
				$result = $client->test_connection();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
					return;
				}

				wp_send_json_success(
					array(
						'message' => __( 'Ollama connection successful!', 'mcp-ai-wpoos' ),
						'details' => $result,
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Hugging Face connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_huggingface( $settings ) {
			if ( empty( $settings['huggingface_api_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Hugging Face API key is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( empty( $settings['huggingface_endpoint_url'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Hugging Face endpoint URL is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Hugging Face client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_Huggingface_Client();
				$result = $client->test_connection();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
					return;
				}

				wp_send_json_success(
					array(
						'message' => __( 'Hugging Face connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Endpoint', 'mcp-ai-wpoos' ) => $settings['huggingface_endpoint_url'],
							__( 'Model', 'mcp-ai-wpoos' ) => isset( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : __( 'Not configured', 'mcp-ai-wpoos' ),
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test LM Studio connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_lm_studio( $settings ) {
			if ( empty( $settings['lm_studio_endpoint_url'] ) ) {
				wp_send_json_error( array( 'message' => __( 'LM Studio endpoint URL is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'LM Studio client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_LM_Studio_Client();
				$result = $client->test_connection();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
					return;
				}

				wp_send_json_success(
					array(
						'message' => __( 'LM Studio connection successful!', 'mcp-ai-wpoos' ),
						'details' => $result,
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Cloudflare Workers AI connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_cloudflare( $settings ) {
			if ( empty( $settings['enable_cloudflare'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Cloudflare Workers AI provider is not enabled.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( empty( $settings['cloudflare_api_token'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Cloudflare API token is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( empty( $settings['cloudflare_account_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Cloudflare account ID is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Cloudflare client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_Cloudflare_Client();
				$result = $client->test_connection();

				if ( is_wp_error( $result ) ) {
					$error_data = $result->get_error_data();
					$error_msg  = $result->get_error_message();

					// Include additional error details if available.
					if ( is_array( $error_data ) ) {
						if ( isset( $error_data['status'] ) ) {
							$error_msg .= ' ' . sprintf(
								/* translators: %d: HTTP status code */
								__( '(HTTP Status: %d)', 'mcp-ai-wpoos' ),
								absint( $error_data['status'] )
							);
						}
						if ( isset( $error_data['body'] ) && is_string( $error_data['body'] ) ) {
							$body_data = json_decode( $error_data['body'], true );
							if ( $body_data && isset( $body_data['errors'] ) && is_array( $body_data['errors'] ) ) {
								foreach ( $body_data['errors'] as $error ) {
									if ( isset( $error['message'] ) ) {
										$error_msg .= ' - ' . esc_html( $error['message'] );
									}
								}
							}
						}
					}

					wp_send_json_error( array( 'message' => $error_msg ) );
					return;
				}

				// Extract model count from the result if available.
				$model_count = isset( $result['model_count'] ) ? $result['model_count'] : 0;
				$account_id  = isset( $settings['cloudflare_account_id'] ) ? $settings['cloudflare_account_id'] : '';

				wp_send_json_success(
					array(
						'message' => __( 'Cloudflare Workers AI connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Account ID', 'mcp-ai-wpoos' )      => $account_id,
							__( 'Models Available', 'mcp-ai-wpoos' ) => $model_count,
							__( 'Selected Model', 'mcp-ai-wpoos' )   => isset( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : __( 'Not configured', 'mcp-ai-wpoos' ),
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Embedded LLM connection.
		 *
		 * Tests configuration for client-side WebLLM models.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_embedded( $settings ) {
			// Check if in base version (embedded not available).
			if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
				wp_send_json_error( array( 'message' => __( 'Embedded LLM provider is only available in the Pro version.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( empty( $settings['enable_embedded'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Embedded LLM provider is not enabled.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				// Get available WebLLM models (client-side).
				// All available models are listed. Models marked with * support function calling.
				$available_models = array(
					'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC' => __( 'Hermes 2 Pro Llama 3 8B (~4.5GB) - Recommended*', 'mcp-ai-wpoos' ),
					'Qwen2.5-7B-Instruct-q4f16_1-MLC'     => __( 'Qwen2.5 7B Instruct (~4.5GB)*', 'mcp-ai-wpoos' ),
					'Phi-3.5-mini-instruct-q4f16_1-MLC'   => __( 'Phi-3.5 Mini Instruct (~2.5GB)*', 'mcp-ai-wpoos' ),
					'Llama-3.2-3B-Instruct-q4f16_1-MLC'   => __( 'Llama 3.2 3B Instruct (~2GB)', 'mcp-ai-wpoos' ),
					'Qwen2.5-1.5B-Instruct-q4f16_1-MLC'   => __( 'Qwen2.5 1.5B Instruct (~1GB)*', 'mcp-ai-wpoos' ),
					'Llama-3.2-1B-Instruct-q4f16_1-MLC'   => __( 'Llama 3.2 1B Instruct (~800MB)', 'mcp-ai-wpoos' ),
					'Qwen2.5-0.5B-Instruct-q4f16_1-MLC'   => __( 'Qwen2.5 0.5B Instruct (~400MB)', 'mcp-ai-wpoos' ),
				);

				$model_count    = count( $available_models );
				$selected_model = isset( $settings['embedded_model'] ) ? $settings['embedded_model'] : '';

				// Get model name if a model is selected.
				$selected_model_name = __( 'Not configured', 'mcp-ai-wpoos' );
				if ( $selected_model && isset( $available_models[ $selected_model ] ) ) {
					$selected_model_name = $available_models[ $selected_model ];
				}

				wp_send_json_success(
					array(
						'message' => __( 'Embedded LLM configuration verified!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'Available Models', 'mcp-ai-wpoos' )  => $model_count,
							__( 'Selected Model', 'mcp-ai-wpoos' )    => $selected_model_name,
							__( 'Model Identifier', 'mcp-ai-wpoos' )  => $selected_model ? $selected_model : __( 'None', 'mcp-ai-wpoos' ),
							__( 'Model Type', 'mcp-ai-wpoos' )        => __( 'WebLLM (Client-side)', 'mcp-ai-wpoos' ),
							__( 'Runtime', 'mcp-ai-wpoos' )           => __( 'Browser (WebGPU/WebAssembly)', 'mcp-ai-wpoos' ),
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Test Google Maps connection.
		 *
		 * @param array $settings Plugin settings.
		 */
		private static function test_google_maps( $settings ) {
			if ( empty( $settings['google_maps_api_key'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Google Maps API key is not configured.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Google Maps client class not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			try {
				$client = new WP_MCP_AI_Google_Maps_Client();

				// Test with a simple geocoding request to a well-known location.
				$result = $client->geocode( 'New York City, NY, USA' );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
					return;
				}

				// Check if we got results.
				$results_count = isset( $result['results'] ) ? count( $result['results'] ) : 0;

				wp_send_json_success(
					array(
						'message' => __( 'Google Maps connection successful!', 'mcp-ai-wpoos' ),
						'details' => array(
							__( 'API Status', 'mcp-ai-wpoos' )     => __( 'Valid', 'mcp-ai-wpoos' ),
							__( 'Test Results', 'mcp-ai-wpoos' )   => $results_count > 0 ? __( 'Geocoding works', 'mcp-ai-wpoos' ) : __( 'No results', 'mcp-ai-wpoos' ),
							__( 'Results Found', 'mcp-ai-wpoos' ) => $results_count,
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
			}
		}
	}

	// Initialize the diagnostic page.
	WP_MCP_AI_Provider_Diagnostics::init();
}
