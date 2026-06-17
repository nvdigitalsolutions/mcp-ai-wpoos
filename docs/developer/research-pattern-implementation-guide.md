# Research Pattern Implementation Guide

**Version:** 1.0  
**Last Updated:** February 13, 2026  
**Target Audience:** Plugin Developers

## Overview

This guide provides step-by-step instructions for implementing the **research_product pattern** in WordPress plugin tools. The pattern enables multi-step orchestration: **web search → source collection → AI synthesis → report generation**.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Pattern Architecture](#pattern-architecture)
3. [Step-by-Step Implementation](#step-by-step-implementation)
4. [Code Templates](#code-templates)
5. [Testing](#testing)
6. [Common Pitfalls](#common-pitfalls)
7. [Best Practices](#best-practices)
8. [Examples](#examples)

---

## Prerequisites

### Required Knowledge

- PHP 7.4+ and WordPress development
- Understanding of tool interface (`WP_MCP_AI_Tool_Interface`)
- Familiarity with WordPress coding standards
- Basic understanding of AI/LLM integration

### Required Tools

- Tool registry access (`WP_MCP_AI_Tool_Registry`)
- Web search tool (`web_search`)
- API manager (`WP_MCP_AI_API_Manager`)
- Caching system (WordPress transients)

### Reference Implementation

Study the reference implementation first:

```
/addons/pro/includes/tools/class-wp-mcp-ai-tool-research-product.php
```

This is the canonical example of the research pattern.

---

## Pattern Architecture

### The 4-Step Pipeline

```
┌─────────────────────────────────────────────────────────────┐
│ 1. WEB SEARCH (Information Gathering)                      │
│    - Generate dynamic search queries                        │
│    - Execute web searches (1-3 queries based on depth)      │
│    - Collect results from multiple sources                  │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│ 2. SOURCE COLLECTION (Aggregation & Deduplication)         │
│    - Aggregate results from all queries                     │
│    - Deduplicate sources by URL                             │
│    - Limit to top N sources (typically 5-15)                │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│ 3. AI SYNTHESIS (Contextual Analysis)                      │
│    - Build comprehensive prompt with sources                │
│    - Call AI API with low temperature (0.3) for facts       │
│    - Request structured JSON response                       │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│ 4. REPORT GENERATION (Structured Output)                   │
│    - Parse AI response                                      │
│    - Format for tool's specific needs                       │
│    - Include source citations                               │
└─────────────────────────────────────────────────────────────┘
```

### Key Characteristics

- **Depth Levels:** Basic (1 query), Standard (2 queries), Comprehensive (3 queries)
- **Caching:** 24-hour transient cache to prevent duplicate searches
- **Error Handling:** Graceful fallback to AI-only mode if web search fails
- **Token Efficiency:** Smart source limiting and content trimming
- **Logging:** Multi-stage event logging for debugging

---

## Step-by-Step Implementation

### Step 1: Update Tool Parameters

Add research-related parameters to your tool's schema:

```php
public function get_parameters_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            // Existing parameters...
            
            // Research parameters (add these)
            'use_research'    => array(
                'type'        => 'boolean',
                'description' => __( 'Enable web research for enhanced results', 'mcp-ai-wpoos' ),
                'default'     => true, // Or false for backward compatibility
            ),
            'research_depth'  => array(
                'type'        => 'string',
                'enum'        => array( 'basic', 'standard', 'comprehensive' ),
                'description' => __( 'Research depth: basic (1 query), standard (2 queries), comprehensive (3 queries)', 'mcp-ai-wpoos' ),
                'default'     => 'standard',
            ),
            'focus_areas'     => array(
                'type'        => 'array',
                'items'       => array( 'type' => 'string' ),
                'description' => __( 'Specific focus areas for research queries (e.g., ["pricing", "reviews", "specifications"])', 'mcp-ai-wpoos' ),
            ),
        ),
        'required'   => array( /* existing required fields */ ),
    );
}
```

### Step 2: Update Capability Flags

Add appropriate capability flags if research adds async or long-running behavior:

```php
public function get_capability_flags() {
    return array(
        'write',              // If tool writes data
        'read',               // If tool reads data
        'cacheable',          // Results can be cached
        'consumes-tokens',    // Uses AI API tokens
        'model-dependent',    // Depends on AI model
        'may-timeout',        // Research can take time (optional)
        // Don't add 'async' unless you implement queue processing
    );
}
```

### Step 3: Implement Main Execute Method

Update your `execute()` method to route between research and non-research modes:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    $start_time = microtime( true );
    
    // Validate and sanitize inputs
    $use_research = isset( $arguments['use_research'] ) ? (bool) $arguments['use_research'] : true;
    $depth = isset( $arguments['research_depth'] ) ? sanitize_text_field( $arguments['research_depth'] ) : 'standard';
    $focus_areas = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] ) 
        ? array_map( 'sanitize_text_field', $arguments['focus_areas'] ) 
        : array();
    
    // Validate depth
    if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
        $depth = 'standard';
    }
    
    // Check cache first
    $cache_key = $this->get_cache_key( $arguments );
    $cached_result = get_transient( $cache_key );
    if ( false !== $cached_result ) {
        $this->log_event( 'cache_hit', array( 'cache_key' => $cache_key ) );
        return $cached_result;
    }
    
    // Execute with or without research
    if ( $use_research ) {
        $result = $this->execute_with_research( $arguments, $context, $depth, $focus_areas );
    } else {
        $result = $this->execute_without_research( $arguments, $context );
    }
    
    // Cache successful results
    if ( ! is_wp_error( $result ) ) {
        set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
    }
    
    // Log execution
    $this->log_event(
        'tool_execution',
        array(
            'tool'           => $this->get_slug(),
            'use_research'   => $use_research,
            'depth'          => $depth,
            'execution_time' => microtime( true ) - $start_time,
        )
    );
    
    return $result;
}
```

### Step 4: Implement Research Execution Method

Create the method that orchestrates the research pattern:

```php
/**
 * Execute with research pattern
 *
 * @param array  $arguments Arguments.
 * @param array  $context   Context.
 * @param string $depth     Research depth.
 * @param array  $focus_areas Focus areas.
 * @return array|WP_Error Result or error.
 */
protected function execute_with_research( $arguments, $context, $depth, $focus_areas ) {
    // Extract main query parameter (adapt to your tool)
    $query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
    
    if ( empty( $query ) ) {
        return new WP_Error( 'missing_query', __( 'Query parameter is required', 'mcp-ai-wpoos' ) );
    }
    
    // Step 1: Gather information through web searches
    $search_results = $this->gather_information( $query, $depth, $focus_areas, $context );
    
    if ( is_wp_error( $search_results ) ) {
        // Graceful fallback to AI-only mode
        $this->log_event( 'research_failed', array( 'error' => $search_results->get_error_message() ) );
        return $this->execute_without_research( $arguments, $context );
    }
    
    // Step 2: Build research prompt
    $prompt = $this->build_research_prompt( $query, $depth, $focus_areas, $search_results, $arguments );
    
    // Step 3: Perform AI synthesis
    $research_result = $this->perform_ai_research( $prompt, $context );
    
    if ( is_wp_error( $research_result ) ) {
        return $research_result;
    }
    
    // Step 4: Parse and format results
    $data = $this->parse_research_results( $research_result, $query, $search_results );
    
    return $data;
}
```

### Step 5: Implement Information Gathering

Create the method that performs web searches:

```php
/**
 * Gather information through web searches
 *
 * @param string $query       Main query.
 * @param string $depth       Research depth.
 * @param array  $focus_areas Focus areas.
 * @param array  $context     Execution context.
 * @return array|WP_Error Search results or error.
 */
protected function gather_information( $query, $depth, $focus_areas, $context ) {
    $tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
    $web_search = $tool_registry->get_tool( 'web_search' );
    
    if ( ! $web_search ) {
        return new WP_Error( 'web_search_unavailable', __( 'Web search tool not available', 'mcp-ai-wpoos' ) );
    }
    
    // Determine number of queries based on depth
    $num_queries = $this->get_query_count_for_depth( $depth );
    
    $all_results = array();
    $seen_urls = array();
    
    // Execute multiple searches
    for ( $i = 0; $i < $num_queries; $i++ ) {
        $search_query = $this->generate_search_query( $query, $focus_areas, $i );
        
        $this->log_event(
            'web_search',
            array(
                'query'     => $search_query,
                'iteration' => $i,
            )
        );
        
        $search_response = $web_search->execute(
            array(
                'query' => $search_query,
                'limit' => 5, // Limit results per query
            ),
            $context
        );
        
        if ( is_wp_error( $search_response ) ) {
            $this->log_event( 'web_search_error', array( 'error' => $search_response->get_error_message() ) );
            continue;
        }
        
        if ( isset( $search_response['results'] ) && is_array( $search_response['results'] ) ) {
            foreach ( $search_response['results'] as $result ) {
                $url = $result['url'] ?? '';
                
                // Deduplicate by URL
                if ( $url && ! in_array( $url, $seen_urls, true ) ) {
                    $all_results[] = $result;
                    $seen_urls[] = $url;
                }
            }
        }
    }
    
    $this->log_event(
        'sources_collected',
        array(
            'total_sources' => count( $all_results ),
            'unique_urls'   => count( $seen_urls ),
        )
    );
    
    return $all_results;
}
```

### Step 6: Implement Dynamic Query Generation

Create varied search queries for better source diversity:

```php
/**
 * Generate search query for specific iteration
 *
 * @param string $query       Base query.
 * @param array  $focus_areas Focus areas.
 * @param int    $iteration   Query iteration (0-based).
 * @return string Search query.
 */
protected function generate_search_query( $query, $focus_areas, $iteration ) {
    // First query: use base query
    if ( $iteration === 0 ) {
        return $query;
    }
    
    // Subsequent queries: add focus areas
    if ( ! empty( $focus_areas ) && isset( $focus_areas[ $iteration - 1 ] ) ) {
        return sprintf( '%s %s', $query, $focus_areas[ $iteration - 1 ] );
    }
    
    // Fallback: add generic modifiers
    $modifiers = array(
        'best practices',
        'guide',
        'tutorial',
        'comparison',
        'review',
    );
    
    $modifier_index = ( $iteration - 1 ) % count( $modifiers );
    return sprintf( '%s %s', $query, $modifiers[ $modifier_index ] );
}

/**
 * Get query count based on depth
 *
 * @param string $depth Research depth.
 * @return int Number of queries.
 */
protected function get_query_count_for_depth( $depth ) {
    $counts = array(
        'basic'         => 1,
        'standard'      => 2,
        'comprehensive' => 3,
    );
    
    return $counts[ $depth ] ?? 2;
}
```

### Step 7: Implement Prompt Building

Create a comprehensive prompt with sources:

```php
/**
 * Build research prompt with gathered information
 *
 * @param string $query          Main query.
 * @param string $depth          Research depth.
 * @param array  $focus_areas    Focus areas.
 * @param array  $search_results Search results.
 * @param array  $arguments      Original arguments (for additional context).
 * @return string Research prompt.
 */
protected function build_research_prompt( $query, $depth, $focus_areas, $search_results, $arguments ) {
    $prompt = "You are a research assistant specializing in [YOUR DOMAIN]. ";
    $prompt .= "Your task is to analyze the following sources and provide a comprehensive, accurate answer.\n\n";
    
    // Add query context
    $prompt .= "## Query\n";
    $prompt .= $query . "\n\n";
    
    // Add research depth
    $prompt .= "## Research Depth\n";
    $prompt .= ucfirst( $depth ) . " research (detailed analysis required)\n\n";
    
    // Add focus areas if provided
    if ( ! empty( $focus_areas ) ) {
        $prompt .= "## Focus Areas\n";
        foreach ( $focus_areas as $area ) {
            $prompt .= "- " . $area . "\n";
        }
        $prompt .= "\n";
    }
    
    // Add sources
    if ( ! empty( $search_results ) ) {
        $prompt .= "## Sources\n";
        $source_count = 0;
        $max_sources = 10; // Limit to prevent token overflow
        
        foreach ( array_slice( $search_results, 0, $max_sources ) as $result ) {
            $source_count++;
            $title = $result['title'] ?? 'Untitled';
            $url = $result['url'] ?? 'No URL';
            $content = $result['content'] ?? '';
            
            // Trim content to avoid token overflow
            $content = wp_trim_words( $content, 150 ); // ~200 tokens per source
            
            $prompt .= sprintf(
                "[Source %d] %s\n%s\n%s\n\n",
                $source_count,
                $title,
                $url,
                $content
            );
        }
    }
    
    // Add output format instructions
    $prompt .= "## Instructions\n";
    $prompt .= "Please provide a comprehensive response in JSON format with the following structure:\n";
    $prompt .= "{\n";
    $prompt .= '  "summary": "Brief summary of findings",\n';
    $prompt .= '  "key_points": ["Point 1", "Point 2", ...],\n';
    $prompt .= '  "details": "Detailed analysis",\n';
    $prompt .= '  "sources_used": [1, 2, 3], // Array of source numbers cited\n';
    $prompt .= '  "confidence": 0.95 // Confidence score 0-1\n';
    $prompt .= "}\n\n";
    $prompt .= "Base your response on the provided sources. Cite sources by number [1], [2], etc.";
    
    return $prompt;
}
```

### Step 8: Implement AI Synthesis

Call the AI API with appropriate settings:

```php
/**
 * Perform AI research synthesis
 *
 * @param string $prompt  Research prompt.
 * @param array  $context Execution context.
 * @return array|WP_Error AI response or error.
 */
protected function perform_ai_research( $prompt, $context ) {
    $api_manager = new WP_MCP_AI_API_Manager();
    
    // Prepare messages
    $messages = array(
        array(
            'role'    => 'system',
            'content' => 'You are a research assistant. Provide accurate, well-researched responses based on provided sources.',
        ),
        array(
            'role'    => 'user',
            'content' => $prompt,
        ),
    );
    
    // API options
    $options = array(
        'model'           => 'gpt-4o', // Or get from settings
        'temperature'     => 0.3,      // Low temperature for factual content
        'max_tokens'      => 2000,     // Adjust based on needs
        'response_format' => array( 'type' => 'json_object' ), // Request JSON
    );
    
    // Add user context if available
    if ( isset( $context['user_id'] ) ) {
        $options['user_id'] = $context['user_id'];
    }
    
    // Send request
    $response = $api_manager->send_message( $messages, $options );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    // Extract content
    if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
        return new WP_Error( 'invalid_response', __( 'Invalid AI response format', 'mcp-ai-wpoos' ) );
    }
    
    return array(
        'content' => $response['choices'][0]['message']['content'],
        'usage'   => $response['usage'] ?? array(),
    );
}
```

### Step 9: Implement Result Parsing

Parse the AI response and format for your tool:

```php
/**
 * Parse research results
 *
 * @param array  $research_result AI research result.
 * @param string $query           Original query.
 * @param array  $search_results  Original search results.
 * @return array Parsed data.
 */
protected function parse_research_results( $research_result, $query, $search_results ) {
    // Decode JSON response
    $content = $research_result['content'] ?? '';
    $data = json_decode( $content, true );
    
    if ( null === $data ) {
        return new WP_Error( 'parse_error', __( 'Failed to parse research results', 'mcp-ai-wpoos' ) );
    }
    
    // Build source citations
    $sources = array();
    $sources_used = $data['sources_used'] ?? array();
    
    foreach ( $sources_used as $source_num ) {
        $index = $source_num - 1; // Convert to 0-based index
        if ( isset( $search_results[ $index ] ) ) {
            $sources[] = array(
                'title' => $search_results[ $index ]['title'] ?? 'Untitled',
                'url'   => $search_results[ $index ]['url'] ?? '',
            );
        }
    }
    
    // Format final result
    $result = array(
        'success'    => true,
        'query'      => $query,
        'summary'    => $data['summary'] ?? '',
        'key_points' => $data['key_points'] ?? array(),
        'details'    => $data['details'] ?? '',
        'sources'    => $sources,
        'confidence' => $data['confidence'] ?? 0.8,
        'usage'      => $research_result['usage'] ?? array(),
    );
    
    return $result;
}
```

### Step 10: Implement Fallback Method

Provide a non-research fallback:

```php
/**
 * Execute without research (AI-only mode)
 *
 * @param array $arguments Arguments.
 * @param array $context   Context.
 * @return array|WP_Error Result or error.
 */
protected function execute_without_research( $arguments, $context ) {
    $query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
    
    if ( empty( $query ) ) {
        return new WP_Error( 'missing_query', __( 'Query parameter is required', 'mcp-ai-wpoos' ) );
    }
    
    // Build simple prompt without sources
    $prompt = "Please provide a comprehensive answer to the following query:\n\n";
    $prompt .= $query;
    
    // Use AI synthesis (reuse existing method)
    $research_result = $this->perform_ai_research( $prompt, $context );
    
    if ( is_wp_error( $research_result ) ) {
        return $research_result;
    }
    
    // Parse and return
    $data = json_decode( $research_result['content'], true );
    
    return array(
        'success' => true,
        'query'   => $query,
        'summary' => $data['summary'] ?? '',
        'details' => $data['details'] ?? '',
        'sources' => array(), // No sources in AI-only mode
    );
}
```

### Step 11: Implement Cache Key Generation

Create consistent cache keys:

```php
/**
 * Get cache key for arguments
 *
 * @param array $arguments Arguments.
 * @return string Cache key.
 */
protected function get_cache_key( $arguments ) {
    // Create cache key from relevant arguments
    $cache_data = array(
        'tool'           => $this->get_slug(),
        'query'          => $arguments['query'] ?? '',
        'depth'          => $arguments['research_depth'] ?? 'standard',
        'focus_areas'    => $arguments['focus_areas'] ?? array(),
        'use_research'   => $arguments['use_research'] ?? true,
    );
    
    return 'wp_mcp_ai_research_' . md5( serialize( $cache_data ) );
}
```

### Step 12: Implement Logging

Add comprehensive logging:

```php
/**
 * Log event
 *
 * @param string $event Event name.
 * @param array  $data  Event data.
 */
protected function log_event( $event, $data = array() ) {
    if ( ! defined( 'WP_MCP_AI_DEBUG' ) || ! WP_MCP_AI_DEBUG ) {
        return;
    }
    
    $log_entry = array(
        'timestamp' => current_time( 'mysql' ),
        'tool'      => $this->get_slug(),
        'event'     => $event,
        'data'      => $data,
    );
    
    // Use WordPress logging or custom logger
    error_log( sprintf( '[WP_MCP_AI] %s', wp_json_encode( $log_entry ) ) );
}
```

---

## Code Templates

### Complete Tool Template

See `/docs/guides/templates/research-pattern-tool-template.php` (to be created)

### Quick Start Template

```php
<?php
class WP_MCP_AI_Tool_Your_Tool implements WP_MCP_AI_Tool_Interface {
    
    public function execute( array $arguments = array(), array $context = array() ) {
        // 1. Check cache
        $cache_key = $this->get_cache_key( $arguments );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;
        
        // 2. Execute with or without research
        $use_research = $arguments['use_research'] ?? true;
        $result = $use_research 
            ? $this->execute_with_research( $arguments, $context )
            : $this->execute_without_research( $arguments, $context );
        
        // 3. Cache and return
        if ( ! is_wp_error( $result ) ) {
            set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
        }
        return $result;
    }
    
    protected function execute_with_research( $arguments, $context ) {
        // 1. Gather info
        $results = $this->gather_information( ... );
        // 2. Build prompt
        $prompt = $this->build_research_prompt( ... );
        // 3. AI synthesis
        $response = $this->perform_ai_research( $prompt, $context );
        // 4. Parse
        return $this->parse_research_results( $response );
    }
    
    // Implement other methods from guide...
}
```

---

## Testing

### Unit Tests

```php
class Test_Your_Research_Tool extends WP_UnitTestCase {
    
    public function test_execute_with_research() {
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        
        $result = $tool->execute(
            array(
                'query'          => 'test query',
                'use_research'   => true,
                'research_depth' => 'basic',
            ),
            array( 'user_id' => 1 )
        );
        
        $this->assertNotWPError( $result );
        $this->assertArrayHasKey( 'sources', $result );
    }
    
    public function test_execute_without_research() {
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        
        $result = $tool->execute(
            array(
                'query'        => 'test query',
                'use_research' => false,
            ),
            array( 'user_id' => 1 )
        );
        
        $this->assertNotWPError( $result );
    }
    
    public function test_cache_functionality() {
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        $args = array( 'query' => 'test', 'use_research' => true );
        
        // First call
        $result1 = $tool->execute( $args, array( 'user_id' => 1 ) );
        
        // Second call (should be cached)
        $start = microtime( true );
        $result2 = $tool->execute( $args, array( 'user_id' => 1 ) );
        $duration = microtime( true ) - $start;
        
        $this->assertLessThan( 0.1, $duration ); // Cache should be fast
        $this->assertEquals( $result1, $result2 );
    }
}
```

---

## Common Pitfalls

### 1. Token Overflow

**Problem:** Too many sources cause token limit errors

**Solution:**
```php
// Limit sources and trim content
foreach ( array_slice( $search_results, 0, 10 ) as $result ) {
    $content = wp_trim_words( $result['content'] ?? '', 150 );
    // ...
}
```

### 2. Missing Error Handling

**Problem:** Web search fails and tool crashes

**Solution:**
```php
$search_results = $this->gather_information( ... );
if ( is_wp_error( $search_results ) ) {
    return $this->execute_without_research( $arguments, $context );
}
```

### 3. Cache Key Collisions

**Problem:** Different queries return same cached result

**Solution:**
```php
// Include all relevant parameters in cache key
$cache_key = 'wp_mcp_ai_' . md5( serialize( array(
    'tool'   => $this->get_slug(),
    'query'  => $query,
    'depth'  => $depth,
    'focus'  => $focus_areas,
) ) );
```

### 4. No Source Deduplication

**Problem:** Same URL appears multiple times

**Solution:**
```php
$seen_urls = array();
foreach ( $results as $result ) {
    $url = $result['url'] ?? '';
    if ( $url && ! in_array( $url, $seen_urls, true ) ) {
        $all_results[] = $result;
        $seen_urls[] = $url;
    }
}
```

### 5. Missing Backward Compatibility

**Problem:** Existing code breaks after adding research

**Solution:**
```php
// Default to non-research mode for backward compatibility
$use_research = isset( $arguments['use_research'] ) ? (bool) $arguments['use_research'] : false;
```

---

## Best Practices

### 1. Make Research Optional

Always provide `use_research` parameter and default to `false` for existing tools:

```php
'use_research' => array(
    'type'    => 'boolean',
    'default' => false, // Backward compatible
),
```

### 2. Use Smart Caching

24-hour cache for research results:

```php
set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
```

### 3. Implement Rate Limiting

Prevent abuse:

```php
$rate_key = 'research_rate_' . $user_id;
$count = get_transient( $rate_key );
if ( $count && $count >= 10 ) {
    return new WP_Error( 'rate_limit', 'Too many requests' );
}
set_transient( $rate_key, ( $count ?: 0 ) + 1, HOUR_IN_SECONDS );
```

### 4. Log Everything

Enable debugging with `WP_MCP_AI_DEBUG`:

```php
if ( defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG ) {
    $this->log_event( 'research_started', array( 'query' => $query ) );
}
```

### 5. Graceful Degradation

Always provide fallback:

```php
if ( is_wp_error( $search_results ) ) {
    return $this->execute_without_research( $arguments, $context );
}
```

---

## Examples

### Example 1: SEO Meta Optimizer

```php
protected function execute_with_research( $arguments, $context, $depth, $focus_areas ) {
    $focus_keyword = $arguments['focus_keyword'] ?? '';
    
    // Generate SEO-focused queries
    $queries = array(
        "top ranking pages for {$focus_keyword}",
        "{$focus_keyword} meta description examples",
        "seo trends {$focus_keyword}",
    );
    
    // Gather competitor data
    $search_results = $this->gather_information( $focus_keyword, $depth, $queries, $context );
    
    // Analyze and synthesize
    // ... (rest of implementation)
}
```

### Example 2: Content Recommendation Engine

```php
protected function execute_with_research( $arguments, $context, $depth, $focus_areas ) {
    $topic = $arguments['topic'] ?? '';
    
    // Research trending topics
    $queries = array(
        "trending {$topic} content",
        "popular {$topic} articles",
        "{$topic} engagement metrics",
    );
    
    $search_results = $this->gather_information( $topic, $depth, $queries, $context );
    
    // Build recommendations based on trends
    // ... (rest of implementation)
}
```

---

## Next Steps

1. **Study Reference Implementation:** Read `class-wp-mcp-ai-tool-research-product.php`
2. **Start Small:** Enhance one tool as a proof of concept
3. **Test Thoroughly:** Write comprehensive unit and integration tests
4. **Monitor Performance:** Track execution times and token usage
5. **Iterate:** Improve based on real-world usage

---

## Support

For questions or issues:

- **Documentation:** See `/docs/RESEARCH_PATTERN_ENHANCEMENT_ANALYSIS.md`
- **Reference Code:** `/addons/pro/includes/tools/class-wp-mcp-ai-tool-research-product.php`
- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Document End**
