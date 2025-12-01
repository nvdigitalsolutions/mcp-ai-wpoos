# LM Studio Integration Testing Guide

This guide explains how to test the LM Studio connection and model fetching functionality.

## What Was Fixed

**Issue:** The JavaScript frontend expected model data with an `id` field, but the PHP backend was returning models with a `name` field.

**Solution:** Updated `includes/class-wp-mcp-ai-lm-studio-client.php` to return models with the correct `id` field that matches the OpenAI API format and JavaScript expectations.

## Testing Locally

### Prerequisites

1. **LM Studio must be running** on your local machine
2. **Local server must be enabled** in LM Studio settings
3. **At least one model must be loaded** in LM Studio

### Default Configuration

- Default endpoint: `http://127.0.0.1:1234`
- You can change the port in LM Studio settings if needed

### Quick Test (Command Line)

Run the test script from the repository root:

```bash
./bin/test-lm-studio-local.sh
```

Or specify a custom endpoint:

```bash
./bin/test-lm-studio-local.sh http://localhost:8080
```

### Expected Output

```
==========================================
LM Studio Connection Test
==========================================

Endpoint: http://127.0.0.1:1234

Test 1: Testing connection...
   ✓ Connected successfully (HTTP 200)

Test 2: Fetching available models...
   ✓ Found 2 model(s)

Test 3: Verifying data structure...

   ✓ Models with 'id' field:
      - llama-2-7b
      - mistral-7b-instruct

Test 4: Simulating PHP data transformation...

   Processed models array:
[
  {
    "id": "llama-2-7b",
    "owned_by": "meta",
    "created": 1234567890
  },
  {
    "id": "mistral-7b-instruct",
    "owned_by": "mistralai",
    "created": 1234567891
  }
]

Test 5: Verifying JavaScript compatibility...

   ✓ JavaScript can access model.id
   Example: models[0].id = 'llama-2-7b'

==========================================
ALL TESTS PASSED ✓
==========================================

Summary:
  - Connection: OK
  - Models found: 2
  - Data structure: Compatible with JavaScript

The LM Studio integration should work correctly!
```

## Testing in WordPress Admin

1. Navigate to **Settings → WP oOS**
2. Scroll to the **LM Studio Configuration** section
3. Enter your endpoint URL: `http://127.0.0.1:1234`
4. Click **Test Connection** - should show success
5. Click **Fetch Models** - should list available models
6. Click on a model name to select it
7. Save settings

### What to Expect

- **Test Connection**: Should show a green checkmark with "Successfully connected to LM Studio instance"
- **Fetch Models**: Should display a list of clickable model names
- **Model Selection**: Clicking a model should populate the model field and show a confirmation

## Troubleshooting

### "Could not connect" Error

**Cause:** LM Studio server is not running or not accessible

**Solution:**
1. Open LM Studio
2. Go to the local server tab (usually the bottom left icon)
3. Click "Start Server" if it's not running
4. Verify the server is listening on the correct port (default: 1234)
5. Check if any firewall is blocking the connection

### "No models found" Error

**Cause:** No models are loaded in LM Studio

**Solution:**
1. Open LM Studio
2. Go to the models tab
3. Download and load at least one model
4. Make sure the model is enabled for the local server
5. Try fetching models again

### "Invalid JSON response" Error

**Cause:** LM Studio returned an unexpected response format

**Solution:**
1. Update LM Studio to the latest version
2. Check LM Studio logs for errors
3. Try restarting LM Studio
4. Verify the endpoint URL is correct

## API Details

### LM Studio Endpoints

LM Studio implements OpenAI-compatible API endpoints:

- **Models list:** `GET /v1/models`
- **Chat completion:** `POST /v1/chat/completions`

### Expected Response Format

```json
{
  "object": "list",
  "data": [
    {
      "id": "model-name",
      "object": "model",
      "created": 1234567890,
      "owned_by": "organization"
    }
  ]
}
```

### PHP Processing

The plugin transforms the LM Studio response into this structure:

```php
array(
    'id'       => 'model-name',        // Used by JavaScript
    'owned_by' => 'organization',      // Optional metadata
    'created'  => 1234567890,          // Optional timestamp
)
```

## Technical Details

### Files Changed

- `includes/class-wp-mcp-ai-lm-studio-client.php` - Line 180: Changed `'name'` to `'id'`

### Why This Matters

The JavaScript code in `assets/js/admin-settings.js` expects:
```javascript
model.id  // ✓ Correct
```

Not:
```javascript
model.name  // ✗ Would fail
```

This mismatch caused the model selection feature to break.

## Related Documentation

- [LM Studio Official Docs](https://lmstudio.ai/docs)
- [OpenAI API Compatibility](https://lmstudio.ai/docs/api/openai)
- [README.md - LM Studio Setup](../README.md#lm-studio-setup)
