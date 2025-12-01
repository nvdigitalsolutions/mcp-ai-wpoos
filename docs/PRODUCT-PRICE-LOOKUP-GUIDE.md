# Product Price Lookup Tool Guide

## Overview

The **Lookup Product Price** tool (Pro addon) provides industry-standard product price discovery and comparison functionality, similar to:
- **Google Lens Shopping** - Image-based product identification and price lookup
- **Amazon Visual Search** - Product recognition from photos
- **Browser Price Comparison Extensions** (Honey, Capital One Shopping) - Multi-retailer price comparison

## Architecture

The tool orchestrates multiple data sources and extraction methods to provide comprehensive price lookup:

```
┌─────────────────────────────────────────────────────────────┐
│              lookup_product_price (Pro Tool)                │
└─────────────────────────────────────────────────────────────┘
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
┌───────▼────────┐ ┌──────▼───────┐ ┌────────▼────────┐
│  Image Input   │ │Document Input│ │   URL Input     │
│ (attachment)   │ │(invoice/quote│ │ (single/batch)  │
└───────┬────────┘ └──────┬───────┘ └────────┬────────┘
        │                  │                  │
        │                  │                  │
┌───────▼────────┐ ┌──────▼───────┐ ┌────────▼────────┐
│ Vision Product │ │   Crawl4AI   │ │   Crawl4AI      │
│     Search     │ │File Processing│ │  URL Crawling   │
└───────┬────────┘ └──────┬───────┘ └────────┬────────┘
        │                  │                  │
        └──────────────────┼──────────────────┘
                           │
                  ┌────────▼─────────┐
                  │  Price Discovery │
                  │  (Multi-Retailer)│
                  └────────┬─────────┘
                           │
                  ┌────────▼─────────┐
                  │ Normalized Offers│
                  │ (price, currency,│
                  │  availability)   │
                  └──────────────────┘
```

## Features

### 1. Multi-Source Input Support

- **Image Recognition**: Upload product photos for Visual AI identification
- **Document Processing**: Extract line items from invoices/quotes (future)
- **URL Crawling**: Single or batch URL price comparison
- **Automatic Fallbacks**: Graceful degradation when primary methods unavailable

### 2. Comprehensive Product Identification

- **Vision API Integration**: Google Cloud Vision Product Search
- **Schema.org Parsing**: JSON-LD structured data extraction
- **Pattern Matching**: Regex-based product/price detection
- **Identifier Extraction**: SKU, GTIN, brand, model, MPN

### 3. Multi-Retailer Price Discovery

Supported retailers:
- Amazon
- Walmart
- eBay
- Target
- Extensible via filters

### 4. Data Normalization

All offers return consistent structure:
```json
{
  "retailer": "amazon.com",
  "url": "https://www.amazon.com/...",
  "price": 199.99,
  "currency": "USD",
  "availability": "in_stock",
  "last_checked": "2025-12-01T13:45:00Z"
}
```

## Usage

### Basic Usage

```php
// Image-based lookup
$result = $assistant->call_tool( 'lookup_product_price', [
    'image_attachment_id' => 123,
    'max_results_per_item' => 5,
    'currency' => 'USD'
] );

// URL-based comparison
$result = $assistant->call_tool( 'lookup_product_price', [
    'url' => 'https://www.amazon.com/product/B09ABC123',
    'max_results_per_item' => 5
] );

// Batch URL comparison
$result = $assistant->call_tool( 'lookup_product_price', [
    'urls' => [
        'https://www.amazon.com/product/B09ABC123',
        'https://www.walmart.com/ip/12345678',
        'https://www.target.com/p/A-87654321'
    ],
    'preferred_retailers' => ['amazon.com', 'walmart.com']
] );
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `image_attachment_id` | integer | No* | WordPress Media Library attachment ID of product image |
| `document_attachment_id` | integer | No* | WordPress Media Library attachment ID of document (invoice/quote) |
| `urls` | array | No* | Array of product page URLs to compare (max 20) |
| `url` | string | No* | Single product URL (convenience parameter) |
| `max_results_per_item` | integer | No | Maximum offers per product (1-10, default: 5) |
| `preferred_retailers` | array | No | Retailer domains to prioritize (e.g., ["amazon.com"]) |
| `currency` | string | No | Preferred currency code (e.g., "USD", "EUR") |
| `locale` | string | No | Locale for results (e.g., "en-US", "en-GB") |

*At least one input source required

### Response Structure

```json
{
  "items": [
    {
      "query_source": "image|document|url",
      "source_ref": "attachment:123 OR https://...",
      "identified_product": {
        "title": "Apple AirPods Pro (2nd generation)",
        "brand": "Apple",
        "model": "A2931",
        "identifiers": {
          "gtin": "194253482175",
          "asin": "B0D123XYZ"
        }
      },
      "offers": [
        {
          "retailer": "amazon.com",
          "url": "https://www.amazon.com/...",
          "price": 199.99,
          "currency": "USD",
          "availability": "in_stock",
          "shipping": "Free Prime shipping",
          "last_checked": "2025-12-01T13:45:00Z"
        },
        {
          "retailer": "walmart.com",
          "url": "https://www.walmart.com/...",
          "price": 189.99,
          "currency": "USD",
          "availability": "in_stock",
          "last_checked": "2025-12-01T13:45:00Z"
        }
      ]
    }
  ],
  "metadata": {
    "total_items": 1,
    "max_results_per_item": 5,
    "currency": "USD",
    "locale": "en-US",
    "timestamp": "2025-12-01 13:45:00"
  }
}
```

## Assistant Prompts

### Example: Image Price Lookup

```
Please upload a photo of the product you'd like to price check.
```

Then call:
```
lookup_product_price with image_attachment_id from the uploaded image
```

### Example: URL Comparison

```
Compare prices for this product across retailers:
https://www.amazon.com/Apple-AirPods-Pro-2nd-Generation/dp/B0D123XYZ
```

### Example: Multi-URL Batch

```
Find the best price for these items:
1. https://www.amazon.com/product/A
2. https://www.walmart.com/ip/B
3. https://www.target.com/p/C
```

## Integration with Enhanced scrape_product

The core `scrape_product` tool has been enhanced to support price lookup functionality:

### New Features

1. **Schema.org JSON-LD Extraction**
   - Automatically parses `<script type="application/ld+json">` tags
   - Extracts Product schema with offers, pricing, availability
   - Supports @graph structures

2. **Price Detection**
   - Multiple extraction methods (structured data → selector → patterns)
   - Multi-currency support (USD, EUR, GBP)
   - Fallback regex patterns for non-structured sites

3. **Product Identifiers**
   - SKU, GTIN, brand, model, MPN
   - Required for accurate price comparison

### Enhanced scrape_product Usage

```php
$result = $assistant->call_tool( 'scrape_product', [
    'url' => 'https://example.com/product',
    'extract_structured_data' => true,  // NEW: Enable Schema.org parsing
    'price_selector' => '.product-price', // Optional: CSS selector for price
    'download_images' => true
] );

// Returns:
{
    "title": "Product Name",
    "price": 99.99,
    "currency": "USD",
    "availability": "in_stock",
    "brand": "BrandName",
    "sku": "ABC-123",
    "gtin": "1234567890123",
    "structured_data": { ... }, // Full Schema.org data
    "image_urls": [ ... ],
    "media_ids": [ ... ]
}
```

## Configuration

### Required Settings

1. **Crawl4AI Integration**
   - Required for URL crawling and price extraction
   - Configure in: Settings → WP oOS → Crawl4AI Base URL

2. **Google Cloud Vision API** (Optional)
   - Required for image-based product identification
   - Configure credentials in Settings → WP oOS → Vision API

### Optional Settings

- **Retailer URL Patterns**: Customize via `wp_mcp_ai_pro_lookup_product_price_retailers` filter
- **Price Caching**: Results cached for 15 minutes by default
- **Rate Limits**: 10 requests/minute, 100 requests/hour (configurable)

## Performance Considerations

### Timeouts

- **Single item**: 60 seconds (recommended)
- **Batch mode**: 300 seconds (max execution time)

### Caching

Results are cached with 15-minute TTL:
```php
// Cache key format
$cache_key = 'wp_mcp_ai_price_lookup_' . md5( $retailer . $product_identifier );
```

### Async Execution

For large batches (> 5 URLs), the tool automatically uses async execution with SSE progress updates.

## Error Handling

### Common Errors

| Error Code | Cause | Solution |
|------------|-------|----------|
| `wp_mcp_ai_missing_input` | No input source provided | Provide at least one: image, document, or URL |
| `wp_mcp_ai_invalid_image` | Invalid attachment ID | Verify attachment exists |
| `wp_mcp_ai_no_vision_tool` | Vision API unavailable | Configure Google Cloud Vision or use URL input |
| `wp_mcp_ai_missing_dependency` | Crawl4AI not configured | Set up Crawl4AI in plugin settings |

### Graceful Degradation

The tool implements fallback chains:

1. **Image Identification**
   - Vision Product Search → Vision Object Localization → Generic labels

2. **Price Extraction**
   - Schema.org JSON-LD → CSS selector → Regex patterns

3. **Product Data**
   - Structured data → DOM parsing → Text pattern matching

## Security

### Capability Requirements

- **User authentication**: Required (no guest access)
- **Minimum capability**: `read` (customizable via filters)
- **Multisite**: Enforces blog membership

### Input Validation

- All URLs validated for scheme (HTTP/HTTPS only)
- Attachment IDs verified against Media Library
- Price values sanitized and validated

### Rate Limiting

Built-in rate limits prevent abuse:
- 10 requests/minute per user
- 100 requests/hour per user
- 3 concurrent requests max

## Extending the Tool

### Custom Retailers

Add custom retailer search patterns:

```php
add_filter( 'wp_mcp_ai_pro_lookup_product_price_retailers', function( $retailers ) {
    $retailers['bestbuy.com'] = 'https://www.bestbuy.com/site/searchpage.jsp?st={query}';
    return $retailers;
} );
```

### Custom Price Patterns

Add custom price extraction patterns:

```php
add_filter( 'wp_mcp_ai_pro_price_patterns', function( $patterns ) {
    $patterns[] = '/PRICE:\s*\$([0-9,.]+)/i';
    return $patterns;
} );
```

### Custom Caching

Adjust cache TTL:

```php
add_filter( 'wp_mcp_ai_pro_price_cache_ttl', function( $ttl ) {
    return 30 * MINUTE_IN_SECONDS; // 30 minutes
} );
```

## Troubleshooting

### No prices found

1. Check Crawl4AI connection
2. Verify retailer URLs are accessible
3. Test with known working URL
4. Check debug logs

### Vision API errors

1. Verify Google Cloud credentials
2. Check Vision API is enabled
3. Test with simple product image
4. Review API quota limits

### Slow performance

1. Reduce `max_results_per_item`
2. Use `preferred_retailers` to limit searches
3. Enable caching
4. Consider async execution for batches

## Best Practices

1. **Use image input for unknown products**: Vision API can identify products you don't know the name of
2. **Batch URLs efficiently**: Combine related products in single request
3. **Set preferred retailers**: Focus on retailers your users care about
4. **Cache results**: Don't re-query immediately
5. **Handle errors gracefully**: Provide fallback options to users
6. **Monitor rate limits**: Track usage to avoid hitting limits

## Future Enhancements

Planned features:
- Document processing (invoice/quote line item extraction)
- Price history tracking
- Price drop alerts
- Additional retailer integrations
- Currency conversion API integration
- Product review/rating aggregation

## Related Documentation

- [Tool Reference](tool-reference.md)
- [Crawl4AI Integration](async-tool-execution-guide.md)
- [Vision API Setup](gemini-api-enhancements.md)
- [Rate Limiting](rate-limit-protection.md)
- [Pro Tools Overview](FEATURE-MATRIX-CORE-PRO.md)
