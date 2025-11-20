# Gemini API Header Authentication & JSON Schema Support

## Overview

The Gemini client has been updated to use the latest REST API authentication method and support structured JSON schema responses.

## Changes Made

### 1. Header-Based Authentication

**Old Method (Query Parameter):**
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
$response = wp_remote_post( $url, array(
    'headers' => array(
        'Content-Type' => 'application/json',
    ),
    'body' => $payload,
) );
```

**New Method (Header):**
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
$response = wp_remote_post( $url, array(
    'headers' => array(
        'Content-Type'   => 'application/json',
        'x-goog-api-key' => $api_key,
    ),
    'body' => $payload,
) );
```

### 2. JSON Schema Response Support

Three new options have been added to control JSON schema responses:

#### `response_mime_type`
Sets the MIME type for the response (e.g., `application/json`).

#### `response_schema`
Provides a JSON schema to structure the response.

#### `response_json_schema`
Alternative field for JSON schema (same as `response_schema`).

## Usage Examples

### Basic JSON Schema Response

```php
$client = new WP_MCP_AI_Gemini_Client();

$schema = array(
    'type'       => 'object',
    'properties' => array(
        'name'  => array( 'type' => 'string' ),
        'age'   => array( 'type' => 'integer' ),
        'email' => array( 'type' => 'string' ),
    ),
    'required'   => array( 'name', 'email' ),
);

$messages = array(
    array(
        'role'    => 'user',
        'content' => 'Extract user info from: John Doe is 30 years old, contact: john@example.com',
    ),
);

$options = array(
    'model'                => 'gemini-2.5-flash',
    'response_mime_type'   => 'application/json',
    'response_json_schema' => $schema,
);

$response = $client->create_chat_completion( $messages, $options );
```

### Recipe Extraction Example

Based on the Google AI documentation example:

```php
$schema = array(
    'type'       => 'object',
    'properties' => array(
        'recipe_name'        => array(
            'type'        => 'string',
            'description' => 'The name of the recipe.',
        ),
        'prep_time_minutes'  => array(
            'type'        => 'integer',
            'description' => 'Time in minutes to prepare the recipe.',
        ),
        'ingredients'        => array(
            'type'  => 'array',
            'items' => array(
                'type'       => 'object',
                'properties' => array(
                    'name'     => array( 'type' => 'string' ),
                    'quantity' => array( 'type' => 'string' ),
                ),
                'required'   => array( 'name', 'quantity' ),
            ),
        ),
        'instructions'       => array(
            'type'  => 'array',
            'items' => array( 'type' => 'string' ),
        ),
    ),
    'required'   => array( 'recipe_name', 'ingredients', 'instructions' ),
);

$messages = array(
    array(
        'role'    => 'user',
        'content' => 'Extract the recipe from this text: [recipe text here]',
    ),
);

$options = array(
    'model'                => 'gemini-2.5-flash',
    'response_mime_type'   => 'application/json',
    'response_json_schema' => $schema,
);

$response = $client->create_chat_completion( $messages, $options );

// Parse the JSON response
if ( isset( $response['choices'][0]['message']['content'][0]['text'] ) ) {
    $recipe = json_decode( $response['choices'][0]['message']['content'][0]['text'], true );
    // $recipe now contains structured data matching the schema
}
```

## Benefits

1. **Security**: API key is no longer visible in URLs or logs
2. **Standards Compliance**: Follows modern REST API authentication practices
3. **Structured Data**: JSON schemas ensure predictable, type-safe responses
4. **Easier Parsing**: No need to extract data from free-form text responses
5. **Type Validation**: Gemini validates the response matches your schema

## Affected Methods

All the following methods now use header authentication:

- `create_chat_completion()`
- `stream_chat_completion()`
- `generate_image()`
- `edit_image()`
- `list_models()`
- `count_tokens()`
- `create_embedding()`

## File Service Updates

The Gemini File Service also uses header authentication:

- `upload_file()`
- `get_file()`
- `delete_file()`

## Backward Compatibility

This is a **breaking change** for direct API calls. However:

- All plugin code has been updated
- The Gemini client wrapper handles the new authentication automatically
- Existing code using the wrapper requires no changes
- Only custom direct API calls need updating

## Migration Guide

If you have custom code making direct Gemini API calls:

### Before
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
wp_remote_post( $url, array(
    'headers' => array( 'Content-Type' => 'application/json' ),
    'body'    => $payload,
) );
```

### After
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
wp_remote_post( $url, array(
    'headers' => array(
        'Content-Type'   => 'application/json',
        'x-goog-api-key' => $api_key,
    ),
    'body'    => $payload,
) );
```

## Testing

Comprehensive tests have been added in `tests/test-gemini-header-auth.php`:

- Header authentication verification
- URL sanitization (no API key in URL)
- JSON schema option handling
- All API methods covered

Run tests with:
```bash
composer run test -- tests/test-gemini-header-auth.php
```

## References

- [Google AI Gemini API Documentation](https://ai.google.dev/gemini-api/docs)
- [Structured Output Guide](https://ai.google.dev/gemini-api/docs/structured-output)
- [Authentication Guide](https://ai.google.dev/gemini-api/docs/authentication)

## Example Files

- `assets/examples/gemini-json-schema-example.php` - Complete working examples
