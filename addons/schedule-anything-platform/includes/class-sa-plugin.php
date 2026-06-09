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

		// Hook into new blog creation for automatic seeding.
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_blog_created' ), 10, 1 );

		// Admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_multisite_notice' ) );

		// Schedule usage heartbeat.
		add_action( 'sa_usage_heartbeat', array( 'SA_Usage_Tracker', 'send_heartbeat' ) );
		if ( ! wp_next_scheduled( 'sa_usage_heartbeat' ) ) {
			wp_schedule_event( time(), 'wp_mcp_ai_every_15_minutes', 'sa_usage_heartbeat' );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_activate() {
		// Ensure Multisite is active — this plugin requires it.
		if ( ! is_multisite() ) {
			deactivate_plugins( plugin_basename( SA_PLATFORM_FILE ) );
			wp_die(
				esc_html__(
					'Schedule Anything Platform requires WordPress Multisite to be enabled. Please enable Multisite and try again.',
					'schedule-anything'
				)
			);
		}

		// Register tenant admin role.
		self::register_tenant_admin_role();

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
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_tenant_admin_role() {
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
	 * Render admin notice if Multisite is not enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_render_multisite_notice() {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Schedule Anything Platform:', 'schedule-anything' ),
			esc_html__(
				'This plugin requires WordPress Multisite to function. Please enable Multisite in wp-config.php.',
				'schedule-anything'
			)
		);
	}
}
