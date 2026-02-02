# yfinance Integration Guide - Financial Planner Pro Toolkit

## Executive Summary

This guide provides best practices for integrating the yfinance library (https://github.com/ranaroussi/yfinance) with the Financial Planner Pro Toolkit. yfinance provides free access to Yahoo Finance market data, making it ideal for educational financial planning tools.

**Status**: Integration Architecture & Best Practices  
**Version**: 1.0  
**Date**: February 2, 2026  
**Purpose**: Educational market data integration for portfolio visualization and financial planning

## Table of Contents

1. [Overview](#overview)
2. [Industry Best Practices (2026)](#industry-best-practices-2026)
3. [Architecture Strategy](#architecture-strategy)
4. [Implementation Guidelines](#implementation-guidelines)
5. [Security & Compliance](#security--compliance)
6. [Performance Optimization](#performance-optimization)
7. [Error Handling](#error-handling)
8. [Testing Strategy](#testing-strategy)
9. [Future Enhancements](#future-enhancements)

## Overview

### What is yfinance?

yfinance is a Python library that provides a simple interface to Yahoo Finance data:
- **Free & Open Source**: No API keys required
- **Rich Data**: OHLCV prices, dividends, splits, fundamentals, earnings
- **Educational Purpose**: Designed for research and educational use
- **Active Maintenance**: Regular updates and community support

### Why yfinance for Financial Planner Toolkit?

✅ **Advantages**:
- Free access to market data (no subscription costs)
- Easy integration with Python microservices
- Comprehensive data coverage (stocks, ETFs, indices, crypto)
- Consistent with toolkit's educational focus
- Aligns with WordPress plugin limitations

❌ **Limitations**:
- Educational use only (not for commercial redistribution)
- Rate limits apply (undocumented but enforced)
- 15-minute delayed data (not real-time)
- No SLA or uptime guarantees
- Should not be sole data source for mission-critical systems

## Industry Best Practices (2026)

### 1. Data Handling

**Batch Requests**:
```python
import yfinance as yf

# ✅ GOOD: Batch multiple tickers in single request
tickers = ["AAPL", "GOOGL", "MSFT", "TSLA"]
data = yf.download(tickers, period="1d", group_by='ticker')

# ❌ BAD: Individual requests for each ticker
for ticker in tickers:
    data = yf.download(ticker, period="1d")  # Rate limit risk
```

**Ticker Objects for Deep Analysis**:
```python
# For detailed company information
ticker = yf.Ticker("AAPL")
info = ticker.info  # Company metadata
history = ticker.history(period="1mo")  # Price history
dividends = ticker.dividends  # Dividend history
```

**Error Handling**:
```python
import yfinance as yf
from requests.exceptions import HTTPError, ConnectionError

try:
    ticker = yf.Ticker("INVALID")
    data = ticker.history(period="1d")
    if data.empty:
        # Handle invalid/delisted ticker
        raise ValueError(f"No data returned for ticker")
except (HTTPError, ConnectionError) as e:
    # Handle network/API errors
    logger.error(f"yfinance API error: {e}")
    # Fallback to cached data or error response
except Exception as e:
    # Handle unexpected errors
    logger.error(f"Unexpected error: {e}")
```

### 2. Caching Strategy

**Why Caching Matters**:
- Reduces API calls and rate limit risk
- Improves performance (faster response times)
- Provides resilience (fallback when API unavailable)
- Lowers bandwidth usage

**Built-in yfinance Caching**:
yfinance uses SQLite-based caching for:
- Timezone data
- Authentication cookies
- Security identifier mappings

**Recommended Additional Caching**:
```python
import yfinance as yf
from datetime import datetime, timedelta
import json
import os

class YFinanceCache:
    """Cache wrapper for yfinance with TTL support."""
    
    def __init__(self, cache_dir="/tmp/yfinance_cache", ttl_hours=1):
        self.cache_dir = cache_dir
        self.ttl = timedelta(hours=ttl_hours)
        os.makedirs(cache_dir, exist_ok=True)
    
    def get_cache_path(self, ticker, data_type):
        """Generate cache file path."""
        return os.path.join(self.cache_dir, f"{ticker}_{data_type}.json")
    
    def is_cache_valid(self, cache_path):
        """Check if cache file exists and is not expired."""
        if not os.path.exists(cache_path):
            return False
        
        mtime = datetime.fromtimestamp(os.path.getmtime(cache_path))
        return datetime.now() - mtime < self.ttl
    
    def get_ticker_data(self, ticker, period="1d", force_refresh=False):
        """Get ticker data with caching."""
        cache_path = self.get_cache_path(ticker, f"history_{period}")
        
        # Check cache first
        if not force_refresh and self.is_cache_valid(cache_path):
            with open(cache_path, 'r') as f:
                return json.load(f)
        
        # Fetch fresh data
        try:
            ticker_obj = yf.Ticker(ticker)
            data = ticker_obj.history(period=period)
            
            if not data.empty:
                # Cache the result
                result = data.to_dict('records')
                with open(cache_path, 'w') as f:
                    json.dump(result, f)
                return result
            else:
                return None
                
        except Exception as e:
            # Try to return stale cache on error
            if os.path.exists(cache_path):
                with open(cache_path, 'r') as f:
                    return json.load(f)
            raise e
```

**Cache TTL Recommendations**:
- **Real-time portfolio**: 5-15 minutes (balance freshness vs rate limits)
- **Daily summaries**: 1 hour (updated once per hour)
- **Historical analysis**: 24 hours (data doesn't change)
- **Company info**: 7 days (rarely changes)

### 3. Rate Limiting

**Yahoo Finance Rate Limits** (undocumented but observed):
- ~2,000 requests per hour per IP
- ~48 requests per minute per IP
- Excessive requests trigger 429 "Too Many Requests" errors

**Best Practices**:
```python
import time
import yfinance as yf
from functools import wraps

def rate_limit(calls_per_minute=30):
    """Decorator to rate limit function calls."""
    min_interval = 60.0 / calls_per_minute
    last_called = [0.0]
    
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            elapsed = time.time() - last_called[0]
            left_to_wait = min_interval - elapsed
            
            if left_to_wait > 0:
                time.sleep(left_to_wait)
            
            result = func(*args, **kwargs)
            last_called[0] = time.time()
            return result
        return wrapper
    return decorator

@rate_limit(calls_per_minute=30)
def fetch_ticker_data(ticker):
    """Rate-limited ticker data fetcher."""
    return yf.Ticker(ticker).info

# Usage
for ticker in ["AAPL", "GOOGL", "MSFT"]:
    data = fetch_ticker_data(ticker)  # Automatically rate limited
```

**Exponential Backoff for Retries**:
```python
import time
from requests.exceptions import HTTPError

def fetch_with_retry(ticker, max_retries=3):
    """Fetch with exponential backoff."""
    for attempt in range(max_retries):
        try:
            data = yf.Ticker(ticker).history(period="1d")
            if not data.empty:
                return data
        except HTTPError as e:
            if e.response.status_code == 429:  # Rate limited
                wait_time = (2 ** attempt) * 2  # 2, 4, 8 seconds
                time.sleep(wait_time)
            else:
                raise e
    
    raise Exception(f"Failed to fetch data for {ticker} after {max_retries} attempts")
```

### 4. Security Considerations

**Data Privacy**:
- ✅ No user authentication required (public data only)
- ✅ No personal information transmitted
- ✅ Use HTTPS for all requests (enforced by yfinance)

**Dependencies**:
- ⚠️ Keep yfinance updated (security patches)
- ⚠️ Monitor for CVEs in dependencies
- ⚠️ Use virtual environments to isolate packages

**Credential Security**:
- ✅ No API keys required (public data)
- ✅ No password storage needed
- ✅ Cache data can be stored unencrypted (public market data)

**Updates**:
```bash
# Regular updates recommended
pip install --upgrade yfinance

# Check for security vulnerabilities
pip install safety
safety check
```

### 5. Data Validation

**Always Validate Data**:
```python
def validate_ticker_data(data, ticker):
    """Validate yfinance data before use."""
    if data is None:
        raise ValueError(f"No data returned for {ticker}")
    
    if isinstance(data, pd.DataFrame) and data.empty:
        raise ValueError(f"Empty dataframe for {ticker}")
    
    # Check for required columns
    required_cols = ['Open', 'High', 'Low', 'Close', 'Volume']
    if isinstance(data, pd.DataFrame):
        missing = [col for col in required_cols if col not in data.columns]
        if missing:
            raise ValueError(f"Missing columns for {ticker}: {missing}")
    
    return True

# Usage
ticker_data = yf.Ticker("AAPL").history(period="1d")
if validate_ticker_data(ticker_data, "AAPL"):
    # Safe to use data
    current_price = ticker_data['Close'].iloc[-1]
```

## Architecture Strategy

### Integration Pattern: Python Microservice

The Financial Planner Toolkit already uses Node.js microservices for complex calculations. We'll follow the same pattern for yfinance integration.

**Architecture Overview**:
```
WordPress Plugin (PHP)
    ↓ HTTP Request
Python Microservice (Flask/FastAPI)
    ↓ API Call
yfinance Library
    ↓ HTTP Request
Yahoo Finance API
```

**Benefits**:
- ✅ Separation of concerns (PHP for WordPress, Python for data)
- ✅ Language-appropriate tools (Python for financial libraries)
- ✅ Scalability (microservice can be deployed separately)
- ✅ Caching at microservice level (shared across requests)
- ✅ Rate limiting centralized (prevent abuse)

### Microservice Structure

**Directory Layout**:
```
addons/pro/services/yfinance/
├── requirements.txt          # Python dependencies
├── app.py                    # Flask/FastAPI application
├── cache.py                  # Caching implementation
├── rate_limiter.py           # Rate limiting logic
├── validators.py             # Data validation
├── README.md                 # Service documentation
└── tests/
    ├── test_api.py
    └── test_cache.py
```

**Sample Microservice** (Flask):
```python
# app.py
from flask import Flask, jsonify, request
import yfinance as yf
from cache import YFinanceCache
from rate_limiter import rate_limit
import logging

app = Flask(__name__)
cache = YFinanceCache()
logger = logging.getLogger(__name__)

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint."""
    return jsonify({"status": "healthy", "service": "yfinance-api"})

@app.route('/ticker/<symbol>', methods=['GET'])
@rate_limit(calls_per_minute=30)
def get_ticker_info(symbol):
    """Get ticker information."""
    try:
        # Check cache first
        cached = cache.get_ticker_data(symbol, data_type='info')
        if cached:
            return jsonify(cached)
        
        # Fetch fresh data
        ticker = yf.Ticker(symbol.upper())
        info = ticker.info
        
        # Cache result
        cache.set_ticker_data(symbol, 'info', info)
        
        return jsonify(info)
        
    except Exception as e:
        logger.error(f"Error fetching {symbol}: {e}")
        return jsonify({"error": str(e)}), 500

@app.route('/price', methods=['POST'])
@rate_limit(calls_per_minute=30)
def get_prices():
    """Get current prices for multiple tickers."""
    try:
        tickers = request.json.get('tickers', [])
        period = request.json.get('period', '1d')
        
        if not tickers:
            return jsonify({"error": "No tickers provided"}), 400
        
        # Batch request for efficiency
        data = yf.download(tickers, period=period, group_by='ticker')
        
        result = {}
        for ticker in tickers:
            if len(tickers) == 1:
                ticker_data = data
            else:
                ticker_data = data[ticker]
            
            if not ticker_data.empty:
                result[ticker] = {
                    "current_price": float(ticker_data['Close'].iloc[-1]),
                    "open": float(ticker_data['Open'].iloc[-1]),
                    "high": float(ticker_data['High'].iloc[-1]),
                    "low": float(ticker_data['Low'].iloc[-1]),
                    "volume": int(ticker_data['Volume'].iloc[-1])
                }
        
        return jsonify(result)
        
    except Exception as e:
        logger.error(f"Error fetching prices: {e}")
        return jsonify({"error": str(e)}), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
```

**requirements.txt**:
```
yfinance>=0.2.40
Flask>=3.0.0
requests>=2.31.0
pandas>=2.0.0
numpy>=1.24.0
```

### PHP Integration

**PHP HTTP Client** (in WordPress tool):
```php
/**
 * Fetch ticker data from yfinance microservice.
 *
 * @param string $ticker Stock ticker symbol.
 * @return array|WP_Error Ticker data or error.
 */
private function fetch_ticker_data( $ticker ) {
    // Check WordPress transient cache first (additional caching layer)
    $cache_key = 'yfinance_' . sanitize_key( $ticker );
    $cached    = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return $cached;
    }
    
    // Microservice URL (configurable in settings)
    $service_url = get_option( 'wp_mcp_ai_yfinance_service_url', 'http://localhost:5000' );
    $url         = trailingslashit( $service_url ) . 'ticker/' . urlencode( strtoupper( $ticker ) );
    
    // Make request
    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        )
    );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status_code ) {
        return new WP_Error(
            'yfinance_api_error',
            sprintf( __( 'yfinance API returned status %d', 'mcp-ai-wpoos-pro' ), $status_code )
        );
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( null === $data ) {
        return new WP_Error( 'invalid_json', __( 'Invalid JSON response', 'mcp-ai-wpoos-pro' ) );
    }
    
    // Cache for 15 minutes (WordPress transient)
    set_transient( $cache_key, $data, 15 * MINUTE_IN_SECONDS );
    
    return $data;
}
```

## Implementation Guidelines

### Phase 1: Service Setup

1. **Create Python microservice directory**:
   ```bash
   mkdir -p addons/pro/services/yfinance
   cd addons/pro/services/yfinance
   ```

2. **Create virtual environment**:
   ```bash
   python3 -m venv venv
   source venv/bin/activate  # Linux/Mac
   # or: venv\Scripts\activate  # Windows
   ```

3. **Install dependencies**:
   ```bash
   pip install -r requirements.txt
   ```

4. **Test microservice**:
   ```bash
   python app.py
   # Visit: http://localhost:5000/health
   ```

### Phase 2: Tool Enhancement

1. **Update Portfolio Visualizer tool** to fetch real-time prices
2. **Create Market Data Fetcher tool** for batch ticker lookups
3. **Add Stock Price Lookup tool** for individual stocks

### Phase 3: Configuration

1. **Add settings page** for yfinance configuration:
   - Microservice URL
   - Enable/disable real-time data
   - Cache TTL settings
   - Rate limit configuration

2. **Fallback mechanism** when service unavailable:
   - Use manual price input
   - Use last cached values
   - Show clear error messages

### Phase 4: Testing & Documentation

1. **Unit tests** for microservice
2. **Integration tests** for PHP tools
3. **User documentation** for setup
4. **Developer documentation** for extending

## Security & Compliance

### Educational Use Disclaimers

**Required Disclaimers** (must be displayed prominently):

```
EDUCATIONAL PURPOSES ONLY
This tool uses market data from Yahoo Finance for educational purposes only.
Data may be delayed by 15 minutes or more. This is not real-time market data
and should not be used for actual trading decisions.

NOT INVESTMENT ADVICE
Historical and current price data is for informational purposes only and does
not constitute investment advice. Consult a licensed financial advisor before
making any investment decisions.

DATA ACCURACY
While we strive for accuracy, we cannot guarantee the completeness or accuracy
of market data. Past performance does not guarantee future results.
```

### Terms of Use Compliance

**Yahoo Finance Terms**:
- ✅ Educational and personal use allowed
- ❌ Commercial redistribution prohibited
- ❌ High-frequency trading not allowed
- ❌ Cannot present as real-time data

**Plugin Implementation**:
- Display disclaimers on all market data tools
- Label data as "delayed" or "educational"
- Include data source attribution ("Data provided by Yahoo Finance")
- Implement rate limiting to respect API limits
- Do not cache data longer than reasonable (24-48 hours max)

### GDPR Compliance

**Data Collected**:
- ✅ No personal data collected (public market data only)
- ✅ No user authentication required
- ✅ No tracking or analytics on microservice

**Privacy Considerations**:
- Cache data is public market data (no privacy concerns)
- No need for user consent (public data)
- No data export requirements (no personal data)

## Performance Optimization

### Caching Layers

**Multi-Level Caching**:
```
Request → WordPress Transient (15 min)
    ↓ Miss
Python Microservice Cache (1 hour)
    ↓ Miss
yfinance Built-in Cache (varies)
    ↓ Miss
Yahoo Finance API
```

**Benefits**:
- Reduces API calls dramatically
- Improves response time (< 50ms for cached)
- Protects against rate limits
- Provides resilience during outages

### Batch Requests

**Always batch when possible**:
```python
# ✅ GOOD: Single request for 10 stocks
tickers = ["AAPL", "GOOGL", "MSFT", "AMZN", "TSLA", "FB", "NVDA", "AMD", "INTC", "IBM"]
data = yf.download(tickers, period="1d")

# ❌ BAD: 10 separate requests
for ticker in tickers:
    data = yf.download(ticker, period="1d")  # 10x API calls!
```

### Async Processing

**For large portfolios** (20+ holdings):
```python
import asyncio
import aiohttp

async def fetch_ticker_async(session, ticker):
    """Async ticker fetch."""
    url = f"http://localhost:5000/ticker/{ticker}"
    async with session.get(url) as response:
        return await response.json()

async def fetch_portfolio_async(tickers):
    """Fetch entire portfolio asynchronously."""
    async with aiohttp.ClientSession() as session:
        tasks = [fetch_ticker_async(session, t) for t in tickers]
        return await asyncio.gather(*tasks)

# Usage
tickers = ["AAPL", "GOOGL", "MSFT", ...]  # 50 stocks
results = asyncio.run(fetch_portfolio_async(tickers))
```

## Error Handling

### Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| `429 Too Many Requests` | Rate limit exceeded | Implement backoff, use caching |
| `Empty DataFrame` | Invalid ticker | Validate ticker before request |
| `Connection Timeout` | Network issue | Retry with exponential backoff |
| `JSONDecodeError` | Invalid response | Validate response, use fallback |
| `KeyError` | Missing data field | Check data structure, use .get() |

### Graceful Degradation

**Fallback Strategy**:
1. Try microservice
2. If fails, use WordPress cached data
3. If no cache, use manual input
4. Show clear error message to user

**Example**:
```php
$price = $this->fetch_ticker_data( $ticker );

if ( is_wp_error( $price ) ) {
    // Fallback to cached data
    $cached = $this->get_cached_price( $ticker );
    
    if ( $cached ) {
        return array(
            'success' => true,
            'price'   => $cached,
            'source'  => 'cache',
            'warning' => __( 'Using cached data (service unavailable)', 'mcp-ai-wpoos-pro' ),
        );
    }
    
    // No cache, require manual input
    return new WP_Error(
        'no_price_data',
        __( 'Unable to fetch price. Please enter manually.', 'mcp-ai-wpoos-pro' )
    );
}
```

## Testing Strategy

### Unit Tests

**Microservice Tests** (pytest):
```python
# tests/test_api.py
import pytest
from app import app

@pytest.fixture
def client():
    app.config['TESTING'] = True
    with app.test_client() as client:
        yield client

def test_health(client):
    """Test health endpoint."""
    response = client.get('/health')
    assert response.status_code == 200
    assert response.json['status'] == 'healthy'

def test_ticker_info(client):
    """Test ticker info endpoint."""
    response = client.get('/ticker/AAPL')
    assert response.status_code == 200
    assert 'symbol' in response.json
    assert response.json['symbol'] == 'AAPL'

def test_invalid_ticker(client):
    """Test invalid ticker handling."""
    response = client.get('/ticker/INVALID123')
    # Should handle gracefully
    assert response.status_code in [200, 404, 500]
```

**PHP Tests** (PHPUnit):
```php
class Test_YFinance_Integration extends WP_UnitTestCase {
    
    public function test_fetch_ticker_data() {
        $tool = new WP_MCP_AI_Tool_Portfolio_Visualizer();
        $data = $tool->fetch_ticker_data( 'AAPL' );
        
        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'symbol', $data );
        $this->assertEquals( 'AAPL', $data['symbol'] );
    }
    
    public function test_caching() {
        $tool = new WP_MCP_AI_Tool_Portfolio_Visualizer();
        
        // First call (fresh data)
        $data1 = $tool->fetch_ticker_data( 'AAPL' );
        
        // Second call (should be cached)
        $data2 = $tool->fetch_ticker_data( 'AAPL' );
        
        $this->assertEquals( $data1, $data2 );
    }
}
```

### Integration Tests

**End-to-End Test**:
1. Start microservice
2. Make request from PHP
3. Verify data returned
4. Check cache population
5. Verify rate limiting

## Future Enhancements

### Potential Improvements

1. **Real-Time Data Streams**:
   - WebSocket integration for live prices
   - Server-Sent Events (SSE) for updates
   - Requires premium data source

2. **Advanced Analytics**:
   - Technical indicators (RSI, MACD, Bollinger Bands)
   - Risk metrics (beta, alpha, Sharpe ratio)
   - Portfolio optimization (Modern Portfolio Theory)

3. **Historical Analysis**:
   - Backtesting capabilities
   - Performance attribution
   - Scenario analysis

4. **Alternative Data Sources**:
   - Alpha Vantage (free tier: 5 calls/min, 500/day)
   - IEX Cloud (free tier: 50K messages/month)
   - Polygon.io (free tier: delayed data)
   - Blend data from multiple sources

5. **Machine Learning**:
   - Price prediction models
   - Anomaly detection
   - Sentiment analysis (news, social media)

## Conclusion

Integrating yfinance with the Financial Planner Pro Toolkit provides users with free, educational market data while maintaining proper security, performance, and compliance standards. By following these best practices, the toolkit can offer robust portfolio visualization and financial planning features without expensive data subscriptions.

### Key Takeaways

✅ **Do**:
- Use yfinance for educational purposes only
- Implement multi-level caching (15 min to 24 hours)
- Rate limit requests (30/min, batch when possible)
- Validate all data before use
- Display disclaimers prominently
- Keep dependencies updated

❌ **Don't**:
- Use for commercial redistribution
- Present as real-time data
- Exceed rate limits (respect API)
- Store credentials (not needed)
- Skip error handling
- Ignore terms of use

### Next Steps

1. Review and approve integration architecture
2. Set up Python microservice infrastructure
3. Enhance Portfolio Visualizer tool
4. Create Market Data tools
5. Add configuration settings
6. Implement testing suite
7. Deploy to production

---

**Document Version**: 1.0  
**Date**: February 2, 2026  
**Author**: Financial Planner Toolkit Development Team  
**Status**: Architecture & Best Practices Guide
