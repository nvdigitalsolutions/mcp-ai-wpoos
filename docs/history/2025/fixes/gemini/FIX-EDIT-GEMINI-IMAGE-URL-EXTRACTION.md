# Fix: Edit Gemini Image URL Extraction from Attachments

## Issue Summary

**Problem**: When using the chat client with OpenAI as the provider, the `edit_gemini_image` tool fails with the error:
> "You must provide attachment_id, file_id, url, image_url, or image_data."

This occurred even when users attached image files in the chat and requested edits.

## Root Cause

The issue was **not** in the infrastructure (which was working correctly) but in the **LLM guidance**. The tool had all the necessary capabilities, but the LLM wasn't receiving clear enough instructions on how to extract the URL from structured message content.

### Message Structure

When a user attaches an image in chat, the message content becomes a structured array:

```json
{
  "role": "user",
  "content": [
    {
      "type": "text",
      "text": "edit Gemini image so there is no background"
    },
    {
      "type": "input_image",
      "attachment_id": 123,
      "url": "https://example.com/wp-content/uploads/2025/12/photo.jpg",
      "name": "photo.jpg",
      "mime_type": "image/jpeg",
      "bytes": 189800
    }
  ]
}
```

### What Was Missing

1. **Tool Rules**: The `get_tool_rules()` method didn't list `url` and `file_id` in the `optional_fields` array
2. **Explicit Extraction Instructions**: The tool description and parameter descriptions didn't explicitly tell the LLM to "look in the message content array for segments with type:'input_image' and extract the url field"
3. **Repetition**: The instruction wasn't repeated enough times in different contexts (description, parameters, shortcuts)

## Solution

### 1. Added Constant for URL Extraction Instruction

```php
/**
 * Instruction for LLMs on how to extract URL from attached images.
 * Repeated in multiple places to ensure LLMs see and follow the pattern.
 *
 * @var string
 */
const URL_EXTRACTION_INSTRUCTION = 'When the user has attached an image, look for the "url" field in the message content array (within segments of type "input_image") and pass it as the "url" parameter to the tool.';
```

**Why**: Creating a constant:
- Reduces duplication while maintaining explicit guidance
- Makes it easy to update the instruction in one place
- Documents the pattern clearly for maintainers
- Ensures consistency across all usages

### 2. Enhanced Tool Description

**Before**:
```php
'Edits an existing image using Gemini Nano Banana (text + image-to-image) and stores the result in the Media Library. Can edit images from user attachments by using their URL, or images from the Media Library by attachment_id.'
```

**After**:
```php
'Edits an existing image using Gemini Nano Banana (text + image-to-image) and stores the result in the Media Library. IMPORTANT: When a user attaches an image in chat, extract the "url" field from the message content segments (look for type:"input_image" segments with a url field) and pass it as the "url" parameter. Can also edit images from the Media Library by attachment_id.'
```

**Why**: The tool description is one of the first things an LLM sees. Adding "IMPORTANT:" and explicit step-by-step instructions increases the likelihood the LLM will understand and follow the pattern.

### 3. Enhanced URL Parameter Description

**Before**:
```php
'URL of the image to edit. Can be a WordPress media URL (e.g., from user attachments) or external URL.'
```

**After**:
```php
'URL of the image to edit. REQUIRED when user attaches an image in chat - extract the "url" field from the message content segment (look for segments with type:"input_image" that contain a url field). Can be a WordPress media URL or external URL.'
```

**Why**: Parameter descriptions are shown in tool schema that LLMs parse. Making it explicit that this is REQUIRED for attached images and providing extraction instructions helps the LLM understand when and how to use this parameter.

### 4. Refactored Shortcuts with Constant

**Before** (repeated in 3 places):
```php
'Use the `edit_gemini_image` tool to remove the background from an image. When the user has attached an image, use the "url" parameter with the attachment URL. Use a prompt like "remove background, make transparent".'
```

**After**:
```php
sprintf( 
  __( 'Use the `edit_gemini_image` tool to remove the background from an image. IMPORTANT: %s Use a prompt like "remove background, make transparent".', 'wp-mcp-ai' ), 
  self::URL_EXTRACTION_INSTRUCTION 
)
```

**Why**: 
- Reduces duplication (DRY principle)
- Maintains explicit IMPORTANT prefix for LLM attention
- Uses the constant for consistency
- Adds translator comments for proper i18n

### 5. Fixed Tool Rules

**Before**:
```php
'optional_fields' => array( 'attachment_id', 'image_url', 'image_data', 'source_mime_type', 'model', 'aspect_ratio', 'mime_type', 'file_name', 'timeout' )
```

**After**:
```php
'optional_fields' => array( 'attachment_id', 'file_id', 'url', 'image_url', 'image_data', 'source_mime_type', 'model', 'aspect_ratio', 'mime_type', 'file_name', 'timeout' )
```

**Why**: Tool rules are used by orchestration systems and some LLMs to understand available parameters. Adding `file_id` and `url` ensures they're recognized as valid parameters.

## LLM Communication Strategy

### Why Repetition and Verbosity Matter

When communicating with LLMs, different principles apply than when writing code for humans:

1. **Repetition Increases Reliability**: LLMs work probabilistically. Seeing the same instruction in multiple contexts increases the likelihood they'll follow it.

2. **Explicit > Implicit**: What seems obvious to a human developer (e.g., "extract the URL from the attachment") may not be clear to an LLM without step-by-step instructions.

3. **Context Windows Are Large**: Modern LLMs have large context windows (100k+ tokens). A few hundred extra characters of explicit instructions are negligible.

4. **Multiple Entry Points**: Different LLMs may prioritize different parts of the tool schema:
   - Some read the tool description first
   - Some focus on parameter descriptions
   - Some learn from shortcuts/examples
   - Covering all bases ensures broader compatibility

### Instruction Placement Strategy

The URL extraction instruction appears in **4 strategic locations**:

1. **Tool Description**: First impression, sets the context
2. **URL Parameter Description**: Reinforces when the LLM is examining parameters
3. **Shortcut 1 (Remove Background)**: Common use case, provides example
4. **Shortcut 2 (Change Style)**: Alternative use case, reinforces pattern
5. **Shortcut 3 (Enhance Photo)**: Another example, ensures pattern recognition

This multi-layered approach ensures the LLM encounters the instruction regardless of which part of the schema it prioritizes.

## Expected Behavior

### Successful Flow

1. **User Action**: Attaches `photo.jpg` in chat
2. **Frontend**: Creates `input_image` segment with URL, attachment_id, etc.
3. **User Request**: "edit Gemini image so there is no background"
4. **REST API**: Sends message with structured content to OpenAI
5. **LLM Processing**:
   - Sees tool description with IMPORTANT instruction
   - Examines message content array
   - Finds `input_image` segment
   - Extracts `url` field: `https://example.com/wp-content/uploads/photo.jpg`
6. **LLM Tool Call**:
   ```json
   {
     "name": "edit_gemini_image",
     "arguments": {
       "prompt": "remove background, make transparent",
       "url": "https://example.com/wp-content/uploads/photo.jpg"
     }
   }
   ```
7. **Tool Execution**: Successfully processes the image
8. **Response**: Returns edited image to user

## Testing Checklist

### Manual Testing
- [ ] Create assistant with OpenAI provider (e.g., `gpt-4`)
- [ ] Ensure Gemini API key is configured
- [ ] Open chat client
- [ ] Attach image file (JPEG, PNG, etc.)
- [ ] Send message: "edit this image to remove the background"
- [ ] Verify:
  - [ ] Tool is called with `url` parameter
  - [ ] Image is successfully edited
  - [ ] Result appears in chat
  - [ ] No "missing parameter" errors

### Edge Cases to Test
- [ ] Multiple images attached (should handle first image)
- [ ] External image URL (should work)
- [ ] WordPress attachment ID (should work)
- [ ] OpenAI file_id (should work)
- [ ] Base64 image data (should work)

## Files Modified

- `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`
  - Added `URL_EXTRACTION_INSTRUCTION` constant (line 34)
  - Enhanced tool description (line 54)
  - Enhanced URL parameter description (line 78)
  - Refactored shortcuts to use constant (lines 133-145)
  - Fixed `get_tool_rules()` optional_fields (line 1025)

## Related Documentation

- **Original Fix**: `docs/FIX-EDIT-GEMINI-IMAGE-ATTACHMENTS.md` - Added trait and parameters
- **Attachment Preservation**: `docs/ATTACHMENT_ID_PRESERVATION_FIX.md` - Backend metadata fix
- **Tool Reference**: `docs/tool-reference.md` - Complete tool documentation
- **Trait Documentation**: `includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php` - Resolution logic

## Lessons Learned

### For Future Tool Development

1. **LLM Instructions Must Be Explicit**: Don't assume LLMs will infer patterns. Spell out every step.

2. **Repeat Critical Instructions**: Place important instructions in multiple locations (description, parameters, shortcuts).

3. **Use IMPORTANT/REQUIRED Keywords**: These help LLMs prioritize certain instructions.

4. **Test with Multiple Providers**: Different LLMs (OpenAI, Gemini, Ollama) may interpret instructions differently.

5. **Document Extraction Patterns**: When parameters come from structured data (like message segments), document the extraction path explicitly.

6. **Balance DRY with Clarity**: While code duplication is generally bad, for LLM instructions, repetition serves a purpose. Use constants to manage it.

### Architecture Patterns

1. **Separation of Concerns**: The infrastructure (frontend, backend, trait) was working correctly. The issue was purely in LLM guidance.

2. **Progressive Enhancement**: Each fix builds on previous work:
   - First: Added trait and parameters
   - Then: Added backend metadata preservation
   - Now: Enhanced LLM instructions

3. **Multi-Provider Support**: Design tools to work across providers by being explicit rather than relying on provider-specific behaviors.

## Migration Notes

### Backward Compatibility

✅ **Fully backward compatible**:
- Existing tool calls still work
- No breaking changes to parameters or behavior
- Only additions to descriptions and rules

### Rollout

- No database migrations required
- No cache clearing needed
- Changes take effect immediately on deployment

## Future Enhancements

### Potential Improvements

1. **Visual Examples**: Add example JSON structures to tool documentation
2. **Error Messages**: Enhance error messages to guide users on correct parameter usage
3. **Auto-Detection**: Tool could automatically detect attached images in recent messages
4. **Multi-Image Support**: Handle multiple attached images in a single request
5. **Fallback Chain**: If URL extraction fails, try attachment_id, then file_id, etc.

### Monitoring

Consider adding telemetry to track:
- How often the tool is called with `url` vs `attachment_id` vs `file_id`
- Success/failure rates for each parameter type
- Common error patterns to inform future improvements

## Summary

This fix demonstrates that sometimes the issue isn't in the code infrastructure but in how we communicate with AI systems. By making our instructions explicit, repetitive, and strategically placed, we significantly increase the reliability of LLM-tool interactions.

**Key Takeaway**: When building tools for LLMs to use, think of the tool schema as the API documentation that the LLM reads. Make it clear, explicit, and comprehensive—even if that seems verbose by traditional coding standards.
