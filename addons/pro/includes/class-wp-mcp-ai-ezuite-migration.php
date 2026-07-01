<?php
/**
 * EZuite Migration Helper.
 *
 * Detects the standalone ezuite-inventory-sync plugin and offers
 * a one-click migration to the Pro toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_EZuite_Migration' ) ) {

	/**
	 * EZuite Migration Helper.
	 *
	 * Handles detection of and migration from the standalone
	 * ezuite-inventory-sync plugin.
	 *
	 * @since 1.9.0
	 */
	class WP_MCP_AI_EZuite_Migration {

		/**
		 * Source plugin slug.
		 *
		 * @since 1.9.0
		 * @var string
		 */
		const SOURCE_PLUGIN = 'ezuite-inventory-sync/ezuite-inventory-sync.php';

		/**
		 * Option flag to prevent repeated migration prompts.
		 *
		 * @since 1.9.0
		 * @var string
		 */
		const MIGRATION_DISMISSED_OPTION = 'wp_mcp_ai_ezuite_migration_dismissed';

		/**
		 * Initialize migration hooks.
		 *
		 * @since 1.9.0
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'check_for_source_plugin' ) );
			add_action( 'admin_post_wp_mcp_ai_ezuite_migrate', array( __CLASS__, 'handle_migration' ) );
			add_action( 'admin_post_wp_mcp_ai_ezuite_dismiss_migration', array( __CLASS__, 'handle_dismiss' ) );
		}

		/**
		 * Check if the standalone plugin is active and show migration notice.
		 *
		 * @since 1.9.0
		 */
		public static function check_for_source_plugin() {
			if ( ! is_plugin_active( self::SOURCE_PLUGIN ) ) {
				return;
			}

			if ( get_option( self::MIGRATION_DISMISSED_OPTION, false ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			add_action( 'admin_notices', array( __CLASS__, 'show_migration_notice' ) );
		}

		/**
		 * Display the migration notice.
		 *
		 * @since 1.9.0
		 */
		public static function show_migration_notice() {
			?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'EZuite Inventory Sync Detected', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'You have the standalone EZuite Inventory Sync plugin active. You can migrate your settings to the NV oOS Pro EZuite Toolkit for AI-powered inventory management, automatic background sync, and natural language inventory queries.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_mcp_ai_ezuite_migrate' ), 'ezuite_migrate' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Migrate to Pro Toolkit', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_mcp_ai_ezuite_dismiss_migration' ), 'ezuite_dismiss' ) ); ?>" class="button">
						<?php esc_html_e( 'Dismiss', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Handle the migration action.
		 *
		 * Reads standalone plugin settings, converts them to the toolkit
		 * format, enables the EZuite toolkit flag, and deactivates the
		 * source plugin.
		 *
		 * @since 1.9.0
		 */
		public static function handle_migration() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
			}

			check_admin_referer( 'ezuite_migrate' );

			$settings = array();

			// Import from standalone plugin options.
			$standalone_options = array(
				'ezuite_connection_id'   => 'connection_id',
				'ezuite_api_url'         => 'api_url',
				'ezuite_cct'             => 'cct_slug',
				'ezuite_enable_wc_cron'  => 'enable_wc_sync',
				'ezuite_stock_threshold' => 'low_stock_threshold',
				'ezuite_sync_interval'   => 'sync_interval',
			);

			foreach ( $standalone_options as $old_key => $new_key ) {
				$value = get_option( $old_key, null );
				if ( null !== $value ) {
					if ( 'ezuite_enable_wc_cron' === $old_key ) {
						$value = ( 'yes' === $value ) ? 'yes' : 'no';
					}
					$settings[ $new_key ] = $value;
				}
			}

			// Set defaults for any missing values.
			$settings = wp_parse_args(
				$settings,
				array(
					'sync_interval'       => 15,
					'sync_direction'      => 'ezuite_to_woo',
					'cct_slug'            => 'ezuite_inventory',
					'low_stock_threshold' => 5,
				)
			);

			update_option( 'wp_mcp_ai_ezuite_toolkit_settings', $settings );

			// Also create a Remote Sites connection if connection_id was migrated.
			if ( ! empty( $settings['connection_id'] ) && ! empty( $settings['api_url'] ) ) {
				$remote_connections                               = get_option( 'wp_mcp_ai_remote_sites', array() );
				$remote_connections[ $settings['connection_id'] ] = array(
					'name'     => __( 'EZuite ERP (Migrated)', 'mcp-ai-wpoos-pro' ),
					'type'     => 'ezuite_erp',
					'api_url'  => $settings['api_url'],
					'enabled'  => true,
					'migrated' => true,
				);
				update_option( 'wp_mcp_ai_remote_sites', $remote_connections );
			}

			// Enable the toolkit.
			$global_settings                          = get_option( 'wp_mcp_ai_settings', array() );
			$global_settings['enable_ezuite_toolkit'] = true;
			update_option( 'wp_mcp_ai_settings', $global_settings );

			// Mark as migrated.
			update_option( self::MIGRATION_DISMISSED_OPTION, true );

			// Deactivate the source plugin.
			if ( is_plugin_active( self::SOURCE_PLUGIN ) ) {
				deactivate_plugins( self::SOURCE_PLUGIN );
			}

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log( 'EZuite settings migrated from standalone plugin.', 'info' );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-ezuite-toolkit-settings&migrated=1' ) );
			exit;
		}

		/**
		 * Handle dismiss action.
		 *
		 * @since 1.9.0
		 */
		public static function handle_dismiss() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
			}

			check_admin_referer( 'ezuite_dismiss' );

			update_option( self::MIGRATION_DISMISSED_OPTION, true );

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
			exit;
		}
	}
}
