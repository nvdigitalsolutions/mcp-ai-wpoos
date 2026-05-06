<?php
/**
 * Admin menu and page renderer for the NV oOS SaaS Controller.
 *
 * Registers a top-level WP-Admin menu — **NV oOS SaaS** — with two tabs in
 * Phase 2: **Overview** and **Packages**. Subsequent phases will add
 * **Wizard**, **Plan / Apply**, **Drift**, **Audit Log**, and **Smoke Tests**
 * tabs by mounting the React bundle under `assets/build/index.js` into the
 * existing `<div id="nvoos-saas-controller-root"></div>` mount point.
 *
 * The Packages tab is the in-product credits surface mandated by the Phase 1
 * "Distribution Hygiene" deliverable; it mirrors the metadata exposed by the
 * Pro Packages page (`get_package_definitions()` returning homepage, license,
 * and copyright per package).
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu and page renderer.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Admin_Page {

	/**
	 * Page slug used by `add_menu_page`.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-saas-controller';

	/**
	 * Required capability for every admin surface in this addon.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Register the admin hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_drift_banner' ) );
	}

	/**
	 * Register the top-level menu page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'NV oOS SaaS Controller', 'nvoos-saas-controller' ),
			__( 'NV oOS SaaS', 'nvoos-saas-controller' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-cloud',
			81
		);
	}

	/**
	 * Resolve the active tab from the request.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of: 'overview', 'packages'.
	 */
	protected static function get_active_tab() {
		// Read-only navigation; no nonce required for tab selection per WP core convention.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'overview', 'deployment', 'operations', 'packages' );
		return in_array( $tab, $allowed, true ) ? $tab : 'overview';
	}

	/**
	 * Render the admin page shell with tabs.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-saas-controller' ) );
		}

		$active = self::get_active_tab();
		$tabs   = array(
			'overview'   => __( 'Overview', 'nvoos-saas-controller' ),
			'deployment' => __( 'Deployment', 'nvoos-saas-controller' ),
			'operations' => __( 'Operations', 'nvoos-saas-controller' ),
			'packages'   => __( 'Packages', 'nvoos-saas-controller' ),
		);
		?>
		<div class="wrap nvoos-saas-controller-wrap">
			<h1><?php esc_html_e( 'NV oOS SaaS Controller', 'nvoos-saas-controller' ); ?></h1>
			<p class="description">
				<?php
				esc_html_e(
					'Operator-side toolkit to deploy and manage the NV oOS Cloud control plane (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter).',
					'nvoos-saas-controller'
				);
				?>
			</p>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => self::PAGE_SLUG,
									'tab'  => $slug,
								),
								admin_url( 'admin.php' )
							)
						);
						?>
								"
						class="nav-tab<?php echo $active === $slug ? ' nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $active ) {
				case 'deployment':
					self::render_deployment_tab();
					break;
				case 'operations':
					self::render_operations_tab();
					break;
				case 'packages':
					self::render_packages_tab();
					break;
				case 'overview':
				default:
					self::render_overview_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Overview tab — high-level status and roadmap.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_overview_tab() {
		$store  = NVOOS_SaaS_Controller_Credential_Store::instance();
		$masked = $store->get_masked();
		?>
		<div id="nvoos-saas-controller-wizard-root" data-mounted="false">
			<noscript>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'JavaScript is disabled. The interactive credentials wizard is unavailable, but the static status table below still works.', 'nvoos-saas-controller' ); ?></p>
				</div>
			</noscript>
		</div>

		<div class="card" style="max-width:780px;padding:1em 1.5em;">
			<h2><?php esc_html_e( 'Status', 'nvoos-saas-controller' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Setting', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'State', 'nvoos-saas-controller' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Addon version', 'nvoos-saas-controller' ); ?></td>
						<td><code><?php echo esc_html( defined( 'NVOOS_SAAS_CONTROLLER_VERSION' ) ? NVOOS_SAAS_CONTROLLER_VERSION : 'dev' ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Base plugin', 'nvoos-saas-controller' ); ?></td>
						<td>
							<?php if ( class_exists( 'WP_MCP_AI_Plugin' ) ) : ?>
								<span style="color:#2e7d32;">●</span> <?php esc_html_e( 'Active', 'nvoos-saas-controller' ); ?>
							<?php else : ?>
								<span style="color:#c62828;">●</span> <?php esc_html_e( 'Missing', 'nvoos-saas-controller' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php foreach ( NVOOS_SaaS_Controller_Credential_Store::ALLOWED_KEYS as $key ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td>
								<?php if ( ! empty( $masked[ $key ]['configured'] ) ) : ?>
									<span style="color:#2e7d32;">●</span>
									<code><?php echo esc_html( $masked[ $key ]['masked'] ); ?></code>
								<?php else : ?>
									<span style="color:#999;">○</span>
									<em><?php esc_html_e( 'not configured', 'nvoos-saas-controller' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Roadmap', 'nvoos-saas-controller' ); ?></h3>
			<p>
				<?php
				esc_html_e(
					'This addon is being delivered in incremental phases. Each phase below ships behind a feature flag and can be activated independently.',
					'nvoos-saas-controller'
				);
				?>
			</p>
			<ul style="list-style:disc;padding-left:1.5em;">
				<li>✅ <strong><?php esc_html_e( 'Phase 1 — Scaffolding', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( 'package layout, attribution, build hooks.', 'nvoos-saas-controller' ); ?></li>
				<li>🚧 <strong><?php esc_html_e( 'Phase 2 — WP-Admin & REST plumbing', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( 'this menu, the Packages tab, the credential store, and the /nvoos-saas/v1 REST namespace.', 'nvoos-saas-controller' ); ?></li>
				<li>✅ <strong><?php esc_html_e( 'Phase 3 — One-Click Wizard', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( 'collect credentials, validate, provision D1 + KV + Worker bindings.', 'nvoos-saas-controller' ); ?></li>
				<li>🚧 <strong><?php esc_html_e( 'Phase 4 — Plan / Apply', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( 'terraform-style preview of every reconcile action — read-only plan generator on the Deployment tab.', 'nvoos-saas-controller' ); ?></li>
				<li>✅ <strong><?php esc_html_e( 'Phase 5 — Apply, Drift, Audit Log, Smoke Tests', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( '5a (audit log + smoke tests), 5b (HITL-gated Apply), 5c (drift detector) and 5d (Worker upload) all shipped.', 'nvoos-saas-controller' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the Deployment tab — desired-config form + plan preview.
	 *
	 * The form is server-rendered (no JS required to save the desired
	 * config); the **Run Plan** button posts to `/nvoos-saas/v1/plan` via a
	 * small inline script and renders the structured plan in-place.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_deployment_tab() {
		$config_store = NVOOS_SaaS_Controller_Deployment_Config::instance();

		// Handle form submission (server-side, no React required for editing).
		if ( ! empty( $_POST['nvoos_saas_deployment_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['nvoos_saas_deployment_nonce'] ) ),
				'nvoos_saas_deployment_save'
			)
		) {
			$incoming = array(
				'worker_name'     => isset( $_POST['worker_name'] ) ? wp_unslash( $_POST['worker_name'] ) : '',
				'account_id'      => isset( $_POST['account_id'] ) ? wp_unslash( $_POST['account_id'] ) : '',
				'ai_gateway_slug' => isset( $_POST['ai_gateway_slug'] ) ? wp_unslash( $_POST['ai_gateway_slug'] ) : '',
				'd1_databases'    => self::parse_pairs_from_post( 'd1', 'name', 'binding' ),
				'kv_namespaces'   => self::parse_pairs_from_post( 'kv', 'title', 'binding' ),
			);
			$config_store->set( $incoming );
			echo '<div class="notice notice-success is-dismissible inline"><p>'
				. esc_html__( 'Desired deployment config saved.', 'nvoos-saas-controller' )
				. '</p></div>';
		}

		$config = $config_store->get();
		?>
		<div class="card" style="max-width:1080px;padding:1em 1.5em;">
			<h2><?php esc_html_e( 'Desired Cloudflare Topology', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The plan generator will diff this config against your live Cloudflare account and tell you exactly what would change. No mutation happens on this tab — Apply is a separate Phase 5 surface gated on HITL approval.', 'nvoos-saas-controller' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'nvoos_saas_deployment_save', 'nvoos_saas_deployment_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nvoos_worker_name"><?php esc_html_e( 'Worker name', 'nvoos-saas-controller' ); ?></label></th>
						<td><input name="worker_name" id="nvoos_worker_name" type="text" class="regular-text" value="<?php echo esc_attr( $config['worker_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="nvoos_account_id"><?php esc_html_e( 'Account ID override', 'nvoos-saas-controller' ); ?></label></th>
						<td>
							<input name="account_id" id="nvoos_account_id" type="text" class="regular-text" value="<?php echo esc_attr( $config['account_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the account ID from the credential store.', 'nvoos-saas-controller' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nvoos_ai_gateway"><?php esc_html_e( 'AI Gateway slug', 'nvoos-saas-controller' ); ?></label></th>
						<td><input name="ai_gateway_slug" id="nvoos_ai_gateway" type="text" class="regular-text" value="<?php echo esc_attr( $config['ai_gateway_slug'] ); ?>" /></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'D1 Databases', 'nvoos-saas-controller' ); ?></h3>
				<?php self::render_pairs_editor( 'd1', $config['d1_databases'], 'name', __( 'Database name', 'nvoos-saas-controller' ), 'binding', __( 'Binding', 'nvoos-saas-controller' ) ); ?>

				<h3><?php esc_html_e( 'KV Namespaces', 'nvoos-saas-controller' ); ?></h3>
				<?php self::render_pairs_editor( 'kv', $config['kv_namespaces'], 'title', __( 'Namespace title', 'nvoos-saas-controller' ), 'binding', __( 'Binding', 'nvoos-saas-controller' ) ); ?>

				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Desired Config', 'nvoos-saas-controller' ); ?></button></p>
			</form>
		</div>

		<div class="card" style="max-width:1080px;padding:1em 1.5em;margin-top:1em;">
			<h2><?php esc_html_e( 'Reconcile Plan (read-only)', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Click Run Plan to call POST /nvoos-saas/v1/plan and see what would change. Listing live resources requires a Cloudflare API token with read scopes on Account, D1, KV, Workers, and AI Gateway.', 'nvoos-saas-controller' ); ?>
			</p>
			<button type="button" class="button button-secondary" id="nvoos-saas-run-plan"><?php esc_html_e( 'Run Plan', 'nvoos-saas-controller' ); ?></button>
			<div id="nvoos-saas-plan-output" style="margin-top:1em;"></div>
		</div>

		<script>
		(function() {
			var btn = document.getElementById( 'nvoos-saas-run-plan' );
			var out = document.getElementById( 'nvoos-saas-plan-output' );
			if ( ! btn || ! window.wp || ! window.wp.apiFetch ) {
				if ( btn ) {
					btn.disabled = true;
					btn.title = 'wp.apiFetch unavailable on this admin page.';
				}
				return;
			}
			function clear( el ) { while ( el.firstChild ) { el.removeChild( el.firstChild ); } }
			function notice( type, msg ) {
				clear( out );
				var div = document.createElement( 'div' );
				div.className = 'notice notice-' + type + ' inline';
				var p = document.createElement( 'p' );
				p.textContent = msg;
				div.appendChild( p );
				out.appendChild( div );
			}
			btn.addEventListener( 'click', function() {
				clear( out );
				var em = document.createElement( 'em' );
				em.textContent = <?php echo wp_json_encode( __( 'Running plan…', 'nvoos-saas-controller' ) ); ?>;
				out.appendChild( em );
				btn.disabled = true;
				wp.apiFetch( { path: '/nvoos-saas/v1/plan', method: 'POST' } ).then( function( resp ) {
					btn.disabled = false;
					var plan = resp && resp.plan ? resp.plan : null;
					if ( ! plan ) {
						notice( 'error', <?php echo wp_json_encode( __( 'No plan returned.', 'nvoos-saas-controller' ) ); ?> );
						return;
					}
					clear( out );
					var table = document.createElement( 'table' );
					table.className = 'widefat striped';
					var thead = document.createElement( 'thead' );
					var theadRow = document.createElement( 'tr' );
					[ 'Section', 'Count' ].forEach( function( h ) {
						var th = document.createElement( 'th' );
						th.textContent = h;
						theadRow.appendChild( th );
					} );
					thead.appendChild( theadRow );
					table.appendChild( thead );
					var tbody = document.createElement( 'tbody' );
					[ 'creates', 'updates', 'noops', 'orphans', 'errors' ].forEach( function( k ) {
						var tr = document.createElement( 'tr' );
						var td1 = document.createElement( 'td' );
						var strong = document.createElement( 'strong' );
						strong.textContent = k;
						td1.appendChild( strong );
						var td2 = document.createElement( 'td' );
						td2.textContent = String( ( plan.summary && plan.summary[ k ] ) || 0 );
						tr.appendChild( td1 );
						tr.appendChild( td2 );
						tbody.appendChild( tr );
					} );
					table.appendChild( tbody );
					out.appendChild( table );
					var pre = document.createElement( 'pre' );
					pre.style.cssText = 'margin-top:1em;background:#f6f7f7;padding:1em;border:1px solid #ccd0d4;overflow:auto;max-height:480px;';
					pre.textContent = JSON.stringify( plan, null, 2 );
					out.appendChild( pre );
				} ).catch( function( err ) {
					btn.disabled = false;
					var msg = ( err && err.message ) ? err.message : 'Plan failed.';
					notice( 'error', msg );
				} );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * Helper: render a paired text-input editor for D1/KV style rows.
	 *
	 * @param string $prefix    Form prefix (e.g. `d1`).
	 * @param array  $rows      Existing rows.
	 * @param string $key_a     First field key.
	 * @param string $label_a   First field label.
	 * @param string $key_b     Second field key.
	 * @param string $label_b   Second field label.
	 * @return void
	 */
	protected static function render_pairs_editor( $prefix, array $rows, $key_a, $label_a, $key_b, $label_b ) {
		// Always render at least one blank row so the operator can add an entry on first visit.
		if ( empty( $rows ) ) {
			$rows[] = array(
				$key_a => '',
				$key_b => '',
			);
		}
		// Add an extra blank row at the end so adding entries doesn't require JS.
		$rows[] = array(
			$key_a => '',
			$key_b => '',
		);
		?>
		<table class="widefat striped" style="max-width:780px;">
			<thead>
				<tr>
					<th><?php echo esc_html( $label_a ); ?></th>
					<th><?php echo esc_html( $label_b ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[' . $i . '][' . $key_a . ']' ); ?>" value="<?php echo esc_attr( isset( $row[ $key_a ] ) ? $row[ $key_a ] : '' ); ?>" /></td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[' . $i . '][' . $key_b . ']' ); ?>" value="<?php echo esc_attr( isset( $row[ $key_b ] ) ? $row[ $key_b ] : '' ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Parse a `$_POST['<prefix>']` array of `{ key_a, key_b }` rows.
	 *
	 * Empty rows are dropped; the per-field sanitiser in
	 * {@see NVOOS_SaaS_Controller_Deployment_Config::sanitize()} runs after.
	 *
	 * @param string $prefix POST array key.
	 * @param string $key_a  First field name.
	 * @param string $key_b  Second field name.
	 * @return array
	 */
	protected static function parse_pairs_from_post( $prefix, $key_a, $key_b ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		if ( empty( $_POST[ $prefix ] ) || ! is_array( $_POST[ $prefix ] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST[ $prefix ] );
		// phpcs:enable
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$a = isset( $row[ $key_a ] ) ? (string) $row[ $key_a ] : '';
			$b = isset( $row[ $key_b ] ) ? (string) $row[ $key_b ] : '';
			if ( '' === trim( $a ) || '' === trim( $b ) ) {
				continue;
			}
			$out[] = array(
				$key_a => $a,
				$key_b => $b,
			);
		}
		return $out;
	}

	/**
	 * Render the Operations tab — recent audit log + smoke-test runner.
	 *
	 * The tab is intentionally lightweight: a "Run Smoke Tests" button
	 * (drives `POST /smoke-tests/run` via `wp.apiFetch`), the last
	 * cached result rendered server-side, and a recent audit-log table.
	 * No third-party JS framework — same pattern as the Deployment tab.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_operations_tab() {
		$audit_log   = NVOOS_SaaS_Controller_Audit_Log::instance();
		$entries     = $audit_log->get_recent( 50 );
		$tester      = new NVOOS_SaaS_Controller_Smoke_Tester();
		$last_result = $tester->get_last_result();
		$rest_url    = esc_url_raw( rest_url( 'nvoos-saas/v1/' ) );
		?>
		<div class="card" style="max-width:1080px;padding:1em 1.5em;">
			<h2><?php esc_html_e( 'Apply (HITL-gated)', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Two-step approval: Preview re-runs the plan and issues a single-use apply token (15-minute TTL). Apply consumes that token and mutates Cloudflare. Each Cloudflare write records one audit-log entry.', 'nvoos-saas-controller' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="nvoos-saas-apply-preview"><?php esc_html_e( 'Preview Apply', 'nvoos-saas-controller' ); ?></button>
				<button type="button" class="button button-primary" id="nvoos-saas-apply-run" disabled><?php esc_html_e( 'Apply…', 'nvoos-saas-controller' ); ?></button>
				<button type="button" class="button" id="nvoos-saas-apply-cancel" hidden><?php esc_html_e( 'Cancel', 'nvoos-saas-controller' ); ?></button>
				<span id="nvoos-saas-apply-status" style="margin-left:0.75em;" aria-live="polite"></span>
			</p>
			<p>
				<label for="nvoos-saas-apply-background">
					<input type="checkbox" id="nvoos-saas-apply-background" />
					<?php esc_html_e( 'Run in background (recommended for large plans — processes one row per cron tick to avoid PHP timeouts)', 'nvoos-saas-controller' ); ?>
				</label>
			</p>
			<div id="nvoos-saas-apply-progress" hidden>
				<p>
					<progress id="nvoos-saas-apply-progress-bar" max="100" value="0" style="width:100%;height:1.4em;"></progress>
				</p>
				<p id="nvoos-saas-apply-progress-text" style="margin:0;"></p>
			</div>
			<div id="nvoos-saas-apply-output"></div>
		</div>

		<?php self::render_drift_card(); ?>

		<?php self::render_orphans_card(); ?>

		<div class="card" style="max-width:1080px;padding:1em 1.5em;margin-top:1em;">
			<h2><?php esc_html_e( 'Smoke Tests', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Runs a fixed sequence of read-only health checks: credential presence, live Cloudflare workers list, plan dry-run, and base-plugin liveness.', 'nvoos-saas-controller' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="nvoos-saas-run-smoke-tests"><?php esc_html_e( 'Run Smoke Tests', 'nvoos-saas-controller' ); ?></button>
				<span id="nvoos-saas-smoke-status" style="margin-left:0.75em;" aria-live="polite"></span>
			</p>
			<?php if ( null !== $last_result ) : ?>
				<h3><?php esc_html_e( 'Last Result', 'nvoos-saas-controller' ); ?></h3>
				<p>
					<strong><?php echo esc_html( ! empty( $last_result['ok'] ) ? __( '✅ All checks passed', 'nvoos-saas-controller' ) : __( '⚠️ One or more checks failed', 'nvoos-saas-controller' ) ); ?></strong>
					<span style="color:#666;margin-left:0.5em;">
						<?php
						printf(
							/* translators: 1: timestamp, 2: duration in ms */
							esc_html__( 'at %1$s · %2$d ms', 'nvoos-saas-controller' ),
							esc_html( gmdate( 'Y-m-d H:i:s', (int) $last_result['ts'] ) . ' UTC' ),
							(int) ( isset( $last_result['duration_ms'] ) ? $last_result['duration_ms'] : 0 )
						);
						?>
					</span>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'nvoos-saas-controller' ); ?></th>
							<th><?php esc_html_e( 'Status', 'nvoos-saas-controller' ); ?></th>
							<th><?php esc_html_e( 'Latency', 'nvoos-saas-controller' ); ?></th>
							<th><?php esc_html_e( 'Message', 'nvoos-saas-controller' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( (array) $last_result['checks'] as $check ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $check['name'] ) ? (string) $check['name'] : '' ); ?></code></td>
								<td><?php echo ! empty( $check['ok'] ) ? '<span style="color:#0a7d18;">✅</span>' : '<span style="color:#b32d2e;">❌</span>'; ?></td>
								<td><?php echo (int) ( isset( $check['latency_ms'] ) ? $check['latency_ms'] : 0 ); ?> ms</td>
								<td><?php echo esc_html( isset( $check['message'] ) ? (string) $check['message'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="card" style="max-width:1080px;padding:1em 1.5em;margin-top:1em;">
			<h2><?php esc_html_e( 'Audit Log', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Most recent 50 entries. The log is a ring buffer capped at 200 entries; older entries are discarded automatically.', 'nvoos-saas-controller' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="nvoos-saas-clear-audit-log"><?php esc_html_e( 'Clear Audit Log', 'nvoos-saas-controller' ); ?></button>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Action', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Latency', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Message', 'nvoos-saas-controller' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="7"><em><?php esc_html_e( 'No audit-log entries yet.', 'nvoos-saas-controller' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $row ) : ?>
							<tr>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( isset( $row['ts'] ) ? $row['ts'] : 0 ) ) ); ?></td>
								<td><?php echo esc_html( isset( $row['actor'] ) ? (string) $row['actor'] : '' ); ?></td>
								<td><code><?php echo esc_html( isset( $row['channel'] ) ? (string) $row['channel'] : '' ); ?></code></td>
								<td><code><?php echo esc_html( isset( $row['action'] ) ? (string) $row['action'] : '' ); ?></code></td>
								<td>
									<?php
									$status = isset( $row['status'] ) ? (string) $row['status'] : '';
									if ( 'ok' === $status ) {
										echo '<span style="color:#0a7d18;">ok</span>';
									} else {
										echo '<span style="color:#b32d2e;">' . esc_html( $status ) . '</span>';
									}
									?>
								</td>
								<td><?php echo (int) ( isset( $row['latency_ms'] ) ? $row['latency_ms'] : 0 ); ?> ms</td>
								<td><?php echo esc_html( isset( $row['message'] ) ? (string) $row['message'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		( function () {
			if ( ! window.wp || ! wp.apiFetch ) { return; }
			var statusEl = document.getElementById( 'nvoos-saas-smoke-status' );
			var runBtn   = document.getElementById( 'nvoos-saas-run-smoke-tests' );
			var clearBtn = document.getElementById( 'nvoos-saas-clear-audit-log' );
			if ( runBtn ) {
				runBtn.addEventListener( 'click', function () {
					runBtn.disabled = true;
					if ( statusEl ) { statusEl.textContent = <?php echo wp_json_encode( __( 'Running…', 'nvoos-saas-controller' ) ); ?>; }
					wp.apiFetch( { path: 'nvoos-saas/v1/smoke-tests/run', method: 'POST' } )
						.then( function () { window.location.reload(); } )
						.catch( function ( err ) {
							runBtn.disabled = false;
							if ( statusEl ) { statusEl.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Smoke test failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Clear all audit-log entries?', 'nvoos-saas-controller' ) ); ?> ) ) { return; }
					wp.apiFetch( { path: 'nvoos-saas/v1/audit-log', method: 'DELETE' } )
						.then( function () { window.location.reload(); } );
				} );
			}

			var previewBtn = document.getElementById( 'nvoos-saas-apply-preview' );
			var applyBtn   = document.getElementById( 'nvoos-saas-apply-run' );
			var applyOut   = document.getElementById( 'nvoos-saas-apply-output' );
			var applyMsg   = document.getElementById( 'nvoos-saas-apply-status' );
			var pendingToken = null;

			function renderPlanSummary( plan ) {
				while ( applyOut.firstChild ) { applyOut.removeChild( applyOut.firstChild ); }
				if ( ! plan || ! plan.summary ) { return; }
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';
				table.style.marginTop = '1em';
				var thead = document.createElement( 'thead' );
				var hr = document.createElement( 'tr' );
				[ 'Section', 'Count' ].forEach( function ( h ) {
					var th = document.createElement( 'th' );
					th.textContent = h;
					hr.appendChild( th );
				} );
				thead.appendChild( hr );
				table.appendChild( thead );
				var tbody = document.createElement( 'tbody' );
				[ 'creates', 'updates', 'noops', 'orphans', 'errors' ].forEach( function ( k ) {
					var tr = document.createElement( 'tr' );
					var td1 = document.createElement( 'td' );
					var s = document.createElement( 'strong' );
					s.textContent = k;
					td1.appendChild( s );
					var td2 = document.createElement( 'td' );
					td2.textContent = String( ( plan.summary && plan.summary[ k ] ) || 0 );
					tr.appendChild( td1 );
					tr.appendChild( td2 );
					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );
				applyOut.appendChild( table );
			}

			function renderApplyResults( result ) {
				if ( ! result || ! result.results ) { return; }
				while ( applyOut.firstChild ) { applyOut.removeChild( applyOut.firstChild ); }
				var h = document.createElement( 'h3' );
				h.textContent = <?php echo wp_json_encode( __( 'Apply Result', 'nvoos-saas-controller' ) ); ?>;
				applyOut.appendChild( h );
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';
				var thead = document.createElement( 'thead' );
				var hr = document.createElement( 'tr' );
				[ 'Kind', 'Target', 'Status', 'Message' ].forEach( function ( label ) {
					var th = document.createElement( 'th' );
					th.textContent = label;
					hr.appendChild( th );
				} );
				thead.appendChild( hr );
				table.appendChild( thead );
				var tbody = document.createElement( 'tbody' );
				result.results.forEach( function ( row ) {
					var tr = document.createElement( 'tr' );
					[ row.kind || '', row.target || '', row.status || '', row.message || '' ].forEach( function ( v ) {
						var td = document.createElement( 'td' );
						td.textContent = String( v );
						tr.appendChild( td );
					} );
					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );
				applyOut.appendChild( table );
			}

			if ( previewBtn ) {
				previewBtn.addEventListener( 'click', function () {
					previewBtn.disabled = true;
					if ( applyBtn ) { applyBtn.disabled = true; }
					pendingToken = null;
					if ( applyMsg ) { applyMsg.textContent = <?php echo wp_json_encode( __( 'Generating plan…', 'nvoos-saas-controller' ) ); ?>; }
					wp.apiFetch( { path: 'nvoos-saas/v1/apply/preview', method: 'POST' } )
						.then( function ( resp ) {
							previewBtn.disabled = false;
							pendingToken = resp && resp.apply_token ? String( resp.apply_token ) : null;
							renderPlanSummary( resp && resp.plan );
							if ( applyBtn ) { applyBtn.disabled = ! pendingToken; }
							if ( applyMsg ) {
								var ttl = resp && resp.expires_in ? Number( resp.expires_in ) : 0;
								applyMsg.textContent = pendingToken
									? <?php echo wp_json_encode( __( 'Token issued. Click Apply within ', 'nvoos-saas-controller' ) ); ?> + Math.floor( ttl / 60 ) + 'm.'
									: <?php echo wp_json_encode( __( 'No token issued.', 'nvoos-saas-controller' ) ); ?>;
							}
						} )
						.catch( function ( err ) {
							previewBtn.disabled = false;
							if ( applyBtn ) { applyBtn.disabled = true; }
							if ( applyMsg ) { applyMsg.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Preview failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}
			var bgCheckbox = document.getElementById( 'nvoos-saas-apply-background' );
			var cancelBtn  = document.getElementById( 'nvoos-saas-apply-cancel' );
			var progressEl = document.getElementById( 'nvoos-saas-apply-progress' );
			var progressBar = document.getElementById( 'nvoos-saas-apply-progress-bar' );
			var progressText = document.getElementById( 'nvoos-saas-apply-progress-text' );
			var pollTimer  = null;
			var activeJobId = null;
			var POLL_INTERVAL_MS = 2000;

			function setProgressVisible( visible ) {
				if ( progressEl ) { progressEl.hidden = ! visible; }
			}
			function setCancelVisible( visible ) {
				if ( cancelBtn ) { cancelBtn.hidden = ! visible; }
			}
			function renderProgress( job ) {
				if ( ! job ) { return; }
				if ( progressBar ) { progressBar.value = Number( job.percent || 0 ); }
				if ( progressText ) {
					var parts = [];
					parts.push( <?php echo wp_json_encode( __( 'Status', 'nvoos-saas-controller' ) ); ?> + ': ' + String( job.status || '' ) );
					parts.push( String( job.processed || 0 ) + ' / ' + String( job.total || 0 ) + ' (' + String( job.percent || 0 ) + '%)' );
					if ( job.summary ) {
						parts.push( 'ok=' + ( job.summary.ok || 0 ) + ' err=' + ( job.summary.error || 0 ) + ' skip=' + ( job.summary.skipped || 0 ) );
					}
					if ( job.last_message ) { parts.push( String( job.last_message ) ); }
					progressText.textContent = parts.join( ' · ' );
				}
				if ( job.results && job.results.length ) {
					renderApplyResults( { results: job.results } );
				}
			}
			function isTerminal( status ) {
				return status === 'completed' || status === 'cancelled' || status === 'failed';
			}
			function stopPolling() {
				if ( pollTimer ) { window.clearTimeout( pollTimer ); pollTimer = null; }
			}
			function pollJob( jobId ) {
				wp.apiFetch( { path: 'nvoos-saas/v1/apply/jobs/' + encodeURIComponent( jobId ), method: 'GET' } )
					.then( function ( resp ) {
						var job = resp && resp.job;
						renderProgress( job );
						if ( job && isTerminal( job.status ) ) {
							stopPolling();
							setCancelVisible( false );
							activeJobId = null;
							if ( applyMsg ) {
								applyMsg.textContent = ( job.status === 'completed' )
									? <?php echo wp_json_encode( __( 'Background apply complete.', 'nvoos-saas-controller' ) ); ?>
									: ( job.status === 'cancelled' )
										? <?php echo wp_json_encode( __( 'Background apply cancelled.', 'nvoos-saas-controller' ) ); ?>
										: <?php echo wp_json_encode( __( 'Background apply finished with errors — see results.', 'nvoos-saas-controller' ) ); ?>;
							}
							return;
						}
						pollTimer = window.setTimeout( function () { pollJob( jobId ); }, POLL_INTERVAL_MS );
					} )
					.catch( function ( err ) {
						stopPolling();
						setCancelVisible( false );
						activeJobId = null;
						if ( applyMsg ) { applyMsg.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Lost contact with apply job.', 'nvoos-saas-controller' ) ); ?>; }
					} );
			}

			if ( applyBtn ) {
				applyBtn.addEventListener( 'click', function () {
					if ( ! pendingToken ) { return; }
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Apply the previewed plan to Cloudflare? This will create live resources.', 'nvoos-saas-controller' ) ); ?> ) ) { return; }
					applyBtn.disabled = true;
					var runInBackground = bgCheckbox && bgCheckbox.checked;
					if ( applyMsg ) {
						applyMsg.textContent = runInBackground
							? <?php echo wp_json_encode( __( 'Enqueuing background apply…', 'nvoos-saas-controller' ) ); ?>
							: <?php echo wp_json_encode( __( 'Applying…', 'nvoos-saas-controller' ) ); ?>;
					}
					var token = pendingToken;
					pendingToken = null;
					if ( runInBackground ) {
						setProgressVisible( true );
						wp.apiFetch( {
							path:   'nvoos-saas/v1/apply/enqueue',
							method: 'POST',
							data:   { apply_token: token }
						} )
							.then( function ( resp ) {
								var job = resp && resp.job;
								if ( ! job || ! job.id ) {
									if ( applyMsg ) { applyMsg.textContent = <?php echo wp_json_encode( __( 'Enqueue failed: no job id returned.', 'nvoos-saas-controller' ) ); ?>; }
									return;
								}
								activeJobId = job.id;
								setCancelVisible( true );
								renderProgress( job );
								pollJob( job.id );
							} )
							.catch( function ( err ) {
								if ( applyMsg ) { applyMsg.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Enqueue failed.', 'nvoos-saas-controller' ) ); ?>; }
							} );
						return;
					}
					wp.apiFetch( {
						path:   'nvoos-saas/v1/apply/run',
						method: 'POST',
						data:   { apply_token: token }
					} )
						.then( function ( resp ) {
							renderApplyResults( resp );
							if ( applyMsg ) {
								applyMsg.textContent = ( resp && resp.ok )
									? <?php echo wp_json_encode( __( 'Apply complete.', 'nvoos-saas-controller' ) ); ?>
									: <?php echo wp_json_encode( __( 'Apply finished with errors — see results.', 'nvoos-saas-controller' ) ); ?>;
							}
						} )
						.catch( function ( err ) {
							if ( applyMsg ) { applyMsg.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Apply failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}
			if ( cancelBtn ) {
				cancelBtn.addEventListener( 'click', function () {
					if ( ! activeJobId ) { return; }
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Cancel this background apply? An already-firing tick will finish its current row first.', 'nvoos-saas-controller' ) ); ?> ) ) { return; }
					var jobId = activeJobId;
					cancelBtn.disabled = true;
					wp.apiFetch( { path: 'nvoos-saas/v1/apply/jobs/' + encodeURIComponent( jobId ) + '/cancel', method: 'POST' } )
						.then( function ( resp ) {
							cancelBtn.disabled = false;
							if ( resp && resp.job ) { renderProgress( resp.job ); }
						} )
						.catch( function ( err ) {
							cancelBtn.disabled = false;
							if ( applyMsg ) { applyMsg.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Cancel failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Render the Review Orphans card inside the Operations tab (Phase 10).
	 *
	 * The card is opt-in by design: every checkbox starts *unchecked* and
	 * a per-row delete confirmation is required before the destructive
	 * REST call. The orphan token namespace is separate from the regular
	 * Apply token (see {@see NVOOS_SaaS_Controller_Apply_Engine::ORPHAN_TRANSIENT_PREFIX})
	 * so a careless click on the Apply button can never nuke production
	 * resources.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_orphans_card() {
		?>
		<div class="card" style="max-width:1080px;padding:1em 1.5em;margin-top:1em;">
			<h2><?php esc_html_e( 'Review Orphans (HITL-gated delete)', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Cloudflare D1 / KV / AI-Gateway, Stripe, and OpenRouter resources that exist live but no longer appear in the desired config. The list is read-only until you tick the boxes you want to delete and click "Delete Selected" — Stripe rows are archived (active=false), every other row is permanently deleted. Each click issues a separate single-use token (15-minute TTL) distinct from the Apply token, so it cannot be replayed against the regular Apply surface.', 'nvoos-saas-controller' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="nvoos-saas-orphans-review"><?php esc_html_e( 'Review Orphans', 'nvoos-saas-controller' ); ?></button>
				<button type="button" class="button button-link-delete" id="nvoos-saas-orphans-delete" disabled><?php esc_html_e( 'Delete Selected…', 'nvoos-saas-controller' ); ?></button>
				<span id="nvoos-saas-orphans-status" style="margin-left:0.75em;" aria-live="polite"></span>
			</p>
			<div id="nvoos-saas-orphans-output"></div>
		</div>

		<script>
		( function () {
			if ( ! window.wp || ! wp.apiFetch ) { return; }
			var reviewBtn = document.getElementById( 'nvoos-saas-orphans-review' );
			var deleteBtn = document.getElementById( 'nvoos-saas-orphans-delete' );
			var statusEl  = document.getElementById( 'nvoos-saas-orphans-status' );
			var outEl     = document.getElementById( 'nvoos-saas-orphans-output' );
			var pendingToken   = null;
			var pendingOrphans = [];

			function clearOut() {
				while ( outEl.firstChild ) { outEl.removeChild( outEl.firstChild ); }
			}

			function identityFor( row ) {
				if ( ! row || ! row.kind ) { return ''; }
				switch ( row.kind ) {
					case 'd1':             return 'd1:' + ( row.uuid || '' );
					case 'kv':             return 'kv:' + ( row.id || '' );
					case 'ai_gateway':     return 'ai_gateway:' + ( row.slug || '' );
					case 'stripe_product': return 'stripe_product:' + ( row.id || '' );
					case 'stripe_price':   return 'stripe_price:' + ( row.id || '' );
					case 'openrouter_key': return 'openrouter_key:' + ( row.hash || '' );
				}
				return '';
			}

			function labelFor( row ) {
				if ( ! row ) { return ''; }
				return String( row.name || row.title || row.slug || row.label || row.id || row.hash || '' );
			}

			function updateDeleteEnabled() {
				if ( ! deleteBtn ) { return; }
				var anyChecked = !! outEl.querySelector( 'input[type="checkbox"][data-nvoos-orphan]:checked' );
				deleteBtn.disabled = ! ( anyChecked && pendingToken );
			}

			function renderOrphansTable( orphans ) {
				clearOut();
				if ( ! orphans || ! orphans.length ) {
					var p = document.createElement( 'p' );
					p.innerHTML = '<em>' + <?php echo wp_json_encode( __( 'No orphans detected — your live infrastructure matches the desired config.', 'nvoos-saas-controller' ) ); ?> + '</em>';
					outEl.appendChild( p );
					return;
				}
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';
				table.style.marginTop = '1em';
				var thead = document.createElement( 'thead' );
				var hr = document.createElement( 'tr' );
				[
					<?php echo wp_json_encode( __( 'Delete?', 'nvoos-saas-controller' ) ); ?>,
					<?php echo wp_json_encode( __( 'Kind', 'nvoos-saas-controller' ) ); ?>,
					<?php echo wp_json_encode( __( 'Identifier', 'nvoos-saas-controller' ) ); ?>,
					<?php echo wp_json_encode( __( 'Detail', 'nvoos-saas-controller' ) ); ?>
				].forEach( function ( label ) {
					var th = document.createElement( 'th' );
					th.textContent = label;
					hr.appendChild( th );
				} );
				thead.appendChild( hr );
				table.appendChild( thead );
				var tbody = document.createElement( 'tbody' );
				orphans.forEach( function ( row, idx ) {
					var tr = document.createElement( 'tr' );

					var td0 = document.createElement( 'td' );
					var cb = document.createElement( 'input' );
					cb.type = 'checkbox';
					cb.checked = false; // never default-on — operator must opt in per row.
					cb.setAttribute( 'data-nvoos-orphan', identityFor( row ) );
					cb.addEventListener( 'change', updateDeleteEnabled );
					td0.appendChild( cb );
					tr.appendChild( td0 );

					var td1 = document.createElement( 'td' );
					var c = document.createElement( 'code' );
					c.textContent = String( row.kind || '' );
					td1.appendChild( c );
					tr.appendChild( td1 );

					var td2 = document.createElement( 'td' );
					td2.textContent = labelFor( row );
					tr.appendChild( td2 );

					var td3 = document.createElement( 'td' );
					var detailParts = [];
					if ( row.uuid ) { detailParts.push( 'uuid=' + row.uuid ); }
					if ( row.id )   { detailParts.push( 'id=' + row.id ); }
					if ( row.hash ) { detailParts.push( 'hash=' + row.hash ); }
					td3.textContent = detailParts.join( ' · ' );
					tr.appendChild( td3 );

					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );
				outEl.appendChild( table );
			}

			function renderOrphansResult( resp ) {
				if ( ! resp || ! resp.results ) { return; }
				clearOut();
				var h = document.createElement( 'h3' );
				h.textContent = <?php echo wp_json_encode( __( 'Orphan Cleanup Result', 'nvoos-saas-controller' ) ); ?>;
				outEl.appendChild( h );
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';
				var thead = document.createElement( 'thead' );
				var hr = document.createElement( 'tr' );
				[ 'Kind', 'Target', 'Status', 'Message' ].forEach( function ( label ) {
					var th = document.createElement( 'th' );
					th.textContent = label;
					hr.appendChild( th );
				} );
				thead.appendChild( hr );
				table.appendChild( thead );
				var tbody = document.createElement( 'tbody' );
				resp.results.forEach( function ( row ) {
					var tr = document.createElement( 'tr' );
					[ row.kind || '', row.target || '', row.status || '', row.message || '' ].forEach( function ( v ) {
						var td = document.createElement( 'td' );
						td.textContent = String( v );
						tr.appendChild( td );
					} );
					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );
				outEl.appendChild( table );
			}

			if ( reviewBtn ) {
				reviewBtn.addEventListener( 'click', function () {
					reviewBtn.disabled = true;
					if ( deleteBtn ) { deleteBtn.disabled = true; }
					pendingToken = null;
					pendingOrphans = [];
					if ( statusEl ) { statusEl.textContent = <?php echo wp_json_encode( __( 'Generating orphan list…', 'nvoos-saas-controller' ) ); ?>; }
					wp.apiFetch( { path: 'nvoos-saas/v1/apply/orphans/preview', method: 'POST' } )
						.then( function ( resp ) {
							reviewBtn.disabled = false;
							pendingToken   = resp && resp.orphan_token ? String( resp.orphan_token ) : null;
							pendingOrphans = ( resp && resp.orphans ) || [];
							renderOrphansTable( pendingOrphans );
							updateDeleteEnabled();
							if ( statusEl ) {
								var ttl = resp && resp.expires_in ? Number( resp.expires_in ) : 0;
								statusEl.textContent = pendingToken
									? <?php echo wp_json_encode( __( 'Orphan token issued. Tick the rows you want to delete (TTL ', 'nvoos-saas-controller' ) ); ?> + Math.floor( ttl / 60 ) + 'm).'
									: <?php echo wp_json_encode( __( 'No orphan token issued.', 'nvoos-saas-controller' ) ); ?>;
							}
						} )
						.catch( function ( err ) {
							reviewBtn.disabled = false;
							if ( statusEl ) { statusEl.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Orphan preview failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}

			if ( deleteBtn ) {
				deleteBtn.addEventListener( 'click', function () {
					if ( ! pendingToken ) { return; }
					var checked = outEl.querySelectorAll( 'input[type="checkbox"][data-nvoos-orphan]:checked' );
					if ( ! checked.length ) { return; }
					var ids = {};
					Array.prototype.forEach.call( checked, function ( cb ) {
						ids[ cb.getAttribute( 'data-nvoos-orphan' ) ] = true;
					} );
					var selected = pendingOrphans.filter( function ( row ) {
						return !! ids[ identityFor( row ) ];
					} );
					if ( ! selected.length ) { return; }
					var msg = <?php echo wp_json_encode( __( 'Permanently delete the selected orphans? Stripe rows are archived (active=false); every other row is permanently deleted from Cloudflare / OpenRouter. This cannot be undone.', 'nvoos-saas-controller' ) ); ?>
						+ '\n\n' + selected.map( function ( r ) { return '• ' + r.kind + ' — ' + labelFor( r ); } ).join( '\n' );
					if ( ! window.confirm( msg ) ) { return; }
					deleteBtn.disabled = true;
					if ( statusEl ) { statusEl.textContent = <?php echo wp_json_encode( __( 'Deleting…', 'nvoos-saas-controller' ) ); ?>; }
					var token = pendingToken;
					pendingToken = null;
					wp.apiFetch( {
						path:   'nvoos-saas/v1/apply/orphans/run',
						method: 'POST',
						data:   { orphan_token: token, selected: selected }
					} )
						.then( function ( resp ) {
							renderOrphansResult( resp );
							if ( statusEl ) {
								statusEl.textContent = ( resp && resp.ok )
									? <?php echo wp_json_encode( __( 'Orphan cleanup complete.', 'nvoos-saas-controller' ) ); ?>
									: <?php echo wp_json_encode( __( 'Orphan cleanup finished with errors — see results.', 'nvoos-saas-controller' ) ); ?>;
							}
						} )
						.catch( function ( err ) {
							if ( statusEl ) { statusEl.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Orphan cleanup failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Render the Drift Detector card inside the Operations tab.
	 *
	 * Pulls the cached last-result via the detector singleton-style API
	 * (no fresh API call here — the operator has to click the "Run Drift
	 * Check" button to trigger Cloudflare traffic). The card colour-codes
	 * the status row so `drift` jumps off the page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_drift_card() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$last     = $detector->get_last_result();
		?>
		<div class="card" style="max-width:1080px;padding:1em 1.5em;margin-top:1em;">
			<h2><?php esc_html_e( 'Drift Detector', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Compares the deployed Cloudflare Worker against the addon\'s pinned worker/dist/index.js fingerprint. Read-only — never mutates Cloudflare. A red banner is shown across all NV oOS SaaS screens when drift is detected.', 'nvoos-saas-controller' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="nvoos-saas-run-drift-check"><?php esc_html_e( 'Run Drift Check', 'nvoos-saas-controller' ); ?></button>
				<span id="nvoos-saas-drift-status" style="margin-left:0.75em;" aria-live="polite"></span>
			</p>
			<?php if ( null !== $last ) : ?>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th scope="row" style="width:200px;"><?php esc_html_e( 'Status', 'nvoos-saas-controller' ); ?></th>
							<td><?php echo self::render_drift_status_badge( isset( $last['status'] ) ? (string) $last['status'] : 'unknown' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Worker', 'nvoos-saas-controller' ); ?></th>
							<td><code><?php echo esc_html( isset( $last['worker_name'] ) ? (string) $last['worker_name'] : '' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pinned (etag)', 'nvoos-saas-controller' ); ?></th>
							<td><code><?php echo esc_html( ! empty( $last['expected_etag'] ) ? (string) $last['expected_etag'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Deployed (etag)', 'nvoos-saas-controller' ); ?></th>
							<td><code><?php echo esc_html( ! empty( $last['actual_etag'] ) ? (string) $last['actual_etag'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pinned (sha256)', 'nvoos-saas-controller' ); ?></th>
							<td><code style="word-break:break-all;"><?php echo esc_html( ! empty( $last['expected_sha256'] ) ? (string) $last['expected_sha256'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Deployed (sha256)', 'nvoos-saas-controller' ); ?></th>
							<td><code style="word-break:break-all;"><?php echo esc_html( ! empty( $last['actual_sha256'] ) ? (string) $last['actual_sha256'] : '—' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Message', 'nvoos-saas-controller' ); ?></th>
							<td><?php echo esc_html( isset( $last['message'] ) ? (string) $last['message'] : '' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last Checked', 'nvoos-saas-controller' ); ?></th>
							<td>
								<?php
								printf(
									/* translators: 1: timestamp, 2: duration in ms */
									esc_html__( '%1$s · %2$d ms', 'nvoos-saas-controller' ),
									esc_html( gmdate( 'Y-m-d H:i:s', (int) ( isset( $last['ts'] ) ? $last['ts'] : 0 ) ) . ' UTC' ),
									(int) ( isset( $last['duration_ms'] ) ? $last['duration_ms'] : 0 )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No drift check has been run yet.', 'nvoos-saas-controller' ); ?></em></p>
			<?php endif; ?>
			<script>
			( function () {
				if ( ! window.wp || ! wp.apiFetch ) { return; }
				var btn = document.getElementById( 'nvoos-saas-run-drift-check' );
				var status = document.getElementById( 'nvoos-saas-drift-status' );
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					if ( status ) { status.textContent = <?php echo wp_json_encode( __( 'Checking…', 'nvoos-saas-controller' ) ); ?>; }
					wp.apiFetch( { path: 'nvoos-saas/v1/drift/check', method: 'POST' } )
						.then( function () { window.location.reload(); } )
						.catch( function ( err ) {
							btn.disabled = false;
							if ( status ) { status.textContent = ( err && err.message ) ? String( err.message ) : <?php echo wp_json_encode( __( 'Drift check failed.', 'nvoos-saas-controller' ) ); ?>; }
						} );
				} );
			}() );
			</script>
		</div>
		<?php
	}

	/**
	 * Render an inline status badge for a drift state.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status One of synced/drift/unknown/error.
	 * @return string Pre-escaped HTML.
	 */
	protected static function render_drift_status_badge( $status ) {
		$colors = array(
			'synced'  => '#0a7d18',
			'drift'   => '#b32d2e',
			'error'   => '#b32d2e',
			'unknown' => '#666',
		);
		$icons  = array(
			'synced'  => '✅',
			'drift'   => '⚠️',
			'error'   => '❌',
			'unknown' => '❔',
		);
		$labels = array(
			'synced'  => __( 'In sync', 'nvoos-saas-controller' ),
			'drift'   => __( 'Drift detected', 'nvoos-saas-controller' ),
			'error'   => __( 'Error', 'nvoos-saas-controller' ),
			'unknown' => __( 'Unknown', 'nvoos-saas-controller' ),
		);
		$key    = isset( $colors[ $status ] ) ? $status : 'unknown';
		$color  = $colors[ $key ];
		$icon   = $icons[ $key ];
		$label  = $labels[ $key ];
		return '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $icon . ' ' . $label ) . '</strong>';
	}

	/**
	 * Print an admin-wide drift banner when the cached drift state is
	 * `drift`. Shown only on NV oOS SaaS screens (matches the page slug
	 * via the current screen's `id`) so other admin pages stay quiet.
	 *
	 * Never makes a live Cloudflare call — reads only the cached option.
	 * The operator has to explicitly click "Run Drift Check" for new
	 * data, which means the banner cannot disappear without an action
	 * (acknowledged design: drift should be visible until acted on).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_render_drift_banner() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) || false === strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Drift_Detector' ) ) {
			return;
		}
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$last     = $detector->get_last_result();
		if ( ! is_array( $last ) || empty( $last['status'] ) || 'drift' !== $last['status'] ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo '<strong>' . esc_html__( 'NV oOS SaaS — Cloudflare Worker drift detected.', 'nvoos-saas-controller' ) . '</strong> ';
		echo esc_html( isset( $last['message'] ) ? (string) $last['message'] : '' );
		echo '</p></div>';
	}

	/**
	 * Render the Packages tab — in-product credits surface.
	 *
	 * Mirrors `WP_MCP_AI_Pro_Packages_Settings_Page::render_packages_table()`
	 * but is self-contained: the SaaS Controller addon depends only on the
	 * NV oOS base plugin, never on Pro.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected static function render_packages_tab() {
		$packages = self::get_package_definitions();
		?>
		<div class="card" style="max-width:1080px;padding:1em 1.5em;">
			<h2><?php esc_html_e( 'Bundled npm Packages', 'nvoos-saas-controller' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: relative path of THIRD_PARTY_NOTICES.md inside the addon. */
					esc_html__( 'Every JavaScript dependency the addon ships at runtime, with upstream license and copyright. Source-of-truth: %s.', 'nvoos-saas-controller' ),
					'<code>addons/saas-controller/THIRD_PARTY_NOTICES.md</code>'
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Package', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Version', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Bucket', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'License', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Copyright', 'nvoos-saas-controller' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'nvoos-saas-controller' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $packages as $pkg ) : ?>
						<tr>
							<td>
								<?php if ( ! empty( $pkg['homepage'] ) ) : ?>
									<a href="<?php echo esc_url( $pkg['homepage'] ); ?>" target="_blank" rel="noopener noreferrer">
										<code><?php echo esc_html( $pkg['name'] ); ?></code>
									</a>
								<?php else : ?>
									<code><?php echo esc_html( $pkg['name'] ); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $pkg['version'] ); ?></td>
							<td><?php echo esc_html( $pkg['bucket'] ); ?></td>
							<td><?php echo esc_html( $pkg['license'] ); ?></td>
							<td><?php echo esc_html( $pkg['copyright'] ); ?></td>
							<td><?php echo esc_html( $pkg['purpose'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1.5em;">
				<?php
				printf(
					/* translators: 1: link to root CREDITS.md, 2: link to addon notices */
					esc_html__( 'For the canonical, repo-wide attribution index, see %1$s. For per-package detail, see %2$s.', 'nvoos-saas-controller' ),
					'<code>CREDITS.md</code>',
					'<code>addons/saas-controller/THIRD_PARTY_NOTICES.md</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Definitions of every npm package this addon redistributes.
	 *
	 * Kept in code (not parsed from `package.json`) so the in-product credits
	 * surface remains accurate even when `node_modules/` is absent (production
	 * sites never ship it). The list mirrors the runtime + build sections of
	 * `addons/saas-controller/THIRD_PARTY_NOTICES.md`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array{name:string,version:string,bucket:string,license:string,copyright:string,homepage:string,purpose:string}>
	 */
	public static function get_package_definitions() {
		return array(
			array(
				'name'      => '@tanstack/react-query',
				'version'   => '^5.62.0',
				'bucket'    => 'B · Admin UI runtime',
				'license'   => 'MIT',
				'copyright' => '© Tanner Linsley & TanStack contributors',
				'homepage'  => 'https://tanstack.com/query',
				'purpose'   => 'Polling reconcile-job status, drift results, audit log.',
			),
			array(
				'name'      => 'zod',
				'version'   => '^3.24.1',
				'bucket'    => 'B · Admin UI runtime',
				'license'   => 'MIT',
				'copyright' => '© Colin McDonnell',
				'homepage'  => 'https://zod.dev/',
				'purpose'   => 'Client-side schema validation of credentials & reconcile-plan JSON.',
			),
			array(
				'name'      => 'diff',
				'version'   => '^7.0.0',
				'bucket'    => 'B · Admin UI runtime',
				'license'   => 'BSD-3-Clause',
				'copyright' => '© Kevin Decker & jsdiff contributors',
				'homepage'  => 'https://github.com/kpdecker/jsdiff',
				'purpose'   => 'Plan-preview before/after rendering.',
			),
			array(
				'name'      => 'date-fns',
				'version'   => '^4.1.0',
				'bucket'    => 'B · Admin UI runtime',
				'license'   => 'MIT',
				'copyright' => '© date-fns contributors',
				'homepage'  => 'https://date-fns.org/',
				'purpose'   => 'Audit-log timestamps and "last checked X ago" labels.',
			),
			array(
				'name'      => 'clsx',
				'version'   => '^2.1.1',
				'bucket'    => 'B · Admin UI runtime',
				'license'   => 'MIT',
				'copyright' => '© Luke Edwards',
				'homepage'  => 'https://github.com/lukeed/clsx',
				'purpose'   => 'Conditional className helper.',
			),
			array(
				'name'      => 'wrangler',
				'version'   => '^4.59.1',
				'bucket'    => 'A · Worker build-time',
				'license'   => 'MIT OR Apache-2.0',
				'copyright' => '© Cloudflare, Inc.',
				'homepage'  => 'https://github.com/cloudflare/workers-sdk',
				'purpose'   => 'Cloudflare CLI — typecheck and dry-publish the Worker. Pinned ≥ 4.59.1 for GHSA OS-command-injection.',
			),
			array(
				'name'      => 'esbuild',
				'version'   => '^0.24.2',
				'bucket'    => 'A · Worker build-time',
				'license'   => 'MIT',
				'copyright' => '© Evan Wallace',
				'homepage'  => 'https://github.com/evanw/esbuild',
				'purpose'   => 'Single-file ESM bundler for the Worker.',
			),
			array(
				'name'      => '@cloudflare/workers-types',
				'version'   => '^4.20250109.0',
				'bucket'    => 'A · Worker build-time',
				'license'   => 'Apache-2.0',
				'copyright' => '© Cloudflare, Inc.',
				'homepage'  => 'https://github.com/cloudflare/workerd',
				'purpose'   => 'TypeScript typings for D1 / KV / AI Gateway bindings.',
			),
			array(
				'name'      => 'miniflare',
				'version'   => '^4.20250109.0',
				'bucket'    => 'A · Worker build-time',
				'license'   => 'MIT',
				'copyright' => '© Cloudflare, Inc. & contributors',
				'homepage'  => 'https://github.com/cloudflare/workers-sdk/tree/main/packages/miniflare',
				'purpose'   => 'Local Worker emulator for integration tests.',
			),
			array(
				'name'      => '@wordpress/scripts',
				'version'   => '^30.0.0',
				'bucket'    => 'C · Dev tooling',
				'license'   => 'GPL-2.0-or-later',
				'copyright' => '© WordPress contributors',
				'homepage'  => 'https://github.com/WordPress/gutenberg/tree/trunk/packages/scripts',
				'purpose'   => 'Sole admin-UI build/lint/test toolchain.',
			),
			array(
				'name'      => 'typescript',
				'version'   => '^5.7.2',
				'bucket'    => 'C · Dev tooling',
				'license'   => 'Apache-2.0',
				'copyright' => '© Microsoft Corporation',
				'homepage'  => 'https://github.com/microsoft/TypeScript',
				'purpose'   => 'Type-check (`tsc --noEmit`) of the Worker source and admin UI.',
			),
			array(
				'name'      => 'npm-run-all',
				'version'   => '^4.1.5',
				'bucket'    => 'C · Dev tooling',
				'license'   => 'MIT',
				'copyright' => '© Toru Nagashima',
				'homepage'  => 'https://github.com/mysticatea/npm-run-all',
				'purpose'   => 'Run `build:worker` + `build:admin` in sequence.',
			),
		);
	}
}
