# HuggingFace Dataset Viewer Integration - Implementation Plan

## Executive Summary

This document outlines a comprehensive plan to integrate HuggingFace's Dataset Viewer API into the WP oOS plugin, enabling AI assistants to discover, explore, and query over 100,000+ datasets hosted on HuggingFace Hub directly from WordPress without downloading them.

## Overview

**HuggingFace Dataset Viewer** is a REST API service that provides programmatic access to datasets hosted on the HuggingFace Hub. Unlike the Python-based `datasets` library which requires downloading full datasets, the Dataset Viewer API allows:

- **Instant Access**: Query datasets without downloading
- **Preview & Exploration**: Browse rows, splits, and configurations
- **Search & Filter**: Full-text search and SQL-like filtering
- **Statistics**: Get metadata, size info, and statistical summaries
- **Efficient Loading**: Access optimized Parquet files for large datasets

**Base URL**: `https://datasets-server.huggingface.co`

**Authentication**: Optional Bearer token for private/gated datasets

---

## Why This Integration Matters

### For AI Assistants
- Access training data examples for few-shot learning
- Query benchmark datasets for evaluation
- Explore domain-specific datasets (medical, legal, scientific)
- Find suitable datasets for fine-tuning recommendations

### For WordPress Users
- No Python or complex setup required
- Works directly via REST API
- Integrates seamlessly with existing WP oOS workflow
- Can be used in chat, tools, or custom automations

### For Developers
- Leverage 100,000+ public datasets
- Access private organization datasets
- Build data-driven AI applications
- Create dataset-aware assistants

---

## Architecture Design

### 1. Client Layer
**File**: `includes/class-wp-mcp-ai-huggingface-datasets-client.php`

```php
class WP_MCP_AI_Huggingface_Datasets_Client {
    // Configuration
    public function get_api_token()
    public function get_base_url()
    
    // Core Operations
    public function is_valid($dataset)
    public function get_splits($dataset)
    public function get_info($dataset)
    public function get_size($dataset)
    public function get_statistics($dataset, $config, $split)
    
    // Data Access
    public function preview_rows($dataset, $config, $split, $limit = 100)
    public function get_rows($dataset, $config, $split, $offset = 0, $length = 100)
    public function search($dataset, $config, $split, $query)
    public function filter($dataset, $config, $split, $where, $orderby = null, $offset = 0, $length = 100)
    
    // Advanced
    public function get_parquet($dataset)
    public function get_croissant($dataset)
    
    // Helpers
    protected function make_request($endpoint, $params)
    protected function handle_response($response)
    protected function cache_result($key, $data, $ttl)
}
```

**Key Features**:
- Centralized API communication
- Automatic error handling and normalization
- Response caching (WordPress transients)
- Rate limiting protection
- Support for private datasets (Bearer token)

---

### 2. Tool Layer

Each Dataset Viewer API endpoint maps to a WP oOS tool that AI assistants can use.

#### Tool: `huggingface_dataset_is_valid`
**Purpose**: Check if a dataset exists and is accessible

**API Endpoint**: `GET /is-valid?dataset={dataset}`

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_Is_Valid {
    public function get_slug() {
        return 'huggingface_dataset_is_valid';
    }
    
    public function get_definition() {
        return array(
            'name' => 'Validate HuggingFace Dataset',
            'description' => 'Check if a HuggingFace dataset exists and is accessible',
            'parameters' => array(
                'dataset' => array(
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Dataset name (e.g., squad, imdb, glue)',
                ),
            ),
        );
    }
    
    public function execute($arguments, $context) {
        $dataset = $arguments['dataset'];
        $client = WP_MCP_AI_Container::get('client.huggingface_datasets');
        return $client->is_valid($dataset);
    }
}
```

**Use Cases**:
- Validate dataset before querying
- Check access to private datasets
- Verify dataset names in user input

---

#### Tool: `huggingface_dataset_list_splits`
**Purpose**: Get available splits and configurations

**API Endpoint**: `GET /splits?dataset={dataset}`

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_List_Splits {
    public function get_slug() {
        return 'huggingface_dataset_list_splits';
    }
    
    public function get_definition() {
        return array(
            'name' => 'List Dataset Splits',
            'description' => 'Get available splits (train/test/validation) and configurations for a dataset',
            'parameters' => array(
                'dataset' => array(
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Dataset name',
                ),
            ),
        );
    }
}
```

**Response Format**:
```json
{
    "splits": [
        {
            "dataset": "squad",
            "config": "plain_text",
            "split": "train",
            "num_rows": 87599
        },
        {
            "dataset": "squad",
            "config": "plain_text",
            "split": "validation",
            "num_rows": 10570
        }
    ]
}
```

**Use Cases**:
- Discover available data splits
- Get row counts per split
- Find configuration variants (languages, subsets)

---

#### Tool: `huggingface_dataset_get_info`
**Purpose**: Get comprehensive dataset information

**API Endpoint**: `GET /info?dataset={dataset}`

**Response Includes**:
- Dataset description
- Features schema (column types)
- Total rows across splits
- Dataset card metadata
- Citation information

**Use Cases**:
- Understand dataset structure
- Get schema for column-based queries
- Display dataset card to users

---

#### Tool: `huggingface_dataset_preview_rows`
**Purpose**: Preview first N rows (up to 100)

**API Endpoint**: `GET /first-rows?dataset={dataset}&config={config}&split={split}`

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_Preview_Rows {
    public function get_definition() {
        return array(
            'name' => 'Preview Dataset Rows',
            'description' => 'Get first rows of a dataset split for quick inspection',
            'parameters' => array(
                'dataset' => array(
                    'type' => 'string',
                    'required' => true,
                ),
                'config' => array(
                    'type' => 'string',
                    'required' => false,
                    'default' => 'default',
                ),
                'split' => array(
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Split name (train, test, validation)',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => 10,
                    'maximum' => 100,
                ),
            ),
        );
    }
}
```

**Response Format**:
```json
{
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
    "rows": [
        {
            "row_idx": 0,
            "row": {
                "id": "5733be284776f41900661182",
                "title": "University_of_Notre_Dame",
                "context": "Architecturally, the school has...",
                "question": "To whom did the Virgin Mary...",
                "answers": {
                    "text": ["Saint Bernadette Soubirous"],
                    "answer_start": [515]
                }
            }
        }
    ]
}
```

**Use Cases**:
- Quick dataset inspection
- Show examples in chat
- Validate dataset format before processing
- Display preview tables in admin UI

---

#### Tool: `huggingface_dataset_get_rows`
**Purpose**: Paginated access to dataset rows

**API Endpoint**: `GET /rows?dataset={dataset}&config={config}&split={split}&offset={offset}&length={length}`

**Parameters**:
- `offset`: Starting row (0-based index)
- `length`: Number of rows (max 100 per request)

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_Get_Rows {
    public function get_definition() {
        return array(
            'parameters' => array(
                'dataset' => array('type' => 'string', 'required' => true),
                'config' => array('type' => 'string', 'default' => 'default'),
                'split' => array('type' => 'string', 'required' => true),
                'offset' => array('type' => 'integer', 'default' => 0),
                'length' => array('type' => 'integer', 'default' => 10, 'maximum' => 100),
            ),
        );
    }
}
```

**Use Cases**:
- Pagination through large datasets
- Implement "next page" functionality
- Process datasets in chunks
- Build dataset browsers

---

#### Tool: `huggingface_dataset_search`
**Purpose**: Full-text search within dataset

**API Endpoint**: `GET /search?dataset={dataset}&config={config}&split={split}&query={query}`

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_Search {
    public function get_definition() {
        return array(
            'name' => 'Search Dataset',
            'description' => 'Full-text search within a dataset split',
            'parameters' => array(
                'dataset' => array('type' => 'string', 'required' => true),
                'config' => array('type' => 'string', 'default' => 'default'),
                'split' => array('type' => 'string', 'required' => true),
                'query' => array(
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Search text to find in dataset rows',
                ),
            ),
        );
    }
}
```

**Use Cases**:
- Find examples containing specific keywords
- Locate relevant training data
- Search for entities, phrases, or patterns
- Build search interfaces

**Example Query**:
```
Dataset: squad
Query: "Notre Dame"
Returns: All rows where "Notre Dame" appears
```

---

#### Tool: `huggingface_dataset_filter`
**Purpose**: SQL-like filtering and ordering

**API Endpoint**: `GET /filter?dataset={dataset}&config={config}&split={split}&where={expression}&orderby={order}&offset={offset}&length={length}`

**Tool Definition**:
```php
class WP_MCP_AI_Tool_Huggingface_Dataset_Filter {
    public function get_definition() {
        return array(
            'name' => 'Filter Dataset',
            'description' => 'Filter dataset rows using SQL-like expressions',
            'parameters' => array(
                'dataset' => array('type' => 'string', 'required' => true),
                'config' => array('type' => 'string', 'default' => 'default'),
                'split' => array('type' => 'string', 'required' => true),
                'where' => array(
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Filter expression (e.g., "label = 1", "score > 0.5")',
                ),
                'orderby' => array(
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Column to sort by (e.g., "score DESC")',
                ),
                'offset' => array('type' => 'integer', 'default' => 0),
                'length' => array('type' => 'integer', 'default' => 10, 'maximum' => 100),
            ),
        );
    }
}
```

**Use Cases**:
- Filter by label/category
- Query numeric ranges
- Sort by specific columns
- Complex data queries

**Example Queries**:
```
where: "label = 'positive'"
where: "score > 0.8"
where: "length(text) > 100"
orderby: "score DESC"
```

---

#### Tool: `huggingface_dataset_get_statistics`
**Purpose**: Get dataset statistics

**API Endpoint**: `GET /statistics?dataset={dataset}&config={config}&split={split}`

**Response Includes**:
- Column types and distributions
- Numerical statistics (mean, std, min, max)
- Categorical frequencies
- Missing value counts

**Use Cases**:
- Understand dataset characteristics
- Detect data quality issues
- Guide data preprocessing decisions
- Generate dataset reports

---

#### Tool: `huggingface_dataset_get_parquet`
**Purpose**: Get Parquet file URLs for efficient loading

**API Endpoint**: `GET /parquet?dataset={dataset}`

**Response Format**:
```json
{
    "parquet_files": [
        {
            "dataset": "squad",
            "config": "plain_text",
            "split": "train",
            "url": "https://huggingface.co/datasets/squad/resolve/refs%2Fconvert%2Fparquet/plain_text/train/0000.parquet",
            "filename": "0000.parquet",
            "size": 30735944
        }
    ]
}
```

**Use Cases**:
- Download optimized dataset files
- Integrate with data pipelines
- Use with pandas, polars, DuckDB
- Efficient bulk data access

---

#### Tool: `huggingface_dataset_get_size`
**Purpose**: Get dataset size information

**API Endpoint**: `GET /size?dataset={dataset}`

**Response Format**:
```json
{
    "size": {
        "dataset": "squad",
        "config": "plain_text",
        "splits": [
            {
                "split": "train",
                "num_rows": 87599,
                "num_bytes": 79346360
            },
            {
                "split": "validation",
                "num_rows": 10570,
                "num_bytes": 10473816
            }
        ]
    }
}
```

**Use Cases**:
- Check dataset size before processing
- Plan resource allocation
- Display storage requirements
- Monitor dataset growth

---

### 3. Admin UI Layer

**File**: `includes/admin/sections/class-wp-mcp-ai-section-providers.php`

Add HuggingFace Dataset Viewer configuration section:

```php
// HuggingFace Dataset Viewer Settings
'huggingface_datasets_section' => array(
    'title' => __( 'HuggingFace Dataset Viewer', 'wp-mcp-ai' ),
    'fields' => array(
        'enable_huggingface_datasets' => array(
            'type' => 'checkbox',
            'label' => __( 'Enable Dataset Viewer Tools', 'wp-mcp-ai' ),
            'description' => __( 'Enable tools for querying HuggingFace datasets', 'wp-mcp-ai' ),
            'default' => true,
        ),
        'huggingface_datasets_api_token' => array(
            'type' => 'password',
            'label' => __( 'HuggingFace API Token (Optional)', 'wp-mcp-ai' ),
            'description' => __( 'Required only for private/gated datasets. Get from https://huggingface.co/settings/tokens', 'wp-mcp-ai' ),
            'placeholder' => 'hf_...',
        ),
        'huggingface_datasets_cache_ttl' => array(
            'type' => 'number',
            'label' => __( 'Cache TTL (seconds)', 'wp-mcp-ai' ),
            'description' => __( 'How long to cache dataset API responses', 'wp-mcp-ai' ),
            'default' => 3600,
            'min' => 60,
            'max' => 86400,
        ),
        'huggingface_datasets_default_limit' => array(
            'type' => 'number',
            'label' => __( 'Default Row Limit', 'wp-mcp-ai' ),
            'description' => __( 'Default number of rows to return (max 100)', 'wp-mcp-ai' ),
            'default' => 10,
            'min' => 1,
            'max' => 100,
        ),
    ),
),
```

**UI Features**:
- Enable/disable dataset tools globally
- Optional API token for private datasets
- Configurable caching (performance tuning)
- Default row limits (token management)
- Connection test button
- Example dataset queries

---

### 4. Container Registration

**File**: `includes/class-wp-mcp-ai-container.php`

```php
// Register HuggingFace Datasets client
$this->singleton(
    'client.huggingface_datasets',
    function () {
        return new WP_MCP_AI_Huggingface_Datasets_Client();
    }
);
```

---

### 5. Tool Registration

**File**: `includes/tools/tools-init.php`

Tools automatically register via the tool registry system. No manual registration needed if tools follow naming convention:
- `class-wp-mcp-ai-tool-huggingface-dataset-*.php`

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1)
**Priority**: Critical foundation

**Tasks**:
1. Create `WP_MCP_AI_Huggingface_Datasets_Client` class
   - Base API connection
   - Authentication (Bearer token)
   - Error handling
   - Response normalization
   - Caching layer (WordPress transients)

2. Add admin settings section
   - Enable/disable toggle
   - API token field
   - Cache configuration
   - Connection test

3. Register client in dependency container

**Deliverables**:
- Working client class with all API methods
- Admin UI for configuration
- Connection test functionality
- PHPUnit tests for client

---

### Phase 2: Dataset Discovery Tools (Week 1-2)
**Priority**: High - Essential for dataset exploration

**Tools to Implement**:
1. `huggingface_dataset_is_valid`
2. `huggingface_dataset_list_splits`
3. `huggingface_dataset_get_info`
4. `huggingface_dataset_get_size`

**Testing**:
- Test with public datasets (squad, imdb, glue)
- Test with non-existent datasets (error handling)
- Test response caching
- Test capability checks

**Deliverables**:
- 4 working tools
- PHPUnit tests for each tool
- Integration tests with real API

---

### Phase 3: Data Access Tools (Week 2)
**Priority**: High - Core functionality

**Tools to Implement**:
1. `huggingface_dataset_preview_rows`
2. `huggingface_dataset_get_rows`

**Features**:
- Pagination support
- Configurable limits (max 100)
- Row formatting for chat display
- Token usage tracking (LLM payloads)

**Testing**:
- Test with various datasets
- Test pagination edge cases
- Test large row responses
- Performance testing

**Deliverables**:
- 2 working tools
- Comprehensive tests
- Performance benchmarks

---

### Phase 4: Search & Filter Tools (Week 2-3)
**Priority**: Medium - Advanced features

**Tools to Implement**:
1. `huggingface_dataset_search`
2. `huggingface_dataset_filter`

**Features**:
- Full-text search
- SQL-like filtering expressions
- Ordering and sorting
- Result limiting

**Testing**:
- Test various search queries
- Test filter expressions
- Test complex queries
- Error handling for invalid queries

**Deliverables**:
- 2 working tools
- Query examples documentation
- Comprehensive tests

---

### Phase 5: Advanced Features (Week 3)
**Priority**: Low - Nice to have

**Tools to Implement**:
1. `huggingface_dataset_get_statistics`
2. `huggingface_dataset_get_parquet`
3. `huggingface_dataset_get_croissant` (optional)

**Features**:
- Statistical summaries
- Parquet file URLs
- Dataset metadata

**Testing**:
- Test statistics calculations
- Test Parquet URL retrieval
- Test metadata parsing

**Deliverables**:
- 2-3 working tools
- Advanced usage examples
- Tests for all features

---

### Phase 6: Documentation (Week 3-4)
**Priority**: High - Critical for adoption

**Documents to Create**:
1. `docs/HUGGINGFACE_DATASETS_SETUP.md`
   - Getting started guide
   - Configuration walkthrough
   - Example queries
   - Troubleshooting

2. `docs/HUGGINGFACE_DATASETS_TOOL_REFERENCE.md`
   - Complete tool catalog
   - Parameter documentation
   - Response formats
   - Use case examples

3. `docs/HUGGINGFACE_DATASETS_USE_CASES.md`
   - Real-world scenarios
   - Assistant configuration examples
   - Integration patterns
   - Best practices

4. Update existing docs:
   - `docs/DOCUMENTATION_INDEX.md`
   - `docs/tool-reference.md`
   - `README.md`

**Deliverables**:
- 3 comprehensive guides
- Updated documentation index
- Code examples for all tools
- Video tutorials (optional)

---

### Phase 7: Testing & Quality Assurance (Week 4)
**Priority**: Critical

**Testing Tasks**:
1. Unit tests for all tools (target: 100% coverage)
2. Integration tests with live API
3. Performance testing (caching, rate limits)
4. Security testing (input sanitization, capability checks)
5. WordPress coding standards compliance
6. Cross-browser testing (admin UI)
7. Manual QA scenarios

**Quality Checks**:
- [ ] All tools have PHPDoc blocks
- [ ] Input sanitization on all parameters
- [ ] Output escaping for all responses
- [ ] Capability checks enforced
- [ ] Error messages are actionable
- [ ] Logging for debugging
- [ ] Cache invalidation works correctly
- [ ] Rate limiting prevents abuse

**Deliverables**:
- Comprehensive test suite
- Test coverage report
- QA checklist completed
- Security audit passed

---

## Technical Specifications

### API Client Implementation

**Caching Strategy**:
```php
protected function cache_result($key, $data, $ttl = null) {
    if (null === $ttl) {
        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $ttl = isset($settings['huggingface_datasets_cache_ttl']) 
            ? (int) $settings['huggingface_datasets_cache_ttl'] 
            : 3600;
    }
    
    set_transient('wp_mcp_ai_hf_datasets_' . md5($key), $data, $ttl);
}

protected function get_cached_result($key) {
    return get_transient('wp_mcp_ai_hf_datasets_' . md5($key));
}
```

**Error Handling**:
```php
protected function handle_response($response) {
    if (is_wp_error($response)) {
        return new WP_Error(
            'wp_mcp_ai_hf_datasets_request_failed',
            __('Failed to connect to HuggingFace Dataset Viewer API', 'wp-mcp-ai'),
            array('error' => $response->get_error_message())
        );
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($status_code !== 200) {
        return new WP_Error(
            'wp_mcp_ai_hf_datasets_api_error',
            sprintf(__('HuggingFace API returned error: %d', 'wp-mcp-ai'), $status_code),
            array('status' => $status_code, 'body' => $body)
        );
    }
    
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error(
            'wp_mcp_ai_hf_datasets_invalid_json',
            __('Invalid JSON response from HuggingFace API', 'wp-mcp-ai'),
            array('body' => $body)
        );
    }
    
    return $data;
}
```

**Rate Limiting**:
```php
protected function check_rate_limit() {
    $user_id = get_current_user_id();
    $key = 'wp_mcp_ai_hf_datasets_rate_limit_' . $user_id;
    $count = get_transient($key);
    
    if (false === $count) {
        set_transient($key, 1, MINUTE_IN_SECONDS);
        return true;
    }
    
    $max_requests = apply_filters('wp_mcp_ai_hf_datasets_rate_limit', 60);
    
    if ($count >= $max_requests) {
        return new WP_Error(
            'wp_mcp_ai_hf_datasets_rate_limited',
            __('Rate limit exceeded. Please try again later.', 'wp-mcp-ai'),
            array('retry_after' => 60)
        );
    }
    
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
}
```

---

### Security Considerations

**Input Sanitization**:
```php
// Dataset names
$dataset = sanitize_text_field($arguments['dataset']);

// Config names
$config = sanitize_text_field($arguments['config']);

// Split names
$split = sanitize_text_field($arguments['split']);

// Numeric parameters
$offset = absint($arguments['offset']);
$length = min(100, absint($arguments['length']));

// Search queries
$query = sanitize_textarea_field($arguments['query']);

// Filter expressions (validate format)
$where = $this->validate_filter_expression($arguments['where']);
```

**Capability Checks**:
```php
public function get_required_capability() {
    return apply_filters(
        'wp_mcp_ai_hf_datasets_required_capability',
        'read' // Default: any logged-in user
    );
}
```

**Output Escaping**:
```php
// For chat display
return array(
    'dataset' => esc_html($dataset),
    'rows' => array_map(function($row) {
        return array_map('esc_html', $row);
    }, $rows),
);
```

---

### Performance Optimization

**Strategies**:
1. **Aggressive Caching**: Cache all API responses (1 hour default)
2. **Lazy Loading**: Only fetch data when requested
3. **Pagination**: Limit rows per request (max 100)
4. **Response Compression**: Use gzip for large responses
5. **CDN Support**: Parquet files served via HuggingFace CDN

**Benchmarks** (Target):
- Dataset validation: < 500ms
- List splits: < 1s
- Preview rows: < 2s
- Search query: < 3s
- Statistics: < 5s

---

## Use Case Examples

### Use Case 1: Find Training Examples
**Scenario**: Assistant needs examples for few-shot learning

**Conversation**:
```
User: Show me examples of sentiment analysis tasks