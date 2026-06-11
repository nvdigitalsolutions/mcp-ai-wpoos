# Content Media Embedding for CPT Tools

## Overview

Custom Post Type (CPT) creation tools now support embedding images and charts directly within the content. This enhancement allows AI assistants to create richer, more engaging content by including visual elements alongside text.

## Supported CPT Tools

1. **Create Quiz** (`create_quiz`)
2. **Create Place** (`create_place`)
3. **Create ECA** (`create_eca`)
4. **Create Policy** (`create_policy`)

## Features

### Images

- **Support**: Up to 5 images per CPT
- **Source Types**: 
  - WordPress attachment IDs
  - Direct URLs
- **Features**:
  - Optional captions
  - Optional alt text for accessibility
  - Configurable positioning
- **Output**: WordPress image blocks with proper markup

### Charts

- **Support**: Up to 3 charts per CPT
- **Library**: Chart.js 4.4.0
- **Chart Types**:
  - Bar charts
  - Line charts
  - Pie charts
  - Doughnut charts
  - Radar charts
  - Polar area charts
- **Features**:
  - Optional titles
  - Full Chart.js data configuration
  - Responsive rendering
  - Interactive tooltips

## Usage

### Adding Images to Content

Images can be added via the `content_images` parameter:

```php
create_quiz(
    title: "World Geography Quiz",
    description: "Test your knowledge of world geography",
    questions: [...],
    content_images: [
        {
            source: 123,                  // Attachment ID
            caption: "World Map Reference",
            alt: "A labeled world map",
            position: "start"
        },
        {
            source: "https://example.com/image.jpg", // URL
            caption: "Continental Divisions",
            position: "middle"
        }
    ]
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `source` | integer or string | Yes | Attachment ID or image URL |
| `caption` | string | No | Image caption displayed below |
| `alt` | string | No | Alt text for accessibility |
| `position` | string | No | Where to place image: `start`, `middle`, `end` (default: `middle`) |

### Adding Charts to Content

Charts can be added via the `content_charts` parameter:

```php
create_place(
    name: "City Museum",
    description: "Historic museum featuring...",
    content_charts: [
        {
            type: "bar",
            title: "Visitor Statistics by Month",
            data: {
                labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
                datasets: [
                    {
                        label: "2024 Visitors",
                        data: [1200, 1500, 1800, 2100, 2400, 2200]
                    }
                ]
            },
            position: "end"
        }
    ]
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | string | Yes | Chart type: `bar`, `line`, `pie`, `doughnut`, `radar`, `polarArea` |
| `title` | string | No | Chart title displayed above |
| `data` | object | Yes | Chart.js data object with labels and datasets |
| `position` | string | No | Where to place chart: `start`, `middle`, `end` (default: `middle`) |

## Position Behavior

Content positioning works as follows:

- **`start`**: Media placed before the main content text
- **`middle`**: Media inserted approximately halfway through the content
- **`end`**: Media placed after the main content text

Multiple items with the same position are rendered in the order they appear in the array.

## Limits and Performance

To ensure optimal performance and prevent content bloat:

- **Maximum 5 images** per CPT (additional images ignored)
- **Maximum 3 charts** per CPT (additional charts ignored)
- Charts loaded via CDN (Chart.js 4.4.0)
- Images lazy-loaded by WordPress

## Technical Implementation

### Trait: `WP_MCP_AI_Tool_Content_Media`

Located at: `/includes/tools/trait-wp-mcp-ai-tool-content-media.php`

**Methods:**

1. `get_content_media_parameters()` - Returns schema parameters for images and charts
2. `embed_content_media($content, $arguments)` - Embeds media into content
3. `generate_image_html($image)` - Generates WordPress image block markup
4. `generate_chart_html($chart)` - Generates Chart.js HTML and script

### Image Rendering

Images are rendered as WordPress image blocks:

```html
<!-- wp:image -->
<figure class="wp-block-image">
    <img src="[URL]" alt="[ALT TEXT]" class="wp-image-[ID]"/>
    <figcaption>[CAPTION]</figcaption>
</figure>
<!-- /wp:image -->
```

### Chart Rendering

Charts are rendered with Chart.js:

```html
<!-- wp:html -->
<div class="wp-mcp-ai-chart-container">
    <h4>[TITLE]</h4>
    <canvas id="chart-[UNIQUE_ID]"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById("chart-[UNIQUE_ID]");
    new Chart(ctx, {
        type: "[TYPE]",
        data: [DATA],
        options: { responsive: true, maintainAspectRatio: true }
    });
});
</script>
<!-- /wp:html -->
```

## Example Use Cases

### Quiz with Reference Images

Create a geography quiz with map references:

```php
create_quiz(
    title: "European Capitals Quiz",
    description: "Identify the capitals of European countries",
    questions: [
        {
            question: "What is the capital of France?",
            type: "multiple_choice",
            options: ["Paris", "London", "Berlin", "Madrid"],
            correct_answer: "Paris"
        }
    ],
    content_images: [
        {
            source: 456,
            caption: "Map of Europe",
            alt: "Political map of Europe showing country boundaries",
            position: "start"
        }
    ]
)
```

### Place with Visitor Charts

Create a museum entry with visitor statistics:

```php
create_place(
    name: "National Art Museum",
    description: "Premier art museum featuring classical and modern works",
    address: "123 Museum St, City",
    content_images: [
        {
            source: "https://museum.com/exterior.jpg",
            caption: "Museum Exterior",
            position: "start"
        }
    ],
    content_charts: [
        {
            type: "line",
            title: "Annual Visitor Trends",
            data: {
                labels: ["2019", "2020", "2021", "2022", "2023", "2024"],
                datasets: [
                    {
                        label: "Visitors (thousands)",
                        data: [250, 180, 200, 280, 320, 350]
                    }
                ]
            },
            position: "end"
        }
    ]
)
```

### ECA with Activity Photos

Create an after-school activity with photos:

```php
create_eca(
    name: "Robotics Club",
    description: "Build and program robots for competitions",
    day: "Wednesday",
    start_time: "3:30 PM",
    end_time: "5:00 PM",
    content_images: [
        {
            source: 789,
            caption: "Students working on robot designs",
            position: "middle"
        },
        {
            source: 790,
            caption: "Competition day showcase",
            position: "end"
        }
    ]
)
```

### Policy with Coverage Charts

Create an insurance policy with visual coverage breakdown:

```php
create_policy(
    member_id: 123,
    policy_number: "POL-2024-001",
    policy_type: "health-insurance",
    coverage_details: "Comprehensive health coverage including...",
    content_charts: [
        {
            type: "doughnut",
            title: "Coverage Breakdown",
            data: {
                labels: ["Medical", "Dental", "Vision", "Pharmacy"],
                datasets: [
                    {
                        data: [50, 20, 10, 20],
                        backgroundColor: ["#FF6384", "#36A2EB", "#FFCE56", "#4BC0C0"]
                    }
                ]
            },
            position: "middle"
        }
    ]
)
```

## Chart.js Data Format

Charts use standard Chart.js data configuration. Example formats:

### Bar/Line Chart

```php
{
    labels: ["Label 1", "Label 2", "Label 3"],
    datasets: [
        {
            label: "Dataset 1",
            data: [10, 20, 30],
            backgroundColor: "#FF6384"
        }
    ]
}
```

### Pie/Doughnut Chart

```php
{
    labels: ["Section 1", "Section 2", "Section 3"],
    datasets: [
        {
            data: [30, 50, 20],
            backgroundColor: ["#FF6384", "#36A2EB", "#FFCE56"]
        }
    ]
}
```

### Radar Chart

```php
{
    labels: ["Speed", "Reliability", "Comfort", "Safety", "Efficiency"],
    datasets: [
        {
            label: "Product A",
            data: [80, 90, 70, 85, 75]
        }
    ]
}
```

## WordPress Integration

### Block Editor Compatibility

Images and charts render properly in:
- Gutenberg block editor
- Classic editor
- Theme front-end displays
- RSS feeds (images only)

### Theme Support

Works with any WordPress theme that supports:
- Standard image blocks
- HTML/JavaScript blocks
- Responsive containers

### Accessibility

- Images include alt text support
- Charts have descriptive titles
- Chart.js provides keyboard navigation
- Screen readers can access chart title

## Error Handling

- Invalid attachment IDs: Silently skipped
- Invalid URLs: Silently skipped
- Malformed chart data: Silently skipped
- Too many items: Excess items ignored
- Missing required parameters: Item skipped

Content creation **always succeeds** even if all media items fail to render.

## Security Considerations

- All URLs are escaped with `esc_url()`
- All text content uses `wp_kses_post()`
- Alt text sanitized with `esc_attr()`
- Chart IDs generated securely
- No user-provided JavaScript executed
- Chart.js loaded from trusted CDN

## Performance Tips

1. **Use attachment IDs** when possible (faster than URLs)
2. **Limit charts** to what's necessary (each adds ~100KB)
3. **Optimize images** before uploading to WordPress
4. **Position strategically** to break up long text blocks
5. **Avoid duplicate charts** (use different data, not duplicate type)

## Future Enhancements

Potential improvements for future versions:

1. Video embedding support
2. Audio file embedding
3. Gallery blocks (multiple images together)
4. Custom chart color schemes
5. Chart export functionality
6. Image optimization/compression
7. Lazy loading for charts
8. More chart types (scatter, bubble, etc.)
9. Interactive chart filtering
10. Chart data export to CSV

## Troubleshooting

### Images Not Displaying

**Symptom**: Images don't appear in content

**Possible Causes**:
1. Invalid attachment ID
2. Broken image URL
3. Image file deleted from media library
4. Incorrect permissions

**Solution**: Verify attachment exists and is accessible

### Charts Not Rendering

**Symptom**: Charts show as empty or don't display

**Possible Causes**:
1. JavaScript errors on page
2. Chart.js CDN unavailable
3. Malformed data structure
4. Browser compatibility issues

**Solution**: Check browser console for errors, verify data format

### Content Positioning Wrong

**Symptom**: Media appears in unexpected location

**Possible Causes**:
1. No base content to position relative to
2. Multiple items with same position
3. Short content length

**Solution**: Ensure adequate base content, use different positions

## Support

For issues or questions:

1. Check this documentation
2. Review example use cases
3. Verify Chart.js documentation for data formats
4. Check WordPress Codex for image block format
5. Contact NV Digital Solutions support

## Changelog

### Version 1.0.0
- Initial release
- Support for 4 CPT types
- Image embedding (up to 5)
- Chart embedding (up to 3)
- Chart.js 4.4.0 integration
- WordPress block format output
- Position configuration
