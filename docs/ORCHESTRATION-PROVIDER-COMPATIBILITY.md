# Orchestration Features Provider Compatibility

## Overview

This implementation ensures that orchestration layer settings actually control whether features are applied, and clarifies that all orchestration features work uniformly across all AI providers.

## Question Answered

**"Do these orchestration features work for all providers?"**

**YES** - All orchestration features work uniformly across ALL AI providers:
- ✅ OpenAI
- ✅ Google Gemini
- ✅ Anthropic (Claude)
- ✅ Ollama (local AI)
- ✅ LM Studio (local AI)

## Why Features Are Provider-Agnostic

The orchestration layer operates at a **higher abstraction level** through the `WP_MCP_AI_Resource_Manager`, which all provider clients use for resource limits. The orchestration policies are applied via filters before values reach any specific provider.

### Architecture Flow

```
Settings (enable_budget_management)
    ↓
Orchestration Budget Enforcement Service (applies policy via filters)
    ↓
Resource Manager (provides tier-based values)
    ↓
Provider Clients (OpenAI, Gemini, Anthropic, Ollama, LM Studio)
    ↓
API Requests (with enforced limits)
```

## Orchestration Features

### 1. Dynamic Budget Management (`enable_budget_management`)

**What it does:**
- Automatically allocates token budgets based on server resources
- Adjusts request timeouts based on PHP execution limits
- Prevents resource exhaustion by limiting operation scope

**When enabled (default):**
- Low tier (< 128MB): 1,000 tokens, 30s timeout
- Medium tier (128-512MB): 4,000 tokens, 60s timeout
- High tier (≥ 512MB): 16,000 tokens, 120s timeout

**When disabled:**
- All requests: 128,000 tokens, 120s timeout
- **Important:** The 120s timeout is applied unconditionally, even if it exceeds `max_execution_time`. This removes all budget constraints including PHP execution time caps, allowing long-running AI operations.

**Works for:** All providers

### 2. Predictive Optimization (`enable_predictive_optimization`)

**What it does:**
- Uses historical usage patterns to forecast resource needs
- Prevents resource exhaustion before it occurs
- Provides health status predictions

**Implementation:**
- Checked in `WP_MCP_AI_Orchestration_Health_Service`
- Enables/disables predictive health metrics

**Works for:** All providers

### 3. Capability-Based Tool Gating (`enable_capability_gating`)

**What it does:**
- Enforces WordPress capability checks for tool access
- Ensures only authorized users can invoke specific tools
- Applies role-based permissions at API boundaries

**Implementation:**
- Enforced at REST API level (`WP_MCP_AI_REST`)
- Tool-specific capability requirements checked before execution

**Works for:** All providers

### 4. Cron-Based Task Orchestration (`enable_cron_orchestration`)

**What it does:**
- Allows AI agents to create scheduled background tasks
- Inherits budget constraints from orchestration layer
- Maintains audit trail with user attribution

**When enabled (default):**
- Cron tools (create, list, delete, get) are available
- Scheduled operations validated against resource budgets

**When disabled:**
- All cron tools return error: `wp_mcp_ai_cron_disabled`
- Error message directs users to settings page

**Works for:** All providers

## Implementation Details

### Separation of Concerns

The implementation follows clean architecture principles:

**Resource Manager** (`class-resource-manager.php`)
- Simple utility class
- Detects system resources (memory, execution time)
- Provides tier-based recommendations
- Exposes filter hooks for policy application

**Orchestration Budget Enforcement Service** (`class-wp-mcp-ai-orchestration-budget-enforcement-service.php`)
- Policy enforcement layer
- Hooks into resource manager filters
- Checks orchestration settings
- Modifies values based on enabled/disabled state

**Provider Clients** (OpenAI, Gemini, Anthropic, Ollama, LM Studio)
- Unchanged - use Resource Manager via existing pattern
- Receive enforced limits transparently
- No provider-specific orchestration code

### Filter Architecture

The service uses WordPress filters to apply policies:

```php
// Register filters on service initialization
add_filter( 'wp_mcp_ai_resource_max_tokens', 
    'apply_budget_management_to_max_tokens', 5, 2 );
add_filter( 'wp_mcp_ai_resource_request_timeout', 
    'apply_budget_management_to_timeout', 5, 4 );
```

When budget management is disabled:
- `wp_mcp_ai_resource_max_tokens` → returns 128,000
- `wp_mcp_ai_resource_request_timeout` → returns 120s

When budget management is enabled:
- Filters return original tier-based values
- Existing behavior preserved

## Testing

### Automated Tests

Location: `tests/test-orchestration-settings-enforcement.php`

**Test Coverage:**
1. Budget management controls max tokens
2. Budget management controls timeouts
3. Cron orchestration blocks tools when disabled
4. Cron orchestration allows tools when enabled
5. Helper methods return correct values
6. Orchestration works for all providers (via Resource Manager)

### Manual Testing

**Test Budget Management:**
1. Navigate to Settings → Orchestration Layer → Settings
2. Toggle "Enable Dynamic Budget Management"
3. Make API request and observe token limits in logs
4. Verify high limits when disabled, tier-based when enabled

**Test Cron Orchestration:**
1. Navigate to Settings → Orchestration Layer → Settings
2. Disable "Enable Cron-Based Task Orchestration"
3. Try to create cron job via tool or assistant
4. Verify error message with settings link
5. Re-enable and verify job creation works

## Files Modified

### New Files
- `includes/services/class-wp-mcp-ai-orchestration-budget-enforcement-service.php`
- `tests/test-orchestration-settings-enforcement.php`
- `docs/ORCHESTRATION-PROVIDER-COMPATIBILITY.md` (this file)

### Modified Files
- `includes/services-init.php` - Load and initialize enforcement service
- `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php` - Updated description
- `includes/tools/class-wp-mcp-ai-tool-create-cron-job.php` - Check setting
- `includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php` - Check setting
- `includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php` - Check setting
- `includes/tools/class-wp-mcp-ai-tool-get-cron-job.php` - Check setting

## Backward Compatibility

- All features **enabled by default** (existing behavior preserved)
- No breaking changes to provider clients
- Settings can be toggled without code changes
- Filters allow customization for advanced users

## Future Enhancements

Potential improvements:
1. Per-provider budget overrides
2. Time-based budget windows (hourly/daily limits)
3. User-specific or role-specific budget allocation
4. Real-time budget usage dashboard
5. Automatic budget scaling based on usage patterns

## Related Documentation

- `docs/RESOURCE-MANAGEMENT.md` - Detailed resource management architecture
- `docs/ORCHESTRATION-LAYER-ARCHITECTURE.md` - Complete orchestration layer design
- `docs/orchestration-budget-enforcement.md` - Budget enforcement specifics
