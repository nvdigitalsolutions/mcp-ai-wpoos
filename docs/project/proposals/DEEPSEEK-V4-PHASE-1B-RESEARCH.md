# Phase 1B Implementation Plan - Research Summary

**Date:** January 17, 2026  
**Research Duration:** 3 web searches (multi-agent orchestration, WordPress database, workflow patterns)  
**Implementation Time:** 7 hours (4h Service Layer + 3h Seeding)

---

## Research Findings Summary

### 1. Agent Role Assignment Best Practices (2026)

**Sources:** AWS, Microsoft Azure, Databricks, OpenAI, IBM

#### Role Definitions (Industry Standard)
- **Planner**: Decomposes goals → actionable subtasks, sequences steps, adapts based on outcomes
- **Executor**: Performs actual operations (API calls, data processing, direct actions)
- **Critic**: Evaluates outputs against success criteria, validates, detects errors, initiates corrections (QA/reviewer)
- **Specialist**: Handles domain-specific reasoning (legal, code generation, scientific modeling)

#### Automatic Role Assignment Patterns

**By Profession Category:**
| Category | Primary Role | Rationale |
|----------|--------------|-----------|
| Advisory | Planner | Strategic planning, task decomposition |
| Creative (editorial/review) | Critic | Quality validation, content review |
| Technical (execution) | Executor | Tool-based operations, implementation |
| Healthcare (specialist) | Specialist | Domain-specific expertise |
| Legal (specialist) | Specialist | Compliance, regulatory knowledge |
| Financial (specialist) | Specialist | Domain calculations, analysis |

**By Expertise Keywords:**
| Keywords | Assigned Role | Examples |
|----------|---------------|----------|
| "project management", "coordination", "planning" | Planner | Project Manager, Coordinator |
| "editing", "reviewing", "quality", "validation" | Critic | Technical Editor, QA Engineer |
| "development", "analysis", "research" | Executor | Software Developer, Data Scientist |
| "legal", "medical", "financial" | Specialist | Attorney, Physician, Accountant |

**Fallback:** If no clear match → **Generalist** (can do any role with reduced efficiency)

---

### 2. WordPress Database Seeding Best Practices (2026)

**Sources:** WordPress Developer Blog, Reliable Penguin, Database Migration Guides

#### Idempotent Seeding Pattern
```php
// Version-controlled migrations
$current_version = get_option( 'wp_mcp_ai_profession_orchestration_version', '0.0.0' );
$target_version = '1.1.0';

if ( version_compare( $current_version, $target_version, '<' ) ) {
    // Run migration
    seed_orchestration_configs();
    update_option( 'wp_mcp_ai_profession_orchestration_version', $target_version );
}
```

#### Seeding Strategies
1. **On Plugin Activation**: Initial seed for new installations
2. **On Version Upgrade**: Incremental updates for existing installations
3. **Via WP-CLI**: `wp profession seed-orchestration --force`
4. **Batch Processing**: Process 20-50 professions per request to avoid timeouts

#### Best Practices
- ✅ Track version in wp_options
- ✅ Make seeding idempotent (can re-run safely)
- ✅ Log successes and failures
- ✅ Provide rollback mechanism
- ✅ Test on staging first
- ❌ Never run heavy migrations on pageload
- ❌ Avoid mixing schema (DDL) and data (DML) changes

---

### 3. Task Pattern & Decision Criteria Structures (2026)

**Sources:** Google Cloud, Beam AI, Azure, OpenAI, Machine Learning Mastery

#### Standard Workflow Template JSON
```json
{
  "workflow_name": "research_analysis",
  "description": "Multi-source research with validation",
  "steps": [
    {
      "step_id": "1",
      "type": "planning",
      "agent_role": "planner",
      "action": "decompose_research_task",
      "outputs": ["subtask_list"]
    },
    {
      "step_id": "2",
      "type": "execution",
      "agent_role": "executor",
      "action": "gather_data",
      "inputs": ["subtask_list"],
      "outputs": ["raw_data"],
      "tools": ["web_search", "crawl4ai"]
    },
    {
      "step_id": "3",
      "type": "review",
      "agent_role": "critic",
      "action": "validate_quality",
      "inputs": ["raw_data"],
      "outputs": ["validation_report"]
    }
  ],
  "decision_criteria": {
    "max_steps": 10,
    "min_confidence": 0.7,
    "escalation_threshold": 0.5,
    "human_escalation": true
  }
}
```

#### Decision Criteria Patterns
```json
{
  "decision_criteria": {
    // Conditional routing
    "if_dataset_size > 10MB": "escalate_to_specialist",
    "if_confidence < 0.7": "request_human_review",
    "if_tools_unavailable": "use_fallback_method",
    
    // Quality gates
    "min_completeness": 0.85,
    "min_accuracy": 0.80,
    "required_fields": ["title", "content", "sources"],
    
    // Resource limits
    "max_execution_time": 300,  // seconds
    "max_api_calls": 10,
    "max_cost": 1.00  // dollars
  }
}
```

#### Orchestration Rules Structure
```json
{
  "orchestration_rules": {
    // Agent coordination
    "delegation_policy": "delegate_if_confidence_low",
    "collaboration_mode": "sequential",  // or "parallel"
    "result_aggregation": "consensus",   // or "weighted", "hierarchical"
    
    // Error handling
    "retry_strategy": "exponential_backoff",
    "max_retries": 3,
    "fallback_agent": "generalist",
    
    // Communication
    "context_sharing": "full",  // or "minimal", "none"
    "intermediate_feedback": true
  }
}
```

---

## Implementation Plan

### Phase 1B.1: Update Profession Service Layer (4 hours)

#### Add New Service Methods

**File:** `includes/services/class-wp-mcp-ai-profession-service.php`

```php
/**
 * Get profession configured for specific agent role
 * 
 * @param string $profession_slug Profession slug
 * @param string $agent_role Role: planner|executor|critic|specialist
 * @return array|WP_Error Profession with orchestration config
 */
public function get_profession_for_agent_role( $profession_slug, $agent_role ) {
    $profession = $this->get_profession( $profession_slug );
    if ( is_wp_error( $profession ) ) {
        return $profession;
    }
    
    // Add orchestration configuration
    $orchestration = $this->get_orchestration_config( $profession['id'] );
    $profession['orchestration'] = $orchestration;
    
    return $profession;
}

/**
 * Get all professions by agent role
 * 
 * @param string $agent_role Role filter
 * @return array Array of professions
 */
public function get_professions_by_agent_role( $agent_role ) {
    $args = array(
        'post_type'      => 'mcp_ai_profession',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'   => '_wp_mcp_ai_profession_agent_role',
                'value' => $agent_role,
            ),
        ),
    );
    
    $query = new WP_Query( $args );
    return $this->format_professions_from_query( $query );
}

/**
 * Get orchestration configuration for profession
 * 
 * @param int $profession_id Profession post ID
 * @return array Orchestration config
 */
public function get_orchestration_config( $profession_id ) {
    return array(
        'agent_role'              => get_post_meta( $profession_id, '_wp_mcp_ai_profession_agent_role', true ) ?: 'generalist',
        'task_patterns'           => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_task_patterns', true ) ?: '[]', true ),
        'decision_criteria'       => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_decision_criteria', true ) ?: '{}', true ),
        'orchestration_rules'     => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_orchestration_rules', true ) ?: '{}', true ),
        'quality_metrics'         => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_quality_metrics', true ) ?: '{}', true ),
        'tool_execution_order'    => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_tool_execution_order', true ) ?: '[]', true ),
        'confidence_thresholds'   => json_decode( get_post_meta( $profession_id, '_wp_mcp_ai_profession_confidence_thresholds', true ) ?: '{}', true ),
    );
}

/**
 * Update orchestration configuration for profession
 * 
 * @param int   $profession_id Profession post ID
 * @param array $config Orchestration config
 * @return bool Success
 */
public function update_orchestration_config( $profession_id, $config ) {
    $updated = true;
    
    if ( isset( $config['agent_role'] ) ) {
        $updated = $updated && update_post_meta( $profession_id, '_wp_mcp_ai_profession_agent_role', sanitize_key( $config['agent_role'] ) );
    }
    
    if ( isset( $config['task_patterns'] ) ) {
        $updated = $updated && update_post_meta( $profession_id, '_wp_mcp_ai_profession_task_patterns', wp_json_encode( $config['task_patterns'] ) );
    }
    
    // ... (similar for other fields)
    
    return $updated;
}

/**
 * Transform profession for orchestration (includes orchestration metadata)
 * 
 * @param mixed $profession Profession post object, ID, or slug
 * @return array Profession data with orchestration config
 */
public function transform_profession_for_orchestration( $profession ) {
    $base = $this->transform_profession_for_assistant( $profession );
    $orchestration = $this->get_orchestration_config( $base['id'] );
    
    return array_merge( $base, array( 'orchestration' => $orchestration ) );
}
```

**Tasks:**
1. Add 5 new public methods (1.5 hours)
2. Add private helper methods for JSON validation (0.5 hours)
3. Update existing `transform_profession_for_assistant()` to optionally include orchestration (0.5 hours)
4. Add PHPDoc comments (0.5 hours)
5. Write unit tests (1 hour)

---

### Phase 1B.2: Seed Default Orchestration Configs (3 hours)

#### Create Seeder Class

**File:** `includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php`

```php
/**
 * Seeds orchestration configurations for existing professions
 */
class WP_MCP_AI_Profession_Orchestration_Seeder {
    
    /**
     * Assign default agent roles based on profession characteristics
     */
    public function seed_agent_roles() {
        $professions = get_posts( array(
            'post_type'      => 'mcp_ai_profession',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ) );
        
        $seeded = 0;
        foreach ( $professions as $profession ) {
            $role = $this->determine_agent_role( $profession );
            update_post_meta( $profession->ID, '_wp_mcp_ai_profession_agent_role', $role );
            $seeded++;
            
            // Batch processing (avoid timeouts)
            if ( $seeded % 50 === 0 ) {
                wp_cache_flush();
            }
        }
        
        return $seeded;
    }
    
    /**
     * Determine agent role based on profession attributes
     */
    private function determine_agent_role( $profession ) {
        $category = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_category', true );
        $expertise = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_expertise', true ) ?: array();
        $title = strtolower( $profession->post_title );
        
        // Check for planner keywords
        if ( $this->has_keywords( $title, array( 'project manager', 'coordinator', 'planner', 'strategist' ) ) ||
             $this->has_keywords( $expertise, array( 'project management', 'coordination', 'planning' ) ) ) {
            return 'planner';
        }
        
        // Check for critic keywords
        if ( $this->has_keywords( $title, array( 'editor', 'reviewer', 'qa', 'quality', 'validator' ) ) ||
             $this->has_keywords( $expertise, array( 'editing', 'reviewing', 'quality assurance' ) ) ) {
            return 'critic';
        }
        
        // Check for specialist categories
        if ( in_array( $category, array( 'legal', 'healthcare', 'financial' ), true ) ) {
            return 'specialist';
        }
        
        // Default to executor for technical/creative
        if ( in_array( $category, array( 'technical', 'creative' ), true ) ) {
            return 'executor';
        }
        
        // Fallback
        return 'generalist';
    }
    
    /**
     * Seed task patterns for top professions
     */
    public function seed_task_patterns() {
        $patterns = $this->get_default_task_patterns();
        
        foreach ( $patterns as $profession_slug => $pattern ) {
            $profession = get_page_by_path( $profession_slug, OBJECT, 'mcp_ai_profession' );
            if ( $profession ) {
                update_post_meta( $profession->ID, '_wp_mcp_ai_profession_task_patterns', wp_json_encode( $pattern ) );
            }
        }
    }
    
    /**
     * Get default task patterns for common professions
     */
    private function get_default_task_patterns() {
        return array(
            'data_scientist' => array(
                'data_analysis' => array(
                    'steps'         => array( 'get_dataset', 'analyze_data', 'create_chart', 'interpret_results' ),
                    'dependencies'  => array( 'analyze_data' => 'get_dataset', 'create_chart' => 'analyze_data' ),
                    'parallel_safe' => false,
                ),
            ),
            'content_writer' => array(
                'article_writing' => array(
                    'steps'        => array( 'research_topic', 'create_outline', 'write_draft', 'polish' ),
                    'dependencies' => array( 'create_outline' => 'research_topic', 'write_draft' => 'create_outline' ),
                ),
            ),
            // ... more professions
        );
    }
}
```

**Tasks:**
1. Create seeder class with role assignment logic (1 hour)
2. Create default task patterns for top 20 professions (1 hour)
3. Create migration runner with version tracking (0.5 hours)
4. Add WP-CLI command for manual seeding (0.5 hours)

---

## Testing Strategy

### Unit Tests
```php
class Test_Profession_Orchestration extends WP_UnitTestCase {
    public function test_get_professions_by_agent_role() {
        // Create test professions with different roles
        $planner_id = $this->create_profession_with_role( 'planner' );
        $executor_id = $this->create_profession_with_role( 'executor' );
        
        // Query planners
        $planners = $service->get_professions_by_agent_role( 'planner' );
        
        // Assert only planners returned
        $this->assertCount( 1, $planners );
        $this->assertEquals( $planner_id, $planners[0]['id'] );
    }
    
    public function test_role_assignment_algorithm() {
        // Test project manager → planner
        $this->assertEquals( 'planner', determine_agent_role( 'project_manager' ) );
        
        // Test editor → critic
        $this->assertEquals( 'critic', determine_agent_role( 'technical_editor' ) );
        
        // Test data scientist → executor
        $this->assertEquals( 'executor', determine_agent_role( 'data_scientist' ) );
    }
}
```

---

## Success Criteria

### Phase 1B.1: Service Layer
- ✅ 5 new methods added to Profession Service
- ✅ All methods have PHPDoc comments
- ✅ Unit tests pass (95%+ coverage)
- ✅ No breaking changes to existing methods

### Phase 1B.2: Seeding
- ✅ 200+ professions have agent_role assigned
- ✅ Top 20 professions have task_patterns
- ✅ Seeding is idempotent (can re-run safely)
- ✅ WP-CLI command works
- ✅ Version tracking in wp_options

---

## Risk Mitigation

### Risk 1: Performance Impact on Large Sites
**Mitigation:** Batch processing (50 professions per request), wp_cache_flush() between batches

### Risk 2: Invalid JSON in Seeded Data
**Mitigation:** JSON validation before saving, schema validation using JSON Schema library

### Risk 3: Backward Compatibility
**Mitigation:** All new meta fields optional, existing code continues to work, graceful fallbacks

---

## Timeline

| Task | Duration | Dependencies |
|------|----------|--------------|
| Service Layer: Add methods | 2h | None |
| Service Layer: Tests | 1h | Methods complete |
| Service Layer: Documentation | 1h | Methods complete |
| Seeding: Create seeder class | 1h | None |
| Seeding: Default patterns | 1h | Seeder class |
| Seeding: Migration runner | 0.5h | Seeder class |
| Seeding: WP-CLI command | 0.5h | Migration runner |
| **TOTAL** | **7 hours** | |

---

## References

1. **AWS Prescriptive Guidance** - Multi-agent collaboration patterns
2. **Microsoft Azure** - Agent Factory design patterns
3. **Databricks** - Building intelligent AI agents enterprise guide
4. **OpenAI** - Practical guide to building agents
5. **Google Cloud** - Choose design pattern for agentic AI
6. **WordPress Developer Blog** - JSON Schema in WordPress
7. **Reliable Penguin** - WordPress database migrations best practices
8. **Beam AI** - 9 best agentic workflow patterns 2026
9. **Machine Learning Mastery** - 7 must-know agentic AI design patterns

---

**Next Steps:**
1. Review and approve this plan
2. Begin Phase 1B.1 implementation (Service Layer)
3. Test on staging environment
4. Run Phase 1B.2 (Seeding) with version tracking
5. Validate with integration tests
6. Document in user guides

---

**Document Version:** 1.0  
**Last Updated:** January 17, 2026  
**Status:** Ready for Implementation
