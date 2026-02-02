# Portfolio Visualizer Tool - yfinance Integration Enhancement

## Overview

This document describes the enhancement to the Portfolio Visualizer tool to integrate with the yfinance microservice for real-time price fetching.

## Current Implementation

The current `WP_MCP_AI_Tool_Portfolio_Visualizer` tool:
- Accepts manual price input for each holding
- Requires users to provide `current_price` for each ticker
- No automatic price fetching capability
- Static portfolio snapshots only

## Enhanced Implementation with yfinance

### New Features

1. **Automatic Price Fetching**: Fetch real-time prices for tickers
2. **Batch Price Updates**: Update entire portfolio in single request
3. **Price History**: View portfolio value over time
4. **Smart Caching**: Cache prices to reduce API calls
5. **Fallback to Manual**: Still support manual price input

### Architecture

```
Portfolio Visualizer Tool (PHP)
    ↓
Check if yfinance service enabled
    ↓
    Yes → Fetch from yfinance microservice (with cache)
    ↓
    No → Use manual prices (current behavior)
```

### Implementation Details

#### 1. Add yfinance Service Helper Methods

Add private methods to the Portfolio Visualizer class:

```php
/**
 * Check if yfinance service is enabled and available.
 *
 * @return bool True if service is available.
 */
private function is_yfinance_enabled() {
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    return ! empty( $settings['enable_yfinance_service'] ) && 
           ! empty( $settings['yfinance_service_url'] );
}

/**
 * Get yfinance service URL.
 *
 * @return string Service URL.
 */
private function get_yfinance_service_url() {
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    return isset( $settings['yfinance_service_url'] ) 
        ? trailingslashit( $settings['yfinance_service_url'] )
        : 'http://localhost:5000/';
}

/**
 * Fetch current prices for multiple tickers from yfinance service.
 *
 * @param array $tickers Array of ticker symbols.
 * @return array|WP_Error Array of prices or error.
 */
private function fetch_prices_batch( $tickers ) {
    if ( empty( $tickers ) ) {
        return array();
    }
    
    // Check WordPress transient cache first (15 min cache)
    $cache_key = 'yfinance_batch_' . md5( implode( '_', $tickers ) );
    $cached    = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return $cached;
    }
    
    $service_url = $this->get_yfinance_service_url() . 'prices';
    
    $response = wp_remote_post(
        $service_url,
        array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'tickers' => $tickers,
                'period'  => '1d',
            ) ),
            'timeout' => 15,
        )
    );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status_code ) {
        return new WP_Error(
            'yfinance_api_error',
            sprintf( __( 'yfinance service returned status %d', 'mcp-ai-wpoos-pro' ), $status_code )
        );
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( null === $data || ! isset( $data['data'] ) ) {
        return new WP_Error(
            'invalid_response',
            __( 'Invalid response from yfinance service', 'mcp-ai-wpoos-pro' )
        );
    }
    
    // Cache for 15 minutes
    set_transient( $cache_key, $data['data'], 15 * MINUTE_IN_SECONDS );
    
    return $data['data'];
}

/**
 * Fetch single ticker price from yfinance service.
 *
 * @param string $ticker Ticker symbol.
 * @return float|WP_Error Current price or error.
 */
private function fetch_ticker_price( $ticker ) {
    // Check WordPress transient cache first
    $cache_key = 'yfinance_price_' . sanitize_key( $ticker );
    $cached    = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return $cached;
    }
    
    $service_url = $this->get_yfinance_service_url() . 'price/' . urlencode( strtoupper( $ticker ) );
    
    $response = wp_remote_get(
        $service_url,
        array(
            'headers' => array( 'Accept' => 'application/json' ),
            'timeout' => 10,
        )
    );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status_code ) {
        return new WP_Error(
            'yfinance_api_error',
            sprintf( __( 'Failed to fetch price for %s', 'mcp-ai-wpoos-pro' ), $ticker )
        );
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( null === $data || ! isset( $data['current_price'] ) ) {
        return new WP_Error(
            'invalid_price_data',
            sprintf( __( 'Invalid price data for %s', 'mcp-ai-wpoos-pro' ), $ticker )
        );
    }
    
    $price = (float) $data['current_price'];
    
    // Cache for 15 minutes
    set_transient( $cache_key, $price, 15 * MINUTE_IN_SECONDS );
    
    return $price;
}
```

#### 2. Update execute() Method Logic

Modify the `execute()` method to support automatic price fetching:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... existing validation code ...
    
    $holdings  = isset( $arguments['holdings'] ) && is_array( $arguments['holdings'] ) ? $arguments['holdings'] : array();
    $view_type = isset( $arguments['view_type'] ) ? sanitize_text_field( $arguments['view_type'] ) : 'allocation';
    $auto_fetch_prices = isset( $arguments['auto_fetch_prices'] ) ? (bool) $arguments['auto_fetch_prices'] : false;
    
    if ( empty( $holdings ) ) {
        return new WP_Error( 'empty_portfolio', __( 'Portfolio holdings are required.', 'mcp-ai-wpoos-pro' ) );
    }
    
    // Auto-fetch prices if requested and service available
    if ( $auto_fetch_prices && $this->is_yfinance_enabled() ) {
        $holdings = $this->enrich_holdings_with_prices( $holdings );
    }
    
    // ... rest of existing logic ...
}

/**
 * Enrich holdings with current prices from yfinance.
 *
 * @param array $holdings Portfolio holdings.
 * @return array Holdings with updated prices.
 */
private function enrich_holdings_with_prices( $holdings ) {
    // Extract tickers that need price fetching
    $tickers_to_fetch = array();
    foreach ( $holdings as $holding ) {
        if ( empty( $holding['current_price'] ) && ! empty( $holding['ticker'] ) ) {
            $tickers_to_fetch[] = strtoupper( $holding['ticker'] );
        }
    }
    
    if ( empty( $tickers_to_fetch ) ) {
        return $holdings; // All holdings have prices
    }
    
    // Fetch prices in batch
    $prices = $this->fetch_prices_batch( $tickers_to_fetch );
    
    if ( is_wp_error( $prices ) ) {
        // Log error but continue with manual prices
        error_log( 'yfinance price fetch error: ' . $prices->get_error_message() );
        return $holdings;
    }
    
    // Update holdings with fetched prices
    foreach ( $holdings as &$holding ) {
        $ticker = strtoupper( $holding['ticker'] );
        if ( empty( $holding['current_price'] ) && isset( $prices[ $ticker ] ) ) {
            $holding['current_price'] = $prices[ $ticker ]['current_price'];
            $holding['price_source'] = 'yfinance';
            $holding['price_date'] = $prices[ $ticker ]['date'] ?? gmdate( 'Y-m-d' );
        } elseif ( ! empty( $holding['current_price'] ) ) {
            $holding['price_source'] = 'manual';
        }
    }
    
    return $holdings;
}
```

#### 3. Update Parameters Schema

Add `auto_fetch_prices` parameter:

```php
public function get_parameters_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            'holdings'          => array(
                // ... existing schema ...
            ),
            'auto_fetch_prices' => array(
                'type'        => 'boolean',
                'description' => __( 'Automatically fetch current prices from yfinance service', 'mcp-ai-wpoos-pro' ),
                'default'     => false,
            ),
            'view_type'         => array(
                // ... existing schema ...
            ),
        ),
        'required'   => array( 'holdings' ),
    );
}
```

#### 4. Update Tool Description

```php
public function get_description() {
    return __( 'Visualize investment portfolio allocation and performance. Supports automatic price fetching via yfinance service or manual price input. Analyze asset distribution, sector diversification, and risk metrics. EDUCATIONAL ONLY - Data may be delayed 15 minutes. Not investment advice.', 'mcp-ai-wpoos-pro' );
}
```

### Settings Integration

Add new settings to the Financial Planner settings page:

```php
// In class-wp-mcp-ai-financial-planner-settings-page.php

add_settings_field(
    'enable_yfinance_service',
    __( 'Enable yfinance Service', 'mcp-ai-wpoos-pro' ),
    array( $this, 'render_checkbox_field' ),
    'wp_mcp_ai_financial_planner_settings',
    'wp_mcp_ai_financial_planner_general',
    array(
        'label_for' => 'enable_yfinance_service',
        'field'     => 'enable_yfinance_service',
        'description' => __( 'Enable automatic price fetching from yfinance microservice', 'mcp-ai-wpoos-pro' ),
    )
);

add_settings_field(
    'yfinance_service_url',
    __( 'yfinance Service URL', 'mcp-ai-wpoos-pro' ),
    array( $this, 'render_text_field' ),
    'wp_mcp_ai_financial_planner_settings',
    'wp_mcp_ai_financial_planner_general',
    array(
        'label_for' => 'yfinance_service_url',
        'field'     => 'yfinance_service_url',
        'description' => __( 'URL of the yfinance microservice (e.g., http://localhost:5000)', 'mcp-ai-wpoos-pro' ),
        'default'   => 'http://localhost:5000',
    )
);
```

### Usage Examples

#### Example 1: Portfolio with Manual Prices (Existing Behavior)

```json
{
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "current_price": 150.25,
      "cost_basis": 140.00,
      "asset_class": "stocks",
      "sector": "Technology"
    }
  ],
  "view_type": "allocation"
}
```

#### Example 2: Portfolio with Auto-Fetch Prices (New Feature)

```json
{
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "cost_basis": 140.00,
      "asset_class": "stocks",
      "sector": "Technology"
    },
    {
      "ticker": "GOOGL",
      "shares": 5,
      "cost_basis": 2500.00,
      "asset_class": "stocks",
      "sector": "Technology"
    }
  ],
  "auto_fetch_prices": true,
  "view_type": "allocation"
}
```

The tool will automatically:
1. Detect missing `current_price` fields
2. Extract tickers to fetch
3. Make batch request to yfinance service
4. Update holdings with current prices
5. Proceed with portfolio analysis

#### Example 3: Mixed Manual and Auto-Fetch

```json
{
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "current_price": 150.25,
      "cost_basis": 140.00
    },
    {
      "ticker": "GOOGL",
      "shares": 5,
      "cost_basis": 2500.00
    }
  ],
  "auto_fetch_prices": true
}
```

Result:
- AAPL: Uses manual price (150.25)
- GOOGL: Fetches current price from yfinance

### Response Format

Enhanced response includes price source information:

```json
{
  "success": true,
  "portfolio_value": 10523.75,
  "total_gain_loss": 523.75,
  "total_return_pct": 5.24,
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "current_price": 150.25,
      "market_value": 1502.50,
      "price_source": "manual",
      "...": "..."
    },
    {
      "ticker": "GOOGL",
      "shares": 5,
      "current_price": 2804.45,
      "market_value": 14022.25,
      "price_source": "yfinance",
      "price_date": "2026-02-02",
      "...": "..."
    }
  ],
  "data_disclaimer": "EDUCATIONAL ONLY. Prices from yfinance may be delayed 15 minutes...",
  "message": "Portfolio analyzed successfully with real-time price data"
}
```

### Error Handling

#### When yfinance Service is Unavailable

1. Tool logs error
2. Falls back to manual prices
3. Adds warning to response:

```json
{
  "success": true,
  "warning": "Unable to fetch real-time prices. Using manual input only.",
  "...": "..."
}
```

#### When Some Tickers Fail

```json
{
  "success": true,
  "price_fetch_errors": [
    {
      "ticker": "INVALID",
      "error": "No data found for ticker"
    }
  ],
  "...": "..."
}
```

### Benefits

1. **User Experience**: No manual price entry for every ticker
2. **Accuracy**: Real-time (delayed 15min) market data
3. **Efficiency**: Batch requests reduce API calls
4. **Flexibility**: Still supports manual prices
5. **Caching**: Reduces redundant API calls
6. **Resilience**: Graceful fallback when service unavailable

### Implementation Checklist

- [ ] Add helper methods for yfinance service integration
- [ ] Update execute() method to support auto-fetch
- [ ] Add auto_fetch_prices parameter to schema
- [ ] Update tool description with data disclaimer
- [ ] Add settings for yfinance service configuration
- [ ] Add WordPress transient caching layer
- [ ] Implement error handling and fallback logic
- [ ] Add price source tracking in response
- [ ] Update documentation
- [ ] Add unit tests for new functionality
- [ ] Test with yfinance service running
- [ ] Test fallback when service unavailable

### Testing

#### Unit Tests

```php
public function test_portfolio_visualizer_auto_fetch() {
    $tool = new WP_MCP_AI_Tool_Portfolio_Visualizer();
    
    $arguments = array(
        'holdings' => array(
            array(
                'ticker' => 'AAPL',
                'shares' => 10,
                'cost_basis' => 140.00,
            ),
        ),
        'auto_fetch_prices' => true,
    );
    
    $result = $tool->execute( $arguments, array() );
    
    $this->assertTrue( $result['success'] );
    $this->assertArrayHasKey( 'price_source', $result['holdings'][0] );
}
```

### Security Considerations

- ✅ Validate all ticker symbols before API calls
- ✅ Rate limit enforcement (handled by microservice)
- ✅ Cache to reduce API calls
- ✅ Sanitize all input
- ✅ Use wp_remote_* functions (not cURL directly)
- ✅ Check user capabilities
- ✅ Display educational disclaimers

### Performance

- **Caching**: 15-minute WordPress transient cache
- **Batch Requests**: Single API call for multiple tickers
- **Timeout**: 15-second timeout for batch, 10-second for single
- **Fallback**: Graceful degradation to manual prices

### Future Enhancements

1. **Historical Portfolio Value**: Track portfolio value over time
2. **Price Alerts**: Notify when holdings reach target prices
3. **Dividend Tracking**: Include dividend data from yfinance
4. **Performance Attribution**: Analyze which holdings performed best
5. **Rebalancing Suggestions**: Based on current prices vs targets

---

**Status**: Enhancement Specification  
**Version**: 1.0  
**Date**: February 2, 2026  
**Next Steps**: Implement changes in Portfolio Visualizer tool
