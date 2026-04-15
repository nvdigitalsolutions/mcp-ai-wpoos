# NV oOS (Open Operator System) — Claude Code Context

> **This file is loaded every turn by Claude Code.** Keep it focused and actionable.
> Last reviewed: April 2026.

## What This Is

NV oOS is a **WordPress plugin** providing an AI Assistant framework with 519+ tools, MCP protocol support, multi-provider AI (OpenAI, Gemini, Ollama), and Server-Sent Events streaming.

## PHP Compatibility — Critical

| Distribution | Minimum PHP | Location |
|-------------|-------------|----------|
| **Base plugin** (`includes/`, root `*.php`) | **PHP 7.4+** | WordPress.org compatible |
| **Pro addon** (`addons/pro/`) | **PHP 8.1+** | Enums, fibers, readonly, named args OK |

**Base plugin:** No enums, no `readonly`, no union types, no named arguments, no match expressions.

## Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| PHP Classes | `WP_MCP_AI_{Feature}_{Component}` | `WP_MCP_AI_Tool_Manage_Redirects` |
| Tool Classes | `WP_MCP_AI_Tool_{Name}` | `WP_MCP_AI_Tool_Web_Search` |
| Functions | `wp_mcp_ai_{name}()` | `wp_mcp_ai_get_assistant()` |
| Action Hooks | `wp_mcp_ai_{name}` | `wp_mcp_ai_before_tool_execution` |
| Filter Hooks | `wp_mcp_ai_{name}` | `wp_mcp_ai_tool_output` |
| Options | `wp_mcp_ai_{name}` | `wp_mcp_ai_settings` |
| CPT Slugs | `mcp_ai_{type}` | `mcp_ai_assistant` |
| Nonces | `wp_mcp_ai_{context}_{action}` | `wp_mcp_ai_assistant_save` |

## File Structure

```
mcp-ai-wpoos.php                        ← Plugin entry point
mcp-ai-wpoos-base.php                   ← Base-only entry point
includes/
├── bootstrap/                          ← Boot: constants → autoload → hooks → loader
├── class-wp-mcp-ai-plugin.php          ← Main singleton + DI container
├── class-wp-mcp-ai-rest.php            ← Core REST API + agentic loop
├── class-wp-mcp-ai-tool-registry.php   ← Tool registry singleton (519+ tools)
├── tools/                              ← 165 base tool implementations
├── services/                           ← 20+ service classes
├── admin/                              ← WordPress admin UI
├── slash-commands/                     ← /help, /ship, /compact, /context, etc.
├── integrations/                       ← JetEngine, Elementor, Auth0
├── a2a/                                ← Agent-to-Agent protocol
└── interfaces/                         ← PHP interfaces
addons/pro/
├── mcp-ai-wpoos-pro.php                ← Pro entry (auto-loaded, no WP plugin header)
└── includes/
    ├── tools/                          ← 348+ pro tools
    └── ...                             ← Pro admin, REST, services
```

## Security — Non-Negotiable

Every code change must:
- **Sanitize input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_kses_post()`
- **Escape output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- **Check capabilities**: `current_user_can()` before every privileged operation
- **Verify nonces**: `check_ajax_referer()` or `wp_verify_nonce()` for state changes
- **ABSPATH guard**: Every non-root PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **Prepared queries**: Always `$wpdb->prepare()` — never string-concatenate SQL

## Tool Implementation Pattern

```php
class WP_MCP_AI_Tool_Example extends WP_MCP_AI_Tool_Base {
    public function get_slug() { return 'example_tool'; }
    public function get_definition() {
        return array(
            'name'                => 'Example Tool',
            'description'         => 'LLM-facing description.',
            'required_capability' => 'edit_posts',
            'parameters'          => array(
                'type'       => 'object',
                'properties' => array( /* ... */ ),
                'required'   => array( 'action' ),
            ),
        );
    }
    public function execute( $arguments, $context ) {
        if ( ! current_user_can( $this->get_required_capability() ) ) {
            return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
        }
        // Sanitize, implement, return array or WP_Error
    }
}
```

## Tool Return Format

```php
// Success:
return array( 'success' => true, 'message' => __( 'Done.', 'mcp-ai-wpoos' ), 'data' => $results );
// Error:
return new WP_Error( 'error_code', __( 'Error message.', 'mcp-ai-wpoos' ) );
```

## Base vs Pro Decision

- **Base:** Core WordPress functionality, no third-party APIs, useful to any site
- **Pro:** Paid APIs (Shopify, Upwork), optional plugins (JetEngine, WooCommerce), healthcare, enterprise
- **Constants:** `WP_MCP_AI_BASE_VERSION = true` (165 tools) or `false` (all 519+)
- **Guard:** `if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) { /* pro code */ }`

## Key Architecture Patterns

### Lifecycle Hooks (60+)

The plugin fires 60+ hooks. Key ones:
- `wp_mcp_ai_before_tool_execution` / `wp_mcp_ai_after_tool_execution` — tool lifecycle
- `wp_mcp_ai_before_chat_request` / `wp_mcp_ai_after_chat_response` — chat lifecycle
- `wp_mcp_ai_register_tools` — tool registration
- `wp_mcp_ai_cost_calculated` — token cost tracking
- `wp_mcp_ai_slash_commands_initialized` — slash command system ready

Full reference: `docs/hooks-reference.md`

### Agentic Loop (REST API)

In `class-wp-mcp-ai-rest.php` (lines ~2578-2950):
- Iterates tool calls from AI response
- Executes tools sequentially
- Validates TPM budget between iterations
- Handles async pending results
- Strips orphaned tool calls before response
- Filterable max iterations: `wp_mcp_ai_max_agentic_iterations`

### Tool Registry

- Singleton: `WP_MCP_AI_Tool_Registry::get_instance()`
- Hook-based: `do_action( 'wp_mcp_ai_register_tools', $registry )`
- Optional interfaces: `WP_MCP_AI_Tool_Capability_Flags_Interface` (read-only, write, async, etc.)
- Capability flags: `'read-only'`, `'write'`, `'state-changing'`, `'cacheable'`, `'external-api'`

### Slash Commands

Pattern: class with `execute( $args, $flags, $context )` returning string/array/WP_Error.
Registration via `$handler->register( 'name', array( 'handler' => ..., 'capability' => ..., 'aliases' => ... ) )`.
Located in `includes/slash-commands/commands/`.

### SSE Streaming

RFC 6202-compliant: `STREAMING_CHUNK_SIZE = 50`, `RETRY_INTERVAL_MS = 3000`.
Client can close connection to interrupt. Job cancellation supported.

## Build & Test Commands

```bash
# Before every PR:
composer run lint:base && composer run test

# Full CI check:
composer run ci:all && npm run build

# Quick checks:
composer run lint          # PHPCS full codebase
composer run format        # PHPCBF auto-fix
composer run lint:compat   # PHP 7.4-8.3 compatibility
npm run lint:js            # ESLint
npm test                   # Jest
```

## Commit Convention

```
feat(scope): brief description
fix(scope): brief description
docs(scope): brief description
test(scope): brief description
```

## Context Engineering Files

| File | When to Load |
|------|-------------|
| `.context/conventions.md` | Always — naming, style, PHP compat |
| `.context/security-checklist.md` | Always — security requirements |
| `.context/tool-registry.md` | Working on tools |
| `.context/rest-api.md` | Working on REST endpoints |
| `.context/chat-ui.md` | Working on frontend chat |
| `.context/testing.md` | Writing PHPUnit tests |
| `.context/pro-vs-base.md` | Base vs Pro decisions |
| `docs/hooks-reference.md` | Working with plugin hooks |

## OpenAI Schema Compatibility

- OpenAI rejects `'mixed'` type and multi-type arrays `type:['string','number']` — use `anyOf` instead
- Array types **must** include `'items'`
- The `sanitize_parameters_for_openai()` method provides a safety net but tools should declare correct schemas

## Guest Tool Permissions

Guest execution requires `guest_request` flag in tool context. Pattern:
```php
if ( ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] ) ) {
    // Allow guest bypass
}
```

---

## Coding Behavior Guidelines
<!-- Derived from Andrej Karpathy's observations on LLM coding pitfalls — https://github.com/forrestchang/andrej-karpathy-skills -->

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.
