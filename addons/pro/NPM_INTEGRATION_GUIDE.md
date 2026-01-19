# NPM Package Enhanced Tools - Integration Guide

This guide explains how to integrate and use the newly enhanced tools that leverage NPM packages in the Pro addon.

## Overview

The Pro addon now includes 3 new tools and 3 helper services that utilize NPM packages installed in the base plugin's `node_modules/` directory (managed from the root `package.json`):

### New Tools (v1.1.0)

1. **format_code_prettier** - Code formatting using Prettier
2. **generate_email_template** - Responsive email templates using MJML
3. **transcode_video** - Video format conversion using fluent-ffmpeg

### New Services

1. **WP_MCP_AI_Fluent_FFmpeg_Service** - Video processing service
2. **WP_MCP_AI_Prettier_Service** - Code formatting service
3. **WP_MCP_AI_MJML_Service** - Email template generation service

## Architecture

All NPM package integrations follow a consistent pattern:

### 1. Service Layer
Each NPM package has a dedicated PHP service class that acts as a bridge between WordPress/PHP and Node.js:

```php
// Example: Prettier Service
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-prettier-service.php';
$prettier_service = new WP_MCP_AI_Prettier_Service();

if ( ! $prettier_service->is_available() ) {
    // Handle unavailable package
}

$formatted_code = $prettier_service->format_code( $code, $options );
```

### 2. Filter-Based Integration
Services use WordPress filters to allow custom Node.js implementations:

```php
/**
 * Filter to allow custom Prettier code formatting.
 *
 * @param string|false $result Formatted code or false.
 * @param array        $params Formatting parameters.
 */
$result = apply_filters( 'wp_mcp_ai_prettier_format_code', false, $params );
```

### 3. Tool Layer
Tools use services to provide AI assistant functionality:

```php
class WP_MCP_AI_Tool_Format_Code_Prettier implements WP_MCP_AI_Tool_Interface {
    public function execute( array $arguments = array(), array $context = array() ) {
        $prettier_service = new WP_MCP_AI_Prettier_Service();
        return $prettier_service->format_code( $arguments['code'], $options );
    }
}
```

## Implementation Guide

To implement the Node.js integration for these tools, you need to create a microservice that responds to the WordPress filters.

### Example: Prettier Integration

#### Step 1: Create Node.js Script

Create `addons/pro/node-services/prettier-service.js`:

```javascript
const prettier = require('prettier');

/**
 * Format code using Prettier
 * @param {string} code - Code to format
 * @param {object} options - Prettier options
 * @returns {string} Formatted code
 */
function formatCode(code, options) {
    try {
        return prettier.format(code, options);
    } catch (error) {
        throw new Error(`Prettier formatting failed: ${error.message}`);
    }
}

// Export for use in WordPress integration
module.exports = { formatCode };
```

#### Step 2: Create WordPress Filter Handler

Add to your theme's `functions.php` or custom plugin:

```php
add_filter( 'wp_mcp_ai_prettier_format_code', 'my_prettier_integration', 10, 2 );

function my_prettier_integration( $result, $params ) {
    if ( false !== $result ) {
        return $result; // Already handled
    }

    // Path to Node.js service
    $service_path = WP_MCP_AI_PRO_PATH . 'node-services/prettier-service.js';
    
    // Execute Node.js script
    $node_cmd = sprintf(
        'node -e "const service = require(\'%s\'); console.log(service.formatCode(%s, %s));"',
        $service_path,
        json_encode( $params['code'] ),
        json_encode( $params['options'] )
    );
    
    $output = shell_exec( $node_cmd );
    
    if ( empty( $output ) ) {
        return new WP_Error(
            'prettier_failed',
            'Prettier formatting failed'
        );
    }
    
    return trim( $output );
}
```

### Example: Fluent-FFmpeg Integration

#### Step 1: Create Node.js Script

Create `addons/pro/node-services/ffmpeg-service.js`:

```javascript
const ffmpeg = require('fluent-ffmpeg');
const path = require('path');

/**
 * Get video metadata
 */
function getMetadata(videoPath) {
    return new Promise((resolve, reject) => {
        ffmpeg.ffprobe(videoPath, (err, metadata) => {
            if (err) reject(err);
            else resolve(metadata);
        });
    });
}

/**
 * Extract frames from video
 */
function extractFrames(videoPath, options) {
    return new Promise((resolve, reject) => {
        const outputFolder = options.folder || '/tmp';
        const timestamps = options.timestamps || [];
        const outputPattern = options.filename || 'frame-%i.jpg';
        
        let command = ffmpeg(videoPath);
        
        if (timestamps.length > 0) {
            // Extract specific timestamps
            timestamps.forEach(timestamp => {
                command = command.screenshots({
                    timestamps: [timestamp],
                    filename: outputPattern,
                    folder: outputFolder,
                    size: options.size || '640x?'
                });
            });
        } else {
            // Extract N frames evenly distributed
            command = command.screenshots({
                count: options.count || 10,
                filename: outputPattern,
                folder: outputFolder,
                size: options.size || '640x?'
            });
        }
        
        command
            .on('end', () => resolve({ folder: outputFolder, pattern: outputPattern }))
            .on('error', (err) => reject(err));
    });
}

/**
 * Transcode video
 */
function transcodeVideo(videoPath, outputPath, options) {
    return new Promise((resolve, reject) => {
        let command = ffmpeg(videoPath);
        
        // Set video codec
        if (options.codec) {
            command = command.videoCodec(options.codec);
        }
        
        // Set audio codec
        if (options.audio_codec) {
            command = command.audioCodec(options.audio_codec);
        }
        
        // Set bitrate
        if (options.bitrate) {
            command = command.videoBitrate(options.bitrate);
        }
        
        // Set size (resolution)
        if (options.size) {
            command = command.size(options.size);
        }
        
        // Set FPS
        if (options.fps) {
            command = command.fps(options.fps);
        }
        
        command
            .output(outputPath)
            .on('end', () => resolve(outputPath))
            .on('error', (err) => reject(err))
            .run();
    });
}

module.exports = {
    getMetadata,
    extractFrames,
    transcodeVideo
};
```

#### Step 2: Create WordPress Filter Handlers

```php
// Metadata extraction
add_filter( 'wp_mcp_ai_fluent_ffmpeg_get_metadata', 'my_ffmpeg_metadata', 10, 2 );
function my_ffmpeg_metadata( $result, $params ) {
    if ( false !== $result ) return $result;
    
    $service_path = WP_MCP_AI_PRO_PATH . 'node-services/ffmpeg-service.js';
    $node_cmd = sprintf(
        'node -e "const service = require(\'%s\'); service.getMetadata(\'%s\').then(m => console.log(JSON.stringify(m)));"',
        $service_path,
        $params['video_path']
    );
    
    $output = shell_exec( $node_cmd );
    return json_decode( $output, true );
}

// Frame extraction
add_filter( 'wp_mcp_ai_fluent_ffmpeg_extract_frames', 'my_ffmpeg_frames', 10, 2 );
function my_ffmpeg_frames( $result, $params ) {
    if ( false !== $result ) return $result;
    
    $service_path = WP_MCP_AI_PRO_PATH . 'node-services/ffmpeg-service.js';
    $node_cmd = sprintf(
        'node -e "const service = require(\'%s\'); service.extractFrames(\'%s\', %s).then(r => console.log(JSON.stringify(r)));"',
        $service_path,
        $params['video_path'],
        json_encode( $params['options'] )
    );
    
    $output = shell_exec( $node_cmd );
    return json_decode( $output, true );
}

// Video transcoding
add_filter( 'wp_mcp_ai_fluent_ffmpeg_transcode_video', 'my_ffmpeg_transcode', 10, 2 );
function my_ffmpeg_transcode( $result, $params ) {
    if ( false !== $result ) return $result;
    
    $service_path = WP_MCP_AI_PRO_PATH . 'node-services/ffmpeg-service.js';
    $node_cmd = sprintf(
        'node -e "const service = require(\'%s\'); service.transcodeVideo(\'%s\', \'%s\', %s).then(r => console.log(r));"',
        $service_path,
        $params['video_path'],
        $params['output_path'],
        json_encode( $params['options'] )
    );
    
    $output = shell_exec( $node_cmd );
    return trim( $output );
}
```

## Available Filters

### Prettier Service
- `wp_mcp_ai_prettier_format_code` - Format code
- `wp_mcp_ai_prettier_check_syntax` - Check syntax

### MJML Service
- `wp_mcp_ai_mjml_compile` - Compile MJML to HTML
- `wp_mcp_ai_mjml_validate` - Validate MJML markup

### Fluent-FFmpeg Service
- `wp_mcp_ai_fluent_ffmpeg_get_metadata` - Get video metadata
- `wp_mcp_ai_fluent_ffmpeg_extract_frames` - Extract video frames
- `wp_mcp_ai_fluent_ffmpeg_generate_thumbnail` - Generate thumbnail
- `wp_mcp_ai_fluent_ffmpeg_transcode_video` - Transcode video
- `wp_mcp_ai_fluent_ffmpeg_extract_audio` - Extract audio track

## Tool Usage Examples

### 1. Format Code with Prettier

```json
{
    "tool": "format_code_prettier",
    "arguments": {
        "code": "function hello(){console.log('Hello World')}",
        "language": "javascript",
        "tab_width": 2,
        "use_tabs": true,
        "single_quote": true
    }
}
```

Response:
```json
{
    "success": true,
    "formatted_code": "function hello() {\n\tconsole.log('Hello World');\n}\n",
    "language": "javascript",
    "original_lines": 1,
    "formatted_lines": 3
}
```

### 2. Generate Email Template

```json
{
    "tool": "generate_email_template",
    "arguments": {
        "template_type": "marketing",
        "subject": "New Product Launch",
        "preview_text": "Check out our latest product",
        "components": [
            {
                "type": "text",
                "content": "<h1>Exciting News!</h1><p>We're launching a new product.</p>",
                "attributes": {
                    "font-size": "18px",
                    "color": "#333333"
                }
            },
            {
                "type": "button",
                "text": "Learn More",
                "attributes": {
                    "href": "https://example.com",
                    "background-color": "#0066cc",
                    "color": "#ffffff"
                }
            }
        ],
        "branding": {
            "logo_url": "https://example.com/logo.png",
            "company_name": "Example Corp",
            "primary_color": "#0066cc"
        },
        "output_format": "html"
    }
}
```

### 3. Transcode Video

```json
{
    "tool": "transcode_video",
    "arguments": {
        "attachment_id": 123,
        "preset": "youtube",
        "save_to_media": true
    }
}
```

Or with custom settings:
```json
{
    "tool": "transcode_video",
    "arguments": {
        "video_url": "https://example.com/video.mov",
        "output_format": "mp4",
        "resolution": "1920x1080",
        "video_codec": "libx264",
        "audio_codec": "aac",
        "video_bitrate": "5000k",
        "fps": 30,
        "save_to_media": true
    }
}
```

## Security Considerations

1. **shell_exec Usage**: The example implementations use `shell_exec()` which requires careful input sanitization. In production, consider using a queue-based system or dedicated microservice.

2. **File Access**: Ensure proper file permission checks and path validation when accessing video files or creating output files.

3. **Resource Limits**: Video transcoding and image processing are resource-intensive. Implement proper timeout and memory limits.

4. **User Permissions**: All tools check WordPress capabilities before execution. Maintain these checks when implementing custom handlers.

## Performance Optimization

1. **Caching**: Implement result caching for expensive operations like video metadata extraction or code formatting.

2. **Async Processing**: For long-running operations (video transcoding), consider using WordPress cron or a job queue system.

3. **CDN Integration**: For generated assets (formatted code, email templates), consider CDN storage for better performance.

## Troubleshooting

### Node.js Not Available
```
Error: Prettier is not available. Please ensure Node.js and Prettier package are installed.
```

**Solution**: Install Node.js and run `npm install` in the `addons/pro/` directory.

### FFmpeg Not Available
```
Error: Fluent-ffmpeg is not available. Please ensure Node.js, fluent-ffmpeg package, and FFmpeg binary are installed.
```

**Solution**: 
1. Install FFmpeg binary: `sudo apt-get install ffmpeg` (Linux) or `brew install ffmpeg` (macOS)
2. Install fluent-ffmpeg: `cd addons/pro && npm install`

### Filter Not Implemented
```
Error: Prettier code formatting requires Node.js integration. Please implement the wp_mcp_ai_prettier_format_code filter.
```

**Solution**: Add the filter implementation as shown in the examples above.

## Future Enhancements

The following enhancements are planned for future releases:

1. **Sharp Integration** - Already implemented in `optimize_image_sharp` tool, can be further enhanced
2. **KaTeX Integration** - Already implemented in `render_math_equation` tool for quiz systems
3. **ICS Integration** - Already implemented in `export_calendar_ics` tool for project management
4. **Chart.js Integration** - Already implemented in `generate_health_chart` tool
5. **Turf.js Integration** - Already implemented in `analyze_geospatial` tool for places management

## Support

For questions or issues related to NPM package integrations:

1. Check the `INTEGRATION_BEST_PRACTICES.md` file in the pro addon directory
2. Review the tool implementations in `addons/pro/includes/tools/`
3. Consult the service classes in `addons/pro/includes/services/`
4. Open an issue on the GitHub repository with the `enhancement` label

---

**Version**: 1.1.0  
**Last Updated**: January 18, 2026  
**Maintainer**: NV Digital Solutions
