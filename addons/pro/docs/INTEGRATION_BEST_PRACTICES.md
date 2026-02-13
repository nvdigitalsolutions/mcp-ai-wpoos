# Pro Toolkit NPM Package Integration - Best Practices Guide

**Version**: 1.0  
**Date**: January 18, 2026  
**Status**: Production Best Practices

## Overview

This guide provides comprehensive best practices for integrating and using the 8 NPM packages added to the pro toolkits. These recommendations are based on industry standards, WordPress development patterns, and real-world implementation experience.

---

## Table of Contents

1. [Sharp - Image Processing](#1-sharp---image-processing)
2. [KaTeX - Math Rendering](#2-katex---math-rendering)
3. [ICS - Calendar Generation](#3-ics---calendar-generation)
4. [Chart.js - Data Visualization](#4-chartjs---data-visualization)
5. [Prettier - Code Formatting](#5-prettier---code-formatting)
6. [MJML - Email Templates](#6-mjml---email-templates)
7. [Turf.js - Geospatial Analysis](#7-turfjs---geospatial-analysis)
8. [Fluent-FFmpeg - Video Processing](#8-fluent-ffmpeg---video-processing)

---

## 1. Sharp - Image Processing

### Integration Architecture

**Recommended Approach**: Microservice Architecture
- Host Sharp in a separate Node.js service communicating via HTTP(S) API
- Keeps PHP stack clean and allows independent scaling
- Use authentication tokens and HTTPS for security

### Best Practices

#### A. Background Processing
```php
// WordPress hook for async processing
add_action('add_attachment', 'queue_sharp_processing');

function queue_sharp_processing($attachment_id) {
    // Queue job for background processing
    as_enqueue_async_action('process_with_sharp', array($attachment_id));
}
```

**Why?** Avoid blocking WordPress request/response cycle during compute-intensive operations.

#### B. Modern Format Generation
```javascript
// Generate WebP with fallback
sharp('input.jpg')
  .webp({ quality: 80 })
  .toFile('output.webp')
  .then(() => {
    // Also keep JPEG for fallback
    sharp('input.jpg')
      .jpeg({ quality: 85 })
      .toFile('output.jpg');
  });
```

**Benefits**: 
- WebP reduces file size by 25-35% vs JPEG
- Improves Core Web Vitals and SEO
- Better user experience with faster loads

#### C. WordPress Integration
```php
// Hook into WordPress media workflow
add_filter('wp_generate_attachment_metadata', 'add_sharp_processed_sizes', 10, 2);

function add_sharp_processed_sizes($metadata, $attachment_id) {
    // Call Sharp API to generate optimized versions
    // Store results in $metadata['sizes']
    return $metadata;
}
```

#### D. Error Handling
```javascript
sharp('input.jpg')
  .resize(800, 600)
  .toFile('output.jpg')
  .catch(err => {
    console.error('Sharp processing failed:', err);
    // Log to monitoring service
    // Fall back to original image
  });
```

### Performance Optimization

1. **Memory Management**: Set limits for concurrent processing
2. **Caching**: Store processed images in CDN
3. **Batch Processing**: Use queues for bulk operations
4. **Monitoring**: Track processing times and failure rates

### Security Considerations

- Validate image files before processing
- Sanitize file paths and names
- Implement rate limiting on API endpoints
- Use secure communication (HTTPS only)

---

## 2. KaTeX - Math Rendering

### Server-Side Rendering (Recommended)

**Why SSR?**
- Performance: Pre-rendered HTML loads faster
- SEO: Math visible to search engines
- Accessibility: Works without JavaScript
- Consistency: Identical rendering across browsers

### Best Practices

#### A. WordPress Content Filter
```php
add_filter('the_content', 'render_katex_server_side', 20);

function render_katex_server_side($content) {
    // Find [latex]...[/latex] or $$...$$ patterns
    // Call Node.js service to render with KaTeX
    // Replace with generated HTML
    return $content;
}
```

#### B. Standardize Delimiters
```javascript
// Common delimiter patterns
const delimiters = [
  {left: '$$', right: '$$', display: true},
  {left: '\\[', right: '\\]', display: true},
  {left: '\\(', right: '\\)', display: false},
  {left: '[latex]', right: '[/latex]', display: false}
];
```

#### C. Caching Strategy
```php
// Cache rendered math expressions
$cache_key = 'katex_' . md5($latex_expression);
$rendered = wp_cache_get($cache_key);

if ($rendered === false) {
    $rendered = call_katex_api($latex_expression);
    wp_cache_set($cache_key, $rendered, '', 3600);
}
```

#### D. CSS Delivery
```php
// Enqueue KaTeX CSS
add_action('wp_enqueue_scripts', 'enqueue_katex_styles');

function enqueue_katex_styles() {
    if (has_katex_content()) {
        wp_enqueue_style('katex', 
            'https://cdn.jsdelivr.net/npm/katex@0.16.27/dist/katex.min.css');
    }
}
```

### Error Handling
```javascript
try {
  const html = katex.renderToString(latexString, {
    throwOnError: false,  // Return error message instead of throwing
    displayMode: true
  });
} catch (e) {
  // Fallback: Display LaTeX source with error
  return `<span class="katex-error">${latexString}</span>`;
}
```

### Gutenberg Block Integration
```javascript
// Custom KaTeX block with SSR support
registerBlockType('mcp-ai/katex-equation', {
  attributes: {
    latex: { type: 'string' }
  },
  edit: ({ attributes, setAttributes }) => {
    return (
      <TextControl
        value={attributes.latex}
        onChange={(latex) => setAttributes({ latex })}
      />
    );
  },
  save: ({ attributes }) => {
    // Server-side rendered during save
    return <div dangerouslySetInnerHTML={{ __html: renderKaTeX(attributes.latex) }} />;
  }
});
```

---

## 3. ICS - Calendar Generation

### RFC 5545 Compliance

**Critical**: Always follow iCalendar specification for maximum compatibility.

### Best Practices

#### A. Proper Headers
```php
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="event.ics"');
```

#### B. Unique Identifiers
```javascript
const event = {
  uid: `event-${postId}-${timestamp}@${domain}`,
  start: [2025, 2, 14, 10, 0],
  duration: { hours: 2 },
  title: 'Event Title',
  organizer: { name: 'Name', email: 'email@domain.com' }
};
```

#### C. Timezone Handling
```javascript
// Always specify timezone or use UTC
const event = {
  start: [2025, 2, 14, 10, 0],
  startInputType: 'utc',  // or specify TZID
  timezone: 'America/New_York'
};
```

#### D. WordPress Integration
```php
// Export project timeline as ICS
add_action('wp_ajax_export_project_calendar', 'export_project_to_ics');

function export_project_to_ics() {
    check_ajax_referer('export_calendar');
    
    $project_id = absint($_POST['project_id']);
    $events = get_project_events($project_id);
    
    // Generate ICS via Node.js service
    $ics_content = generate_ics_from_events($events);
    
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="project-' . $project_id . '.ics"');
    echo $ics_content;
    exit;
}
```

### Line Folding
```javascript
// ICS requires lines ≤ 75 octets
function foldLine(line) {
  const maxLen = 75;
  if (line.length <= maxLen) return line;
  
  const folded = [];
  let pos = 0;
  while (pos < line.length) {
    folded.push(line.substring(pos, pos + maxLen));
    pos += maxLen;
  }
  return folded.join('\r\n ');  // Note the space after CRLF
}
```

### Recurring Events
```javascript
// Weekly recurring event
const event = {
  start: [2025, 2, 14, 10, 0],
  duration: { hours: 1 },
  title: 'Weekly Meeting',
  recurrenceRule: 'FREQ=WEEKLY;COUNT=10'  // RFC 5545 format
};
```

---

## 4. Chart.js - Data Visualization

### Healthcare & Data Security

**Critical for Healthcare**: Ensure HIPAA/GDPR compliance when displaying patient data.

### Best Practices

#### A. Data Sanitization
```php
// Anonymize data before sending to frontend
function get_health_metrics_for_chart($patient_id) {
    $data = get_patient_vitals($patient_id);
    
    // Remove PII
    return array(
        'dates' => $data['dates'],
        'values' => $data['values'],
        // No names, birthdates, or identifiers
    );
}
```

#### B. Responsive Charts
```javascript
const config = {
  type: 'line',
  data: chartData,
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
      },
      tooltip: {
        mode: 'index',
        intersect: false
      }
    }
  }
};
```

#### C. Accessibility
```javascript
// Add ARIA labels and descriptions
const canvas = document.getElementById('healthChart');
canvas.setAttribute('role', 'img');
canvas.setAttribute('aria-label', 'Patient vital signs over time');

const chart = new Chart(canvas, config);
```

#### D. WordPress Shortcode
```php
add_shortcode('health_chart', 'render_health_chart');

function render_health_chart($atts) {
    $atts = shortcode_atts(array(
        'patient_id' => 0,
        'metric' => 'blood_pressure',
        'days' => 30
    ), $atts);
    
    wp_enqueue_script('chart-js');
    
    $data = get_health_metrics_for_chart($atts['patient_id']);
    
    return sprintf(
        '<canvas id="chart-%s" width="400" height="200" data-chart=\'%s\'></canvas>',
        esc_attr($atts['metric']),
        esc_attr(json_encode($data))
    );
}
```

### Performance for Large Datasets

```javascript
// Decimation for large datasets
const config = {
  options: {
    parsing: false,  // Use pre-parsed data
    normalized: true,
    decimation: {
      enabled: true,
      algorithm: 'lttb',  // Largest-Triangle-Three-Buckets
      samples: 500
    }
  }
};
```

### Medical Reference Ranges

```javascript
// Add annotation for normal ranges
const config = {
  plugins: [{
    id: 'referenceLines',
    afterDraw: (chart) => {
      const ctx = chart.ctx;
      const yAxis = chart.scales.y;
      
      // Draw "normal range" zone
      ctx.fillStyle = 'rgba(0, 255, 0, 0.1)';
      ctx.fillRect(0, yAxis.getPixelForValue(120), 
                   chart.width, yAxis.getPixelForValue(80) - yAxis.getPixelForValue(120));
    }
  }]
};
```

---

## 5. Prettier - Code Formatting

### WordPress Integration

### Best Practices

#### A. Configuration
```json
// .prettierrc
{
  "plugins": ["@prettier/plugin-php"],
  "phpVersion": "8.2",
  "printWidth": 100,
  "tabWidth": 4,
  "useTabs": true,
  "singleQuote": true,
  "trailingComma": "es5"
}
```

#### B. WordPress Compatibility
```bash
# Use WordPress-patched Prettier
npm install --save-dev "prettier@npm:wp-prettier@latest"
npm install --save-dev @wordpress/prettier-config
```

#### C. VSCode Settings
```json
// .vscode/settings.json
{
  "[javascript]": {
    "editor.defaultFormatter": "esbenp.prettier-vscode",
    "editor.formatOnSave": true
  },
  "[php]": {
    "editor.defaultFormatter": "esbenp.prettier-vscode",
    "editor.formatOnSave": true
  }
}
```

#### D. Git Hook Integration
```json
// package.json
{
  "husky": {
    "hooks": {
      "pre-commit": "lint-staged"
    }
  },
  "lint-staged": {
    "*.{js,php,css,md}": ["prettier --write"]
  }
}
```

### Code Review Integration
```php
// Format code snippets before saving
add_action('save_post_wpcode_snippet', 'format_code_snippet', 10, 2);

function format_code_snippet($post_id, $post) {
    $code = get_post_meta($post_id, 'wpcode_snippet_code', true);
    
    // Call Prettier API
    $formatted = format_with_prettier($code, 'javascript');
    
    update_post_meta($post_id, 'wpcode_snippet_code', $formatted);
}
```

---

## 6. MJML - Email Templates

### Responsive Email Architecture

### Best Practices

#### A. Project Structure
```
/email-templates
  /src
    /components
      header.mjml
      footer.mjml
    /templates
      welcome.mjml
      notification.mjml
  /build
    welcome.html
    notification.html
```

#### B. Build Process
```json
// package.json
{
  "scripts": {
    "build:emails": "mjml src/templates/**/*.mjml -o build/",
    "watch:emails": "mjml --watch src/templates/**/*.mjml -o build/"
  }
}
```

#### C. WordPress Integration
```php
// Use MJML-generated HTML for emails
add_filter('wp_mail', 'use_mjml_template');

function use_mjml_template($args) {
    // Load pre-compiled MJML HTML
    $template_path = WP_MCP_AI_PRO_PATH . 'build/email-templates/notification.html';
    
    if (file_exists($template_path)) {
        $html_template = file_get_contents($template_path);
        
        // Replace variables
        $html_template = str_replace('{{user_name}}', $args['user_name'], $html_template);
        
        $args['message'] = $html_template;
        $args['headers'][] = 'Content-Type: text/html; charset=UTF-8';
    }
    
    return $args;
}
```

#### D. Component Reuse
```xml
<!-- src/components/header.mjml -->
<mj-section background-color="#f0f0f0">
  <mj-column>
    <mj-image src="{{logo_url}}" width="200px" />
  </mj-column>
</mj-section>

<!-- src/templates/welcome.mjml -->
<mjml>
  <mj-body>
    <mj-include path="../components/header.mjml" />
    <mj-section>
      <mj-column>
        <mj-text>Welcome {{user_name}}!</mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
```

### Testing Across Clients

```javascript
// Test email rendering
const mjml2html = require('mjml');

const result = mjml2html(`
  <mjml>
    <mj-body>
      <mj-section>
        <mj-column>
          <mj-text>Test Email</mj-text>
        </mj-column>
      </mj-section>
    </mj-body>
  </mjml>
`, {
  validationLevel: 'strict',
  minify: true
});

if (result.errors.length > 0) {
  console.error('MJML errors:', result.errors);
}
```

---

## 7. Turf.js - Geospatial Analysis

### Client-Side Architecture (Recommended)

**Why Client-Side?**
- Instant spatial filtering
- Reduced server load
- Better user experience with real-time updates

### Best Practices

#### A. GeoJSON Data Structure
```javascript
// Store location data as GeoJSON
const locations = {
  type: 'FeatureCollection',
  features: [
    {
      type: 'Feature',
      geometry: {
        type: 'Point',
        coordinates: [-73.935242, 40.730610]  // [lng, lat]
      },
      properties: {
        id: 123,
        name: 'Location Name',
        type: 'hospital'
      }
    }
  ]
};
```

#### B. Proximity Search
```javascript
import buffer from '@turf/buffer';
import pointsWithinPolygon from '@turf/points-within-polygon';

// Find locations within radius
function findNearby(userLocation, radius, locations) {
  const buffered = buffer(userLocation, radius, { units: 'miles' });
  const nearby = pointsWithinPolygon(locations, buffered);
  return nearby;
}
```

#### C. WordPress REST API
```php
// Endpoint for location search
add_action('rest_api_init', function() {
    register_rest_route('mcp-ai/v1', '/places/nearby', array(
        'methods' => 'POST',
        'callback' => 'get_nearby_places',
        'permission_callback' => '__return_true'
    ));
});

function get_nearby_places($request) {
    $lat = floatval($request['lat']);
    $lng = floatval($request['lng']);
    $radius = floatval($request['radius']);
    
    // Query places from database
    $places = get_places_as_geojson();
    
    // Return GeoJSON for client-side Turf.js processing
    return rest_ensure_response($places);
}
```

#### D. Modular Imports
```javascript
// Import only what you need (reduces bundle size)
import distance from '@turf/distance';
import bearing from '@turf/bearing';
import destination from '@turf/destination';

// NOT: import * as turf from '@turf/turf';
```

### Performance Optimization

```javascript
// Limit dataset size for client-side processing
const MAX_FEATURES = 1000;

// Use clustering for large datasets
function clusterLocations(geojson) {
  return turf.clustersDbscan(geojson, 0.5, {
    units: 'miles',
    minPoints: 2
  });
}
```

### Security

```javascript
// Validate user coordinates
function validateCoordinates(lat, lng) {
  return (
    typeof lat === 'number' &&
    typeof lng === 'number' &&
    lat >= -90 && lat <= 90 &&
    lng >= -180 && lng <= 180
  );
}
```

---

## 8. Fluent-FFmpeg - Video Processing

### Architecture Recommendations

**Microservice Approach**: Separate Node.js service for video processing

### Best Practices

#### A. Environment Setup
```javascript
const ffmpeg = require('fluent-ffmpeg');

// Set paths from environment
if (process.env.FFMPEG_PATH) {
  ffmpeg.setFfmpegPath(process.env.FFMPEG_PATH);
}
if (process.env.FFPROBE_PATH) {
  ffmpeg.setFfprobePath(process.env.FFPROBE_PATH);
}
```

#### B. Social Media Optimization
```javascript
// Platform-specific presets
const platformPresets = {
  instagram: {
    size: '1080x1080',  // Square
    videoCodec: 'libx264',
    videoBitrate: '3500k',
    fps: 30
  },
  youtube: {
    size: '1920x1080',  // 16:9
    videoCodec: 'libx264',
    videoBitrate: '8000k',
    fps: 60
  },
  tiktok: {
    size: '1080x1920',  // 9:16
    videoCodec: 'libx264',
    videoBitrate: '4500k',
    fps: 30
  }
};

function optimizeForPlatform(inputPath, platform) {
  const preset = platformPresets[platform];
  
  return ffmpeg(inputPath)
    .size(preset.size)
    .videoCodec(preset.videoCodec)
    .videoBitrate(preset.videoBitrate)
    .fps(preset.fps)
    .audioCodec('aac')
    .audioBitrate('128k')
    .outputOptions([
      '-preset fast',
      '-movflags +faststart'  // Enable progressive streaming
    ])
    .output(`output_${platform}.mp4`);
}
```

#### C. Thumbnail Generation
```javascript
function generateThumbnail(videoPath, timestamp = '00:00:05') {
  return new Promise((resolve, reject) => {
    ffmpeg(videoPath)
      .screenshots({
        timestamps: [timestamp],
        filename: 'thumbnail.jpg',
        folder: './thumbnails',
        size: '1280x720'
      })
      .on('end', () => resolve('./thumbnails/thumbnail.jpg'))
      .on('error', reject);
  });
}
```

#### D. Stream-Based Processing
```javascript
const fs = require('fs');

// Process video without loading into memory
function transcodeStream(inputPath, outputPath) {
  return new Promise((resolve, reject) => {
    const input = fs.createReadStream(inputPath);
    const output = fs.createWriteStream(outputPath);
    
    ffmpeg(input)
      .videoCodec('libx264')
      .audioCodec('aac')
      .outputOptions(['-preset fast', '-movflags +faststart'])
      .format('mp4')
      .pipe(output, { end: true })
      .on('end', resolve)
      .on('error', reject);
  });
}
```

#### E. WordPress Integration
```php
// Queue video processing on upload
add_action('add_attachment', 'queue_video_processing');

function queue_video_processing($attachment_id) {
    $mime_type = get_post_mime_type($attachment_id);
    
    if (strpos($mime_type, 'video/') === 0) {
        // Queue async job
        as_enqueue_async_action('process_video_for_social', array(
            'attachment_id' => $attachment_id,
            'platforms' => array('instagram', 'youtube', 'tiktok')
        ));
    }
}

// Process video via Node.js API
function process_video_for_social($attachment_id, $platforms) {
    $video_path = get_attached_file($attachment_id);
    
    foreach ($platforms as $platform) {
        $response = wp_remote_post(VIDEO_API_URL . '/process', array(
            'body' => json_encode(array(
                'video_path' => $video_path,
                'platform' => $platform
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . VIDEO_API_KEY
            )
        ));
        
        // Store processed video URLs
        $result = json_decode(wp_remote_retrieve_body($response), true);
        add_post_meta($attachment_id, '_processed_' . $platform, $result['url']);
    }
}
```

### Error Handling

```javascript
ffmpeg(input)
  .output(output)
  .on('start', (cmd) => {
    console.log('FFmpeg command:', cmd);
  })
  .on('progress', (progress) => {
    console.log('Processing: ' + progress.percent + '% done');
  })
  .on('error', (err, stdout, stderr) => {
    console.error('FFmpeg error:', err.message);
    console.error('FFmpeg stderr:', stderr);
    // Log to monitoring service
    // Notify user of failure
  })
  .on('end', () => {
    console.log('Processing completed successfully');
  })
  .run();
```

---

## General Integration Principles

### 1. Security First
- Always validate and sanitize user input
- Use authentication for API endpoints
- Implement rate limiting
- Store secrets in environment variables
- Use HTTPS for all external communications

### 2. Performance Optimization
- Use caching aggressively
- Implement queue-based processing for heavy operations
- Monitor resource usage and set limits
- Use CDN for static assets

### 3. Error Handling
- Log all errors to monitoring service
- Provide graceful fallbacks
- Show user-friendly error messages
- Implement retry logic for transient failures

### 4. WordPress Best Practices
- Use action scheduler for background jobs
- Leverage WordPress caching APIs
- Follow WordPress coding standards
- Respect user capabilities and permissions
- Use nonces for form submissions

### 5. Documentation
- Document all API endpoints
- Provide code examples
- Include troubleshooting guides
- Keep documentation updated

---

## Testing Strategy

### Unit Tests
```javascript
// Example: Test image processing
describe('Sharp Image Processing', () => {
  it('should resize image to specified dimensions', async () => {
    const buffer = await sharp('input.jpg')
      .resize(800, 600)
      .toBuffer();
    
    const metadata = await sharp(buffer).metadata();
    expect(metadata.width).toBe(800);
    expect(metadata.height).toBe(600);
  });
});
```

### Integration Tests
```php
// Example: Test calendar generation
class Test_ICS_Generation extends WP_UnitTestCase {
    public function test_generates_valid_ics_file() {
        $project_id = $this->factory->post->create(array('post_type' => 'project'));
        
        $ics_content = generate_project_ics($project_id);
        
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics_content);
        $this->assertStringContainsString('END:VCALENDAR', $ics_content);
    }
}
```

---

## Monitoring and Maintenance

### Key Metrics to Track
- Processing times for each package
- Error rates and types
- Resource utilization (CPU, memory, disk)
- API response times
- Queue depths for async operations

### Regular Maintenance
- Update packages monthly for security patches
- Review and optimize slow operations
- Clean up old cached files
- Monitor disk space for generated assets
- Review error logs weekly

---

## Conclusion

These best practices ensure reliable, secure, and performant integration of all pro toolkit NPM packages. Always prioritize security, user experience, and maintainability when implementing these tools in production environments.

For package-specific questions or implementation assistance, refer to the official documentation or open an issue in the repository.

---

**References**:
- Sharp Documentation: https://sharp.pixelplumbing.com/
- KaTeX Documentation: https://katex.org/docs/
- ICS Specification: RFC 5545
- Chart.js Documentation: https://www.chartjs.org/docs/
- Prettier Documentation: https://prettier.io/docs/
- MJML Documentation: https://documentation.mjml.io/
- Turf.js Documentation: https://turfjs.org/docs/
- FFmpeg Documentation: https://ffmpeg.org/documentation.html
