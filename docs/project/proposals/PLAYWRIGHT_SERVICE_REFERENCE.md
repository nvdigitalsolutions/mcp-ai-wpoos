# Playwright Service Reference for web_browser Tool

**Created:** January 9, 2026  
**Status:** WordPress integration complete ✅ | Standalone Node.js service deployment unverified ⏳ (v1.1.29)

## Overview

The `web_browser` Pro tool in WordPress requires an external Playwright service for full browser automation capabilities. This document provides the complete reference for setting up and using the Playwright service.

## Service Location

The Playwright service has been created as a separate Node.js application in:
```
/home/runner/work/mcp-ai-wpoos/playwright-service/
```

This service should be deployed separately from the WordPress plugin and accessed via HTTP API.

## Quick Setup

### 1. Install Service Dependencies

```bash
cd /path/to/playwright-service
npm install
npx playwright install chromium
```

### 2. Configure Environment

```bash
cp .env.example .env
nano .env
```

Required settings:
```env
PORT=3000
CORS_ORIGIN=https://yourwordpresssite.com
API_KEY=your-secret-key  # Optional
HEADLESS=true
```

### 3. Start Service

**Development:**
```bash
npm run dev
```

**Production (PM2):**
```bash
npm install -g pm2
pm2 start src/index.js --name playwright-service
pm2 save
```

**Docker:**
```bash
docker build -t playwright-service .
docker run -d -p 3000:3000 playwright-service
```

### 4. Configure WordPress

In WordPress admin:
1. Go to **Settings → NV oOS → Tools**
2. Find **Playwright Service URL**
3. Enter: `http://localhost:3000` (development) or `https://playwright.yoursite.com` (production)
4. Save settings

## API Reference

### Endpoint: POST /api/browser

Base URL: `http://your-service:3000/api/browser`

### Actions

#### 1. Navigate & Extract

```json
{
  "url": "https://example.com",
  "action": "navigate",
  "wait_for": "load",
  "timeout": 30000
}
```

**Response:**
```json
{
  "success": true,
  "action": "navigate",
  "url": "https://example.com",
  "data": {
    "html": "<html>...</html>",
    "title": "Example Domain",
    "url": "https://example.com"
  }
}
```

#### 2. Screenshot

```json
{
  "url": "https://example.com",
  "action": "screenshot",
  "screenshot_options": {
    "full_page": true,
    "type": "png"
  },
  "viewport": {
    "width": 1280,
    "height": 720
  }
}
```

**Response:**
```json
{
  "success": true,
  "action": "screenshot",
  "data": {
    "screenshot": "base64-encoded-png-data...",
    "type": "png",
    "size": 123456
  }
}
```

#### 3. PDF Generation

```json
{
  "url": "https://example.com",
  "action": "pdf",
  "pdf_options": {
    "format": "A4",
    "landscape": false,
    "printBackground": true
  }
}
```

**Response:**
```json
{
  "success": true,
  "action": "pdf",
  "data": {
    "pdf": "base64-encoded-pdf-data...",
    "size": 234567
  }
}
```

#### 4. Click Element

```json
{
  "url": "https://example.com",
  "action": "click",
  "selector": "#submit-button"
}
```

#### 5. Type Text

```json
{
  "url": "https://example.com/form",
  "action": "type",
  "selector": "#email",
  "text": "user@example.com"
}
```

#### 6. Submit Form

```json
{
  "url": "https://example.com/form",
  "action": "submit",
  "selector": "form button[type='submit']"
}
```

#### 7. Extract Content

```json
{
  "url": "https://example.com",
  "action": "extract",
  "selector": ".main-content"
}
```

**Response:**
```json
{
  "success": true,
  "action": "extract",
  "data": {
    "text": "Extracted text content...",
    "html": "<div class='main-content'>...</div>",
    "selector": ".main-content"
  }
}
```

## WordPress Integration

### How It Works

```
WordPress web_browser Tool
         ↓
  HTTP POST request
         ↓
Playwright Service (Node.js)
         ↓
  Browser automation
         ↓
Return results (HTML/Screenshot/PDF)
         ↓
WordPress processes & stores
```

### Configuration Options

**In WordPress (Settings → NV oOS → Tools):**
- **Playwright Service URL**: URL of your Playwright service
- Leave empty to use local HTTP fallback (limited features)

**Filter Hooks:**
```php
// Dynamic service URL (e.g., from environment variable)
add_filter('wp_mcp_ai_playwright_service_url', function($url) {
    return getenv('PLAYWRIGHT_SERVICE_URL') ?: $url;
});

// Disable local fallback
add_filter('wp_mcp_ai_web_browser_local_enabled', '__return_false');

// Custom rate limit
add_filter('wp_mcp_ai_web_browser_rate_limit', function() {
    return 50; // 50 actions per hour
});
```

## Service Features

### Browser Pool Management

The service maintains a pool of browser instances for optimal performance:
- Reuses existing browsers when possible
- Creates new browsers up to configured limit
- Automatically cleans up closed browsers
- Default: 5 concurrent browsers

### Security Features

1. **SSRF Protection**: Blocks localhost, private IPs, internal networks
2. **CORS**: Configurable origin whitelist
3. **Rate Limiting**: Configurable per-IP limits
4. **API Key**: Optional authentication
5. **URL Validation**: Strict URL validation
6. **Timeouts**: Prevents long-running operations

### Logging

Winston-based logging with multiple transports:
- Console output (development)
- File logging (`logs/combined.log`, `logs/error.log`)
- JSON format for easy parsing
- Configurable log levels

## Deployment Scenarios

### Scenario 1: Same Server as WordPress

```
WordPress: localhost:8000
Playwright: localhost:3000
```

WordPress config:
```
Playwright Service URL: http://localhost:3000
```

### Scenario 2: Separate Server

```
WordPress: https://yoursite.com
Playwright: https://playwright.yoursite.com (port 443)
```

WordPress config:
```
Playwright Service URL: https://playwright.yoursite.com
```

Use Nginx reverse proxy for SSL:
```nginx
server {
    listen 443 ssl;
    server_name playwright.yoursite.com;
    
    location / {
        proxy_pass http://localhost:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### Scenario 3: Docker Container

```bash
docker run -d \
  --name playwright \
  -p 3000:3000 \
  -e CORS_ORIGIN=https://yoursite.com \
  playwright-service
```

WordPress config:
```
Playwright Service URL: http://your-server-ip:3000
```

### Scenario 4: Kubernetes Cluster

Deploy as a Kubernetes service with horizontal pod autoscaling.

WordPress config:
```
Playwright Service URL: https://playwright-service.cluster.local
```

## Testing the Integration

### 1. Test Service Health

```bash
curl http://localhost:3000/health
```

Expected:
```json
{
  "status": "ok",
  "timestamp": "2026-01-09T08:48:00.000Z",
  "uptime": 123.45
}
```

### 2. Test Screenshot Capture

```bash
curl -X POST http://localhost:3000/api/browser \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "action": "screenshot",
    "screenshot_options": {"full_page": true, "type": "png"}
  }'
```

### 3. Test from WordPress

In WordPress, use an assistant with the `web_browser` tool:

```
Take a screenshot of https://example.com
```

The assistant should use the remote Playwright service automatically.

## Performance Optimization

### Concurrent Browsers

Adjust based on server resources:
```env
MAX_CONCURRENT_BROWSERS=10
```

**Rule of thumb:** ~500MB RAM per browser instance

### Viewport Size

Smaller viewports = faster screenshots:
```json
{
  "viewport": {
    "width": 800,
    "height": 600
  }
}
```

### Wait Strategies

Choose appropriate wait strategy:
- `load` - Fast, basic page load
- `domcontentloaded` - Medium, DOM ready
- `networkidle` - Slow, all network requests done

### Timeouts

Balance between reliability and speed:
- Development: 60000ms (60s)
- Production: 30000ms (30s)
- Screenshots: 15000ms (15s)

## Monitoring

### Health Checks

Add to monitoring tools:
```bash
*/5 * * * * curl -f http://localhost:3000/health || alert
```

### PM2 Monitoring

```bash
pm2 monit               # Real-time monitoring
pm2 logs playwright    # View logs
pm2 restart playwright # Restart service
```

### Docker Monitoring

```bash
docker stats playwright-service
docker logs -f playwright-service
```

## Troubleshooting

### Issue: Service Won't Start

**Solution:** Check dependencies
```bash
npm install
npx playwright install chromium
```

### Issue: WordPress Can't Connect

**Checklist:**
1. Is service running? `curl http://localhost:3000/health`
2. Is CORS configured? Check `CORS_ORIGIN` in `.env`
3. Is firewall blocking? Check ports
4. Is URL correct in WordPress settings?

### Issue: Screenshots Fail

**Solution:** Install browser dependencies
```bash
# Ubuntu/Debian
sudo apt-get install -y libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0
```

### Issue: High Memory Usage

**Solution:** Reduce concurrent browsers
```env
MAX_CONCURRENT_BROWSERS=3
```

Or increase Node.js memory:
```bash
NODE_OPTIONS="--max-old-space-size=4096" npm start
```

### Issue: Rate Limiting

**Solution:** Adjust limits or increase for specific IPs
```env
RATE_LIMIT_MAX=50
RATE_LIMIT_WINDOW=60000
```

## Security Checklist

- [ ] Change default API_KEY in production
- [ ] Configure CORS_ORIGIN to specific domains (not *)
- [ ] Enable HTTPS with valid SSL certificate
- [ ] Run service as non-root user
- [ ] Keep dependencies updated
- [ ] Monitor logs for suspicious activity
- [ ] Implement firewall rules
- [ ] Regular security audits

## Files Reference

### Service Files Created

```
playwright-service/
├── package.json                 # Dependencies
├── README.md                    # Service documentation
├── DEPLOYMENT.md               # Deployment guide
├── .env.example                # Environment template
├── .gitignore                  # Git ignore rules
├── Dockerfile                  # Docker image
├── docker-compose.yml          # Docker Compose config
└── src/
    ├── index.js                # Main application
    ├── config.js               # Configuration
    ├── routes/
    │   ├── browser.js          # Browser automation endpoint
    │   └── health.js           # Health check endpoint
    ├── services/
    │   └── browser.js          # Browser pool & actions
    └── utils/
        ├── logger.js           # Winston logger
        └── validator.js        # Request validation
```

### WordPress Plugin Files

```
addons/pro/includes/tools/
└── class-wp-mcp-ai-tool-web-browser.php  # Pro tool implementation

includes/admin/
└── class-wp-mcp-ai-admin-settings.php    # Settings integration

tests/pro/
└── test-web-browser-tool.php             # Tests
```

## Next Steps

1. ✅ Service implementation complete
2. ⏳ Deploy service to production server
3. ⏳ Configure SSL/TLS with Nginx
4. ⏳ Test end-to-end WordPress integration
5. ⏳ Monitor performance and adjust resources
6. ⏳ Add service to monitoring/alerting
7. ⏳ Document production deployment

## Support

For service-related issues:
- Check logs: `pm2 logs playwright` or `docker logs playwright-service`
- Verify health: `curl http://localhost:3000/health`
- Review configuration in `.env`
- Check firewall/network settings

For WordPress plugin issues:
- Verify Playwright Service URL in settings
- Check WordPress debug logs
- Test local fallback mode

## License

GPL-3.0 - Same as WordPress plugin

---

**Service Ready**: The Playwright service is fully implemented and ready for deployment. Configure the service URL in WordPress settings to enable full browser automation capabilities.
