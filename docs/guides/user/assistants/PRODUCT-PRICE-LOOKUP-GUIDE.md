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
- **Document Processing**: Extract line items from invoices/quotes (PDF, Word, Excel, TXT, CSV)
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

### Example: Document Processing (Invoice/Quote)

```
Please upload your invoice/quote document and I'll check current market prices for all items.
```

Then the assistant calls:
```
lookup_product_price with document_attachment_id from the uploaded document
```

**Supported document formats:**
- PDF (.pdf)
- Microsoft Word (.docx, .doc)
- Microsoft Excel (.xlsx, .xls)
- Plain text (.txt)
- CSV (.csv)

**How it works:**
1. Document text is extracted using LLM-based document processing
2. LLM analyzes text to extract line items with product details
3. For each line item, current prices are looked up across retailers
4. Results show original quoted price vs. current market prices

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

3. **LLM Configuration** (Required for Document Processing)
   - Document text extraction uses the `submit_document_prompt` tool
   - Requires an active LLM provider (OpenAI, Google Gemini, etc.)
   - Line item extraction uses LLM to parse invoice/quote structure
   - Ensure your LLM supports document processing (PDF, Word, Excel)

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

### Document processing errors

1. **"Unsupported document type"**
   - Verify file is PDF, Word, Excel, TXT, or CSV
   - Check MIME type is correct

2. **"No text content could be extracted"**
   - Ensure LLM provider supports document processing
   - Try converting to plain text first
   - Check if PDF is image-based (requires OCR)

3. **"Failed to parse JSON from LLM response"**
   - LLM may not have returned structured data
   - Try again with simpler document
   - Check document has clear line item structure

4. **No line items found**
   - Document may not contain product listings
   - Format may be non-standard
   - Try extracting text first to verify content

## Best Practices

1. **Use image input for unknown products**: Vision API can identify products you don't know the name of
2. **Batch URLs efficiently**: Combine related products in single request
3. **Set preferred retailers**: Focus on retailers your users care about
4. **Cache results**: Don't re-query immediately
5. **Handle errors gracefully**: Provide fallback options to users
6. **Monitor rate limits**: Track usage to avoid hitting limits
7. **Document format matters**: For best results, use structured documents (PDFs with clear tables, Excel spreadsheets)
8. **Verify line items**: Always review LLM-extracted line items for accuracy before querying prices

## Document Processing Details

### Supported File Formats

| Format | Extension | Processing Method | Notes |
|--------|-----------|-------------------|-------|
| PDF | .pdf | LLM document parsing | Works best with text-based PDFs |
| Word | .docx, .doc | LLM document parsing | Supports tables and structured content |
| Excel | .xlsx, .xls | LLM document parsing | Ideal for line-item lists |
| Text | .txt | Direct text extraction | Fastest processing |
| CSV | .csv | Direct text extraction | Good for product lists |

### Line Item Extraction

The tool uses a two-step LLM process:

1. **Text Extraction**: Document is processed to extract all text content
2. **Structured Parsing**: LLM analyzes text to identify line items with:
   - Product description
   - Brand (if mentioned)
   - Model/SKU (if mentioned)
   - Quantity (if mentioned)
   - Unit price (if mentioned)
   - GTIN/UPC (if mentioned)

**Example extraction from invoice:**

Input document:
```
Invoice #12345
Date: 2025-01-15

Line Items:
1. Apple AirPods Pro (2nd Gen) - Qty: 2 - $199.99 each
2. Samsung Galaxy Buds2 Pro - Model SM-R510 - Qty: 1 - $149.99
3. Sony WH-1000XM5 Headphones - GTIN: 4548736139847 - Qty: 3 - $329.99 each
```

Extracted line items:
```json
[
  {
    "description": "Apple AirPods Pro (2nd Gen)",
    "brand": "Apple",
    "model": "",
    "quantity": 2,
    "unit_price": 199.99,
    "gtin": ""
  },
  {
    "description": "Samsung Galaxy Buds2 Pro",
    "brand": "Samsung",
    "model": "SM-R510",
    "quantity": 1,
    "unit_price": 149.99,
    "gtin": ""
  },
  {
    "description": "Sony WH-1000XM5 Headphones",
    "brand": "Sony",
    "model": "WH-1000XM5",
    "quantity": 3,
    "unit_price": 329.99,
    "gtin": "4548736139847"
  }
]
```

## Future Enhancements

Planned features:
- Price history tracking
- Price drop alerts
- Additional retailer integrations
- Currency conversion API integration
- Product review/rating aggregation
- OCR support for image-based PDFs
- Batch document processing (multiple invoices at once)

## Related Documentation

- [Tool Reference](tool-reference.md)
- [Crawl4AI Integration](async-tool-execution-guide.md)
- [Vision API Setup](gemini-api-enhancements.md)
- [Rate Limiting](rate-limit-protection.md)
- [Pro Tools Overview](FEATURE-MATRIX-CORE-PRO.md)
