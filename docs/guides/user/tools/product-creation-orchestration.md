# Product Creation with Multi-Step Orchestration

**Tool:** `create_woo_product`  
**Feature:** Multi-step orchestration mode  
**Status:** Available in latest version

## Quick Start

Enable orchestration for automatic validation:

```php
$result = $tool->execute(array(
    'reference'          => 'SKU-12345',
    'title'              => 'My Product',
    'local_price'        => '29.99',
    'orchestration_mode' => true,
), $context);
```

## New Parameters

- `orchestration_mode` (boolean): Enable 5-step workflow
- `auto_research` (boolean): Auto-research product information
- `enhance_content` (boolean): AI-powered content enhancement
- `optimize` (boolean): Post-creation optimization

## Full Automation Example

```php
$result = $tool->execute(array(
    'reference'          => 'AUTO-001',
    'title'              => 'Smart Fitness Watch',
    'orchestration_mode' => true,
    'auto_research'      => true,
    'enhance_content'    => true,
    'optimize'           => true,
), $context);
```

This will:
1. Research product details online
2. Validate all data
3. Generate AI descriptions  
4. Create the product
5. Add featured image and SEO

## Benefits

- ✅ Automatic SKU uniqueness validation
- ✅ Price format validation
- ✅ AI-powered descriptions
- ✅ SEO optimization
- ✅ Featured image generation
- ✅ Detailed error messages

## Backward Compatibility

Default behavior is unchanged. Orchestration is opt-in via the `orchestration_mode` parameter.

---

For complete documentation, see the developer guides in `docs/guides/developer/`.
