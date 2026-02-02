"""
yfinance Microservice - Market Data API

This microservice provides a REST API interface to Yahoo Finance market data
using the yfinance Python library. It includes caching, rate limiting, and
error handling for production use.

Features:
- Real-time stock quotes (15-min delayed)
- Historical price data
- Company information and fundamentals
- Multi-ticker batch requests
- Built-in caching with configurable TTL
- Rate limiting to prevent API abuse
- Comprehensive error handling

Author: Financial Planner Toolkit Team
Version: 1.0.0
Date: February 2, 2026
License: GPLv3 or later
"""

import os
import json
import logging
import time
from datetime import datetime, timedelta
from functools import wraps
from typing import Dict, List, Optional, Any

from flask import Flask, jsonify, request, Response
from flask_cors import CORS
import yfinance as yf
import pandas as pd
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Initialize Flask app
app = Flask(__name__)
CORS(app)  # Enable CORS for WordPress integration

# Configuration
CACHE_DIR = os.getenv('CACHE_DIR', '/tmp/yfinance_cache')
CACHE_TTL_MINUTES = int(os.getenv('CACHE_TTL_MINUTES', '15'))
RATE_LIMIT_PER_MINUTE = int(os.getenv('RATE_LIMIT_PER_MINUTE', '30'))
MAX_BATCH_SIZE = int(os.getenv('MAX_BATCH_SIZE', '50'))

# Ensure cache directory exists
os.makedirs(CACHE_DIR, exist_ok=True)

# Simple in-memory rate limiting
request_timestamps = []


def rate_limit(max_per_minute: int):
    """
    Rate limiting decorator.
    
    Args:
        max_per_minute: Maximum requests allowed per minute
        
    Returns:
        Decorated function
    """
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            global request_timestamps
            
            # Clean old timestamps
            current_time = time.time()
            request_timestamps = [
                ts for ts in request_timestamps
                if current_time - ts < 60
            ]
            
            # Check rate limit
            if len(request_timestamps) >= max_per_minute:
                return jsonify({
                    'error': 'Rate limit exceeded',
                    'message': f'Maximum {max_per_minute} requests per minute',
                    'retry_after': 60
                }), 429
            
            # Add current timestamp
            request_timestamps.append(current_time)
            
            return func(*args, **kwargs)
        return wrapper
    return decorator


class SimpleCache:
    """
    Simple file-based cache with TTL support.
    """
    
    def __init__(self, cache_dir: str, ttl_minutes: int):
        self.cache_dir = cache_dir
        self.ttl = timedelta(minutes=ttl_minutes)
    
    def _get_cache_path(self, key: str) -> str:
        """Generate cache file path."""
        safe_key = key.replace('/', '_').replace('\\', '_')
        return os.path.join(self.cache_dir, f"{safe_key}.json")
    
    def get(self, key: str) -> Optional[Any]:
        """Get cached value if not expired."""
        cache_path = self._get_cache_path(key)
        
        if not os.path.exists(cache_path):
            return None
        
        # Check if expired
        mtime = datetime.fromtimestamp(os.path.getmtime(cache_path))
        if datetime.now() - mtime > self.ttl:
            # Expired, remove file
            try:
                os.remove(cache_path)
            except OSError:
                pass
            return None
        
        # Read cached data
        try:
            with open(cache_path, 'r') as f:
                return json.load(f)
        except (json.JSONDecodeError, IOError) as e:
            logger.warning(f"Cache read error for {key}: {e}")
            return None
    
    def set(self, key: str, value: Any) -> bool:
        """Set cache value."""
        cache_path = self._get_cache_path(key)
        
        try:
            with open(cache_path, 'w') as f:
                json.dump(value, f)
            return True
        except (TypeError, IOError) as e:
            logger.warning(f"Cache write error for {key}: {e}")
            return False
    
    def delete(self, key: str) -> bool:
        """Delete cached value."""
        cache_path = self._get_cache_path(key)
        
        try:
            if os.path.exists(cache_path):
                os.remove(cache_path)
            return True
        except OSError as e:
            logger.warning(f"Cache delete error for {key}: {e}")
            return False


# Initialize cache
cache = SimpleCache(CACHE_DIR, CACHE_TTL_MINUTES)


def validate_ticker(ticker: str) -> bool:
    """
    Validate ticker symbol format.
    
    Args:
        ticker: Stock ticker symbol
        
    Returns:
        True if valid, False otherwise
    """
    if not ticker or not isinstance(ticker, str):
        return False
    
    # Basic validation: alphanumeric, dots, hyphens
    import re
    return bool(re.match(r'^[A-Z0-9\.\-]+$', ticker.upper()))


def serialize_dataframe(df: pd.DataFrame) -> List[Dict]:
    """
    Serialize pandas DataFrame to JSON-serializable format.
    
    Args:
        df: Pandas DataFrame
        
    Returns:
        List of dictionaries
    """
    if df.empty:
        return []
    
    # Convert to dict with proper datetime handling
    result = []
    for idx, row in df.iterrows():
        row_dict = row.to_dict()
        # Convert timestamp index to string
        if isinstance(idx, pd.Timestamp):
            row_dict['date'] = idx.strftime('%Y-%m-%d')
        result.append(row_dict)
    
    return result


@app.route('/health', methods=['GET'])
def health_check():
    """
    Health check endpoint.
    
    Returns:
        JSON response with service status
    """
    return jsonify({
        'status': 'healthy',
        'service': 'yfinance-api',
        'version': '1.0.0',
        'timestamp': datetime.now().isoformat()
    })


@app.route('/ticker/<symbol>', methods=['GET'])
@rate_limit(max_per_minute=RATE_LIMIT_PER_MINUTE)
def get_ticker_info(symbol: str):
    """
    Get ticker information.
    
    Args:
        symbol: Stock ticker symbol
        
    Returns:
        JSON response with ticker info
    """
    symbol = symbol.upper()
    
    if not validate_ticker(symbol):
        return jsonify({'error': 'Invalid ticker symbol'}), 400
    
    # Check cache
    cache_key = f"ticker_info_{symbol}"
    cached = cache.get(cache_key)
    if cached:
        logger.info(f"Cache hit for {symbol}")
        return jsonify({**cached, 'cached': True})
    
    # Fetch from yfinance
    try:
        logger.info(f"Fetching info for {symbol}")
        ticker = yf.Ticker(symbol)
        info = ticker.info
        
        if not info or len(info) == 0:
            return jsonify({'error': f'No data found for ticker {symbol}'}), 404
        
        # Cache the result
        cache.set(cache_key, info)
        
        return jsonify({**info, 'cached': False})
        
    except Exception as e:
        logger.error(f"Error fetching {symbol}: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/price/<symbol>', methods=['GET'])
@rate_limit(max_per_minute=RATE_LIMIT_PER_MINUTE)
def get_current_price(symbol: str):
    """
    Get current price for a single ticker.
    
    Args:
        symbol: Stock ticker symbol
        
    Query params:
        period: Data period (1d, 5d, 1mo, etc.)
        
    Returns:
        JSON response with current price data
    """
    symbol = symbol.upper()
    
    if not validate_ticker(symbol):
        return jsonify({'error': 'Invalid ticker symbol'}), 400
    
    period = request.args.get('period', '1d')
    
    # Check cache
    cache_key = f"price_{symbol}_{period}"
    cached = cache.get(cache_key)
    if cached:
        logger.info(f"Cache hit for price {symbol}")
        return jsonify({**cached, 'cached': True})
    
    # Fetch from yfinance
    try:
        logger.info(f"Fetching price for {symbol}")
        ticker = yf.Ticker(symbol)
        hist = ticker.history(period=period)
        
        if hist.empty:
            return jsonify({'error': f'No price data found for {symbol}'}), 404
        
        # Get latest data
        latest = hist.iloc[-1]
        
        result = {
            'symbol': symbol,
            'current_price': float(latest['Close']),
            'open': float(latest['Open']),
            'high': float(latest['High']),
            'low': float(latest['Low']),
            'volume': int(latest['Volume']),
            'date': hist.index[-1].strftime('%Y-%m-%d'),
            'period': period
        }
        
        # Cache the result
        cache.set(cache_key, result)
        
        return jsonify({**result, 'cached': False})
        
    except Exception as e:
        logger.error(f"Error fetching price for {symbol}: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/prices', methods=['POST'])
@rate_limit(max_per_minute=RATE_LIMIT_PER_MINUTE)
def get_multiple_prices():
    """
    Get prices for multiple tickers (batch request).
    
    Request body:
        {
            "tickers": ["AAPL", "GOOGL", "MSFT"],
            "period": "1d"
        }
        
    Returns:
        JSON response with prices for all tickers
    """
    data = request.get_json()
    
    if not data or 'tickers' not in data:
        return jsonify({'error': 'Missing tickers in request body'}), 400
    
    tickers = data.get('tickers', [])
    period = data.get('period', '1d')
    
    if not tickers or not isinstance(tickers, list):
        return jsonify({'error': 'tickers must be a non-empty list'}), 400
    
    if len(tickers) > MAX_BATCH_SIZE:
        return jsonify({
            'error': f'Too many tickers (max {MAX_BATCH_SIZE})'
        }), 400
    
    # Validate all tickers
    tickers = [t.upper() for t in tickers]
    for ticker in tickers:
        if not validate_ticker(ticker):
            return jsonify({'error': f'Invalid ticker: {ticker}'}), 400
    
    # Fetch data
    try:
        logger.info(f"Fetching batch prices for {len(tickers)} tickers")
        
        # Use yfinance batch download
        data = yf.download(tickers, period=period, group_by='ticker', progress=False)
        
        results = {}
        for ticker in tickers:
            try:
                if len(tickers) == 1:
                    ticker_data = data
                else:
                    ticker_data = data[ticker]
                
                if not ticker_data.empty:
                    latest = ticker_data.iloc[-1]
                    results[ticker] = {
                        'current_price': float(latest['Close']),
                        'open': float(latest['Open']),
                        'high': float(latest['High']),
                        'low': float(latest['Low']),
                        'volume': int(latest['Volume']),
                        'date': ticker_data.index[-1].strftime('%Y-%m-%d')
                    }
                else:
                    results[ticker] = {'error': 'No data found'}
            except Exception as e:
                logger.warning(f"Error processing {ticker}: {str(e)}")
                results[ticker] = {'error': str(e)}
        
        return jsonify({
            'success': True,
            'period': period,
            'count': len(results),
            'data': results
        })
        
    except Exception as e:
        logger.error(f"Error fetching batch prices: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/history/<symbol>', methods=['GET'])
@rate_limit(max_per_minute=RATE_LIMIT_PER_MINUTE)
def get_price_history(symbol: str):
    """
    Get historical price data.
    
    Args:
        symbol: Stock ticker symbol
        
    Query params:
        period: Data period (1d, 5d, 1mo, 3mo, 6mo, 1y, 2y, 5y, 10y, ytd, max)
        interval: Data interval (1m, 2m, 5m, 15m, 30m, 60m, 90m, 1h, 1d, 5d, 1wk, 1mo, 3mo)
        
    Returns:
        JSON response with historical price data
    """
    symbol = symbol.upper()
    
    if not validate_ticker(symbol):
        return jsonify({'error': 'Invalid ticker symbol'}), 400
    
    period = request.args.get('period', '1mo')
    interval = request.args.get('interval', '1d')
    
    # Check cache
    cache_key = f"history_{symbol}_{period}_{interval}"
    cached = cache.get(cache_key)
    if cached:
        logger.info(f"Cache hit for history {symbol}")
        return jsonify({**cached, 'cached': True})
    
    # Fetch from yfinance
    try:
        logger.info(f"Fetching history for {symbol}")
        ticker = yf.Ticker(symbol)
        hist = ticker.history(period=period, interval=interval)
        
        if hist.empty:
            return jsonify({'error': f'No history data found for {symbol}'}), 404
        
        # Serialize dataframe
        data = serialize_dataframe(hist)
        
        result = {
            'symbol': symbol,
            'period': period,
            'interval': interval,
            'count': len(data),
            'data': data
        }
        
        # Cache the result
        cache.set(cache_key, result)
        
        return jsonify({**result, 'cached': False})
        
    except Exception as e:
        logger.error(f"Error fetching history for {symbol}: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/search', methods=['GET'])
@rate_limit(max_per_minute=RATE_LIMIT_PER_MINUTE)
def search_ticker():
    """
    Search for ticker symbols.
    
    Query params:
        q: Search query
        
    Returns:
        JSON response with search results
    """
    query = request.args.get('q', '')
    
    if not query or len(query) < 1:
        return jsonify({'error': 'Search query required (q parameter)'}), 400
    
    # Note: yfinance doesn't have built-in search, so we use Ticker for validation
    # In production, consider using a dedicated ticker search API
    
    try:
        query_upper = query.upper()
        ticker = yf.Ticker(query_upper)
        info = ticker.info
        
        if info and len(info) > 0:
            return jsonify({
                'success': True,
                'results': [{
                    'symbol': info.get('symbol', query_upper),
                    'name': info.get('longName', info.get('shortName', '')),
                    'type': info.get('quoteType', ''),
                    'exchange': info.get('exchange', '')
                }]
            })
        else:
            return jsonify({
                'success': True,
                'results': []
            })
            
    except Exception as e:
        logger.error(f"Error searching for {query}: {str(e)}")
        return jsonify({
            'success': True,
            'results': []
        })


@app.route('/cache/clear', methods=['POST'])
def clear_cache():
    """
    Clear all cached data.
    
    Returns:
        JSON response with success status
    """
    try:
        import shutil
        shutil.rmtree(CACHE_DIR)
        os.makedirs(CACHE_DIR, exist_ok=True)
        
        logger.info("Cache cleared")
        return jsonify({
            'success': True,
            'message': 'Cache cleared successfully'
        })
    except Exception as e:
        logger.error(f"Error clearing cache: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.errorhandler(404)
def not_found(error):
    """Handle 404 errors."""
    return jsonify({'error': 'Endpoint not found'}), 404


@app.errorhandler(500)
def internal_error(error):
    """Handle 500 errors."""
    logger.error(f"Internal server error: {str(error)}")
    return jsonify({'error': 'Internal server error'}), 500


if __name__ == '__main__':
    # Development server
    port = int(os.getenv('PORT', '5000'))
    debug = os.getenv('DEBUG', 'False').lower() == 'true'
    
    logger.info(f"Starting yfinance microservice on port {port}")
    logger.info(f"Cache directory: {CACHE_DIR}")
    logger.info(f"Cache TTL: {CACHE_TTL_MINUTES} minutes")
    logger.info(f"Rate limit: {RATE_LIMIT_PER_MINUTE} requests/minute")
    
    app.run(host='0.0.0.0', port=port, debug=debug)
