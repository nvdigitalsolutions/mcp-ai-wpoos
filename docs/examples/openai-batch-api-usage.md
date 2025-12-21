# OpenAI Batch API Usage Guide

**Date:** December 21, 2025  
**Version:** 1.0  
**Plugin:** WP oOS (Open Operator System)

## Overview

The OpenAI Batch API allows you to process large jobs asynchronously with **50% cost reduction** compared to synchronous API calls. This is ideal for bulk operations like content generation, embeddings creation, or mass content moderation.

### Key Benefits

- **50% Cost Savings**: Batch API provides 50% lower pricing than synchronous calls
- **Higher Rate Limits**: Dedicated quota with much higher throughput
- **24-Hour SLA**: Guaranteed completion within 24 hours
- **Async Processing**: Non-blocking operations that don't affect your site performance
- **Scalability**: Process thousands of requests efficiently

### Supported Endpoints

The Batch API supports the following OpenAI endpoints:

1. `/v1/chat/completions` - Bulk text generation
2. `/v1/embeddings` - Mass embeddings creation
3. `/v1/moderations` - Large-scale content moderation

---

## Quick Start

### 1. Prepare Your Input File

Create a JSONL (JSON Lines) file where each line is a valid JSON object with the following structure:

```json
{"custom_id": "request-1", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "gpt-4o-mini", "messages": [{"role": "user", "content": "Write a product description for wireless earbuds"}], "max_tokens": 1000}}
{"custom_id": "request-2", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "gpt-4o-mini", "messages": [{"role": "user", "content": "Write a product description for smart watch"}], "max_tokens": 1000}}
{"custom_id": "request-3", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "gpt-4o-mini", "messages": [{"role": "user", "content": "Write a product description for laptop stand"}], "max_tokens": 1000}}
```

**Important:** Each line must be a complete, valid JSON object. No commas between lines.

### 2. Upload the Input File

First, upload your JSONL file to OpenAI's file storage:

```php
// Upload the input file
$client = new WP_MCP_AI_OpenAI_Client();
$file_result = $client->upload_file(
    '/path/to/batch-requests.jsonl',
    array(
        'purpose' => 'batch',
    )
);

if ( is_wp_error( $file_result ) ) {
    // Handle error
    echo 'Upload failed: ' . $file_result->get_error_message();
} else {
    $input_file_id = $file_result['id'];
    echo 'File uploaded: ' . $input_file_id;
}
```

### 3. Create a Batch Job

Use the `create_batch` tool or client method:

#### Using the Tool

```php
$tool = new WP_MCP_AI_Tool_Create_Batch();
$result = $tool->execute(
    array(
        'input_file_id' => $input_file_id,
        'endpoint'      => '/v1/chat/completions',
        'metadata'      => array(
            'project'     => 'Product Descriptions',
            'batch_type'  => 'content_generation',
        ),
    ),
    array( 'user_id' => get_current_user_id() )
);

if ( ! is_wp_error( $result ) ) {
    $batch_id = $result['batch_id'];
    echo 'Batch created: ' . $batch_id;
    echo "\n" . $result['summary'];
}
```

#### Using the Client Directly

```php
$client = new WP_MCP_AI_OpenAI_Client();
$result = $client->create_batch(
    $input_file_id,
    '/v1/chat/completions',
    array(
        'metadata' => array(
            'project' => 'Product Descriptions',
        ),
    )
);

if ( ! is_wp_error( $result ) ) {
    $batch_id = $result['id'];
    echo 'Batch ID: ' . $batch_id;
    echo "\nStatus: " . $result['status'];
}
```

### 4. Monitor Batch Progress

Check the status of your batch job:

```php
$tool = new WP_MCP_AI_Tool_Get_Batch_Status();
$result = $tool->execute(
    array( 'batch_id' => $batch_id ),
    array( 'user_id' => get_current_user_id() )
);

if ( ! is_wp_error( $result ) ) {
    echo 'Status: ' . $result['status'];
    echo "\n" . $result['summary'];
    
    // Check if completed
    if ( 'completed' === $result['status'] ) {
        $output_file_id = $result['output_file_id'];
        echo "\nResults ready! Output file: " . $output_file_id;
    }
}
```

### 5. Download Results

When the batch is completed, download the results:

```php
if ( 'completed' === $result['status'] && ! empty( $result['output_file_id'] ) ) {
    $client = new WP_MCP_AI_OpenAI_Client();
    
    // Get file details
    $file_info = $client->retrieve_file( $result['output_file_id'] );
    
    // Download file content
    $file_content = $client->download_file( $result['output_file_id'] );
    
    if ( ! is_wp_error( $file_content ) ) {
        // Parse JSONL results
        $lines = explode( "\n", trim( $file_content ) );
        foreach ( $lines as $line ) {
            if ( empty( $line ) ) {
                continue;
            }
            
            $response = json_decode( $line, true );
            $custom_id = $response['custom_id'];
            $status_code = $response['response']['status_code'];
            
            if ( 200 === $status_code ) {
                // Extract the generated content
                $body = $response['response']['body'];
                $content = $body['choices'][0]['message']['content'];
                
                echo "Request $custom_id completed:\n";
                echo $content . "\n\n";
            } else {
                // Handle error
                $error = $response['response']['body']['error'];
                echo "Request $custom_id failed: " . $error['message'] . "\n\n";
            }
        }
    }
}
```

---

## Batch Status Lifecycle

A batch job goes through the following statuses:

1. **validating** - Input file is being validated (few seconds)
2. **in_progress** - Batch is being processed (up to 24 hours)
3. **completed** - All requests completed successfully
4. **failed** - Batch processing failed (check error_file_id)
5. **expired** - Batch expired before completion
6. **cancelling** - Batch is being cancelled
7. **cancelled** - Batch was cancelled

---

## Use Cases

### Bulk Content Generation

Generate product descriptions, blog posts, or marketing copy in bulk:

```jsonl
{"custom_id": "product-101", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "gpt-4o-mini", "messages": [{"role": "system", "content": "You are a product copywriter."}, {"role": "user", "content": "Write a 100-word product description for: Wireless Bluetooth Earbuds with Noise Cancellation"}]}}
{"custom_id": "product-102", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "gpt-4o-mini", "messages": [{"role": "system", "content": "You are a product copywriter."}, {"role": "user", "content": "Write a 100-word product description for: Smart Fitness Watch with Heart Rate Monitor"}]}}
```

### Mass Embeddings Creation

Generate embeddings for your entire content library:

```jsonl
{"custom_id": "post-1", "method": "POST", "url": "/v1/embeddings", "body": {"model": "text-embedding-3-small", "input": "Understanding WordPress hooks and filters for plugin development"}}
{"custom_id": "post-2", "method": "POST", "url": "/v1/embeddings", "body": {"model": "text-embedding-3-small", "input": "Best practices for WordPress database optimization and performance"}}
{"custom_id": "post-3", "method": "POST", "url": "/v1/embeddings", "body": {"model": "text-embedding-3-small", "input": "Building custom Gutenberg blocks with React and WordPress"}}
```

### Large-Scale Content Moderation

Moderate user-generated content in bulk:

```jsonl
{"custom_id": "comment-501", "method": "POST", "url": "/v1/moderations", "body": {"input": "This is a helpful comment about the product quality."}}
{"custom_id": "comment-502", "method": "POST", "url": "/v1/moderations", "body": {"input": "Great article! Thanks for sharing this information."}}
{"custom_id": "comment-503", "method": "POST", "url": "/v1/moderations", "body": {"input": "Looking forward to trying this plugin on my site."}}
```

---

## Advanced Features

### Custom Metadata

Add custom metadata to track and organize batch jobs:

```php
$result = $client->create_batch(
    $input_file_id,
    '/v1/chat/completions',
    array(
        'metadata' => array(
            'project'    => 'E-commerce SEO',
            'category'   => 'Product Descriptions',
            'batch_date' => gmdate( 'Y-m-d' ),
            'user_id'    => get_current_user_id(),
        ),
    )
);
```

### List and Filter Batches

View all your batch jobs with pagination:

```php
$tool = new WP_MCP_AI_Tool_List_Batches();
$result = $tool->execute(
    array(
        'limit' => 50,
    ),
    array( 'user_id' => get_current_user_id() )
);

if ( ! is_wp_error( $result ) ) {
    foreach ( $result['batches'] as $batch ) {
        echo sprintf(
            "Batch %s: %s (%s)\n",
            $batch['id'],
            $batch['status'],
            $batch['created_at']
        );
    }
}
```

### Cancel Running Batches

Cancel a batch that's taking too long or is no longer needed:

```php
$client = new WP_MCP_AI_OpenAI_Client();
$result = $client->cancel_batch( $batch_id );

if ( ! is_wp_error( $result ) ) {
    echo 'Batch cancelled. Status: ' . $result['status'];
}
```

---

## Error Handling

### Check for Errors

Always check for errors when working with batches:

```php
$result = $tool->execute( $arguments, $context );

if ( is_wp_error( $result ) ) {
    $error_code = $result->get_error_code();
    $error_message = $result->get_error_message();
    
    // Log error
    error_log( "Batch API Error [$error_code]: $error_message" );
    
    // Display user-friendly message
    wp_send_json_error( array(
        'message' => $error_message,
        'code'    => $error_code,
    ) );
    
    return;
}
```

### Download Error File

If a batch fails, download the error file for details:

```php
if ( 'failed' === $result['status'] && ! empty( $result['error_file_id'] ) ) {
    $client = new WP_MCP_AI_OpenAI_Client();
    $error_content = $client->download_file( $result['error_file_id'] );
    
    if ( ! is_wp_error( $error_content ) ) {
        // Parse error details
        $lines = explode( "\n", trim( $error_content ) );
        foreach ( $lines as $line ) {
            if ( empty( $line ) ) {
                continue;
            }
            
            $error_item = json_decode( $line, true );
            echo "Request {$error_item['custom_id']} failed:\n";
            echo $error_item['error']['message'] . "\n\n";
        }
    }
}
```

---

## Cost Estimation

### Calculate Savings

The Batch API provides 50% cost reduction compared to synchronous calls:

```php
// Synchronous cost example (1000 requests at 500 tokens average)
$sync_cost_per_1k_tokens = 0.00015; // gpt-4o-mini input cost
$total_tokens = 1000 * 500; // 500,000 tokens
$sync_cost = ( $total_tokens / 1000 ) * $sync_cost_per_1k_tokens;
echo "Synchronous cost: $" . number_format( $sync_cost, 2 ); // $0.075

// Batch API cost (50% reduction)
$batch_cost = $sync_cost * 0.5;
echo "\nBatch API cost: $" . number_format( $batch_cost, 2 ); // $0.0375

// Savings
$savings = $sync_cost - $batch_cost;
echo "\nSavings: $" . number_format( $savings, 2 ) . " (" . ( $savings / $sync_cost * 100 ) . "%)";
```

---

## Best Practices

### 1. Optimize Request Size

- **Batch size**: Aim for 1,000-10,000 requests per batch for optimal processing
- **Token limits**: Stay within model token limits for each request
- **File size**: Keep input files under 100 MB

### 2. Use Custom IDs Wisely

- Use meaningful custom IDs for easy result mapping: `product-{ID}`, `post-{ID}`, `user-{ID}`
- Include metadata in custom IDs if needed: `category-electronics-product-101`

### 3. Monitor Progress

- Check batch status every 5-10 minutes for quick jobs
- For large batches (10K+ requests), check every hour

### 4. Handle Failures Gracefully

- Always download and parse error files
- Implement retry logic for failed requests
- Use exponential backoff for retries

### 5. Clean Up Files

- Delete input and output files after processing to save storage costs
- Use OpenAI's file lifecycle management

---

## WordPress Integration Examples

### WP-CLI Command

Create a WP-CLI command for batch processing:

```php
/**
 * Process content generation in batches.
 *
 * ## OPTIONS
 *
 * <input-file>
 * : Path to the JSONL input file
 *
 * ## EXAMPLES
 *
 *     wp mcp-ai batch create batch-requests.jsonl
 */
class MCP_AI_Batch_Command {
    public function create( $args, $assoc_args ) {
        $input_file = $args[0];
        
        if ( ! file_exists( $input_file ) ) {
            WP_CLI::error( 'Input file not found.' );
            return;
        }
        
        // Upload file
        $client = new WP_MCP_AI_OpenAI_Client();
        $file_result = $client->upload_file( $input_file, array( 'purpose' => 'batch' ) );
        
        if ( is_wp_error( $file_result ) ) {
            WP_CLI::error( $file_result->get_error_message() );
            return;
        }
        
        // Create batch
        $batch_result = $client->create_batch(
            $file_result['id'],
            '/v1/chat/completions'
        );
        
        if ( is_wp_error( $batch_result ) ) {
            WP_CLI::error( $batch_result->get_error_message() );
            return;
        }
        
        WP_CLI::success( 'Batch created: ' . $batch_result['id'] );
        WP_CLI::line( 'Status: ' . $batch_result['status'] );
    }
}

WP_CLI::add_command( 'mcp-ai batch', 'MCP_AI_Batch_Command' );
```

### Scheduled Cron Job

Process batches automatically with WordPress cron:

```php
// Schedule batch processing
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'mcp_ai_check_batch_status' ) ) {
        wp_schedule_event( time(), 'hourly', 'mcp_ai_check_batch_status' );
    }
} );

// Check batch status
add_action( 'mcp_ai_check_batch_status', function() {
    $batch_ids = get_option( 'mcp_ai_active_batches', array() );
    
    if ( empty( $batch_ids ) ) {
        return;
    }
    
    $client = new WP_MCP_AI_OpenAI_Client();
    
    foreach ( $batch_ids as $key => $batch_id ) {
        $result = $client->retrieve_batch( $batch_id );
        
        if ( is_wp_error( $result ) ) {
            continue;
        }
        
        if ( 'completed' === $result['status'] ) {
            // Process results
            process_batch_results( $batch_id, $result['output_file_id'] );
            
            // Remove from active list
            unset( $batch_ids[ $key ] );
            update_option( 'mcp_ai_active_batches', array_values( $batch_ids ) );
        }
    }
} );

function process_batch_results( $batch_id, $output_file_id ) {
    // Download and process results
    $client = new WP_MCP_AI_OpenAI_Client();
    $content = $client->download_file( $output_file_id );
    
    if ( is_wp_error( $content ) ) {
        return;
    }
    
    // Process each result line
    $lines = explode( "\n", trim( $content ) );
    foreach ( $lines as $line ) {
        if ( empty( $line ) ) {
            continue;
        }
        
        $response = json_decode( $line, true );
        // Store or use the generated content
        // ...
    }
}
```

---

## Troubleshooting

### Common Issues

**Issue**: Batch stuck in "validating" status  
**Solution**: Check your JSONL file format. Each line must be valid JSON.

**Issue**: Batch fails immediately  
**Solution**: Verify the endpoint path is correct and supported.

**Issue**: High failure rate in results  
**Solution**: Check token limits and model availability for each request.

**Issue**: Can't download output file  
**Solution**: Wait a few minutes after completion, then retry.

---

## API Reference

### Client Methods

#### `create_batch( $input_file_id, $endpoint, $options = array() )`

Creates a new batch processing job.

**Parameters:**
- `$input_file_id` (string, required): ID of uploaded JSONL file
- `$endpoint` (string, required): OpenAI endpoint to use
- `$options` (array, optional):
  - `completion_window` (string): Time window ("24h")
  - `metadata` (array): Custom key-value pairs (max 16)
  - `timeout` (int): Request timeout in seconds

**Returns:** `array|WP_Error`

#### `retrieve_batch( $batch_id, $options = array() )`

Retrieves batch job status and details.

**Parameters:**
- `$batch_id` (string, required): Batch job ID
- `$options` (array, optional):
  - `timeout` (int): Request timeout

**Returns:** `array|WP_Error`

#### `cancel_batch( $batch_id, $options = array() )`

Cancels a running batch job.

**Parameters:**
- `$batch_id` (string, required): Batch job ID
- `$options` (array, optional):
  - `timeout` (int): Request timeout

**Returns:** `array|WP_Error`

#### `list_batches( $options = array() )`

Lists batch jobs with optional filtering.

**Parameters:**
- `$options` (array, optional):
  - `after` (string): Cursor for pagination
  - `limit` (int): Max results (1-100, default 20)
  - `timeout` (int): Request timeout

**Returns:** `array|WP_Error`

---

## Related Documentation

- [OpenAI Batch API Official Docs](https://platform.openai.com/docs/guides/batch)
- [OpenAI API Reference - Batches](https://platform.openai.com/docs/api-reference/batch)
- [OpenAI File API](https://platform.openai.com/docs/api-reference/files)
- [WP oOS Tool Reference](../reference/tools/tool-reference.md)

---

## Changelog

**v1.0** - December 21, 2025
- Initial Batch API integration
- Added client methods and tools
- Comprehensive documentation and examples

---

**Need Help?** See the *(Troubleshooting guide pending)* or open an issue on [GitHub](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues).
