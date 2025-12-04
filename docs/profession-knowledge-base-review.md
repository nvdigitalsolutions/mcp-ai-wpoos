# Profession Knowledge Base Review

## What was reviewed
- Seeded JSON knowledge base in `includes/knowledge-base/professions/*.json`
- Focus on ensuring assistants have clear safety, privacy, and compliance guidance

## Gaps identified
- Cross-profession safety guidance is scattered across individual roles and lacks a centralized set of guardrails
- Privacy handling and data minimization reminders are limited, leaving assistants without explicit prompts to avoid collecting sensitive information
- Escalation guidance for crisis or emergency cues is minimal, creating risk for mishandling safety-critical conversations

## Enhancements added
- Added `includes/knowledge-base/professions/safety-guardrails.json` with two reusable guardrail-focused roles:
  - **Safety & Compliance Advisor**: refusal patterns, escalation steps, and transparent capability limits
  - **Data Privacy Steward**: data minimization, secret handling, and privacy regulation reminders
- Guardrail knowledge bases emphasize refusal language, safer alternatives, and when to direct users to licensed professionals or emergency services

## Safety Guardrails Integration (Completed)
The safety guardrail roles are now automatically threaded into assistant creation to ensure all assistants inherit baseline safety and compliance guidance:

### Implementation Details
1. **Helper Method Added**: `WP_MCP_AI_Assistant_CPT::get_safety_guardrail_profession_ids()`
   - Queries for published professions with category "safety"
   - Returns array of profession post IDs for safety guardrails
   - Located in `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`

2. **Profession Role Presets**: `WP_MCP_AI_Assistant_CPT::get_profession_role_presets()`
   - Defines role presets for all tool categories (content_writing, ecommerce, etc.)
   - Each preset automatically includes safety guardrail profession IDs
   - Extensible via `wp_mcp_ai_profession_role_presets` filter

3. **Automatic Assignment in create_assistant Tool**:
   - When assistants are created via the `create_assistant` tool, safety guardrails are automatically assigned
   - Added to primary_roles meta: `_wp_mcp_ai_primary_roles`
   - Safety roles are prepended to system prompts via `build_prompt_from_primary_roles()` method
   - Changes in `includes/tools/class-wp-mcp-ai-tool-create-assistant.php`

### How It Works
- Safety guardrails are stored as profession posts with category "safety"
- When an assistant is created, the system queries for these safety profession posts
- The profession IDs are saved to the assistant's `_wp_mcp_ai_primary_roles` meta
- When assistant configuration is retrieved, primary roles are built into the system prompt
- All role descriptions, knowledge bases, expertise, and warnings are included in the prompt

### Benefits
- **Consistent Safety**: Every assistant created via tools inherits the same baseline safety guidance
- **Centralized Updates**: Safety policies can be updated in one place (the profession posts)
- **Transparent to Users**: Safety guardrails are visible in the assistant's primary roles
- **Extensible**: Developers can filter presets or add additional safety professions

## Next steps to deepen coverage
- ✅ ~~Thread guardrail roles into preset configurations or templates so assistants inherit the safety defaults automatically~~
- Add more domain-specific safety notes (e.g., child safety, security testing boundaries, content moderation standards)
- Periodically audit new profession entries to ensure they include privacy prompts, escalation language, and disclaimers about licensure
- Consider UI integration: Show safety guardrails in assistant builder interface
- Add tests to verify safety guardrails are properly assigned
