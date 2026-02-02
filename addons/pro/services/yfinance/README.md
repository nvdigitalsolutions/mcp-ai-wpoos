# yfinance Microservice

A lightweight Python microservice providing REST API access to Yahoo Finance market data via the yfinance library.

## Features

- ✅ Real-time stock quotes (15-minute delayed)
- ✅ Historical price data with customizable periods
- ✅ Company information and fundamentals
- ✅ Batch requests for multiple tickers
- ✅ Built-in file-based caching with configurable TTL
- ✅ Rate limiting (30 requests/minute default)
- ✅ Comprehensive error handling
- ✅ CORS enabled for WordPress integration
- ✅ Health check endpoint

## Requirements

- Python 3.8 or higher
- pip (Python package manager)
- Virtual environment (recommended)

## Installation

### 1. Create Virtual Environment

```bash
cd addons/pro/services/yfinance
python3 -m venv venv

# Activate virtual environment
# On Linux/Mac:
source venv/bin/activate

# On Windows:
venv\Scripts\activate
```

### 2. Install Dependencies

```bash
pip install -r requirements.txt
```

### 3. Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` to customize settings:

```bash
# Service configuration
PORT=5000
DEBUG=False

# Cache configuration
CACHE_DIR=/tmp/yfinance_cache
CACHE_TTL_MINUTES=15

# Rate limiting
RATE_LIMIT_PER_MINUTE=30
MAX_BATCH_SIZE=50
```

## Running the Service

### Development Mode

```bash
python app.py
```

The service will start on `http://localhost:5000`

### Production Mode (with Gunicorn)

```bash
# Install gunicorn
pip install gunicorn

# Run with 4 workers
gunicorn -w 4 -b 0.0.0.0:5000 app:app
```

## API Endpoints

### Health Check

```
GET /health
```

Response:
```json
{
  "status": "healthy",
  "service": "yfinance-api",
  "version": "1.0.0",
  "timestamp": "2026-02-02T12:00:00"
}
```

### Get Ticker Information

```
GET /ticker/<symbol>
```

Example: `GET /ticker/AAPL`

Response:
```json
{
  "symbol": "AAPL",
  "longName": "Apple Inc.",
  "currentPrice": 150.25,
  "marketCap": 2500000000000,
  "sector": "Technology",
  ...
  "cached": false
}
```

### Get Current Price

```
GET /price/<symbol>?period=1d
```

Example: `GET /price/AAPL?period=1d`

Response:
```json
{
  "symbol": "AAPL",
  "current_price": 150.25,
  "open": 149.50,
  "high": 151.00,
  "low": 149.00,
  "volume": 50000000,
  "date": "2026-02-02",
  "period": "1d",
  "cached": false
}
```

### Get Multiple Prices (Batch)

```
POST /prices
Content-Type: application/json

{
  "tickers": ["AAPL", "GOOGL", "MSFT"],
  "period": "1d"
}
```

Response:
```json
{
  "success": true,
  "period": "1d",
  "count": 3,
  "data": {
    "AAPL": {
      "current_price": 150.25,
      "open": 149.50,
      ...
    },
    "GOOGL": {
      "current_price": 2800.00,
      ...
    },
    "MSFT": {
      "current_price": 350.75,
      ...
    }
  }
}
```

### Get Price History

```
GET /history/<symbol>?period=1mo&interval=1d
```

Example: `GET /history/AAPL?period=1mo&interval=1d`

Parameters:
- `period`: 1d, 5d, 1mo, 3mo, 6mo, 1y, 2y, 5y, 10y, ytd, max
- `interval`: 1m, 2m, 5m, 15m, 30m, 60m, 90m, 1h, 1d, 5d, 1wk, 1mo, 3mo

Response:
```json
{
  "symbol": "AAPL",
  "period": "1mo",
  "interval": "1d",
  "count": 21,
  "data": [
    {
      "date": "2026-01-02",
      "Open": 148.50,
      "High": 150.00,
      "Low": 147.00,
      "Close": 149.25,
      "Volume": 55000000
    },
    ...
  ],
  "cached": false
}
```

### Search Ticker

```
GET /search?q=AAPL
```

Response:
```json
{
  "success": true,
  "results": [
    {
      "symbol": "AAPL",
      "name": "Apple Inc.",
      "type": "EQUITY",
      "exchange": "NMS"
    }
  ]
}
```

### Clear Cache

```
POST /cache/clear
```

Response:
```json
{
  "success": true,
  "message": "Cache cleared successfully"
}
```

## Error Handling

The service returns appropriate HTTP status codes:

- `200` - Success
- `400` - Bad request (invalid parameters)
- `404` - Not found (invalid ticker or no data)
- `429` - Rate limit exceeded
- `500` - Internal server error

Error response format:
```json
{
  "error": "Error description",
  "message": "Additional details (optional)",
  "retry_after": 60
}
```

## Caching

The service implements a simple file-based cache:

- Cache location: `/tmp/yfinance_cache` (configurable)
- Cache TTL: 15 minutes (configurable)
- Automatic cache expiration
- Manual cache clearing via `/cache/clear` endpoint

### Cache Keys

- `ticker_info_{SYMBOL}` - Company information
- `price_{SYMBOL}_{PERIOD}` - Price data
- `history_{SYMBOL}_{PERIOD}_{INTERVAL}` - Historical data

## Rate Limiting

Default rate limit: **30 requests per minute**

When rate limit is exceeded, the service returns:

```json
{
  "error": "Rate limit exceeded",
  "message": "Maximum 30 requests per minute",
  "retry_after": 60
}
```

HTTP Status: `429 Too Many Requests`

## Testing

Run the test suite:

```bash
# Install test dependencies
pip install pytest pytest-cov pytest-mock requests-mock

# Run tests
pytest tests/

# Run with coverage
pytest --cov=. tests/

# Generate coverage report
pytest --cov=. --cov-report=html tests/
```

## WordPress Integration

### PHP Example

```php
<?php
// Fetch ticker data
$service_url = 'http://localhost:5000';
$response = wp_remote_get( $service_url . '/ticker/AAPL' );

if ( ! is_wp_error( $response ) ) {
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    
    if ( isset( $data['currentPrice'] ) ) {
        $price = $data['currentPrice'];
        echo "Apple stock price: $" . number_format( $price, 2 );
    }
}
```

### Batch Request Example

```php
<?php
$service_url = 'http://localhost:5000';
$tickers = array( 'AAPL', 'GOOGL', 'MSFT' );

$response = wp_remote_post(
    $service_url . '/prices',
    array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => json_encode( array(
            'tickers' => $tickers,
            'period'  => '1d'
        ) ),
        'timeout' => 15,
    )
);

if ( ! is_wp_error( $response ) ) {
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    
    foreach ( $data['data'] as $ticker => $price_data ) {
        echo "$ticker: $" . number_format( $price_data['current_price'], 2 ) . "\n";
    }
}
```

## Deployment

### Docker

Create `Dockerfile`:

```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 5000

CMD ["gunicorn", "-w", "4", "-b", "0.0.0.0:5000", "app:app"]
```

Build and run:

```bash
docker build -t yfinance-api .
docker run -p 5000:5000 -e CACHE_TTL_MINUTES=15 yfinance-api
```

### Systemd Service (Linux)

Create `/etc/systemd/system/yfinance-api.service`:

```ini
[Unit]
Description=yfinance Microservice
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/addons/pro/services/yfinance
Environment="PATH=/path/to/addons/pro/services/yfinance/venv/bin"
ExecStart=/path/to/addons/pro/services/yfinance/venv/bin/gunicorn -w 4 -b 0.0.0.0:5000 app:app
Restart=always

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable yfinance-api
sudo systemctl start yfinance-api
```

## Security Considerations

### Production Checklist

- [ ] Disable debug mode (`DEBUG=False`)
- [ ] Use HTTPS/SSL for production
- [ ] Implement authentication if exposing publicly
- [ ] Configure firewall rules (only allow WordPress server)
- [ ] Monitor rate limits and adjust as needed
- [ ] Set up log rotation
- [ ] Keep dependencies updated
- [ ] Use environment variables for secrets
- [ ] Consider Redis for distributed caching

### Educational Use Disclaimer

This service uses Yahoo Finance data via the yfinance library for **educational purposes only**. Please ensure your use complies with:

- Yahoo Finance Terms of Service
- yfinance license (Apache 2.0)
- Your local regulations

**Important**: Data may be delayed by 15 minutes or more. Do not use for actual trading decisions.

## Troubleshooting

### Service won't start

- Check Python version: `python --version` (requires 3.8+)
- Verify dependencies: `pip list`
- Check port availability: `lsof -i :5000`
- Review logs for errors

### Rate limit errors

- Reduce request frequency
- Implement caching in WordPress layer
- Increase `RATE_LIMIT_PER_MINUTE` if needed
- Use batch requests for multiple tickers

### Cache issues

- Check cache directory permissions
- Verify `CACHE_DIR` path is writable
- Clear cache: `POST /cache/clear`
- Check disk space

### No data returned

- Verify ticker symbol is valid
- Check yfinance library is up to date: `pip install --upgrade yfinance`
- Try different period/interval
- Check Yahoo Finance service status

## Performance Tips

1. **Use batch requests** for multiple tickers
2. **Enable caching** in WordPress (transients)
3. **Adjust cache TTL** based on use case
4. **Use Gunicorn** with multiple workers in production
5. **Consider Redis** for distributed caching
6. **Monitor response times** and optimize as needed

## License

This microservice is part of the WP MCP AI Pro plugin and is licensed under GPLv3 or later.

## Support

For issues and questions:
- Check the [main documentation](../../docs/YFINANCE_INTEGRATION_GUIDE.md)
- Review [Financial Planner Toolkit Plan](../../docs/FINANCIAL_PLANNER_TOOLKIT_PLAN.md)
- Open an issue on the main repository

## Version History

- **1.0.0** (2026-02-02) - Initial release
  - Core API endpoints
  - File-based caching
  - Rate limiting
  - WordPress integration examples

---

**Author**: Financial Planner Toolkit Team  
**Date**: February 2, 2026  
**Status**: Production Ready
