<?php
/**
 * NV Platform Dashboard Controller.
 *
 * Creates the top-level "NV Platform" admin menu with a tabbed
 * settings interface. Mirrors the base plugin's
 * WP_MCP_AI_Settings_Dashboard pattern but uses the Platform's
 * own section registry and settings storage.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin;

use NvoosContentGraphAiPlatform\Schema\Defaults;

/**
 * Composition root for the NV Platform admin surface.
 *
 * Registers the top-level menu, settings group, assets, AJAX
 * handlers, and the tabbed dashboard renderer.
 */
final class PlatformDashboard {

	/**
	 * Admin page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-platform-dashboard';

	/**
	 * Settings option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_NAME = 'ai_platform_settings';

	/**
	 * Settings group name (for settings_fields() / register_setting()).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SETTINGS_GROUP = 'ai_platform_settings_group';

	/**
	 * Nonce action for settings save.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const NONCE_ACTION = 'ai_platform_save_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * Called from {@see \NvoosContentGraphAiPlatform\Plugin::registerAdmin()}.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_menu', array( $this, 'reorderMenu' ), 999 );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_post_' . self::NONCE_ACTION, array( $this, 'handleSaveSettings' ) );

		// Fires after all sections are registered so the dashboard can discover them.
		add_action( 'admin_init', array( $this, 'registerPlatformSections' ), 5 );
	}

	/**
	 * Register sections and tabs with the Platform registry.
	 *
	 * Subsystem sections hook into the `nvoos_content_graph_ai_platform_admin_register_sections`
	 * action to self-register. This method fires that action after all
	 * plugins are loaded but before the dashboard renders.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerPlatformSections(): void {
		/**
		 * Fires when the NV Platform dashboard is ready for section registration.
		 *
		 * Subsystem admin classes hook into this to register their
		 * PlatformSection instances via PlatformSettingsRegistry.
		 *
		 * @since 1.0.0
		 */
		do_action( 'nvoos_content_graph_ai_platform_admin_register_sections' );
	}

	// ─────────────────────────────────────────────────────────
	// Menu Registration
	// ─────────────────────────────────────────────────────────

	/**
	 * Register the top-level "NV Platform" menu and submenu items.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerMenu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. Top-level menu — appears as "NV Platform" in the admin sidebar.
		add_menu_page(
			__( 'NV Platform Settings', 'nvoos-content-graph-ai-platform' ),
			__( 'NV Platform', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderDashboard' ),
			'dashicons-layout',
			null // Let WordPress auto-position (below NV Content Graph at 85).
		);

		// 2. Remove the auto-generated duplicate submenu (same slug as parent).
		remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );

		// 3. Add "Platform Settings" as the first child submenu.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Platform Settings', 'nvoos-content-graph-ai-platform' ),
			__( 'Platform Settings', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderDashboard' )
		);
	}

	/**
	 * Reorder submenu items so Platform Settings appears first,
	 * followed by CPTs and any other registered pages.
	 *
	 * Runs at priority 999 to ensure all submenus are registered first.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reorderMenu(): void {
		global $submenu;

		if ( ! isset( $submenu[ self::PAGE_SLUG ] ) || ! is_array( $submenu[ self::PAGE_SLUG ] ) ) {
			return;
		}

		$platform_submenu = $submenu[ self::PAGE_SLUG ];

		$settings_item = null;
		$other_items   = array();

		foreach ( $platform_submenu as $item ) {
			if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
				$settings_item = $item;
			} else {
				$other_items[] = $item;
			}
		}

		$ordered = array();
		if ( $settings_item ) {
			$ordered[0] = $settings_item;
		}

		$pos = 10;
		foreach ( $other_items as $item ) {
			$ordered[ $pos ] = $item;
			++$pos;
		}

		ksort( $ordered );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to reorder admin menu items.
		$submenu[ self::PAGE_SLUG ] = $ordered;
	}

	// ─────────────────────────────────────────────────────────
	// Settings Registration
	// ─────────────────────────────────────────────────────────

	/**
	 * Register the settings group and option with WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerSettings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeSettings' ),
				'default'           => Defaults::platformSettings(),
			)
		);
	}

	/**
	 * Sanitize the full settings array.
	 *
	 * Runs a general-purpose pass: ensures the value is an array,
	 * merges with defaults, and delegates per-field sanitization
	 * to registered PlatformSection instances.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Incoming value.
	 * @return array<string,mixed> Sanitized settings.
	 */
	public function sanitizeSettings( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start with defaults so no key goes missing.
		$sanitized = Defaults::platformSettings();

		// Merge raw input over defaults (section-based sanitization runs
		// in handleSaveSettings with full $_POST context).
		foreach ( $input as $key => $value ) {
			if ( array_key_exists( $key, $sanitized ) ) {
				$sanitized[ $key ] = $this->sanitizeByType( $key, $value, $sanitized[ $key ] );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single value based on its default type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $default_value Default value (used to infer type).
	 * @return mixed Sanitized value.
	 */
	private function sanitizeByType( string $key, $value, $default_value ) {
		if ( is_bool( $default_value ) ) {
			return (bool) $value;
		}
		if ( is_int( $default_value ) ) {
			return absint( $value );
		}
		if ( is_float( $default_value ) ) {
			return (float) $value;
		}
		if ( is_array( $default_value ) ) {
			return is_array( $value ) ? array_values( $value ) : array();
		}
		return sanitize_text_field( (string) $value );
	}

	// ─────────────────────────────────────────────────────────
	// Settings Save Handler
	// ─────────────────────────────────────────────────────────

	/**
	 * Handle settings form submission.
	 *
	 * Merges section-sanitized values with existing settings
	 * and redirects back to the dashboard with the active tab.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handleSaveSettings(): void {
		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage platform settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Load existing settings.
		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$active_tab = isset( $_POST['active_tab'] )
			? sanitize_key( wp_unslash( $_POST['active_tab'] ) )
			: 'general';

		// Collect sanitized values from the raw POST.
		// Each section is responsible for picking out its own keys.
		$raw_input = isset( $_POST[ self::OPTION_NAME ] ) && is_array( $_POST[ self::OPTION_NAME ] )
			? wp_unslash( $_POST[ self::OPTION_NAME ] )
			: array();

		$new_values = array();
		foreach ( $raw_input as $key => $value ) {
			$defaults           = Defaults::platformSettings();
			$default            = $defaults[ $key ] ?? '';
			$new_values[ $key ] = $this->sanitizeByType( $key, $value, $default );
		}

		// Merge with existing (partial save — only changed keys are sent).
		$merged = array_merge( $existing, $new_values );

		update_option( self::OPTION_NAME, $merged );

		/**
		 * Fires after platform settings are saved.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $merged     The full merged settings.
		 * @param array<string,mixed> $new_values Only the changed keys.
		 */
		do_action( 'nvoos_content_graph_ai_platform_after_settings_saved', $merged, $new_values );

		// Redirect with success message.
		$redirect_args = array(
			'page'    => self::PAGE_SLUG,
			'updated' => 'true',
		);

		if ( 'general' !== $active_tab ) {
			$redirect_args['tab'] = $active_tab;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ─────────────────────────────────────────────────────────
	// Dashboard Rendering
	// ─────────────────────────────────────────────────────────

	/**
	 * Render the tabbed dashboard page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function renderDashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switching is a read-only GET parameter.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) && 'true' === $_GET['updated'];

		$tabs = PlatformSettingsRegistry::get_tabs();

		// Default to overview if the requested tab doesn't exist.
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'overview';
			// If overview doesn't exist either, use the first registered tab.
			if ( ! isset( $tabs[ $active_tab ] ) && ! empty( $tabs ) ) {
				$first      = array_key_first( $tabs );
				$active_tab = is_string( $first ) ? $first : 'overview';
			}
		}

		?>
		<div class="wrap ai-platform-dashboard">
			<h1><?php esc_html_e( 'NV Platform', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => self::PAGE_SLUG,
								'tab'  => $tab_id,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								"
						class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content" style="margin-top: 1.5rem;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>">
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<?php settings_fields( self::SETTINGS_GROUP ); ?>

					<?php
					$sections = PlatformSettingsRegistry::get_sections( $active_tab );

					if ( empty( $sections ) ) :
						?>
						<div class="ai-platform-empty-tab" style="background:#fff;border:1px solid #c3c4c7;padding:2rem;text-align:center;">
							<h2><?php esc_html_e( 'No content yet', 'nvoos-content-graph-ai-platform' ); ?></h2>
							<p><?php esc_html_e( 'This tab has no registered sections. Content will appear as subsystems are activated.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</div>
						<?php
					else :
						foreach ( $sections as $section ) {
							$section->render_wrapper( self::PAGE_SLUG );
						}
						?>

						<p class="submit">
							<?php submit_button( __( 'Save Settings', 'nvoos-content-graph-ai-platform' ), 'primary', 'submit', false ); ?>
						</p>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────
	// Asset Enqueue
	// ─────────────────────────────────────────────────────────

	/**
	 * Enqueue admin styles and scripts for the dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook ): void {
		$is_dashboard = ( false !== strpos( $hook, self::PAGE_SLUG ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ( isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) ) );

		if ( ! $is_dashboard ) {
			return;
		}

		// Minimal inline styles for the dashboard layout.
		// Heavy styles come from subsystem-specific CSS files enqueued
		// by individual admin pages (AddAgentPage, BuildAgentPage, etc.).
		wp_add_inline_style(
			'wp-admin',
			'
			.ai-platform-dashboard .nav-tab-wrapper { margin-bottom: 1rem; }
			.ai-platform-dashboard .tab-content { max-width: 1200px; }
			.ai-platform-dashboard .form-table th { width: 220px; }
			.ai-platform-empty-tab h2 { margin-top: 0; color: #646970; }
			'
		);
	}
}
