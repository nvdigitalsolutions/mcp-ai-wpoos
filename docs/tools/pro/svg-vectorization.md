# SVG Vectorization for Architectural Drawings

## Overview

This implementation adds AI-powered raster-to-vector (SVG) conversion to the architectural drawing tool using the **@neplex/vectorizer** npm package. This enables true scalable vector graphics output from AI-generated architectural drawings.

## Installation

```bash
npm install @neplex/vectorizer
```

The package is included in `package.json` dependencies and will be installed automatically with `npm install`.

## How It Works

### 1. AI Image Generation
The tool uses OpenAI DALL-E or Gemini Imagen to generate high-quality architectural drawings as raster images (PNG).

### 2. Raster-to-Vector Conversion
The generated PNG is then converted to SVG using `@neplex/vectorizer`, which:
- Traces the raster image to create vector paths
- Preserves color information and layers
- Optimizes path precision for architectural drawings
- Generates clean, scalable SVG output

### 3. Dual Output
When `output_format: 'both'` is specified, the tool saves:
- PNG attachment (raster original)
- SVG attachment (vectorized version)

## Technical Architecture

### Node.js Script (`bin/vectorize.js`)
- Standalone script that handles vectorization
- Accepts base64 image input
- Configurable precision settings
- Returns JSON output with success/error status

### PHP Integration
- `convert_to_svg()` method orchestrates the conversion
- Writes temporary files for input/output
- Executes Node.js script via `exec()`
- Parses JSON response
- Saves SVG as WordPress attachment

### Vectorization Options

The tool configures vectorization parameters based on drawing type:

#### Floor Plans / Site Plans
- High precision (pathPrecision: 10)
- Sharp corners (cornerThreshold: 45°)
- Minimal smoothing (lengthThreshold: 3.0)

#### Elevations / Sections
- Balanced precision (colorPrecision: 7)
- Moderate corners (cornerThreshold: 50°)

#### Construction Details
- Maximum precision (pathPrecision: 12)
- Very sharp corners (cornerThreshold: 40°)
- Minimal filtering (filterSpeckle: 2)

#### 3D Views (Axonometric/Isometric)
- Smoother curves (cornerThreshold: 70°)
- More smoothing (lengthThreshold: 5.0)

## Why @neplex/vectorizer?

Based on research of available solutions:

### Performance
- **4-5x faster** than imagetracerjs (based on benchmarks)
- Built on VTracer (Rust-based high-performance core)
- Handles large architectural drawings efficiently

### Features
- Modern TypeScript/JavaScript API
- Configurable precision settings
- Color support with adjustable precision
- Hierarchical layer stacking
- Path simplification (polygon/spline)
- Speckle filtering for clean output

### Maintenance
- Actively maintained
- Modern codebase (2024-2025)
- Good documentation
- MIT license

### Alternatives Considered

1. **ImageTracerJS** - Reliable but slower, older codebase
2. **Potrace** - Excellent for black/white but limited color support
3. **ConvertAPI** - Cloud-based, requires paid API subscription
4. **Vectorizer.ai** - Web service, not suitable for server-side automation
5. **Recraft API** - Commercial API, additional costs

## Usage Examples

### Example 1: PNG Only (default)
```json
{
  "drawing_type": "floor_plan",
  "description": "2-bedroom apartment floor plan",
  "output_format": "png"
}
```

### Example 2: SVG Only
```json
{
  "drawing_type": "floor_plan",
  "description": "2-bedroom apartment floor plan",
  "output_format": "svg"
}
```

### Example 3: Both PNG and SVG
```json
{
  "drawing_type": "elevation",
  "description": "Modern commercial building elevation",
  "output_format": "both"
}
```

## Response Format

When SVG conversion succeeds:

```json
{
  "success": true,
  "drawing_type": "floor_plan",
  "output_format": "both",
  "attachment_id": 1234,
  "attachment_url": "https://example.com/.../architectural-floor_plan-123.png",
  "svg_generated": true,
  "svg_size": 245678,
  "svg_attachment_id": 1235,
  "svg_attachment_url": "https://example.com/.../architectural-floor_plan-vector-123.svg"
}
```

When SVG conversion fails (PNG still available):

```json
{
  "success": true,
  "drawing_type": "floor_plan",
  "output_format": "both",
  "attachment_id": 1234,
  "attachment_url": "https://example.com/.../architectural-floor_plan-123.png",
  "svg_error": "Node.js not found. Please install Node.js to enable SVG conversion."
}
```

## Requirements

### Server Requirements
- **Node.js** v14 or higher installed on server
- `exec()` function enabled in PHP
- Write permissions to temp directory

### Package Requirements
- **@neplex/vectorizer** npm package installed
- All npm dependencies installed (`npm install`)

## Error Handling

The tool gracefully handles SVG conversion failures:

1. **Node.js Not Found** - Returns PNG only with error message
2. **Package Not Installed** - Returns PNG only with installation instructions
3. **Vectorization Failed** - Returns PNG only with error details
4. **File System Issues** - Returns error with specific cause

PNG generation always succeeds independently of SVG conversion.

## Performance Considerations

### Conversion Time
- Small drawings (1024x1024): ~1-3 seconds
- Large drawings (1792x1024): ~3-7 seconds
- Complex details: ~5-10 seconds

### File Sizes
- PNG: ~500KB - 2MB (depending on complexity)
- SVG: ~200KB - 1MB (typically 50-70% smaller)

### Server Load
- Temporary files cleaned up immediately
- Process spawning per conversion
- Suitable for on-demand generation
- Consider caching for high-volume use

## Customization

### Filter Hook
Customize vectorization options per drawing type:

```php
add_filter( 'wp_mcp_ai_architectural_drawing_vectorizer_options', function( $options, $drawing_type ) {
    if ( 'floor_plan' === $drawing_type ) {
        // Ultra-high precision for floor plans
        $options['pathPrecision'] = 15;
        $options['cornerThreshold'] = 30;
    }
    return $options;
}, 10, 2 );
```

## Troubleshooting

### "Node.js not found"
Install Node.js on your server:
```bash
# Ubuntu/Debian
sudo apt-get install nodejs npm

# CentOS/RHEL
sudo yum install nodejs npm

# macOS
brew install node
```

### "SVG vectorizer package not installed"
Run npm install:
```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos
npm install
```

### "Vectorization failed"
Check:
1. PHP `exec()` function is enabled
2. Node.js has execution permissions
3. Temporary directory is writable
4. Input image is valid PNG

## Future Enhancements

Potential improvements for future versions:

1. **Batch Vectorization** - Convert multiple drawings at once
2. **DXF Export** - AutoCAD format support
3. **Layer Separation** - Export different elements as separate layers
4. **Dimension Preservation** - Maintain measurement annotations
5. **Style Optimization** - CAD-specific vector output
6. **Cloud Vectorization** - Optional API fallback for servers without Node.js

## References

- [@neplex/vectorizer on npm](https://www.npmjs.com/package/@neplex/vectorizer)
- [VTracer (Rust core)](https://github.com/visioncortex/vtracer)
- [Benchmark comparisons](https://github.com/neplextech/vectorizer#benchmarks)

## Version History

- **1.0.0** (2025-01) - Initial implementation with @neplex/vectorizer
  - Added raster-to-vector conversion
  - Drawing type-specific optimization
  - Dual PNG/SVG output support
  - Graceful error handling
