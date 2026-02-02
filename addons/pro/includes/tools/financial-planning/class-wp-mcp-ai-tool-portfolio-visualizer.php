<?php
/**
 * Portfolio Visualizer Tool
 *
 * Visualize investment portfolio allocation, performance, and diversification
 * with detailed breakdowns by asset class, sector, and risk profile.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for visualizing investment portfolios.
 *
 * Supports:
 * - Asset allocation visualization
 * - Sector diversification analysis
 * - Performance metrics calculation
 * - Risk profile assessment
 * - Historical comparison
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Portfolio_Visualizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Portfolio visualizer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'portfolio_visualizer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Portfolio Visualizer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Visualize investment portfolio allocation and performance. Supports automatic price fetching via yfinance service or manual price input. Analyze asset distribution, sector diversification, and risk metrics. EDUCATIONAL ONLY - Data may be delayed 15 minutes. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'holdings'  => array(
					'type'        => 'array',
					'description' => __( 'Portfolio holdings with ticker, shares, and current price', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'ticker'        => array(
								'type'        => 'string',
								'description' => __( 'Stock ticker symbol', 'mcp-ai-wpoos-pro' ),
							),
							'shares'        => array(
								'type'        => 'number',
								'description' => __( 'Number of shares owned', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'current_price' => array(
								'type'        => 'number',
								'description' => __( 'Current price per share', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'cost_basis'    => array(
								'type'        => 'number',
								'description' => __( 'Original purchase price per share', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'asset_class'   => array(
								'type'        => 'string',
								'description' => __( 'Asset class', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'stocks', 'bonds', 'real_estate', 'commodities', 'cash', 'crypto', 'other' ),
							),
							'sector'        => array(
								'type'        => 'string',
								'description' => __( 'Industry sector', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'auto_fetch_prices' => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically fetch current prices from yfinance service for holdings without prices', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'view_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of visualization', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'allocation', 'performance', 'diversification', 'risk_analysis' ),
					'default'     => 'allocation',
				),
			),
			'required'   => array( 'holdings' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to visualize portfolios.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$holdings          = isset( $arguments['holdings'] ) && is_array( $arguments['holdings'] ) ? $arguments['holdings'] : array();
		$view_type         = isset( $arguments['view_type'] ) ? sanitize_text_field( $arguments['view_type'] ) : 'allocation';
		$auto_fetch_prices = isset( $arguments['auto_fetch_prices'] ) ? (bool) $arguments['auto_fetch_prices'] : false;

		if ( empty( $holdings ) ) {
			return new WP_Error( 'empty_portfolio', __( 'Portfolio holdings are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Auto-fetch prices if requested and service is available.
		if ( $auto_fetch_prices && $this->is_yfinance_available() ) {
			$holdings = $this->enrich_holdings_with_prices( $holdings );
		}

		$portfolio_value   = 0;
		$total_cost_basis  = 0;
		$by_asset_class    = array();
		$by_sector         = array();
		$analyzed_holdings = array();

		foreach ( $holdings as $holding ) {
			$ticker        = isset( $holding['ticker'] ) ? sanitize_text_field( $holding['ticker'] ) : '';
			$shares        = isset( $holding['shares'] ) ? floatval( $holding['shares'] ) : 0;
			$current_price = isset( $holding['current_price'] ) ? floatval( $holding['current_price'] ) : 0;
			$cost_basis    = isset( $holding['cost_basis'] ) ? floatval( $holding['cost_basis'] ) : $current_price;
			$asset_class   = isset( $holding['asset_class'] ) ? sanitize_text_field( $holding['asset_class'] ) : 'stocks';
			$sector        = isset( $holding['sector'] ) ? sanitize_text_field( $holding['sector'] ) : 'Unknown';

			$market_value  = $shares * $current_price;
			$cost_value    = $shares * $cost_basis;
			$gain_loss     = $market_value - $cost_value;
			$gain_loss_pct = $cost_value > 0 ? ( $gain_loss / $cost_value ) * 100 : 0;

			$portfolio_value  += $market_value;
			$total_cost_basis += $cost_value;

			if ( ! isset( $by_asset_class[ $asset_class ] ) ) {
				$by_asset_class[ $asset_class ] = 0;
			}
			$by_asset_class[ $asset_class ] += $market_value;

			if ( ! isset( $by_sector[ $sector ] ) ) {
				$by_sector[ $sector ] = 0;
			}
			$by_sector[ $sector ] += $market_value;

			$analyzed_holdings[] = array(
				'ticker'        => $ticker,
				'shares'        => $shares,
				'current_price' => $current_price,
				'market_value'  => round( $market_value, 2 ),
				'cost_basis'    => round( $cost_value, 2 ),
				'gain_loss'     => round( $gain_loss, 2 ),
				'gain_loss_pct' => round( $gain_loss_pct, 2 ),
				'asset_class'   => $asset_class,
				'sector'        => $sector,
				'price_source'  => isset( $holding['price_source'] ) ? $holding['price_source'] : 'manual',
			);
		}

		$total_gain_loss  = $portfolio_value - $total_cost_basis;
		$total_return_pct = $total_cost_basis > 0 ? ( $total_gain_loss / $total_cost_basis ) * 100 : 0;

		$allocation = array();
		foreach ( $by_asset_class as $class => $value ) {
			$allocation[ $class ] = array(
				'value'      => round( $value, 2 ),
				'percentage' => round( ( $value / $portfolio_value ) * 100, 2 ),
			);
		}

		$sector_breakdown = array();
		foreach ( $by_sector as $sector => $value ) {
			$sector_breakdown[ $sector ] = array(
				'value'      => round( $value, 2 ),
				'percentage' => round( ( $value / $portfolio_value ) * 100, 2 ),
			);
		}

		return array(
			'success'          => true,
			'portfolio_value'  => round( $portfolio_value, 2 ),
			'total_cost_basis' => round( $total_cost_basis, 2 ),
			'total_gain_loss'  => round( $total_gain_loss, 2 ),
			'total_return_pct' => round( $total_return_pct, 2 ),
			'allocation'       => $allocation,
			'sector_breakdown' => $sector_breakdown,
			'holdings'         => $analyzed_holdings,
			'view_type'        => $view_type,
			'disclaimer'       => __( 'EDUCATIONAL ONLY. This visualization is for informational purposes only and does not constitute investment advice. Data from yfinance may be delayed 15 minutes or more. Past performance does not guarantee future results. Consult a licensed financial advisor.', 'mcp-ai-wpoos-pro' ),
			'message'          => sprintf(
				/* translators: 1: Portfolio value, 2: Return percentage */
				__( 'Portfolio value: $%1$s with %2$s%% total return.', 'mcp-ai-wpoos-pro' ),
				number_format( $portfolio_value, 2 ),
				number_format( $total_return_pct, 2 )
			),
		);
	}

	/**
	 * Check if yfinance service is available
	 *
	 * @return bool
	 */
	private function is_yfinance_available() {
		// Check if service file exists.
		if ( ! file_exists( WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php' ) ) {
			return false;
		}

		// Load the service class if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
		}

		// Check if service is enabled.
		$service = WP_MCP_AI_YFinance_Service::get_instance();
		return $service->is_enabled();
	}

	/**
	 * Enrich holdings with current prices from yfinance
	 *
	 * @param array $holdings Portfolio holdings.
	 * @return array Holdings with enriched price data.
	 */
	private function enrich_holdings_with_prices( $holdings ) {
		// Load the service class.
		if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
		}

		$service = WP_MCP_AI_YFinance_Service::get_instance();

		// Use the service's enrich method.
		return $service->enrich_holdings_with_prices( $holdings );
	}
}
