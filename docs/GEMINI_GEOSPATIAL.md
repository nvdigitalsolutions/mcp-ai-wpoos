# Gemini Geospatial API Integration

## Overview

The WP oOS plugin now includes integration with Google's Gemini Geospatial API, enabling AI-powered location-based queries with Google Maps grounding. This feature allows users to ask natural language questions about places, directions, and local information, receiving rich, contextual answers powered by Gemini's AI and Google Maps data.

## What is Gemini Geospatial API?

The Gemini Geospatial API combines:
- **Gemini AI Models**: Advanced language understanding and generation
- **Google Maps Grounding**: Real-time, verified geospatial data from Google Maps
- **Contextual Responses**: AI-generated answers enriched with map-based insights
- **Context Tokens**: Special tokens that can be used to render interactive map visualizations

## Features

### AI-Powered Geospatial Queries
Ask natural language questions about:
- **Places**: "What are the best coffee shops near Central Park?"
- **Directions**: "How do I get from Times Square to Brooklyn Bridge?"
- **Local Information**: "Tell me about dog-friendly parks in Seattle"
- **Area Insights**: "What's special about the Mission District in San Francisco?"

### Google Maps Grounding
- Access to 250M+ places in Google Maps database
- Real-time, verified geospatial data
- Reduced hallucinations through factual grounding
- Context-aware recommendations

### Interactive Map Context
- Responses include `googleMapsWidgetContextToken` when available
- Tokens can be used with Google Maps JavaScript API Contextual View component
- Enable interactive map visualizations in your frontend

## Technical Implementation

### Gemini Client Enhancement

The `WP_MCP_AI_Gemini_Client` class now includes a new `create_geospatial_query()` method:

```php
$client = new WP_MCP_AI_Gemini_Client();

$response = $client->create_geospatial_query(
    'Find restaurants with outdoor seating in downtown Seattle',
    array(
        'location' => array(
            'latitude'  => 47.6062,
            'longitude' => -122.3321,
        ),
        'temperature' => 0.7,
        'model'       => 'gemini-1.5-flash',
        'timeout'     => 30,
    )
);

if ( ! is_wp_error( $response ) ) {
    $content = $response['content'];
    $context_token = $response['google_maps_context_token'] ?? null;
    
    // Use context token with Maps JavaScript API for visualization
}
```

### Tool Integration

A new tool `gemini_geospatial_query` is available for AI assistants:

```json
{
  "tool": "gemini_geospatial_query",
  "arguments": {
    "query": "What are popular tourist attractions in Paris?",
    "latitude": 48.8566,
    "longitude": 2.3522,
    "temperature": 0.8
  }
}
```

#### Tool Parameters

| Parameter    | Type   | Required | Description                                           |
|--------------|--------|----------|-------------------------------------------------------|
| `query`      | string | Yes      | Natural language query about locations or places      |
| `latitude`   | number | No       | Latitude for location context                         |
| `longitude`  | number | No       | Longitude for location context                        |
| `model`      | string | No       | Gemini model to use (defaults to configured model)    |
| `temperature`| number | No       | Creativity level (0.0-2.0)                           |
| `timeout`    | integer| No       | Request timeout in seconds (5-120)                    |

#### Tool Response

```json
{
  "summary": "Geospatial query completed for: What are popular tourist attractions in Paris?",
  "query": "What are popular tourist attractions in Paris?",
  "content": "Paris offers numerous world-famous attractions...",
  "model": "gemini-1.5-flash",
  "has_map_context": true,
  "google_maps_context_token": "CTxY8vQ...",
  "usage": {
    "prompt_tokens": 15,
    "completion_tokens": 120,
    "total_tokens": 135
  }
}
```

## Configuration

### Requirements

1. **Gemini API Key**: Configure in Settings → WP oOS
2. **Gemini Model**: Set default model (recommended: `gemini-1.5-flash` or `gemini-1.5-pro`)
3. **User Capabilities**: Users must have at least `read` capability

### Settings Location

Navigate to **WordPress Admin → Settings → WP oOS**:
- Set your Gemini API key
- Choose default Gemini model
- Enable logging for debugging

## Usage Examples

### Example 1: Find Nearby Places

```php
$tool = new WP_MCP_AI_Tool_Gemini_Geospatial_Query();

$result = $tool->execute(
    array(
        'query' => 'Find museums near me',
        'latitude' => 40.7580,
        'longitude' => -73.9855,
    ),
    array( 'user_id' => get_current_user_id() )
);
```

### Example 2: Get Area Information

```php
$result = $tool->execute(
    array(
        'query' => 'Tell me about the historic district in Charleston, SC',
        'temperature' => 0.7,
    ),
    array( 'user_id' => get_current_user_id() )
);
```

### Example 3: Route Planning

```php
$result = $tool->execute(
    array(
        'query' => 'Best route from LAX airport to Santa Monica with stops for EV charging',
        'latitude' => 33.9416,
        'longitude' => -118.4085,
    ),
    array( 'user_id' => get_current_user_id() )
);
```

## Frontend Integration

### Using Context Tokens

When a response includes a `google_maps_context_token`, you can use it with the Google Maps JavaScript API Contextual View component:

```javascript
// Load Google Maps JavaScript API with alpha version
// <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=places&v=alpha"></script>

const contextToken = response.google_maps_context_token;

const mapContextualView = new google.maps.ContextualView({
  contextToken: contextToken,
  container: document.getElementById('map-container'),
});
```

### Example Implementation

```javascript
// Make API call to WP oOS
fetch('/wp-json/mcp-ai/v1/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    messages: [{
      role: 'user',
      content: 'Find coffee shops near Central Park'
    }],
    tools: ['gemini_geospatial_query']
  })
})
.then(response => response.json())
.then(data => {
  // Display AI response
  document.getElementById('response').textContent = data.content;
  
  // Render map if context token available
  if (data.google_maps_context_token) {
    new google.maps.ContextualView({
      contextToken: data.google_maps_context_token,
      container: document.getElementById('map')
    });
  }
});
```

## Security & Capabilities

### User Authentication
- Users must be authenticated to use geospatial queries
- Minimum capability: `read`
- Token authentication supported for external integrations

### Capability Flags
The tool declares these capability flags:
- `external-api`: Makes external API calls to Gemini and Google Maps
- `requires-capability`: Requires user capabilities check
- `ai-powered`: Uses AI to generate responses

### Multisite Support
- Works in WordPress multisite installations
- Users must be members of the current site
- Network-wide settings available

## Filters & Hooks

### Payload Filter
Customize the request payload before sending to Gemini:

```php
add_filter( 'wp_mcp_ai_gemini_geospatial_payload', function( $payload, $options, $query ) {
    // Add custom grounding data
    $payload['custom_context'] = get_option( 'my_location_data' );
    return $payload;
}, 10, 3 );
```

### Result Filter
Modify the tool response before returning:

```php
add_filter( 'wp_mcp_ai_gemini_geospatial_query_result', function( $response, $arguments, $context ) {
    // Add custom metadata
    $response['custom_meta'] = array(
        'timestamp' => time(),
        'source' => 'gemini_geospatial'
    );
    return $response;
}, 10, 3 );
```

## Troubleshooting

### Common Issues

**1. "No Gemini API key has been configured"**
- Solution: Add your Gemini API key in Settings → WP oOS

**2. "You must be authenticated to use geospatial queries"**
- Solution: Ensure user is logged in or using valid token authentication

**3. No map context token in response**
- Note: Not all queries will generate a context token
- Context tokens are generated when the query has strong geospatial relevance
- Try more location-specific queries

**4. API errors or timeouts**
- Check your Gemini API key is valid
- Verify API quota hasn't been exceeded
- Increase timeout parameter for complex queries

### Debugging

Enable logging in Settings → WP oOS to see detailed request/response logs:

```php
// View recent logs
$logs = get_option( 'wp_mcp_ai_recent_activity', array() );
foreach ( $logs as $log ) {
    if ( $log['event'] === 'gemini_geospatial_request' ) {
        error_log( print_r( $log, true ) );
    }
}
```

## Limitations

### Current Limitations
- Context tokens are experimental (pre-GA feature)
- Geographic coverage may vary by region
- Some features require Google Maps JavaScript API alpha version
- Rate limits apply based on your Gemini API tier

### Best Practices
- Keep queries specific and location-focused for best results
- Include latitude/longitude when available for better context
- Use appropriate temperature settings (0.5-0.8 for factual queries)
- Cache responses when appropriate to reduce API calls

## Future Enhancements

The following features are planned for future releases:

### Phase 2: Extended Geospatial Analytics
- **Places Insights Integration**: BigQuery integration for POI analytics
- **Imagery Analysis**: Satellite and Street View image analysis
- **Roads Management**: Traffic patterns and congestion analysis
- **Earth Engine**: Access to 90+ petabytes of earth observation data

### Phase 3: Advanced Features
- **Custom ML Models**: Integration with Vertex AI for custom geospatial models
- **Batch Processing**: Bulk geospatial queries and analysis
- **Real-time Updates**: Webhook support for dynamic location data
- **Enhanced Visualization**: Additional map rendering options

## API Reference

### Class: `WP_MCP_AI_Gemini_Client`

#### Method: `create_geospatial_query()`

```php
public function create_geospatial_query( string $query, array $options = array() ): array|WP_Error
```

**Parameters:**
- `$query` (string, required): Natural language query about locations
- `$options` (array, optional): Configuration options
  - `location` (array): Latitude and longitude for context
  - `model` (string): Gemini model identifier
  - `temperature` (float): Creativity level (0.0-2.0)
  - `timeout` (int): Request timeout in seconds

**Returns:**
- Success: Array with `content`, `model`, `google_maps_context_token` (if available), `usage`
- Error: `WP_Error` object

### Class: `WP_MCP_AI_Tool_Gemini_Geospatial_Query`

#### Method: `execute()`

```php
public function execute( array $arguments = array(), array $context = array() ): array|WP_Error
```

**Parameters:**
- `$arguments` (array): Tool-specific arguments
- `$context` (array): Execution context (user_id, token_authenticated)

**Returns:**
- Success: Array with formatted response including summary
- Error: `WP_Error` object

## Support

For issues, questions, or feature requests:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/docs
- Email: support@nvdigitalsolutions.com

## License

This feature is part of WP oOS and is licensed under GPLv3 or later.
