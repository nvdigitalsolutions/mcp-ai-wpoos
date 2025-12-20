# New Tools Implementation Plan for OpenAI API Integration

## Overview
This document outlines the implementation plan for new tools that leverage the newly integrated OpenAI API functionality. These tools will be available to AI assistants to enhance their capabilities.

---

## 1. Files Management Tools

### 1.1 List OpenAI Files Tool
**Tool Slug**: `list_openai_files`

**Description**: Lists files uploaded to OpenAI for the current user or organization.

**Use Cases**:
- Audit uploaded files
- Find files by purpose (assistants, fine-tune, etc.)
- Clean up old/unused files
- Check file quotas

**Parameters**:
```php
array(
    'purpose'  => 'Optional: Filter by purpose (assistants, fine-tune, etc.)',
    'limit'    => 'Optional: Maximum number of files to return (1-100)',
    'order'    => 'Optional: Sort order (asc or desc)',
    'after'    => 'Optional: Cursor for pagination',
)
```

**Required Capability**: `manage_options` or file ownership check

**Output Example**:
```json
{
    "files": [
        {
            "id": "file-abc123",
            "filename": "knowledge_base.pdf",
            "purpose": "assistants",
            "bytes": 204800,
            "created_at": "2024-01-15T10:30:00Z"
        }
    ],
    "total_count": 5,
    "has_more": false
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-list-openai-files.php`

---

### 1.2 Get OpenAI File Details Tool
**Tool Slug**: `get_openai_file_details`

**Description**: Retrieves detailed metadata about a specific OpenAI file.

**Use Cases**:
- Verify file upload success
- Check file processing status
- Get file size and format info
- Debugging file-related issues

**Parameters**:
```php
array(
    'file_id' => 'Required: OpenAI file identifier (e.g., file-abc123)',
)
```

**Required Capability**: `read` or file ownership check

**Output Example**:
```json
{
    "id": "file-abc123",
    "object": "file",
    "bytes": 204800,
    "created_at": "2024-01-15T10:30:00Z",
    "filename": "knowledge_base.pdf",
    "purpose": "assistants",
    "status": "processed",
    "status_details": null
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-get-openai-file-details.php`

---

## 2. Models Discovery Tools

### 2.1 List Available Models Tool
**Tool Slug**: `list_available_models`

**Description**: Lists all OpenAI models available to the configured API key.

**Use Cases**:
- Discover new models
- Check model availability
- Compare model capabilities
- Dynamic model selection based on task requirements

**Parameters**:
```php
array(
    'filter_by_capability' => 'Optional: Filter by capability (chat, embeddings, images, audio)',
    'include_deprecated'   => 'Optional: Include deprecated models (default: false)',
)
```

**Required Capability**: `read`

**Output Example**:
```json
{
    "models": [
        {
            "id": "gpt-4o",
            "created": 1715367049,
            "owned_by": "openai",
            "capabilities": ["chat", "function_calling", "vision"]
        },
        {
            "id": "gpt-4o-mini",
            "created": 1717527127,
            "owned_by": "openai",
            "capabilities": ["chat", "function_calling", "vision"]
        }
    ],
    "total_count": 42
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-list-available-models.php`

---

### 2.2 Get Model Information Tool
**Tool Slug**: `get_model_information`

**Description**: Retrieves detailed information about a specific OpenAI model.

**Use Cases**:
- Check model specifications
- Verify model exists before use
- Get model context length
- Understand model capabilities

**Parameters**:
```php
array(
    'model_id' => 'Required: Model identifier (e.g., gpt-4o)',
)
```

**Required Capability**: `read`

**Output Example**:
```json
{
    "id": "gpt-4o",
    "object": "model",
    "created": 1715367049,
    "owned_by": "openai",
    "permission": [],
    "root": "gpt-4o",
    "parent": null
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-get-model-information.php`

---

## 3. Embeddings & Semantic Search Tools

### 3.1 Create Text Embeddings Tool
**Tool Slug**: `create_text_embeddings`

**Description**: Generates vector embeddings for text using OpenAI's embedding models.

**Use Cases**:
- Semantic search preparation
- Content similarity comparison
- Text classification
- Recommendation systems
- Vector database population

**Parameters**:
```php
array(
    'input'            => 'Required: Text or array of texts to embed',
    'model'            => 'Optional: Embedding model (default: text-embedding-3-small)',
    'encoding_format'  => 'Optional: Encoding format (float or base64)',
    'dimensions'       => 'Optional: Number of dimensions (for text-embedding-3-*)',
    'save_to_metadata' => 'Optional: Save embeddings to post meta (default: false)',
    'post_id'          => 'Optional: Post ID to attach embeddings to',
)
```

**Required Capability**: `edit_posts`

**Output Example**:
```json
{
    "embeddings": [
        {
            "object": "embedding",
            "embedding": [0.123, -0.456, ...], // 1536 or 3072 dimensions
            "index": 0
        }
    ],
    "model": "text-embedding-3-small",
    "usage": {
        "prompt_tokens": 8,
        "total_tokens": 8
    }
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-create-text-embeddings.php`

---

### 3.2 Semantic Content Search Tool
**Tool Slug**: `semantic_content_search`

**Description**: Performs semantic search across WordPress content using embeddings.

**Use Cases**:
- Find similar posts/pages
- Content recommendation
- Question answering from knowledge base
- Duplicate content detection

**Parameters**:
```php
array(
    'query'         => 'Required: Search query text',
    'post_types'    => 'Optional: Array of post types to search (default: post, page)',
    'limit'         => 'Optional: Maximum results (default: 10)',
    'threshold'     => 'Optional: Similarity threshold 0-1 (default: 0.7)',
    'include_meta'  => 'Optional: Include post metadata (default: false)',
)
```

**Required Capability**: `read`

**Output Example**:
```json
{
    "results": [
        {
            "post_id": 123,
            "title": "How to Install WordPress",
            "excerpt": "...",
            "similarity_score": 0.92,
            "permalink": "https://example.com/how-to-install"
        }
    ],
    "query_embedding_model": "text-embedding-3-small"
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php`

---

## 4. Vector Stores Tools (When API Added)

### 4.1 Create Vector Store Tool
**Tool Slug**: `create_vector_store`

**Description**: Creates a new OpenAI vector store for knowledge retrieval.

**Use Cases**:
- Set up knowledge bases
- Organize assistant memory
- Create domain-specific search indexes

**Parameters**:
```php
array(
    'name'               => 'Required: Vector store name',
    'file_ids'           => 'Optional: Array of file IDs to add',
    'expires_after_days' => 'Optional: Auto-expire after N days',
    'metadata'           => 'Optional: Custom metadata',
)
```

**Required Capability**: `manage_options`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-create-vector-store.php`

---

### 4.2 Manage Vector Store Files Tool
**Tool Slug**: `manage_vector_store_files`

**Description**: Add, remove, or list files in a vector store.

**Use Cases**:
- Update knowledge base
- Remove outdated information
- Audit vector store contents

**Parameters**:
```php
array(
    'vector_store_id' => 'Required: Vector store ID',
    'action'          => 'Required: add, remove, list',
    'file_ids'        => 'Required for add/remove: Array of file IDs',
)
```

**Required Capability**: `manage_options`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-manage-vector-store-files.php`

---

### 4.3 Query Vector Store Tool
**Tool Slug**: `query_vector_store`

**Description**: Searches a vector store for relevant information.

**Use Cases**:
- Retrieve context for RAG
- Answer questions from knowledge base
- Find relevant documentation

**Parameters**:
```php
array(
    'vector_store_id' => 'Required: Vector store ID',
    'query'           => 'Required: Search query',
    'limit'           => 'Optional: Max results (default: 5)',
    'score_threshold' => 'Optional: Minimum relevance score',
)
```

**Required Capability**: `read`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-query-vector-store.php`

---

## 5. Advanced Image Tools

### 5.1 Edit Image Tool
**Tool Slug**: `edit_openai_image`

**Description**: Edits an existing image using OpenAI's image editing capabilities.

**Use Cases**:
- Remove objects from images
- Inpainting/outpainting
- Style transfer
- Image correction

**Parameters**:
```php
array(
    'image_file_id'  => 'Required: OpenAI file ID or attachment ID',
    'mask_file_id'   => 'Optional: Mask file ID',
    'prompt'         => 'Required: Description of desired edits',
    'model'          => 'Optional: Model to use (default: dall-e-2)',
    'n'              => 'Optional: Number of variations (1-10)',
    'size'           => 'Optional: 256x256, 512x512, 1024x1024',
    'response_format' => 'Optional: url or b64_json',
)
```

**Required Capability**: `upload_files`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php`

---

### 5.2 Create Image Variation Tool
**Tool Slug**: `create_image_variation`

**Description**: Generates variations of an existing image.

**Use Cases**:
- Create alternative versions
- A/B testing visuals
- Expand creative options
- Generate similar images

**Parameters**:
```php
array(
    'image_file_id'  => 'Required: Source image file ID',
    'n'              => 'Optional: Number of variations (1-10, default: 1)',
    'size'           => 'Optional: 256x256, 512x512, 1024x1024',
    'response_format' => 'Optional: url or b64_json',
    'model'          => 'Optional: Model to use (default: dall-e-2)',
)
```

**Required Capability**: `upload_files`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-create-image-variation.php`

---

## 6. Large File Upload Tool

### 6.1 Upload Large File Tool
**Tool Slug**: `upload_large_file_multipart`

**Description**: Uploads large files (>512MB) using multipart upload.

**Use Cases**:
- Upload large datasets
- Video file uploads
- Large document processing
- Batch file operations

**Parameters**:
```php
array(
    'file_path'   => 'Required: Local file path',
    'purpose'     => 'Required: assistants, fine-tune, etc.',
    'chunk_size'  => 'Optional: Chunk size in MB (default: 50)',
    'mime_type'   => 'Optional: MIME type',
)
```

**Required Capability**: `upload_files`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-upload-large-file.php`

---

## 7. Assistant Enhancement Tools

### 7.1 Suggest Best Model Tool
**Tool Slug**: `suggest_best_model`

**Description**: Recommends the best OpenAI model for a given task.

**Use Cases**:
- Dynamic model selection
- Cost optimization
- Performance optimization
- Task-appropriate model matching

**Parameters**:
```php
array(
    'task_type'          => 'Required: chat, image, audio, embeddings, etc.',
    'requirements'       => 'Optional: Array of requirements (speed, quality, cost)',
    'context_length'     => 'Optional: Required context length',
    'multimodal'         => 'Optional: Requires vision/audio (boolean)',
    'budget_preference'  => 'Optional: low, medium, high',
)
```

**Required Capability**: `read`

**Output Example**:
```json
{
    "recommended_model": "gpt-4o-mini",
    "reasoning": "Best balance of speed and cost for chat tasks with moderate context",
    "alternatives": ["gpt-4o", "gpt-3.5-turbo"],
    "estimated_cost_per_1k_tokens": 0.00015
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-suggest-best-model.php`

---

### 7.2 Batch Embed Content Tool
**Tool Slug**: `batch_embed_content`

**Description**: Generates embeddings for multiple posts/pages in batch.

**Use Cases**:
- Prepare semantic search
- Index content library
- Build recommendation systems
- Initialize vector databases

**Parameters**:
```php
array(
    'post_ids'        => 'Optional: Specific post IDs',
    'post_types'      => 'Optional: Post types to process',
    'limit'           => 'Optional: Max posts per batch (default: 50)',
    'model'           => 'Optional: Embedding model',
    'store_in_meta'   => 'Optional: Store in post meta (default: true)',
    'update_existing' => 'Optional: Re-embed already embedded posts (default: false)',
)
```

**Required Capability**: `edit_posts`

**Output Example**:
```json
{
    "processed": 50,
    "embedded": 45,
    "skipped": 5,
    "errors": 0,
    "total_tokens_used": 12500,
    "estimated_cost": 0.00125
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-batch-embed-content.php`

---

## 8. Knowledge Base Management Tools

### 8.1 Sync WordPress to Vector Store Tool
**Tool Slug**: `sync_wp_to_vector_store`

**Description**: Synchronizes WordPress content to an OpenAI vector store.

**Use Cases**:
- Keep knowledge base updated
- Automated content indexing
- Assistant memory updates

**Parameters**:
```php
array(
    'vector_store_id' => 'Required: Target vector store ID',
    'post_types'      => 'Optional: Post types to sync',
    'taxonomies'      => 'Optional: Include taxonomy terms',
    'since_date'      => 'Optional: Only sync posts modified after date',
    'delete_removed'  => 'Optional: Remove files for deleted posts (default: false)',
)
```

**Required Capability**: `manage_options`

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-sync-wp-to-vector-store.php`

---

### 8.2 Analyze File Suitability Tool
**Tool Slug**: `analyze_file_suitability`

**Description**: Analyzes if a file is suitable for OpenAI processing.

**Use Cases**:
- Pre-upload validation
- File format checking
- Size limit verification
- Quality assessment

**Parameters**:
```php
array(
    'file_id'       => 'Required: WordPress attachment ID',
    'purpose'       => 'Required: Intended purpose (assistants, embeddings, etc.)',
    'check_content' => 'Optional: Perform content analysis (default: true)',
)
```

**Required Capability**: `upload_files`

**Output Example**:
```json
{
    "suitable": true,
    "file_size": 204800,
    "file_type": "application/pdf",
    "warnings": [],
    "recommendations": [
        "File is optimal for assistants purpose",
        "Consider compressing images within PDF for faster processing"
    ]
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-analyze-file-suitability.php`

---

## 9. Monitoring & Analytics Tools

### 9.1 OpenAI Usage Analytics Tool
**Tool Slug**: `openai_usage_analytics`

**Description**: Provides analytics on OpenAI API usage.

**Use Cases**:
- Track costs
- Monitor API quotas
- Optimize model selection
- Usage reporting

**Parameters**:
```php
array(
    'period'       => 'Optional: today, week, month, custom',
    'start_date'   => 'Optional: Start date for custom period',
    'end_date'     => 'Optional: End date for custom period',
    'group_by'     => 'Optional: model, user, tool, date',
    'include_cost' => 'Optional: Calculate costs (default: true)',
)
```

**Required Capability**: `manage_options`

**Output Example**:
```json
{
    "period": "month",
    "total_requests": 1250,
    "total_tokens": 425000,
    "estimated_cost": 42.50,
    "by_model": {
        "gpt-4o": {"requests": 500, "tokens": 200000, "cost": 30.00},
        "gpt-4o-mini": {"requests": 750, "tokens": 225000, "cost": 12.50}
    },
    "top_tools": ["create_text_embeddings", "semantic_content_search"]
}
```

**Tool Location**: `includes/tools/class-wp-mcp-ai-tool-openai-usage-analytics.php`

---

## Implementation Priority

### Phase 1: Core Tools (Week 1) - ✅ COMPLETED
1. ✅ List OpenAI Files - `list_openai_files`
2. ✅ Get OpenAI File Details - `get_openai_file_details`
3. ✅ List Available Models - `list_available_models`
4. ✅ Get Model Information - `get_model_information`
5. ✅ Create Text Embeddings - `create_text_embeddings`

### Phase 2: Search & Discovery (Week 2) - ✅ COMPLETED
6. ✅ Semantic Content Search - `semantic_content_search`
7. ✅ Suggest Best Model - `suggest_best_model`
8. ✅ Batch Embed Content - `batch_embed_content`

### Phase 3: Vector Stores (Week 3) - After API Implementation
9. Create Vector Store
10. Manage Vector Store Files
11. Query Vector Store
12. Sync WordPress to Vector Store

### Phase 4: Advanced Features (Week 4)
13. Edit Image Tool
14. Create Image Variation
15. Upload Large File
16. Analyze File Suitability
17. OpenAI Usage Analytics

---

## Technical Implementation Guidelines

### Tool Base Class Structure
```php
class WP_MCP_AI_Tool_Example extends WP_MCP_AI_Tool_Base {
    public function get_slug() {
        return 'example_tool';
    }
    
    public function get_name() {
        return __( 'Example Tool', 'wp-mcp-ai' );
    }
    
    public function get_description() {
        return __( 'Description for AI to understand when to use this tool.', 'wp-mcp-ai' );
    }
    
    public function get_parameters_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'param1' => array(
                    'type'        => 'string',
                    'description' => 'Parameter description',
                ),
            ),
            'required'   => array( 'param1' ),
        );
    }
    
    public function execute( $arguments, $context ) {
        // Implementation
    }
    
    public function get_required_capability() {
        return 'edit_posts';
    }
}
```

### Error Handling Pattern
```php
// Validate inputs
if ( empty( $file_id ) ) {
    return array(
        'success' => false,
        'error'   => __( 'File ID is required.', 'wp-mcp-ai' ),
        'code'    => 'missing_file_id',
    );
}

// Call OpenAI API
$client = new WP_MCP_AI_OpenAI_Client();
$result = $client->list_files( $args );

// Handle errors
if ( is_wp_error( $result ) ) {
    return array(
        'success' => false,
        'error'   => $result->get_error_message(),
        'code'    => $result->get_error_code(),
    );
}

// Return success
return array(
    'success' => true,
    'data'    => $result,
);
```

### Logging Pattern
```php
WP_MCP_AI_Logger::log_event(
    'tool_execution',
    'Executing ' . $this->get_slug(),
    array(
        'arguments' => $arguments,
        'user_id'   => get_current_user_id(),
    )
);
```

---

## Testing Strategy

### Unit Tests
- Test each tool's parameter validation
- Test error handling
- Test capability checks
- Mock OpenAI API responses

### Integration Tests
- Test tool execution flow
- Test with real OpenAI API (sandbox)
- Test tool chaining scenarios

### Performance Tests
- Measure tool execution time
- Test with large datasets
- Monitor memory usage

---

## Documentation Requirements

For each tool, provide:
1. **User Documentation**: How to use the tool in plain language
2. **AI Documentation**: When the AI should use the tool
3. **Code Examples**: Sample usage scenarios
4. **Troubleshooting**: Common issues and solutions

---

## Security Considerations

1. **Capability Checks**: All tools must check user capabilities
2. **Input Sanitization**: Sanitize all user inputs
3. **Output Escaping**: Escape all outputs
4. **Rate Limiting**: Implement rate limits for expensive operations
5. **API Key Protection**: Never log or expose API keys
6. **File Access Control**: Verify user has access to files
7. **Cost Controls**: Implement budget limits for expensive operations

---

## Backward Compatibility

- All new tools are additions (no breaking changes)
- Existing tools continue to work
- Optional feature flags for beta features
- Graceful degradation if API unavailable

---

## Success Metrics

- Tool adoption rate by assistants
- Tool execution success rate
- User satisfaction scores
- API cost efficiency
- Performance benchmarks

---

## Maintenance Plan

- Monthly API compatibility checks
- Quarterly performance reviews
- Regular security audits
- Documentation updates with API changes
- Community feedback integration
