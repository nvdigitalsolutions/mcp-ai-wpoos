<?php
/**
 * Onboarding Wizard for NV oOS
 *
 * Provides a guided multi-step setup experience for new users.
 * Triggered automatically on first activation and accessible via the
 * "Getting Started" sub-menu item until the wizard is completed.
 *
 * Steps:
 *   1. Welcome       — intro and path selection
 *   2. AI Provider   — enter/test API key
 *   3. Use-Case      — choose preset template
 *   4. Finish        — summary and next-step links
 *
 * @package WP_MCP_AI
 * @since 1.1.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Onboarding_Wizard' ) ) {

	/**
	 * Multi-step onboarding wizard controller.
	 */
	class WP_MCP_AI_Onboarding_Wizard {

		/**
		 * WordPress option name that stores wizard completion state.
		 */
		const COMPLETE_OPTION = 'wp_mcp_ai_onboarding_complete';

		/**
		 * Transient name used to trigger an activation redirect.
		 */
		const REDIRECT_TRANSIENT = 'wp_mcp_ai_activation_redirect';

		/**
		 * Admin page slug for the wizard.
		 */
		const PAGE_SLUG = 'wp-mcp-ai-getting-started';

		/**
		 * User-meta key for per-user notice dismissal.
		 */
		const NOTICE_META_KEY = 'wp_mcp_ai_welcome_notice_dismissed';

		/**
		 * Total number of wizard steps.
		 */
		const TOTAL_STEPS = 4;

		/**
		 * Register all hooks.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
			add_action( 'admin_notices', array( $this, 'render_welcome_notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_wp_mcp_ai_wizard_save_step', array( $this, 'ajax_save_step' ) );
			add_action( 'wp_ajax_wp_mcp_ai_dismiss_welcome_notice', array( $this, 'ajax_dismiss_notice' ) );
			add_action( 'wp_ajax_wp_mcp_ai_wizard_complete', array( $this, 'ajax_complete_wizard' ) );
		}

		// -------------------------------------------------------------------------
		// Menu registration
		// -------------------------------------------------------------------------

		/**
		 * Register the "Getting Started" sub-menu item.
		 *
		 * The item is visible until the wizard is marked complete so users can
		 * always return to finish the setup. It is removed once the wizard is done
		 * to keep the menu clean.
		 */
		public function register_menu() {
			// Always register the page so the URL is valid (needed for AJAX redirects).
			add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Getting Started', 'mcp-ai-wpoos' ),
				$this->is_complete() ? __( 'Getting Started', 'mcp-ai-wpoos' ) : __( '⭐ Getting Started', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_wizard_page' )
			);
		}

		// -------------------------------------------------------------------------
		// Activation redirect
		// -------------------------------------------------------------------------

		/**
		 * Redirect new installs to the wizard on the first admin page load after activation.
		 *
		 * Uses a transient set during plugin activation so the redirect only fires once.
		 * Skipped on multisite network admin, AJAX requests, and bulk activations.
		 */
		public function maybe_redirect_to_wizard() {
			// Do not redirect during AJAX, CLI, or if not in admin.
			if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}

			// Do not redirect on network admin.
			if ( is_network_admin() ) {
				return;
			}

			// Check if we already redirected (transient set during activation).
			if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
				return;
			}

			// Delete the transient so we only redirect once.
			delete_transient( self::REDIRECT_TRANSIENT );

			// Do not redirect if the wizard was already completed.
			if ( $this->is_complete() ) {
				return;
			}

			// Only redirect users who can manage options.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_safe_redirect( $this->wizard_url( 1 ) );
			exit;
		}

		// -------------------------------------------------------------------------
		// Admin notice
		// -------------------------------------------------------------------------

		/**
		 * Render a dismissible welcome notice on all admin pages until the wizard is complete.
		 */
		public function render_welcome_notice() {
			// Skip if the wizard is complete.
			if ( $this->is_complete() ) {
				return;
			}

			// Skip if the current user has dismissed the notice.
			if ( get_user_meta( get_current_user_id(), self::NOTICE_META_KEY, true ) ) {
				return;
			}

			// Only show to administrators.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Do not show the notice on the wizard page itself.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page parameter only for display logic.
			$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
			if ( self::PAGE_SLUG === $current_page ) {
				return;
			}

			$wizard_url = esc_url( $this->wizard_url( 1 ) );
			$nonce      = wp_create_nonce( 'wp_mcp_ai_dismiss_welcome_notice' );
			?>
			<div class="notice notice-info is-dismissible wp-mcp-ai-welcome-notice"
				 data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<p>
					<strong><?php esc_html_e( '👋 Welcome to NV oOS!', 'mcp-ai-wpoos' ); ?></strong>
					<?php esc_html_e( 'Complete the quick setup wizard to configure your first AI assistant in under 2 minutes.', 'mcp-ai-wpoos' ); ?>
					<a href="<?php echo $wizard_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above. ?>" class="button button-primary wp-mcp-ai-welcome-notice-cta">
						<?php esc_html_e( 'Start Setup →', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<script>
			(function(){
				var notice = document.querySelector('.wp-mcp-ai-welcome-notice');
				if (!notice) return;
				notice.addEventListener('click', function(e){
					if (e.target.classList.contains('notice-dismiss')) {
						fetch(ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {'Content-Type':'application/x-www-form-urlencoded'},
							body: 'action=wp_mcp_ai_dismiss_welcome_notice&nonce=' + notice.dataset.nonce
						});
					}
				});
			})();
			</script>
			<?php
		}

		// -------------------------------------------------------------------------
		// Asset enqueue
		// -------------------------------------------------------------------------

		/**
		 * Enqueue wizard-specific CSS on the wizard admin page.
		 *
		 * @param string $hook_suffix The current admin page hook suffix.
		 */
		public function enqueue_assets( $hook_suffix ) {
			// The hook suffix for a sub-menu page is "toplevel_page_<slug>" for top-level
			// and "<parent-slug>_page_<slug>" for sub-menu pages.
			if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
				return;
			}

			// Inline styles — no external file needed, keeping the footprint minimal.
			$css = $this->get_wizard_css();
			// Register a handle-only style (no source URL) so we can attach inline styles to it.
			wp_register_style( 'wp-mcp-ai-wizard', false, array(), WP_MCP_AI_VERSION );
			wp_enqueue_style( 'wp-mcp-ai-wizard' );
			wp_add_inline_style( 'wp-mcp-ai-wizard', $css );

			// Enqueue wp.ajax dependency for AJAX calls inside the wizard.
			wp_enqueue_script( 'jquery' );
		}

		// -------------------------------------------------------------------------
		// Wizard page renderer
		// -------------------------------------------------------------------------

		/**
		 * Render the full wizard page.
		 */
		public function render_wizard_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Step is display-only; each step's form has its own nonce.
			$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
			$step = max( 1, min( self::TOTAL_STEPS, $step ) );

			?>
			<div class="wrap wp-mcp-ai-wizard-wrap">
				<?php $this->render_wizard_header( $step ); ?>
				<div class="wp-mcp-ai-wizard-body">
					<?php
					switch ( $step ) {
						case 1:
							$this->render_step_welcome();
							break;
						case 2:
							$this->render_step_provider();
							break;
						case 3:
							$this->render_step_presets();
							break;
						case 4:
							$this->render_step_finish();
							break;
					}
					?>
				</div>
				<?php $this->render_wizard_footer( $step ); ?>
			</div>
			<?php
		}

		/**
		 * Render the wizard progress header.
		 *
		 * @param int $current_step The active step number (1-based).
		 */
		private function render_wizard_header( $current_step ) {
			$steps = array(
				1 => __( 'Welcome', 'mcp-ai-wpoos' ),
				2 => __( 'AI Provider', 'mcp-ai-wpoos' ),
				3 => __( 'Use Case', 'mcp-ai-wpoos' ),
				4 => __( 'Finish', 'mcp-ai-wpoos' ),
			);

			$skip_url = esc_url(
				add_query_arg(
					array(
						'page'   => 'wp-mcp-ai-dashboard',
						'wizard' => 'skipped',
					),
					admin_url( 'admin.php' )
				)
			);
			?>
			<div class="wp-mcp-ai-wizard-header">
				<div class="wp-mcp-ai-wizard-logo">
					<span class="dashicons dashicons-format-chat"></span>
					<span class="wp-mcp-ai-wizard-brand">NV oOS</span>
				</div>

				<nav class="wp-mcp-ai-wizard-steps" aria-label="<?php esc_attr_e( 'Setup steps', 'mcp-ai-wpoos' ); ?>">
					<?php foreach ( $steps as $num => $label ) : ?>
						<div class="wp-mcp-ai-wizard-step <?php echo $num < $current_step ? 'is-complete' : ( $num === $current_step ? 'is-active' : 'is-pending' ); ?>">
							<span class="wp-mcp-ai-wizard-step-indicator">
								<?php if ( $num < $current_step ) : ?>
									<span class="dashicons dashicons-yes"></span>
								<?php else : ?>
									<?php echo esc_html( $num ); ?>
								<?php endif; ?>
							</span>
							<span class="wp-mcp-ai-wizard-step-label"><?php echo esc_html( $label ); ?></span>
						</div>
						<?php if ( $num < self::TOTAL_STEPS ) : ?>
							<div class="wp-mcp-ai-wizard-step-connector <?php echo $num < $current_step ? 'is-complete' : ''; ?>"></div>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>

				<a href="<?php echo $skip_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
				   class="wp-mcp-ai-wizard-skip"
				   onclick="return confirm('<?php esc_attr_e( 'Skip the setup wizard? You can always return via the "Getting Started" menu.', 'mcp-ai-wpoos' ); ?>')">
					<?php esc_html_e( 'Skip Setup', 'mcp-ai-wpoos' ); ?> &rsaquo;
				</a>
			</div>
			<?php
		}

		/**
		 * Render the wizard navigation footer (Back / Next buttons).
		 *
		 * @param int $current_step The active step number (1-based).
		 */
		private function render_wizard_footer( $current_step ) {
			$back_url = $current_step > 1 ? esc_url( $this->wizard_url( $current_step - 1 ) ) : '';
			$next_url = $current_step < self::TOTAL_STEPS ? esc_url( $this->wizard_url( $current_step + 1 ) ) : '';
			?>
			<div class="wp-mcp-ai-wizard-footer">
				<?php if ( $back_url ) : ?>
					<a href="<?php echo $back_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>" class="button button-secondary">
						&larr; <?php esc_html_e( 'Back', 'mcp-ai-wpoos' ); ?>
					</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>

				<?php if ( $next_url && $current_step < self::TOTAL_STEPS ) : ?>
					<a href="<?php echo $next_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>" class="button button-primary wp-mcp-ai-wizard-next">
						<?php esc_html_e( 'Next Step', 'mcp-ai-wpoos' ); ?> &rarr;
					</a>
				<?php endif; ?>
			</div>
			<?php
		}

		// -------------------------------------------------------------------------
		// Individual step renderers
		// -------------------------------------------------------------------------

		/**
		 * Step 1: Welcome screen.
		 */
		private function render_step_welcome() {
			$quick_url  = esc_url( $this->wizard_url( 2 ) );
			$expert_url = esc_url(
				add_query_arg(
					array(
						'page'   => 'wp-mcp-ai-dashboard',
						'wizard' => 'skipped',
					),
					admin_url( 'admin.php' )
				)
			);
			?>
			<div class="wp-mcp-ai-wizard-step-content">
				<div class="wp-mcp-ai-wizard-hero">
					<span class="wp-mcp-ai-wizard-hero-icon">🤖</span>
					<h1><?php esc_html_e( 'Welcome to NV oOS', 'mcp-ai-wpoos' ); ?></h1>
					<p class="wp-mcp-ai-wizard-subtitle">
						<?php esc_html_e( 'Your AI Command Center for WordPress. Connect powerful AI models, create custom assistants, and automate your workflow — all from within your dashboard.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<div class="wp-mcp-ai-wizard-paths">
					<a href="<?php echo $quick_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>" class="wp-mcp-ai-wizard-path-card wp-mcp-ai-wizard-path-primary">
						<span class="wp-mcp-ai-path-icon">⚡</span>
						<h3><?php esc_html_e( 'Quick Setup', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Get up and running in 2 minutes with a guided wizard. Recommended for most users.', 'mcp-ai-wpoos' ); ?></p>
						<span class="button button-primary"><?php esc_html_e( 'Start Quick Setup →', 'mcp-ai-wpoos' ); ?></span>
					</a>

					<a href="<?php echo $expert_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>" class="wp-mcp-ai-wizard-path-card">
						<span class="wp-mcp-ai-path-icon">⚙️</span>
						<h3><?php esc_html_e( 'Expert Mode', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Skip the wizard and go directly to the full settings dashboard. For developers and power users.', 'mcp-ai-wpoos' ); ?></p>
						<span class="button button-secondary"><?php esc_html_e( 'Go to Settings', 'mcp-ai-wpoos' ); ?></span>
					</a>
				</div>

				<div class="wp-mcp-ai-wizard-features">
					<h4><?php esc_html_e( 'What you can do with NV oOS:', 'mcp-ai-wpoos' ); ?></h4>
					<ul>
						<li>✅ <?php esc_html_e( 'Connect OpenAI, Google Gemini, or run local AI with Ollama', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Build specialized AI assistants for writing, support, and more', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( '165+ built-in tools for WordPress, media, SEO, and integrations', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Embed a live AI chat on any page with a shortcode or Elementor widget', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				</div>
			</div>
			<?php
		}

		/**
		 * Step 2: AI Provider connection.
		 */
		private function render_step_provider() {
			$settings    = get_option( 'wp_mcp_ai_settings', array() );
			$openai_key  = ! empty( $settings['openai_api_key'] ) ? '••••••••••••••••' : '';
			$gemini_key  = ! empty( $settings['gemini_api_key'] ) ? '••••••••••••••••' : '';
			$ollama_url  = ! empty( $settings['ollama_url'] ) ? esc_url( $settings['ollama_url'] ) : 'http://localhost:11434';
			$nonce       = wp_create_nonce( 'wp_mcp_ai_wizard_save_step' );
			?>
			<div class="wp-mcp-ai-wizard-step-content">
				<h2><?php esc_html_e( 'Connect Your AI Provider', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'NV oOS works with several AI providers. Enter your API key below and click "Test Connection" to confirm it works. You can always change this later in Settings → Providers.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-wizard-provider-tabs">
					<button type="button" class="wp-mcp-ai-provider-tab is-active" data-provider="openai">
						<img src="<?php echo esc_url( WP_MCP_AI_URL . 'assets/images/openai-logo.svg' ); ?>"
							 onerror="this.style.display='none'"
							 alt="" width="20" height="20" style="vertical-align:middle;margin-right:6px;">
						OpenAI
					</button>
					<button type="button" class="wp-mcp-ai-provider-tab" data-provider="gemini">
						<img src="<?php echo esc_url( WP_MCP_AI_URL . 'assets/images/gemini-logo.svg' ); ?>"
							 onerror="this.style.display='none'"
							 alt="" width="20" height="20" style="vertical-align:middle;margin-right:6px;">
						Google Gemini
					</button>
					<button type="button" class="wp-mcp-ai-provider-tab" data-provider="ollama">
						🦙 Ollama (Local)
					</button>
				</div>

				<!-- OpenAI panel -->
				<div class="wp-mcp-ai-provider-panel is-active" data-panel="openai">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_openai_key">
									<?php esc_html_e( 'OpenAI API Key', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
										   id="wp_mcp_ai_openai_key"
										   name="wp_mcp_ai_openai_key"
										   class="regular-text"
										   value="<?php echo esc_attr( $openai_key ); ?>"
										   placeholder="sk-proj-…"
										   autocomplete="off">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_openai_key">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to OpenAI API keys page */
										esc_html__( 'Get your API key from %s', 'mcp-ai-wpoos' ),
										'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="openai"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_test_connection' ) ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" data-for="openai"></span>
				</div>

				<!-- Gemini panel -->
				<div class="wp-mcp-ai-provider-panel" data-panel="gemini">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_gemini_key">
									<?php esc_html_e( 'Google Gemini API Key', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
										   id="wp_mcp_ai_gemini_key"
										   name="wp_mcp_ai_gemini_key"
										   class="regular-text"
										   value="<?php echo esc_attr( $gemini_key ); ?>"
										   placeholder="AIza…"
										   autocomplete="off">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_gemini_key">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to Google AI Studio API keys page */
										esc_html__( 'Get your API key from %s', 'mcp-ai-wpoos' ),
										'<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">aistudio.google.com</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="gemini"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_test_connection' ) ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" data-for="gemini"></span>
				</div>

				<!-- Ollama panel -->
				<div class="wp-mcp-ai-provider-panel" data-panel="ollama">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_ollama_url">
									<?php esc_html_e( 'Ollama Server URL', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="url"
									   id="wp_mcp_ai_ollama_url"
									   name="wp_mcp_ai_ollama_url"
									   class="regular-text"
									   value="<?php echo esc_attr( $ollama_url ); ?>">
								<p class="description">
									<?php esc_html_e( 'The URL of your running Ollama instance. Default: http://localhost:11434', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="ollama"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_mcp_ai_test_ollama_connection' ) ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" data-for="ollama"></span>
				</div>

				<input type="hidden" id="wp_mcp_ai_wizard_nonce" value="<?php echo esc_attr( $nonce ); ?>">

				<p class="wp-mcp-ai-wizard-skip-note">
					<a href="<?php echo esc_url( $this->wizard_url( 3 ) ); ?>">
						<?php esc_html_e( 'Skip for now — I\'ll add my API key later', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>

			<script>
			(function($){
				// Provider tabs.
				$('.wp-mcp-ai-provider-tab').on('click', function(){
					var provider = $(this).data('provider');
					$('.wp-mcp-ai-provider-tab').removeClass('is-active');
					$('.wp-mcp-ai-provider-panel').removeClass('is-active');
					$(this).addClass('is-active');
					$('[data-panel="' + provider + '"]').addClass('is-active');
				});

				// Show/hide key toggle.
				$('.wp-mcp-ai-show-key').on('click', function(){
					var targetId = $(this).data('target');
					var input = $('#' + targetId);
					if ('password' === input.attr('type')) {
						input.attr('type', 'text');
						$(this).text('<?php echo esc_js( __( 'Hide', 'mcp-ai-wpoos' ) ); ?>');
					} else {
						input.attr('type', 'password');
						$(this).text('<?php echo esc_js( __( 'Show', 'mcp-ai-wpoos' ) ); ?>');
					}
				});

				// Save API key + Test connection.
				$('.wp-mcp-ai-wizard-test-btn').on('click', function(){
					var provider = $(this).data('provider');
					var $result  = $('[data-for="' + provider + '"]');
					var $btn     = $(this);

					var apiKey  = '';
					var extraData = {};

					if ('openai' === provider) {
						apiKey = $('#wp_mcp_ai_openai_key').val();
					} else if ('gemini' === provider) {
						apiKey = $('#wp_mcp_ai_gemini_key').val();
					} else if ('ollama' === provider) {
						extraData.ollama_url = $('#wp_mcp_ai_ollama_url').val();
					}

					$result.html('<span style="color:#888"><?php echo esc_js( __( 'Testing…', 'mcp-ai-wpoos' ) ); ?></span>');
					$btn.prop('disabled', true);

					// First save the key via our wizard AJAX handler.
					$.post(ajaxurl, {
						action:   'wp_mcp_ai_wizard_save_step',
						step:     2,
						provider: provider,
						api_key:  apiKey,
						nonce:    $('#wp_mcp_ai_wizard_nonce').val(),
						extra:    extraData
					}).always(function(){
						// Then test the connection using the existing provider test AJAX actions.
						var testAction = 'wp_mcp_ai_test_connection';
						if ('ollama' === provider) {
							testAction = 'wp_mcp_ai_test_ollama_connection';
						}

						$.post(ajaxurl, {
							action:   testAction,
							provider: provider,
							nonce:    $btn.data('nonce')
						}).done(function(resp){
							if (resp && resp.success) {
								$result.html('<span style="color:#46b450">✓ <?php echo esc_js( __( 'Connected!', 'mcp-ai-wpoos' ) ); ?></span>');
							} else {
								var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Connection failed. Check your key and try again.', 'mcp-ai-wpoos' ) ); ?>';
								$result.html('<span style="color:#dc3232">✗ ' + msg + '</span>');
							}
						}).fail(function(){
							$result.html('<span style="color:#dc3232">✗ <?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos' ) ); ?></span>');
						}).always(function(){
							$btn.prop('disabled', false);
						});
					});
				});
			})(jQuery);
			</script>
			<?php
		}

		/**
		 * Step 3: Use-case preset selection.
		 */
		private function render_step_presets() {
			$presets         = $this->get_presets();
			$saved_selection = get_option( 'wp_mcp_ai_onboarding_presets', array() );
			if ( ! is_array( $saved_selection ) ) {
				$saved_selection = array();
			}
			$nonce = wp_create_nonce( 'wp_mcp_ai_wizard_save_step' );
			?>
			<div class="wp-mcp-ai-wizard-step-content">
				<h2><?php esc_html_e( 'What will you use NV oOS for?', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose one or more use cases. NV oOS will pre-configure the right tools and create a starter assistant for each selection. You can change this any time.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-wizard-presets" id="wp-mcp-ai-presets">
					<?php foreach ( $presets as $key => $preset ) : ?>
						<label class="wp-mcp-ai-preset-card <?php echo in_array( $key, $saved_selection, true ) ? 'is-selected' : ''; ?>">
							<input type="checkbox"
								   name="wp_mcp_ai_presets[]"
								   value="<?php echo esc_attr( $key ); ?>"
								   class="wp-mcp-ai-preset-checkbox"
								   <?php checked( in_array( $key, $saved_selection, true ) ); ?>>
							<span class="wp-mcp-ai-preset-icon"><?php echo esc_html( $preset['icon'] ); ?></span>
							<span class="wp-mcp-ai-preset-title"><?php echo esc_html( $preset['label'] ); ?></span>
							<span class="wp-mcp-ai-preset-desc"><?php echo esc_html( $preset['description'] ); ?></span>
							<span class="wp-mcp-ai-preset-check dashicons dashicons-yes-alt"></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="wp-mcp-ai-wizard-preset-actions">
					<button type="button"
							id="wp-mcp-ai-apply-presets"
							class="button button-primary"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Save Selection & Continue →', 'mcp-ai-wpoos' ); ?>
					</button>
					<a href="<?php echo esc_url( $this->wizard_url( 4 ) ); ?>" class="button button-link">
						<?php esc_html_e( 'Skip — I\'ll set up manually', 'mcp-ai-wpoos' ); ?>
					</a>
				</div>
				<span id="wp-mcp-ai-preset-save-result"></span>
			</div>

			<script>
			(function($){
				// Toggle card selected state on checkbox change.
				$('.wp-mcp-ai-preset-card').on('click', function(){
					var $card = $(this);
					// Let the browser toggle the checkbox first.
					setTimeout(function(){
						if ($card.find('input').is(':checked')) {
							$card.addClass('is-selected');
						} else {
							$card.removeClass('is-selected');
						}
					}, 0);
				});

				// Save presets and redirect to next step.
				$('#wp-mcp-ai-apply-presets').on('click', function(){
					var selected = [];
					$('.wp-mcp-ai-preset-checkbox:checked').each(function(){
						selected.push($(this).val());
					});

					var $btn    = $(this);
					var $result = $('#wp-mcp-ai-preset-save-result');
					$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'mcp-ai-wpoos' ) ); ?>');

					$.post(ajaxurl, {
						action:   'wp_mcp_ai_wizard_save_step',
						step:     3,
						presets:  selected,
						nonce:    $btn.data('nonce')
					}).done(function(resp){
						if (resp && resp.success) {
							window.location.href = '<?php echo esc_js( $this->wizard_url( 4 ) ); ?>';
						} else {
							$result.html('<span style="color:#dc3232"><?php echo esc_js( __( 'Could not save. Please try again.', 'mcp-ai-wpoos' ) ); ?></span>');
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Selection & Continue →', 'mcp-ai-wpoos' ) ); ?>');
						}
					}).fail(function(){
						$result.html('<span style="color:#dc3232"><?php echo esc_js( __( 'Request failed. Please try again.', 'mcp-ai-wpoos' ) ); ?></span>');
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Selection & Continue →', 'mcp-ai-wpoos' ) ); ?>');
					});
				});
			})(jQuery);
			</script>
			<?php
		}

		/**
		 * Step 4: Finish / summary screen.
		 */
		private function render_step_finish() {
			// Mark the wizard as complete.
			$this->mark_complete();

			$presets          = $this->get_presets();
			$selected_presets = get_option( 'wp_mcp_ai_onboarding_presets', array() );
			if ( ! is_array( $selected_presets ) ) {
				$selected_presets = array();
			}
			$settings    = get_option( 'wp_mcp_ai_settings', array() );
			$has_openai  = ! empty( $settings['openai_api_key'] );
			$has_gemini  = ! empty( $settings['gemini_api_key'] );
			$has_ollama  = ! empty( $settings['ollama_url'] );
			$has_api_key = $has_openai || $has_gemini || $has_ollama;
			?>
			<div class="wp-mcp-ai-wizard-step-content wp-mcp-ai-wizard-finish">
				<div class="wp-mcp-ai-wizard-hero">
					<span class="wp-mcp-ai-wizard-hero-icon">🎉</span>
					<h1><?php esc_html_e( 'You\'re All Set!', 'mcp-ai-wpoos' ); ?></h1>
					<p class="wp-mcp-ai-wizard-subtitle">
						<?php esc_html_e( 'NV oOS is configured and ready to use. Here\'s what was set up:', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<div class="wp-mcp-ai-wizard-summary">
					<div class="wp-mcp-ai-summary-item <?php echo $has_api_key ? 'is-done' : 'is-skipped'; ?>">
						<span class="dashicons <?php echo $has_api_key ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
						<?php if ( $has_api_key ) : ?>
							<?php esc_html_e( 'AI provider connected', 'mcp-ai-wpoos' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: URL to settings providers tab */
								esc_html__( 'No AI provider connected yet — %s', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ) . '">' . esc_html__( 'add your API key →', 'mcp-ai-wpoos' ) . '</a>'
							);
							?>
						<?php endif; ?>
					</div>

					<div class="wp-mcp-ai-summary-item <?php echo ! empty( $selected_presets ) ? 'is-done' : 'is-skipped'; ?>">
						<span class="dashicons <?php echo ! empty( $selected_presets ) ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
						<?php if ( ! empty( $selected_presets ) ) : ?>
							<?php
							$preset_labels = array_map(
								function ( $key ) use ( $presets ) {
									return isset( $presets[ $key ] ) ? $presets[ $key ]['label'] : $key;
								},
								$selected_presets
							);
							printf(
								/* translators: %s: comma-separated list of preset names */
								esc_html__( 'Use-case presets applied: %s', 'mcp-ai-wpoos' ),
								esc_html( implode( ', ', $preset_labels ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'No preset selected — using default configuration', 'mcp-ai-wpoos' ); ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="wp-mcp-ai-wizard-next-steps">
					<h3><?php esc_html_e( 'Next Steps', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-next-step-cards">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ); ?>" class="wp-mcp-ai-next-step-card">
							<span class="dashicons dashicons-admin-settings"></span>
							<strong><?php esc_html_e( 'View Settings', 'mcp-ai-wpoos' ); ?></strong>
							<span><?php esc_html_e( 'Explore the full settings dashboard', 'mcp-ai-wpoos' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="wp-mcp-ai-next-step-card">
							<span class="dashicons dashicons-format-chat"></span>
							<strong><?php esc_html_e( 'Manage Assistants', 'mcp-ai-wpoos' ); ?></strong>
							<span><?php esc_html_e( 'View and customize your AI assistants', 'mcp-ai-wpoos' ); ?></span>
						</a>
						<a href="https://nvdigitalsolutions.com/wpoos/docs" target="_blank" rel="noopener noreferrer" class="wp-mcp-ai-next-step-card">
							<span class="dashicons dashicons-book-alt"></span>
							<strong><?php esc_html_e( 'Read the Docs', 'mcp-ai-wpoos' ); ?></strong>
							<span><?php esc_html_e( 'Learn advanced features and integrations', 'mcp-ai-wpoos' ); ?></span>
						</a>
					</div>
				</div>

				<div class="wp-mcp-ai-shortcode-info">
					<h4><?php esc_html_e( '💡 Embed the AI Chat on Any Page', 'mcp-ai-wpoos' ); ?></h4>
					<p><?php esc_html_e( 'Use the following shortcode to add an AI chat widget to any page or post:', 'mcp-ai-wpoos' ); ?></p>
					<code class="wp-mcp-ai-shortcode">[mcp_ai_chat]</code>
					<p class="description">
						<?php esc_html_e( 'Or use the NV oOS Elementor widget for drag-and-drop placement.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
			</div>
			<?php
		}

		// -------------------------------------------------------------------------
		// AJAX handlers
		// -------------------------------------------------------------------------

		/**
		 * AJAX handler: save wizard step data (step 2 API key, step 3 presets).
		 */
		public function ajax_save_step() {
			check_ajax_referer( 'wp_mcp_ai_wizard_save_step', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			}

			$step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;

			if ( 2 === $step ) {
				$this->handle_save_provider_step();
			} elseif ( 3 === $step ) {
				$this->handle_save_presets_step();
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown step.', 'mcp-ai-wpoos' ) ) );
			}
		}

		/**
		 * Save the provider API key from step 2.
		 */
		private function handle_save_provider_step() {
			$provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
			// API keys are typically alphanumeric with hyphens, dashes, and underscores.
			// Using sanitize_text_field + wp_unslash is the standard WordPress approach.
			$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			$valid_providers = array( 'openai', 'gemini', 'ollama' );
			if ( ! in_array( $provider, $valid_providers, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'mcp-ai-wpoos' ) ) );
			}

			// Do not overwrite a real key if the user saw the masked placeholder.
			if ( '' === $api_key || '••••••••••••••••' === $api_key ) {
				wp_send_json_success( array( 'message' => __( 'No change to API key.', 'mcp-ai-wpoos' ) ) );
			}

			$settings = get_option( 'wp_mcp_ai_settings', array() );

			if ( 'openai' === $provider ) {
				$settings['openai_api_key'] = $api_key;
			} elseif ( 'gemini' === $provider ) {
				$settings['gemini_api_key'] = $api_key;
			} elseif ( 'ollama' === $provider ) {
				// For Ollama, save the URL rather than an API key.
				// Unslash the extra array first, then apply esc_url_raw directly to the URL value.
				$extra_raw  = isset( $_POST['extra'] ) && is_array( $_POST['extra'] ) ? wp_unslash( $_POST['extra'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below with esc_url_raw.
				$ollama_url = ! empty( $extra_raw['ollama_url'] ) ? esc_url_raw( wp_unslash( (string) $extra_raw['ollama_url'] ) ) : '';
				if ( $ollama_url ) {
					$settings['ollama_url'] = $ollama_url;
				}
			}

			update_option( 'wp_mcp_ai_settings', $settings );
			wp_send_json_success( array( 'message' => __( 'Provider settings saved.', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * Save the preset selection from step 3.
		 */
		private function handle_save_presets_step() {
			// Unslash the raw POST data first, then validate the structure, then sanitize each key.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below with array_map+sanitize_key.
			$post_presets = isset( $_POST['presets'] ) ? wp_unslash( $_POST['presets'] ) : array();
			$raw_presets  = is_array( $post_presets ) ? $post_presets : array();
			$presets      = array_map( 'sanitize_key', $raw_presets );

			$valid_keys = array_keys( $this->get_presets() );
			$presets    = array_filter(
				$presets,
				function ( $key ) use ( $valid_keys ) {
					return in_array( $key, $valid_keys, true );
				}
			);

			update_option( 'wp_mcp_ai_onboarding_presets', array_values( $presets ) );
			wp_send_json_success( array( 'message' => __( 'Presets saved.', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * AJAX handler: dismiss the welcome admin notice for the current user.
		 */
		public function ajax_dismiss_notice() {
			check_ajax_referer( 'wp_mcp_ai_dismiss_welcome_notice', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error();
			}

			update_user_meta( get_current_user_id(), self::NOTICE_META_KEY, '1' );
			wp_send_json_success();
		}

		/**
		 * AJAX handler: explicitly mark the wizard as complete.
		 */
		public function ajax_complete_wizard() {
			check_ajax_referer( 'wp_mcp_ai_wizard_complete', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error();
			}

			$this->mark_complete();
			wp_send_json_success();
		}

		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------

		/**
		 * Return the full admin URL for a given wizard step.
		 *
		 * @param int $step Step number (1-based).
		 * @return string Admin URL.
		 */
		private function wizard_url( $step ) {
			return add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'step' => absint( $step ),
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Check whether the wizard has been completed.
		 *
		 * @return bool True if completed.
		 */
		public function is_complete() {
			return (bool) get_option( self::COMPLETE_OPTION, false );
		}

		/**
		 * Mark the wizard as completed.
		 */
		private function mark_complete() {
			update_option( self::COMPLETE_OPTION, 1 );
		}

		/**
		 * Return the filterable array of onboarding presets.
		 *
		 * @return array Preset definitions keyed by preset slug.
		 */
		public function get_presets() {
			$defaults = array(
				'content_creator'  => array(
					'label'       => __( 'Content Creator / Blogger', 'mcp-ai-wpoos' ),
					'icon'        => '✍️',
					'description' => __( 'Write blog posts, social media content, and email campaigns.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'create_post', 'rewrite_content', 'summarize_content' ),
					'assistant'   => __( 'Blog Writing Assistant', 'mcp-ai-wpoos' ),
				),
				'customer_support' => array(
					'label'       => __( 'Customer Support Bot', 'mcp-ai-wpoos' ),
					'icon'        => '🎧',
					'description' => __( 'Answer FAQs, handle support requests, and greet visitors.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'search_posts', 'get_post', 'send_email' ),
					'assistant'   => __( 'Support Assistant', 'mcp-ai-wpoos' ),
				),
				'ecommerce'        => array(
					'label'       => __( 'E-commerce Assistant', 'mcp-ai-wpoos' ),
					'icon'        => '🛒',
					'description' => __( 'Write product descriptions and assist shoppers.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'create_post', 'get_post' ),
					'assistant'   => __( 'E-commerce Assistant', 'mcp-ai-wpoos' ),
				),
				'seo_research'     => array(
					'label'       => __( 'SEO & Research', 'mcp-ai-wpoos' ),
					'icon'        => '🔍',
					'description' => __( 'Research topics, analyze keywords, and optimize content.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'brave_search', 'summarize_content' ),
					'assistant'   => __( 'Research Assistant', 'mcp-ai-wpoos' ),
				),
				'developer'        => array(
					'label'       => __( 'Developer Copilot', 'mcp-ai-wpoos' ),
					'icon'        => '💻',
					'description' => __( 'Code review, WP-CLI commands, and developer tools.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'run_wp_cli', 'create_snippet' ),
					'assistant'   => __( 'Developer Assistant', 'mcp-ai-wpoos' ),
				),
				'general'          => array(
					'label'       => __( 'General Purpose', 'mcp-ai-wpoos' ),
					'icon'        => '🤖',
					'description' => __( 'A balanced assistant for everyday AI tasks.', 'mcp-ai-wpoos' ),
					'tools'       => array( 'create_post', 'search_posts', 'summarize_content' ),
					'assistant'   => __( 'General Assistant', 'mcp-ai-wpoos' ),
				),
			);

			/**
			 * Filter the onboarding preset definitions.
			 *
			 * Third-party addons can add, remove, or modify presets.
			 *
			 * @since 1.1.5
			 * @param array $defaults Default preset definitions keyed by preset slug.
			 */
			return apply_filters( 'wp_mcp_ai_onboarding_presets', $defaults );
		}

		// -------------------------------------------------------------------------
		// Inline CSS
		// -------------------------------------------------------------------------

		/**
		 * Return the inline CSS for the wizard page.
		 *
		 * @return string CSS string.
		 */
		private function get_wizard_css() {
			return '
			/* NV oOS Onboarding Wizard Styles */
			.wp-mcp-ai-wizard-wrap {
				max-width: 860px;
				margin: 30px auto;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			}

			/* Header */
			.wp-mcp-ai-wizard-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 8px 8px 0 0;
				padding: 18px 24px;
				margin-bottom: 0;
			}
			.wp-mcp-ai-wizard-logo {
				display: flex;
				align-items: center;
				gap: 8px;
				font-size: 1.1em;
				font-weight: 700;
				color: #1d2327;
			}
			.wp-mcp-ai-wizard-logo .dashicons {
				font-size: 24px;
				color: #2271b1;
			}

			/* Step indicators */
			.wp-mcp-ai-wizard-steps {
				display: flex;
				align-items: center;
				gap: 0;
			}
			.wp-mcp-ai-wizard-step {
				display: flex;
				align-items: center;
				gap: 6px;
				font-size: 0.85em;
				color: #8c8f94;
			}
			.wp-mcp-ai-wizard-step.is-active {
				color: #2271b1;
				font-weight: 600;
			}
			.wp-mcp-ai-wizard-step.is-complete {
				color: #46b450;
			}
			.wp-mcp-ai-wizard-step-indicator {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 26px;
				height: 26px;
				border-radius: 50%;
				background: #f0f0f1;
				font-weight: 700;
				font-size: 0.9em;
				color: inherit;
				border: 2px solid currentColor;
			}
			.wp-mcp-ai-wizard-step.is-active .wp-mcp-ai-wizard-step-indicator {
				background: #2271b1;
				color: #fff;
			}
			.wp-mcp-ai-wizard-step.is-complete .wp-mcp-ai-wizard-step-indicator {
				background: #46b450;
				color: #fff;
				border-color: #46b450;
			}
			.wp-mcp-ai-wizard-step-connector {
				width: 32px;
				height: 2px;
				background: #e0e0e0;
				margin: 0 4px;
			}
			.wp-mcp-ai-wizard-step-connector.is-complete {
				background: #46b450;
			}
			.wp-mcp-ai-wizard-skip {
				font-size: 0.85em;
				color: #8c8f94;
				text-decoration: none;
			}
			.wp-mcp-ai-wizard-skip:hover {
				color: #1d2327;
			}

			/* Body */
			.wp-mcp-ai-wizard-body {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-top: none;
				border-bottom: none;
				padding: 32px 40px;
			}

			/* Footer */
			.wp-mcp-ai-wizard-footer {
				display: flex;
				justify-content: space-between;
				align-items: center;
				background: #f6f7f7;
				border: 1px solid #e0e0e0;
				border-top: none;
				border-radius: 0 0 8px 8px;
				padding: 14px 24px;
			}

			/* Step content */
			.wp-mcp-ai-wizard-step-content h2 {
				margin-top: 0;
				font-size: 1.5em;
				color: #1d2327;
			}
			.wp-mcp-ai-wizard-step-content .description {
				color: #50575e;
				margin-bottom: 24px;
			}

			/* Hero (steps 1 & 4) */
			.wp-mcp-ai-wizard-hero {
				text-align: center;
				margin-bottom: 32px;
			}
			.wp-mcp-ai-wizard-hero-icon {
				font-size: 3em;
				display: block;
				margin-bottom: 8px;
			}
			.wp-mcp-ai-wizard-hero h1 {
				font-size: 2em;
				margin: 0 0 12px;
				color: #1d2327;
			}
			.wp-mcp-ai-wizard-subtitle {
				font-size: 1.05em;
				color: #50575e;
				max-width: 560px;
				margin: 0 auto;
			}

			/* Path cards (step 1) */
			.wp-mcp-ai-wizard-paths {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 20px;
				margin-bottom: 32px;
			}
			.wp-mcp-ai-wizard-path-card {
				display: block;
				border: 2px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px;
				text-decoration: none;
				color: #1d2327;
				transition: border-color 0.15s, box-shadow 0.15s;
			}
			.wp-mcp-ai-wizard-path-card:hover {
				border-color: #2271b1;
				box-shadow: 0 2px 8px rgba(34, 113, 177, 0.15);
				color: #1d2327;
			}
			.wp-mcp-ai-wizard-path-primary {
				border-color: #2271b1;
			}
			.wp-mcp-ai-path-icon {
				font-size: 2em;
				display: block;
				margin-bottom: 12px;
			}
			.wp-mcp-ai-wizard-path-card h3 {
				margin: 0 0 8px;
				font-size: 1.1em;
			}
			.wp-mcp-ai-wizard-path-card p {
				color: #50575e;
				font-size: 0.9em;
				margin-bottom: 16px;
			}
			.wp-mcp-ai-wizard-features ul {
				list-style: none;
				padding: 0;
				margin: 0;
			}
			.wp-mcp-ai-wizard-features li {
				padding: 4px 0;
				color: #50575e;
			}

			/* Provider tabs (step 2) */
			.wp-mcp-ai-wizard-provider-tabs {
				display: flex;
				gap: 8px;
				margin-bottom: 20px;
				border-bottom: 2px solid #e0e0e0;
				padding-bottom: 0;
			}
			.wp-mcp-ai-provider-tab {
				background: none;
				border: none;
				padding: 8px 16px;
				cursor: pointer;
				font-size: 0.95em;
				color: #50575e;
				border-bottom: 3px solid transparent;
				margin-bottom: -2px;
				border-radius: 0;
			}
			.wp-mcp-ai-provider-tab:hover {
				color: #2271b1;
			}
			.wp-mcp-ai-provider-tab.is-active {
				color: #2271b1;
				border-bottom-color: #2271b1;
				font-weight: 600;
			}
			.wp-mcp-ai-provider-panel {
				display: none;
			}
			.wp-mcp-ai-provider-panel.is-active {
				display: block;
			}
			.wp-mcp-ai-wizard-skip-note {
				margin-top: 16px;
				font-size: 0.9em;
			}
			.wp-mcp-ai-test-result {
				margin-left: 12px;
			}
			.wp-mcp-ai-welcome-notice-cta {
				margin-left: 10px;
			}

			/* Preset cards (step 3) */
			.wp-mcp-ai-wizard-presets {
				display: grid;
				grid-template-columns: repeat(3, 1fr);
				gap: 16px;
				margin-bottom: 24px;
			}
			.wp-mcp-ai-preset-card {
				position: relative;
				border: 2px solid #e0e0e0;
				border-radius: 8px;
				padding: 16px;
				cursor: pointer;
				transition: border-color 0.15s, box-shadow 0.15s;
				display: flex;
				flex-direction: column;
				gap: 4px;
				user-select: none;
			}
			.wp-mcp-ai-preset-card:hover {
				border-color: #2271b1;
			}
			.wp-mcp-ai-preset-card.is-selected {
				border-color: #2271b1;
				background: #f0f6ff;
			}
			.wp-mcp-ai-preset-card input[type="checkbox"] {
				position: absolute;
				opacity: 0;
				width: 0;
				height: 0;
			}
			.wp-mcp-ai-preset-icon {
				font-size: 1.8em;
			}
			.wp-mcp-ai-preset-title {
				font-weight: 600;
				font-size: 0.9em;
				color: #1d2327;
			}
			.wp-mcp-ai-preset-desc {
				font-size: 0.8em;
				color: #50575e;
				line-height: 1.4;
			}
			.wp-mcp-ai-preset-check {
				position: absolute;
				top: 8px;
				right: 8px;
				color: #2271b1;
				opacity: 0;
				font-size: 18px;
			}
			.wp-mcp-ai-preset-card.is-selected .wp-mcp-ai-preset-check {
				opacity: 1;
			}
			.wp-mcp-ai-wizard-preset-actions {
				display: flex;
				gap: 12px;
				align-items: center;
			}

			/* Finish / summary (step 4) */
			.wp-mcp-ai-wizard-summary {
				background: #f6f7f7;
				border-radius: 8px;
				padding: 20px 24px;
				margin-bottom: 28px;
			}
			.wp-mcp-ai-summary-item {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 8px 0;
				font-size: 0.95em;
				border-bottom: 1px solid #e0e0e0;
			}
			.wp-mcp-ai-summary-item:last-child {
				border-bottom: none;
			}
			.wp-mcp-ai-summary-item.is-done .dashicons { color: #46b450; }
			.wp-mcp-ai-summary-item.is-skipped .dashicons { color: #dba617; }

			.wp-mcp-ai-wizard-next-steps h3 {
				margin-top: 0;
			}
			.wp-mcp-ai-next-step-cards {
				display: grid;
				grid-template-columns: repeat(3, 1fr);
				gap: 16px;
				margin-bottom: 28px;
			}
			.wp-mcp-ai-next-step-card {
				display: flex;
				flex-direction: column;
				gap: 6px;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 16px;
				text-decoration: none;
				color: #1d2327;
				transition: border-color 0.15s;
			}
			.wp-mcp-ai-next-step-card:hover {
				border-color: #2271b1;
				color: #1d2327;
			}
			.wp-mcp-ai-next-step-card .dashicons {
				font-size: 24px;
				color: #2271b1;
			}
			.wp-mcp-ai-next-step-card strong {
				font-size: 0.95em;
			}
			.wp-mcp-ai-next-step-card span:not(.dashicons) {
				font-size: 0.8em;
				color: #50575e;
			}

			.wp-mcp-ai-shortcode-info {
				background: #f0f6ff;
				border: 1px solid #c3d9f5;
				border-radius: 8px;
				padding: 16px 20px;
			}
			.wp-mcp-ai-shortcode-info h4 {
				margin-top: 0;
			}
			.wp-mcp-ai-shortcode {
				display: inline-block;
				background: #1d2327;
				color: #7dd3fc;
				padding: 6px 14px;
				border-radius: 4px;
				font-size: 1em;
				letter-spacing: 0.02em;
			}

			/* Responsive */
			@media (max-width: 700px) {
				.wp-mcp-ai-wizard-paths,
				.wp-mcp-ai-wizard-presets,
				.wp-mcp-ai-next-step-cards {
					grid-template-columns: 1fr;
				}
				.wp-mcp-ai-wizard-steps {
					display: none;
				}
			}
			';
		}
	}
}
