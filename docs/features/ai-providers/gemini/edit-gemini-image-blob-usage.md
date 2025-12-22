# Using Edit Gemini Image Tool with Multiple Image Sources

## Overview

The `edit_gemini_image` tool supports multiple ways to specify the source image:

1. **WordPress Attachment ID** (`attachment_id`) - Reference an existing image in the Media Library
2. **File ID** (`file_id`) - OpenAI or Gemini file identifier (NEW)
3. **URL** (`url`) - Download an image from any URL, including user attachments (ENHANCED)
4. **Image URL** (`image_url`) - Legacy parameter (use `url` instead)
5. **Base64 Blob Data** (`image_data`) - Use inline image data

This flexibility makes the tool work seamlessly in various contexts:
- **Chat with attachments**: User attaches an image → LLM uses `url` parameter
- **Generated images**: User generates image → LLM uses `image_data` from previous result
- **Media Library**: User references existing image → LLM uses `attachment_id`
- **OpenAI files**: Image uploaded via files endpoint → LLM uses `file_id`

## Use Cases

### Scenario 1: Edit an Attached Image (NEW)

When a user attaches an image in chat and asks to edit it, the LLM can now extract the attachment URL and use it directly:

**Example Flow:**

1. User attaches image: `https://example.com/wp-content/uploads/2025/12/photo.jpg`
2. User says: "edit Gemini image to remove the background"
3. LLM calls:
```json
{
  "tool": "edit_gemini_image",
  "arguments": {
    "prompt": "remove background, make transparent",
    "url": "https://example.com/wp-content/uploads/2025/12/photo.jpg"
  }
}
```

This works with **OpenAI as the provider** because the tool uses Gemini's API regardless of the chat provider.

### Scenario 2: Edit a Generated Image in the Same Chat Session

When you generate an image in chat, the result includes a `content` field with base64-encoded image data. You can immediately edit that image without saving it to the Media Library first.

**Example Flow:**

1. Generate an image:
```json
{
  "tool": "generate_gemini_image",
  "arguments": {
    "prompt": "A red sunset over mountains"
  }
}
```

2. Response includes:
```json
{
  "attachment_id": 123,
  "url": "https://example.com/wp-content/uploads/...",
  "content": {
    "data": "iVBORw0KGgo...", // Base64-encoded image
    "mime_type": "image/png"
  }
}
```

3. Edit that image immediately using the blob data:
```json
{
  "tool": "edit_gemini_image",
  "arguments": {
    "prompt": "make the sky purple instead of red",
    "image_data": "iVBORw0KGgo...", // From content.data above
    "source_mime_type": "image/png"  // From content.mime_type above
  }
}
```

### Scenario 3: Chain Multiple Edits

You can chain multiple edits without touching the Media Library:

```
Generate → Edit 1 → Edit 2 → Edit 3
  ↓         ↓         ↓         ↓
 blob  →   blob  →  blob  →   blob
```

Each step uses the `content.data` from the previous step.

### Scenario 4: Edit Existing Media Library Images

The traditional approach still works:

```json
{
  "tool": "edit_gemini_image",
  "arguments": {
    "prompt": "remove background",
    "attachment_id": 456
  }
}
```

## Parameters

### Required
- `prompt` (string): Editing instruction

### Source Image (one required)
- `attachment_id` (integer): WordPress attachment ID
- `file_id` (string): OpenAI or Gemini file identifier
- `url` (string): Image URL (including user attachments) - **Recommended for chat attachments**
- `image_url` (string): Legacy parameter (use `url` instead)
- `image_data` (string): Base64-encoded image data

### Optional
- `source_mime_type` (string): MIME type when using `image_data` (defaults to `image/png`)
  - Supported: `image/png`, `image/jpeg`, `image/webp`
- `model` (string): Gemini model to use (defaults to `gemini-2.5-flash-image`)
- `aspect_ratio` (string): Output aspect ratio (`1:1`, `3:4`, `4:3`, `9:16`, `16:9`)
- `mime_type` (string): Output MIME type (`image/png`, `image/jpeg`)
- `file_name` (string): Base filename for saved attachment
- `timeout` (integer): Request timeout in seconds (5-300)

## Implementation Details

### How It Works

1. **Input**: The tool accepts base64-encoded image data via `image_data` parameter
2. **Decoding**: The data is decoded from base64 to raw binary
3. **Validation**: MIME type is validated against allowed types
4. **Re-encoding**: Binary data is re-encoded to base64 for Gemini API
5. **API Call**: Gemini processes the edit with the source image inline
6. **Storage**: Result is saved as a new WordPress attachment
7. **Output**: Returns attachment details plus inline base64 data

### Data Flow

```
User Input (base64) → Decode → Validate → Re-encode → Gemini API
                                                           ↓
                                           Result ← Save Attachment
```

### Provider Routing

The tool explicitly specifies Gemini as the required provider through `get_tool_rules()`:

```php
'model_requirements' => array(
    'providers' => array( 'gemini' ),
    'models'    => array( 'gemini-2.5-flash-image', ... ),
    'required'  => true,
),
```

This ensures multi-provider chat clients route the request to Gemini's API, not OpenAI or other providers.

## Error Handling

### Invalid Base64 Data
```json
{
  "error": {
    "code": "wp_mcp_ai_invalid_image_data",
    "message": "The provided image data is not valid base64."
  }
}
```

### Empty Image Data
```json
{
  "error": {
    "code": "wp_mcp_ai_empty_image_data",
    "message": "The decoded image data is empty."
  }
}
```

### Missing Source
```json
{
  "error": {
    "code": "wp_mcp_ai_missing_source",
    "message": "Either attachment_id, image_url, or image_data must be provided."
  }
}
```

## Best Practices

### 1. Use Blob Data for Temporary Edits
If you're experimenting with multiple variations, use blob data to avoid cluttering the Media Library.

### 2. Save Final Results via Attachment ID
Once you're happy with an image, reference it by `attachment_id` for subsequent edits or use in posts.

### 3. Specify MIME Type
Always include `source_mime_type` when using `image_data` for accurate processing:

```json
{
  "image_data": "...",
  "source_mime_type": "image/png"  // Good practice
}
```

### 4. Handle Large Images Carefully
Base64-encoded images increase payload size by ~33%. For very large images, consider using `attachment_id` or `image_url` instead.

### 5. Chain Edits Efficiently
When chaining edits, you can extract the blob from each result:

```javascript
// Step 1: Generate
const result1 = await generateImage({ prompt: "..." });
const blob1 = result1.content.data;

// Step 2: Edit
const result2 = await editImage({ 
  prompt: "...", 
  image_data: blob1,
  source_mime_type: result1.content.mime_type 
});
const blob2 = result2.content.data;

// Step 3: Edit again
const result3 = await editImage({ 
  prompt: "...", 
  image_data: blob2,
  source_mime_type: result2.content.mime_type 
});
```

## Compatibility

- **Minimum WordPress Version**: 6.0+
- **PHP Version**: 7.4+
- **Required Provider**: Gemini (enforced via tool rules)
- **Supported Models**: 
  - `gemini-2.5-flash-image` (recommended)
  - `gemini-exp-1206`
  - `gemini-2.0-flash-exp`

## Security Considerations

1. **Base64 Validation**: Input is strictly validated to ensure it's valid base64
2. **MIME Type Validation**: Only image MIME types are accepted
3. **Capability Checks**: Requires `read` capability for authenticated users
4. **Size Limits**: Subject to WordPress and server upload limits
5. **Sanitization**: All inputs are sanitized according to WordPress standards

## Testing

See `tests/test-edit-gemini-image-blob.php` for comprehensive test coverage including:
- Valid blob data processing
- Invalid base64 handling  
- Empty data handling
- MIME type defaults and validation
- Parameter schema verification
- Provider rules verification
