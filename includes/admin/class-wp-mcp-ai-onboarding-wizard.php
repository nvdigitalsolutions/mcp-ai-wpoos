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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
			$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
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
			<?php
				ob_start();
			?>
				/* Minimal inline dismiss handler — runs outside the wizard page. */
				(function(){
					var n = document.querySelector('.wp-mcp-ai-welcome-notice');
					if (!n) return;
					n.addEventListener('click', function(e){
						if (e.target.classList.contains('notice-dismiss')) {
							var fd = new FormData();
							fd.append('action', 'wp_mcp_ai_dismiss_welcome_notice');
							fd.append('nonce', n.dataset.nonce);
							fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd});
						}
					});
				})();
				<?php
				$js = ob_get_clean();
				wp_print_inline_script_tag( $js );
		}

		// -------------------------------------------------------------------------
		// Asset enqueue
		// -------------------------------------------------------------------------

		/**
		 * Enqueue wizard-specific CSS and JavaScript on the wizard admin page.
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

			// Enqueue the wizard JavaScript with jQuery dependency.
			wp_enqueue_script(
				'wp-mcp-ai-wizard',
				WP_MCP_AI_URL . 'assets/js/onboarding-wizard.js',
				array( 'jquery' ),
				WP_MCP_AI_VERSION,
				true
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Step is display-only for localization.
			$current_step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
			$next_step    = min( $current_step + 1, self::TOTAL_STEPS );

			wp_localize_script(
				'wp-mcp-ai-wizard',
				'wpMcpAiWizard',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'completeNonce' => wp_create_nonce( 'wp_mcp_ai_wizard_complete' ),
					'nextStepUrl'   => esc_url( $this->wizard_url( $next_step ) ),
					'i18n'          => array(
						'show'             => __( 'Show', 'mcp-ai-wpoos' ),
						'hide'             => __( 'Hide', 'mcp-ai-wpoos' ),
						'showKey'          => __( 'Show API key', 'mcp-ai-wpoos' ),
						'hideKey'          => __( 'Hide API key', 'mcp-ai-wpoos' ),
						'testing'          => __( 'Testing…', 'mcp-ai-wpoos' ),
						'connected'        => __( 'Connected!', 'mcp-ai-wpoos' ),
						'connectionFailed' => __( 'Connection failed. Check your key and try again.', 'mcp-ai-wpoos' ),
						'requestFailed'    => __( 'Request failed. Please try again.', 'mcp-ai-wpoos' ),
						'saving'           => __( 'Saving…', 'mcp-ai-wpoos' ),
						'saveFailed'       => __( 'Could not save. Please try again.', 'mcp-ai-wpoos' ),
						'saveAndContinue'  => __( 'Save Selection & Continue →', 'mcp-ai-wpoos' ),
						'completed'        => __( 'Setup Complete ✓', 'mcp-ai-wpoos' ),
						'setupComplete'    => __( 'Setup marked complete!', 'mcp-ai-wpoos' ),
						'copied'           => __( 'Copied!', 'mcp-ai-wpoos' ),
						'copyFailed'       => __( 'Copy failed — please select and copy manually.', 'mcp-ai-wpoos' ),
					),
				)
			);
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
			$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
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
				<div class="wp-mcp-ai-wizard-logo" aria-label="<?php esc_attr_e( 'NV oOS — Open Operator System', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
					<span class="wp-mcp-ai-wizard-brand" aria-hidden="true">NV oOS</span>
				</div>

				<nav class="wp-mcp-ai-wizard-steps" aria-label="<?php esc_attr_e( 'Setup progress', 'mcp-ai-wpoos' ); ?>">
					<ol class="wp-mcp-ai-wizard-steps-list">
						<?php foreach ( $steps as $num => $label ) : ?>
							<li class="wp-mcp-ai-wizard-step <?php echo esc_attr( $num < $current_step ? 'is-complete' : ( $num === $current_step ? 'is-active' : 'is-pending' ) ); ?>"
								<?php echo esc_attr( $num === $current_step ? 'aria-current="step"' : '' ); ?>>
								<span class="wp-mcp-ai-wizard-step-indicator" aria-hidden="true">
									<?php if ( $num < $current_step ) : ?>
										<span class="dashicons dashicons-yes"></span>
									<?php else : ?>
										<?php echo esc_html( $num ); ?>
									<?php endif; ?>
								</span>
								<span class="wp-mcp-ai-wizard-step-label">
									<?php
									printf(
										/* translators: 1: step number, 2: total steps, 3: step label */
										'<span class="screen-reader-text">%s</span>%s',
										sprintf(
											/* translators: 1: step number, 2: total steps */
											esc_html__( 'Step %1$d of %2$d: ', 'mcp-ai-wpoos' ),
											intval( $num ),
											intval( self::TOTAL_STEPS )
										),
										esc_html( $label )
									);
									?>
								</span>
							</li>
							<?php if ( $num < self::TOTAL_STEPS ) : ?>
								<li class="wp-mcp-ai-wizard-step-connector <?php echo esc_attr( $num < $current_step ? 'is-complete' : '' ); ?>" aria-hidden="true"></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ol>
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
			$settings           = get_option( 'wp_mcp_ai_settings', array() );
			$openai_key         = ! empty( $settings['openai_api_key'] ) ? '••••••••••••••••' : '';
			$anthropic_key      = ! empty( $settings['anthropic_api_key'] ) ? '••••••••••••••••' : '';
			$gemini_key         = ! empty( $settings['gemini_api_key'] ) ? '••••••••••••••••' : '';
			$huggingface_key    = ! empty( $settings['huggingface_api_key'] ) ? '••••••••••••••••' : '';
			$ollama_url         = ! empty( $settings['ollama_endpoint_url'] ) ? esc_url( $settings['ollama_endpoint_url'] ) : 'http://localhost:11434';
			$lm_studio_url      = ! empty( $settings['lm_studio_endpoint_url'] ) ? esc_url( $settings['lm_studio_endpoint_url'] ) : 'http://localhost:1234';
			$nvidia_key         = ! empty( $settings['nvidia_api_key'] ) ? '••••••••••••••••' : '';
			$cloudflare_token   = ! empty( $settings['cloudflare_api_token'] ) ? '••••••••••••••••' : '';
			$cloudflare_acct_id = ! empty( $settings['cloudflare_account_id'] ) ? esc_attr( $settings['cloudflare_account_id'] ) : '';
			$test_nonce         = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
			$nonce              = wp_create_nonce( 'wp_mcp_ai_wizard_save_step' );

			// Providers definition for the tab loop.
			$providers     = array(
				'openai'      => array(
					'label'    => 'OpenAI',
					'icon_img' => WP_MCP_AI_URL . 'assets/images/openai-logo.svg',
				),
				'anthropic'   => array(
					'label'    => '🤖 Anthropic',
					'icon_img' => '',
				),
				'gemini'      => array(
					'label'    => 'Google Gemini',
					'icon_img' => WP_MCP_AI_URL . 'assets/images/gemini-logo.svg',
				),
				'huggingface' => array(
					'label'    => '🤗 Hugging Face',
					'icon_img' => '',
				),
				'nvidia'      => array(
					'label'    => '🟢 NVIDIA NIM',
					'icon_img' => '',
				),
				'ollama'      => array(
					'label'    => '🦙 Ollama (Local)',
					'icon_img' => '',
				),
				'lm_studio'   => array(
					'label'    => '🖥️ LM Studio',
					'icon_img' => '',
				),
				'cloudflare'  => array(
					'label'    => '☁️ Cloudflare',
					'icon_img' => '',
				),
			);
			$provider_keys = array_keys( $providers );
			$first_key     = reset( $provider_keys );
			?>
			<div class="wp-mcp-ai-wizard-step-content">
				<h2><?php esc_html_e( 'Connect Your AI Provider', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'NV oOS works with several AI providers. Enter your API key below and click "Test Connection" to confirm it works. You can always change this later in Settings → Providers.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-wizard-provider-tabs" role="tablist" aria-label="<?php esc_attr_e( 'AI Provider selection', 'mcp-ai-wpoos' ); ?>">
					<?php foreach ( $providers as $key => $provider ) : ?>
						<button type="button"
								role="tab"
								id="wp-mcp-ai-tab-<?php echo esc_attr( $key ); ?>"
								class="wp-mcp-ai-provider-tab <?php echo esc_attr( $key === $first_key ? 'is-active' : '' ); ?>"
								aria-selected="<?php echo esc_attr( $key === $first_key ? 'true' : 'false' ); ?>"
								aria-controls="wp-mcp-ai-panel-<?php echo esc_attr( $key ); ?>"
								tabindex="<?php echo esc_attr( $key === $first_key ? '0' : '-1' ); ?>"
								data-provider="<?php echo esc_attr( $key ); ?>">
							<?php if ( ! empty( $provider['icon_img'] ) ) : ?>
								<img src="<?php echo esc_url( $provider['icon_img'] ); ?>"
									onerror="this.style.display='none'"
									alt="" width="20" height="20" style="vertical-align:middle;margin-right:6px;">
							<?php endif; ?>
							<?php echo esc_html( $provider['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<!-- OpenAI panel -->
				<div class="wp-mcp-ai-provider-panel is-active"
					id="wp-mcp-ai-panel-openai"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-openai"
					data-panel="openai">
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
											autocomplete="new-password">
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
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="openai"></span>
				</div>

				<!-- Anthropic panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-anthropic"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-anthropic"
					hidden
					data-panel="anthropic">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_anthropic_key">
									<?php esc_html_e( 'Anthropic API Key', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
											id="wp_mcp_ai_anthropic_key"
											name="wp_mcp_ai_anthropic_key"
											class="regular-text"
											value="<?php echo esc_attr( $anthropic_key ); ?>"
											placeholder="sk-ant-…"
											autocomplete="new-password">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_anthropic_key">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to Anthropic Console */
										esc_html__( 'Get your API key from %s', 'mcp-ai-wpoos' ),
										'<a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="anthropic"
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="anthropic"></span>
				</div>

				<!-- Gemini panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-gemini"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-gemini"
					hidden
					data-panel="gemini">
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
											autocomplete="new-password">
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
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="gemini"></span>
				</div>

				<!-- Hugging Face panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-huggingface"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-huggingface"
					hidden
					data-panel="huggingface">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_huggingface_key">
									<?php esc_html_e( 'Hugging Face API Token', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
											id="wp_mcp_ai_huggingface_key"
											name="wp_mcp_ai_huggingface_key"
											class="regular-text"
											value="<?php echo esc_attr( $huggingface_key ); ?>"
											placeholder="hf_…"
											autocomplete="new-password">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_huggingface_key">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to Hugging Face settings/tokens page */
										esc_html__( 'Get your API token from %s', 'mcp-ai-wpoos' ),
										'<a href="https://huggingface.co/settings/tokens" target="_blank" rel="noopener noreferrer">huggingface.co/settings/tokens</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="huggingface"
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="huggingface"></span>
				</div>

				<!-- NVIDIA NIM panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-nvidia"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-nvidia"
					hidden
					data-panel="nvidia">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_nvidia_key">
									<?php esc_html_e( 'NVIDIA API Key', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
											id="wp_mcp_ai_nvidia_key"
											name="wp_mcp_ai_nvidia_key"
											class="regular-text"
											value="<?php echo esc_attr( $nvidia_key ); ?>"
											placeholder="nvapi-…"
											autocomplete="new-password">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_nvidia_key">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to NVIDIA Build Portal */
										esc_html__( 'Get your API key from %s', 'mcp-ai-wpoos' ),
										'<a href="https://build.nvidia.com/" target="_blank" rel="noopener noreferrer">build.nvidia.com</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="nvidia"
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="nvidia"></span>
				</div>

				<!-- Ollama panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-ollama"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-ollama"
					hidden
					data-panel="ollama">
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
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="ollama"></span>
				</div>

				<!-- LM Studio panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-lm_studio"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-lm_studio"
					hidden
					data-panel="lm_studio">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_lm_studio_url">
									<?php esc_html_e( 'LM Studio Endpoint URL', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="url"
										id="wp_mcp_ai_lm_studio_url"
										name="wp_mcp_ai_lm_studio_url"
										class="regular-text"
										value="<?php echo esc_attr( $lm_studio_url ); ?>">
								<p class="description">
									<?php esc_html_e( 'The URL of your running LM Studio server. Default: http://localhost:1234', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="lm_studio"
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="lm_studio"></span>
				</div>

				<!-- Cloudflare panel -->
				<div class="wp-mcp-ai-provider-panel"
					id="wp-mcp-ai-panel-cloudflare"
					role="tabpanel"
					aria-labelledby="wp-mcp-ai-tab-cloudflare"
					hidden
					data-panel="cloudflare">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_cloudflare_token">
									<?php esc_html_e( 'Cloudflare API Token', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<div style="display:flex;gap:8px;align-items:center;">
									<input type="password"
											id="wp_mcp_ai_cloudflare_token"
											name="wp_mcp_ai_cloudflare_token"
											class="regular-text"
											value="<?php echo esc_attr( $cloudflare_token ); ?>"
											placeholder="<?php esc_attr_e( 'Bearer token…', 'mcp-ai-wpoos' ); ?>"
											autocomplete="new-password">
									<button type="button" class="button wp-mcp-ai-show-key" data-target="wp_mcp_ai_cloudflare_token">
										<?php esc_html_e( 'Show', 'mcp-ai-wpoos' ); ?>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to Cloudflare API tokens page */
										esc_html__( 'Get your API token from %s', 'mcp-ai-wpoos' ),
										'<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">Cloudflare Dashboard</a>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wp_mcp_ai_cloudflare_account_id">
									<?php esc_html_e( 'Cloudflare Account ID', 'mcp-ai-wpoos' ); ?>
								</label>
							</th>
							<td>
								<input type="text"
										id="wp_mcp_ai_cloudflare_account_id"
										name="wp_mcp_ai_cloudflare_account_id"
										class="regular-text"
										value="<?php echo esc_attr( $cloudflare_acct_id ); ?>"
										placeholder="1234567890abcdef…"
										autocomplete="off">
								<p class="description">
									<?php esc_html_e( 'Find your Account ID in the Cloudflare Dashboard under Workers & Pages.', 'mcp-ai-wpoos' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<button type="button"
							class="button button-secondary wp-mcp-ai-wizard-test-btn"
							data-provider="cloudflare"
							data-nonce="<?php echo esc_attr( $test_nonce ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span class="wp-mcp-ai-test-result" aria-live="polite" data-for="cloudflare"></span>
				</div>

				<input type="hidden" id="wp_mcp_ai_wizard_nonce" value="<?php echo esc_attr( $nonce ); ?>">

				<p class="wp-mcp-ai-wizard-skip-note">
					<a href="<?php echo esc_url( $this->wizard_url( 3 ) ); ?>">
						<?php esc_html_e( 'Skip for now — I\'ll add my API key later', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
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
						<label class="wp-mcp-ai-preset-card <?php echo in_array( $key, $saved_selection, true ) ? 'is-selected' : ''; ?>"
							aria-checked="<?php echo in_array( $key, $saved_selection, true ) ? 'true' : 'false'; ?>">
							<input type="checkbox"
									name="wp_mcp_ai_presets[]"
									value="<?php echo esc_attr( $key ); ?>"
									class="wp-mcp-ai-preset-checkbox"
									<?php checked( in_array( $key, $saved_selection, true ) ); ?>>
							<span class="wp-mcp-ai-preset-icon"><?php echo esc_html( $preset['icon'] ); ?></span>
							<span class="wp-mcp-ai-preset-title"><?php echo esc_html( $preset['label'] ); ?></span>
							<span class="wp-mcp-ai-preset-desc"><?php echo esc_html( $preset['description'] ); ?></span>
							<span class="wp-mcp-ai-preset-tools-count">
								<?php
								printf(
									/* translators: %d: number of tools */
									esc_html__( '%d tools included', 'mcp-ai-wpoos' ),
									count( $preset['tools'] )
								);
								?>
							</span>
							<span class="wp-mcp-ai-preset-check dashicons dashicons-yes-alt" aria-hidden="true"></span>
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
				<span id="wp-mcp-ai-preset-save-result" aria-live="polite"></span>
			</div>
			<?php
		}

		/**
		 * Step 4: Finish / summary screen.
		 *
		 * Note: the wizard is NOT marked complete automatically on render.
		 * Completion is triggered by an explicit "Mark Setup Complete" button
		 * via the wp_mcp_ai_wizard_complete AJAX handler, so that navigating
		 * back from this step does not prematurely seal the wizard.
		 */
		private function render_step_finish() {
			$presets          = $this->get_presets();
			$selected_presets = get_option( 'wp_mcp_ai_onboarding_presets', array() );
			if ( ! is_array( $selected_presets ) ) {
				$selected_presets = array();
			}
			$settings    = get_option( 'wp_mcp_ai_settings', array() );
			$has_openai  = ! empty( $settings['openai_api_key'] );
			$has_gemini  = ! empty( $settings['gemini_api_key'] );
			$has_ollama  = ! empty( $settings['ollama_endpoint_url'] );
			$has_api_key = $has_openai || $has_gemini || $has_ollama
				|| ! empty( $settings['anthropic_api_key'] )
				|| ! empty( $settings['huggingface_api_key'] )
				|| ! empty( $settings['lm_studio_endpoint_url'] )
				|| ! empty( $settings['cloudflare_api_token'] );
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
					<div class="wp-mcp-ai-summary-item <?php echo esc_attr( $has_api_key ? 'is-done' : 'is-skipped' ); ?>">
						<span class="dashicons <?php echo esc_attr( $has_api_key ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span>
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
					<div class="wp-mcp-ai-shortcode-row">
						<code class="wp-mcp-ai-shortcode">[mcp_ai_chat]</code>
						<button type="button"
								class="button button-small wp-mcp-ai-copy-shortcode"
								data-shortcode="[mcp_ai_chat]"
								aria-label="<?php esc_attr_e( 'Copy shortcode to clipboard', 'mcp-ai-wpoos' ); ?>">
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-copy-feedback" aria-live="polite"></span>
						</button>
					</div>
					<p class="description">
						<?php esc_html_e( 'Or use the NV oOS Elementor widget for drag-and-drop placement.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<?php if ( ! $this->is_complete() ) : ?>
					<div class="wp-mcp-ai-wizard-complete-section">
						<button type="button"
								id="wp-mcp-ai-complete-wizard"
								class="button button-primary button-hero">
							<?php esc_html_e( 'Mark Setup Complete ✓', 'mcp-ai-wpoos' ); ?>
						</button>
						<span class="wp-mcp-ai-wizard-completion-status" aria-live="polite"></span>
						<p class="description">
							<?php esc_html_e( 'This hides the setup notice and wizard star from the menu.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
				<?php else : ?>
					<div class="wp-mcp-ai-wizard-complete-section">
						<span class="wp-mcp-ai-test-success">✓ <?php esc_html_e( 'Setup already complete!', 'mcp-ai-wpoos' ); ?></span>
					</div>
				<?php endif; ?>
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

			$step = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 0;

			if ( 2 === $step ) {
				$this->handle_save_provider_step();
			} elseif ( 3 === $step ) {
				$this->handle_save_presets_step();
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown step.', 'mcp-ai-wpoos' ) ) );
			}
		}

		/**
		 * Check whether an API key value is the masked placeholder shown in the UI.
		 *
		 * Returns true for empty strings and the bullet-masked placeholder so we
		 * never accidentally overwrite a real saved key with the display mask.
		 *
		 * @param string $value Value to test.
		 * @return bool
		 */
		private function is_masked_key( $value ) {
			return '' === $value || '••••••••••••••••' === $value;
		}

		/**
		 * Save the provider API key from step 2.
		 */
		private function handle_save_provider_step() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling ajax_save_step() method via check_ajax_referer().
			$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
			// API keys are typically alphanumeric with hyphens, dashes, and underscores.
			// Using sanitize_text_field + wp_unslash is the standard WordPress approach.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling ajax_save_step() method via check_ajax_referer().
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
			if ( ! in_array( $provider, $valid_providers, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'mcp-ai-wpoos' ) ) );
			}

			$settings = get_option( 'wp_mcp_ai_settings', array() );

			// Unslash the extra array once for use across all providers.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in ajax_save_step(); array values sanitized per-field below.
			$extra_raw = isset( $_POST['extra'] ) && is_array( $_POST['extra'] ) ? wp_unslash( $_POST['extra'] ) : array();

			if ( 'openai' === $provider ) {
				// Do not overwrite a real key if the user saw the masked placeholder.
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['openai_api_key'] = $api_key;
				}
			} elseif ( 'anthropic' === $provider ) {
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['anthropic_api_key'] = $api_key;
				}
			} elseif ( 'gemini' === $provider ) {
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['gemini_api_key'] = $api_key;
				}
			} elseif ( 'huggingface' === $provider ) {
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['huggingface_api_key'] = $api_key;
					// Enable Hugging Face (disabled by default) when a key is provided.
					$settings['enable_huggingface'] = true;
				}
			} elseif ( 'nvidia' === $provider ) {
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['nvidia_api_key'] = $api_key;
					// Enable NVIDIA NIM (disabled by default) when a key is provided.
					$settings['enable_nvidia'] = true;
				}
			} elseif ( 'ollama' === $provider ) {
				// For Ollama, save the endpoint URL from the extra data.
				$ollama_url = ! empty( $extra_raw['ollama_url'] ) ? esc_url_raw( (string) $extra_raw['ollama_url'] ) : '';
				if ( $ollama_url ) {
					$settings['ollama_endpoint_url'] = $ollama_url;
				}
			} elseif ( 'lm_studio' === $provider ) {
				// For LM Studio, save the endpoint URL from the extra data.
				$lm_studio_url = ! empty( $extra_raw['lm_studio_url'] ) ? esc_url_raw( (string) $extra_raw['lm_studio_url'] ) : '';
				if ( $lm_studio_url ) {
					$settings['lm_studio_endpoint_url'] = $lm_studio_url;
				}
			} elseif ( 'cloudflare' === $provider ) {
				if ( ! $this->is_masked_key( $api_key ) ) {
					$settings['cloudflare_api_token'] = $api_key;
				}
				$cloudflare_account_id = ! empty( $extra_raw['cloudflare_account_id'] ) ? sanitize_text_field( (string) $extra_raw['cloudflare_account_id'] ) : '';
				if ( $cloudflare_account_id ) {
					$settings['cloudflare_account_id'] = $cloudflare_account_id;
					// Enable Cloudflare (disabled by default) when credentials are configured.
					$settings['enable_cloudflare'] = true;
				}
			}

			update_option( 'wp_mcp_ai_settings', $settings );
			wp_send_json_success( array( 'message' => __( 'Provider settings saved.', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * Save the preset selection from step 3 and seed assistants.
		 */
		private function handle_save_presets_step() {
			// Unslash the raw POST data first, then validate the structure, then sanitize each key.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in ajax_save_step(); array sanitized below with array_map+sanitize_key.
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

			// Seed assistants for each selected preset.
			$created = $this->seed_preset_assistants( array_values( $presets ) );

			wp_send_json_success(
				array(
					'message' => __( 'Presets saved.', 'mcp-ai-wpoos' ),
					'created' => $created,
				)
			);
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
		 * Each preset defines a complete assistant configuration including tools,
		 * a system prompt, temperature, and description. When the user selects
		 * presets in Step 3, the plugin creates a fully-functional assistant CPT
		 * post for each one so the site is working out of the box.
		 *
		 * @return array Preset definitions keyed by preset slug.
		 */
		public function get_presets() {
			$defaults = array(
				'content_creator'  => array(
					'label'         => __( 'Content Creator / Blogger', 'mcp-ai-wpoos' ),
					'icon'          => '✍️',
					'description'   => __( 'Write, edit, and publish blog posts with AI-powered SEO, image generation, and internal linking.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'create_post',
						'save_post',
						'generate_post_excerpt',
						'search_content',
						'auto_categorize_content',
						'suggest_internal_links',
						'generate_openai_image',
						'seo_meta_optimizer',
						'content_freshness_checker',
						'web_search',
						'deep_research',
						'client_summarize_text',
					),
					'system_prompt' => "You are a professional content writer and blogging expert for a WordPress site.\n\nYour capabilities:\n- Research topics in depth using web search before writing\n- Create well-structured, engaging blog posts with proper headings\n- Generate relevant featured images for every post\n- Optimize content for SEO with meta descriptions and keywords\n- Suggest internal links to existing content\n- Auto-categorize posts into the right categories\n- Write compelling excerpts and summaries\n\nWhen creating content:\n1. Research the topic first using web_search or deep_research\n2. Outline the structure before writing\n3. Write in a clear, engaging, conversational tone\n4. Include a generated featured image\n5. Add SEO meta data\n6. Suggest internal links to related content\n\nAlways prioritize accuracy, originality, and reader engagement.",
					'temperature'   => 0.7,
					'assistant'     => __( 'Content Writer', 'mcp-ai-wpoos' ),
				),
				'customer_support' => array(
					'label'         => __( 'Customer Support Bot', 'mcp-ai-wpoos' ),
					'icon'          => '🎧',
					'description'   => __( 'Answer FAQs, search your knowledge base, send emails, and assist visitors with a friendly support persona.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'search_content',
						'semantic_content_search',
						'get_recent_posts',
						'send_group_email',
						'moderate_content',
						'client_summarize_text',
						'get_site_summary',
						'web_search',
					),
					'system_prompt' => "You are a friendly, professional customer support assistant for this WordPress site.\n\nYour responsibilities:\n- Answer user questions by searching the site's existing content and knowledge base\n- Provide accurate, helpful responses based on published pages and posts\n- Summarize long articles into concise answers\n- Escalate complex issues by sending an email to the site administrator\n- Moderate user-submitted content for appropriateness\n- Stay on-topic and never fabricate information not found in the knowledge base\n\nCommunication style:\n- Warm, patient, and professional\n- Use short paragraphs and bullet points for clarity\n- Always offer to help further at the end of your response\n- If you cannot find an answer, honestly say so and suggest contacting a human",
					'temperature'   => 0.3,
					'assistant'     => __( 'Support Assistant', 'mcp-ai-wpoos' ),
				),
				'ecommerce'        => array(
					'label'         => __( 'E-commerce Assistant', 'mcp-ai-wpoos' ),
					'icon'          => '🛒',
					'description'   => __( 'Write product descriptions, manage WooCommerce products, analyze competitors, and assist shoppers.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'create_post',
						'save_post',
						'get_woo_products',
						'get_woo_recent_orders',
						'create_woo_product',
						'scrape_product',
						'generate_openai_image',
						'search_content',
						'web_search',
						'seo_meta_optimizer',
						'client_summarize_text',
					),
					'system_prompt' => "You are an e-commerce specialist assistant for a WordPress/WooCommerce store.\n\nYour capabilities:\n- Create compelling, SEO-optimized product descriptions\n- Manage WooCommerce product listings (create, update, review)\n- Check order status and recent order activity\n- Research competitor products and pricing via web search\n- Generate product images when needed\n- Optimize product pages for search engines\n- Assist shoppers with product recommendations\n\nWhen writing product descriptions:\n1. Highlight key features and benefits\n2. Use persuasive, conversion-focused language\n3. Include relevant keywords for SEO\n4. Add structured information (specs, materials, dimensions)\n5. Write in a tone that matches the brand voice\n\nAlways be accurate about product information and pricing.",
					'temperature'   => 0.5,
					'assistant'     => __( 'E-commerce Assistant', 'mcp-ai-wpoos' ),
				),
				'seo_research'     => array(
					'label'         => __( 'SEO & Research', 'mcp-ai-wpoos' ),
					'icon'          => '🔍',
					'description'   => __( 'Deep research, SEO audits, content optimization, keyword analysis, and competitive intelligence.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'web_search',
						'deep_research',
						'search_content',
						'semantic_content_search',
						'get_rankmath_seo',
						'seo_meta_optimizer',
						'suggest_internal_links',
						'content_freshness_checker',
						'auto_categorize_content',
						'client_summarize_text',
						'client_extract_entities',
						'create_chart',
					),
					'system_prompt' => "You are an SEO analyst and research specialist for a WordPress site.\n\nYour expertise:\n- Keyword research and competitive analysis via web search\n- On-page SEO audits using Rank Math integration\n- Content gap analysis and optimization recommendations\n- Internal linking strategy to boost site authority\n- Content freshness checks to identify outdated material\n- Data visualization with charts for reporting\n\nWhen conducting SEO analysis:\n1. Audit existing content for SEO issues\n2. Research competitors and identify keyword opportunities\n3. Recommend specific optimizations for each page\n4. Suggest internal links between related content\n5. Identify stale content that needs refreshing\n6. Create visual reports with charts when presenting data\n\nBase all recommendations on current SEO best practices. Provide actionable, specific suggestions rather than vague advice.",
					'temperature'   => 0.3,
					'assistant'     => __( 'SEO & Research Analyst', 'mcp-ai-wpoos' ),
				),
				'developer'        => array(
					'label'         => __( 'Developer Copilot', 'mcp-ai-wpoos' ),
					'icon'          => '💻',
					'description'   => __( 'Site health monitoring, security checks, cron management, system logs, and environment diagnostics.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'get_site_summary',
						'get_site_health',
						'get_environment_status',
						'get_system_logs',
						'get_update_status',
						'check_site_security',
						'create_cron_job',
						'list_cron_jobs',
						'check_workflow_health',
						'purge_cache',
						'login_security_monitor',
						'user_activity_auditor',
					),
					'system_prompt' => "You are a WordPress developer and system administrator assistant.\n\nYour capabilities:\n- Monitor site health, performance, and environment status\n- Review system and error logs for issues\n- Check for pending updates and security vulnerabilities\n- Manage cron jobs (create, list, monitor scheduled tasks)\n- Audit user activity and login security\n- Purge caches when needed\n- Validate workflow configurations\n\nWhen troubleshooting:\n1. Start by checking site health and environment status\n2. Review recent system logs for errors\n3. Verify security status and pending updates\n4. Provide specific, actionable fix recommendations\n5. Explain technical concepts in clear language\n\nAlways prioritize security and stability. Warn about risky operations before executing them.",
					'temperature'   => 0.2,
					'assistant'     => __( 'Developer Copilot', 'mcp-ai-wpoos' ),
				),
				'media_creative'   => array(
					'label'         => __( 'Media & Creative Studio', 'mcp-ai-wpoos' ),
					'icon'          => '🎨',
					'description'   => __( 'Generate images, analyze visuals, create charts, produce audio/speech, and manage your media library.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'generate_openai_image',
						'generate_gemini_image',
						'analyze_image',
						'extract_image_text',
						'resize_image',
						'generate_image_alt_text',
						'search_attachments',
						'generate_openai_speech',
						'create_chart',
						'generate_mermaid',
						'media_library_optimizer',
					),
					'system_prompt' => "You are a creative media specialist and visual content producer.\n\nYour capabilities:\n- Generate images using AI (OpenAI DALL-E and Google Gemini)\n- Analyze and describe existing images\n- Extract text from images (OCR)\n- Generate accessible alt text for images\n- Resize and optimize media files\n- Create data visualizations (charts and diagrams)\n- Generate speech audio from text\n- Search and manage the media library\n\nWhen creating visual content:\n1. Ask about the desired style, mood, and dimensions\n2. Generate high-quality images with detailed prompts\n3. Always add descriptive alt text for accessibility\n4. Optimize file sizes for web performance\n5. Suggest complementary visuals for articles\n\nPrioritize quality, accessibility, and brand consistency in all creative work.",
					'temperature'   => 0.8,
					'assistant'     => __( 'Creative Studio', 'mcp-ai-wpoos' ),
				),
				'site_admin'       => array(
					'label'         => __( 'Site Administrator', 'mcp-ai-wpoos' ),
					'icon'          => '🛡️',
					'description'   => __( 'Full site management: security monitoring, email, caching, user audits, and scheduled task automation.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'get_site_summary',
						'get_site_health',
						'get_environment_status',
						'check_site_security',
						'get_update_status',
						'get_system_logs',
						'purge_cache',
						'create_cron_job',
						'list_cron_jobs',
						'send_group_email',
						'login_security_monitor',
						'user_activity_auditor',
						'get_user_info',
					),
					'system_prompt' => "You are a WordPress site administrator assistant with full operational access.\n\nYour responsibilities:\n- Monitor site health, security, and performance\n- Review and act on system logs and error reports\n- Manage scheduled tasks and cron jobs\n- Send administrative emails and notifications\n- Audit user activity and login attempts\n- Clear caches and optimize site performance\n- Track pending updates and security patches\n- Provide user account information when needed\n\nOperating principles:\n1. Prioritize site security and stability above all else\n2. Always explain what an action will do before executing it\n3. Recommend preventive measures, not just reactive fixes\n4. Keep the site owner informed with clear status summaries\n5. Flag urgent security issues immediately\n\nYou handle day-to-day site operations so the owner can focus on their business.",
					'temperature'   => 0.2,
					'assistant'     => __( 'Site Administrator', 'mcp-ai-wpoos' ),
				),
				'general'          => array(
					'label'         => __( 'General Purpose', 'mcp-ai-wpoos' ),
					'icon'          => '🤖',
					'description'   => __( 'A well-rounded assistant with content, research, media, and site management tools for everyday tasks.', 'mcp-ai-wpoos' ),
					'tools'         => array(
						'create_post',
						'save_post',
						'search_content',
						'web_search',
						'generate_openai_image',
						'generate_post_excerpt',
						'suggest_internal_links',
						'client_summarize_text',
						'get_site_summary',
						'moderate_content',
						'auto_categorize_content',
						'send_group_email',
					),
					'system_prompt' => "You are a versatile AI assistant for a WordPress site, capable of handling a wide range of tasks.\n\nYour capabilities include:\n- Creating and editing blog posts and pages\n- Researching topics via web search\n- Generating images for content\n- Summarizing long documents\n- Searching existing site content\n- Providing a site overview and status\n- Sending emails on behalf of the administrator\n- Moderating content for quality\n\nAdapt your communication style to each task:\n- For writing: be creative, engaging, and SEO-aware\n- For research: be thorough, factual, and well-sourced\n- For administration: be concise, clear, and action-oriented\n\nAlways ask for clarification if a request is ambiguous. Prioritize helpful, accurate, and safe responses.",
					'temperature'   => 0.5,
					'assistant'     => __( 'General Assistant', 'mcp-ai-wpoos' ),
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
		// Preset assistant seeding
		// -------------------------------------------------------------------------

		/**
		 * Create a fully configured assistant CPT post for each selected preset.
		 *
		 * Assistants are only created if they do not already exist (checked by
		 * post slug). This makes the method safe to call multiple times.
		 *
		 * @param array $preset_keys Array of preset slugs to seed.
		 * @return array Associative array of preset_key => assistant post ID (or WP_Error message).
		 */
		private function seed_preset_assistants( $preset_keys ) {
			$presets = $this->get_presets();
			$created = array();

			// Determine the provider and model from saved settings.
			$settings         = get_option( 'wp_mcp_ai_settings', array() );
			$default_provider = ! empty( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';
			$default_model    = $this->resolve_default_model( $settings, $default_provider );

			foreach ( $preset_keys as $key ) {
				if ( ! isset( $presets[ $key ] ) ) {
					continue;
				}

				$preset = $presets[ $key ];
				$slug   = 'onboarding-' . sanitize_title( $key );

				// Skip if an assistant with this slug already exists.
				$existing = get_page_by_path( $slug, OBJECT, 'mcp_ai_assistant' );
				if ( $existing ) {
					$created[ $key ] = $existing->ID;
					continue;
				}

				$post_id = wp_insert_post(
					array(
						'post_type'    => 'mcp_ai_assistant',
						'post_title'   => $preset['assistant'],
						'post_content' => $preset['description'],
						'post_name'    => $slug,
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					$created[ $key ] = $post_id->get_error_message();
					continue;
				}

				// Core configuration.
				update_post_meta( $post_id, '_wp_mcp_ai_provider', $default_provider );
				update_post_meta( $post_id, '_wp_mcp_ai_model', $default_model );
				update_post_meta( $post_id, '_wp_mcp_ai_temperature', isset( $preset['temperature'] ) ? floatval( $preset['temperature'] ) : 0.7 );
				update_post_meta( $post_id, '_wp_mcp_ai_system_prompt', $preset['system_prompt'] );
				update_post_meta( $post_id, '_wp_mcp_ai_tools', $preset['tools'] );
				update_post_meta( $post_id, 'mcp_ai_required_capability', 'edit_posts' );

				// If this is the first assistant created and no default is set, make it the default.
				$current_default = ! empty( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
				if ( 0 === $current_default ) {
					$settings['default_assistant'] = $post_id;
					update_option( 'wp_mcp_ai_settings', $settings );
				}

				$created[ $key ] = $post_id;
			}

			/**
			 * Fires after onboarding presets have been seeded as assistants.
			 *
			 * @since 1.1.5
			 * @param array $created    Associative array of preset_key => post_id or error.
			 * @param array $preset_keys The preset slugs that were selected.
			 */
			do_action( 'wp_mcp_ai_onboarding_presets_seeded', $created, $preset_keys );

			return $created;
		}

		/**
		 * Determine the default model for a given provider from saved settings.
		 *
		 * Falls back to sensible defaults for each provider.
		 *
		 * @param array  $settings        Saved plugin settings.
		 * @param string $default_provider The provider slug.
		 * @return string The model identifier.
		 */
		private function resolve_default_model( $settings, $default_provider ) {
			// Check if a specific model is configured.
			if ( ! empty( $settings['default_model'] ) ) {
				return $settings['default_model'];
			}

			// Provider-specific fallbacks (April 2026).
			$fallbacks = array(
				'openai'      => 'gpt-4.1',
				'anthropic'   => 'claude-sonnet-4-6',
				'gemini'      => 'gemini-3.5-flash',
				'ollama'      => 'llama4',
				'lm_studio'   => 'local',
				'cloudflare'  => '@cf/meta/llama-4-scout-17b-16e-instruct',
				'huggingface' => 'meta-llama/Llama-4-8B-Instruct',
			);

			return isset( $fallbacks[ $default_provider ] ) ? $fallbacks[ $default_provider ] : 'gpt-4.1';
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
				flex-wrap: wrap;
				gap: 0;
				margin-bottom: 20px;
				border-bottom: 2px solid #e0e0e0;
				padding-bottom: 0;
			}
			.wp-mcp-ai-provider-tab {
				background: none;
				border: none;
				padding: 8px 12px;
				cursor: pointer;
				font-size: 0.88em;
				color: #50575e;
				border-bottom: 3px solid transparent;
				margin-bottom: -2px;
				border-radius: 0;
				white-space: nowrap;
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

			/* Preset cards (step 3) — grid defined at bottom of stylesheet */
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
			.wp-mcp-ai-shortcode-row {
				display: flex;
				align-items: center;
				gap: 12px;
				margin: 8px 0;
			}

			/* Copy button */
			.wp-mcp-ai-copy-shortcode {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				position: relative;
			}
			.wp-mcp-ai-copy-shortcode .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.wp-mcp-ai-copy-feedback {
				position: absolute;
				top: -24px;
				left: 50%;
				transform: translateX(-50%);
				background: #46b450;
				color: #fff;
				font-size: 0.75em;
				padding: 2px 8px;
				border-radius: 3px;
				white-space: nowrap;
				opacity: 0;
				transition: opacity 0.2s;
				pointer-events: none;
			}
			.wp-mcp-ai-copy-feedback.is-visible {
				opacity: 1;
			}
			.wp-mcp-ai-copy-feedback.is-error {
				background: #dc3232;
			}

			/* Completion section (step 4) */
			.wp-mcp-ai-wizard-complete-section {
				margin-top: 28px;
				padding-top: 20px;
				border-top: 1px solid #e0e0e0;
				text-align: center;
			}
			.wp-mcp-ai-wizard-complete-section .description {
				margin-top: 8px;
			}
			.wp-mcp-ai-wizard-completion-status {
				display: block;
				margin-top: 8px;
			}

			/* Test result colours */
			.wp-mcp-ai-test-success { color: #46b450; }
			.wp-mcp-ai-test-error { color: #dc3232; }
			.wp-mcp-ai-testing { color: #888; }

			/* Preset tool count badge */
			.wp-mcp-ai-preset-tools-count {
				font-size: 0.72em;
				color: #2271b1;
				background: #e8f0fe;
				border-radius: 3px;
				padding: 2px 6px;
				margin-top: 4px;
				display: inline-block;
			}

			/* Focus styles for accessibility */
			.wp-mcp-ai-provider-tab:focus-visible,
			.wp-mcp-ai-preset-card:focus-within,
			.wp-mcp-ai-wizard-path-card:focus-visible,
			.wp-mcp-ai-next-step-card:focus-visible {
				outline: 2px solid #2271b1;
				outline-offset: 2px;
			}

			/* Step indicators — semantic list */
			.wp-mcp-ai-wizard-steps-list {
				display: flex;
				align-items: center;
				gap: 0;
				list-style: none;
				margin: 0;
				padding: 0;
			}

			/* Presets grid — 4 columns for 8 presets */
			.wp-mcp-ai-wizard-presets {
				display: grid;
				grid-template-columns: repeat(4, 1fr);
				gap: 16px;
				margin-bottom: 24px;
			}

			/* Responsive */
			@media (max-width: 900px) {
				.wp-mcp-ai-wizard-presets {
					grid-template-columns: repeat(2, 1fr);
				}
			}
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
