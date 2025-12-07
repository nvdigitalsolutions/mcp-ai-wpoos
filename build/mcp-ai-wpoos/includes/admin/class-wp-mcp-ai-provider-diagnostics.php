<?php
/**
 * WP oOS Provider Connectivity Diagnostic Page
 *
 * Test connectivity and configuration for all AI providers:
 * OpenAI, Google Gemini, Ollama (local AI), and LM Studio.
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
				__( 'Provider Connectivity Diagnostic', 'wp-mcp-ai' ),
				__( 'WP oOS Provider Test', 'wp-mcp-ai' ),
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
				<h1><?php esc_html_e( 'AI Provider Connectivity Diagnostics', 'wp-mcp-ai' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Test connectivity and configuration for all AI providers including OpenAI, Google Gemini, Ollama, and LM Studio.', 'wp-mcp-ai' ); ?>
				</p>

				<!-- OpenAI -->
				<div class="card">
					<h2><?php esc_html_e( '1. OpenAI', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['openai_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'wp-mcp-ai' ); ?></span>
										<code><?php echo esc_html( substr( $settings['openai_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Default Model', 'wp-mcp-ai' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['default_model'] ) ? $settings['default_model'] : 'gpt-4.1-mini' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Request Timeout', 'wp-mcp-ai' ); ?></th>
								<td><?php echo esc_html( isset( $settings['request_timeout'] ) ? $settings['request_timeout'] : 30 ); ?> <?php esc_html_e( 'seconds', 'wp-mcp-ai' ); ?></td>
							</tr>
						</tbody>
					</table>

					<div id="openai-test-result" style="margin: 15px 0;"></div>

					<button 
						type="button" 
						class="button button-primary test-provider" 
						data-provider="openai"
						<?php echo esc_attr( empty( $settings['openai_api_key'] ) ? 'disabled' : '' ); ?>>
						<?php esc_html_e( 'Test OpenAI Connection', 'wp-mcp-ai' ); ?>
					</button>

					<?php if ( empty( $settings['openai_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your OpenAI API key in settings to enable testing.', 'wp-mcp-ai' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'wp-mcp-ai' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<!-- Google Gemini -->
				<div class="card">
					<h2><?php esc_html_e( '2. Google Gemini', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'API Key Configured', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['gemini_api_key'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Yes', 'wp-mcp-ai' ); ?></span>
										<code><?php echo esc_html( substr( $settings['gemini_api_key'], 0, 12 ) . '...' ); ?></code>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Not Configured', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Default Model', 'wp-mcp-ai' ); ?></th>
								<td><code><?php echo esc_html( isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : 'gemini-2.5-flash' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'High Token Fallback Model', 'wp-mcp-ai' ); ?></th>
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
						<?php esc_html_e( 'Test Gemini Connection', 'wp-mcp-ai' ); ?>
					</button>

					<?php if ( empty( $settings['gemini_api_key'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Google Gemini API key in settings to enable testing.', 'wp-mcp-ai' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'wp-mcp-ai' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<!-- Ollama (Local AI) -->
				<div class="card">
					<h2><?php esc_html_e( '3. Ollama (Local AI)', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Endpoint URL', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['ollama_endpoint_url'] ) ) : ?>
										<code><?php echo esc_html( $settings['ollama_endpoint_url'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['ollama_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['ollama_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'wp-mcp-ai' ); ?>
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
						<?php esc_html_e( 'Test Ollama Connection', 'wp-mcp-ai' ); ?>
					</button>

					<?php if ( empty( $settings['ollama_endpoint_url'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your Ollama endpoint URL in settings. Typically http://localhost:11434', 'wp-mcp-ai' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'wp-mcp-ai' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Note: Ollama must be running on your local machine or accessible network.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- LM Studio (Local AI) -->
				<div class="card">
					<h2><?php esc_html_e( '4. LM Studio (Local AI)', 'wp-mcp-ai' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Endpoint URL', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) : ?>
										<code><?php echo esc_html( $settings['lm_studio_endpoint_url'] ); ?></code>
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'Not Configured', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Selected Model', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['lm_studio_model'] ) ) : ?>
										<code><?php echo esc_html( $settings['lm_studio_model'] ); ?></code>
									<?php else : ?>
										<?php esc_html_e( 'Not Selected', 'wp-mcp-ai' ); ?>
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
						<?php esc_html_e( 'Test LM Studio Connection', 'wp-mcp-ai' ); ?>
					</button>

					<?php if ( empty( $settings['lm_studio_endpoint_url'] ) ) : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Configure your LM Studio endpoint URL in settings. Typically http://127.0.0.1:1234', 'wp-mcp-ai' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
								<?php esc_html_e( 'Go to Settings', 'wp-mcp-ai' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="description" style="margin-top: 10px;">
							<?php esc_html_e( 'Note: LM Studio must be running with the local server enabled.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Provider Summary -->
				<div class="card">
					<h2><?php esc_html_e( '5. Provider Summary', 'wp-mcp-ai' ); ?></h2>
					<?php
					$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';
					$configured       = array();

					if ( ! empty( $settings['openai_api_key'] ) ) {
						$configured[] = 'OpenAI';
					}
					if ( ! empty( $settings['gemini_api_key'] ) ) {
						$configured[] = 'Gemini';
					}
					if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
						$configured[] = 'Ollama';
					}
					if ( ! empty( $settings['lm_studio_endpoint_url'] ) ) {
						$configured[] = 'LM Studio';
					}
					?>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Default Provider', 'wp-mcp-ai' ); ?></th>
								<td><code><?php echo esc_html( ucfirst( str_replace( '_', ' ', $default_provider ) ) ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Configured Providers', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $configured ) ) : ?>
										<?php echo esc_html( implode( ', ', $configured ) ); ?>
										(<?php echo count( $configured ); ?> <?php esc_html_e( 'total', 'wp-mcp-ai' ); ?>)
									<?php else : ?>
										<span style="color: orange;">⚠ <?php esc_html_e( 'No providers configured', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'High Token Model Switch', 'wp-mcp-ai' ); ?></th>
								<td>
									<?php if ( ! empty( $settings['enable_high_token_model_switch'] ) ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'Enabled', 'wp-mcp-ai' ); ?></span>
										→ <code><?php echo esc_html( isset( $settings['high_token_fallback_model'] ) ? $settings['high_token_fallback_model'] : 'gemini-2.5-flash' ); ?></code>
									<?php else : ?>
										<span style="color: gray;">— <?php esc_html_e( 'Disabled', 'wp-mcp-ai' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<?php if ( empty( $configured ) ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<strong><?php esc_html_e( 'No AI providers configured!', 'wp-mcp-ai' ); ?></strong>
								<?php esc_html_e( 'Configure at least one provider to use the AI assistant features.', 'wp-mcp-ai' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>">
									<?php esc_html_e( 'Configure Now', 'wp-mcp-ai' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<!-- Troubleshooting -->
				<div class="card">
					<h2><?php esc_html_e( '6. Troubleshooting Guide', 'wp-mcp-ai' ); ?></h2>
					
					<h3><?php esc_html_e( 'Common Issues:', 'wp-mcp-ai' ); ?></h3>
					<ul>
						<li>
							<strong><?php esc_html_e( 'OpenAI connection fails:', 'wp-mcp-ai' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check OpenAI account billing and usage limits', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Ensure server can connect to api.openai.com (not blocked by firewall)', 'wp-mcp-ai' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Gemini connection fails:', 'wp-mcp-ai' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify API key is correct and active', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check Google AI Studio for API quota limits', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Ensure Generative Language API is enabled in Google Cloud Console', 'wp-mcp-ai' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'Ollama connection fails:', 'wp-mcp-ai' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify Ollama is running (ollama serve)', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check endpoint URL is correct (typically http://localhost:11434)', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Ensure selected model is installed (ollama list)', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check firewall settings if running on different machine', 'wp-mcp-ai' ); ?></li>
							</ul>
						</li>
						<li>
							<strong><?php esc_html_e( 'LM Studio connection fails:', 'wp-mcp-ai' ); ?></strong>
							<ul>
								<li><?php esc_html_e( 'Verify LM Studio local server is running', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check endpoint URL matches LM Studio server address', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Ensure a model is loaded in LM Studio', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Check CORS settings if accessing from different origin', 'wp-mcp-ai' ); ?></li>
							</ul>
						</li>
					</ul>

					<h3><?php esc_html_e( 'Useful Links:', 'wp-mcp-ai' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>"><?php esc_html_e( 'Plugin Settings', 'wp-mcp-ai' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-mcp-ai-mcp-diagnostic' ) ); ?>"><?php esc_html_e( 'MCP Server Diagnostic', 'wp-mcp-ai' ); ?></a></li>
						<li><a href="https://platform.openai.com/api-keys" target="_blank"><?php esc_html_e( 'OpenAI API Keys', 'wp-mcp-ai' ); ?></a></li>
						<li><a href="https://aistudio.google.com/app/apikey" target="_blank"><?php esc_html_e( 'Google AI Studio', 'wp-mcp-ai' ); ?></a></li>
						<li><a href="https://ollama.com/" target="_blank"><?php esc_html_e( 'Ollama Documentation', 'wp-mcp-ai' ); ?></a></li>
						<li><a href="https://lmstudio.ai/" target="_blank"><?php esc_html_e( 'LM Studio Download', 'wp-mcp-ai' ); ?></a></li>
					</ul>
				</div>
			</div>

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
					
					button.prop('disabled', true).text('<?php esc_attr_e( 'Testing...', 'wp-mcp-ai' ); ?>');
					resultDiv.html('<p><?php esc_html_e( 'Testing connection...', 'wp-mcp-ai' ); ?></p>');

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
									'<?php esc_html_e( 'Success!', 'wp-mcp-ai' ); ?>' +
									'</strong> ' + message + '</p>' + details + '</div>'
								);
							} else {
								var errorMessage = (response.data && response.data.message) ? response.data.message : '<?php esc_js( __( 'Unknown error occurred', 'wp-mcp-ai' ) ); ?>';
								resultDiv.html(
									'<div class="notice notice-error inline"><p><strong>' +
									'<?php esc_html_e( 'Error!', 'wp-mcp-ai' ); ?>' +
									'</strong> ' + errorMessage + '</p></div>'
								);
							}
						},
						error: function(xhr, status, error) {
							resultDiv.html(
								'<div class="notice notice-error inline"><p><strong>' +
								'<?php esc_html_e( 'Error!', 'wp-mcp-ai' ); ?>' +
								'</strong> ' + error + '</p></div>'
							);
						},
						complete: function() {
							var providerName = provider.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
							button.prop('disabled', false).text('<?php esc_attr_e( 'Test', 'wp-mcp-ai' ); ?> ' + providerName + ' <?php esc_attr_e( 'Connection', 'wp-mcp-ai' ); ?>');
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';

			if ( empty( $provider ) ) {
				wp_send_json_error( array( 'message' => __( 'Provider parameter is required.', 'wp-mcp-ai' ) ) );
				return;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			switch ( $provider ) {
				case 'openai':
					self::test_openai( $settings );
					break;

				case 'gemini':
					self::test_gemini( $settings );
					break;

				case 'ollama':
					self::test_ollama( $settings );
					break;

				case 'lm_studio':
					self::test_lm_studio( $settings );
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'wp-mcp-ai' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'OpenAI API key is not configured.', 'wp-mcp-ai' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'OpenAI client class not found.', 'wp-mcp-ai' ) ) );
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
								__( 'Connection failed: %s', 'wp-mcp-ai' ),
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
								__( 'API returned error code: %d', 'wp-mcp-ai' ),
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
						'message' => __( 'OpenAI connection successful!', 'wp-mcp-ai' ),
						'details' => array(
							__( 'Models Available', 'wp-mcp-ai' ) => $model_count,
							__( 'Default Model', 'wp-mcp-ai' ) => isset( $settings['default_model'] ) ? $settings['default_model'] : 'gpt-4.1-mini',
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'wp-mcp-ai' ),
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
				wp_send_json_error( array( 'message' => __( 'Gemini API key is not configured.', 'wp-mcp-ai' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Gemini client class not found.', 'wp-mcp-ai' ) ) );
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
								__( 'Connection failed: %s', 'wp-mcp-ai' ),
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
								__( 'API returned error code: %d', 'wp-mcp-ai' ),
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
						'message' => __( 'Gemini connection successful!', 'wp-mcp-ai' ),
						'details' => array(
							__( 'Models Available', 'wp-mcp-ai' ) => $model_count,
							__( 'Default Model', 'wp-mcp-ai' ) => isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : 'gemini-2.5-flash',
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'wp-mcp-ai' ),
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
				wp_send_json_error( array( 'message' => __( 'Ollama endpoint URL is not configured.', 'wp-mcp-ai' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'Ollama client class not found.', 'wp-mcp-ai' ) ) );
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
						'message' => __( 'Ollama connection successful!', 'wp-mcp-ai' ),
						'details' => $result,
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'wp-mcp-ai' ),
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
				wp_send_json_error( array( 'message' => __( 'LM Studio endpoint URL is not configured.', 'wp-mcp-ai' ) ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
				wp_send_json_error( array( 'message' => __( 'LM Studio client class not found.', 'wp-mcp-ai' ) ) );
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
						'message' => __( 'LM Studio connection successful!', 'wp-mcp-ai' ),
						'details' => $result,
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Test failed: %s', 'wp-mcp-ai' ),
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
