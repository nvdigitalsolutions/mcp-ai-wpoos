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
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'overview', 'deployment', 'packages' );
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
						href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab<?php echo $active === $slug ? ' nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $active ) {
				case 'deployment':
					self::render_deployment_tab();
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
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
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
				<li>⏳ <strong><?php esc_html_e( 'Phase 5 — Apply, Drift, Audit Log, Smoke Tests', 'nvoos-saas-controller' ); ?></strong>: <?php esc_html_e( 'execute the plan behind a HITL approval gate; periodic reconciliation and observability.', 'nvoos-saas-controller' ); ?></li>
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
			$rows[] = array( $key_a => '', $key_b => '' );
		}
		// Add an extra blank row at the end so adding entries doesn't require JS.
		$rows[] = array( $key_a => '', $key_b => '' );
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
