<?php
/**
 * NV oOS Cloud — Admin settings page.
 *
 * Adds an "NV oOS Cloud" submenu under the main NV oOS menu where customers
 * can connect/disconnect, view their balance, top-up via Stripe Checkout,
 * toggle auto-top-up and inspect the per-request ledger (wholesale + 7%
 * service fee + Stripe pass-through).
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_NV_Cloud_Settings_Page' ) ) {

	/**
	 * Renders the NV oOS Cloud admin settings screen.
	 */
	class WP_MCP_AI_NV_Cloud_Settings_Page {

		/**
		 * Page slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'wp-mcp-ai-nv-cloud';

		/**
		 * Service helper.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Service
		 */
		protected $service;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_NV_Cloud_Service|null $service Optional override.
		 */
		public function __construct( $service = null ) {
			$this->service = $service instanceof WP_MCP_AI_NV_Cloud_Service
				? $service
				: WP_MCP_AI_NV_Cloud_Service::get_instance();
		}

		/**
		 * Wire admin hooks.
		 */
		public function register() {
			add_action( 'admin_menu', array( $this, 'add_menu' ), 50 );
			add_action( 'admin_notices', array( $this, 'maybe_render_low_balance_notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Enqueue the admin JS on the NV oOS Cloud screen only.
		 *
		 * @param string $hook_suffix Current admin page hook.
		 */
		public function enqueue_assets( $hook_suffix ) {
			if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
				return;
			}
			$rel = 'addons/pro/assets/js/nv-cloud-admin.js';
			$url = defined( 'WP_MCP_AI_URL' ) ? WP_MCP_AI_URL . $rel : plugins_url( 'mcp-ai-wpoos/' . $rel );
			wp_enqueue_script(
				'wp-mcp-ai-nv-cloud-admin',
				$url,
				array( 'wp-api-fetch' ),
				defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.7.0',
				true
			);
		}

		/**
		 * Register the submenu page.
		 */
		public function add_menu() {
			$parent = $this->resolve_parent_slug();

			add_submenu_page(
				$parent,
				__( 'NV oOS Cloud', 'mcp-ai-wpoos' ),
				__( 'NV oOS Cloud', 'mcp-ai-wpoos' ),
				'manage_options',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Resolve the parent menu slug — falls back to Settings if NV oOS's
		 * top-level menu is missing for any reason.
		 *
		 * @return string
		 */
		protected function resolve_parent_slug() {
			global $admin_page_hooks;
			if ( is_array( $admin_page_hooks ) ) {
				foreach ( array( 'wp-mcp-ai', 'mcp-ai-wpoos', 'mcp-ai' ) as $candidate ) {
					if ( isset( $admin_page_hooks[ $candidate ] ) ) {
						return $candidate;
					}
				}
			}
			return 'options-general.php';
		}

		/**
		 * Render the page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$cached    = $this->service->get_cached_balance();
			$connected = $this->service->is_connected();
			$prefs     = $this->service->get_prefs();
			$markup    = WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE * 100.0;
			$min_topup = WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD;

			?>
			<div class="wrap wp-mcp-ai-nv-cloud-page">
				<h1><?php esc_html_e( 'NV oOS Cloud', 'mcp-ai-wpoos' ); ?></h1>

				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: markup percentage. */
							__( 'Hosted "Managed Tokens" service. Routes inference through NV-managed Cloudflare AI Gateway → OpenRouter. You pay upstream wholesale + a transparent %1$s%% service fee, plus Stripe processor pass-through on top-ups. No need to bring your own OpenAI / Anthropic / OpenRouter key.', 'mcp-ai-wpoos' ),
							number_format_i18n( $markup, 0 )
						)
					);
					?>
				</p>

				<?php $this->render_status_panel( $connected, $cached ); ?>

				<?php if ( $connected ) : ?>
					<?php $this->render_topup_panel( $cached, $min_topup ); ?>
					<?php $this->render_prefs_panel( $prefs ); ?>
					<?php $this->render_ledger_panel(); ?>
				<?php else : ?>
					<?php $this->render_connect_panel(); ?>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Status / balance panel.
		 *
		 * @param bool  $connected Connection state.
		 * @param array $cached    Cached balance record.
		 */
		protected function render_status_panel( $connected, array $cached ) {
			$balance = isset( $cached['balance'] ) ? (float) $cached['balance'] : 0.0;
			$low     = $balance < WP_MCP_AI_NV_Cloud_Service::LOW_BALANCE_THRESHOLD_USD;
			?>
			<div class="card" style="max-width:640px;padding:1em 1.5em;margin-top:1.5em;">
				<h2><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Connection:', 'mcp-ai-wpoos' ); ?></strong>
					<?php if ( $connected ) : ?>
						<span style="color:#1a7e1a;">●</span>
						<?php esc_html_e( 'Connected', 'mcp-ai-wpoos' ); ?>
					<?php else : ?>
						<span style="color:#ccc;">○</span>
						<?php esc_html_e( 'Not connected', 'mcp-ai-wpoos' ); ?>
					<?php endif; ?>
				</p>
				<?php if ( $connected ) : ?>
					<p>
						<strong><?php esc_html_e( 'Balance:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html( '$' . number_format_i18n( $balance, 2 ) ); ?> USD
						<?php if ( $low ) : ?>
							<span style="color:#b22222;font-weight:bold;">
								<?php esc_html_e( 'Low balance — top up to avoid interruptions.', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php endif; ?>
					</p>
					<p>
						<button type="button" class="button" id="wp-mcp-ai-nv-cloud-refresh">
							<?php esc_html_e( 'Refresh balance', 'mcp-ai-wpoos' ); ?>
						</button>
						<button type="button" class="button" id="wp-mcp-ai-nv-cloud-disconnect">
							<?php esc_html_e( 'Disconnect', 'mcp-ai-wpoos' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Connect panel — shown when the site has no token yet.
		 */
		protected function render_connect_panel() {
			$base_url       = $this->service->get_base_url();
			$connect_target = $this->build_connect_url();
			?>
			<div class="card" style="max-width:640px;padding:1em 1.5em;margin-top:1.5em;">
				<h2><?php esc_html_e( 'Connect this site', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: SaaS base URL. */
							__( 'Click "Connect" below to open the NV oOS Cloud onboarding at %1$s. After Stripe Checkout you will be redirected back here with a Connect Token bound to this site.', 'mcp-ai-wpoos' ),
							preg_replace( '#/v1$#', '', $base_url )
						)
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $connect_target ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Connect NV oOS Cloud', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>

				<h3><?php esc_html_e( 'Or paste a Connect Token manually', 'mcp-ai-wpoos' ); ?></h3>
				<p>
					<label for="wp-mcp-ai-nv-cloud-token-input">
						<?php esc_html_e( 'Connect Token:', 'mcp-ai-wpoos' ); ?>
					</label>
					<input type="text" id="wp-mcp-ai-nv-cloud-token-input" class="regular-text" autocomplete="off" />
					<button type="button" class="button button-primary" id="wp-mcp-ai-nv-cloud-save-token">
						<?php esc_html_e( 'Save token', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
			<?php
		}

		/**
		 * Top-up panel.
		 *
		 * @param array $cached    Balance record.
		 * @param float $min_topup Min top-up amount.
		 */
		protected function render_topup_panel( array $cached, $min_topup ) {
			$processor_fee = $this->service->compute_stripe_passthrough( $min_topup );
			?>
			<div class="card" style="max-width:640px;padding:1em 1.5em;margin-top:1.5em;">
				<h2><?php esc_html_e( 'Top up', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: minimum top-up dollar amount, 2: estimated processor fee. */
							__( 'Minimum top-up is $%1$s USD. A typical Stripe pass-through fee on this amount is approximately $%2$s — shown as a transparent line item on your Stripe receipt.', 'mcp-ai-wpoos' ),
							number_format_i18n( $min_topup, 2 ),
							number_format_i18n( $processor_fee, 2 )
						)
					);
					?>
				</p>
				<p>
					<label for="wp-mcp-ai-nv-cloud-topup-amount">
						<?php esc_html_e( 'Amount (USD):', 'mcp-ai-wpoos' ); ?>
					</label>
					<input
						type="number"
						id="wp-mcp-ai-nv-cloud-topup-amount"
						min="<?php echo esc_attr( (string) $min_topup ); ?>"
						step="1"
						value="<?php echo esc_attr( (string) $min_topup ); ?>"
					/>
					<button type="button" class="button button-primary" id="wp-mcp-ai-nv-cloud-topup">
						<?php esc_html_e( 'Open Stripe Checkout', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
			<?php
		}

		/**
		 * Prefs panel — auto-topup, "use as default" toggle.
		 *
		 * @param array $prefs Prefs.
		 */
		protected function render_prefs_panel( array $prefs ) {
			?>
			<div class="card" style="max-width:640px;padding:1em 1.5em;margin-top:1.5em;">
				<h2><?php esc_html_e( 'Preferences', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" id="wp-mcp-ai-nv-cloud-default" <?php checked( ! empty( $prefs['use_as_default'] ) ); ?> />
						<?php esc_html_e( 'Use NV oOS Cloud as the default provider for assistants without a BYOK key', 'mcp-ai-wpoos' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" id="wp-mcp-ai-nv-cloud-auto-topup" <?php checked( ! empty( $prefs['auto_topup_enabled'] ) ); ?> />
						<?php esc_html_e( 'Enable auto-top-up when balance falls below the threshold', 'mcp-ai-wpoos' ); ?>
					</label>
				</p>
				<p>
					<label for="wp-mcp-ai-nv-cloud-auto-topup-amount">
						<?php esc_html_e( 'Auto-top-up amount (USD):', 'mcp-ai-wpoos' ); ?>
					</label>
					<input
						type="number"
						id="wp-mcp-ai-nv-cloud-auto-topup-amount"
						min="<?php echo esc_attr( (string) WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD ); ?>"
						step="1"
						value="<?php echo esc_attr( (string) ( ! empty( $prefs['auto_topup_amount_usd'] ) ? $prefs['auto_topup_amount_usd'] : WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD ) ); ?>"
					/>
					<button type="button" class="button" id="wp-mcp-ai-nv-cloud-save-prefs">
						<?php esc_html_e( 'Save preferences', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
			<?php
		}

		/**
		 * Ledger panel — recent activity (wholesale + service fee + total).
		 */
		protected function render_ledger_panel() {
			$entries = $this->service->get_ledger( 25 );
			?>
			<div class="card" style="max-width:960px;padding:1em 1.5em;margin-top:1.5em;">
				<h2><?php esc_html_e( 'Recent usage', 'mcp-ai-wpoos' ); ?></h2>
				<?php if ( empty( $entries ) ) : ?>
					<p><em><?php esc_html_e( 'No usage recorded yet. Send a chat request through any assistant configured to use NV oOS Cloud and entries will appear here.', 'mcp-ai-wpoos' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Wholesale', 'mcp-ai-wpoos' ); ?></th>
								<th>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: markup percentage. */
											__( 'Service fee (%1$s%%)', 'mcp-ai-wpoos' ),
											number_format_i18n( WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE * 100.0, 0 )
										)
									);
									?>
								</th>
								<th><?php esc_html_e( 'Total', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<tr>
									<td>
										<?php
										echo esc_html(
											function_exists( 'wp_date' )
												? wp_date( 'Y-m-d H:i:s', (int) $entry['timestamp'] )
												: gmdate( 'Y-m-d H:i:s', (int) $entry['timestamp'] )
										);
										?>
									</td>
									<td><?php echo esc_html( (string) $entry['model'] ); ?></td>
									<td><?php echo esc_html( '$' . number_format_i18n( (float) $entry['wholesale_usd'], 6 ) ); ?></td>
									<td><?php echo esc_html( '$' . number_format_i18n( (float) $entry['service_fee_usd'], 6 ) ); ?></td>
									<td><strong><?php echo esc_html( '$' . number_format_i18n( (float) $entry['total_usd'], 6 ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Build the SaaS connect URL.
		 *
		 * @return string
		 */
		protected function build_connect_url() {
			$base = $this->service->get_base_url();
			// Strip /v1 suffix to get the marketing root.
			$root = preg_replace( '#/v1$#', '', $base );
			return esc_url_raw(
				add_query_arg(
					array(
						'site_url'   => function_exists( 'home_url' ) ? home_url( '/' ) : '',
						'return_url' => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . self::MENU_SLUG ) : '',
					),
					$root . '/connect'
				)
			);
		}

		/**
		 * Render a low-balance admin notice on every screen so the customer
		 * never gets surprised by a stalled assistant.
		 */
		public function maybe_render_low_balance_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! $this->service->is_connected() ) {
				return;
			}
			$cached = $this->service->get_cached_balance();
			if ( (float) $cached['balance'] >= WP_MCP_AI_NV_Cloud_Service::LOW_BALANCE_THRESHOLD_USD ) {
				return;
			}
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'NV oOS Cloud — low balance.', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: balance in USD. */
							__( 'Your current balance is $%1$s. Top up to avoid interruptions.', 'mcp-ai-wpoos' ),
							number_format_i18n( (float) $cached['balance'], 2 )
						)
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
						<?php esc_html_e( 'Manage NV oOS Cloud', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}
}
