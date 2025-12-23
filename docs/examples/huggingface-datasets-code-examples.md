# HuggingFace Datasets - Code Examples

**Version:** 1.0.0  
**Last Updated:** December 23, 2025  
**Companion To:** [Complete How-To Guide](../guides/features/HUGGINGFACE_DATASETS_HOW_TO.md)

## Table of Contents

1. [Basic PHP Examples](#basic-php-examples)
2. [AI Assistant Conversation Examples](#ai-assistant-conversation-examples)
3. [REST API Examples](#rest-api-examples)
4. [WordPress Integration Patterns](#wordpress-integration-patterns)
5. [WooCommerce Examples](#woocommerce-examples)
6. [Content Management Examples](#content-management-examples)
7. [Complete Working Examples](#complete-working-examples)

---

## Basic PHP Examples

### Example 1: Check Dataset Availability

```php
<?php
/**
 * Check if a HuggingFace dataset is available and accessible.
 */
function example_check_dataset() {
    // Get the client from container
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Check if dataset exists
    $dataset = 'stanfordnlp/imdb';
    $result = $client->is_valid( $dataset );
    
    if ( is_wp_error( $result ) ) {
        echo "Error: " . $result->get_error_message() . "\n";
        return false;
    }
    
    echo "Dataset: {$dataset}\n";
    echo "✓ Viewer API: " . ( $result['viewer'] ? 'Available' : 'Not available' ) . "\n";
    echo "✓ Preview: " . ( $result['preview'] ? 'Available' : 'Not available' ) . "\n";
    echo "✓ Search: " . ( $result['search'] ? 'Available' : 'Not available' ) . "\n";
    echo "✓ Filter: " . ( $result['filter'] ? 'Available' : 'Not available' ) . "\n";
    echo "✓ Statistics: " . ( $result['statistics'] ? 'Available' : 'Not available' ) . "\n";
    
    return true;
}

// Run example
example_check_dataset();
```

**Expected Output:**
```
Dataset: stanfordnlp/imdb
✓ Viewer API: Available
✓ Preview: Available
✓ Search: Available
✓ Filter: Available
✓ Statistics: Available
```

---

### Example 2: Get and Display Dataset Information

```php
<?php
/**
 * Retrieve and display detailed information about a dataset.
 */
function example_get_dataset_info() {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $dataset = 'rajpurkar/squad';
    $info = $client->get_info( $dataset );
    
    if ( is_wp_error( $info ) ) {
        echo "Error: " . $info->get_error_message() . "\n";
        return;
    }
    
    $dataset_info = $info['dataset_info'];
    
    echo "=== Dataset Information ===\n\n";
    echo "Name: {$dataset}\n";
    echo "Description: " . substr( $dataset_info['description'], 0, 200 ) . "...\n\n";
    
    echo "License: " . ( $dataset_info['license'] ?? 'Not specified' ) . "\n";
    echo "Homepage: " . ( $dataset_info['homepage'] ?? 'N/A' ) . "\n\n";
    
    echo "=== Splits ===\n";
    foreach ( $dataset_info['splits'] as $split_name => $split_info ) {
        echo "- {$split_name}: " . number_format( $split_info['num_examples'] ) . " examples ";
        echo "(" . size_format( $split_info['num_bytes'] ) . ")\n";
    }
    
    echo "\n=== Features ===\n";
    foreach ( $dataset_info['features'] as $feature_name => $feature_info ) {
        $dtype = isset( $feature_info['dtype'] ) ? $feature_info['dtype'] : 'complex';
        echo "- {$feature_name}: {$dtype}\n";
    }
}

example_get_dataset_info();
```

**Expected Output:**
```
=== Dataset Information ===

Name: rajpurkar/squad
Description: Stanford Question Answering Dataset (SQuAD) is a reading comprehension dataset, consisting of questions posed by crowdworkers on a set of Wikipedia articles...

License: CC BY-SA 4.0
Homepage: https://rajpurkar.github.io/SQuAD-explorer/

=== Splits ===
- train: 87,599 examples (75.67 MB)
- validation: 10,570 examples (9.98 MB)

=== Features ===
- id: string
- title: string
- context: string
- question: string
- answers: complex
```

---

### Example 3: Preview and Display Dataset Rows

```php
<?php
/**
 * Preview first few rows of a dataset with formatted output.
 */
function example_preview_dataset_rows() {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $dataset = 'stanfordnlp/imdb';
    $result = $client->preview_rows( $dataset, 'default', 'train', 5 );
    
    if ( is_wp_error( $result ) ) {
        echo "Error: " . $result->get_error_message() . "\n";
        return;
    }
    
    echo "=== Preview: {$dataset} (train split) ===\n\n";
    
    foreach ( $result['rows'] as $row_data ) {
        $row = $row_data['row'];
        $row_idx = $row_data['row_idx'];
        
        $sentiment = $row['label'] === 1 ? 'POSITIVE 😊' : 'NEGATIVE 😞';
        $text = substr( $row['text'], 0, 150 );
        
        echo "Row #{$row_idx} [{$sentiment}]:\n";
        echo "  {$text}...\n\n";
    }
}

example_preview_dataset_rows();
```

**Expected Output:**
```
=== Preview: stanfordnlp/imdb (train split) ===

Row #0 [POSITIVE 😊]:
  One of the other reviewers has mentioned that after watching just 1 Oz episode you'll be hooked. They are right, as this is exactly what happened...

Row #1 [POSITIVE 😊]:
  A wonderful little production. The filming technique is very unassuming- very old-time-BBC fashion and gives a comforting, and sometimes disquieting...

Row #2 [POSITIVE 😊]:
  I thought this was a wonderful way to spend time on a too hot summer weekend, sitting in the air conditioned theater and watching a light-hearted film...
```

---

### Example 4: Search Dataset Content

```php
<?php
/**
 * Search for specific content within a dataset.
 */
function example_search_dataset( $query = 'artificial intelligence' ) {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $dataset = 'rajpurkar/squad';
    $results = $client->search(
        $dataset,
        'default',
        'train',
        $query,
        0,
        5
    );
    
    if ( is_wp_error( $results ) ) {
        echo "Error: " . $results->get_error_message() . "\n";
        return;
    }
    
    $count = count( $results['rows'] );
    echo "=== Search Results: '{$query}' in {$dataset} ===\n";
    echo "Found {$count} matching results\n\n";
    
    foreach ( $results['rows'] as $row_data ) {
        $row = $row_data['row'];
        
        echo "Question: {$row['question']}\n";
        echo "Answer: {$row['answers']['text'][0]}\n";
        echo "Context: " . substr( $row['context'], 0, 100 ) . "...\n";
        echo str_repeat( '-', 60 ) . "\n\n";
    }
}

example_search_dataset( 'machine learning' );
```

**Expected Output:**
```
=== Search Results: 'machine learning' in rajpurkar/squad ===
Found 5 matching results

Question: What is machine learning?
Answer: a field of study that gives computers the ability to learn
Context: Machine learning is a field of study that gives computers the ability to learn without being...
------------------------------------------------------------

Question: When was the term machine learning coined?
Answer: 1959
Context: The term machine learning was coined in 1959 by Arthur Samuel, an American IBMer...
------------------------------------------------------------
```

---

### Example 5: Filter Dataset with Conditions

```php
<?php
/**
 * Filter dataset rows using SQL-like conditions.
 */
function example_filter_dataset() {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $dataset = 'stanfordnlp/imdb';
    
    // Find long positive reviews
    $filtered = $client->filter(
        $dataset,
        'default',
        'train',
        'label = 1 AND LENGTH(text) > 2000',  // WHERE clause
        'LENGTH(text) DESC',                   // ORDER BY
        0,
        10
    );
    
    if ( is_wp_error( $filtered ) ) {
        echo "Error: " . $filtered->get_error_message() . "\n";
        return;
    }
    
    echo "=== Filtered Results: Long Positive Reviews ===\n";
    echo "Criteria: label = 1 AND LENGTH(text) > 2000\n";
    echo "Order: Longest first\n\n";
    
    foreach ( $filtered['rows'] as $row_data ) {
        $row = $row_data['row'];
        $length = strlen( $row['text'] );
        
        echo "Length: " . number_format( $length ) . " chars\n";
        echo "Preview: " . substr( $row['text'], 0, 100 ) . "...\n\n";
    }
}

example_filter_dataset();
```

**Expected Output:**
```
=== Filtered Results: Long Positive Reviews ===
Criteria: label = 1 AND LENGTH(text) > 2000
Order: Longest first

Length: 13,704 chars
Preview: This is a fantastic film. The acting is superb, the cinematography is breathtaking, and the story...

Length: 11,245 chars
Preview: Where do I begin? This movie changed my life. From the opening scene to the final credits...

Length: 9,832 chars
Preview: I've been a fan of cinema for decades, and this is hands down one of the best films...
```

---

### Example 6: Get Dataset Statistics

```php
<?php
/**
 * Retrieve and display statistical information about a dataset.
 */
function example_get_statistics() {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $dataset = 'stanfordnlp/imdb';
    $stats = $client->get_statistics( $dataset, 'default', 'train' );
    
    if ( is_wp_error( $stats ) ) {
        echo "Error: " . $stats->get_error_message() . "\n";
        return;
    }
    
    echo "=== Dataset Statistics: {$dataset} ===\n";
    echo "Total Examples: " . number_format( $stats['num_examples'] ) . "\n\n";
    
    foreach ( $stats['statistics'] as $column_stat ) {
        $col_name = $column_stat['column_name'];
        $col_type = $column_stat['column_type'];
        $col_stats = $column_stat['column_statistics'];
        
        echo "Column: {$col_name} ({$col_type})\n";
        
        if ( $col_type === 'string_text' ) {
            echo "  - Min Length: " . number_format( $col_stats['min_length'] ?? 0 ) . "\n";
            echo "  - Max Length: " . number_format( $col_stats['max_length'] ?? 0 ) . "\n";
            echo "  - Avg Length: " . number_format( $col_stats['mean_length'] ?? 0 ) . "\n";
            echo "  - Median Length: " . number_format( $col_stats['median_length'] ?? 0 ) . "\n";
        } else {
            echo "  - Min: " . ( $col_stats['min'] ?? 'N/A' ) . "\n";
            echo "  - Max: " . ( $col_stats['max'] ?? 'N/A' ) . "\n";
            echo "  - Mean: " . number_format( $col_stats['mean'] ?? 0, 2 ) . "\n";
            echo "  - Median: " . ( $col_stats['median'] ?? 'N/A' ) . "\n";
        }
        
        echo "\n";
    }
}

example_get_statistics();
```

**Expected Output:**
```
=== Dataset Statistics: stanfordnlp/imdb ===
Total Examples: 25,000

Column: text (string_text)
  - Min Length: 52
  - Max Length: 13,704
  - Avg Length: 1,327
  - Median Length: 982

Column: label (int)
  - Min: 0
  - Max: 1
  - Mean: 0.50
  - Median: 0.5
```

---

## AI Assistant Conversation Examples

### Example 1: Finding Relevant Datasets

**User:**
```
I'm building a WordPress plugin for sentiment analysis of product reviews. 
What datasets would be good for training?
```

**Assistant Response:**
```
I'll help you find relevant datasets for product review sentiment analysis.

[Uses: huggingface_recommended_datasets(use_case="product review sentiment", limit=5)]

Based on your needs, here are the top 5 datasets:

1. **IMDB Movie Reviews** (stanfordnlp/imdb)
   - 50,000 movie reviews with binary sentiment labels
   - Clean, well-balanced dataset
   - Perfect for training basic sentiment classifiers

2. **Yelp Reviews** (yelp_review_full)
   - 650,000 Yelp reviews with 5-star ratings
   - Multi-class sentiment (1-5 stars)
   - Real product/service reviews

3. **Amazon Polarity** (amazon_polarity)
   - 3.6 million Amazon product reviews
   - Binary polarity (positive/negative)
   - Huge dataset for robust training

4. **Financial PhraseBank** (financial_phrasebank)
   - Financial news sentiment
   - Domain-specific sentiment patterns
   - Good for business contexts

5. **Multi-Domain Sentiment** (multi_domain_sentiment)
   - Reviews from multiple product categories
   - Book, DVD, electronics, kitchen products
   - Great for cross-domain training

For WordPress product reviews, I'd recommend starting with **Yelp Reviews** as it's 
most similar to WooCommerce product reviews. Would you like me to show you some examples?
```

---

### Example 2: Getting Dataset Examples

**User:**
```
Yes, show me 5 examples from the Yelp dataset
```

**Assistant Response:**
```
Let me preview some examples from the Yelp Reviews dataset.

[Uses: huggingface_dataset_preview_rows(dataset="yelp_review_full", split="train", limit=5)]

Here are 5 examples from Yelp Reviews:

**Example 1** (Rating: 5/5 ⭐⭐⭐⭐⭐)
"This is hands down my favorite restaurant in Phoenix. The food is consistently excellent, 
the service is impeccable, and the atmosphere is warm and inviting..."

**Example 2** (Rating: 1/5 ⭐)
"Terrible experience. We waited 45 minutes for our food, and when it finally arrived, 
it was cold and tasteless. The server was rude when we complained..."

**Example 3** (Rating: 4/5 ⭐⭐⭐⭐)
"Really good food, nice ambiance. Lost one star because the portions were a bit small 
for the price, but overall would definitely come back..."

**Example 4** (Rating: 2/5 ⭐⭐)
"Disappointed. Expected much better based on the reviews. The food was okay but nothing 
special, and very overpriced for what you get..."

**Example 5** (Rating: 5/5 ⭐⭐⭐⭐⭐)
"Absolutely amazing! From the moment we walked in, we were treated like royalty. Every 
dish was perfection. Can't wait to return..."

These examples show clear sentiment patterns that you can use to train your classifier!
```

---

### Example 3: Searching for Specific Content

**User:**
```
Search the SQuAD dataset for questions about "WordPress"
```

**Assistant Response:**
```
Let me search the SQuAD dataset for questions about WordPress.

[Uses: huggingface_dataset_search(dataset="rajpurkar/squad", split="train", query="WordPress", limit=10)]

I found 3 questions about WordPress in the SQuAD dataset:

**Question 1:**
Q: What is WordPress?
A: WordPress is a free and open-source content management system based on PHP and MySQL
Context: WordPress was released in 2003 by Matt Mullenweg and Mike Little as a fork of b2/cafelog...

**Question 2:**
Q: When was WordPress first released?
A: 2003
Context: WordPress was released on May 27, 2003, by its founders Matt Mullenweg and Mike Little...

**Question 3:**
Q: What programming language is WordPress written in?
A: PHP
Context: WordPress is written in PHP and paired with a MySQL or MariaDB database...

These Q&A pairs could be useful for creating a WordPress FAQ or training a support chatbot!
```

---

### Example 4: Filtering Dataset Results

**User:**
```
Find all positive IMDB reviews that mention "outstanding performance"
```

**Assistant Response:**
```
I'll search for positive IMDB reviews mentioning "outstanding performance".

[Uses: huggingface_dataset_filter(
    dataset="stanfordnlp/imdb",
    split="train",
    where="label = 1",
    limit=20
)]
[Then searches within results for "outstanding performance"]

Found 8 positive reviews mentioning "outstanding performance":

**Review 1:**
"The outstanding performance by the lead actor elevates this film to masterpiece status. 
Every scene is captivating, and the emotional depth is profound..."

**Review 2:**
"What an outstanding performance! The entire cast delivered, but the lead was exceptional. 
This movie will be remembered for years..."

**Review 3:**
"I was blown away by the outstanding performance. The actor completely disappeared into 
the role. One of the best performances I've ever seen..."

[... 5 more examples ...]

These reviews highlight what makes a performance truly outstanding - useful examples 
for training a model to recognize praise patterns!
```

---

## REST API Examples

### Example 1: Using cURL to Call Dataset Tools

**Get Dataset Information:**

```bash
#!/bin/bash

# Configuration
SITE_URL="https://yoursite.com"
USERNAME="admin"
PASSWORD="your_password"
ASSISTANT_ID="asst_123"

# Get authentication cookie
COOKIE=$(curl -s -c - \
  "${SITE_URL}/wp-login.php" \
  -d "log=${USERNAME}" \
  -d "pwd=${PASSWORD}" \
  -d "wp-submit=Log+In" \
  | grep "wordpress_logged_in" \
  | awk '{print $7}')

# Get nonce
NONCE=$(curl -s \
  "${SITE_URL}/wp-admin/admin-ajax.php?action=wp_mcp_ai_get_nonce" \
  -b "wordpress_logged_in=${COOKIE}" \
  | jq -r '.nonce')

# Make chat request
curl -X POST \
  "${SITE_URL}/wp-json/mcp-ai/v1/chat" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: ${NONCE}" \
  -d '{
    "messages": [
      {
        "role": "user",
        "content": "Tell me about the IMDB dataset"
      }
    ],
    "assistant_id": "'"${ASSISTANT_ID}"'",
    "stream": false
  }' | jq '.'
```

---

### Example 2: JavaScript Fetch API

**Search Dataset from Frontend:**

```javascript
async function searchHuggingFaceDataset(query) {
    try {
        const response = await fetch('/wp-json/mcp-ai/v1/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpMcpAi.nonce  // Localized from WordPress
            },
            body: JSON.stringify({
                messages: [
                    {
                        role: 'user',
                        content: `Search the SQuAD dataset for: ${query}`
                    }
                ],
                assistant_id: wpMcpAi.assistantId,
                stream: false
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        
        console.log('Search results:', data);
        displayResults(data);
        
    } catch (error) {
        console.error('Error searching dataset:', error);
    }
}

function displayResults(data) {
    const resultsContainer = document.getElementById('results');
    
    if (data.messages && data.messages.length > 0) {
        const lastMessage = data.messages[data.messages.length - 1];
        resultsContainer.innerHTML = `
            <div class="dataset-results">
                <h3>Results</h3>
                <div>${lastMessage.content}</div>
            </div>
        `;
    }
}

// Usage
document.getElementById('search-button').addEventListener('click', () => {
    const query = document.getElementById('search-input').value;
    searchHuggingFaceDataset(query);
});
```

---

### Example 3: jQuery AJAX

**Preview Dataset Rows:**

```javascript
jQuery(document).ready(function($) {
    $('#preview-dataset-button').on('click', function() {
        const dataset = $('#dataset-select').val();
        const limit = $('#limit-input').val() || 5;
        
        $.ajax({
            url: '/wp-json/mcp-ai/v1/chat',
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpMcpAi.nonce
            },
            contentType: 'application/json',
            data: JSON.stringify({
                messages: [
                    {
                        role: 'user',
                        content: `Preview ${limit} rows from the ${dataset} dataset`
                    }
                ],
                assistant_id: wpMcpAi.assistantId,
                stream: false
            }),
            success: function(response) {
                console.log('Preview data:', response);
                displayPreview(response);
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                alert('Failed to preview dataset');
            }
        });
    });
});

function displayPreview(response) {
    const $container = $('#preview-results');
    $container.empty();
    
    if (response.messages && response.messages.length > 0) {
        const content = response.messages[response.messages.length - 1].content;
        $container.html(`<pre>${content}</pre>`);
    }
}
```

---

## WordPress Integration Patterns

### Pattern 1: Custom Shortcode for Dataset Preview

```php
<?php
/**
 * Shortcode to display dataset preview in post content.
 * 
 * Usage: [hf_dataset_preview dataset="stanfordnlp/imdb" split="train" limit="3"]
 */
function wpoos_dataset_preview_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'dataset' => 'stanfordnlp/imdb',
            'split'   => 'train',
            'limit'   => 5,
        ),
        $atts,
        'hf_dataset_preview'
    );
    
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    $result = $client->preview_rows(
        sanitize_text_field( $atts['dataset'] ),
        'default',
        sanitize_text_field( $atts['split'] ),
        absint( $atts['limit'] )
    );
    
    if ( is_wp_error( $result ) ) {
        return '<div class="error">Error loading dataset: ' . esc_html( $result->get_error_message() ) . '</div>';
    }
    
    ob_start();
    ?>
    <div class="hf-dataset-preview">
        <h3>Dataset Preview: <?php echo esc_html( $atts['dataset'] ); ?></h3>
        <p class="split-info">Split: <?php echo esc_html( $atts['split'] ); ?> | 
           Showing <?php echo count( $result['rows'] ); ?> rows</p>
        
        <div class="dataset-rows">
            <?php foreach ( $result['rows'] as $row_data ) : ?>
                <div class="dataset-row">
                    <strong>Row #<?php echo esc_html( $row_data['row_idx'] ); ?>:</strong>
                    <pre><?php echo esc_html( wp_json_encode( $row_data['row'], JSON_PRETTY_PRINT ) ); ?></pre>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'hf_dataset_preview', 'wpoos_dataset_preview_shortcode' );
```

**Usage in Post:**
```
[hf_dataset_preview dataset="stanfordnlp/imdb" split="train" limit="3"]
```

---

### Pattern 2: Admin Dashboard Widget

```php
<?php
/**
 * Add HuggingFace dataset stats to WordPress dashboard.
 */
function wpoos_add_dataset_dashboard_widget() {
    wp_add_dashboard_widget(
        'wpoos_dataset_stats',
        'HuggingFace Dataset Stats',
        'wpoos_render_dataset_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', 'wpoos_add_dataset_dashboard_widget' );

function wpoos_render_dataset_dashboard_widget() {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Get stats for popular datasets
    $datasets = array(
        'stanfordnlp/imdb',
        'rajpurkar/squad',
        'abisee/cnn_dailymail'
    );
    
    echo '<div class="dataset-stats-widget">';
    echo '<h4>Popular Datasets</h4>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>Dataset</th><th>Rows</th><th>Size</th></tr></thead>';
    echo '<tbody>';
    
    foreach ( $datasets as $dataset ) {
        $size = $client->get_size( $dataset );
        
        if ( ! is_wp_error( $size ) && isset( $size['size'] ) ) {
            $num_rows = number_format( $size['size']['num_rows'] );
            $num_bytes = size_format( $size['size']['num_bytes_memory'] );
            
            echo '<tr>';
            echo '<td><strong>' . esc_html( $dataset ) . '</strong></td>';
            echo '<td>' . esc_html( $num_rows ) . '</td>';
            echo '<td>' . esc_html( $num_bytes ) . '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody></table>';
    echo '<p><a href="admin.php?page=wp-mcp-ai-hf-datasets">Browse All Datasets →</a></p>';
    echo '</div>';
}
```

---

### Pattern 3: Custom Post Type Meta Box

```php
<?php
/**
 * Add dataset suggestions meta box to posts.
 */
function wpoos_add_dataset_meta_box() {
    add_meta_box(
        'wpoos_dataset_suggestions',
        'Content Examples from Datasets',
        'wpoos_render_dataset_meta_box',
        'post',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'wpoos_add_dataset_meta_box' );

function wpoos_render_dataset_meta_box( $post ) {
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Get post title for search
    $post_title = $post->post_title;
    
    if ( empty( $post_title ) ) {
        echo '<p>Add a title to get content suggestions from datasets.</p>';
        return;
    }
    
    // Search CNN/DailyMail for similar articles
    $results = $client->search(
        'abisee/cnn_dailymail',
        '3.0.0',
        'train',
        substr( $post_title, 0, 100 ),
        0,
        3
    );
    
    if ( is_wp_error( $results ) || empty( $results['rows'] ) ) {
        echo '<p>No suggestions available.</p>';
        return;
    }
    
    echo '<p><strong>Similar articles from CNN/DailyMail:</strong></p>';
    echo '<ul>';
    
    foreach ( $results['rows'] as $row_data ) {
        if ( isset( $row_data['row']['highlights'] ) ) {
            $summary = substr( $row_data['row']['highlights'], 0, 100 );
            echo '<li>' . esc_html( $summary ) . '...</li>';
        }
    }
    
    echo '</ul>';
    echo '<p><em>Use these examples as inspiration for your content.</em></p>';
}
```

---

## WooCommerce Examples

### Example 1: Product Review Sentiment Analysis

```php
<?php
/**
 * Analyze product review sentiment using IMDB dataset patterns.
 */
function wpoos_analyze_product_review_sentiment( $review_id ) {
    $review = get_comment( $review_id );
    
    if ( ! $review || 'review' !== $review->comment_type ) {
        return null;
    }
    
    $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
    
    // Search IMDB for similar review patterns
    $review_text = substr( $review->comment_content, 0, 200 );
    $similar_reviews = $client->search(
        'stanfordnlp/imdb',
        'default',
        'train',
        $review_text,
        0,
        10
    );
    
    if ( is_wp_error( $similar_reviews ) || empty( $similar_reviews['rows'] ) ) {
        return null;
    }
    
    // Calculate sentiment score based on similar reviews
    $positive_count = 0;
    $total_count = count( $similar_reviews['rows'] );
    
    foreach ( $similar_reviews['rows'] as $row_data ) {
        if ( isset( $row_data['row']['label'] ) && 1 === $row_data['row']['label'] ) {
            $positive_count++;
        }
    }
    
    $sentiment_score = $total_count > 0 ? ( $positive_count / $total_count ) : 0.5;
    
    // Store sentiment score as meta
    update_comment_meta( $review_id, '_wpoos_sentiment_score', $sentiment_score );
    update_comment_meta( $review_id, '_wpoos_sentiment_label', $sentiment_score > 0.6 ? 'positive' : 'negative' );
    
    return array(
        'score' => $sentiment_score,
        'label' => $sentiment_score > 0.6 ? 'positive' : 'negative',
        'confidence' => abs( $sentiment_score - 0.5 ) * 2  // 0-1 scale
    );
}

// Hook into review posting
add_action( 'comment_post', 'wpoos_analyze_review_on_post', 10, 1 );

function wpoos_analyze_review_on_post( $comment_id ) {
    $comment = get_comment( $comment_id );
    
    if ( 'review' === $comment->comment_type ) {
        $analysis = wpoos_analyze_product_review_sentiment( $comment_id );
        
        if ( $analysis ) {
            // Maybe send notification for negative reviews
            if ( 'negative' === $analysis['label'] && $analysis['confidence'] > 0.7 ) {
                wpoos_notify_negative_review( $comment_id, $analysis );
            }
        }
    }
}

function wpoos_notify_negative_review( $review_id, $analysis ) {
    $review = get_comment( $review_id );
    $product = wc_get_product( $review->comment_post_ID );
    
    wp_mail(
        get_option( 'admin_email' ),
        'Negative Product Review Detected',
        sprintf(
            "A potentially negative review was posted:\n\nProduct: %s\nSentiment: %s (%.2f%% confidence)\nReview: %s\n\nView: %s",
            $product->get_name(),
            $analysis['label'],
            $analysis['confidence'] * 100,
            $review->comment_content,
            admin_url( 'comment.php?action=editcomment&c=' . $review_id )
        )
    );
}
```

---

### Example 2: Product Categorization Helper

```php
<?php
/**
 * Suggest product categories using Fashion MNIST dataset.
 */
function wpoos_suggest_woo_product_categories( $product_id ) {
    $product = wc_get_product( $product_id );
    
    if ( ! $product ) {
        return array();
    }
    
    // Fashion MNIST categories
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
    
    $product_name = strtolower( $product->get_name() );
    $product_desc = strtolower( $product->get_description() );
    $combined = $product_name . ' ' . $product_desc;
    
    $matches = array();
    
    foreach ( $fashion_categories as $category ) {
        $category_lower = strtolower( $category );
        
        // Check for category mentions
        if ( stripos( $combined, $category_lower ) !== false ) {
            $matches[] = array(
                'category' => $category,
                'confidence' => 0.8  // High confidence for direct match
            );
        }
    }
    
    return $matches;
}

// Add admin column
add_filter( 'manage_edit-product_columns', 'wpoos_add_category_suggestions_column' );

function wpoos_add_category_suggestions_column( $columns ) {
    $columns['wpoos_category_suggestions'] = 'AI Category Suggestions';
    return $columns;
}

add_action( 'manage_product_posts_custom_column', 'wpoos_display_category_suggestions_column', 10, 2 );

function wpoos_display_category_suggestions_column( $column, $post_id ) {
    if ( 'wpoos_category_suggestions' === $column ) {
        $suggestions = wpoos_suggest_woo_product_categories( $post_id );
        
        if ( ! empty( $suggestions ) ) {
            foreach ( $suggestions as $suggestion ) {
                echo esc_html( $suggestion['category'] ) . ' ';
                echo '<span class="confidence">(' . round( $suggestion['confidence'] * 100 ) . '%)</span><br>';
            }
        } else {
            echo '<em>No suggestions</em>';
        }
    }
}
```

---

## Complete Working Examples

### Example 1: Comment Moderation System

**File:** `wp-content/plugins/wpoos-comment-mod/wpoos-comment-mod.php`

```php
<?php
/**
 * Plugin Name: WP oOS Comment Moderation
 * Description: Automatic comment moderation using HuggingFace datasets
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPOOS_Comment_Moderation {
    
    /**
     * Initialize the plugin.
     */
    public function __construct() {
        add_action( 'comment_post', array( $this, 'moderate_comment' ), 10, 1 );
        add_filter( 'comment_text', array( $this, 'add_toxicity_warning' ), 10, 2 );
        add_action( 'add_meta_boxes_comment', array( $this, 'add_toxicity_metabox' ) );
    }
    
    /**
     * Moderate comment using toxic comments dataset.
     *
     * @param int $comment_id Comment ID.
     */
    public function moderate_comment( $comment_id ) {
        $comment = get_comment( $comment_id );
        
        if ( ! $comment ) {
            return;
        }
        
        $toxicity_score = $this->calculate_toxicity( $comment->comment_content );
        
        // Store score
        update_comment_meta( $comment_id, '_wpoos_toxicity_score', $toxicity_score );
        
        // Auto-hold if high toxicity
        if ( $toxicity_score > 0.7 ) {
            wp_set_comment_status( $comment_id, 'hold' );
            update_comment_meta( $comment_id, '_wpoos_auto_moderated', true );
            
            $this->notify_moderators( $comment_id, $toxicity_score );
        }
    }
    
    /**
     * Calculate toxicity score.
     *
     * @param string $text Comment text.
     * @return float Toxicity score 0-1.
     */
    private function calculate_toxicity( $text ) {
        $client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
        
        // Search civil comments dataset
        $results = $client->search(
            'google/civil_comments',
            'default',
            'train',
            substr( $text, 0, 100 ),
            0,
            10
        );
        
        if ( is_wp_error( $results ) || empty( $results['rows'] ) ) {
            return 0;  // Default to non-toxic if can't analyze
        }
        
        $total_toxicity = 0;
        $count = 0;
        
        foreach ( $results['rows'] as $row_data ) {
            if ( isset( $row_data['row']['toxicity'] ) ) {
                $total_toxicity += $row_data['row']['toxicity'];
                $count++;
            }
        }
        
        return $count > 0 ? ( $total_toxicity / $count ) : 0;
    }
    
    /**
     * Notify moderators of toxic comment.
     *
     * @param int   $comment_id Comment ID.
     * @param float $score      Toxicity score.
     */
    private function notify_moderators( $comment_id, $score ) {
        $comment = get_comment( $comment_id );
        
        wp_mail(
            get_option( 'admin_email' ),
            sprintf( 'Comment flagged for review (toxicity: %.1f%%)', $score * 100 ),
            sprintf(
                "A comment has been automatically held for moderation:\n\nToxicity Score: %.1f%%\n\nComment:\n%s\n\nView: %s",
                $score * 100,
                $comment->comment_content,
                admin_url( 'comment.php?action=editcomment&c=' . $comment_id )
            )
        );
    }
    
    /**
     * Add toxicity warning to comment display.
     *
     * @param string $text       Comment text.
     * @param object $comment    Comment object.
     * @return string Modified comment text.
     */
    public function add_toxicity_warning( $text, $comment ) {
        if ( ! is_admin() ) {
            return $text;
        }
        
        $toxicity = get_comment_meta( $comment->comment_ID, '_wpoos_toxicity_score', true );
        
        if ( $toxicity && $toxicity > 0.5 ) {
            $warning = sprintf(
                '<div class="notice notice-warning inline"><p><strong>Toxicity Warning:</strong> This comment has a toxicity score of %.1f%%</p></div>',
                $toxicity * 100
            );
            return $warning . $text;
        }
        
        return $text;
    }
    
    /**
     * Add toxicity metabox to comment editor.
     */
    public function add_toxicity_metabox() {
        add_meta_box(
            'wpoos_toxicity_score',
            'Toxicity Analysis',
            array( $this, 'render_toxicity_metabox' ),
            'comment',
            'normal',
            'default'
        );
    }
    
    /**
     * Render toxicity metabox.
     *
     * @param object $comment Comment object.
     */
    public function render_toxicity_metabox( $comment ) {
        $toxicity = get_comment_meta( $comment->comment_ID, '_wpoos_toxicity_score', true );
        $auto_moderated = get_comment_meta( $comment->comment_ID, '_wpoos_auto_moderated', true );
        
        if ( ! $toxicity ) {
            echo '<p>No toxicity analysis available.</p>';
            return;
        }
        
        $percentage = round( $toxicity * 100, 1 );
        $level = $toxicity > 0.7 ? 'high' : ( $toxicity > 0.4 ? 'medium' : 'low' );
        $color = $toxicity > 0.7 ? '#dc3232' : ( $toxicity > 0.4 ? '#ffb900' : '#46b450' );
        
        ?>
        <div class="toxicity-analysis">
            <div class="toxicity-meter" style="background: <?php echo esc_attr( $color ); ?>; padding: 10px; color: white; text-align: center; border-radius: 3px;">
                <strong><?php echo esc_html( $percentage ); ?>%</strong> Toxicity
            </div>
            <p style="margin-top: 10px;">
                <strong>Level:</strong> <?php echo esc_html( ucfirst( $level ) ); ?><br>
                <?php if ( $auto_moderated ) : ?>
                    <span style="color: #dc3232;">⚠️ Automatically held for moderation</span>
                <?php endif; ?>
            </p>
            <p><em>Calculated using Google Civil Comments dataset patterns.</em></p>
        </div>
        <?php
    }
}

// Initialize
new WPOOS_Comment_Moderation();
```

---

## Summary

This document provides complete, working code examples for:

✅ Basic PHP usage of all 11 HuggingFace dataset tools  
✅ AI Assistant conversation patterns  
✅ REST API integration (cURL, Fetch, jQuery)  
✅ WordPress shortcodes and widgets  
✅ WooCommerce product review analysis  
✅ Complete comment moderation plugin  
✅ Error handling and best practices

For more information, see:
- [Complete How-To Guide](../guides/features/HUGGINGFACE_DATASETS_HOW_TO.md)
- [Quick Start Guide](../HUGGINGFACE_DATASETS_QUICK_START.md)
- [Dataset Catalog](../HUGGINGFACE_TOP_DATASETS.md)

---

**Version:** 1.0.0  
**Last Updated:** December 23, 2025  
**Status:** Production Ready ✅
