# HuggingFace Datasets - Complete How-To Guide

**Version:** 1.0.0  
**Last Updated:** December 23, 2025  
**Status:** Production Ready ✅

## Table of Contents

1. [Introduction](#introduction)
2. [Prerequisites](#prerequisites)
3. [Setup & Configuration](#setup--configuration)
4. [Basic Operations](#basic-operations)
5. [WordPress Integration Examples](#wordpress-integration-examples)
6. [Advanced Patterns](#advanced-patterns)
7. [REST API Usage](#rest-api-usage)
8. [Troubleshooting](#troubleshooting)
9. [Best Practices](#best-practices)

---

## Introduction

This guide provides step-by-step instructions for using HuggingFace datasets in WP oOS. The integration allows you to query 50+ popular datasets directly from WordPress without downloading them, using the HuggingFace Dataset Viewer API.

**What You Can Do:**
- Browse and search datasets
- Get dataset information and statistics
- Preview dataset rows
- Filter and query specific data
- Use dataset examples in AI assistants
- Integrate dataset insights into WordPress content

**What You'll Learn:**
- How to enable and configure the feature
- How to use each of the 11 dataset tools
- How to integrate datasets into WordPress workflows
- How to build custom dataset-powered features

---

## Prerequisites

### Required
- WordPress 6.0 or higher
- WP oOS plugin installed and activated
- User role with `read` capability (minimum)
- Active internet connection

### Optional
- HuggingFace account (for private datasets)
- HuggingFace API token (for gated datasets)
- Basic understanding of datasets and splits (train/test/validation)

---

## Setup & Configuration

### Step 1: Enable HuggingFace Datasets

1. Log in to WordPress admin dashboard
2. Navigate to **WP oOS → Settings**
3. Click on the **Providers** tab
4. Scroll to **HuggingFace Dataset Viewer** section
5. Check the box: **"Enable tools for querying HuggingFace datasets"**
6. Click **Save Changes**

**Screenshot Location:**
```
Settings → Providers → HuggingFace Dataset Viewer
[✓] Enable tools for querying HuggingFace datasets
```

### Step 2: Configure Optional Settings

**For Private/Gated Datasets:**
```
1. Go to https://huggingface.co/settings/tokens
2. Create a new token with "read" access
3. Copy the token (starts with "hf_...")
4. Paste in WP oOS → Settings → Providers → HuggingFace API Token field
5. Save Changes
```

**Advanced Settings:**
- **Cache TTL**: How long to cache results (default: 3600 seconds / 1 hour)
- **Default Row Limit**: Number of rows to return by default (default: 10, max: 100)
- **Rate Limit**: Maximum API requests per hour (default: 60)

### Step 3: Verify Installation

Navigate to **WP oOS → HF Datasets** in the WordPress admin menu. You should see:
- A visual catalog of featured datasets
- Filter options (Category, Priority, Search)
- Preview buttons for each dataset
- Status: "Connection: Active ✓"

---

## Basic Operations

### 1. Check if a Dataset is Valid

**Tool:** `huggingface_dataset_is_valid`

**Purpose:** Verify that a dataset exists and is accessible before querying it.

**Usage in AI Assistant:**
```
User: "Is the IMDB dataset available?"

Assistant uses: huggingface_dataset_is_valid(dataset="stanfordnlp/imdb")
```

**Expected Response:**
```json
{
  "viewer": true,
  "preview": true,
  "filter": true,
  "search": true,
  "statistics": true
}
```

**PHP Code Example:**
```php
// Get the HuggingFace Datasets client
$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

// Check if dataset is valid
$result = $client->is_valid( 'stanfordnlp/imdb' );

if ( is_wp_error( $result ) ) {
    echo 'Error: ' . $result->get_error_message();
} else {
    echo 'Dataset is valid and supports: ';
    echo 'Viewer: ' . ( $result['viewer'] ? 'Yes' : 'No' );
}
```

---

### 2. Get Dataset Information

**Tool:** `huggingface_dataset_get_info`

**Purpose:** Retrieve metadata about a dataset including description, size, features, and configurations.

**Usage in AI Assistant:**
```
User: "Tell me about the SQuAD dataset"

Assistant uses: huggingface_dataset_get_info(dataset="rajpurkar/squad")
```

**Expected Response:**
```json
{
  "dataset_info": {
    "description": "Stanford Question Answering Dataset (SQuAD) is a reading comprehension dataset...",
    "citation": "@article{rajpurkar2016squad...",
    "homepage": "https://rajpurkar.github.io/SQuAD-explorer/",
    "license": "CC BY-SA 4.0",
    "features": {
      "id": { "dtype": "string" },
      "title": { "dtype": "string" },
      "context": { "dtype": "string" },
      "question": { "dtype": "string" },
      "answers": {
        "feature": {
          "text": { "dtype": "string" },
          "answer_start": { "dtype": "int32" }
        }
      }
    },
    "splits": {
      "train": {
        "name": "train",
        "num_bytes": 79346360,
        "num_examples": 87599
      },
      "validation": {
        "name": "validation",
        "num_bytes": 10473040,
        "num_examples": 10570
      }
    }
  }
}
```

**PHP Code Example:**
```php
$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
$info = $client->get_info( 'rajpurkar/squad' );

if ( ! is_wp_error( $info ) ) {
    $dataset_info = $info['dataset_info'];
    echo 'Description: ' . $dataset_info['description'] . "\n";
    echo 'License: ' . $dataset_info['license'] . "\n";
    echo 'Number of training examples: ' . $dataset_info['splits']['train']['num_examples'];
}
```

---

### 3. Preview Dataset Rows

**Tool:** `huggingface_dataset_preview_rows`

**Purpose:** Get the first N rows of a dataset for quick inspection.

**Usage in AI Assistant:**
```
User: "Show me 3 examples from the IMDB training set"

Assistant uses: huggingface_dataset_preview_rows(
    dataset="stanfordnlp/imdb",
    split="train",
    limit=3
)
```

**Expected Response:**
```json
{
  "features": [
    {
      "feature_idx": 0,
      "name": "text",
      "type": { "_type": "Value", "dtype": "string" }
    },
    {
      "feature_idx": 1,
      "name": "label",
      "type": { "_type": "ClassLabel", "names": ["neg", "pos"] }
    }
  ],
  "rows": [
    {
      "row_idx": 0,
      "row": {
        "text": "One of the other reviewers has mentioned that after watching just 1 Oz episode you'll be hooked...",
        "label": 1
      }
    },
    {
      "row_idx": 1,
      "row": {
        "text": "A wonderful little production...",
        "label": 1
      }
    },
    {
      "row_idx": 2,
      "row": {
        "text": "I thought this was a wonderful way to spend time on a too hot summer weekend...",
        "label": 1
      }
    }
  ]
}
```

**PHP Code Example:**
```php
$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

$result = $client->preview_rows(
    'stanfordnlp/imdb',  // dataset
    'default',            // config
    'train',              // split
    5                     // limit
);

if ( ! is_wp_error( $result ) && isset( $result['rows'] ) ) {
    foreach ( $result['rows'] as $row_data ) {
        $row = $row_data['row'];
        $sentiment = $row['label'] === 1 ? 'Positive' : 'Negative';
        echo "Review ({$sentiment}): " . substr( $row['text'], 0, 100 ) . "...\n";
    }
}
```

---

### 4. Search Within Dataset

**Tool:** `huggingface_dataset_search`

**Purpose:** Search for specific text within dataset content.

**Usage in AI Assistant:**
```
User: "Search the SQuAD dataset for questions about 'artificial intelligence'"

Assistant uses: huggingface_dataset_search(
    dataset="rajpurkar/squad",
    split="train",
    query="artificial intelligence",
    limit=5
)
```

**PHP Code Example:**
```php
$results = $client->search(
    'rajpurkar/squad',
    'default',
    'train',
    'machine learning',  // search query
    0,                   // offset
    10                   // limit
);

if ( ! is_wp_error( $results ) && isset( $results['rows'] ) ) {
    echo "Found " . count( $results['rows'] ) . " matching rows\n";
    foreach ( $results['rows'] as $row_data ) {
        $row = $row_data['row'];
        echo "Q: " . $row['question'] . "\n";
        echo "A: " . $row['answers']['text'][0] . "\n\n";
    }
}
```

---

### 5. Filter Dataset Rows

**Tool:** `huggingface_dataset_filter`

**Purpose:** Filter rows using SQL-like WHERE clauses and ORDER BY.

**Usage in AI Assistant:**
```
User: "Find all positive reviews (label=1) from IMDB sorted by length"

Assistant uses: huggingface_dataset_filter(
    dataset="stanfordnlp/imdb",
    split="train",
    where="label = 1",
    orderby="LENGTH(text) DESC",
    length=10
)
```

**PHP Code Example:**
```php
// Filter for positive reviews only
$filtered = $client->filter(
    'stanfordnlp/imdb',
    'default',
    'train',
    'label = 1',           // WHERE clause
    'LENGTH(text) DESC',   // ORDER BY clause
    0,                     // offset
    20                     // limit
);

if ( ! is_wp_error( $filtered ) ) {
    echo "Found " . count( $filtered['rows'] ) . " positive reviews\n";
}
```

**Filter Expression Examples:**
```sql
-- Numeric comparisons
"score > 0.5"
"year >= 2020"
"rating != 3"

-- String matching
"category = 'technology'"
"status IN ('published', 'featured')"

-- Combined conditions
"label = 1 AND LENGTH(text) > 500"
"score >= 0.8 OR rating = 5"
```

---

## WordPress Integration Examples

### Example 1: Moderate Comments Using Toxic Comment Dataset

**Scenario:** Automatically flag potentially toxic comments before they're published.

**Implementation:**

```php
/**
 * Check if a comment might be toxic using HuggingFace dataset patterns.
 *
 * @param int $comment_id Comment ID.
 * @return bool True if potentially toxic.
 */
function wpoos_check_toxic_comment( $comment_id ) {
    $comment = get_comment( $comment_id );
    if ( ! $comment ) {
        return false;
    }
    
    // Get HuggingFace client
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Search toxic comments dataset for similar patterns
    $search_query = substr( $comment->comment_content, 0, 100 ); // First 100 chars
    $results = $client->search(
        'google/civil_comments',
        'default',
        'train',
        $search_query,
        0,
        5
    );
    
    if ( is_wp_error( $results ) || ! isset( $results['rows'] ) ) {
        return false;
    }
    
    // Check toxicity scores of similar comments
    $toxic_count = 0;
    foreach ( $results['rows'] as $row_data ) {
        if ( isset( $row_data['row']['toxicity'] ) && $row_data['row']['toxicity'] > 0.5 ) {
            $toxic_count++;
        }
    }
    
    // Flag if more than 60% of similar comments are toxic
    return ( $toxic_count / count( $results['rows'] ) ) > 0.6;
}

// Hook into comment posting
add_action( 'comment_post', 'wpoos_moderate_comment_on_post', 10, 1 );

function wpoos_moderate_comment_on_post( $comment_id ) {
    if ( wpoos_check_toxic_comment( $comment_id ) ) {
        // Hold for moderation
        wp_set_comment_status( $comment_id, 'hold' );
        
        // Notify admin
        wp_mail(
            get_option( 'admin_email' ),
            'Comment flagged for review',
            "Comment ID {$comment_id} has been flagged as potentially toxic and held for moderation."
        );
    }
}
```

---

### Example 2: Generate Alt Text Using Image Caption Dataset

**Scenario:** Automatically suggest alt text for images using patterns from Flickr30k dataset.

**Implementation:**

```php
/**
 * Get alt text suggestions from HuggingFace caption dataset.
 *
 * @param string $image_context Description or context about the image.
 * @return array Array of suggested alt texts.
 */
function wpoos_get_alt_text_suggestions( $image_context ) {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Search for similar image descriptions
    $results = $client->search(
        'nlphuji/flickr30k',
        'default',
        'test',
        $image_context,
        0,
        5
    );
    
    if ( is_wp_error( $results ) || ! isset( $results['rows'] ) ) {
        return array();
    }
    
    $suggestions = array();
    foreach ( $results['rows'] as $row_data ) {
        if ( isset( $row_data['row']['caption'] ) ) {
            $suggestions[] = $row_data['row']['caption'];
        }
    }
    
    return array_slice( $suggestions, 0, 3 ); // Return top 3
}

// Add meta box to media library
add_action( 'add_meta_boxes_attachment', 'wpoos_add_alt_text_metabox' );

function wpoos_add_alt_text_metabox() {
    add_meta_box(
        'wpoos_alt_text_suggestions',
        'Alt Text Suggestions (HuggingFace)',
        'wpoos_render_alt_text_metabox',
        'attachment',
        'side',
        'default'
    );
}

function wpoos_render_alt_text_metabox( $post ) {
    $image_title = get_the_title( $post->ID );
    $suggestions = wpoos_get_alt_text_suggestions( $image_title );
    
    if ( empty( $suggestions ) ) {
        echo '<p>No suggestions available.</p>';
        return;
    }
    
    echo '<p><strong>Suggested alt texts:</strong></p>';
    echo '<ul>';
    foreach ( $suggestions as $suggestion ) {
        echo '<li>' . esc_html( $suggestion ) . '</li>';
    }
    echo '</ul>';
}
```

---

### Example 3: Summarize Blog Posts Using CNN/DailyMail Dataset

**Scenario:** Generate article summaries using summarization examples from CNN/DailyMail.

**Implementation:**

```php
/**
 * Generate a summary for a blog post using dataset examples.
 *
 * @param int $post_id Post ID.
 * @return string|false Generated summary or false on error.
 */
function wpoos_generate_post_summary( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return false;
    }
    
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Get summarization examples similar to our content
    $content_start = substr( wp_strip_all_tags( $post->post_content ), 0, 200 );
    $examples = $client->search(
        'abisee/cnn_dailymail',
        '3.0.0',
        'train',
        $content_start,
        0,
        3
    );
    
    if ( is_wp_error( $examples ) || ! isset( $examples['rows'] ) ) {
        return false;
    }
    
    // Use the AI assistant to generate summary based on examples
    $assistant_context = "Here are examples of good article summaries:\n\n";
    foreach ( $examples['rows'] as $row_data ) {
        if ( isset( $row_data['row']['highlights'] ) ) {
            $assistant_context .= "- " . $row_data['row']['highlights'] . "\n";
        }
    }
    
    $assistant_context .= "\nNow summarize this article in a similar style:\n" . $post->post_content;
    
    // Return context for AI assistant to process
    return $assistant_context;
}

// Add a button to post editor
add_action( 'post_submitbox_misc_actions', 'wpoos_add_summary_button' );

function wpoos_add_summary_button() {
    global $post;
    if ( 'post' !== $post->post_type ) {
        return;
    }
    ?>
    <div class="misc-pub-section">
        <button type="button" 
                class="button" 
                onclick="wpoos_generate_summary(<?php echo esc_js( $post->ID ); ?>)">
            Generate Summary from Dataset Examples
        </button>
        <div id="wpoos-summary-result" style="margin-top: 10px;"></div>
    </div>
    <?php
}
```

---

### Example 4: Categorize WooCommerce Products Using Fashion MNIST

**Scenario:** Automatically suggest product categories based on Fashion MNIST dataset.

**Implementation:**

```php
/**
 * Suggest product categories using Fashion MNIST patterns.
 *
 * @param int $product_id Product ID.
 * @return array Suggested categories.
 */
function wpoos_suggest_product_categories( $product_id ) {
    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return array();
    }
    
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Get Fashion MNIST category mapping
    $info = $client->get_info( 'zalando-datasets/fashion_mnist' );
    
    if ( is_wp_error( $info ) ) {
        return array();
    }
    
    // Categories in Fashion MNIST
    $fashion_categories = array(
        'T-shirt/top',
        'Trouser',
        'Pullover',
        'Dress',
        'Coat',
        'Sandal',
        'Shirt',
        'Sneaker',
        'Bag',
        'Ankle boot'
    );
    
    $product_name = $product->get_name();
    $product_description = $product->get_description();
    
    // Match product name against fashion categories
    $suggested = array();
    foreach ( $fashion_categories as $category ) {
        if ( stripos( $product_name, $category ) !== false || 
             stripos( $product_description, $category ) !== false ) {
            $suggested[] = $category;
        }
    }
    
    return $suggested;
}

// Hook into product save
add_action( 'woocommerce_process_product_meta', 'wpoos_auto_categorize_product' );

function wpoos_auto_categorize_product( $product_id ) {
    $suggestions = wpoos_suggest_product_categories( $product_id );
    
    if ( ! empty( $suggestions ) ) {
        // Add as product meta for review
        update_post_meta( $product_id, '_wpoos_suggested_categories', $suggestions );
    }
}
```

---

## Advanced Patterns

### Pattern 1: Chaining Multiple Tools

**Scenario:** Find a dataset, validate it, get examples, and use them.

```php
function wpoos_dataset_workflow_example( $use_case ) {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Step 1: Get recommendations
    $tool = new WP_MCP_AI_Tool_Huggingface_Recommended_Datasets();
    $recommendations = $tool->execute( 
        array( 'use_case' => $use_case, 'limit' => 3 ),
        array()
    );
    
    if ( is_wp_error( $recommendations ) || empty( $recommendations['recommendations'] ) ) {
        return new WP_Error( 'no_recommendations', 'No datasets found for this use case' );
    }
    
    $chosen_dataset = $recommendations['recommendations'][0]['dataset'];
    
    // Step 2: Validate dataset
    $is_valid = $client->is_valid( $chosen_dataset );
    if ( is_wp_error( $is_valid ) || ! $is_valid['viewer'] ) {
        return new WP_Error( 'invalid_dataset', 'Dataset is not accessible' );
    }
    
    // Step 3: Get dataset info
    $info = $client->get_info( $chosen_dataset );
    if ( is_wp_error( $info ) ) {
        return $info;
    }
    
    // Step 4: Get first split name
    $splits = array_keys( $info['dataset_info']['splits'] );
    $first_split = $splits[0];
    
    // Step 5: Preview rows
    $examples = $client->preview_rows( $chosen_dataset, 'default', $first_split, 5 );
    
    return array(
        'dataset' => $chosen_dataset,
        'info' => $info,
        'examples' => $examples
    );
}
```

---

### Pattern 2: Caching Dataset Results

**Scenario:** Cache frequently accessed dataset results to reduce API calls.

```php
function wpoos_get_cached_dataset_preview( $dataset, $split = 'train', $limit = 10 ) {
    $cache_key = 'wpoos_dataset_' . md5( $dataset . $split . $limit );
    $cached = get_transient( $cache_key );
    
    if ( false !== $cached ) {
        return $cached;
    }
    
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    $result = $client->preview_rows( $dataset, 'default', $split, $limit );
    
    if ( ! is_wp_error( $result ) ) {
        // Cache for 1 hour
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
    }
    
    return $result;
}
```

---

### Pattern 3: Batch Processing Multiple Datasets

**Scenario:** Process multiple datasets in parallel for comparison.

```php
function wpoos_compare_datasets( $datasets, $query ) {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    $results = array();
    
    foreach ( $datasets as $dataset_name ) {
        $search_result = $client->search(
            $dataset_name,
            'default',
            'train',
            $query,
            0,
            5
        );
        
        if ( ! is_wp_error( $search_result ) ) {
            $results[ $dataset_name ] = array(
                'matches' => count( $search_result['rows'] ),
                'examples' => $search_result['rows']
            );
        }
    }
    
    return $results;
}

// Usage
$comparison = wpoos_compare_datasets(
    array( 'stanfordnlp/imdb', 'yelp_review_full', 'amazon_polarity' ),
    'excellent product'
);
```

---

## REST API Usage

The HuggingFace dataset tools are available via WordPress REST API.

### Authentication

All requests require authentication via:
- **Nonce** (for logged-in users)
- **Assistant Credentials** (for programmatic access)
- **Guest Tokens** (for public surfaces)

### Endpoint Structure

```
POST /wp-json/mcp-ai/v1/chat
```

### Example: Preview Dataset via REST API

**Using cURL:**

```bash
# Get nonce (from logged-in session)
NONCE=$(curl -s -X GET \
  'https://yoursite.com/wp-admin/admin-ajax.php?action=wp_mcp_ai_get_nonce' \
  --cookie "wordpress_logged_in_xxx=..." \
  | jq -r '.nonce')

# Call tool via REST API
curl -X POST \
  'https://yoursite.com/wp-json/mcp-ai/v1/chat' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: ${NONCE}" \
  -d '{
    "messages": [
      {
        "role": "user",
        "content": "Preview 5 rows from the IMDB dataset"
      }
    ],
    "assistant_id": "your-assistant-id"
  }'
```

**Using JavaScript:**

```javascript
// In WordPress admin or frontend with wp_localize_script
const response = await fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpMcpAi.nonce
    },
    body: JSON.stringify({
        messages: [
            {
                role: 'user',
                content: 'Show me examples from the SQuAD dataset'
            }
        ],
        assistant_id: wpMcpAi.assistantId
    })
});

const data = await response.json();
console.log(data);
```

---

## Troubleshooting

### Issue: "Dataset not found"

**Symptoms:**
```
Error: wp_mcp_ai_hf_datasets_api_error
Message: HuggingFace Dataset Viewer API returned error: 404
```

**Solutions:**
1. Verify dataset name on HuggingFace Hub
2. Check spelling and case sensitivity
3. Ensure dataset has Dataset Viewer enabled
4. Try accessing dataset via web browser first

**Example Fix:**
```php
// Wrong
$result = $client->is_valid( 'IMDB' );

// Correct
$result = $client->is_valid( 'stanfordnlp/imdb' );
```

---

### Issue: "Rate limit exceeded"

**Symptoms:**
```
Error: wp_mcp_ai_hf_datasets_rate_limited
Message: Rate limit exceeded. Please try again later.
```

**Solutions:**
1. Enable caching (check Settings → Providers)
2. Increase cache TTL to reduce API calls
3. Wait 60 minutes for rate limit reset
4. Upgrade to HuggingFace Pro ($9/month for higher limits)

**Example Fix:**
```php
// Check if rate limited before making request
$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

// Enable longer cache
add_filter( 'wp_mcp_ai_hf_datasets_cache_ttl', function() {
    return 7200; // 2 hours instead of 1
});
```

---

### Issue: "Configuration not found"

**Symptoms:**
```
Error: Configuration 'myconfig' not found
```

**Solutions:**
1. Use `huggingface_dataset_list_splits` to see available configs
2. Try `config="default"` if unsure
3. Check dataset documentation on HuggingFace

**Example Fix:**
```php
// First, list available splits
$splits = $client->get_splits( 'abisee/cnn_dailymail' );

// Response shows available configs
foreach ( $splits['splits'] as $split_info ) {
    echo "Config: " . $split_info['config'] . "\n";
}

// Then use correct config
$result = $client->preview_rows( 'abisee/cnn_dailymail', '3.0.0', 'train', 5 );
```

---

### Issue: "API token required"

**Symptoms:**
```
Error: This dataset is gated and requires authentication
```

**Solutions:**
1. Get HuggingFace API token from https://huggingface.co/settings/tokens
2. Add token in WP oOS → Settings → Providers
3. Accept dataset terms on HuggingFace website first

---

### Issue: "Empty response"

**Symptoms:**
```
Returns: { "rows": [] }
```

**Solutions:**
1. Check if dataset has data in that split
2. Verify filter/search criteria aren't too restrictive
3. Try different split (train vs test vs validation)
4. Check dataset size with `get_size` tool

---

## Best Practices

### 1. Start Small

Always preview with small limits first:
```php
// Good - Start with 5 rows
$result = $client->preview_rows( $dataset, 'default', 'train', 5 );

// Bad - Requesting max rows immediately
$result = $client->preview_rows( $dataset, 'default', 'train', 100 );
```

### 2. Use Caching

Enable and configure caching to reduce API calls:
```php
// Settings → Providers → Cache TTL: 3600 (1 hour)
// Or programmatically:
add_filter( 'wp_mcp_ai_hf_datasets_cache_ttl', function() {
    return 7200; // 2 hours
});
```

### 3. Validate Before Querying

Always check if dataset is valid before making multiple requests:
```php
$is_valid = $client->is_valid( $dataset );
if ( is_wp_error( $is_valid ) || ! $is_valid['viewer'] ) {
    // Handle error
    return;
}

// Proceed with other operations
$info = $client->get_info( $dataset );
```

### 4. Handle Errors Gracefully

Always check for WordPress errors:
```php
$result = $client->preview_rows( $dataset, 'default', 'train', 10 );

if ( is_wp_error( $result ) ) {
    // Log error
    error_log( 'HF Dataset Error: ' . $result->get_error_message() );
    
    // Show user-friendly message
    return 'Unable to load dataset examples at this time.';
}

// Process result
foreach ( $result['rows'] as $row_data ) {
    // ...
}
```

### 5. Respect Rate Limits

Monitor your usage and implement backoff:
```php
function wpoos_safe_dataset_request( $callback ) {
    static $request_count = 0;
    static $last_reset = 0;
    
    $now = time();
    
    // Reset counter every hour
    if ( $now - $last_reset > 3600 ) {
        $request_count = 0;
        $last_reset = $now;
    }
    
    // Stop if approaching limit
    if ( $request_count >= 50 ) { // Leave buffer
        return new WP_Error( 'rate_limit_buffer', 'Approaching rate limit' );
    }
    
    $request_count++;
    return call_user_func( $callback );
}
```

### 6. Optimize Token Usage in AI Assistants

Control how much data you request:
```php
// Good - Limit rows to control token usage
"Preview 5 rows from IMDB" // Uses ~500-1000 tokens

// Bad - Unnecessary large requests
"Preview 100 rows from IMDB" // Uses ~10,000-20,000 tokens
```

### 7. Use Appropriate Tools

Choose the right tool for the job:
```php
// For quick inspection - use preview
$client->preview_rows( $dataset, 'default', 'train', 5 );

// For specific search - use search
$client->search( $dataset, 'default', 'train', 'query', 0, 10 );

// For complex filtering - use filter
$client->filter( $dataset, 'default', 'train', 'label = 1', 'score DESC', 0, 10 );

// For metadata only - use get_info
$client->get_info( $dataset );
```

### 8. Document Your Usage

Add comments explaining dataset usage:
```php
/**
 * Get product review examples from IMDB dataset.
 * 
 * Dataset: stanfordnlp/imdb
 * Purpose: Sentiment analysis training examples
 * Updates: Cached for 1 hour
 * Rate: ~5-10 API calls per workflow
 */
function wpoos_get_review_examples() {
    // ...
}
```

---

## Additional Resources

- **Quick Start Guide**: See `docs/HUGGINGFACE_DATASETS_QUICK_START.md`
- **Dataset Catalog**: See `docs/HUGGINGFACE_TOP_DATASETS.md`
- **API Reference**: See `docs/HUGGINGFACE_DATASETS_IMPLEMENTATION_PLAN.md`
- **HuggingFace Docs**: https://huggingface.co/docs/dataset-viewer
- **WP oOS Main Docs**: See `docs/DOCUMENTATION_INDEX.md`

---

## FAQ

**Q: Do I need a HuggingFace account?**  
A: No, public datasets work without an account. Only private/gated datasets require authentication.

**Q: How many datasets are available?**  
A: 50+ curated datasets are featured in WP oOS, but you can access thousands more from HuggingFace Hub.

**Q: Is there a cost?**  
A: Free tier allows 60 requests/hour. HuggingFace Pro ($9/month) offers higher limits.

**Q: Can I download datasets?**  
A: Use `huggingface_dataset_get_parquet` to get download URLs for bulk access.

**Q: How is data cached?**  
A: Results are cached using WordPress transients with configurable TTL (default: 1 hour).

**Q: Can I use this with custom datasets?**  
A: Yes, as long as the dataset has Dataset Viewer enabled on HuggingFace Hub.

---

**Last Updated:** December 23, 2025  
**Version:** 1.0.0  
**Plugin:** WP oOS (Open Operator System)  
**Feature Status:** Production Ready ✅
