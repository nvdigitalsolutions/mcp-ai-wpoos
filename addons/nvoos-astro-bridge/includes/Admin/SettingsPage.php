<?php
/**
 * Bridge settings page — the wp-admin operational surface for the Astro
 * Bridge (Proposal 111: the backend owns its own configuration).
 *
 * Research-backed split of concerns: the WordPress admin hosts the BACKEND
 * operational settings (product source mode, Woo SSO switch, system status)
 * through the Settings API — nonce + capability handled by core, values in
 * the options table (the WordPress standard; environment variables are not
 * a supported plugin-configuration surface). The storefront-facing globals
 * (checkout/cart/return URLs, currency) stay in the Astro CMS SPA's
 * Settings view — they configure the STOREFRONT experience, not the bridge.
 *
 * The product source mode gates the embedded checkout: `native` serves the
 * built-in funiq_product CPTs and disables the server-side cart/checkout
 * proxy (fail-closed); `woo`/`both` serve WooCommerce products and enable
 * the embedded checkout. The `funiq_bridge_products_source` filter remains
 * the programmatic override (tests, per-request switches) — the stored
 * option is the default.
 *
 * @package NvoosAstroBridge\Admin
 */

namespace NvoosAstroBridge\Admin;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Woo\NativeCatalogMigrator;

/**
 * Registers and renders the bridge settings page.
 */
class SettingsPage {

	/**
	 * Settings API option group.
	 */
	private const OPTION_GROUP = 'nvoos_astro_bridge_settings';

	/**
	 * Page slug.
	 */
	private const PAGE_SLUG = 'astro-bridge-settings';

	/**
	 * Bootstrap — hooks only in the admin.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_page' ), 11 );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NVOOS_ASTRO_BRIDGE_FILE ), array( $instance, 'plugin_row_links' ) );

		// Proposal 112 — the catalog-migration action endpoints.
		add_action( 'admin_post_nvoos_bridge_migrate_dry_run', array( $instance, 'handle_migrate_dry_run' ) );
		add_action( 'admin_post_nvoos_bridge_migrate_run', array( $instance, 'handle_migrate_run' ) );
	}

	/**
	 * Register the submenu page under the Astro CMS menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			Schema::ADMIN_PAGE_SLUG,
			'Astro Bridge Settings',
			'Settings',
			Schema::CAP_MANAGE,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the settings, sanitizers and the option-page capability.
	 *
	 * The capability filter pins saving to `manage_astro` (the bridge's own
	 * cap — admins AND the editors the activation grants it to), instead of
	 * the Settings API default `manage_options` (admins only). The nonce is
	 * handled by the Settings API itself.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Schema::OPTION_PRODUCT_SOURCE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_products_source' ),
				'default'           => Schema::SOURCE_NATIVE,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Schema::OPTION_WOO_SSO_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_switch' ),
				'default'           => 'no',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Schema::OPTION_PUBLIC_ORIGIN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_public_origin' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Schema::OPTION_MEDIA_ORIGIN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_media_origin' ),
				'default'           => Schema::MEDIA_ORIGIN_CMS,
			)
		);

		add_filter(
			'option_page_capability_' . self::OPTION_GROUP,
			static function (): string {
				return Schema::CAP_MANAGE;
			}
		);

		add_settings_section(
			'nvoos_astro_bridge_integration',
			'Storefront integration',
			array( $this, 'render_integration_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'products_source',
			'Product source',
			array( $this, 'render_products_source_field' ),
			self::PAGE_SLUG,
			'nvoos_astro_bridge_integration'
		);

		add_settings_field(
			'woo_sso',
			'WooCommerce SSO handoff',
			array( $this, 'render_woo_sso_field' ),
			self::PAGE_SLUG,
			'nvoos_astro_bridge_integration'
		);

		add_settings_section(
			'nvoos_astro_bridge_domains',
			'Domains',
			array( $this, 'render_domains_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'public_origin',
			'Public site origin',
			array( $this, 'render_public_origin_field' ),
			self::PAGE_SLUG,
			'nvoos_astro_bridge_domains'
		);

		add_settings_field(
			'media_origin',
			'Media URLs',
			array( $this, 'render_media_origin_field' ),
			self::PAGE_SLUG,
			'nvoos_astro_bridge_domains'
		);
	}

	/**
	 * The "Settings" link on the plugin row.
	 *
	 * @param array<int, string> $links Existing row links.
	 * @return array<int, string>
	 */
	public function plugin_row_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'nvoos-astro-bridge' )
			)
		);
		return $links;
	}

	/**
	 * Sanitize the product source mode — unknown values never persist.
	 *
	 * @param mixed $value Submitted value.
	 * @return string The valid mode, or the previously stored one.
	 */
	public function sanitize_products_source( $value ): string {
		$valid = array( Schema::SOURCE_NATIVE, 'woo', 'both' );
		if ( is_string( $value ) && in_array( $value, $valid, true ) ) {
			return $value;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'invalid_products_source',
			'Product source must be native, woo, or both.'
		);

		$stored = get_option( Schema::OPTION_PRODUCT_SOURCE, Schema::SOURCE_NATIVE );
		return is_string( $stored ) && in_array( $stored, $valid, true ) ? $stored : Schema::SOURCE_NATIVE;
	}

	/**
	 * Sanitize an on/off switch stored as yes/no.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_switch( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Sanitize the public site origin (Proposal 135).
	 *
	 * Empty means "same origin as WordPress" (the historical behaviour).
	 * Anything that is not an absolute http(s) origin never persists — the
	 * previously stored value is kept and a settings error is queued.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Normalized origin or ''.
	 */
	public function sanitize_public_origin( $value ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}

		$normalized = Schema::normalize_origin( $value );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'invalid_public_origin',
			'Public site origin must be an absolute http(s) origin, e.g. https://client.com.'
		);

		$stored = get_option( Schema::OPTION_PUBLIC_ORIGIN, '' );
		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Sanitize the media-origin mode (Proposal 135 / A6).
	 *
	 * @param mixed $value Submitted value.
	 * @return string `cms` or `frontend`.
	 */
	public function sanitize_media_origin( $value ): string {
		return Schema::MEDIA_ORIGIN_FRONTEND === $value ? Schema::MEDIA_ORIGIN_FRONTEND : Schema::MEDIA_ORIGIN_CMS;
	}

	/**
	 * Domains section copy — the CMS-vs-public split (Proposal 135).
	 *
	 * @return void
	 */
	public function render_domains_section(): void {
		echo '<p>';
		esc_html_e( 'Set the public origin when the Astro frontend owns the visitor-facing domain and WordPress lives on a CMS subdomain (for example WordPress on cms.example.com, the site on example.com). Shopper-facing defaults and the checkout allowlist use it instead of the WordPress URL.', 'nvoos-astro-bridge' );
		echo '</p>';
	}

	/**
	 * The public-origin input.
	 *
	 * @return void
	 */
	public function render_public_origin_field(): void {
		$current = get_option( Schema::OPTION_PUBLIC_ORIGIN, '' );
		$current = is_string( $current ) ? $current : '';

		printf(
			'<input type="url" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="%3$s" inputmode="url" />',
			esc_attr( Schema::OPTION_PUBLIC_ORIGIN ),
			esc_attr( $current ),
			esc_attr( untrailingslashit( home_url() ) )
		);

		echo '<p class="description">';
		esc_html_e( 'Leave empty to use the WordPress site URL. Changing this does not rewrite an already-stored shop doc — update the checkout/cart/return URLs in the Astro CMS Shop settings.', 'nvoos-astro-bridge' );
		echo '</p>';
	}

	/**
	 * The media-origin select.
	 *
	 * @return void
	 */
	public function render_media_origin_field(): void {
		$current = get_option( Schema::OPTION_MEDIA_ORIGIN, Schema::MEDIA_ORIGIN_CMS );
		if ( ! is_string( $current ) ) {
			$current = Schema::MEDIA_ORIGIN_CMS;
		}

		$options = array(
			Schema::MEDIA_ORIGIN_CMS      => 'Absolute URLs on this WordPress host (static sites, default)',
			Schema::MEDIA_ORIGIN_FRONTEND => 'Same-origin /media/ paths (managed sites with the media proxy)',
		);

		echo '<select name="' . esc_attr( Schema::OPTION_MEDIA_ORIGIN ) . '" id="' . esc_attr( Schema::OPTION_MEDIA_ORIGIN ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<p class="description">';
		esc_html_e( 'Only choose /media/ paths when the frontend serves the media proxy (a managed forged site). A static site cannot serve /media/* — its images would 404.', 'nvoos-astro-bridge' );
		echo '</p>';
	}

	/**
	 * Integration section copy.
	 *
	 * @return void
	 */
	public function render_integration_section(): void {
		echo '<p>';
		esc_html_e( 'How the bridge sources catalog products and which checkout path the storefront uses. The embedded checkout (Proposal 110) requires the woo or both mode with WooCommerce active — native mode keeps the hosted checkout URL handoff.', 'nvoos-astro-bridge' );
		echo '</p>';
	}

	/**
	 * The product source select.
	 *
	 * @return void
	 */
	public function render_products_source_field(): void {
		$current = get_option( Schema::OPTION_PRODUCT_SOURCE, Schema::SOURCE_NATIVE );
		if ( ! is_string( $current ) ) {
			$current = Schema::SOURCE_NATIVE;
		}

		$options = array(
			Schema::SOURCE_NATIVE => 'Native (built-in products) — hosted checkout only',
			'woo'                 => 'WooCommerce — embedded checkout enabled',
			'both'                => 'Both (merged catalog) — embedded checkout enabled',
		);

		echo '<select name="' . esc_attr( Schema::OPTION_PRODUCT_SOURCE ) . '" id="' . esc_attr( Schema::OPTION_PRODUCT_SOURCE ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		if ( ! $this->woo_active() ) {
			echo '<p class="description">';
			esc_html_e( 'WooCommerce is not active — the woo/both modes will degrade to native until it is.', 'nvoos-astro-bridge' );
			echo '</p>';
		}
	}

	/**
	 * The Woo SSO checkbox.
	 *
	 * @return void
	 */
	public function render_woo_sso_field(): void {
		$current = get_option( Schema::OPTION_WOO_SSO_ENABLED, 'no' );
		printf(
			'<label><input type="checkbox" name="%1$s" value="yes"%2$s> %3$s</label>',
			esc_attr( Schema::OPTION_WOO_SSO_ENABLED ),
			checked( 'yes' === $current, true, false ),
			esc_html__( 'Log shoppers into the hosted Woo checkout without a second sign-in (single-use signed token).', 'nvoos-astro-bridge' )
		);
	}

	/**
	 * Render the page — the settings form + a read-only system status panel.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nvoos-astro-bridge' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Astro Bridge Settings', 'nvoos-astro-bridge' ) . '</h1>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'System status', 'nvoos-astro-bridge' ) . '</h2>';
		$this->render_status_panel();

		echo '<h2>' . esc_html__( 'Catalog migration (native → Woo)', 'nvoos-astro-bridge' ) . '</h2>';
		$this->render_migration_panel();

		echo '<p class="description">';
		esc_html_e( 'Storefront-facing globals (checkout/cart/return URLs, display currency) are configured in the Astro CMS Settings view — they shape the storefront, not this backend.', 'nvoos-astro-bridge' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Read-only diagnostics — one glance answers "why is the embedded
	 * checkout disabled?".
	 *
	 * @return void
	 */
	private function render_status_panel(): void {
		$stored = get_option( Schema::OPTION_PRODUCT_SOURCE, Schema::SOURCE_NATIVE );
		if ( ! is_string( $stored ) ) {
			$stored = Schema::SOURCE_NATIVE;
		}

		/**
		 * The runtime read the rest of the plugin takes — a filter override
		 * is the supported programmatic seam and must be visible here.
		 *
		 * @param string $source Current source mode.
		 */
		$effective = apply_filters( 'funiq_bridge_products_source', $stored );

		$rows = array(
			'WooCommerce active'      => $this->woo_active() ? 'Yes' : 'No',
			'Product source (stored)' => $stored,
			'Product source (live)'   => is_string( $effective ) && in_array( $effective, array( Schema::SOURCE_NATIVE, 'woo', 'both' ), true ) ? $effective : $stored,
			'Embedded checkout'       => ( $this->woo_active() && 'native' !== $effective ) ? 'Enabled' : 'Disabled (native mode or Woo missing)',
			'Guest checkout'          => ( 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ) ) ? 'Allowed' : 'Requires account',
			'Public site origin'      => Schema::public_origin(),
			'WordPress origin'        => untrailingslashit( home_url() ),
			'Media URLs'              => Schema::MEDIA_ORIGIN_FRONTEND === get_option( Schema::OPTION_MEDIA_ORIGIN, Schema::MEDIA_ORIGIN_CMS ) ? 'same-origin /media/' : 'absolute (CMS host)',
			'Store API base'          => home_url( '/wp-json/wc/store/v1/' ),
		);

		if ( $this->woo_active() ) {
			$migrator                        = new NativeCatalogMigrator();
			$rows['Native products']         = (string) $migrator->count_native();
			$rows['Migrated (Woo) products'] = (string) $migrator->count_migrated();
			$rows['Migration status']        = (string) $migrator->state()['status'];
		}

		echo '<table class="widefat striped" role="presentation"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Whether WooCommerce is functional in this environment.
	 *
	 * @return bool
	 */
	private function woo_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	// -----------------------------------------------------------------------
	// Proposal 112 — catalog migration controls.
	// -----------------------------------------------------------------------

	/**
	 * Render the migration panel: counts, the dry-run button, the run
	 * button (queues the Action Scheduler sweep) and the latest report.
	 *
	 * @return void
	 */
	private function render_migration_panel(): void {
		if ( ! $this->woo_active() ) {
			echo '<p>';
			esc_html_e( 'WooCommerce is not active — the migration requires it.', 'nvoos-astro-bridge' );
			echo '</p>';
			return;
		}

		$migrator = new NativeCatalogMigrator();
		$state    = $migrator->state();

		echo '<p>';
		esc_html_e( 'Migrate the built-in (native) products into WooCommerce so the storefront has a single purchasable catalog. The dry run reports without writing; the run queues a background sweep. Re-running is safe — existing migrations are updated, never duplicated.', 'nvoos-astro-bridge' );
		echo '</p>';

		echo '<table class="widefat striped" role="presentation"><tbody>';
		$rows = array(
			'Native products'                      => (string) $migrator->count_native(),
			'Migrated products'                    => (string) $migrator->count_migrated(),
			'Last sweep status'                    => (string) $state['status'],
			'Scanned / created / updated / failed' => sprintf( '%d / %d / %d / %d', $state['scanned'], $state['created'], $state['updated'], $state['failed'] ),
		);
		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody></table>';

		echo '<p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="nvoos_bridge_migrate_dry_run">';
		wp_nonce_field( 'nvoos_bridge_migrate', 'nvoos_bridge_migrate_nonce' );
		submit_button( __( 'Dry run (no writes)', 'nvoos-astro-bridge' ), 'secondary', 'nvoos_bridge_migrate_dry_run_submit', false );
		echo '</form> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="nvoos_bridge_migrate_run">';
		wp_nonce_field( 'nvoos_bridge_migrate', 'nvoos_bridge_migrate_nonce' );
		submit_button( __( 'Run migration (background)', 'nvoos-astro-bridge' ), 'primary', 'nvoos_bridge_migrate_run_submit', false );
		echo '</form>';
		echo '</p>';

		$report = get_transient( 'nvoos_bridge_migration_report' );
		if ( is_array( $report ) && ! empty( $report ) ) {
			echo '<h3>' . esc_html__( 'Latest dry-run report', 'nvoos-astro-bridge' ) . '</h3>';
			echo '<table class="widefat striped" role="presentation"><tbody>';
			$report_rows = array(
				'Scanned of total' => sprintf( '%d of %d', (int) $report['scanned'], (int) $report['total'] ),
				'Would create'     => (string) $report['create'],
				'Would update'     => (string) $report['update'],
				'Failed'           => (string) $report['failed'],
				'Terms to create'  => (string) $report['terms_created'],
			);
			foreach ( $report_rows as $label => $value ) {
				printf(
					'<tr><th scope="row">%s</th><td>%s</td></tr>',
					esc_html( $label ),
					esc_html( $value )
				);
			}
			echo '</tbody></table>';

			if ( ! empty( $report['issues'] ) ) {
				echo '<ul>';
				foreach ( array_slice( $report['issues'], 0, 20 ) as $issue ) {
					printf(
						'<li>#%s — %s</li>',
						esc_html( (string) ( $issue['nativeId'] ?? '-' ) ),
						esc_html( (string) ( $issue['message'] ?? '-' ) )
					);
				}
				echo '</ul>';
			}
		}
	}

	/**
	 * Admin-post handler — run the dry-run inline and show the report.
	 *
	 * @return void
	 */
	public function handle_migrate_dry_run(): void {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nvoos-astro-bridge' ) );
		}
		check_admin_referer( 'nvoos_bridge_migrate', 'nvoos_bridge_migrate_nonce' );

		$report = ( new NativeCatalogMigrator() )->dry_run();
		set_transient( 'nvoos_bridge_migration_report', $report, HOUR_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&nvoos-migrated=dry-run' ) );
		exit;
	}

	/**
	 * Admin-post handler — queue the Action Scheduler sweep.
	 *
	 * @return void
	 */
	public function handle_migrate_run(): void {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nvoos-astro-bridge' ) );
		}
		check_admin_referer( 'nvoos_bridge_migrate', 'nvoos_bridge_migrate_nonce' );

		$queued = ( new NativeCatalogMigrator() )->enqueue_sweep();

		$notice = $queued ? 'nvoos-migrated=started' : 'nvoos-migrated=unavailable';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&' . $notice ) );
		exit;
	}
}
