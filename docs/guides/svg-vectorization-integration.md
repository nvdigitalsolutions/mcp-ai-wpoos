# SVG Vectorization - Integration Guide for Tools

## Overview

The SVG vectorization feature can now be easily added to any tool that generates raster images (PNG/JPG). This document explains how to integrate the `WP_MCP_AI_Tool_SVG_Vectorizer` trait into existing image generation tools.

## Trait Location

**File:** `includes/traits/trait-wp-mcp-ai-tool-svg-vectorizer.php`

## Tools That Can Benefit

Based on analysis of the codebase, the following tools could be enhanced with SVG vectorization:

### High Priority (Most Beneficial)

1. **`generate_openai_image`** - General image generation
   - Benefits: Logos, icons, illustrations
   - Use cases: Vector logos, scalable graphics, print materials

2. **`generate_gemini_image`** - Gemini image generation
   - Benefits: Vector illustrations, technical diagrams
   - Use cases: Infographics, diagrams, educational materials

3. **`create_chart`** - Chart.js charts
   - Benefits: Scalable charts for reports and presentations
   - Use cases: Professional reports, dashboards, presentations

### Medium Priority

4. **`edit_openai_image`** - Image editing
   - Benefits: Vector output after AI edits
   - Use cases: Logo variations, icon sets

5. **`edit_gemini_image`** - Gemini image editing
   - Benefits: Maintain vector quality through edits
   - Use cases: Design iterations, variations

6. **`create_image_variation`** - Image variations
   - Benefits: Vector versions of variations
   - Use cases: Brand consistency, design systems

## Integration Steps

### Step 1: Add the Trait

```php
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-svg-vectorizer.php';

class Your_Tool implements WP_MCP_AI_Tool_Interface {
    use WP_MCP_AI_Tool_SVG_Vectorizer;
    
    // ... rest of your tool code
}
```

### Step 2: Add Output Format Parameter

Add an `output_format` parameter to your tool's schema:

```php
public function get_parameters_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            // ... existing parameters ...
            'output_format' => array(
                'type'        => 'string',
                'description' => __( 'Output format for the image', 'wp-mcp-ai' ),
                'enum'        => array( 'png', 'svg', 'both' ),
                'default'     => 'png',
            ),
        ),
    );
}
```

### Step 3: Call Vectorization in Execute Method

After generating your raster image, add SVG conversion:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... your existing image generation code ...
    
    // $base64_image_data contains your generated PNG/JPG
    
    $output_format = isset( $arguments['output_format'] ) 
        ? sanitize_text_field( $arguments['output_format'] ) 
        : 'png';
    
    // Convert to SVG if requested
    if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
        $svg_result = $this->vectorize_to_svg( $base64_image_data );
        
        if ( ! is_wp_error( $svg_result ) ) {
            // Save SVG as attachment
            $svg_attachment_id = $this->save_svg_attachment(
                $svg_result['svg_data'],
                'your-file-name',
                'Your Image Title',
                $user_id
            );
            
            // Add SVG info to response
            $response['svg_generated'] = true;
            $response['svg_size'] = $svg_result['svg_size'];
            if ( ! is_wp_error( $svg_attachment_id ) ) {
                $response['svg_attachment_id'] = $svg_attachment_id;
                $response['svg_attachment_url'] = wp_get_attachment_url( $svg_attachment_id );
            }
        } else {
            // Include error but don't fail the request
            $response['svg_error'] = $svg_result->get_error_message();
        }
    }
    
    return $response;
}
```

### Step 4: Custom Vectorization Options (Optional)

Override the default vectorizer options for your specific use case:

```php
/**
 * Override default vectorizer options.
 *
 * @return array Custom options.
 */
protected function get_default_vectorizer_options() {
    $defaults = parent::get_default_vectorizer_options();
    
    // Customize for logos (higher precision, sharper corners)
    if ( $this->is_logo_generation() ) {
        $defaults['cornerThreshold'] = 40;  // Sharper corners
        $defaults['pathPrecision'] = 10;    // Higher precision
        $defaults['colorPrecision'] = 8;    // More colors
    }
    
    return $defaults;
}
```

## Available Trait Methods

### `vectorize_to_svg( $base64_data, $options = array() )`

Main vectorization method.

**Parameters:**
- `$base64_data` (string) - Base64 encoded PNG/JPG data
- `$options` (array) - Optional vectorizer options

**Returns:** `array|WP_Error`
- Success: `array( 'svg_data' => string, 'svg_size' => int, 'message' => string )`
- Failure: `WP_Error` object

### `save_svg_attachment( $svg_data, $file_name, $title_prefix, $user_id )`

Saves SVG as WordPress media attachment.

**Parameters:**
- `$svg_data` (string) - SVG content
- `$file_name` (string) - Optional file name
- `$title_prefix` (string) - Title prefix for attachment
- `$user_id` (int) - User ID for attachment author

**Returns:** `int|WP_Error` - Attachment ID or error

### `get_default_vectorizer_options()`

Returns default vectorizer options. Override to customize.

**Returns:** `array` - Vectorizer options

### `find_node_binary()`

Finds Node.js binary path. Handles multiple install locations.

**Returns:** `string|WP_Error` - Node.js path or error

## Vectorizer Options

Available options for customization:

```php
array(
    'colorMode'        => 'color',    // 'color' or 'binary'
    'colorPrecision'   => 6,          // 1-8 (higher = more colors)
    'filterSpeckle'    => 4,          // Remove speckles smaller than N pixels
    'cornerThreshold'  => 60,         // 0-180° (lower = sharper corners)
    'lengthThreshold'  => 4.0,        // Path simplification threshold
    'maxIterations'    => 10,         // Curve fitting iterations
    'spliceThreshold'  => 45,         // Path splicing threshold
    'pathPrecision'    => 8,          // Decimal places in SVG paths
    'mode'             => 'stacked',  // 'stacked' or 'cutout'
)
```

## Optimization Tips by Use Case

### Logos & Icons
```php
'cornerThreshold'  => 40,   // Sharp corners
'pathPrecision'    => 10,   // High precision
'colorPrecision'   => 8,    // Full color range
'filterSpeckle'    => 2,    // Minimal filtering
```

### Technical Diagrams
```php
'cornerThreshold'  => 45,   // Very sharp corners
'pathPrecision'    => 12,   // Maximum precision
'colorMode'        => 'binary', // Black & white
```

### Illustrations
```php
'cornerThreshold'  => 70,   // Smoother curves
'lengthThreshold'  => 5.0,  // More smoothing
'colorPrecision'   => 7,    // Rich colors
```

### Charts & Graphs
```php
'cornerThreshold'  => 50,   // Balanced
'pathPrecision'    => 10,   // Clean lines
'filterSpeckle'    => 3,    // Remove noise
```

## Error Handling

The trait gracefully handles errors:

```php
$svg_result = $this->vectorize_to_svg( $base64_data );

if ( is_wp_error( $svg_result ) ) {
    // SVG failed, but PNG/JPG still works
    // Add error to response for user awareness
    $response['svg_error'] = $svg_result->get_error_message();
} else {
    // SVG succeeded
    $response['svg_data'] = $svg_result['svg_data'];
}

// Always return response (don't fail on SVG error)
return $response;
```

## Example: Adding SVG to generate_openai_image

```php
// In class-wp-mcp-ai-tool-generate-openai-image.php

// 1. Add trait
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-svg-vectorizer.php';

class WP_MCP_AI_Tool_Generate_OpenAI_Image implements ... {
    use WP_MCP_AI_Tool_SVG_Vectorizer;
    
    // 2. Add parameter to schema
    public function get_parameters_schema() {
        return array(
            'properties' => array(
                // ... existing parameters ...
                'output_format' => array(
                    'type' => 'string',
                    'enum' => array( 'png', 'svg', 'both' ),
                    'default' => 'png',
                ),
            ),
        );
    }
    
    // 3. Add vectorization in execute
    public function execute( array $arguments = array(), array $context = array() ) {
        // ... existing code to generate image ...
        
        $output_format = $arguments['output_format'] ?? 'png';
        
        // Vectorize if requested
        if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
            $svg_result = $this->vectorize_to_svg( $base64_image );
            
            if ( ! is_wp_error( $svg_result ) ) {
                $svg_id = $this->save_svg_attachment(
                    $svg_result['svg_data'],
                    $file_name . '-vector',
                    'AI Generated Image',
                    $user_id
                );
                
                $response['svg_attachment_id'] = $svg_id;
                $response['svg_attachment_url'] = wp_get_attachment_url( $svg_id );
            }
        }
        
        return $response;
    }
}
```

## Testing

After integration, test with:

1. **Node.js available:**
   ```json
   {
     "prompt": "A simple logo design",
     "output_format": "both"
   }
   ```
   Expected: Both PNG and SVG attachments

2. **Node.js not available:**
   ```json
   {
     "prompt": "A simple logo design",
     "output_format": "svg"
   }
   ```
   Expected: Error message, PNG fallback

3. **Default (PNG only):**
   ```json
   {
     "prompt": "A simple logo design"
   }
   ```
   Expected: PNG only (backward compatible)

## Performance Considerations

- **Conversion time**: 1-7 seconds depending on complexity
- **Memory**: Temporary files cleaned up automatically
- **Graceful degradation**: SVG failure doesn't break PNG generation
- **Caching**: Consider caching SVG results for repeated conversions

## Future Enhancements

Planned improvements:

1. **Batch vectorization** - Convert multiple images at once
2. **Cloud fallback** - Use API service when Node.js unavailable
3. **Format options** - DXF, EPS export
4. **Quality presets** - "logo", "illustration", "technical" presets
5. **Progress callbacks** - Real-time conversion progress

## Support

For issues or questions:

- **Documentation**: `docs/tools/pro/svg-vectorization.md`
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Trait Source**: `includes/traits/trait-wp-mcp-ai-tool-svg-vectorizer.php`

## Version History

- **1.0.0** (2025-01) - Initial trait implementation
  - Extracted from architectural drawing tool
  - Made reusable for all image generation tools
  - Added comprehensive documentation
