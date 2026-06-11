# WooCommerce Variable Product Creation Enhancement

**Date:** 2026-02-12  
**Issue:** Variable product attributes not set for variations, no variation creation support  
**Status:** Complete ✅

## Problem Statement

The `create_woo_product` tool had several critical issues preventing proper variable product creation:

1. **Hardcoded `set_variation(false)`**: Line 1019 forced ALL attributes to not be used for variations
2. **No variation flag**: No way to specify which attributes should be used for variations
3. **No variation creation**: Tool couldn't create actual product variations with SKU/price
4. **No global attribute reuse**: Tool didn't check for existing global attributes before creating new ones

## Research Summary

### WooCommerce Best Practices (2024/2025)

**Global vs Local Attributes:**
- **Global attributes** (with `pa_` prefix) should be created at Products > Attributes level
- Reusable across multiple products
- Enable filtering and layered navigation
- Required for creating variations
- Better for SEO and consistency

**Local attributes:**
- Created per-product
- Not reusable
- Cannot be used for variations
- Use only for unique, product-specific info

**Variation Management:**
- Each variation MUST have unique SKU
- Price should be set per variation
- Stock tracking at variation level, not parent
- Use `WC_Product_Variation` class for programmatic creation
- Must call `WC_Product_Variable::sync()` after creating variations

**Attribute Naming:**
- Global attributes use `pa_{slug}` taxonomy format
- Use `wc_attribute_taxonomy_name()` to get proper taxonomy name
- Always check `taxonomy_exists()` before creating new attributes

## Implementation Details

### 1. Schema Enhancements

#### Added `variation` Flag to Attributes
```php
'variation' => array(
    'type'        => 'boolean',
    'description' => __( 'Whether attribute should be used for variations (only for variable products).', 'mcp-ai-wpoos' ),
    'default'     => false,
),
```

#### Added `variations` Parameter
```php
'variations' => array(
    'type'        => 'array',
    'description' => __( 'Array of product variations (only for variable products). Each variation must specify attributes, SKU, and price.', 'mcp-ai-wpoos' ),
    'items'       => array(
        'type'       => 'object',
        'properties' => array(
            'attributes'      => array( /* ... */ ),
            'sku'             => array( /* ... */ ),
            'regular_price'   => array( /* ... */ ),
            'sale_price'      => array( /* ... */ ),
            'stock_quantity'  => array( /* ... */ ),
            'stock_status'    => array( /* ... */ ),
            'manage_stock'    => array( /* ... */ ),
            'weight'          => array( /* ... */ ),
            'length'          => array( /* ... */ ),
            'width'           => array( /* ... */ ),
            'height'          => array( /* ... */ ),
            'description'     => array( /* ... */ ),
        ),
        'required' => array( 'attributes', 'regular_price' ),
    ),
),
```

### 2. Method Updates

#### `set_product_attributes()` - Enhanced
**Changes:**
- Added `$product_type` parameter (default: 'simple')
- Checks for existing global attributes using `taxonomy_exists()`
- Reuses global attributes when they exist
- Creates terms in global taxonomies
- Sets `variation` flag correctly: `'variable' === $product_type && $is_variation`

**Key Logic:**
```php
$taxonomy_name      = wc_attribute_taxonomy_name( $attribute_name );
$global_attr_exists = taxonomy_exists( $taxonomy_name );

if ( $global_attr_exists ) {
    // Use global attribute
    $attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy_name ) );
    $attribute->set_name( $taxonomy_name );
    
    // Create terms in taxonomy
    foreach ( $attribute_data['options'] as $option ) {
        $term = term_exists( $option, $taxonomy_name );
        if ( ! $term ) {
            $term = wp_insert_term( $option, $taxonomy_name );
        }
        // ...
    }
} else {
    // Use local attribute
    $attribute->set_name( $attribute_name );
    $attribute->set_options( array_map( 'sanitize_text_field', $attribute_data['options'] ) );
}

// Set variation flag based on product type
$attribute->set_variation( 'variable' === $product_type && $is_variation );
```

#### `create_product_variations()` - New Method
**Purpose:** Creates product variations for variable products

**Features:**
- Validates product is variable type
- Checks product has attributes defined
- Normalizes attribute names (handles both taxonomy and custom formats)
- Creates `WC_Product_Variation` instances
- Sets all variation properties (SKU, prices, stock, dimensions)
- Calls `WC_Product_Variable::sync()` to update parent
- Provides detailed feedback via `$messages` array

**Validation:**
- Requires `attributes` and `regular_price` for each variation
- Validates attributes exist in parent product
- Skips invalid variations with error messages

**Attribute Mapping:**
```php
// Handles both formats:
// - Taxonomy: 'pa_color' => 'red'
// - Custom: 'attribute_size' => 'large'
foreach ( $product_attributes as $product_attr ) {
    if ( taxonomy_exists( $product_attr_name ) ) {
        $normalized_attributes[ $product_attr_name ] = sanitize_text_field( $attr_value );
    } else {
        $normalized_attributes[ 'attribute_' . $product_attr_name ] = sanitize_text_field( $attr_value );
    }
}
```

### 3. Integration in `execute()` Method

**Before variations:**
```php
// Handle product attributes.
if ( isset( $arguments['attributes'] ) && is_array( $arguments['attributes'] ) ) {
    $this->set_product_attributes( $product_id, $arguments['attributes'], $product_type );
}
```

**Variation creation:**
```php
// Handle product variations (only for variable products).
if ( 'variable' === $product_type && isset( $arguments['variations'] ) && is_array( $arguments['variations'] ) ) {
    $variation_result = $this->create_product_variations( $product_id, $arguments['variations'], $messages );
    if ( is_wp_error( $variation_result ) ) {
        $messages[] = $variation_result->get_error_message();
    }
}
```

## Example Usage

### Creating Variable Product with Variations

```json
{
  "reference": "TSHIRT-001",
  "product_type": "variable",
  "title": "Premium Cotton T-Shirt",
  "description": "High-quality cotton t-shirt",
  "attributes": [
    {
      "name": "Size",
      "options": ["Small", "Medium", "Large"],
      "visible": true,
      "variation": true
    },
    {
      "name": "Color",
      "options": ["Red", "Blue", "Green"],
      "visible": true,
      "variation": true
    }
  ],
  "variations": [
    {
      "attributes": {
        "Size": "Small",
        "Color": "Red"
      },
      "sku": "TSHIRT-S-RED",
      "regular_price": "19.99",
      "stock_status": "instock"
    },
    {
      "attributes": {
        "Size": "Medium",
        "Color": "Blue"
      },
      "sku": "TSHIRT-M-BLUE",
      "regular_price": "21.99",
      "sale_price": "18.99",
      "manage_stock": true,
      "stock_quantity": 50
    },
    {
      "attributes": {
        "Size": "Large",
        "Color": "Green"
      },
      "sku": "TSHIRT-L-GREEN",
      "regular_price": "23.99",
      "weight": "0.5",
      "description": "Extra comfortable fit"
    }
  ]
}
```

## Testing

### Test Coverage
Created comprehensive test file: `tests/test-create-woo-variable-product.php`

**Test Cases:**
1. ✅ Schema supports variable product type
2. ✅ Attributes schema includes variation flag
3. ✅ Schema includes variations parameter
4. ✅ Create variable product with variation attributes
5. ✅ Create variable product with variations (full test)
6. ✅ Simple products don't mark attributes for variations
7. ✅ Variations without required price are skipped
8. ✅ Global attributes are reused when they exist

### Manual Verification
Created verification script: `bin/verify-variable-product-implementation.php`

**Verification Results:**
- ✅ PHP syntax validation
- ✅ Schema structure verification
- ✅ Method implementation checks
- ✅ Integration point verification
- ✅ WooCommerce best practices compliance

## Security & Quality

### Input Sanitization
- ✅ SKU: `wc_clean()` or `sanitize_text_field()`
- ✅ Prices: `normalise_price()` helper
- ✅ Attributes: `wc_sanitize_taxonomy_name()`
- ✅ Dimensions: `sanitize_dimension()` helper
- ✅ Descriptions: `sanitize_html()` helper

### Validation
- ✅ Product type validation
- ✅ Attribute existence validation
- ✅ Required field validation (attributes, regular_price)
- ✅ Stock status enumeration
- ✅ User permission checks (existing)

### Code Review
- ✅ Passed automated code review
- ✅ Fixed formatting issue (empty line removal)
- ✅ No security vulnerabilities detected

## Files Modified

1. **`includes/tools/class-wp-mcp-ai-tool-create-woo-product.php`**
   - Lines 189-220: Added `variation` flag to attributes schema
   - Lines 221-271: Added `variations` parameter to schema
   - Lines 508-518: Updated attribute and variation handling in execute()
   - Lines 1068-1135: Enhanced `set_product_attributes()` method
   - Lines 1137-1290: Added new `create_product_variations()` method

## Files Added

1. **`tests/test-create-woo-variable-product.php`**
   - Comprehensive test suite with 7 test cases
   - Tests schema, creation, validation, and global attribute reuse

2. **`bin/verify-variable-product-implementation.php`**
   - Manual verification script
   - Checks syntax, schema, methods, integration, best practices

3. **`docs/woocommerce-variable-product-fix-2026-02-12.md`** (this file)
   - Complete documentation of changes

## Benefits

1. **Proper Variable Products**: Can now create fully functional variable products with variations
2. **Unique SKUs**: Each variation gets its own SKU for proper inventory management
3. **Global Attribute Reuse**: Checks and reuses existing global attributes for consistency
4. **Complete Control**: Full control over price, stock, dimensions per variation
5. **Industry Standards**: Follows WooCommerce 2024/2025 best practices
6. **Flexibility**: Supports both global and custom attributes
7. **Better SEO**: Global attributes enable better product filtering and categorization

## Backward Compatibility

✅ **Fully backward compatible**
- Simple products work as before
- Attributes without `variation: true` work as before
- Products without `variations` parameter work as before
- No breaking changes to existing functionality

## Future Enhancements (Optional)

1. **Bulk Variation Creation**: Support creating all combinations automatically from attributes
2. **Variation Images**: Enhanced support for assigning images to specific variations
3. **Default Variation**: Support setting a default selected variation
4. **Conditional Logic**: Support for conditional availability based on other attributes
5. **Import/Export**: Better support for importing variations from CSV/Excel

## References

- [WooCommerce Variable Products Documentation](https://woocommerce.com/document/variable-product/)
- [WooCommerce Product Variations Guide](https://wpastra.com/woocommerce-tutorial/woocommerce-product-variations/)
- [WooCommerce Attributes Guide 2025](https://creativethemes.com/blocksy/blog/ultimate-guide-to-woocommerce-product-attributes-in-2025/)
- [Create Product Variations Programmatically](https://rudrastyh.com/woocommerce/create-product-variations-programmatically.html)

## Commits

1. `ee23cff` - Implement variable product creation with variations support
2. `5ab89c0` - Add comprehensive tests for variable product creation
3. `ad014c4` - Fix code formatting issue from review

---

**Implementation verified and complete!** ✅
