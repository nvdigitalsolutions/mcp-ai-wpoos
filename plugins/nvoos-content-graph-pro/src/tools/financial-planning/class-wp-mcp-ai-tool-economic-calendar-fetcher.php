<?php
/**
 * Financial toolkit ecosystem port (OpenTerminal lessons sub-cluster).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-economic-calendar-fetcher.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base
 * Pro addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined.
 *
 * What this file is: Forex Factory public economic-calendar fetcher.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `mcp-ai-wpoos-pro` → `nvoos-content-graph-pro`; `WP_MCP_AI_PRO_PATH .
 * 'includes/'` path swaps → `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for fetching the economic events calendar.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Tool_Economic_Calendar_Fetcher implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 1800;

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Economic calendar fetcher tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'economic_calendar_fetcher';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Economic Calendar Fetcher', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.80
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Fetch the economic events calendar (Fed, ECB, CPI, NFP and more) with consensus forecast and previous reading from keyless public feeds. Filter by country, currency, impact, and date. EDUCATIONAL ONLY - Data may be delayed. Not investment advice.', 'nvoos-content-graph-pro' );
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
				'currency' => array(
					'type'        => 'string',
					'description' => __( 'Filter by currency code (e.g. "USD", "EUR").', 'nvoos-content-graph-pro' ),
				),
				'country'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by country name fragment (e.g. "United States").', 'nvoos-content-graph-pro' ),
				),
				'impact'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by impact level.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'High', 'Medium', 'Low', 'Holiday' ),
				),
				'date'     => array(
					'type'        => 'string',
					'description' => __( 'Only include events on this date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return.', 'nvoos-content-graph-pro' ),
					'default'     => 50,
				),
			),
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
			'external-api',
			'cacheable',
			'network-dependent',
		);
	}

	/**
	 * Get the market data providers instance.
	 *
	 * @since 1.1.80
	 *
	 * @return WP_MCP_AI_Market_Data_Providers|WP_Error
	 */
	private function get_providers() {
		if ( ! file_exists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-market-data-providers.php' ) ) {
			return new WP_Error(
				'market_data_providers_not_found',
				__( 'Market data providers are not installed. Please ensure the pro addon is properly configured.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Market_Data_Providers' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-market-data-providers.php';
		}

		return WP_MCP_AI_Market_Data_Providers::get_instance();
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
				__( 'You do not have permission to fetch the economic calendar.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$currency = isset( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : '';
		$country  = isset( $arguments['country'] ) ? sanitize_text_field( $arguments['country'] ) : '';
		$impact   = isset( $arguments['impact'] ) ? sanitize_text_field( $arguments['impact'] ) : '';
		$date     = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		$limit    = isset( $arguments['limit'] ) ? min( max( absint( $arguments['limit'] ), 1 ), 100 ) : 50;

		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'Date must be in YYYY-MM-DD format.', 'nvoos-content-graph-pro' )
			);
		}

		$cache_key = 'wp_mcp_ai_econcal_' . md5( wp_json_encode( array( $currency, $country, $impact, $date, $limit ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$providers = $this->get_providers();
		if ( is_wp_error( $providers ) ) {
			return $providers;
		}

		$result = $providers->forex_factory_get_calendar();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = $result['events'];

		// Apply filters.
		if ( '' !== $currency ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $currency ) {
						return false !== stripos( $event['country'], $currency ) || false !== stripos( $event['title'], $currency );
					}
				)
			);
		}

		if ( '' !== $country ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $country ) {
						return false !== stripos( $event['country'], $country );
					}
				)
			);
		}

		if ( '' !== $impact ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $impact ) {
						return $impact === $event['impact'];
					}
				)
			);
		}

		if ( '' !== $date ) {
			$events = array_values(
				array_filter(
					$events,
					function ( $event ) use ( $date ) {
						return 0 === strpos( (string) $event['date'], $date );
					}
				)
			);
		}

		$events = array_slice( $events, 0, $limit );

		$envelope = array(
			'success'    => true,
			'count'      => count( $events ),
			'events'     => $events,
			'filters'    => array(
				'currency' => $currency,
				'country'  => $country,
				'impact'   => $impact,
				'date'     => $date,
			),
			'source'     => $result['source'],
			'from_cache' => false,
			'disclaimer' => __( 'EDUCATIONAL ONLY. Calendar data comes from public feeds and may be delayed or change. Actual releases may differ from forecasts. Not investment advice.', 'nvoos-content-graph-pro' ),
		);

		set_transient( $cache_key, $envelope, self::CACHE_TTL );

		return $envelope;
	}
}
