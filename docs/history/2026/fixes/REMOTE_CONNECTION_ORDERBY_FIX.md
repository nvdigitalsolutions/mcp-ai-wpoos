# Remote Connection Tool Fix - HTTP 400 Error

## Issue
The `remote_wp_connection` tool was failing with the error:
```
Tool "remote_wp_connection" execution failed: HTTP error 400: Invalid parameter(s): orderby
```

## Root Cause
A recent PR attempted to sort WooCommerce products by stock status by adding these parameters to the API request:
```php
'orderby' => 'stock_status',
'order'   => 'asc',
```

However, `stock_status` is **NOT** a valid `orderby` parameter in the WooCommerce REST API v3. 

Valid `orderby` values include:
- `date` (default)
- `id`
- `include`
- `title`
- `slug`
- `price`
- `popularity`
- `rating`
- `menu_order`

## Solution
The fix removes the invalid API parameters and implements **client-side sorting** instead:

1. **Removed invalid parameters** from the API request
2. **Added new method** `sort_products_by_stock_status()` that sorts products after fetching them
3. **Sorting priority**: 
   - In-stock items first (priority: 1)
   - On-backorder items second (priority: 2)
   - Out-of-stock items last (priority: 3)

## Code Changes

### Before (Invalid):
```php
$params = array(
    'per_page' => $per_page,
    'page'     => $page,
    'orderby'  => 'stock_status',  // ❌ Invalid parameter
    'order'    => 'asc',            // ❌ Not needed
);
```

### After (Fixed):
```php
$params = array(
    'per_page' => $per_page,
    'page'     => $page,
);

// ... fetch products ...

// Sort products client-side
$products = $this->sort_products_by_stock_status( $products );
```

### New Helper Method:
```php
protected function sort_products_by_stock_status( $products ) {
    if ( ! is_array( $products ) || empty( $products ) ) {
        return $products;
    }

    $stock_priority = array(
        'instock'      => 1,
        'onbackorder'  => 2,
        'outofstock'   => 3,
    );

    usort(
        $products,
        function ( $a, $b ) use ( $stock_priority ) {
            $stock_a = isset( $a->stock_status ) ? $a->stock_status : 'outofstock';
            $stock_b = isset( $b->stock_status ) ? $b->stock_status : 'outofstock';
            
            $priority_a = isset( $stock_priority[ $stock_a ] ) ? $stock_priority[ $stock_a ] : 999;
            $priority_b = isset( $stock_priority[ $stock_b ] ) ? $stock_priority[ $stock_b ] : 999;
            
            return $priority_a - $priority_b;
        }
    );

    return $products;
}
```

## Testing
✅ PHP syntax validated
✅ Sorting logic tested with sample data
✅ In-stock products are prioritized correctly
✅ Products without stock_status are handled gracefully
✅ Code follows WordPress coding standards

## Impact
- **No breaking changes**: The tool still returns products in the same format
- **Performance**: Minimal impact - sorting happens in PHP after fetching a small batch (max 100 products)
- **Behavior**: Products are now correctly sorted by stock status as originally intended
- **Compatibility**: Works with all WooCommerce versions that support REST API v3

## Files Modified
- `addons/pro/includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php`
  - Removed invalid `orderby` and `order` parameters (lines 566-568)
  - Added client-side sorting call (lines 607-609)
  - Added new `sort_products_by_stock_status()` method (lines 829-870)

## Next Steps
1. Deploy the fix to staging/production
2. Test with real remote WooCommerce sites
3. Verify products are sorted correctly (in-stock first)
4. Monitor error logs for any issues

## Reference
- WooCommerce REST API Documentation: https://woocommerce.github.io/woocommerce-rest-api-docs/
- Valid orderby parameters: https://developer.wordpress.org/reference/classes/wp_query/#orderby-parameters
