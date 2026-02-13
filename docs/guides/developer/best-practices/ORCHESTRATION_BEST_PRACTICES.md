# AI Tool Orchestration Best Practices

**Status:** ✅ Best Practice Guidelines  
**Version:** 1.0  
**Last Updated:** February 13, 2026

## Quick Reference

This document provides actionable best practices for implementing multi-step orchestration in AI tools, based on industry standards from Microsoft Azure, AWS, Prompts.ai, and leading AI platforms (2024-2026).

## Core Principles

### 1. Modularity First

**✅ DO:**
- Break complex workflows into single-purpose steps
- Keep each step independently testable
- Use dependency injection for flexibility
- Design for reusability across tools

**❌ DON'T:**
- Create monolithic orchestration functions
- Mix concerns (validation + creation + logging in one method)
- Hard-code dependencies
- Duplicate orchestration logic across tools

**Example:**
```php
// ✅ GOOD: Modular steps
protected function step_research( $arguments, $context ) {
    return $this->web_search_service->search( $arguments['topic'] );
}

protected function step_validate( $data, $arguments ) {
    return $this->validator->validate( $data, $this->get_schema() );
}

// ❌ BAD: Monolithic function
protected function do_everything( $arguments, $context ) {
    // 500 lines of mixed research, validation, creation...
}
```

### 2. Error Handling & Recovery

**✅ DO:**
- Check for errors after each step
- Return WP_Error with context
- Provide clear, actionable error messages
- Implement retry logic with exponential backoff
- Support graceful degradation

**❌ DON'T:**
- Suppress errors silently
- Return generic error messages
- Fail entire workflow for non-critical errors
- Retry without delay or limits

**Example:**
```php
// ✅ GOOD: Comprehensive error handling
$research_data = $this->step_research( $arguments, $context );

if ( is_wp_error( $research_data ) ) {
    $this->log_step_failure( 'research', $research_data );
    
    return new WP_Error(
        'research_failed',
        sprintf(
            'Research step failed: %s. Please check your API keys and try again.',
            $research_data->get_error_message()
        ),
        array(
            'step'          => 'research',
            'original_code' => $research_data->get_error_code(),
            'recoverable'   => true,
            'retry_after'   => 60, // seconds
        )
    );
}

// ❌ BAD: Poor error handling
$research_data = $this->step_research( $arguments, $context );
if ( ! $research_data ) {
    return array( 'error' => 'Research failed' ); // No context, no recovery
}
```

### 3. Observability & Debugging

**✅ DO:**
- Log every step with context
- Track execution time per step
- Use consistent log format
- Store audit trails
- Provide progress indicators

**❌ DON'T:**
- Log sensitive data (API keys, passwords)
- Skip logging for "simple" operations
- Use inconsistent log levels
- Overlog (spam logs with debug data)

**Example:**
```php
// ✅ GOOD: Comprehensive observability
protected function execute_step( $step_name, $callable, $arguments ) {
    $start_time = microtime( true );
    
    $this->log_step( $this->execution_id, $step_name, 'started', $arguments );
    
    try {
        $result = call_user_func( $callable, $arguments );
        
        $duration = microtime( true ) - $start_time;
        
        $this->log_step( $this->execution_id, $step_name, 'completed', array(
            'duration_ms' => round( $duration * 1000, 2 ),
            'result_size' => is_array( $result ) ? count( $result ) : strlen( $result ),
        ) );
        
        return $result;
    } catch ( Exception $e ) {
        $this->log_step( $this->execution_id, $step_name, 'failed', array(
            'error'       => $e->getMessage(),
            'duration_ms' => round( ( microtime( true ) - $start_time ) * 1000, 2 ),
        ) );
        
        return new WP_Error( 'step_failed', $e->getMessage() );
    }
}

// ❌ BAD: No observability
$result = $callable( $arguments ); // No tracking, no logs
```

### 4. State Management

**✅ DO:**
- Use transients for temporary state
- Implement idempotent operations
- Generate unique execution IDs
- Clean up completed executions
- Support resume capability

**❌ DON'T:**
- Store state in global variables
- Assume database persistence
- Create duplicate operations
- Leave stale state data

**Example:**
```php
// ✅ GOOD: Proper state management
protected function save_execution_state( $execution_id, $step, $data ) {
    $state = array(
        'execution_id' => $execution_id,
        'current_step' => $step,
        'data'         => $data,
        'timestamp'    => current_time( 'mysql' ),
    );
    
    set_transient(
        "wp_mcp_ai_exec_{$execution_id}",
        $state,
        HOUR_IN_SECONDS // Auto-cleanup
    );
}

protected function resume_execution( $execution_id ) {
    $state = get_transient( "wp_mcp_ai_exec_{$execution_id}" );
    
    if ( false === $state ) {
        return new WP_Error( 'execution_not_found', 'Execution state expired or not found' );
    }
    
    return $this->continue_from_step( $state['current_step'], $state['data'] );
}

// ❌ BAD: No state management
global $current_step; // Global state
$current_step = 'research'; // Lost on page refresh
```

### 5. Performance Optimization

**✅ DO:**
- Cache expensive operations
- Use async execution for long-running tasks
- Implement parallel processing for independent steps
- Set appropriate timeouts
- Monitor resource usage

**❌ DON'T:**
- Repeat expensive operations
- Block on long-running tasks
- Process sequentially when parallel is possible
- Use infinite timeouts

**Example:**
```php
// ✅ GOOD: Optimized execution
protected function step_research( $arguments, $context ) {
    // Check cache first
    $cache_key = $this->get_cache_key( 'research', $arguments );
    $cached    = WP_MCP_AI_Cache_Helper::get_cache( $cache_key );
    
    if ( false !== $cached ) {
        $this->log_step( $this->execution_id, 'research', 'cache_hit' );
        return $cached;
    }
    
    // Execute with timeout
    $timeout = $arguments['timeout'] ?? 30;
    set_time_limit( $timeout );
    
    $result = $this->perform_research( $arguments );
    
    // Cache for reuse
    WP_MCP_AI_Cache_Helper::set_cache( $cache_key, $result, HOUR_IN_SECONDS );
    
    return $result;
}

// Parallel execution for independent operations
protected function execute_parallel_steps( $steps ) {
    $results = array();
    
    foreach ( $steps as $step_name => $callable ) {
        // Schedule as background job
        $job_id = $this->schedule_background_job( $step_name, $callable );
        $results[ $step_name ] = $job_id;
    }
    
    // Wait for all jobs
    return $this->wait_for_jobs( $results );
}

// ❌ BAD: No optimization
protected function step_research( $arguments, $context ) {
    return $this->perform_research( $arguments ); // No cache, no timeout
}
```

### 6. Security & Validation

**✅ DO:**
- Validate inputs at every step
- Check capabilities before operations
- Sanitize data before storage
- Escape output
- Use nonces for state-changing operations

**❌ DON'T:**
- Trust user input
- Skip validation for "internal" steps
- Store unsanitized data
- Output unescaped data

**Example:**
```php
// ✅ GOOD: Security-first approach
protected function step_create( $research_data, $arguments, $context ) {
    // Validate capability
    if ( ! current_user_can( 'edit_posts' ) ) {
        return new WP_Error( 'insufficient_permission', 'User lacks edit_posts capability' );
    }
    
    // Sanitize inputs
    $title   = sanitize_text_field( $research_data['title'] );
    $content = wp_kses_post( $research_data['content'] );
    
    // Validate data
    if ( empty( $title ) || strlen( $title ) > 200 ) {
        return new WP_Error( 'invalid_title', 'Title must be 1-200 characters' );
    }
    
    // Create with validation
    $post_id = wp_insert_post( array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'draft', // Safe default
    ), true ); // Return WP_Error on failure
    
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }
    
    return array(
        'id'    => $post_id,
        'title' => $title,
        'url'   => get_edit_post_link( $post_id, 'raw' ),
    );
}

// ❌ BAD: No security
protected function step_create( $research_data, $arguments, $context ) {
    return wp_insert_post( $research_data ); // No validation, capability check, or sanitization
}
```

## Orchestration Patterns

### Pattern 1: Sequential Pipeline (Research → Create)

**When to Use:**
- Ordered dependencies
- Each step depends on previous
- Predictable, linear flow

**Example:**
```php
public function execute( $arguments, $context ) {
    // Step 1: Research
    $research = $this->step_research( $arguments, $context );
    if ( is_wp_error( $research ) ) return $research;
    
    // Step 2: Validate
    $validated = $this->step_validate( $research, $arguments );
    if ( is_wp_error( $validated ) ) return $validated;
    
    // Step 3: Create
    $created = $this->step_create( $validated, $arguments, $context );
    if ( is_wp_error( $created ) ) return $created;
    
    return array( 'success' => true, 'result' => $created );
}
```

### Pattern 2: Parallel Fan-out (Multiple Providers)

**When to Use:**
- Independent operations
- Speed optimization needed
- Multiple provider fallbacks

**Example:**
```php
public function execute( $arguments, $context ) {
    // Start parallel operations
    $jobs = array(
        'openai' => $this->generate_with_openai_async( $arguments ),
        'gemini' => $this->generate_with_gemini_async( $arguments ),
    );
    
    // Wait for first success
    $result = $this->wait_for_first_success( $jobs );
    
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'all_providers_failed', 'All providers failed' );
    }
    
    return array( 'success' => true, 'result' => $result );
}
```

### Pattern 3: Event-Driven (Long-Running)

**When to Use:**
- Operations > 30 seconds
- Async processing needed
- Resume capability required

**Example:**
```php
public function execute( $arguments, $context ) {
    // Check run mode
    if ( 'background' === ( $arguments['run_mode'] ?? 'immediate' ) ) {
        return $this->schedule_background_execution( $arguments, $context );
    }
    
    // Immediate execution with progress tracking
    $execution_id = $this->generate_execution_id();
    
    foreach ( $this->get_steps() as $step_name => $step_callable ) {
        $result = $this->execute_step( $step_name, $step_callable, $arguments );
        
        if ( is_wp_error( $result ) ) {
            return $this->handle_failure( $execution_id, $step_name, $result );
        }
        
        $this->update_progress( $execution_id, $step_name, 'completed' );
    }
    
    return $this->finalize_execution( $execution_id );
}
```

### Pattern 4: Validation Gate (Quality Check)

**When to Use:**
- Quality gates required
- Compliance checks needed
- Multi-stage approval

**Example:**
```php
public function execute( $arguments, $context ) {
    // Step 1: Generate content
    $content = $this->step_generate( $arguments, $context );
    if ( is_wp_error( $content ) ) return $content;
    
    // Step 2: Quality gate
    $quality = $this->step_validate_quality( $content, $arguments );
    
    if ( is_wp_error( $quality ) || $quality['score'] < 0.7 ) {
        // Retry with enhanced prompt
        $content = $this->step_regenerate_enhanced( $arguments, $context );
        if ( is_wp_error( $content ) ) return $content;
    }
    
    // Step 3: Final validation
    $validated = $this->step_final_validation( $content, $arguments );
    if ( is_wp_error( $validated ) ) return $validated;
    
    // Step 4: Publish
    return $this->step_publish( $content, $arguments, $context );
}
```

## Testing Guidelines

### Unit Test Each Step

```php
public function test_step_research_success() {
    $tool = new WP_MCP_AI_Tool_Example();
    
    $arguments = array( 'topic' => 'Test' );
    $context   = new stdClass();
    
    $result = $tool->step_research( $arguments, $context );
    
    $this->assertIsArray( $result );
    $this->assertArrayHasKey( 'data', $result );
}

public function test_step_research_failure() {
    $tool = new WP_MCP_AI_Tool_Example();
    
    // Mock API failure
    add_filter( 'wp_mcp_ai_force_api_failure', '__return_true' );
    
    $result = $tool->step_research( array(), new stdClass() );
    
    $this->assertWPError( $result );
    $this->assertEquals( 'api_failed', $result->get_error_code() );
}
```

### Integration Test Full Workflow

```php
public function test_full_orchestration_workflow() {
    $tool = new WP_MCP_AI_Tool_Example();
    
    $arguments = array(
        'topic'   => 'Test Topic',
        'enhance' => true,
    );
    
    $result = $tool->execute( $arguments, new stdClass() );
    
    $this->assertIsArray( $result );
    $this->assertTrue( $result['success'] );
    $this->assertArrayHasKey( 'execution_id', $result );
    $this->assertArrayHasKey( 'steps', $result );
}
```

### Test Error Recovery

```php
public function test_step_failure_recovery() {
    $tool = new WP_MCP_AI_Tool_Example();
    
    // Force failure at step 2
    add_filter( 'wp_mcp_ai_force_step_failure', function( $fail, $step ) {
        return 'validate' === $step;
    }, 10, 2 );
    
    $result = $tool->execute( array(), new stdClass() );
    
    $this->assertWPError( $result );
    $this->assertStringContainsString( 'validate', $result->get_error_message() );
}
```

## Common Pitfalls

### ❌ Pitfall 1: No Error Propagation

**Problem:**
```php
$result = $this->step_research( $arguments, $context );
// Continue without checking if $result is WP_Error
$validated = $this->step_validate( $result );
```

**Solution:**
```php
$result = $this->step_research( $arguments, $context );
if ( is_wp_error( $result ) ) {
    return $result; // Early exit
}
$validated = $this->step_validate( $result );
```

### ❌ Pitfall 2: State Leakage

**Problem:**
```php
class WP_MCP_AI_Tool_Example {
    private $current_step_data; // Shared state
    
    public function execute( $args, $ctx ) {
        $this->current_step_data = $this->step_1();
        return $this->step_2(); // Uses shared state
    }
}
```

**Solution:**
```php
class WP_MCP_AI_Tool_Example {
    public function execute( $args, $ctx ) {
        $step1_data = $this->step_1();
        return $this->step_2( $step1_data ); // Pass explicitly
    }
}
```

### ❌ Pitfall 3: No Timeout Handling

**Problem:**
```php
public function step_research( $arguments, $context ) {
    // Potentially infinite operation
    return $this->web_search( $arguments['topic'] );
}
```

**Solution:**
```php
public function step_research( $arguments, $context ) {
    $timeout = $arguments['timeout'] ?? 30;
    set_time_limit( $timeout );
    
    $start_time = time();
    
    $result = $this->web_search( $arguments['topic'] );
    
    if ( time() - $start_time > $timeout - 5 ) {
        return new WP_Error( 'timeout_risk', 'Operation near timeout, returning partial results' );
    }
    
    return $result;
}
```

### ❌ Pitfall 4: Inconsistent Logging

**Problem:**
```php
error_log( 'Step 1 done' );
// ... later ...
error_log( 'Error in step 3: ' . $error );
// Inconsistent format, hard to grep
```

**Solution:**
```php
$this->log_step( $execution_id, 'step_1', 'completed' );
$this->log_step( $execution_id, 'step_3', 'failed', $error );
// Consistent format: [WP_MCP_AI] [exec_id] Step: name | Status: status
```

## Migration Checklist

When enhancing existing tools with orchestration:

- [ ] Analyze current tool structure
- [ ] Identify natural step boundaries
- [ ] Add execution ID generation
- [ ] Implement step logging
- [ ] Add error handling per step
- [ ] Implement state management
- [ ] Add progress tracking
- [ ] Create unit tests for each step
- [ ] Create integration tests for workflow
- [ ] Add caching for expensive operations
- [ ] Document new parameters
- [ ] Update tool description
- [ ] Test backward compatibility
- [ ] Deploy behind feature flag (optional)
- [ ] Monitor performance in production

## References

### Industry Standards
- [Microsoft Azure: AI Agent Orchestration Patterns](https://learn.microsoft.com/en-us/azure/architecture/ai-ml/guide/ai-agent-design-patterns)
- [AWS: Multi-stage AI Workflows](https://docs.aws.amazon.com/prescriptive-guidance/latest/agentic-ai-serverless/)
- [Prompts.ai: AI Model Orchestration](https://www.prompts.ai/blog/ai-model-orchestration-workflows-patterns)
- [Deepchecks: Multi-Step LLM Chains](https://www.deepchecks.com/orchestrating-multi-step-llm-chains-best-practices/)

### Internal Resources
- [Multi-Step Orchestration Pattern Guide](./MULTI_STEP_ORCHESTRATION_PATTERN.md)
- [Tool Development Guidelines](../tool-development/)
- Reference Implementations:
  - `class-wp-mcp-ai-tool-deep-research.php`
  - `class-wp-mcp-ai-tool-create-agent-team.php`

---

**Version**: 1.0  
**Last Updated**: February 13, 2026  
**Maintained By**: Development Team
