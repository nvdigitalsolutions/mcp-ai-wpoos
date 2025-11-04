# Rate-Limit Protection

WP oOS includes comprehensive rate-limit protection features to ensure reliable API usage and prevent service disruptions.

## Features

### 1. Exponential Backoff with Retry-After Header

The rate-limit manager automatically retries failed requests using exponential backoff:

- Respects the `Retry-After` header from API responses
- Uses exponential backoff when no header is present
- Configurable retry attempts, delays, and maximum delay
- Automatically handles 429 (Too Many Requests) and other retriable errors

**Example usage:**

```php
$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
    function() {
        // Your API call here
        return $api->make_request();
    },
    array(), // Arguments for the callable
    array(
        'max_retries'   => 3,
        'initial_delay' => 1,
        'max_delay'     => 60,
    )
);
```

**Filters:**

- `wp_mcp_ai_max_retries` - Adjust maximum retry attempts
- `wp_mcp_ai_initial_retry_delay` - Set initial retry delay in seconds
- `wp_mcp_ai_max_retry_delay` - Set maximum retry delay in seconds

### 2. Token Budget Management

The token budget manager helps optimize API usage by managing context size:

**Features:**

- Estimates token usage for messages
- Calculates available budget based on model limits
- Truncates messages to fit within limits
- Splits large documents into processable chunks
- Recommends streaming for large responses

**Example usage:**

```php
// Calculate token budget
$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget(
    'gpt-4o',
    $messages,
    4096 // Max output tokens
);

// Truncate messages if needed
if ($budget['used'] > $budget['limit']) {
    $messages = WP_MCP_AI_Token_Budget_Manager::truncate_messages(
        $messages,
        'gpt-4o',
        $budget['limit']
    );
}

// Split large documents
$chunks = WP_MCP_AI_Token_Budget_Manager::split_document(
    $large_content,
    4000, // Chunk size in tokens
    200   // Overlap in tokens
);

// Check if streaming is recommended
$should_stream = WP_MCP_AI_Token_Budget_Manager::should_stream(
    $messages,
    'gpt-4o',
    1000 // Streaming threshold
);
```

**Supported Models:**

The token budget manager includes limits for common models:

- GPT-4o: 128,000 tokens
- GPT-4o-mini: 128,000 tokens
- GPT-4.1: 128,000 tokens
- GPT-4.1-mini: 128,000 tokens
- o1-preview: 128,000 tokens
- o1-mini: 128,000 tokens
- Gemini 1.5 Pro: 2,097,152 tokens
- Gemini 1.5 Flash: 1,048,576 tokens

### 3. Concurrent Job Queue

The job queue manager prevents overwhelming the API service by managing concurrent requests:

**Features:**

- Queue jobs with priority support (HIGH, NORMAL, LOW)
- Limit concurrent API requests
- Automatic retry with backoff for failed jobs
- Job timeout handling
- Persistent queue storage

**Example usage:**

```php
// Enqueue a job
WP_MCP_AI_Job_Queue_Manager::enqueue_job(
    'unique_job_id',
    array(
        'callable' => function() {
            // Your job logic
            return $api->process_data();
        },
        'args'     => array(),
        'priority' => WP_MCP_AI_Job_Queue_Manager::PRIORITY_HIGH,
        'timeout'  => 300, // 5 minutes
    )
);

// Process the queue
$result = WP_MCP_AI_Job_Queue_Manager::process_queue(
    3 // Max concurrent jobs
);

// Get queue statistics
$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
```

**WP-CLI Commands:**

```bash
# View queue statistics
wp mcp-ai queue stats

# Process the queue
wp mcp-ai queue process --max-concurrent=5

# Clear the queue
wp mcp-ai queue clear
```

## Enhanced OpenAI Client

The enhanced OpenAI client automatically applies rate limiting and token budget management:

```php
$client = new WP_MCP_AI_Enhanced_OpenAI_Client();

// Create chat completion with automatic optimization
$result = $client->create_chat_completion(
    $messages,
    array(
        'model'          => 'gpt-4o',
        'optimize_tokens' => true, // Enable token optimization
        'max_retries'    => 3,
        'initial_delay'  => 1,
    )
);

// Check if rate limited
if ($client->is_rate_limited('gpt-4o')) {
    $retry_after = $client->get_retry_after('gpt-4o');
    // Wait until retry_after timestamp
}

// Split a large document
$chunks = $client->split_document(
    $content,
    'gpt-4o',
    4000, // Chunk size (optional)
    200   // Overlap (optional)
);
```

## Integration

The rate-limit protection features are automatically integrated into the plugin:

1. **OpenAI Client**: Use `WP_MCP_AI_Enhanced_OpenAI_Client` instead of the standard client for automatic rate limiting and token management.

2. **REST API**: The REST endpoints automatically benefit from rate-limit detection and logging.

3. **Job Queue**: Use the job queue for batch processing or background tasks to avoid concurrent request overload.

## Configuration

No additional configuration is required. The features use sensible defaults that can be customized via filters:

- `wp_mcp_ai_max_retries` - Maximum retry attempts (default: 3)
- `wp_mcp_ai_initial_retry_delay` - Initial retry delay in seconds (default: 1)
- `wp_mcp_ai_max_retry_delay` - Maximum retry delay in seconds (default: 60)

## Monitoring

Rate-limit events are logged when logging is enabled in the plugin settings:

- `rate_limit_exceeded` - When a rate limit is encountered
- `api_retry_scheduled` - When a retry is scheduled
- `token_budget_calculated` - Token budget information
- `token_budget_truncated` - When messages are truncated
- `job_enqueued` - When a job is added to the queue
- `job_completed` - When a job completes successfully
- `job_timeout` - When a job times out

Enable logging in **Settings → WP oOS → Enable Logging** to monitor rate-limit activity.
