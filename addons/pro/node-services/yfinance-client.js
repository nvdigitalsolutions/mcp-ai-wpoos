#!/usr/bin/env node
/**
 * yfinance Client Service
 * 
 * Node.js service that acts as a client to the Python yfinance microservice.
 * Uses axios to make HTTP requests to the Flask REST API.
 * 
 * This service follows the pattern established by other Pro toolkit services
 * (prettier-service, mjml-service, etc.) and uses the axios npm package
 * already available in the Pro addon.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

const axios = require('axios');

// Get command line arguments
const action = process.argv[2];
const params = JSON.parse(process.argv[3] || '{}');

// Default service URL (can be overridden via params)
const DEFAULT_SERVICE_URL = process.env.YFINANCE_SERVICE_URL || 'http://localhost:5000';

/**
 * Get yfinance service URL from params or environment
 * 
 * @param {Object} params - Request parameters
 * @returns {string} Service URL
 */
function getServiceUrl(params) {
    return params.service_url || DEFAULT_SERVICE_URL;
}

/**
 * Fetch ticker information
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Ticker information
 */
async function getTickerInfo(params) {
    const { ticker } = params;
    
    if (!ticker) {
        throw new Error('Ticker symbol is required');
    }
    
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/ticker/${encodeURIComponent(ticker.toUpperCase())}`;
    
    try {
        const response = await axios.get(url, {
            timeout: params.timeout || 10000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            data: response.data,
            cached: response.data.cached || false
        };
    } catch (error) {
        throw new Error(`Failed to fetch ticker info: ${error.message}`);
    }
}

/**
 * Fetch current price for a single ticker
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Price data
 */
async function getCurrentPrice(params) {
    const { ticker, period = '1d' } = params;
    
    if (!ticker) {
        throw new Error('Ticker symbol is required');
    }
    
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/price/${encodeURIComponent(ticker.toUpperCase())}`;
    
    try {
        const response = await axios.get(url, {
            params: { period },
            timeout: params.timeout || 10000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            data: response.data,
            cached: response.data.cached || false
        };
    } catch (error) {
        throw new Error(`Failed to fetch price: ${error.message}`);
    }
}

/**
 * Fetch prices for multiple tickers (batch request)
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Batch price data
 */
async function getBatchPrices(params) {
    const { tickers, period = '1d' } = params;
    
    if (!tickers || !Array.isArray(tickers) || tickers.length === 0) {
        throw new Error('Tickers array is required and must not be empty');
    }
    
    if (tickers.length > 50) {
        throw new Error('Maximum 50 tickers per batch request');
    }
    
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/prices`;
    
    try {
        const response = await axios.post(url, {
            tickers: tickers.map(t => t.toUpperCase()),
            period
        }, {
            timeout: params.timeout || 15000,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            data: response.data.data || response.data,
            count: response.data.count || Object.keys(response.data.data || {}).length,
            period: response.data.period || period
        };
    } catch (error) {
        throw new Error(`Failed to fetch batch prices: ${error.message}`);
    }
}

/**
 * Fetch historical price data
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Historical data
 */
async function getPriceHistory(params) {
    const { ticker, period = '1mo', interval = '1d' } = params;
    
    if (!ticker) {
        throw new Error('Ticker symbol is required');
    }
    
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/history/${encodeURIComponent(ticker.toUpperCase())}`;
    
    try {
        const response = await axios.get(url, {
            params: { period, interval },
            timeout: params.timeout || 15000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            data: response.data.data || response.data,
            symbol: response.data.symbol || ticker.toUpperCase(),
            period: response.data.period || period,
            interval: response.data.interval || interval,
            count: response.data.count || 0,
            cached: response.data.cached || false
        };
    } catch (error) {
        throw new Error(`Failed to fetch price history: ${error.message}`);
    }
}

/**
 * Search for ticker symbols
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Search results
 */
async function searchTicker(params) {
    const { query } = params;
    
    if (!query) {
        throw new Error('Search query is required');
    }
    
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/search`;
    
    try {
        const response = await axios.get(url, {
            params: { q: query },
            timeout: params.timeout || 10000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            results: response.data.results || []
        };
    } catch (error) {
        throw new Error(`Failed to search ticker: ${error.message}`);
    }
}

/**
 * Check health of yfinance service
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Health status
 */
async function checkHealth(params) {
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/health`;
    
    try {
        const response = await axios.get(url, {
            timeout: 5000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            status: response.data.status,
            service: response.data.service,
            version: response.data.version,
            timestamp: response.data.timestamp
        };
    } catch (error) {
        return {
            success: false,
            error: `Service unavailable: ${error.message}`
        };
    }
}

/**
 * Clear cache on yfinance service
 * 
 * @param {Object} params - Request parameters
 * @returns {Promise<Object>} Clear cache result
 */
async function clearCache(params) {
    const serviceUrl = getServiceUrl(params);
    const url = `${serviceUrl}/cache/clear`;
    
    try {
        const response = await axios.post(url, {}, {
            timeout: 5000,
            headers: {
                'Accept': 'application/json'
            }
        });
        
        return {
            success: true,
            message: response.data.message || 'Cache cleared successfully'
        };
    } catch (error) {
        throw new Error(`Failed to clear cache: ${error.message}`);
    }
}

/**
 * Main execution handler
 */
async function main() {
    try {
        let result;
        
        switch (action) {
            case 'ticker_info':
                result = await getTickerInfo(params);
                break;
                
            case 'current_price':
                result = await getCurrentPrice(params);
                break;
                
            case 'batch_prices':
                result = await getBatchPrices(params);
                break;
                
            case 'price_history':
                result = await getPriceHistory(params);
                break;
                
            case 'search':
                result = await searchTicker(params);
                break;
                
            case 'health':
                result = await checkHealth(params);
                break;
                
            case 'clear_cache':
                result = await clearCache(params);
                break;
                
            default:
                throw new Error(`Unknown action: ${action}`);
        }
        
        // Output success result as JSON
        console.log(JSON.stringify(result));
        process.exit(0);
        
    } catch (error) {
        // Output error as JSON
        console.error(JSON.stringify({
            success: false,
            error: error.message
        }));
        process.exit(1);
    }
}

// Execute
main();
