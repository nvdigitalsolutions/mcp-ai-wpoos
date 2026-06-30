<?php
/**
 * Shopify Sync Engine.
 *
 * Orchestrates background sync between Shopify and the JetEngine CCT cache.
 * Uses Action Scheduler for reliable scheduling with concurrency guards.
 * Handles WooCommerce stock writeback when enabled in settings.
 * Tracks GraphQL cost and implements budget-aware sync strategies.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {

	/**
	 * Shopify Sync Engine.
	 *
	 * Manages scheduled sync operations, GraphQL cost tracking,
	 * and WooCommerce stock synchronization.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Shopify_Sync_Engine {

		/**
		 * Action Scheduler hook prefix for full inventory sync.
		 *
		 * Actual hooks are suffixed with connection ID: wp_mcp_ai_shopify_full_sync_{conn_id}
		 *
		 * @var string
		 */
		const HOOK_FULL_SYNC = 'wp_mcp_ai_shopify_full_sync';

		/**
		 * Action Scheduler hook prefix for WooCommerce stock sync.
		 *
		 * @var string
		 */
		const HOOK_WC_SYNC = 'wp_mcp_ai_shopify_wc_sync';

		/**
		 * Action Scheduler group for Shopify sync tasks.
		 *
		 * @var string
		 */
		const GROUP = 'shopify_sync';

		/**
		 * Action Scheduler group for Shopify WC sync tasks.
		 *
		 * @var string
		 */
		const GROUP_WC = 'shopify_sync_wc';

		/**
		 * Daily GraphQL cost limit (1,000 points on standard Shopify plans).
		 *
		 * @var int
		 */
		const COST_LIMIT = 1000;

		/**
		 * Refill rate in points per second.
		 *
		 * @var float
		 */
		const COST_REFILL_RATE = 50.0;

		/**
		 * Percentage threshold below which syncs are skipped to conserve budget.
		 *
		 * @var float
		 */
		const COST_LOW_THRESHOLD_PCT = 10.0;

		/**
		 * Remote Sites connection ID for the Shopify store.
		 *
		 * @var string
		 */
		protected $connection_id;

		/**
		 * Constructor.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 */
		public function __construct( $connection_id ) {
			$this->connection_id = $connection_id;
		}

		/**
		 * Initialize the sync engine.
		 *
		 * Hooks into WordPress to register Action Scheduler callbacks.
		 *
		 * @since 1.3.0
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'schedule_all_syncs' ) );
		}

		/**
		 * Schedule recurring sync actions for all enabled connections.
		 *
		 * Called on every init; only schedules if not already scheduled.
		 *
		 * @since 1.3.0
		 */
		public static function schedule_all_syncs() {
			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}

			$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

			if ( ! is_array( $sync_connections ) || empty( $sync_connections ) ) {
				return;
			}

			$interval_minutes = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 15;
			$interval_seconds = $interval_minutes * MINUTE_IN_SECONDS;

			foreach ( $sync_connections as $conn_id ) {
				$conn_id = sanitize_key( $conn_id );
				if ( empty( $conn_id ) ) {
					continue;
				}

				$hook = self::HOOK_FULL_SYNC . '_' . $conn_id;

				if ( ! as_has_scheduled_action( $hook ) ) {
					as_schedule_recurring_action(
						time() + 60, // Start 1 minute from now.
						$interval_seconds,
						$hook,
						array( 'connection_id' => $conn_id ),
						self::GROUP,
						true // Unique.
					);
				}

				// WC sync (only if enabled).
				if ( ! empty( $settings['enable_wc_sync'] ) ) {
					$wc_hook = self::HOOK_WC_SYNC . '_' . $conn_id;
					if ( ! as_has_scheduled_action( $wc_hook ) ) {
						as_schedule_recurring_action(
							time() + 120, // Start 2 minutes from now.
							$interval_seconds,
							$wc_hook,
							array( 'connection_id' => $conn_id ),
							self::GROUP_WC,
							true
						);
					}
				}
			}
		}

		/**
		 * Run a full inventory sync for a specific connection.
		 *
		 * Callback for HOOK_FULL_SYNC action.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 * @param bool   $dry_run       If true, validate everything but skip
		 *                              CCT writes, cost tracking, and option
		 *                              updates. Runs the GraphQL query to
		 *                              verify connectivity and data shape.
		 *                              Default false.
		 * @return array|WP_Error Dry-run report when $dry_run is true,
		 *                        otherwise void (side-effect based).
		 */
		public static function run_full_sync( $connection_id, $dry_run = false ) {
			if ( ! function_exists( 'wp_mcp_ai_log' ) ) {
				return $dry_run ? new WP_Error(
					'wp_mcp_ai_shopify_sync_no_logger',
					__( 'Plugin logger is not available.', 'mcp-ai-wpoos-pro' )
				) : null;
			}

			if ( $dry_run ) {
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log( sprintf( 'Shopify DRY RUN started for connection %s.', $connection_id ), 'info' );
				}
			} elseif ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log( sprintf( 'Shopify full sync started for connection %s.', $connection_id ), 'info' );
			}

			$engine = new self( $connection_id );

			// Check cost budget before syncing (skip in dry-run, but report it).
			if ( ! $dry_run && $engine->should_skip_sync_due_to_cost() ) {
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						sprintf( 'Shopify sync skipped for %s: GraphQL cost budget too low.', $connection_id ),
						'warning'
					);
				}
				return;
			}

			$cost_report = $engine->get_cost_report();

			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );

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
			$columns_ensured = $cct_manager->ensure_columns();
			if ( is_wp_error( $columns_ensured ) ) {
				if ( $dry_run ) {
					return $columns_ensured;
				}
				self::handle_sync_error( $columns_ensured, $connection_id );
				return;
			}

			// Run the sync (GraphQL bulk operation + JSONL upsert).
			$result = $cct_manager->sync_from_bulk_operation( null, $dry_run );

			if ( is_wp_error( $result ) ) {
				if ( $dry_run ) {
					return $result;
				}
				self::handle_sync_error( $result, $connection_id );
				return;
			}

			if ( $dry_run ) {
				// Build a comprehensive dry-run report.
				$item_count = isset( $result['total'] ) ? absint( $result['total'] ) : 0;
				$dry_report = array(
					'success'       => true,
					'dry_run'       => true,
					'connection_id' => $connection_id,
					'status'        => array(
						'cct_slug'           => $cct_manager->get_cct_slug(),
						'cct_exists'         => $cct_manager->is_cct_available() === true,
						'cct_created'        => ! empty( $cct_ensured['created'] ),
						'columns_created'    => $columns_ensured,
						'client_available'   => class_exists( 'WP_MCP_AI_Shopify_Client' ),
						'cost_budget_pct'    => round( $cost_report['pct_remaining'], 1 ),
						'cost_would_consume' => 10,
					),
					'data_summary'  => array(
						'items_would_insert' => isset( $result['inserted'] ) ? $result['inserted'] : 0,
						'items_would_update' => isset( $result['updated'] ) ? $result['updated'] : 0,
						'items_would_skip'   => isset( $result['skipped'] ) ? $result['skipped'] : 0,
						'total_items'        => $item_count,
						'duration'           => isset( $result['duration'] ) ? $result['duration'] : 0,
					),
					'validation'    => array(
						'shopify_connected' => ! is_wp_error( $result ),
						'warnings'          => isset( $result['warnings'] ) ? $result['warnings'] : array(),
					),
					'timestamp'     => current_time( 'mysql' ),
				);

				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log(
						sprintf(
							'Shopify DRY RUN completed for %s: %d items would be inserted, %d updated, %d skipped.',
							$connection_id,
							$dry_report['data_summary']['items_would_insert'],
							$dry_report['data_summary']['items_would_update'],
							$dry_report['data_summary']['items_would_skip']
						),
						'info'
					);
				}

				return $dry_report;
			}

			// Track GraphQL cost (bulk operation = 10 points).
			$engine->track_sync_cost( 10 );

			update_option( 'wp_mcp_ai_shopify_last_sync_' . $connection_id, current_time( 'mysql' ) );
			delete_option( 'wp_mcp_ai_shopify_last_sync_error_' . $connection_id );

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						'Shopify sync completed for %s: %d inserted, %d updated, %d skipped, %d errors, %ss.',
						$connection_id,
						isset( $result['inserted'] ) ? $result['inserted'] : 0,
						isset( $result['updated'] ) ? $result['updated'] : 0,
						isset( $result['skipped'] ) ? $result['skipped'] : 0,
						isset( $result['errors'] ) ? $result['errors'] : 0,
						isset( $result['duration'] ) ? $result['duration'] : 0
					),
					'info'
				);
			}
		}

		/**
		 * Run WooCommerce stock sync.
		 *
		 * Updates WooCommerce product stock quantities from Shopify CCT data.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 */
		public static function run_wc_sync( $connection_id ) {
			$settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );

			if ( empty( $settings['enable_wc_sync'] ) ) {
				return;
			}

			if ( ! class_exists( 'WooCommerce' ) ) {
				if ( function_exists( 'wp_mcp_ai_log' ) ) {
					wp_mcp_ai_log( 'Shopify WC sync skipped: WooCommerce not active.', 'info' );
				}
				return;
			}

			$engine    = new self( $connection_id );
			$direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'shopify_to_woo';

			switch ( $direction ) {
				case 'shopify_to_woo':
					$count = $engine->sync_shopify_to_woocommerce();
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log(
							sprintf( 'Shopify→WC sync for %s: %d products updated.', $connection_id, $count ),
							'info'
						);
					}
					break;

				case 'bidirectional':
					$count = $engine->sync_shopify_to_woocommerce();
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log(
							sprintf( 'Shopify→WC sync (bidirectional) for %s: %d products updated.', $connection_id, $count ),
							'info'
						);
					}
					// Phase 2: WC→Shopify writeback placeholder.
					break;

				case 'woo_to_shopify':
					// Phase 2: Push WC stock back to Shopify.
					if ( function_exists( 'wp_mcp_ai_log' ) ) {
						wp_mcp_ai_log( 'WC→Shopify direction not yet implemented.', 'info' );
					}
					break;
			}
		}

		/**
		 * Sync Shopify inventory quantities to WooCommerce.
		 *
		 * Matches CCT items to WooCommerce products by SKU, then updates
		 * stock quantities via wc_update_product_stock().
		 *
		 * @since 1.3.0
		 *
		 * @return int Number of products updated.
		 */
		protected function sync_shopify_to_woocommerce() {
			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
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

					$quantity = isset( $item['available_qty'] ) ? absint( $item['available_qty'] ) : 0;

					// HPOS-compatible stock update.
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
			 * @since 1.3.0
			 *
			 * @param array  $result        Result data.
			 * @param string $connection_id The Shopify connection ID.
			 */
			do_action(
				'wp_mcp_ai_shopify_after_wc_sync',
				array(
					'connection_id' => $this->connection_id,
					'updated'       => $updated,
					'direction'     => 'shopify_to_woo',
				),
				$this->connection_id
			);

			return $updated;
		}

		// ------------------------------------------------------------------ //
		// GraphQL Cost Management                                             //
		// ------------------------------------------------------------------ //

		/**
		 * Track sync cost against the daily budget.
		 *
		 * @since 1.3.0
		 *
		 * @param int $points GraphQL points consumed.
		 */
		public function track_sync_cost( $points ) {
			$cost_data = $this->get_daily_cost_data();

			$cost_data['used']      = ( isset( $cost_data['used'] ) ? absint( $cost_data['used'] ) : 0 ) + absint( $points );
			$cost_data['history'][] = array(
				'timestamp' => current_time( 'mysql' ),
				'points'    => absint( $points ),
				'operation' => 'sync',
			);

			// Keep only last 100 history entries.
			if ( count( $cost_data['history'] ) > 100 ) {
				$cost_data['history'] = array_slice( $cost_data['history'], -100 );
			}

			update_option( 'wp_mcp_ai_shopify_daily_cost_' . $this->connection_id, $cost_data );
		}

		/**
		 * Get daily cost data for the connection.
		 *
		 * @since 1.3.0
		 *
		 * @return array Cost data.
		 */
		public function get_daily_cost_data() {
			$cost_data = get_option( 'wp_mcp_ai_shopify_daily_cost_' . $this->connection_id, array() );

			// Reset if the stored date doesn't match today.
			$today = gmdate( 'Y-m-d' );
			if ( ! isset( $cost_data['date'] ) || $cost_data['date'] !== $today ) {
				$cost_data = array(
					'date'    => $today,
					'used'    => 0,
					'limit'   => self::COST_LIMIT,
					'history' => isset( $cost_data['history'] ) ? $cost_data['history'] : array(),
				);
			}

			return $cost_data;
		}

		/**
		 * Get remaining GraphQL cost budget.
		 *
		 * @since 1.3.0
		 *
		 * @return int Remaining points.
		 */
		public function get_remaining_cost() {
			$cost_data = $this->get_daily_cost_data();
			return max( 0, self::COST_LIMIT - absint( isset( $cost_data['used'] ) ? $cost_data['used'] : 0 ) );
		}

		/**
		 * Get percentage of cost budget remaining.
		 *
		 * @since 1.3.0
		 *
		 * @return float Percentage (0–100).
		 */
		public function get_cost_budget_pct() {
			$remaining = $this->get_remaining_cost();
			return ( $remaining / self::COST_LIMIT ) * 100;
		}

		/**
		 * Check if sync should be skipped due to low cost budget.
		 *
		 * @since 1.3.0
		 *
		 * @return bool True if sync should be skipped.
		 */
		public function should_skip_sync_due_to_cost() {
			return $this->get_cost_budget_pct() < self::COST_LOW_THRESHOLD_PCT;
		}

		/**
		 * Get a formatted cost report.
		 *
		 * @since 1.3.0
		 *
		 * @return array Cost report data.
		 */
		public function get_cost_report() {
			$cost_data = $this->get_daily_cost_data();
			$remaining = $this->get_remaining_cost();
			$pct       = $this->get_cost_budget_pct();

			// Estimate refill time.
			$seconds_to_refill = ( self::COST_LIMIT - $remaining ) / self::COST_REFILL_RATE;
			$refill_at         = time() + absint( $seconds_to_refill );

			return array(
				'used'           => absint( isset( $cost_data['used'] ) ? $cost_data['used'] : 0 ),
				'limit'          => self::COST_LIMIT,
				'remaining'      => $remaining,
				'pct_remaining'  => round( $pct, 1 ),
				'is_low'         => $pct < self::COST_LOW_THRESHOLD_PCT,
				'refill_at'      => gmdate( 'Y-m-d H:i:s', $refill_at ),
				'refill_in_secs' => absint( $seconds_to_refill ),
				'recent_history' => array_slice( isset( $cost_data['history'] ) ? $cost_data['history'] : array(), -10 ),
				'connection_id'  => $this->connection_id,
			);
		}

		// ------------------------------------------------------------------ //
		// Error Handling                                                      //
		// ------------------------------------------------------------------ //

		/**
		 * Handle a sync error: log, notify admin, store for admin notice.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_Error|string $error         Error object or message string.
		 * @param string          $connection_id The Shopify connection ID.
		 */
		public static function handle_sync_error( $error, $connection_id ) {
			$message = is_wp_error( $error )
				? $error->get_error_message()
				: (string) $error;

			// Store for admin notice display.
			update_option( 'wp_mcp_ai_shopify_last_sync_error_' . $connection_id, $message );

			// Log to plugin logger.
			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf( 'Shopify sync error [%s]: %s', $connection_id, $message ),
					'error'
				);
			}

			// Email site admin with connection context.
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				$connection = null;
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				}
				$store_name = isset( $connection['name'] ) ? $connection['name'] : $connection_id;

				$subject = sprintf(
					'[%s] %s — %s',
					get_bloginfo( 'name' ),
					__( 'Shopify Sync Error', 'mcp-ai-wpoos-pro' ),
					$store_name
				);

				wp_mail(
					$admin_email,
					$subject,
					sprintf(
						/* translators: 1: store name, 2: connection ID, 3: error message, 4: timestamp */
						__( "A Shopify sync error occurred on your site.\n\nStore: %1\$s\nConnection: %2\$s\nError: %3\$s\nTime: %4\$s\n\nPlease check the Shopify Sync Toolkit settings to diagnose the issue.", 'mcp-ai-wpoos-pro' ),
						$store_name,
						$connection_id,
						$message,
						current_time( 'mysql' )
					)
				);
			}

			// Admin notice (shown on next admin page load).
			add_action(
				'admin_notices',
				function () use ( $message, $connection_id ) {
					self::show_sync_error_notice( $message, $connection_id );
				}
			);
		}

		/**
		 * Display an admin notice for the last sync error.
		 *
		 * @since 1.3.0
		 *
		 * @param string $message       Error message.
		 * @param string $connection_id Connection ID context.
		 */
		public static function show_sync_error_notice( $message, $connection_id ) {
			if ( empty( $message ) ) {
				return;
			}

			// Only show on relevant pages.
			$screen = get_current_screen();
			if ( $screen && ! in_array(
				$screen->id,
				array( 'dashboard', 'plugins', 'toplevel_page_wp-mcp-ai-shopify-sync-toolkit' ),
				true
			) ) {
				return;
			}
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Shopify Sync Error:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( $message ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-shopify-sync-toolkit-settings' ) ); ?>">
						<?php esc_html_e( 'Check Shopify Sync Toolkit Settings →', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
			<?php
			delete_option( 'wp_mcp_ai_shopify_last_sync_error_' . $connection_id );
		}

		/**
		 * Clear all scheduled Shopify sync actions for a connection.
		 *
		 * Called on plugin deactivation.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 */
		public static function clear_scheduled_actions( $connection_id ) {
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}

			$hook    = self::HOOK_FULL_SYNC . '_' . $connection_id;
			$wc_hook = self::HOOK_WC_SYNC . '_' . $connection_id;

			as_unschedule_all_actions( $hook, array(), self::GROUP );
			as_unschedule_all_actions( $wc_hook, array(), self::GROUP_WC );
		}

		/**
		 * Clear all scheduled actions across all connections.
		 *
		 * Called on full plugin deactivation.
		 *
		 * @since 1.3.0
		 */
		public static function clear_all_scheduled_actions() {
			$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

			foreach ( $sync_connections as $conn_id ) {
				self::clear_scheduled_actions( $conn_id );
			}
		}

		/**
		 * Dispatch full sync from Action Scheduler args.
		 *
		 * @since 1.3.0
		 *
		 * @param array $args Action Scheduler arguments containing connection_id.
		 */
		public static function dispatch_full_sync( $args = array() ) {
			$connection_id = is_array( $args ) && isset( $args['connection_id'] )
				? sanitize_key( $args['connection_id'] )
				: ( is_string( $args ) ? sanitize_key( $args ) : '' );

			if ( ! empty( $connection_id ) ) {
				self::run_full_sync( $connection_id );
			}
		}

		/**
		 * Dispatch WC sync from Action Scheduler args.
		 *
		 * @since 1.3.0
		 *
		 * @param array $args Action Scheduler arguments containing connection_id.
		 */
		public static function dispatch_wc_sync( $args = array() ) {
			$connection_id = is_array( $args ) && isset( $args['connection_id'] )
				? sanitize_key( $args['connection_id'] )
				: ( is_string( $args ) ? sanitize_key( $args ) : '' );

			if ( ! empty( $connection_id ) ) {
				self::run_wc_sync( $connection_id );
			}
		}
	}

	// Register Action Scheduler callbacks for ALL per-connection hooks.
	// The init schedules hooks named wp_mcp_ai_shopify_full_sync_{conn_id}.
	add_action(
		'init',
		function () {
			$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

			foreach ( $sync_connections as $conn_id ) {
				$conn_id = sanitize_key( $conn_id );
				if ( empty( $conn_id ) ) {
					continue;
				}

				$hook    = WP_MCP_AI_Shopify_Sync_Engine::HOOK_FULL_SYNC . '_' . $conn_id;
				$wc_hook = WP_MCP_AI_Shopify_Sync_Engine::HOOK_WC_SYNC . '_' . $conn_id;

				if ( ! has_action( $hook, array( 'WP_MCP_AI_Shopify_Sync_Engine', 'dispatch_full_sync' ) ) ) {
					add_action( $hook, array( 'WP_MCP_AI_Shopify_Sync_Engine', 'dispatch_full_sync' ), 10, 1 );
				}
				if ( ! has_action( $wc_hook, array( 'WP_MCP_AI_Shopify_Sync_Engine', 'dispatch_wc_sync' ) ) ) {
					add_action( $wc_hook, array( 'WP_MCP_AI_Shopify_Sync_Engine', 'dispatch_wc_sync' ), 10, 1 );
				}
			}
		},
		20
	);
}
