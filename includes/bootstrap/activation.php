<?php
/**
 * Plugin Activation, Deactivation and Uninstall Handlers
 *
 * All lifecycle callbacks for the plugin are defined here.
 * The register_activation_hook / register_deactivation_hook / register_uninstall_hook
 * calls remain in the main plugin file (mcp-ai-wpoos.php) because they must reference
 * WP_MCP_AI_FILE, which is defined there.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_iterate_network_sites' ) ) {
	/**
	 * Helper function to iterate through all sites in a multisite network.
	 *
	 * @param callable $callback Callback function to execute for each site.
	 * @param string   $action   Action name for error logging (e.g., 'activation', 'deactivation').
	 * @return void
	 */
	function wp_mcp_ai_iterate_network_sites( $callback, $action = 'operation' ) {
		if ( ! is_multisite() || ! is_callable( $callback ) ) {
			return;
		}

		/**
		 * Filters the arguments for get_sites() when iterating network sites.
		 *
		 * Allows customization of site retrieval, including pagination for large networks.
		 *
		 * @param array $args Arguments passed to get_sites(). Default: array( 'number' => 0 ).
		 */
		$get_sites_args = apply_filters(
			'wp_mcp_ai_iterate_network_sites_args',
			array( 'number' => 0 )
		);

		// Get sites in the network.
		$sites = get_sites( $get_sites_args );

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			try {
				call_user_func( $callback );
			} catch ( Exception $e ) {
				// Log the error and continue with remaining sites.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for multisite activation/deactivation tracking.
				error_log( sprintf( 'Open Operator System %s failed for site %d: %s', $action, $site->blog_id, $e->getMessage() ) );
			}
			restore_current_blog();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_check_activation_security' ) ) {
	/**
	 * Check site security during plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_check_activation_security() {
		// Allow users to bypass security check with a constant.
		if ( defined( 'WP_MCP_AI_SKIP_SECURITY_CHECK' ) && WP_MCP_AI_SKIP_SECURITY_CHECK ) {
			return;
		}

		// Load the security check tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php';

		// Use container to create instance (supports dependency injection for testing).
		$security_tool = wp_mcp_ai_make( 'WP_MCP_AI_Tool_Check_Site_Security' );
		$result        = $security_tool->execute( array(), array( 'user_id' => get_current_user_id() ) );

		// Store result for admin notice display.
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'wp_mcp_ai_activation_security_check', $result, HOUR_IN_SECONDS );
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activation_security_notice' ) ) {
	/**
	 * Display security warning notice after plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activation_security_notice() {
		$security_check = get_transient( 'wp_mcp_ai_activation_security_check' );

		if ( ! $security_check || ! is_array( $security_check ) ) {
			return;
		}

		// Delete transient so notice only shows once.
		delete_transient( 'wp_mcp_ai_activation_security_check' );

		$risk_level = isset( $security_check['risk_level'] ) ? $security_check['risk_level'] : 'unknown';
		$is_safe    = isset( $security_check['is_safe_to_use'] ) ? $security_check['is_safe_to_use'] : false;

		// Only show notice for high and critical risk levels.
		if ( 'critical' !== $risk_level && 'high' !== $risk_level ) {
			return;
		}

		$recommendation = isset( $security_check['recommendation'] ) ? $security_check['recommendation'] : '';
		$summary        = isset( $security_check['summary'] ) ? $security_check['summary'] : array();
		$checks         = isset( $security_check['checks'] ) ? $security_check['checks'] : array();

		$notice_class = 'critical' === $risk_level ? 'notice-error' : 'notice-warning';

		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<h3><?php esc_html_e( 'Open Operator System Security Warning', 'mcp-ai-wpoos' ); ?></h3>
			<p><strong><?php echo esc_html( $recommendation ); ?></strong></p>
			<?php if ( ! empty( $summary ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: number of critical issues, 2: number of warnings */
						esc_html__( 'Security Check Results: %1$d critical issue(s), %2$d warning(s)', 'mcp-ai-wpoos' ),
						isset( $summary['critical'] ) ? absint( $summary['critical'] ) : 0,
						isset( $summary['warning'] ) ? absint( $summary['warning'] ) : 0
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $checks ) ) : ?>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<?php foreach ( $checks as $check ) : ?>
						<?php if ( isset( $check['severity'] ) && in_array( $check['severity'], array( 'critical', 'warning' ), true ) ) : ?>
							<li>
								<strong><?php echo esc_html( isset( $check['name'] ) ? $check['name'] : '' ); ?>:</strong>
								<?php echo esc_html( isset( $check['message'] ) ? $check['message'] : '' ); ?>
								<?php if ( ! empty( $check['action'] ) ) : ?>
									<br><em><?php echo esc_html( $check['action'] ); ?></em>
								<?php endif; ?>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'This plugin handles sensitive AI API keys and data. Using it on an insecure site puts your API keys and user data at risk.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<em>
					<?php
					printf(
						wp_kses(
							/* translators: %s: code snippet for wp-config.php */
							__( 'To bypass this security check, add %s to your wp-config.php file. Only do this if you understand the risks.', 'mcp-ai-wpoos' ),
							array( 'code' => array() )
						),
						'<code>' . esc_html( "define( 'WP_MCP_AI_SKIP_SECURITY_CHECK', true );" ) . '</code>'
					);
					?>
				</em>
			</p>
		</div>
		<?php
	}
}

/**
 * Run deferred activation security check.
 *
 * WordPress 6.7.0+ requires translations to be loaded at init or later.
 * This function runs the security check on admin_init (after init completes)
 * and stores results for display in admin_notices.
 */
function wp_mcp_ai_run_deferred_activation_security_check() {
	// Check if we need to run the deferred activation security check.
	if ( get_transient( 'wp_mcp_ai_run_activation_security_check' ) ) {
		delete_transient( 'wp_mcp_ai_run_activation_security_check' );
		wp_mcp_ai_check_activation_security();
	}
}
add_action( 'admin_init', 'wp_mcp_ai_run_deferred_activation_security_check' );

/**
 * Run deferred credentials migration on admin_init.
 *
 * Migrates credential fields from wp_mcp_ai_settings into the separate
 * non-autoload wp_mcp_ai_credentials option. Runs once per site and is
 * guarded by a flag option so it never repeats. The migration is additive
 * (creates the new option without deleting from the old) — a verify step
 * confirms success before removing the migrated keys from wp_mcp_ai_settings.
 *
 * @since 1.2.0
 * @return void
 */
function wp_mcp_ai_migrate_credentials_to_split() {
	if ( get_option( 'wp_mcp_ai_credentials_migrated' ) ) {
		return;
	}

	if ( ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
		return;
	}

	$settings    = get_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, array() );
	$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	if ( ! is_array( $credentials ) ) {
		$credentials = array();
	}

	$migrated_count = 0;
	foreach ( $settings as $key => $value ) {
		if ( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( $key ) ) {
			$credentials[ $key ] = $value;
			unset( $settings[ $key ] );
			++$migrated_count;
		}
	}

	if ( $migrated_count > 0 ) {
		// Save credentials first (non-autoload).
		update_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, $credentials, false );

		// Clear cache to ensure read-back is fresh.
		wp_cache_delete( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, 'options' );

		// Verify credentials were saved correctly.
		$verified = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );
		if ( is_array( $verified ) && count( $verified ) >= $migrated_count ) {
			// Success — now remove migrated keys from the main settings option.
			update_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, $settings, true );

			// Clear settings cache.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				WP_MCP_AI_Admin_Settings::reset_settings_cache();
			}
			wp_cache_delete( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, 'options' );
		}
	}

	// Mark migration as complete regardless of count (zero keys = no-op).
	update_option( 'wp_mcp_ai_credentials_migrated', true, false );
}
add_action( 'admin_init', 'wp_mcp_ai_migrate_credentials_to_split' );

/**
 * Register activation security notice on admin_notices.
 *
 * WordPress 6.7.0+ requires translations to be loaded at init or later.
 * By hooking directly to admin_notices, translation functions are only called
 * when the notice is actually rendered, after init completes.
 */
add_action( 'admin_notices', 'wp_mcp_ai_activation_security_notice' );

if ( ! function_exists( 'wp_mcp_ai_activate' ) ) {
	/**
	 * Plugin activation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_activate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_activate_single_site', 'activation' );
		} else {
			wp_mcp_ai_activate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activate_single_site' ) ) {
	/**
	 * Activate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activate_single_site() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Set a flag to run security check on next admin_init instead of during activation.
		// This avoids triggering translation loading before the init action (WordPress 6.7+ requirement).
		// The security check tool uses translation functions, which would trigger the
		// "_load_textdomain_just_in_time was called incorrectly" warning if called during activation.
		set_transient( 'wp_mcp_ai_run_activation_security_check', true, HOUR_IN_SECONDS );

		// Schedule file cleanup cron job (daily).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_gemini_files' );
		}
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_openai_files' );
		}
		// Schedule hourly temp-file cleanup cron job (F-FS-01).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_temp_files' ) ) {
			wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_cleanup_temp_files' );
		}
		// Schedule daily model catalog discovery cron job.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_model_catalog_discovery' ) ) {
			$discovery_interval = apply_filters( 'wp_mcp_ai_model_discovery_interval', 'daily' );
			$discovery_interval = is_string( $discovery_interval ) && '' !== $discovery_interval ? $discovery_interval : 'daily';
			wp_schedule_event( time() + HOUR_IN_SECONDS, $discovery_interval, 'wp_mcp_ai_model_catalog_discovery' );
		}

		// Schedule service status health check cron (every 5 minutes).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_health_check_cron' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'wp_mcp_ai_health_check_cron' );
		}

		// Schedule uptime history rollup cron (hourly).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_uptime_rollup_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_uptime_rollup_cron' );
		}

		// Schedule status history cleanup cron (daily).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_status_history_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_status_history_cleanup' );
		}

		// Schedule maintenance monitor cron (runs every 5 minutes).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_maintenance_monitor_cron' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'wp_mcp_ai_maintenance_monitor_cron' );
		}

		// Schedule maintenance reminder cron (runs every 5 minutes).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_maintenance_reminder_cron' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'wp_mcp_ai_maintenance_reminder_cron' );
		}

		// Install default multi-agent orchestration system on first activation.
		// This is deferred to init hook to ensure assistant CPT is registered.
		// Uses transient to trigger installation on next page load.
		if ( ! get_option( 'wp_mcp_ai_default_assistants_installed' ) ) {
			set_transient( 'wp_mcp_ai_install_default_assistants', true, HOUR_IN_SECONDS );
		}

		// Install bundled Anthropic Agent Skills on activation.
		// Deferred to init hook to ensure the uploads directory is accessible.
		// Skills that are already installed in uploads will be skipped.
		set_transient( 'wp_mcp_ai_install_bundled_skills', true, HOUR_IN_SECONDS );

		// Set a transient to redirect new users to the onboarding wizard on the next
		// admin page load. Only triggers when the wizard has not been completed yet.
		if ( ! get_option( 'wp_mcp_ai_onboarding_complete' ) ) {
			set_transient( 'wp_mcp_ai_activation_redirect', true, 30 );
		}

		// Trigger optional components download (vectorizer & knowledge base).
		// This runs in the background after activation to avoid blocking.
		do_action( 'wp_mcp_ai_after_activation' );

		// Create thread management database tables (SPA conversation support).
		$thread_manager_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-thread-manager.php';
		if ( file_exists( $thread_manager_file ) ) {
			require_once $thread_manager_file;
			WP_MCP_AI_Thread_Manager::create_tables();
		}

		// Track plugin activation for analytics.
		// Determine the plugin variant based on constants and file structure.
		$plugin_variant = 'complete'; // Default for mcp-ai-wpoos.php.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$plugin_variant = 'base';
		}
		WP_MCP_AI_Activation_Tracker::track_activation( $plugin_variant );

		// Note: We intentionally do not call WP_MCP_AI_Assistant_CPT::register_post_type() here
		// to avoid triggering translation loading before the init action (WordPress 6.7+ requirement).
		// The post type will be registered on the next page load via the init hook.
		flush_rewrite_rules();

		// Create slash command audit table.
		if ( file_exists( WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php';
			$audit = new WP_MCP_AI_Slash_Command_Audit();
			$audit->create_table();
		}

		// Create the measurement event-store table and schedule the
		// daily retention cron. Both are idempotent.
		if ( class_exists( 'WP_MCP_AI_Metric_Event_Store' ) ) {
			WP_MCP_AI_Metric_Event_Store::get_instance()->install();
		}
		if ( class_exists( 'WP_MCP_AI_Metric_Retention' ) ) {
			WP_MCP_AI_Metric_Retention::schedule();
		}

		// Create security audit log table and schedule the daily purge cron.
		if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_MCP_AI_Security_Audit_Logger::on_activation();
		}

		// Create queue infrastructure tables (v1.1.37 — migration from wp_options).
		// The Job Queue Manager and Dead Letter Queue previously stored data in
		// serialized wp_options arrays, which are unsafe under concurrent writes.
		// These custom tables use InnoDB row-level locking for data integrity.
		$job_queue_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-queue-manager.php';
		if ( file_exists( $job_queue_file ) ) {
			require_once $job_queue_file;
			if ( method_exists( 'WP_MCP_AI_Job_Queue_Manager', 'create_table' ) ) {
				WP_MCP_AI_Job_Queue_Manager::create_table();
			}
		}

		$dlq_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-dead-letter-queue.php';
		if ( file_exists( $dlq_file ) ) {
			require_once $dlq_file;
			if ( method_exists( 'WP_MCP_AI_Dead_Letter_Queue', 'create_table' ) ) {
				WP_MCP_AI_Dead_Letter_Queue::create_table();
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_deactivate' ) ) {
	/**
	 * Plugin deactivation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_deactivate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_deactivate_single_site', 'deactivation' );
		} else {
			wp_mcp_ai_deactivate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_deactivate_single_site' ) ) {
	/**
	 * Deactivate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_deactivate_single_site() {
		// Track plugin deactivation for analytics.
		$plugin_variant = 'complete';
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$plugin_variant = 'base';
		}
		WP_MCP_AI_Activation_Tracker::track_deactivation( $plugin_variant );

		// Unschedule file cleanup cron jobs.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_cleanup_gemini_files' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_cleanup_openai_files' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_cleanup_temp_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_cleanup_temp_files' );
		}
		// Unschedule the model catalog discovery cron.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_model_catalog_discovery' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_model_catalog_discovery' );
		}

		// Unschedule service status cron jobs.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_health_check_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_health_check_cron' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_uptime_rollup_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_uptime_rollup_cron' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_status_history_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_status_history_cleanup' );
		}

		// Unschedule maintenance cron jobs.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_maintenance_monitor_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_maintenance_monitor_cron' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_maintenance_reminder_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_maintenance_reminder_cron' );
		}

		// Clear the security audit log purge cron.
		if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_MCP_AI_Security_Audit_Logger::on_deactivation();
		}

		// Unschedule the measurement retention cron. Table + data are
		// left in place — uninstall is where destructive cleanup lives.
		if ( class_exists( 'WP_MCP_AI_Metric_Retention' ) ) {
			WP_MCP_AI_Metric_Retention::unschedule();
		}

		/*
		 * Unschedule cron hooks registered by individual service classes.
		 *
		 * The following hooks are scheduled by their respective class init
		 * methods but do not register their own deactivation callbacks.
		 * We clear them here so scheduled events do not persist after
		 * deactivation on hosts that keep the plugin files but disable it.
		 *
		 * When a class provides its own unschedule() / on_deactivation()
		 * method, we prefer that — the hooks below are only for classes
		 * that lack a dedicated cleanup path.
		 */
		$cleanup_hooks = array(
			'wp_mcp_ai_health_check_cron',
			'wp_mcp_ai_uptime_rollup_cron',
			'wp_mcp_ai_status_history_cleanup',
			'wp_mcp_ai_maintenance_monitor_cron',
			'wp_mcp_ai_maintenance_reminder_cron',
			'wp_mcp_ai_check_license',
			'wp_mcp_ai_audit_trail_prune',
			'wp_mcp_ai_approval_cleanup',
			'wp_mcp_ai_cleanup_async_results',
			'wp_mcp_ai_dlq_cleanup',
			'wp_mcp_ai_cleanup_token_tracking',
			'wp_mcp_ai_cleanup_job_cache',
			'wp_mcp_ai_cleanup_old_errors',
			'wp_mcp_ai_cleanup_slash_audit',
			'wp_mcp_ai_memory_tier_sweep',
			'wp_mcp_ai_markup_cleanup',
			'wp_mcp_ai_harness_eval_tick',
			'wp_mcp_ai_skill_catalogue_refresh',
			'wp_mcp_ai_nv_cloud_balance_refresh',
			'wp_mcp_ai_team_budget_reset_daily',
			'wp_mcp_ai_hourly_forecast_check',
		);

		foreach ( $cleanup_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		flush_rewrite_rules();
	}
}

if ( ! function_exists( 'wp_mcp_ai_uninstall' ) ) {
	/**
	 * Plugin uninstall handler.
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall() {
		if ( is_multisite() ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_uninstall_single_site', 'uninstall' );
		} else {
			wp_mcp_ai_uninstall_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_uninstall_single_site' ) ) {
	/**
	 * Uninstall the plugin on a single site.
	 *
	 * Performs comprehensive cleanup of all plugin data when the user has
	 * opted in via the "Delete data on uninstall" setting. This includes:
	 * - All custom post types and their metadata
	 * - All plugin options and transients
	 * - All custom database tables
	 * - All scheduled cron events
	 * - All user metadata created by the plugin
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall_single_site() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, WP_MCP_AI_Admin_Settings::get_default_settings() );

		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		global $wpdb;

		/**
		 * Fires before Open Operator System performs its uninstall cleanup routines.
		 */
		do_action( 'wp_mcp_ai_before_uninstall_cleanup' );

		$summary = array(
			'posts_deleted'   => 0,
			'options_deleted' => false,
			'tables_dropped'  => 0,
			'crons_cleared'   => 0,
		);

		/*
		 * 1. Delete all custom post types and their metadata.
		 *
		 * The plugin registers up to four CPTs. We delete all posts
		 * of each type, including associated post meta and term relationships.
		 */
		$post_types = array(
			'mcp_ai_assistant',
			'mcp_ai_profession',
			'mcp_ai_team',
			'mcp_ai_audit',
			'mcp_ai_service',
			'mcp_ai_maintenance',
			'mcp_ai_incident',
		);

		foreach ( $post_types as $post_type ) {
			$post_ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $post_ids ) ) {
				foreach ( $post_ids as $post_id ) {
					wp_delete_post( $post_id, true );
				}
				$summary['posts_deleted'] += count( $post_ids );
			}
		}

		/*
		 * 2. Delete all plugin options.
		 *
		 * Uses a wildcard query to remove every option prefixed with
		 * wp_mcp_ai_ — this covers settings, API keys, flags, and
		 * internal state that individual delete_option() calls might miss.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup during uninstall; no single-option API exists for wildcard deletion.
		$summary['options_deleted'] = $wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_mcp\_ai\_%'"
		);

		/*
		 * 3. Delete all plugin transients.
		 *
		 * Transients are stored as options with special prefixes.
		 * We remove both the value and the timeout entries.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup during uninstall; no single-transient API exists for wildcard deletion.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wp\_mcp\_ai\_%' OR option_name LIKE '\_transient\_timeout\_wp\_mcp\_ai\_%'"
		);

		/*
		 * 4. Drop custom database tables.
		 *
		 * The base plugin creates up to three tables. We drop them
		 * all, tolerating non-existence via IF EXISTS.
		 */
		$tables = array(
			$wpdb->prefix . 'mcp_ai_slash_command_audit',
			$wpdb->prefix . 'mcp_ai_job_queue',
			$wpdb->prefix . 'mcp_ai_hourly_token_usage',
			$wpdb->prefix . 'mcp_ai_metric_events',
			$wpdb->prefix . 'mcp_ai_threads',
			$wpdb->prefix . 'mcp_ai_thread_messages',
			$wpdb->prefix . 'mcp_ai_thread_checkpoints',
		);

		foreach ( $tables as $table ) {
			$table_name = esc_sql( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL required for custom plugin table cleanup; table name is escaped with esc_sql() and derived from $wpdb->prefix constant.
			if ( false !== $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ) ) {
				++$summary['tables_dropped'];
			}
		}

		/*
		 * 5. Unschedule all plugin cron events.
		 *
		 * Every cron hook registered by the plugin is cleared here.
		 * wp_clear_scheduled_hook() removes all events for a given hook.
		 */
		$cron_hooks = array(
			'wp_mcp_ai_health_check_cron',
			'wp_mcp_ai_uptime_rollup_cron',
			'wp_mcp_ai_status_history_cleanup',
			'wp_mcp_ai_maintenance_monitor_cron',
			'wp_mcp_ai_maintenance_reminder_cron',
			'wp_mcp_ai_cleanup_gemini_files',
			'wp_mcp_ai_cleanup_openai_files',
			'wp_mcp_ai_cleanup_temp_files',
			'wp_mcp_ai_process_job_queue',
			'wp_mcp_ai_cleanup_job_queue',
			'wp_mcp_ai_asset_discovery',
			'wp_mcp_ai_annual_training_reminder',
			'wp_mcp_ai_dlq_cleanup',
			'wp_mcp_ai_hourly_forecast_check',
			'wp_mcp_ai_cleanup_slash_audit',
			'wp_mcp_ai_supplier_review',
			'wp_mcp_ai_dependency_scan',
			'wp_mcp_ai_check_model_pricing',
			'wp_mcp_ai_verify_peers',
			'wp_mcp_ai_prune_expired_contexts',
			'wp_mcp_ai_cleanup_async_results',
			'wp_mcp_ai_cleanup_old_errors',
			'wp_mcp_ai_cleanup_job_cache',
			'wp_mcp_ai_quarterly_audit',
			'wp_mcp_ai_cleanup_token_tracking',
			'wp_mcp_ai_check_license',
		);

		foreach ( $cron_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
			++$summary['crons_cleared'];
		}

		/*
		 * 6. Delete all plugin user metadata.
		 *
		 * Removes per-user preferences, permissions, and dismissed
		 * notice flags created by the plugin.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup during uninstall; no single-user-meta API exists for wildcard deletion across all users.
		$wpdb->query(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wp\_mcp\_ai\_%'"
		);

		/**
		 * Fires after Open Operator System completes its uninstall cleanup routines.
		 *
		 * @param array $summary Summary of cleanup actions performed.
		 */
		do_action( 'wp_mcp_ai_after_uninstall_cleanup', $summary );
	}
}

if ( ! function_exists( 'wp_mcp_ai_new_site_activation' ) ) {
	/**
	 * Activate plugin on a newly created site in a multisite network.
	 *
	 * @param int|WP_Site $blog WordPress 5.1+ passes a WP_Site object, earlier versions pass blog ID.
	 * @return void
	 */
	function wp_mcp_ai_new_site_activation( $blog ) {
		if ( ! is_plugin_active_for_network( plugin_basename( WP_MCP_AI_FILE ) ) ) {
			return;
		}

		// Handle both WP_Site object (WP 5.1+) and blog ID (earlier versions).
		if ( is_object( $blog ) && isset( $blog->blog_id ) ) {
			$blog_id = (int) $blog->blog_id;
		} elseif ( is_numeric( $blog ) ) {
			$blog_id = (int) $blog;
		} else {
			// Invalid parameter, log error and return.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for debugging multisite activation issues.
			error_log( 'Open Operator System: Invalid blog parameter passed to new_site_activation' );
			return;
		}

		switch_to_blog( $blog_id );
		try {
			wp_mcp_ai_activate_single_site();
		} catch ( Exception $e ) {
			// Log the error but don't break the site creation process.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for debugging multisite activation issues.
			error_log( sprintf( 'Open Operator System activation failed for site %d: %s', $blog_id, $e->getMessage() ) );
		}
		restore_current_blog();
	}
}

if ( ! has_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' ) ) {
	add_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' );
	add_action( 'wpmu_new_blog', 'wp_mcp_ai_new_site_activation' );
}

// Deferred init: install default assistants on first page load after activation.
add_action(
	'init',
	function () {
		if ( get_transient( 'wp_mcp_ai_install_default_assistants' ) ) {
			delete_transient( 'wp_mcp_ai_install_default_assistants' );

			// Install default multi-agent orchestration system.
			$result = WP_MCP_AI_Default_Assistants::install();

			// Log any errors for debugging using WordPress logging mechanism.
			if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Development debugging only when WP_DEBUG is enabled.
				error_log( 'WP_MCP_AI: Failed to install default assistants: ' . $result->get_error_message() );
			}
		}
	},
	100 // Run late to ensure CPT is registered.
);

// Deferred init: install bundled Anthropic Agent Skills.
add_action(
	'init',
	function () {
		if ( get_transient( 'wp_mcp_ai_install_bundled_skills' ) ) {
			delete_transient( 'wp_mcp_ai_install_bundled_skills' );

			$registry = WP_MCP_AI_Skill_Registry::instance();
			$result   = $registry->install_bundled_skills();

			// Log any errors for debugging.
			if ( ! empty( $result['errors'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Development debugging only when WP_DEBUG is enabled.
				error_log( 'WP_MCP_AI: Bundled skills install errors: ' . implode( '; ', $result['errors'] ) );
			}
		}
	},
	100
);
