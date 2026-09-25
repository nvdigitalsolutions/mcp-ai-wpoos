<?php
/**
 * Price Alerts Tool
 *
 * Create, list, delete, and check price alerts for tickers. A daily cron
 * evaluates every user's alerts against current market prices and fires the
 * filterable `wp_mcp_ai_price_alert_triggered` hook for newly-triggered
 * alerts — the integration seam for result delivery (Telegram/email/
 * Pro Schedule Manager).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.80
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for managing price alerts.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Price_Alerts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Option name prefix for per-user alert storage.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_price_alerts_';

	/**
	 * Maximum alerts per user.
	 */
	const MAX_ALERTS = 50;

	/**
	 * Daily evaluation cron hook.
	 */
	const CRON_HOOK = 'wp_mcp_ai_price_alert_check_daily';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.80
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Price alerts tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'price_alerts';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Price Alerts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create, list, delete, and check price alerts. Triggered alerts fire the wp_mcp_ai_price_alert_triggered hook for delivery integrations. EDUCATIONAL ONLY - Prices are delayed public data. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the usage guidance.
	 *
	 * @since 1.1.83
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating and managing per-user alerts that fire when a ticker crosses an above or below price threshold.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'One-off quote checks; use stock_data_fetcher. Option strike and chain data belong to options_chain_fetcher.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'stock_data_fetcher', 'options_chain_fetcher', 'portfolio_transaction_log' ),
			'notes'           => __( 'action is required; create needs ticker, condition, and threshold. Alerts are evaluated daily by cron and fire a delivery hook.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.80
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'    => array(
					'type'        => 'string',
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'list', 'delete', 'check_now' ),
				),
				'ticker'    => array(
					'type'        => 'string',
					'description' => __( 'Ticker symbol (required for "create").', 'mcp-ai-wpoos-pro' ),
				),
				'condition' => array(
					'type'        => 'string',
					'description' => __( 'Trigger condition (required for "create").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'above', 'below' ),
				),
				'threshold' => array(
					'type'        => 'number',
					'description' => __( 'Trigger price (required for "create").', 'mcp-ai-wpoos-pro' ),
				),
				'note'      => array(
					'type'        => 'string',
					'description' => __( 'Optional note attached to the alert.', 'mcp-ai-wpoos-pro' ),
				),
				'alert_id'  => array(
					'type'        => 'string',
					'description' => __( 'Alert ID (required for "delete").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.80
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
			'database-read',
			'database-write',
			'external-api',
		);
	}

	/**
	 * Get a user's alerts.
	 *
	 * @since 1.1.80
	 *
	 * @param int $user_id User ID.
	 * @return array Alerts.
	 */
	public static function get_alerts( $user_id ) {
		return get_option( self::OPTION_PREFIX . absint( $user_id ), array() );
	}

	/**
	 * Persist a user's alerts.
	 *
	 * @since 1.1.80
	 *
	 * @param int   $user_id User ID.
	 * @param array $alerts  Alerts.
	 * @return bool
	 */
	protected static function set_alerts( $user_id, $alerts ) {
		return update_option( self::OPTION_PREFIX . absint( $user_id ), $alerts, false );
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage price alerts.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'create', 'list', 'delete', 'check_now' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'invalid_action',
				__( 'Invalid action. Must be one of: create, list, delete, check_now.', 'mcp-ai-wpoos-pro' )
			);
		}

		switch ( $action ) {
			case 'create':
				return $this->execute_create( $arguments, $current_user_id );

			case 'list':
				return $this->execute_list( $current_user_id );

			case 'delete':
				return $this->execute_delete( $arguments, $current_user_id );

			case 'check_now':
				return self::check_all_alerts( $current_user_id );

			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Create an alert.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_create( $arguments, $user_id ) {
		$ticker    = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';
		$condition = isset( $arguments['condition'] ) ? sanitize_key( $arguments['condition'] ) : '';
		$threshold = isset( $arguments['threshold'] ) ? (float) $arguments['threshold'] : 0.0;

		if ( empty( $ticker ) ) {
			return new WP_Error(
				'missing_ticker',
				__( 'Ticker symbol is required for the "create" action.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! in_array( $condition, array( 'above', 'below' ), true ) ) {
			return new WP_Error(
				'invalid_condition',
				__( 'Condition must be "above" or "below".', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $threshold <= 0 ) {
			return new WP_Error(
				'invalid_threshold',
				__( 'Threshold must be a positive number.', 'mcp-ai-wpoos-pro' )
			);
		}

		$alerts = self::get_alerts( $user_id );

		if ( count( $alerts ) >= self::MAX_ALERTS ) {
			return new WP_Error(
				'alert_limit_reached',
				/* translators: %d: maximum alerts */
				sprintf( __( 'Maximum %d price alerts per user.', 'mcp-ai-wpoos-pro' ), self::MAX_ALERTS )
			);
		}

		$alert = array(
			'id'                => substr( md5( uniqid( (string) $user_id, true ) ), 0, 12 ),
			'ticker'            => $ticker,
			'condition'         => $condition,
			'threshold'         => $threshold,
			'note'              => isset( $arguments['note'] ) ? sanitize_text_field( $arguments['note'] ) : '',
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'last_triggered_at' => '',
			'triggered_value'   => null,
			'trigger_count'     => 0,
		);

		$alerts[] = $alert;
		self::set_alerts( $user_id, $alerts );

		return array(
			'success'    => true,
			'action'     => 'create',
			'alert'      => $alert,
			'total'      => count( $alerts ),
			'disclaimer' => __( 'EDUCATIONAL ONLY. Price alerts use delayed public data and are informational. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List a user's alerts.
	 *
	 * @since 1.1.80
	 *
	 * @param int $user_id Current user ID.
	 * @return array
	 */
	private function execute_list( $user_id ) {
		$alerts = self::get_alerts( $user_id );

		return array(
			'success' => true,
			'action'  => 'list',
			'count'   => count( $alerts ),
			'alerts'  => $alerts,
		);
	}

	/**
	 * Delete an alert.
	 *
	 * @since 1.1.80
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error
	 */
	private function execute_delete( $arguments, $user_id ) {
		$alert_id = isset( $arguments['alert_id'] ) ? sanitize_text_field( $arguments['alert_id'] ) : '';

		if ( '' === $alert_id ) {
			return new WP_Error(
				'missing_alert_id',
				__( 'Alert ID is required for the "delete" action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$alerts = self::get_alerts( $user_id );
		$kept   = array_values(
			array_filter(
				$alerts,
				function ( $alert ) use ( $alert_id ) {
					return $alert_id !== $alert['id'];
				}
			)
		);

		if ( count( $kept ) === count( $alerts ) ) {
			return new WP_Error(
				'alert_not_found',
				__( 'Alert not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		self::set_alerts( $user_id, $kept );

		return array(
			'success'  => true,
			'action'   => 'delete',
			'alert_id' => $alert_id,
			'total'    => count( $kept ),
		);
	}

	/**
	 * Evaluate a single alert against a price.
	 *
	 * @since 1.1.80
	 *
	 * @param array $alert Alert record.
	 * @param float $price Current price.
	 * @return bool Whether the alert condition is met.
	 */
	public static function is_triggered( $alert, $price ) {
		if ( 'below' === $alert['condition'] ) {
			return $price < (float) $alert['threshold'];
		}

		return $price > (float) $alert['threshold'];
	}

	/**
	 * Whether an alert already fired today (daily re-arm).
	 *
	 * @since 1.1.80
	 *
	 * @param array $alert Alert record.
	 * @return bool
	 */
	public static function already_triggered_today( $alert ) {
		if ( empty( $alert['last_triggered_at'] ) ) {
			return false;
		}

		return gmdate( 'Y-m-d' ) === substr( (string) $alert['last_triggered_at'], 0, 10 );
	}

	/**
	 * Evaluate a user's alerts against current prices (or a supplied map).
	 *
	 * @since 1.1.80
	 *
	 * @param int        $user_id      User ID (0 = all users with alerts).
	 * @param array|null $price_override Optional map of ticker => price (tests).
	 * @return array Evaluation summary.
	 */
	public static function check_all_alerts( $user_id = 0, $price_override = null ) {
		$user_ids = array();
		if ( $user_id > 0 ) {
			$user_ids = array( $user_id );
		} else {
			global $wpdb;
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					self::OPTION_PREFIX . '%'
				)
			);
			foreach ( $rows as $option_name ) {
				$suffix = substr( $option_name, strlen( self::OPTION_PREFIX ) );
				if ( preg_match( '/^\d+$/', $suffix ) ) {
					$user_ids[] = (int) $suffix;
				}
			}
		}

		$summary = array(
			'checked'   => 0,
			'triggered' => array(),
			'errors'    => array(),
		);

		foreach ( $user_ids as $uid ) {
			$alerts = self::get_alerts( $uid );
			if ( empty( $alerts ) ) {
				continue;
			}

			$tickers = array_values( array_unique( wp_list_pluck( $alerts, 'ticker' ) ) );

			if ( null !== $price_override ) {
				$price_map = $price_override;
			} else {
				$price_map = self::fetch_price_map( $tickers );
			}

			if ( empty( $price_map ) && null === $price_override ) {
				$summary['errors'][] = __( 'No price source available; alert check skipped.', 'mcp-ai-wpoos-pro' );
				continue;
			}

			$changed = false;

			foreach ( $alerts as $index => &$alert ) {
				$ticker = $alert['ticker'];
				if ( ! isset( $price_map[ $ticker ] ) ) {
					continue;
				}

				++$summary['checked'];
				$price = (float) $price_map[ $ticker ];

				if ( self::is_triggered( $alert, $price ) && ! self::already_triggered_today( $alert ) ) {
					$alert['last_triggered_at'] = gmdate( 'Y-m-d H:i:s' );
					$alert['triggered_value']   = $price;
					$alert['trigger_count']    += 1;
					$changed                    = true;

					/**
					 * Fires when a price alert triggers.
					 *
					 * Integrations (result delivery, Telegram, email, schedules)
					 * hook here to deliver notifications.
					 *
					 * @since 1.1.80
					 *
					 * @param array $alert Alert record (with updated trigger stamps).
					 * @param int   $uid   User ID that owns the alert.
					 */
					do_action( 'wp_mcp_ai_price_alert_triggered', $alert, $uid );

					$summary['triggered'][] = array(
						'alert_id' => $alert['id'],
						'ticker'   => $ticker,
						'price'    => $price,
						'user_id'  => $uid,
					);
				}
			}
			unset( $alert );

			if ( $changed ) {
				self::set_alerts( $uid, $alerts );
			}
		}

		return $summary;
	}

	/**
	 * Fetch a ticker => price map via the resilient yfinance service.
	 *
	 * @since 1.1.80
	 *
	 * @param array $tickers Tickers.
	 * @return array Map of ticker => price.
	 */
	private static function fetch_price_map( $tickers ) {
		$map = array();

		$service_file = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
		if ( ! file_exists( $service_file ) ) {
			return $map;
		}

		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once $service_file;
		}

		$service = WP_MCP_AI_YFinance_Service::get_instance();

		if ( ! $service->is_enabled() ) {
			return $map;
		}

		$prices = $service->get_batch_prices( array_slice( $tickers, 0, 50 ) );
		if ( is_wp_error( $prices ) ) {
			return $map;
		}

		$price_map = isset( $prices['data'] ) && is_array( $prices['data'] ) ? $prices['data'] : $prices;

		foreach ( $tickers as $ticker ) {
			if ( isset( $price_map[ $ticker ] ) && isset( $price_map[ $ticker ]['current_price'] ) ) {
				$map[ $ticker ] = (float) $price_map[ $ticker ]['current_price'];
			}
		}

		return $map;
	}

	/**
	 * Schedule the daily evaluation cron (idempotent).
	 *
	 * @since 1.1.80
	 */
	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron callback: evaluate every user's alerts.
	 *
	 * @since 1.1.80
	 */
	public static function run_daily_check() {
		if ( ! self::is_available() ) {
			return;
		}

		self::check_all_alerts( 0 );
	}
}
