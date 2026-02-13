# Multi-Step Orchestration Pattern for Creation Tools

**Version:** 1.0  
**Last Updated:** February 13, 2026  
**Status:** Best Practice Guide

## Overview

This guide documents the multi-step orchestration pattern for AI tool development, aligned with industry best practices (2024-2026) and the "creation pattern" established in PR #3691. Use this pattern when tools need to coordinate multiple sequential or parallel operations.

## When to Use Multi-Step Orchestration

### ✅ Use Multi-Step Orchestration When:

1. **Complex Creation Workflows**
   - Research → Validate → Create → Optimize
   - Generate → Review → Enhance → Publish
   - Example: Product research that includes web search, data validation, and WooCommerce creation

2. **Quality Gates Required**
   - Validation before creation
   - Review after generation
   - Compliance checks before publishing
   - Example: Content creation with SEO validation and accessibility checks

3. **External Dependencies**
   - API calls that may fail
   - Long-running operations (video generation)
   - Rate-limited services
   - Example: Media generation with multiple AI providers

4. **State Preservation**
   - Progress tracking needed
   - Resume capability required
   - Audit trail necessary
   - Example: Multi-day research projects

### ❌ Don't Use Multi-Step Orchestration When:

1. Simple, atomic operations (single API call)
2. No error recovery needed
3. Instant execution (< 1 second)
4. No validation or quality gates

## Industry Best Practices (2024-2026)

Based on research from Microsoft Azure, AWS, Prompts.ai, and leading AI orchestration platforms:

### 1. Core Orchestration Patterns

```
┌─────────────────────────────────────────────────────────┐
│ PATTERN 1: Sequential Pipeline                          │
│ Step 1 → Step 2 → Step 3 → Result                       │
│ Use: Ordered dependencies, predictable flow             │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ PATTERN 2: Parallel Fan-out                             │
│         ┌→ Worker 1 ┐                                    │
│ Input → │→ Worker 2 │→ Aggregate → Result               │
│         └→ Worker 3 ┘                                    │
│ Use: Independent operations, speed optimization         │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ PATTERN 3: Orchestrator-Worker                          │
│ Orchestrator → [Worker Pool] → Orchestrator → Result    │
│ Use: Dynamic task allocation, complex coordination      │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ PATTERN 4: Event-Driven Saga                            │
│ Event → Handler → State → Next Event → ...              │
│ Use: Long-running, stateful, async operations           │
└─────────────────────────────────────────────────────────┘
```

### 2. Essential Best Practices

#### Modularity & Decoupling
- Keep each step small and single-purpose
- Use dependency injection for flexibility
- Enable independent testing and optimization

#### Observability & Monitoring
- Log inputs, outputs, and decisions at each step
- Track execution time per step
- Maintain audit trails for accountability

#### Error Handling & Recovery
- Implement retry logic with exponential backoff
- Support graceful degradation
- Provide clear error messages with context
- Enable rollback for failed multi-step operations

#### Idempotency
- Ensure steps can be safely retried
- Prevent duplicate processing
- Use unique identifiers for tracking

#### Security & Compliance
- Validate permissions at each step
- Sanitize inputs and escape outputs
- Maintain transparent decision trails
- Support compliance requirements (logging, access controls)

## Implementation in WordPress/NV oOS

### Example: Deep Research Tool (Reference Implementation)

The `deep_research` tool demonstrates the multi-step orchestration pattern:

```php
/**
 * Step-by-step research orchestration.
 *
 * @param array  $arguments Tool arguments.
 * @param object $context   Execution context.
 * @return array|WP_Error Result or error.
 */
public function execute( $arguments, $context ) {
    // Step 1: Gather information (web search)
    $search_results = $this->gather_information( 
        $topic, 
        $depth, 
        $focus_areas, 
        $context 
    );
    
    if ( is_wp_error( $search_results ) ) {
        return $search_results; // Early exit on error
    }
    
    // Step 2: Analyze findings with AI
    $analysis_result = $this->analyze_findings( 
        $topic, 
        $search_results, 
        $depth,
        $context
    );
    
    if ( is_wp_error( $analysis_result ) ) {
        return $analysis_result; // Early exit on error
    }
    
    // Step 3: Build final research report
    $research_report = $this->build_research_report( 
        $topic, 
        $analysis_result, 
        $search_results 
    );
    
    // Cache result for reuse
    WP_MCP_AI_Cache_Helper::set_cache(
        "deep_research_{$cache_key}",
        $research_report,
        HOUR_IN_SECONDS
    );
    
    return array(
        'success' => true,
        'report'  => $research_report,
        'steps'   => array(
            'gather'  => count( $search_results ),
            'analyze' => strlen( $analysis_result ),
            'report'  => strlen( $research_report ),
        ),
    );
}
```

### Pattern Template for Creation Tools

```php
<?php
/**
 * Multi-Step Creation Tool Template.
 *
 * Use this as a starting point for tools that need orchestration.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Tool_Create_Example implements WP_MCP_AI_Tool_Interface {
    use WP_MCP_AI_Tool_Chat_Response;

    /**
     * Execute multi-step creation workflow.
     *
     * @param array  $arguments Tool arguments.
     * @param object $context   Execution context.
     * @return array|WP_Error Result or error.
     */
    public function execute( $arguments, $context ) {
        // Initialize tracking
        $execution_id = $this->generate_execution_id();
        $this->log_step( $execution_id, 'started', $arguments );
        
        // Step 1: Research & Gather Data
        $this->log_step( $execution_id, 'research', 'Starting research phase' );
        $research_data = $this->step_research( $arguments, $context );
        
        if ( is_wp_error( $research_data ) ) {
            $this->log_step( $execution_id, 'research_failed', $research_data );
            return $this->handle_step_error( 'research', $research_data );
        }
        
        // Step 2: Validate Data Quality
        $this->log_step( $execution_id, 'validate', 'Validating research data' );
        $validation_result = $this->step_validate( $research_data, $arguments );
        
        if ( is_wp_error( $validation_result ) ) {
            $this->log_step( $execution_id, 'validation_failed', $validation_result );
            return $this->handle_step_error( 'validate', $validation_result );
        }
        
        // Step 3: Create Entity
        $this->log_step( $execution_id, 'create', 'Creating entity' );
        $created_entity = $this->step_create( $research_data, $arguments, $context );
        
        if ( is_wp_error( $created_entity ) ) {
            $this->log_step( $execution_id, 'create_failed', $created_entity );
            return $this->handle_step_error( 'create', $created_entity );
        }
        
        // Step 4: Optimize & Enhance (Optional)
        if ( ! empty( $arguments['enhance'] ) ) {
            $this->log_step( $execution_id, 'enhance', 'Enhancing entity' );
            $enhanced_entity = $this->step_enhance( $created_entity, $arguments );
            
            if ( ! is_wp_error( $enhanced_entity ) ) {
                $created_entity = $enhanced_entity;
            }
        }
        
        // Step 5: Finalize
        $this->log_step( $execution_id, 'completed', 'Workflow completed successfully' );
        
        return array(
            'success'      => true,
            'execution_id' => $execution_id,
            'entity'       => $created_entity,
            'steps'        => $this->get_step_summary( $execution_id ),
            'timing'       => $this->get_timing_data( $execution_id ),
        );
    }
    
    /**
     * Step 1: Research & gather data.
     *
     * @param array  $arguments Tool arguments.
     * @param object $context   Execution context.
     * @return array|WP_Error Research data or error.
     */
    protected function step_research( $arguments, $context ) {
        // Implement research logic
        // Example: Call web_search tool, query database, etc.
        
        return array(
            'data' => 'Research results',
        );
    }
    
    /**
     * Step 2: Validate data quality.
     *
     * @param array $research_data Research results.
     * @param array $arguments     Tool arguments.
     * @return array|WP_Error Validation result or error.
     */
    protected function step_validate( $research_data, $arguments ) {
        // Implement validation logic
        // Check required fields, data quality, compliance
        
        $validation_errors = array();
        
        // Example validation
        if ( empty( $research_data['data'] ) ) {
            $validation_errors[] = 'Missing required data';
        }
        
        if ( ! empty( $validation_errors ) ) {
            return new WP_Error(
                'validation_failed',
                'Data validation failed',
                array( 'errors' => $validation_errors )
            );
        }
        
        return array(
            'valid'   => true,
            'quality' => 'high',
        );
    }
    
    /**
     * Step 3: Create entity.
     *
     * @param array  $research_data Research results.
     * @param array  $arguments     Tool arguments.
     * @param object $context       Execution context.
     * @return array|WP_Error Created entity or error.
     */
    protected function step_create( $research_data, $arguments, $context ) {
        // Implement creation logic
        // Example: Create post, product, custom post type
        
        return array(
            'id'    => 123,
            'title' => 'Created Entity',
            'url'   => home_url( '/entity/123' ),
        );
    }
    
    /**
     * Step 4: Enhance entity (optional).
     *
     * @param array $created_entity Created entity data.
     * @param array $arguments      Tool arguments.
     * @return array|WP_Error Enhanced entity or error.
     */
    protected function step_enhance( $created_entity, $arguments ) {
        // Implement enhancement logic
        // Example: Generate images, optimize content, add metadata
        
        return $created_entity; // Return enhanced version
    }
    
    /**
     * Handle step errors with context.
     *
     * @param string   $step_name Step that failed.
     * @param WP_Error $error     Error object.
     * @return WP_Error Enhanced error with rollback info.
     */
    protected function handle_step_error( $step_name, $error ) {
        // Log failure
        do_action( 'wp_mcp_ai_tool_step_failed', $step_name, $error );
        
        // Optionally: Attempt rollback of previous steps
        // $this->rollback_previous_steps( $step_name );
        
        return new WP_Error(
            'orchestration_failed',
            sprintf(
                'Multi-step orchestration failed at step: %s. %s',
                $step_name,
                $error->get_error_message()
            ),
            array(
                'step'          => $step_name,
                'original_code' => $error->get_error_code(),
                'original_data' => $error->get_error_data(),
            )
        );
    }
    
    /**
     * Log orchestration step.
     *
     * @param string $execution_id Unique execution identifier.
     * @param string $step_name    Step name.
     * @param mixed  $data         Step data or message.
     */
    protected function log_step( $execution_id, $step_name, $data ) {
        if ( defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG ) {
            error_log( 
                sprintf(
                    '[WP_MCP_AI] [%s] Step: %s | Data: %s',
                    $execution_id,
                    $step_name,
                    is_string( $data ) ? $data : wp_json_encode( $data )
                )
            );
        }
        
        // Store in transient for progress tracking
        $steps = get_transient( "wp_mcp_ai_execution_{$execution_id}" ) ?: array();
        $steps[] = array(
            'step' => $step_name,
            'time' => current_time( 'mysql' ),
            'data' => $data,
        );
        set_transient( "wp_mcp_ai_execution_{$execution_id}", $steps, HOUR_IN_SECONDS );
    }
    
    /**
     * Generate unique execution ID.
     *
     * @return string Execution ID.
     */
    protected function generate_execution_id() {
        return 'exec_' . wp_generate_uuid4();
    }
    
    /**
     * Get step summary for completed execution.
     *
     * @param string $execution_id Execution ID.
     * @return array Step summary.
     */
    protected function get_step_summary( $execution_id ) {
        $steps = get_transient( "wp_mcp_ai_execution_{$execution_id}" ) ?: array();
        
        return array_map(
            function( $step ) {
                return array(
                    'name' => $step['step'],
                    'time' => $step['time'],
                );
            },
            $steps
        );
    }
    
    /**
     * Get timing data for execution.
     *
     * @param string $execution_id Execution ID.
     * @return array Timing data.
     */
    protected function get_timing_data( $execution_id ) {
        $steps = get_transient( "wp_mcp_ai_execution_{$execution_id}" ) ?: array();
        
        if ( empty( $steps ) ) {
            return array();
        }
        
        $start_time = strtotime( $steps[0]['time'] );
        $end_time   = strtotime( $steps[ count( $steps ) - 1 ]['time'] );
        
        return array(
            'total_seconds' => $end_time - $start_time,
            'steps_count'   => count( $steps ),
        );
    }
}
```

## Tools Recommended for Enhancement

Based on the analysis, these tools would benefit from multi-step orchestration:

### High Priority (Complex Creation Workflows)

1. **Product Creation Suite**
   - `create_woo_product` → Research → Validate → Create → Optimize (SEO, images)
   - `scrape_product` → Scrape → Validate → Import → Categorize

2. **Content Creation Suite**
   - `create_post` → Research → Draft → Enhance (SEO, accessibility) → Publish
   - `save_post` → Validate → Save → Optimize → Cache purge

3. **Media Generation Suite**
   - `generate_openai_image` → Generate → Validate → Optimize → Store
   - `generate_sora_video` → Generate → Process → Validate → Store (already async)

### Medium Priority (Quality Gates Needed)

4. **Assistant Creation**
   - `create_assistant` → Validate config → Create → Test → Deploy

5. **Batch Operations**
   - `create_batch` → Validate items → Process batch → Report results

6. **Newsletter Suite**
   - `newsletter_create_email` → Research → Draft → Validate (deliverability) → Schedule

### Low Priority (Simple Operations)

- Single-step tools that don't need orchestration
- Tools already working well without multi-step coordination

## Background Execution Support

For long-running orchestration (> 30 seconds), use WordPress cron:

```php
// In tool's execute() method
if ( 'background' === $run_mode ) {
    // Schedule background execution
    $job_id = $this->schedule_background_execution( $arguments, $context );
    
    return array(
        'success'    => true,
        'job_id'     => $job_id,
        'status'     => 'scheduled',
        'message'    => 'Orchestration scheduled for background execution',
        'check_tool' => 'get_cron_job', // Tool to check status
    );
}

// Continue with immediate execution
```

## Testing Multi-Step Orchestration

### Unit Tests

Test each step independently:

```php
public function test_step_research() {
    $tool = new WP_MCP_AI_Tool_Create_Example();
    
    $arguments = array(
        'topic' => 'Test Topic',
    );
    
    $result = $tool->step_research( $arguments, new stdClass() );
    
    $this->assertIsArray( $result );
    $this->assertArrayHasKey( 'data', $result );
}
```

### Integration Tests

Test the full workflow:

```php
public function test_full_orchestration_workflow() {
    $tool = new WP_MCP_AI_Tool_Create_Example();
    
    $arguments = array(
        'topic'   => 'Test Topic',
        'enhance' => true,
    );
    
    $result = $tool->execute( $arguments, new stdClass() );
    
    $this->assertIsArray( $result );
    $this->assertTrue( $result['success'] );
    $this->assertArrayHasKey( 'execution_id', $result );
    $this->assertArrayHasKey( 'steps', $result );
    $this->assertGreaterThan( 3, count( $result['steps'] ) );
}
```

### Error Recovery Tests

Test failure scenarios:

```php
public function test_step_failure_handling() {
    $tool = new WP_MCP_AI_Tool_Create_Example();
    
    // Mock a failure in step 2
    add_filter( 'wp_mcp_ai_force_validation_failure', '__return_true' );
    
    $result = $tool->execute( array(), new stdClass() );
    
    $this->assertWPError( $result );
    $this->assertEquals( 'orchestration_failed', $result->get_error_code() );
}
```

## Performance Considerations

### Caching Strategy

```php
// Cache expensive research steps
$cache_key = "research_{$topic}_" . md5( wp_json_encode( $arguments ) );
$cached    = WP_MCP_AI_Cache_Helper::get_cache( $cache_key );

if ( false !== $cached ) {
    return $cached; // Skip expensive operation
}

// Perform research
$result = $this->perform_research( $topic, $arguments );

// Cache for 1 hour
WP_MCP_AI_Cache_Helper::set_cache( $cache_key, $result, HOUR_IN_SECONDS );
```

### Parallel Execution

For independent steps, use parallel execution:

```php
// Example: Generate multiple images in parallel
$image_jobs = array();
foreach ( $prompts as $prompt ) {
    $image_jobs[] = $this->schedule_image_generation( $prompt );
}

// Wait for all jobs to complete
$images = $this->wait_for_jobs( $image_jobs );
```

### Timeout Handling

```php
// Set reasonable timeouts per step
set_time_limit( 120 ); // 2 minutes per step

// Use transients for long-running operations
if ( time() - $start_time > 90 ) {
    // Near timeout, save state and return partial result
    $this->save_partial_state( $execution_id, $partial_data );
    
    return array(
        'success' => false,
        'partial' => true,
        'resume'  => $execution_id,
    );
}
```

## Migration Path

### Enhancing Existing Tools

1. **Maintain Backward Compatibility**
   - Keep existing behavior as default
   - Add `orchestration_mode` parameter (default: false)
   - Enhanced mode opt-in via settings or argument

2. **Gradual Enhancement**
   - Phase 1: Add logging and tracking
   - Phase 2: Add validation steps
   - Phase 3: Add error recovery
   - Phase 4: Add background execution

3. **Feature Flags**
   ```php
   if ( defined( 'WP_MCP_AI_ENABLE_ORCHESTRATION' ) && WP_MCP_AI_ENABLE_ORCHESTRATION ) {
       // Use enhanced orchestration
   } else {
       // Use legacy single-step
   }
   ```

## References

### Industry Standards (2024-2026)

1. **Microsoft Azure** - AI Agent Orchestration Patterns
   - https://learn.microsoft.com/en-us/azure/architecture/ai-ml/guide/ai-agent-design-patterns

2. **AWS Prescriptive Guidance** - Multi-stage AI Workflows
   - https://docs.aws.amazon.com/prescriptive-guidance/latest/agentic-ai-serverless/

3. **Prompts.ai** - AI Model Orchestration Workflows
   - Best practices for cost reduction and performance optimization

4. **Deepchecks** - Multi-Step LLM Chains
   - Orchestrating complex workflows with observability

5. **Collabnix** - Multi-Agent Orchestration Patterns (2024)
   - Centralized, decentralized, and Kubernetes-based strategies

### Internal References

- PR #3691: Research page enhancements (creation pattern foundation)
- `class-wp-mcp-ai-tool-deep-research.php`: Reference implementation
- `class-wp-mcp-ai-tool-create-agent-team.php`: Multi-agent orchestration
- `src/workflow-builder/`: Visual workflow orchestration system

## Support & Questions

For questions about implementing multi-step orchestration:
1. Review existing implementations (`deep_research`, `create_agent_team`)
2. Check workflow builder patterns (`src/workflow-builder/`)
3. Refer to industry best practices linked above
4. Open a discussion in GitHub issues

---

**Document Version**: 1.0  
**Status**: Living Document  
**Next Review**: Q3 2026
