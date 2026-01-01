# Testing Instructions for vectorize_image Fix

## Issue Fixed
The `vectorize_image` tool was creating SVG images in the WordPress media library but not displaying them in the chat interface.

## What Was Changed
Added support for `image_url` structure in the chat client's tool result parsing logic (`assets/js/chat.js`).

## How to Test

### 1. Prerequisites
- WordPress site with WP oOS plugin installed
- Node.js binary available on the system (required by vectorize_image tool)
- An active assistant with access to the `vectorize_image` tool

### 2. Test the Fix

#### Test Case 1: Basic Vectorization
1. Open the chat interface
2. Upload or provide a URL to a raster image (PNG, JPEG, WebP, or GIF)
3. Send a message: `vectorize_image url:https://example.com/sample.png`
4. Wait for the tool to execute

**Expected Result:**
- ✅ A success message appears
- ✅ The vectorized SVG image displays in the chat with a preview
- ✅ The image has a clickable link to view/download
- ✅ Metadata shows (file size, SVG format, etc.)

#### Test Case 2: Test with Chat Message
1. Upload a PNG/JPEG image to the chat
2. Send: "Please vectorize this image"
3. The assistant should call the vectorize_image tool

**Expected Result:**
- ✅ Tool executes successfully
- ✅ SVG image displays in the chat
- ✅ Assistant can reference the vectorized image in subsequent messages

#### Test Case 3: Verify Other Tools Still Work
Test these tools to ensure no regression:

1. `generate_openai_image` prompt:"a blue cat"
   - ✅ Should display generated image

2. `generate_gemini_image` prompt:"a red dog"
   - ✅ Should display generated image

3. `edit_gemini_image` url:<image-url> prompt:"make it brighter"
   - ✅ Should display edited image

### 3. What to Look For

✅ **Success Indicators:**
- SVG image preview displays in chat
- Click on image opens full-size view
- Metadata shows file size and "SVG" format
- Image URL is accessible
- WordPress Media Library contains the SVG file

❌ **Failure Indicators:**
- Only text message appears (no image preview)
- Image URL not clickable
- Error message in console
- Missing metadata

### 4. Browser Console Check

Open browser developer tools (F12) and check console for:

**Good:**
```javascript
[NV oOS] Tool result normalized with attachments: {text: "...", attachments: [...]"}
```

**Bad:**
```javascript
[NV oOS] Warning: Tool result missing URL for image display
```

### 5. Verification Steps

1. Check WordPress Media Library:
   - Go to Media → Library
   - Verify SVG file exists
   - Check file properties

2. Check Tool Response Structure:
   - In browser console, look for tool_result content
   - Should contain both `url` and `image_url` fields

3. Test Agentic Workflow:
   - Generate an image with OpenAI
   - Ask the assistant to vectorize it
   - Verify both images display correctly

## Troubleshooting

### Issue: Image still not displaying
**Check:**
1. Browser console for JavaScript errors
2. Network tab for failed image requests
3. WordPress Media Library - is the SVG actually created?
4. Tool response - does it contain `image_url` field?

### Issue: SVG displays but other images don't
**Check:**
1. Clear browser cache
2. Verify other image tools are configured correctly
3. Check OpenAI/Gemini API keys are set

### Issue: Tool fails to execute
**Check:**
1. Node.js is installed: `node --version`
2. Vectorizer dependencies are installed: `npm run install:vectorizer`
3. Check WordPress error logs for PHP errors

## Files Modified
- `assets/js/chat.js` - Main fix
- `assets/js/chat.min.js` - Minified version
- `assets/js/chat-bundle.min.js` - Bundled version

## Rollback Instructions
If issues occur, revert to commit before: 8a46b10

```bash
git checkout main
git pull
```

## Support
If problems persist, please provide:
1. Browser console logs
2. Tool response JSON (from network tab)
3. WordPress debug log excerpt
4. Screenshot of the issue
