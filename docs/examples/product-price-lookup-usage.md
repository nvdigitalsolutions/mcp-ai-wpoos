# Product Price Lookup - Usage Examples

## Table of Contents

1. [Image-Based Price Lookup](#image-based-price-lookup)
2. [URL Price Comparison](#url-price-comparison)
3. [Batch URL Processing](#batch-url-processing)
4. [Enhanced scrape_product](#enhanced-scrape_product)
5. [Real-World Scenarios](#real-world-scenarios)

## Image-Based Price Lookup

### Example 1: Simple Product Photo

**User uploads a photo of AirPods and asks:**
> "How much do these cost?"

**Assistant response:**
```json
{
  "tool": "lookup_product_price",
  "arguments": {
    "image_attachment_id": 456,
    "max_results_per_item": 5,
    "currency": "USD"
  }
}
```

**Result:**
```
I found these are Apple AirPods Pro (2nd generation). Here are the current prices:

1. Amazon: $199.99 - In Stock (Free Prime shipping)
2. Walmart: $189.99 - In Stock
3. Target: $199.99 - In Stock

The best price is at Walmart for $189.99, saving you $10.
```

## URL Price Comparison

### Example 2: Single URL Lookup

**User asks:**
> "Is this a good price? https://www.amazon.com/product/ABC"

**Tool checks price across retailers and returns comparison**

## Enhanced scrape_product

### Example 3: E-commerce Site with Schema.org

```php
$result = $assistant->call_tool( 'scrape_product', [
    'url' => 'https://www.nike.com/t/air-max-90/ABC123',
    'extract_structured_data' => true,
    'download_images' => true
] );
```

**Response includes structured pricing data from Schema.org JSON-LD**

See full documentation in `/docs/PRODUCT-PRICE-LOOKUP-GUIDE.md`
