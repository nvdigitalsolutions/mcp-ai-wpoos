# Node.js Services for NV oOS Pro

This directory contains Node.js microservices that implement NPM package integrations for the NV oOS Pro addon.

## Overview

These services act as bridges between WordPress/PHP and Node.js NPM packages, enabling advanced functionality like code formatting, email template generation, and video processing.

## Services

### 1. prettier-service.js
Provides code formatting using Prettier.

**Usage:**
```bash
# Format code
node prettier-service.js format '{"code":"function hello(){console.log(\"test\")}","options":{"parser":"babel"}}'

# Check syntax
node prettier-service.js check '{"code":"const x = 1;","parser":"babel"}'
```

### 2. mjml-service.js
Provides responsive email template generation using MJML.

**Usage:**
```bash
# Compile MJML to HTML
node mjml-service.js compile '{"mjml":"<mjml><mj-body><mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section></mj-body></mjml>","options":{}}'

# Validate MJML
node mjml-service.js validate '{"mjml":"<mjml>...</mjml>"}'
```

### 3. ffmpeg-service.js
Provides video processing using fluent-ffmpeg.

**Usage:**
```bash
# Get video metadata
node ffmpeg-service.js metadata '{"video_path":"/path/to/video.mp4"}'

# Transcode video
node ffmpeg-service.js transcode '{"video_path":"/path/to/input.mp4","output_path":"/path/to/output.mp4","options":{"codec":"libx264","size":"1280x720"}}'
```

## Integration with WordPress

These services are called via WordPress filters. See `docs/NPM_INTEGRATION_GUIDE.md` in the parent directory for complete integration examples.

### Quick Integration Example

Add to your theme's `functions.php`:

```php
// Prettier integration
add_filter( 'wp_mcp_ai_prettier_format_code', function( $result, $params ) {
    if ( false !== $result ) return $result;
    
    $service_path = WP_MCP_AI_PRO_PATH . 'node-services/prettier-service.js';
    $cmd = sprintf(
        'node %s format %s 2>&1',
        escapeshellarg( $service_path ),
        escapeshellarg( json_encode( $params ) )
    );
    
    exec( $cmd, $output, $return_code );
    
    if ( 0 !== $return_code ) {
        $error = json_decode( implode( "\n", $output ), true );
        return new WP_Error( 'prettier_failed', $error['error'] ?? 'Unknown error' );
    }
    
    return implode( "\n", $output );
}, 10, 2 );
```

## Requirements

- Node.js 14+
- NPM packages installed in parent directory (`npm install` in `addons/pro/`)

## Testing

Test each service directly from command line:

```bash
# Test Prettier
node prettier-service.js format '{"code":"const x=1","options":{"parser":"babel"}}'

# Test MJML
node mjml-service.js compile '{"mjml":"<mjml><mj-body><mj-section><mj-column><mj-text>Test</mj-text></mj-column></mj-section></mj-body></mjml>","options":{}}'

# Test FFmpeg (requires video file)
node ffmpeg-service.js metadata '{"video_path":"test.mp4"}'
```

## Security Considerations

1. **Input Validation**: Always validate and sanitize input before passing to Node.js
2. **Path Traversal**: Ensure file paths are validated and restricted to allowed directories
3. **Command Injection**: Use `escapeshellarg()` when building shell commands
4. **Resource Limits**: Set appropriate timeouts and memory limits for long-running operations

## Performance

- Services are stateless and can be called multiple times
- Consider implementing result caching for expensive operations
- For production, consider using a queue system (WordPress cron) for long-running tasks

## Troubleshooting

### "Cannot find module 'prettier'"
Run `npm install` in the parent directory (`addons/pro/`)

### "FFmpeg not found"
Install FFmpeg binary:
- Ubuntu/Debian: `sudo apt-get install ffmpeg`
- macOS: `brew install ffmpeg`
- Windows: Download from https://ffmpeg.org/download.html

### Permission denied
Make scripts executable:
```bash
chmod +x *.js
```

## Support

See the main documentation in `docs/NPM_INTEGRATION_GUIDE.md` for detailed integration examples and troubleshooting.
