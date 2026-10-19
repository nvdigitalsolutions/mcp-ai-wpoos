<?php
/**
 * Schedule Anything Platform — Core Plugin Class
 *
 * Core singleton that registers hooks, admin pages, and coordinates
 * all platform subsystems.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the Schedule Anything Platform addon.
 *
 * @since 0.1.0
 */
class SA_Plugin {

	/**
	 * Option key for platform-level settings (network-wide in Multisite).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sa_platform_settings';

	/**
	 * Option key for the one-time single-site mode notice dismissal.
	 *
	 * @var string
	 */
	const SINGLE_SITE_NOTICE_OPTION = 'sa_single_site_notice_dismissed';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		// Register activation/deactivation hooks.
		register_activation_hook( SA_PLATFORM_FILE, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( SA_PLATFORM_FILE, array( __CLASS__, 'on_deactivate' ) );

		// Seed the tenant admin role on init.
		add_action( 'init', array( __CLASS__, 'register_tenant_admin_role' ), 5 );

		// Single-site upgrade path: converge pre-existing activations onto
		// sole-tenant defaults without a reactivation (Proposal 082).
		add_action( 'init', array( __CLASS__, 'seed_single_site_defaults' ), 6 );

		// Hook into new blog creation for automatic seeding.
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_blog_created' ), 10, 1 );

		// Admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_single_site_notice' ) );

		// Dismissal handler for the single-site notice.
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_single_site_notice' ) );

		// Capability seam: single-site admins manage the workspace through
		// manage_options (Proposal 082, D5).
		add_filter( 'wp_mcp_ai_pro_schedule_rest_capability', array( __CLASS__, 'filter_schedule_rest_capability' ) );

		// Schedule usage heartbeat.
		add_action( 'sa_usage_heartbeat', array( 'SA_Usage_Tracker', 'send_heartbeat' ) );
		if ( ! wp_next_scheduled( 'sa_usage_heartbeat' ) ) {
			wp_schedule_event( time(), 'wp_mcp_ai_every_15_minutes', 'sa_usage_heartbeat' );
		}
	}

	/**
	 * Whether the platform is running on a single-site install.
	 *
	 * On single-site the platform runs in sole-tenant mode (Proposal 082):
	 * the single blog is the tenant.
	 *
	 * @since 0.1.1
	 *
	 * @return bool True when WordPress Multisite is not active.
	 */
	public static function is_single_site_mode() {
		return ! is_multisite();
	}

	/**
	 * The tier seeded on a single-site install.
	 *
	 * @since 0.1.1
	 *
	 * @return string 'starter', 'professional', or 'enterprise'.
	 */
	public static function single_site_tier() {
		$valid_tiers = array( 'starter', 'professional', 'enterprise' );

		/**
		 * Filter the subscription tier seeded in single-site (sole tenant)
		 * mode.
		 *
		 * @since 0.1.1
		 *
		 * @param string $tier Default 'enterprise' so demos show the full product.
		 */
		$tier = apply_filters( 'sa_single_site_tier', 'enterprise' );

		return in_array( $tier, $valid_tiers, true ) ? $tier : 'enterprise';
	}

	/**
	 * Seed single-site defaults (tenant meta + toolkit flags).
	 *
	 * Idempotent: never overwrites existing tenant meta or non-empty
	 * wp_mcp_ai_settings. Runs on activation and on init for the upgrade
	 * path (Proposal 082, section 8).
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function seed_single_site_defaults() {
		if ( is_multisite() ) {
			return;
		}

		$tier = self::single_site_tier();

		$existing_meta = get_option( 'sa_tenant_meta', array() );
		if ( empty( $existing_meta ) ) {
			update_option(
				'sa_tenant_meta',
				array(
					'tier'                => $tier,
					'stripe_customer_id'  => '',
					'created_at'          => time(),
					'provisioned_version' => SA_PLATFORM_VERSION,
					'single_site'         => true,
				)
			);
		}

		if ( class_exists( 'SA_Toolkit_Manager' ) ) {
			SA_Toolkit_Manager::ensure_defaults( $tier );
		}
	}

	/**
	 * Filter the Schedule Anything REST capability seam.
	 *
	 * The Pro addon defaults to sa_manage_workspace when the platform is
	 * active, a capability site admins do not hold. On single-site the
	 * seam resolves to manage_options so the SPA surface is usable
	 * (Proposal 082, D5).
	 *
	 * @since 0.1.1
	 *
	 * @param string $capability The capability resolved by the Pro addon.
	 * @return string
	 */
	public static function filter_schedule_rest_capability( $capability ) {
		return is_multisite() ? $capability : 'manage_options';
	}

	/**
	 * Activation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_activate() {
		// Register tenant admin role.
		self::register_tenant_admin_role();

		// Single-site installs run in sole-tenant mode (Proposal 082) --
		// seed defaults instead of refusing to activate.
		self::seed_single_site_defaults();

		// Flush rewrite rules for REST.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		// Clear scheduled heartbeat.
		wp_clear_scheduled_hook( 'sa_usage_heartbeat' );

		// Do NOT delete tenant data on deactivation — only on uninstall.
	}

	/**
	 * Register the Tenant Admin role with scoped capabilities.
	 *
	 * Tenants get sa_manage_workspace instead of manage_options,
	 * preventing them from accessing platform-level settings.
	 *
	 * Multisite only: on single-site the workspace is managed by site
	 * admins through manage_options (Proposal 082, D5).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_tenant_admin_role() {
		if ( ! is_multisite() ) {
			return;
		}

		$capabilities = array(
			// Read access.
			'read'                   => true,
			'read_private_posts'     => true,
			'read_private_pages'     => true,

			// Workspace management (custom capability).
			'sa_manage_workspace'    => true,

			// Content management.
			'edit_posts'             => true,
			'edit_private_posts'     => true,
			'edit_published_posts'   => true,
			'delete_posts'           => true,
			'delete_private_posts'   => true,
			'delete_published_posts' => true,
			'publish_posts'          => true,
			'upload_files'           => true,

			// Pages.
			'edit_pages'             => true,
			'edit_private_pages'     => true,
			'edit_published_pages'   => true,
			'delete_pages'           => true,
			'publish_pages'          => true,

			// Explicitly NOT granted:
			// 'manage_options' — prevents access to platform-level settings
			// 'create_users' — managed by platform, not tenant admins
			// 'delete_users' — managed by platform
			// 'edit_theme_options' — prevents theme modification
			// 'install_plugins' — prevents plugin installation
			// 'activate_plugins' — prevents plugin activation.
		);

		// Only add if not already registered.
		if ( ! get_role( 'sa_tenant_admin' ) ) {
			add_role(
				'sa_tenant_admin',
				__( 'Tenant Admin', 'schedule-anything' ),
				$capabilities
			);
		}

		// Site administrators on tenant blogs manage their own workspace
		// (sa_manage_workspace gates ONLY the schedule/preset/toolkit REST
		// seam - it carries no platform-level power). Grant it idempotently.
		self::grant_workspace_capability_to_admins();
	}

	/**
	 * Grant sa_manage_workspace to the administrator role.
	 *
	 * Capability grants are treated as version-gated migrations (see
	 * https://wppoland.com/en/wordpress-roles-and-capabilities-guide/):
	 * add only when missing, never remove, and re-run harmlessly on every
	 * init. On multisite the role is per-blog, so this grants on the tenant
	 * blog only - network super admins are unaffected.
	 *
	 * @since 0.1.2
	 *
	 * @return void
	 */
	public static function grant_workspace_capability_to_admins() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( 'sa_manage_workspace' ) ) {
			$role->add_cap( 'sa_manage_workspace' );
		}
	}

	/**
	 * Hook: when a new Multisite blog is created, seed tenant defaults.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Site $new_site The newly created site object.
	 * @return void
	 */
	public static function on_new_blog_created( $new_site ) {
		// This is a fallback for manually-created blogs.
		// Normally, SA_Multisite_Provisioner handles seeding during signup.
		// If the blog was created via wpmu_create_blog() outside the provisioner,
		// apply minimal defaults.

		switch_to_blog( $new_site->blog_id );

		// Ensure default toolkit flags exist.
		if ( class_exists( 'SA_Toolkit_Manager' ) ) {
			SA_Toolkit_Manager::ensure_defaults();
		}

		restore_current_blog();
	}

	/**
	 * Whether the single-site mode notice should be shown.
	 *
	 * @since 0.1.1
	 *
	 * @return bool True when the notice is due.
	 */
	public static function should_show_single_site_notice() {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return ! get_option( self::SINGLE_SITE_NOTICE_OPTION );
	}

	/**
	 * Render the sole-tenant mode notice on single-site installs.
	 *
	 * Replaces the previous multisite-required error notice (Proposal 082,
	 * section 4.2).
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function maybe_render_single_site_notice() {
		if ( ! self::should_show_single_site_notice() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'sa_dismiss_single_site_notice', '1', admin_url( 'index.php' ) ),
			'sa_dismiss_single_site_notice'
		);

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Schedule Anything Platform:', 'schedule-anything' ),
			esc_html__( 'Running in single-site mode - this site is the sole tenant. Tenant provisioning is limited to this workspace.', 'schedule-anything' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'schedule-anything' )
		);
	}

	/**
	 * Handle the single-site notice dismissal link.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function maybe_dismiss_single_site_notice() {
		if ( ! isset( $_GET['sa_dismiss_single_site_notice'] ) ) {
			return;
		}

		check_admin_referer( 'sa_dismiss_single_site_notice' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( self::SINGLE_SITE_NOTICE_OPTION, 1 );
	}
}
