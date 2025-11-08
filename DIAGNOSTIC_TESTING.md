# Diagnostic Pages Testing Guide

This document explains the fixes made to the diagnostic pages and how to test them.

## Issues Fixed

### 1. Provider Diagnostics Page

**File:** `includes/admin/class-wp-mcp-ai-provider-diagnostics.php`

#### Issue 1: Unsafe Error Message Access
**Problem:** When the AJAX response failed, the code tried to access `response.data.message` without checking if it exists, causing JavaScript errors.

**Fix:** Added safe check before accessing the message:
```javascript
var errorMessage = (response.data && response.data.message) ? response.data.message : 'Unknown error occurred';
```

#### Issue 2: Incorrect Button Text Reconstruction
**Problem:** The button text was reconstructed using:
```javascript
provider.toUpperCase().replace('_', ' ')
```
This had two issues:
1. `.replace('_', ' ')` only replaced the FIRST underscore
2. Capitalization was incorrect (e.g., "LM_STUDIO" instead of "Lm Studio")

**Fix:** Changed to:
```javascript
var providerName = provider.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
```
This:
1. Replaces ALL underscores with `/g` flag
2. Properly capitalizes each word using word boundary regex

### 2. MCP Server Diagnostic Page

**File:** `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php`

#### Issue 1: Unsafe Error Message Access (2 locations)
**Problem:** Same as Provider Diagnostics - unsafe access to `response.data.message`

**Fix:** Added the same safe check in both:
- MCP Endpoint test success handler
- MCP Method test success handler

#### Issue 2: Fragile Button Text Restoration
**Problem:** The button text was restored by:
1. Finding the parent's h3 element
2. Extracting its text (which includes HTML)
3. Splitting by space and taking the first word

This was fragile because:
- The h3 contains HTML (`<code>` tags)
- The text structure could change
- It relied on specific DOM structure

**Fix:** Changed to store the original button text in a data attribute:
```javascript
if (!button.data('original-text')) {
    button.data('original-text', button.text());
}
button.prop('disabled', false).text(button.data('original-text'));
```

## Manual Testing Instructions

### Provider Diagnostics Page

1. Navigate to **Tools → WP oOS Provider Test**
2. Test each provider button:

#### Test OpenAI
- **Without API Key:**
  1. Ensure no OpenAI API key is configured
  2. Click "Test OpenAI Connection"
  3. Should display error: "OpenAI API key is not configured"
  4. Button should return to original text: "Test Openai Connection"

- **With Invalid API Key:**
  1. Configure an invalid OpenAI API key
  2. Click "Test OpenAI Connection"
  3. Should display error about authentication failure
  4. Button should return to original text

- **With Valid API Key:**
  1. Configure a valid OpenAI API key
  2. Click "Test OpenAI Connection"
  3. Should display success with model count
  4. Button should return to original text

#### Test Gemini
- Follow the same steps as OpenAI
- Button should return to: "Test Gemini Connection"

#### Test Ollama
- **Without Endpoint:**
  1. Ensure no Ollama endpoint is configured
  2. Click "Test Ollama Connection"
  3. Should display error: "Ollama endpoint URL is not configured"
  4. Button should return to: "Test Ollama Connection"

- **With Endpoint:**
  1. Configure endpoint (e.g., http://localhost:11434)
  2. Click button
  3. Should either succeed or fail with connection error
  4. Button should return to original text

#### Test LM Studio
- **Without Endpoint:**
  1. Ensure no LM Studio endpoint is configured
  2. Click "Test Lm Studio Connection"
  3. Should display error
  4. Button should return to: "Test Lm Studio Connection" (note capitalization)

- **With Endpoint:**
  1. Configure endpoint (e.g., http://127.0.0.1:1234)
  2. Click button
  3. Should either succeed or fail with connection error
  4. Button should return to original text

### MCP Server Diagnostic Page

1. Navigate to **Tools → WP oOS MCP Test**
2. Test each section:

#### Test MCP Endpoint
1. Click "Test MCP Endpoint"
2. Should display:
   - Success message with JSON response
   - OR error message if MCP is not configured
3. Button should return to: "Test MCP Endpoint"

#### Test MCP Methods
For each method (Initialize, Tools List, Resources List, Prompts List):
1. Click the corresponding "Test [Method]" button
2. Should display:
   - Success message with response details
   - OR error message if method fails
3. Button should return to its original text (e.g., "Test Initialize")

## Automated Testing

Run the test suite to verify the diagnostic pages:

```bash
# Run all tests
composer run test

# Run specific test file
vendor/bin/phpunit tests/test-provider-diagnostic-endpoints.php
vendor/bin/phpunit tests/test-mcp-diagnostic-endpoints.php
```

## Expected Behavior

### All Test Buttons Should:
1. ✓ Disable during the test
2. ✓ Show "Testing..." text
3. ✓ Display a result (success or error) when complete
4. ✓ Re-enable after the test
5. ✓ Return to original button text
6. ✓ Handle errors gracefully without JavaScript console errors

### Error Messages Should:
1. ✓ Always display (not cause JavaScript errors)
2. ✓ Be meaningful and actionable
3. ✓ Include details when available

### Button Text Should:
1. ✓ Be properly capitalized (e.g., "Test Lm Studio Connection", not "Test lm_studio Connection")
2. ✓ Remain consistent after multiple test runs
3. ✓ Not break with special characters or underscores

## Common Issues to Check

### JavaScript Console Errors
- Open browser DevTools → Console
- Click each test button
- Verify NO errors appear like:
  - "Cannot read property 'message' of undefined"
  - "Cannot find property 'data' of undefined"

### Button Text Issues
- After clicking any test button multiple times
- Verify button text doesn't:
  - Accumulate extra text
  - Lose capitalization
  - Show raw provider slugs (e.g., "lm_studio" instead of "Lm Studio")

### Result Display
- Verify all tests display SOME result
- Even if the test fails, a result should be shown
- No tests should "hang" with "Testing..." forever (except network timeouts)

## Code Quality Checks

```bash
# Run PHP linter
composer run lint

# Run PHP compatibility check
composer run lint:compat

# Run JavaScript linter (if applicable)
npm run lint:js
```

## Success Criteria

✅ All test buttons can be clicked
✅ All test buttons display a result (success or error)
✅ All test buttons return to their original state
✅ No JavaScript console errors appear
✅ Button text is properly formatted
✅ Error messages are displayed correctly
✅ All automated tests pass
