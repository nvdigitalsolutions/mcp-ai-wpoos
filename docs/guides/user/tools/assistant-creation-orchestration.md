# Assistant Creation with Orchestration

Learn how to create AI assistants with intelligent multi-step orchestration for better quality, validation, and optimization.

## Quick Start

### Basic Assistant Creation (Legacy Mode)

```php
$result = $tool->execute(array(
    'title'       => 'Tax Assistant',
    'professions' => array('tax_advisor'),
    'regions'     => array('united_states'),
), $context);
```

**Result:** Creates a basic assistant with default settings.

### Orchestrated Assistant Creation (New)

```php
$result = $tool->execute(array(
    'title'                 => 'Tax Assistant',
    'professions'           => array('tax_advisor'),
    'regions'               => array('united_states'),
    'orchestration_mode'    => true,
    'auto_research'         => true,
    'optimize_instructions' => true,
    'optimize_tools'        => true,
    'optimize'              => true,
    'generate_avatar'       => true,
), $context);
```

**Result:** Creates an optimized assistant with:
- Research-enriched context
- Validated parameters
- AI-enhanced instructions
- Smart tool selection
- Auto-generated avatar
- Full optimization

## Orchestration Parameters

### Core Orchestration

**`orchestration_mode`** (boolean, default: `false`)
- Enables the 6-step orchestration workflow
- When `false`, uses legacy single-step creation
- When `true`, enables all orchestration features

### Step Controls

**`auto_research`** (boolean, default: `false`)
- Automatically researches profession and industry best practices
- Uses web_search tool to gather context
- Enriches instructions with current information
- Non-critical: continues with provided data if fails

**`optimize_instructions`** (boolean, default: `false`)
- Uses AI to enhance system prompts
- Improves clarity, structure, and actionability
- Maintains core intent while improving effectiveness
- Fallback to original on errors

**`optimize_tools`** (boolean, default: `false`)
- Intelligently selects appropriate tools
- Adds profession-specific tools automatically
- Includes commonly useful tools (web_search, search_content, etc.)
- Merges with user-provided tools

**`optimize`** (boolean, default: `false`)
- Runs post-creation optimization
- Enables cache purging
- Works with generate_avatar

**`generate_avatar`** (boolean, default: `false`)
- Auto-generates professional avatar/featured image
- Uses OpenAI image generation
- Creates profession-appropriate imagery
- Automatically sets as featured image

## Usage Scenarios

### Scenario 1: Basic Validation Only

Use orchestration for validation without additional enhancements:

```php
$result = $tool->execute(array(
    'title'              => 'Legal Assistant',
    'description'        => 'A helpful legal assistant',
    'orchestration_mode' => true,
    // All enhancement flags default to false
), $context);
```

**Benefits:**
- Comprehensive input validation
- Clear error messages
- Execution tracking for debugging

### Scenario 2: Research-Enhanced Creation

Create assistant with external research:

```php
$result = $tool->execute(array(
    'title'              => 'Restaurant Consultant',
    'professions'        => array('restaurant_consultant'),
    'regions'            => array('united_states', 'canada'),
    'industry_focus'     => 'fine dining',
    'orchestration_mode' => true,
    'auto_research'      => true,
), $context);
```

**Benefits:**
- Context enriched with current best practices
- Instructions informed by industry standards
- Better knowledge base

### Scenario 3: Full Orchestration

Create a fully optimized assistant:

```php
$result = $tool->execute(array(
    'title'                 => 'Financial Advisor - Real Estate',
    'professions'           => array('financial_advisor', 'real_estate_agent'),
    'regions'               => array('united_states'),
    'industry_focus'        => 'commercial real estate',
    'description'           => 'Expert in commercial real estate financial analysis',
    'orchestration_mode'    => true,
    'auto_research'         => true,
    'optimize_instructions' => true,
    'optimize_tools'        => true,
    'optimize'              => true,
    'generate_avatar'       => true,
), $context);
```

**Benefits:**
- Comprehensive validation
- Research-enriched instructions
- AI-optimized system prompts
- Smart tool selection
- Professional avatar
- Full optimization
- Detailed execution tracking

## Response Format

### Legacy Mode Response

```json
{
  "assistant_id": 123,
  "title": "Tax Assistant",
  "status": "draft",
  "edit_link": "https://example.com/wp-admin/post.php?post=123&action=edit",
  "documents": 2,
  "mode": "manual",
  "message": "AI assistant \"Tax Assistant\" created successfully as draft."
}
```

### Orchestrated Mode Response

```json
{
  "assistant_id": 123,
  "title": "Tax Assistant",
  "status": "draft",
  "edit_link": "https://example.com/wp-admin/post.php?post=123&action=edit",
  "documents": 2,
  "mode": "manual",
  "tools_optimized": true,
  "avatar_generated": true,
  "featured_image_id": 456,
  "cache_purged": true,
  "message": "AI assistant \"Tax Assistant\" created successfully as draft.",
  "orchestration": {
    "enabled": true,
    "execution_id": "550e8400-e29b-41d4-a716-446655440000",
    "steps": [
      {
        "step": "started",
        "message": "Orchestration workflow initiated",
        "timestamp": "2026-02-13 01:30:00"
      },
      {
        "step": "research_complete",
        "message": "Context enriched with research",
        "timestamp": "2026-02-13 01:30:02"
      },
      {
        "step": "validation_complete",
        "message": "All validations passed",
        "timestamp": "2026-02-13 01:30:03"
      },
      {
        "step": "instructions_optimized",
        "message": "System prompt enhanced",
        "timestamp": "2026-02-13 01:30:05"
      },
      {
        "step": "creation_complete",
        "message": "Assistant 123 created",
        "timestamp": "2026-02-13 01:30:06"
      },
      {
        "step": "tools_enhanced",
        "message": "Tool selection optimized",
        "timestamp": "2026-02-13 01:30:07"
      },
      {
        "step": "optimization_complete",
        "message": "Post-creation optimization complete",
        "timestamp": "2026-02-13 01:30:10"
      },
      {
        "step": "completed",
        "message": "Orchestration workflow completed successfully",
        "timestamp": "2026-02-13 01:30:10"
      }
    ]
  }
}
```

## Validation Rules

Orchestration mode enforces comprehensive validation:

### Required Fields
- **Title**: Required, max 200 characters

### Optional but Validated
- **Professions**: Maximum 3
- **Regions**: Maximum 2
- **Attachment IDs**: Maximum 20, must be valid attachments
- **System Prompt**: Maximum 32,000 characters
- **Description**: Maximum 5,000 characters
- **Temperature**: Must be between 0 and 2

### Context Requirement
At least ONE of the following must be provided:
- Professions
- Regions
- Description
- System Prompt

## Instruction Optimization

When `optimize_instructions=true`, the AI analyzes and enhances your system prompt:

### Original Prompt
```
You are Tax Assistant, an expert AI assistant with the following professional expertise:

PRIMARY ROLES:
- Tax Advisor

GEOGRAPHIC FOCUS:
- United States
```

### Optimized Prompt
```
You are Tax Assistant, a specialized AI tax advisory assistant focused on United States tax law and regulations.

CORE EXPERTISE:
- Federal and state tax code interpretation
- Tax planning strategies for individuals and businesses
- Tax compliance and documentation requirements
- IRS procedures and filing requirements
- Tax optimization and deduction identification

CAPABILITIES:
- Provide accurate, up-to-date tax guidance based on current US tax law
- Assist with tax planning questions and scenarios
- Explain complex tax concepts in clear, accessible language
- Help identify potential deductions and credits
- Guide users through tax-related decision-making processes

LIMITATIONS:
- Cannot provide personalized tax advice without full context
- Cannot file taxes or access external tax systems
- Recommend consulting with licensed tax professionals for complex situations
```

## Performance Considerations

### Orchestration Overhead

| Step | Additional Time | Cached |
|------|----------------|---------|
| Research | +2-5 seconds | No |
| Validation | +0.1 seconds | No |
| Instruction Optimization | +2-4 seconds | No |
| Creation | Same as legacy | N/A |
| Tool Enhancement | +0.1 seconds | No |
| Avatar Generation | +5-10 seconds | No |

**Total Overhead:** 9-19 seconds for full orchestration

### Optimization Tips

1. **Skip research** for well-known professions:
   ```php
   'auto_research' => false,  // Save 2-5 seconds
   ```

2. **Skip avatar generation** if not needed:
   ```php
   'generate_avatar' => false,  // Save 5-10 seconds
   ```

3. **Use legacy mode** for bulk creation:
   ```php
   'orchestration_mode' => false,  // Fastest
   ```

4. **Enable only needed steps**:
   ```php
   'orchestration_mode'    => true,
   'optimize_instructions' => true,  // Only this step
   // All others default to false
   ```

## Integration with Async Mode

Orchestration works with both synchronous and asynchronous execution:

### Async with Orchestration

```php
$result = $tool->execute(array(
    'title'                 => 'Complex Assistant',
    'orchestration_mode'    => true,
    'auto_research'         => true,
    'optimize_instructions' => true,
    'generate_avatar'       => true,
    'async'                 => true,
    'notify_email'          => 'admin@example.com',
), $context);
```

**Response:**
```json
{
  "job_id": "create_assistant_abc123",
  "status": "scheduled",
  "scheduled_for": "2026-02-13T01:31:00+00:00",
  "message": "Assistant creation has been scheduled. You will be notified when complete."
}
```

## Troubleshooting

### Validation Errors

**Error:** "Validation failed: Title is required"
**Solution:** Ensure `title` parameter is provided

**Error:** "Validation failed: Maximum 3 professions allowed"
**Solution:** Reduce professions array to 3 or fewer items

**Error:** "Validation failed: Must provide at least professions, regions, description, or system_prompt"
**Solution:** Provide at least one context field

### Research Failures

**Symptom:** Research step fails but assistant is still created
**Explanation:** Research is non-critical; orchestration continues with provided data
**Action:** None required; assistant will be created without research enrichment

### Avatar Generation Failures

**Symptom:** Avatar generation fails but assistant is created
**Explanation:** Avatar generation is optional optimization
**Action:** Can manually add featured image later or retry

## Best Practices

1. **Use orchestration for important assistants** where quality matters
2. **Enable research** for professions/industries you're less familiar with
3. **Skip optimization** for bulk creation or testing
4. **Review execution logs** via `orchestration.steps` for debugging
5. **Combine with async** for complex assistants to avoid timeouts
6. **Validate inputs** before calling for better error messages

## Example: Complete Workflow

```php
// 1. Create with full orchestration
$result = $tool->execute(array(
    'title'                 => 'Healthcare Advisor - Telemedicine',
    'professions'           => array('healthcare_advisor'),
    'regions'               => array('united_states'),
    'industry_focus'        => 'telemedicine',
    'description'           => 'Expert in telemedicine regulations and best practices',
    'orchestration_mode'    => true,
    'auto_research'         => true,
    'optimize_instructions' => true,
    'optimize_tools'        => true,
    'optimize'              => true,
    'generate_avatar'       => true,
    'temperature'           => 0.7,
    'provider'              => 'openai',
    'model'                 => 'gpt-4',
), $context);

// 2. Check for errors
if (is_wp_error($result)) {
    $errors = $result->get_error_data();
    // Handle specific validation errors
    foreach ($errors['errors'] as $error) {
        echo "Error: {$error}\n";
    }
    return;
}

// 3. Access assistant data
$assistant_id = $result['assistant_id'];
$execution_id = $result['orchestration']['execution_id'];

// 4. Review orchestration steps
foreach ($result['orchestration']['steps'] as $step) {
    echo "{$step['step']}: {$step['message']}\n";
}

// 5. Verify optimizations
if ($result['tools_optimized']) {
    echo "Tools were optimized\n";
}

if ($result['avatar_generated']) {
    echo "Avatar generated with ID: {$result['featured_image_id']}\n";
}

// 6. Publish or further customize
wp_update_post(array(
    'ID' => $assistant_id,
    'post_status' => 'publish',
));
```

## See Also

- [Multi-Step Orchestration Pattern Guide](../../developer/tool-development/MULTI_STEP_ORCHESTRATION_PATTERN.md)
- [Product Creation Orchestration](./product-creation-orchestration.md)
- [Content Creation Orchestration](./content-creation-orchestration.md)
- [Image Generation Orchestration](./image-generation-orchestration.md)
