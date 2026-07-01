<?php
/**
 * EZuite Sync Engine.
 *
 * Orchestrates background sync between EZuite ERP API and the JetEngine CCT cache.
 * Uses Action Scheduler for reliable scheduling with concurrency guards.
 * Handles WooCommerce stock writeback when enabled in settings.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {

	/**
	 * EZuite Sync Engine.
	 *
	 * Manages scheduled sync operations and WooCommerce stock synchronization.
	 *
	 * @since 1.9.0
	 */
	class WP_MCP_AI_EZuite_Sync_Engine {

		/**
		 * Action Scheduler hook for full inventory sync.
		 *
		 * @var string
		 */
		const HOOK_FULL_SYNC = 'wp_mcp_ai_ezuite_full_sync';

		/**
		 * Action Scheduler hook for WooCommerce stock sync.
		 *
		 * @var string
		 */
		const HOOK_WC_SYNC = 'wp_mcp_ai_ezuite_wc_sync';

		/**
		 * Action Scheduler group for EZuite tasks.
		 *
		 * @var string
		 */
		const GROUP = 'ezuite';

		/**
		 * Action Scheduler group for EZuite WC tasks.
		 *
		 * @var string
		 */
		const GROUP_WC = 'ezuite_wc';

		/**
		 * Initialize the sync engine.
		 *
		 * Hooks into WordPress to register Action Scheduler callbacks
		 * and schedule recurring sync actions.
		 *
		 * @since 1.9.0
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'schedule_recurring_syncs' ) );
			add_action( self::HOOK_FULL_SYNC, array( __CLASS__, 'run_full_sync' ) );
			add_action( self::HOOK_WC_SYNC, array( __CLASS__, 'run_wc_sync' ) );
			add_action( 'wp_mcp_ai_ezuite_after_sync', array( __CLASS__, 'log_sync_event' ), 10, 1 );
		}

		/**
		 * Schedule recurring sync actions via Action Scheduler.
		 *
		 * Called on every init; only schedules if not already scheduled.
		 *
		 * @since 1.9.0
		 */
		public static function schedule_recurring_syncs() {
			$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );

			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}

			$interval_seconds = ! empty( $settings['sync_interval'] )
				? absint( $settings['sync_interval'] ) * MINUTE_IN_SECONDS
				: 15 * MINUTE_IN_SECONDS;

			// Full inventory sync.
			if ( ! as_has_scheduled_action( self::HOOK_FULL_SYNC ) ) {
				as_schedule_recurring_action(
					time() + 60, // Start 1 minute from now.
					$interval_seconds,
					self::HOOK_FULL_SYNC,
					array(),
					self::GROUP,
					true // Unique.
				);
			}

			// WooCommerce sync (only if enabled).
			if ( ! empty( $settings['enable_wc_sync'] )
				&& ! as_has_scheduled_action( self::HOOK_WC_SYNC )
			) {
				as_schedule_recurring_action(
					time() + 120, // Start 2 minutes from now.
					$interval_seconds,
					self::HOOK_WC_SYNC,
					array(),
					self::GROUP_WC,
					true
				);
			}
		}

		/**
		 * Run a full inventory sync from EZuite API to CCT.
		 *
		 * Callback for HOOK_FULL_SYNC action.
		 *
		 * @since 1.9.0
		 *
		 * @param bool        $dry_run       If true, validate everything but skip
		 *                                   CCT writes and option updates. Runs
		 *                                   the API query to verify connectivity.
		 *                                   Default false.
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 * @return array|WP_Error Dry-run report when $dry_run is true,
		 *                        otherwise void (side-effect based).
		 */
		public static function run_full_sync( $dry_run = false, $connection_id = null ) {
			if ( $dry_run ) {
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log( 'EZuite DRY RUN started.', 'info' );
				}
			} elseif ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log( 'EZuite full sync started.', 'info' );
			}

			$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager( $connection_id );

			// Ensure CCT exists (auto-create if missing).
			$cct_ensured = $cct_manager->ensure_cct_exists();
			if ( is_wp_error( $cct_ensured ) ) {
				if ( $dry_run ) {
					return $cct_ensured;
				}
				self::handle_sync_error( $cct_ensured, $connection_id );
				return;
			}

			// Ensure CCT columns.
			$columns_result = $cct_manager->ensure_columns();
			if ( is_wp_error( $columns_result ) ) {
				if ( $dry_run ) {
					return $columns_result;
				}
				self::handle_sync_error( $columns_result, $connection_id );
				return;
			}

			$result = $cct_manager->sync_from_api( true, $connection_id, $dry_run );

			if ( is_wp_error( $result ) ) {
				if ( $dry_run ) {
					return $result;
				}
				self::handle_sync_error( $result, $connection_id );
				return;
			}

			if ( $dry_run ) {
				$dry_report = array(
					'success'      => true,
					'dry_run'      => true,
					'status'       => array(
						'cct_slug'      => $cct_manager->get_cct_slug(),
						'cct_exists'    => $cct_manager->is_cct_available() === true,
						'cct_created'   => ! empty( $cct_ensured['created'] ),
						'columns_ready' => $columns_result,
						'is_configured' => $cct_manager->is_api_configured(),
					),
					'data_summary' => array(
						'items_would_sync' => isset( $result['item_count'] ) ? $result['item_count'] : 0,
						'errors'           => isset( $result['error_count'] ) ? $result['error_count'] : 0,
						'duration'         => isset( $result['duration'] ) ? $result['duration'] : 0,
					),
					'timestamp'    => current_time( 'mysql' ),
				);

				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						sprintf(
							'EZuite DRY RUN completed: %d items.',
							$dry_report['data_summary']['items_would_sync']
						),
						'info'
					);
				}

				return $dry_report;
			}

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						'EZuite sync completed: %d items, %d errors, %ss.',
						$result['item_count'],
						$result['error_count'],
						$result['duration']
					),
					'info'
				);
			}
		}

		/**
		 * Run WooCommerce stock sync.
		 *
		 * Updates WooCommerce product stock quantities from EZuite CCT data.
		 * Direction and conflict resolution are controlled by toolkit settings.
		 *
		 * @since 1.9.0
		 */
		public static function run_wc_sync() {
			$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );

			if ( empty( $settings['enable_wc_sync'] ) ) {
				return;
			}

			if ( ! class_exists( 'WooCommerce' ) ) {
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log( 'EZuite WC sync skipped: WooCommerce not active.', 'info' );
				}
				return;
			}

			$direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'ezuite_to_woo';

			switch ( $direction ) {
				case 'ezuite_to_woo':
					$count = self::sync_ezuite_to_woocommerce();
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log(
							sprintf( 'EZuite→WC sync: %d products updated.', $count ),
							'info'
						);
					}
					break;

				case 'woo_to_ezuite':
					// Placeholder for future bidirectional sync.
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log( 'EZuite WC sync: woo_to_ezuite direction not yet implemented.', 'info' );
					}
					break;

				case 'bidirectional':
					// Run EZuite→WC first, then placeholder for WC→EZuite.
					$count = self::sync_ezuite_to_woocommerce();
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log(
							sprintf( 'EZuite→WC sync: %d products updated.', $count ),
							'info'
						);
					}
					break;
			}
		}

		/**
		 * Sync EZuite inventory quantities to WooCommerce.
		 *
		 * Matches CCT items to WooCommerce products by SKU, then updates
		 * stock quantities via wc_update_product_stock().
		 *
		 * Paginates in chunks of 100 for memory safety.
		 *
		 * @since 1.9.0
		 *
		 * @return int Number of products updated.
		 */
		protected static function sync_ezuite_to_woocommerce() {
			$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
			$updated     = 0;
			$page        = 1;
			$per_page    = 100;

			do {
				$items = $cct_manager->get_cached_items(
					array(
						'page'     => $page,
						'per_page' => $per_page,
					)
				);

				if ( empty( $items ) ) {
					break;
				}

				foreach ( $items as $item ) {
					$sku = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
					if ( empty( $sku ) ) {
						continue;
					}

					$product_id = wc_get_product_id_by_sku( $sku );
					if ( ! $product_id ) {
						continue;
					}

					$quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
					wc_update_product_stock( $product_id, $quantity, 'set' );

					// Link CCT row to WooCommerce product.
					if ( ! empty( $item['_ID'] ) ) {
						$cct_manager->update_woo_product_id( absint( $item['_ID'] ), $product_id );
					}

					++$updated;
				}

				++$page;
				$items_count = count( $items );
			} while ( $items_count >= $per_page );

			/**
			 * Fires after WooCommerce stock writeback completes.
			 *
			 * @since 1.9.0
			 *
			 * @param array $result Result data.
			 */
			do_action(
				'wp_mcp_ai_ezuite_after_wc_sync',
				array(
					'updated'   => $updated,
					'direction' => 'ezuite_to_woo',
				)
			);

			return $updated;
		}

		/**
		 * Handle a sync error: log, notify admin, store for admin notice.
		 *
		 * @since 1.9.0
		 *
		 * @param WP_Error|string $error         Error object or message string.
		 * @param string|null     $connection_id Optional Remote Sites connection ID.
		 */
		public static function handle_sync_error( $error, $connection_id = null ) {
			$message = is_wp_error( $error )
				? $error->get_error_message()
				: (string) $error;

			// Store for admin notice display (per-connection when applicable).
			$error_key = 'wp_mcp_ai_ezuite_last_sync_error';
			if ( ! empty( $connection_id ) ) {
				$error_key .= '_' . $connection_id;
			}
			update_option( $error_key, $message );

			// Log to plugin logger.
			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log( 'EZuite sync error: ' . $message, 'error' );
			}

			// Email site admin.
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				$subject = sprintf(
					'[%s] %s',
					get_bloginfo( 'name' ),
					__( 'EZuite Sync Error', 'mcp-ai-wpoos-pro' )
				);
				wp_mail(
					$admin_email,
					$subject,
					sprintf(
						/* translators: %1$s: site URL, %2$s: error message, %3$s: timestamp */
						__( "An EZuite inventory sync error occurred on your site.\n\nSite: %1\$s\nError: %2\$s\nTime: %3\$s\n\nPlease check the EZuite Toolkit settings to diagnose the issue.", 'mcp-ai-wpoos-pro' ),
						site_url(),
						$message,
						current_time( 'mysql' )
					)
				);
			}

			// Show admin notice on next admin page load.
			add_action( 'admin_notices', array( __CLASS__, 'show_sync_error_notice' ) );
		}

		/**
		 * Display an admin notice for the last sync error.
		 *
		 * @since 1.9.0
		 */
		public static function show_sync_error_notice() {
			$error = get_option( 'wp_mcp_ai_ezuite_last_sync_error', '' );

			if ( empty( $error ) ) {
				return;
			}

			// Only show on relevant pages.
			$screen = get_current_screen();
			if ( $screen && ! in_array(
				$screen->id,
				array( 'dashboard', 'plugins', 'toplevel_page_wp-mcp-ai-ezuite-toolkit' ),
				true
			) ) {
				return;
			}
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'EZuite Sync Error:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( $error ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-ezuite-toolkit-settings' ) ); ?>">
						<?php esc_html_e( 'Check EZuite Toolkit Settings →', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
			delete_option( 'wp_mcp_ai_ezuite_last_sync_error' );
		}

		/**
		 * Log a sync event to the plugin activity log.
		 *
		 * @since 1.9.0
		 *
		 * @param array $result Sync result array from CCT manager.
		 */
		public static function log_sync_event( $result ) {
			if ( ! function_exists( 'wp_mcp_ai_log' ) ) {
				return;
			}

			wp_mcp_ai_log(
				sprintf(
					/* translators: 1: item count, 2: error count, 3: duration in seconds */
					__( 'EZuite sync event: %1$d items, %2$d errors (took %3$ss).', 'mcp-ai-wpoos-pro' ),
					isset( $result['item_count'] ) ? $result['item_count'] : 0,
					isset( $result['error_count'] ) ? $result['error_count'] : 0,
					isset( $result['duration'] ) ? $result['duration'] : 0
				),
				'info'
			);
		}

		/**
		 * Clear all scheduled EZuite actions.
		 *
		 * Called on plugin deactivation.
		 *
		 * @since 1.9.0
		 */
		public static function clear_scheduled_actions() {
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}

			as_unschedule_all_actions( self::HOOK_FULL_SYNC, array(), self::GROUP );
			as_unschedule_all_actions( self::HOOK_WC_SYNC, array(), self::GROUP_WC );
		}

		/**
		 * Dispatch an immediate full sync via Action Scheduler.
		 *
		 * Schedules a single one-off action instead of running synchronously,
		 * so the request that triggers it doesn't block on API latency.
		 *
		 * @since 1.9.0
		 *
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 */
		public static function dispatch_full_sync( $connection_id = null ) {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}

			as_enqueue_async_action(
				self::HOOK_FULL_SYNC,
				array(
					'dry_run'       => false,
					'connection_id' => $connection_id,
				),
				self::GROUP,
				true // Unique.
			);
		}

		/**
		 * Dispatch an immediate WooCommerce stock sync.
		 *
		 * Schedules a single one-off action instead of running synchronously.
		 *
		 * @since 1.9.0
		 */
		public static function dispatch_wc_sync() {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}

			as_enqueue_async_action(
				self::HOOK_WC_SYNC,
				array(),
				self::GROUP_WC,
				true // Unique.
			);
		}
	}
}
