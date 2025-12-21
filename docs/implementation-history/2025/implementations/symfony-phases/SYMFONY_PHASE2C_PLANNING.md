# Symfony Phase 2C: AI Embeddings Integration - Planning Document

**Date:** December 10, 2025  
**Status:** PLANNING PHASE  
**Purpose:** Plan Symfony AI Embeddings integration for semantic search capabilities

---

## Executive Summary

Phase 2C aims to add semantic search capabilities to WP oOS using the Symfony AI component. This will enable:
- **Semantic Content Discovery:** Find content by meaning, not just keywords
- **Intelligent Recommendations:** Suggest similar posts, products, or documents
- **Knowledge Base Search:** Query documentation and knowledge bases naturally
- **Content Similarity:** Find related content automatically

**Target:** Pro addon feature for Q1 2026  
**Estimated Effort:** 120-160 hours (2-3 weeks)  
**Priority:** High

---

## Prerequisites - COMPLETED ✅

### Phase 2A: Symfony Validator ✅
- 9 validation classes created
- 9 validated tools implemented
- Type-safe argument validation
- ~600 lines of validation code eliminated

### Phase 2B: Symfony Process ✅
- Process Service created and tested
- All 14 exec() calls migrated
- 2 services migrated (Video, Jukebox)
- 6 Pro tools verified

**Status:** Infrastructure ready for Phase 2C

---

## Research Phase (Week 1, Days 1-2)

### 1. Symfony AI Component Analysis
**Tasks:**
- [ ] Review Symfony AI documentation
- [ ] Evaluate supported embedding providers
- [ ] Compare Symfony AI vs direct API integration
- [ ] Assess WordPress compatibility

**Deliverable:** Component selection decision document

### 2. Embedding Provider Evaluation
**Providers to Research:**
- [ ] **OpenAI Embeddings** (text-embedding-ada-002, text-embedding-3-small, text-embedding-3-large)
  - Cost per 1M tokens
  - Embedding dimensions
  - Performance benchmarks
- [ ] **Voyage AI** (voyage-large-2, voyage-code-2)
  - Pricing comparison
  - Specialized models
- [ ] **Cohere** (embed-english-v3.0)
  - Cost analysis
  - Multilingual support
- [ ] **Google VertexAI** (textembedding-gecko)
  - Integration requirements
  
**Deliverable:** Provider comparison matrix with cost estimates

### 3. Storage Architecture Design
**Options to Evaluate:**
- [ ] **Custom MySQL Table**
  - Pros: Native WordPress, no dependencies
  - Cons: Limited vector search capabilities
  - Research: MySQL 8.0 JSON functions for similarity
  
- [ ] **JetEngine CCT**
  - Pros: Admin UI, existing integration
  - Cons: Not optimized for vectors
  - Research: Performance with meta fields
  
- [ ] **ChromaDB Integration** (Future)
  - Pros: Purpose-built for vectors
  - Cons: External dependency, hosting
  
- [ ] **PostgreSQL with pgvector** (Future)
  - Pros: Excellent vector support
  - Cons: Requires PostgreSQL instead of MySQL

**Deliverable:** Storage architecture recommendation

---

## Implementation Phase (Weeks 1-3)

### Week 1: Infrastructure Setup

#### Day 1-2: Research (see above)

#### Day 3: Composer Dependencies
**Tasks:**
- [ ] Research Symfony AI Embeddings package availability
- [ ] Check if symfony/ai exists or if direct API integration needed
- [ ] Add required dependencies to composer.json
- [ ] Run `composer install`
- [ ] Test autoloading

**Files to Update:**
- `composer.json`
- `composer.lock`

#### Day 4: Embeddings Manager Service
**Tasks:**
- [ ] Create `includes/services/class-wp-mcp-ai-embeddings-manager.php`
- [ ] Implement provider abstraction layer
- [ ] Add generate_embedding() method
- [ ] Add batch_generate_embeddings() method
- [ ] Configure API credentials from settings
- [ ] Implement rate limiting
- [ ] Add error handling and logging

**Class Structure:**
```php
class WP_MCP_AI_Embeddings_Manager {
    private $provider;  // OpenAI, Voyage, etc.
    private $model;     // Model name
    
    public function generate_embedding( $text );
    public function batch_generate_embeddings( $texts );
    public function get_provider_info();
    public function estimate_cost( $text_count );
}
```

#### Day 5: Initial Testing
**Tasks:**
- [ ] Create `tests/test-embeddings-manager.php`
- [ ] Test single embedding generation
- [ ] Test batch processing
- [ ] Test error handling
- [ ] Test rate limiting
- [ ] Mock API calls for unit tests

---

### Week 2: Storage and Search

#### Day 6-7: Embeddings Storage Repository
**Tasks:**
- [ ] Create `includes/repositories/class-wp-mcp-ai-embeddings-storage.php`
- [ ] Design database schema
- [ ] Implement CRUD operations
- [ ] Add similarity search algorithm
- [ ] Create database migration
- [ ] Add indexing for performance

**Database Schema (if custom table):**
```sql
CREATE TABLE {prefix}_mcp_ai_embeddings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(50) NOT NULL,
    embedding_vector TEXT NOT NULL, -- JSON array
    embedding_model VARCHAR(100) NOT NULL,
    embedding_dimensions INT NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_content (content_type, content_id),
    INDEX idx_hash (content_hash)
);
```

**Methods:**
```php
class WP_MCP_AI_Embeddings_Storage {
    public function store_embedding( $content_id, $content_type, $vector, $model );
    public function get_embedding( $content_id, $content_type );
    public function similarity_search( $query_vector, $limit, $filters );
    public function delete_embedding( $content_id, $content_type );
    public function reindex_all();
}
```

#### Day 8: Similarity Search Implementation
**Tasks:**
- [ ] Implement cosine similarity calculation
- [ ] Optimize query performance
- [ ] Add result ranking
- [ ] Implement filtering options
- [ ] Add pagination support

**Similarity Calculation:**
```php
private function cosine_similarity( $vector_a, $vector_b ) {
    // Dot product / (magnitude_a * magnitude_b)
    $dot_product = array_sum( array_map( 
        function( $a, $b ) { return $a * $b; }, 
        $vector_a, 
        $vector_b 
    ) );
    
    $magnitude_a = sqrt( array_sum( array_map( function( $x ) { 
        return $x * $x; 
    }, $vector_a ) ) );
    
    $magnitude_b = sqrt( array_sum( array_map( function( $x ) { 
        return $x * $x; 
    }, $vector_b ) ) );
    
    return $dot_product / ( $magnitude_a * $magnitude_b );
}
```

#### Day 9-10: semantic_search Tool
**Tasks:**
- [ ] Create `includes/tools/class-wp-mcp-ai-tool-semantic-search.php`
- [ ] Create validation class
- [ ] Implement tool execution logic
- [ ] Add content type filtering
- [ ] Add result limiting and pagination
- [ ] Add metadata enrichment

**Tool Definition:**
```php
class WP_MCP_AI_Tool_Semantic_Search extends WP_MCP_AI_Tool_Base {
    public function get_slug() {
        return 'semantic_search';
    }
    
    public function execute( $arguments, $context ) {
        // 1. Generate embedding for query
        // 2. Search for similar embeddings
        // 3. Fetch and enrich results
        // 4. Return formatted response
    }
}
```

**Arguments:**
- `query` (string, required): Search query
- `content_types` (array, optional): Filter by post types
- `limit` (int, optional): Max results (default 10)
- `min_similarity` (float, optional): Minimum similarity score
- `include_metadata` (bool, optional): Include post metadata

---

### Week 3: CLI, UI, and Polish

#### Day 11-12: WP-CLI Commands
**Tasks:**
- [ ] Create `includes/cli/class-wp-mcp-ai-embeddings-cli.php`
- [ ] Implement `generate` command
- [ ] Implement `search` command  
- [ ] Implement `reindex` command
- [ ] Implement `stats` command
- [ ] Add progress bars for batch operations
- [ ] Add error recovery for failed operations

**Commands:**
```bash
# Generate embeddings for all posts
wp mcp-ai embeddings generate --post-type=post --batch-size=100

# Search via CLI
wp mcp-ai embeddings search "WordPress development tips" --limit=5

# Reindex all content
wp mcp-ai embeddings reindex --force

# Show statistics
wp mcp-ai embeddings stats
```

#### Day 13-14: Admin UI
**Tasks:**
- [ ] Create admin page under Settings → WP oOS → Embeddings
- [ ] Add provider configuration section
- [ ] Add search testing console
- [ ] Display embedding statistics
- [ ] Show API usage metrics
- [ ] Add batch generation interface
- [ ] Create progress indicators

**UI Sections:**
1. **Configuration**
   - Provider selection (OpenAI, Voyage, etc.)
   - API key configuration
   - Model selection
   - Batch size settings
   
2. **Search Console**
   - Search input field
   - Content type filters
   - Results display
   - Similarity scores
   
3. **Statistics**
   - Total embeddings count
   - Storage usage
   - API usage this month
   - Estimated costs
   
4. **Batch Operations**
   - Generate embeddings for content
   - Progress bar
   - Error log

#### Day 15: Testing & Documentation
**Tasks:**
- [ ] Write unit tests for all components
- [ ] Create integration tests
- [ ] Performance benchmark with large datasets
- [ ] Write user documentation
- [ ] Write developer API documentation
- [ ] Create usage examples
- [ ] Document best practices

---

## Cost Analysis

### Embedding Generation Costs (Example: OpenAI)

**text-embedding-3-small** ($0.02 per 1M tokens)
- 1,000 posts × 500 tokens avg = 500K tokens
- Cost: $0.01 for initial generation
- Dimensions: 1536

**text-embedding-3-large** ($0.13 per 1M tokens)
- 1,000 posts × 500 tokens avg = 500K tokens
- Cost: $0.065 for initial generation
- Dimensions: 3072 (better accuracy)

**Ongoing Costs:**
- Only new/updated content needs embeddings
- Average site: ~$1-5/month depending on update frequency

### Storage Requirements

**1,000 posts with text-embedding-3-small (1536 dimensions):**
- Per embedding: ~6KB (1536 floats as JSON)
- Total: ~6MB for 1,000 posts
- 10,000 posts: ~60MB
- Very manageable for MySQL

---

## Performance Considerations

### Optimization Strategies

1. **Caching**
   - Cache embedding API responses
   - Cache similarity search results
   - Use WordPress transients

2. **Batch Processing**
   - Generate embeddings in batches of 100
   - Use WP-CLI for large sites
   - Implement rate limiting

3. **Search Optimization**
   - Index content hash for deduplication
   - Limit search to most recent embeddings first
   - Implement pagination
   - Consider approximate nearest neighbor (ANN) algorithms

4. **Database Indexing**
   - Index content_type and content_id
   - Index created_at for filtering
   - Consider partitioning for very large tables

---

## Security Considerations

### API Key Management
- [ ] Store API keys encrypted in database
- [ ] Use WP_MCP_AI_Encryption class
- [ ] Add capability checks for API key access
- [ ] Log API usage for monitoring

### Access Control
- [ ] Only allow manage_options capability for configuration
- [ ] Require edit_posts capability for generating embeddings
- [ ] Public semantic_search requires read permissions
- [ ] Rate limit public API access

### Data Privacy
- [ ] Don't send private/draft content to API
- [ ] Add filter for excluding content types
- [ ] Allow per-post opt-out
- [ ] Document GDPR implications

---

## Success Metrics

### Phase 2C Complete When:
- [ ] Symfony AI component integrated (or direct API working)
- [ ] Embeddings Manager service created and tested
- [ ] Storage repository implemented with similarity search
- [ ] semantic_search tool functional
- [ ] WP-CLI commands working
- [ ] Admin UI complete and usable
- [ ] All tests passing (unit + integration)
- [ ] Documentation complete
- [ ] Code review approved

### Quality Metrics:
- Test coverage > 80%
- No performance regression
- API costs documented and reasonable
- User documentation clear and comprehensive
- All security checks in place

---

## Migration Strategy

### For Existing Sites
1. **Initial Setup**
   - Admin enables embeddings feature
   - Configures provider and API key
   - Selects content types to index

2. **Batch Generation**
   - WP-CLI command for large sites
   - Admin UI batch for small sites
   - Progress tracking and resumability

3. **Ongoing Maintenance**
   - Auto-generate on post save/update
   - Option to disable auto-generation
   - Scheduled cleanup of orphaned embeddings

---

## Future Enhancements (Phase 3+)

### Advanced Features
- [ ] Multi-language embeddings support
- [ ] Image embeddings (CLIP model)
- [ ] Audio embeddings for podcasts
- [ ] Hybrid search (keyword + semantic)
- [ ] Personalized search based on user behavior
- [ ] Related content suggestions
- [ ] Auto-tagging based on content similarity
- [ ] Plagiarism detection

### Performance Upgrades
- [ ] ChromaDB integration for large-scale deployments
- [ ] Vector database backend (Pinecone, Weaviate)
- [ ] Approximate nearest neighbor (ANN) algorithms
- [ ] Distributed processing for batch operations

---

## Dependencies

### Required
- [x] PHP 8.0+ (for attributes)
- [x] WordPress 6.0+
- [x] Composer for dependency management
- [x] Phase 2A complete (Symfony Validator)
- [x] Phase 2B complete (Symfony Process)

### Optional
- [ ] JetEngine (for CCT storage option)
- [ ] ChromaDB (for advanced vector storage)
- [ ] PostgreSQL with pgvector (alternative storage)

---

## Timeline Summary

**Week 1:**
- Days 1-2: Research and evaluation
- Day 3: Infrastructure setup
- Day 4: Embeddings Manager
- Day 5: Initial testing

**Week 2:**
- Days 6-7: Storage repository
- Day 8: Similarity search
- Days 9-10: semantic_search tool

**Week 3:**
- Days 11-12: WP-CLI commands
- Days 13-14: Admin UI
- Day 15: Testing and documentation

**Total:** 15 working days (3 weeks)

---

## Next Steps (Immediate)

1. **Research Phase** (This Week)
   - [ ] Review Symfony AI documentation
   - [ ] Evaluate embedding providers
   - [ ] Calculate cost estimates for typical use cases
   - [ ] Design storage schema
   - [ ] Create detailed technical specification

2. **Decision Points**
   - [ ] Symfony AI vs direct API integration?
   - [ ] Which embedding provider for v1?
   - [ ] Custom table vs JetEngine CCT?
   - [ ] Support multiple providers from start?

3. **Stakeholder Review**
   - [ ] Present cost analysis
   - [ ] Review storage requirements
   - [ ] Approve provider selection
   - [ ] Set success criteria

---

## Resources

### Documentation
- Symfony AI: https://symfony.com/doc/current/ai.html (if exists)
- OpenAI Embeddings: https://platform.openai.com/docs/guides/embeddings
- Vector Similarity Search: https://en.wikipedia.org/wiki/Cosine_similarity
- MySQL JSON Functions: https://dev.mysql.com/doc/refman/8.0/en/json-functions.html

### Reference Implementations
- WordPress Embedding Plugins (research competitors)
- Symfony AI Examples (if available)
- Vector database documentation

---

**Last Updated:** December 10, 2025  
**Status:** Planning phase, ready to begin research  
**Next Review:** After research phase (Week 1, Day 2)
