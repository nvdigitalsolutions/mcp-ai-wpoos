# Testing Attachment Metadata Display Enhancement

This guide explains how to test the attachment metadata display fix that ensures file attachments show complete metadata (including attachment_id) in the chat widget, matching the format used by image tool results.

## What Was Fixed

**Before:** File attachments displayed only basic metadata
```
189.8 KB • image/jpeg
```

**After:** File attachments now display complete metadata including attachment_id
```
189.8 KB • image/jpeg • ID: 123
```

This makes the display consistent with how image generation tools (`edit_gemini_image`, `generate_openai_image`) show their results.

## Testing with OpenAI Provider

### Prerequisites

1. **WordPress Environment**
   - WordPress 6.0 or later
   - PHP 7.4 or later
   - WP oOS plugin activated

2. **OpenAI Configuration**
   - Valid OpenAI API key configured in WP oOS settings
   - At least one assistant configured to use OpenAI as the provider

3. **Test Files**
   - Prepare a test image file (e.g., JPG, PNG) - around 100-500 KB
   - Optionally, prepare other file types (PDF, document, etc.)

### Step-by-Step Testing

#### 1. Configure an OpenAI Assistant

1. Go to WordPress Admin → **WP oOS → Assistants**
2. Create or edit an assistant
3. Set the following:
   - **Provider:** OpenAI
   - **Model:** gpt-4o or gpt-4-turbo (models that support vision)
   - **Enable File Uploads:** Yes
   - **Tools:** Enable `edit_gemini_image` or any image editing tool

#### 2. Test File Attachment Display

1. **Open the Chat Widget**
   - Navigate to a page with the chat widget shortcode: `[wp_mcp_ai_chat assistant_id="X"]`
   - Or use the Test Assistant page in WP oOS admin

2. **Attach a File**
   - Click the attachment/upload button in the chat widget
   - Select a test image file (JPG, PNG)
   - Wait for upload to complete

3. **Verify Metadata Display**
   - Look at the attachment display in your message bubble
   - You should see metadata in this format:
     ```
     [filename] – 189.8 KB • image/jpeg • ID: 123
     ```
   - The **ID: 123** part is the new addition

4. **Send Message with Attached File**
   - Type a message like: "Edit this image and remove the background"
   - Send the message
   - The attachment metadata should remain visible in the user message bubble

#### 3. Compare with Tool Result Display

1. **Wait for Tool Response**
   - After sending the message, the AI will use the `edit_gemini_image` tool
   - The tool will process the image and return a result

2. **Verify Consistent Display**
   - The tool result should show metadata in the same format:
     ```
     Successfully edited image "filename.jpg" (ID: 456).
     [filename] – 400 KB • image/png • ID: 456 • 1:1 • PNG
     ```
   - Notice both user attachments and tool results now show the **ID: X** format

#### 4. Test Different File Types

Test with various file types to ensure metadata displays consistently:

- **Images:** JPG, PNG, GIF, WebP
  ```
  200 KB • image/jpeg • ID: 123
  ```

- **Documents:** PDF, DOCX
  ```
  512 KB • application/pdf • ID: 456
  ```

- **Videos:** MP4, WebM
  ```
  5 MB • video/mp4 • ID: 789
  ```

#### 5. Test Edge Cases

1. **Large File**
   - Upload a file larger than 1 MB
   - Verify size is displayed correctly (e.g., "5.2 MB")

2. **Multiple Attachments**
   - Attach multiple files in one message
   - Each should show its own attachment_id

3. **Backward Compatibility**
   - If any legacy data exists without attachment_id, it should display gracefully:
     ```
     189.8 KB • image/jpeg
     ```
     (ID is omitted if not available)

### Expected Results

✅ **Success Indicators:**

1. User message bubbles show attachment_id in format "ID: X"
2. Tool result attachments also show "ID: X" 
3. Display format is consistent between user attachments and tool results
4. All metadata fields are separated by " • " (bullet)
5. No duplicate "ID: X" entries appear

❌ **Failure Indicators:**

1. Attachment_id is not displayed at all
2. Duplicate "ID: X" appears (e.g., "ID: 123 • ID: 123")
3. Metadata format is inconsistent between user and tool messages
4. JavaScript console shows errors related to attachment display

## Browser Console Testing

You can also verify the attachment metadata programmatically:

1. Open browser Developer Tools (F12)
2. Go to Console tab
3. After uploading a file, inspect the attachment metadata:

```javascript
// Find all attachment metadata elements
const metaElements = document.querySelectorAll('.wp-mcp-ai-chat__attachments-meta');
metaElements.forEach(el => {
  console.log('Metadata:', el.textContent);
  // Should output: "189.8 KB • image/jpeg • ID: 123"
});
```

## Automated Testing

Run the JavaScript test suite to verify the changes:

```bash
cd /path/to/mcp-ai-wpoos
npm test -- tests/js/attachment-metadata-display.test.js
```

Expected output:
```
PASS tests/js/attachment-metadata-display.test.js
  Attachment Metadata Display
    buildAttachmentMeta with attachment_id
      ✓ should include attachment_id for uploaded image
      ✓ should include attachment_id for PDF file
      ✓ should handle attachment_id field instead of id
      ✓ should work with string attachment_id
      ... (12 tests total)

Test Suites: 1 passed, 1 total
Tests:       12 passed, 12 total
```

## Visual Documentation

Open `docs/attachment-metadata-display-demo.html` in a browser to see a visual comparison of the before and after states.

## Troubleshooting

### Issue: Attachment ID not showing

**Possible Causes:**
- Browser cache showing old JavaScript
- File upload didn't complete successfully
- Attachment record missing ID field

**Solutions:**
1. Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
2. Clear browser cache
3. Re-upload the file
4. Check browser console for errors

### Issue: Duplicate ID appears

**Cause:** This should be fixed, but if it occurs:

**Solution:**
1. Clear browser cache
2. Verify you're using the latest version of chat.js
3. Check for conflicting JavaScript modifications

### Issue: Metadata format looks wrong

**Verification:**
1. Inspect the element in browser DevTools
2. Check that CSS is loading correctly
3. Verify no custom styles are overriding the attachment display

## Related Changes

- **Modified:** `assets/js/chat.js` - `buildAttachmentMeta()` function
- **Modified:** `assets/js/chat.js` - `normaliseToolResultForDisplay()` function
- **Added:** `tests/js/attachment-metadata-display.test.js` - 12 test cases
- **Added:** `docs/attachment-metadata-display-demo.html` - Visual documentation

## Support

If you encounter issues:
1. Check JavaScript console for errors
2. Verify all tests pass: `npm test`
3. Review the visual demo: `docs/attachment-metadata-display-demo.html`
4. Report issues with screenshots showing the actual vs expected display
