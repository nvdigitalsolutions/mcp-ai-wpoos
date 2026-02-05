# yfinance Integration - Usage Examples

## Overview

This document provides practical examples for using the yfinance integration in the Financial Planner Pro Toolkit.

**Status**: Implementation Complete  
**Date**: February 2, 2026  
**Version**: 1.0

## Table of Contents

1. [Setup](#setup)
2. [Portfolio Visualizer Examples](#portfolio-visualizer-examples)
3. [Direct Service Usage](#direct-service-usage)
4. [Settings Configuration](#settings-configuration)
5. [Troubleshooting](#troubleshooting)

## Setup

### Prerequisites

1. **Python yfinance Microservice Running**
   ```bash
   cd addons/pro/services/yfinance
   python3 -m venv venv
   source venv/bin/activate  # On Windows: venv\Scripts\activate
   pip install -r requirements.txt
   python app.py
   ```

2. **Node.js Installed** (version 18+)
   ```bash
   node --version  # Should show v18+ or v20+
   ```

3. **Axios Package Available** (already in Pro addon)
   ```bash
   cd addons/pro
   npm list axios  # Should show axios@^1.6.5
   ```

### WordPress Configuration

1. Navigate to **Settings → Financial Planner Toolkit → Configuration**
2. Scroll to "Market Data Integration (yfinance)" section
3. Check "Enable yfinance Service"
4. Set Service URL: `http://localhost:5000` (or your server address)
5. Set Cache Duration: `15` minutes (recommended)
6. Click "Save Changes"
7. Verify "Service Status" shows green "● Online"

## Portfolio Visualizer Examples

### Example 1: Manual Prices (Original Behavior)

**Request:**
```json
{
  "tool": "portfolio_visualizer",
  "arguments": {
    "holdings": [
      {
        "ticker": "AAPL",
        "shares": 10,
        "current_price": 150.25,
        "cost_basis": 140.00,
        "asset_class": "stocks",
        "sector": "Technology"
      },
      {
        "ticker": "GOOGL",
        "shares": 5,
        "current_price": 2800.00,
        "cost_basis": 2500.00,
        "asset_class": "stocks",
        "sector": "Technology"
      }
    ],
    "view_type": "allocation"
  }
}
```

**Response:**
```json
{
  "success": true,
  "portfolio_value": 15502.50,
  "total_cost_basis": 13900.00,
  "total_gain_loss": 1602.50,
  "total_return_pct": 11.53,
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "current_price": 150.25,
      "market_value": 1502.50,
      "gain_loss": 102.50,
      "gain_loss_pct": 7.32,
      "price_source": "manual"
    },
    {
      "ticker": "GOOGL",
      "shares": 5,
      "current_price": 2800.00,
      "market_value": 14000.00,
      "gain_loss": 1500.00,
      "gain_loss_pct": 12.00,
      "price_source": "manual"
    }
  ],
  "allocation": {
    "stocks": {
      "value": 15502.50,
      "percentage": 100.00
    }
  },
  "sector_breakdown": {
    "Technology": {
      "value": 15502.50,
      "percentage": 100.00
    }
  },
  "disclaimer": "EDUCATIONAL ONLY. Data may be delayed 15 minutes...",
  "message": "Portfolio value: $15,502.50 with 11.53% total return."
}
```

### Example 2: Auto-Fetch Prices (New Feature)

**Request:**
```json
{
  "tool": "portfolio_visualizer",
  "arguments": {
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
      },
      {
        "ticker": "MSFT",
        "shares": 8,
        "cost_basis": 300.00,
        "asset_class": "stocks",
        "sector": "Technology"
      }
    ],
    "auto_fetch_prices": true,
    "view_type": "allocation"
  }
}
```

**Response:**
```json
{
  "success": true,
  "portfolio_value": 18323.75,
  "total_cost_basis": 14900.00,
  "total_gain_loss": 3423.75,
  "total_return_pct": 22.98,
  "holdings": [
    {
      "ticker": "AAPL",
      "shares": 10,
      "current_price": 151.32,
      "market_value": 1513.20,
      "gain_loss": 113.20,
      "gain_loss_pct": 8.09,
      "price_source": "yfinance",
      "price_date": "2026-02-02"
    },
    {
      "ticker": "GOOGL",
      "shares": 5,
      "current_price": 2805.43,
      "market_value": 14027.15,
      "gain_loss": 1527.15,
      "gain_loss_pct": 12.22,
      "price_source": "yfinance",
      "price_date": "2026-02-02"
    },
    {
      "ticker": "MSFT",
      "shares": 8,
      "current_price": 347.80,
      "market_value": 2782.40,
      "gain_loss": 382.40,
      "gain_loss_pct": 15.93,
      "price_source": "yfinance",
      "price_date": "2026-02-02"
    }
  ],
  "allocation": {
    "stocks": {
      "value": 18323.75,
      "percentage": 100.00
    }
  },
  "sector_breakdown": {
    "Technology": {
      "value": 18323.75,
      "percentage": 100.00
    }
  },
  "disclaimer": "EDUCATIONAL ONLY. Data may be delayed 15 minutes...",
  "message": "Portfolio value: $18,323.75 with 22.98% total return."
}
```

**Note:** Prices are automatically fetched from yfinance for all three stocks in a single batch request.

### Example 3: Mixed Manual and Auto-Fetch

**Request:**
```json
{
  "tool": "portfolio_visualizer",
  "arguments": {
    "holdings": [
      {
        "ticker": "AAPL",
        "shares": 10,
        "current_price": 150.25,
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
    "auto_fetch_prices": true
  }
}
```

**Behavior:**
- AAPL: Uses manual price (150.25) - `price_source: "manual"`
- GOOGL: Fetches from yfinance - `price_source: "yfinance"`

**Response:**
```json
{
  "holdings": [
    {
      "ticker": "AAPL",
      "current_price": 150.25,
      "price_source": "manual",
      ...
    },
    {
      "ticker": "GOOGL",
      "current_price": 2805.43,
      "price_source": "yfinance",
      "price_date": "2026-02-02",
      ...
    }
  ]
}
```

## Direct Service Usage

### PHP Usage (In Custom Code)

```php
<?php
// Load service class
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';

// Get service instance
$yf_service = WP_MCP_AI_YFinance_Service::get_instance();

// Check if enabled
if ( ! $yf_service->is_enabled() ) {
    echo "yfinance service is not enabled.";
    return;
}

// Example 1: Get single ticker info
$ticker_info = $yf_service->get_ticker_info( 'AAPL' );
if ( ! is_wp_error( $ticker_info ) ) {
    echo "Company: " . $ticker_info['longName'] . "\n";
    echo "Sector: " . $ticker_info['sector'] . "\n";
}

// Example 2: Get current price
$price = $yf_service->get_current_price( 'AAPL' );
if ( ! is_wp_error( $price ) ) {
    echo "Current Price: $" . $price['current_price'] . "\n";
}

// Example 3: Batch prices
$tickers = array( 'AAPL', 'GOOGL', 'MSFT', 'AMZN', 'TSLA' );
$prices = $yf_service->get_batch_prices( $tickers );
if ( ! is_wp_error( $prices ) ) {
    foreach ( $prices as $ticker => $data ) {
        echo "$ticker: $" . $data['current_price'] . "\n";
    }
}

// Example 4: Price history
$history = $yf_service->get_price_history( 'AAPL', '1mo', '1d' );
if ( ! is_wp_error( $history ) ) {
    echo "Historical data points: " . count( $history['data'] ) . "\n";
}

// Example 5: Enrich holdings
$holdings = array(
    array( 'ticker' => 'AAPL', 'shares' => 10, 'cost_basis' => 140 ),
    array( 'ticker' => 'GOOGL', 'shares' => 5, 'cost_basis' => 2500 ),
);

$enriched = $yf_service->enrich_holdings_with_prices( $holdings );
foreach ( $enriched as $holding ) {
    echo $holding['ticker'] . ": $" . $holding['current_price'] . 
         " (" . $holding['price_source'] . ")\n";
}

// Example 6: Health check
$health = $yf_service->check_health();
if ( $health['success'] ) {
    echo "Service is online (version " . $health['version'] . ")\n";
} else {
    echo "Service is offline: " . $health['error'] . "\n";
}

// Example 7: Clear cache
$cleared = $yf_service->clear_all_caches();
echo "Cleared $cleared cached items.\n";
```

### JavaScript Usage (AJAX)

```javascript
// Example: Clear cache from custom admin page
jQuery(document).ready(function($) {
    $('#my-clear-cache-button').on('click', function() {
        $.post(ajaxurl, {
            action: 'wp_mcp_ai_clear_yfinance_cache',
            nonce: wpMcpAi.clearCacheNonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    });
});
```

## Settings Configuration

### Option 1: WordPress Admin UI

1. Go to **Settings → Financial Planner Toolkit**
2. Click **Configuration** tab
3. Scroll to "Market Data Integration (yfinance)"
4. Configure settings:
   - Enable yfinance Service: ✓ Checked
   - Service URL: `http://localhost:5000`
   - Cache Duration: `15` minutes
5. Click **Save Changes**

### Option 2: PHP Code

```php
<?php
// Get current settings
$settings = get_option( 'wp_mcp_ai_settings', array() );

// Enable yfinance
$settings['enable_yfinance_service'] = true;

// Set service URL
$settings['yfinance_service_url'] = 'http://localhost:5000';

// Set cache TTL (in minutes)
$settings['yfinance_cache_ttl'] = 15;

// Save settings
update_option( 'wp_mcp_ai_settings', $settings );
```

### Option 3: wp-config.php Constants

```php
// In wp-config.php
define( 'WP_MCP_AI_YFINANCE_ENABLED', true );
define( 'WP_MCP_AI_YFINANCE_URL', 'http://localhost:5000' );
define( 'WP_MCP_AI_YFINANCE_CACHE_TTL', 15 ); // minutes
```

## Troubleshooting

### Service Shows "Offline"

**Problem:** Settings page shows "● Offline" for service status.

**Solutions:**
1. Verify Python microservice is running:
   ```bash
   curl http://localhost:5000/health
   # Should return: {"status":"healthy","service":"yfinance-api",...}
   ```

2. Check service URL setting matches actual service location

3. Check firewall/network allows connections to service

4. View error logs:
   ```bash
   tail -f wp-content/debug.log
   ```

### "Node.js is not available"

**Problem:** Error message says Node.js is not available.

**Solutions:**
1. Install Node.js 18+ or 20+:
   ```bash
   node --version  # Check version
   ```

2. Ensure Node.js is in server's PATH

3. Test Node.js availability:
   ```bash
   which node
   /usr/bin/node --version
   ```

### Prices Not Auto-Fetching

**Problem:** `auto_fetch_prices: true` but prices not fetched.

**Check:**
1. Is yfinance service enabled in settings?
2. Is Python microservice running?
3. Are ticker symbols valid?
4. Check error logs for specific errors

**Debug:**
```php
<?php
$yf_service = WP_MCP_AI_YFinance_Service::get_instance();

// Check if enabled
var_dump( $yf_service->is_enabled() );  // Should be true

// Check service health
$health = $yf_service->check_health();
var_dump( $health );  // Should show success: true

// Try fetching single price
$price = $yf_service->get_current_price( 'AAPL' );
var_dump( $price );  // Should return price data
```

### Cache Issues

**Problem:** Prices not updating even though they changed.

**Solutions:**
1. Clear cache via Settings page (Click "Clear All Cached Prices")
2. Or clear via PHP:
   ```php
   $yf_service->clear_all_caches();
   ```
3. Or reduce cache TTL to shorter duration (e.g., 5 minutes)

### Rate Limiting

**Problem:** Getting rate limit errors.

**Solutions:**
1. Reduce request frequency
2. Use batch requests (up to 50 tickers per request)
3. Increase cache TTL to reduce API calls
4. Python microservice has 30 req/min limit (configurable)

### Invalid Ticker Symbols

**Problem:** Some tickers return no data.

**Check:**
1. Verify ticker symbol is correct (e.g., "AAPL" not "Apple")
2. Check if ticker is delisted or invalid
3. Try searching for ticker:
   ```php
   $results = $yf_service->search_ticker( 'Apple' );
   var_dump( $results );
   ```

## Performance Tips

1. **Use Batch Requests**
   ```php
   // ✅ GOOD: Single batch request
   $prices = $yf_service->get_batch_prices( ['AAPL', 'GOOGL', 'MSFT'] );
   
   // ❌ BAD: Multiple individual requests
   foreach ( $tickers as $ticker ) {
       $price = $yf_service->get_current_price( $ticker );
   }
   ```

2. **Cache Strategically**
   - Real-time needs: 5-15 minutes cache
   - Daily reports: 1 hour cache
   - Historical data: 24 hours cache

3. **Check Cache First**
   ```php
   // Explicitly use cache
   $price = $yf_service->get_current_price( 'AAPL', '1d', true );
   
   // Force fresh (bypass cache)
   $price = $yf_service->get_current_price( 'AAPL', '1d', false );
   ```

4. **Optimize Holdings**
   ```php
   // Let service handle batch optimization
   $enriched = $yf_service->enrich_holdings_with_prices( $holdings );
   // Service automatically batches tickers that need fetching
   ```

## Security Best Practices

1. **Validate User Input**
   ```php
   $ticker = strtoupper( sanitize_text_field( $_POST['ticker'] ) );
   ```

2. **Check Capabilities**
   ```php
   if ( ! current_user_can( 'edit_posts' ) ) {
       wp_die( 'Unauthorized' );
   }
   ```

3. **Use Nonces**
   ```php
   check_ajax_referer( 'my_action_nonce', 'nonce' );
   ```

4. **Educational Disclaimers**
   - Always display disclaimers with market data
   - Remind users data is educational only
   - Note 15-minute delay

## Next Steps

- Review [yfinance Integration Guide](YFINANCE_INTEGRATION_GUIDE.md) for architecture
- Check [Implementation Summary](YFINANCE_IMPLEMENTATION_SUMMARY.md) for technical details
- See [Python service README](../services/yfinance/README.md) for microservice setup

---

**Version**: 1.0  
**Date**: February 2, 2026  
**Author**: Financial Planner Toolkit Team
