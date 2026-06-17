# Validators

## Purpose

Provides the Symfony-Validator–backed argument validation layer that sits between LLM-supplied (or REST-supplied) raw arguments and a tool's `execute()` — turning untrusted associative arrays into typed, constraint-checked argument objects.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ runtime, but the validated-tool branch is gated to **PHP 8.0+** because Symfony attribute mapping requires it |
| **Loaded by** | [`includes/validators/validated-tools-init.php`](validated-tools-init.php) hooks `wp_mcp_ai_register_validated_tools()` onto `wp_mcp_ai_register_tools` (priority 15); `WP_MCP_AI_Validated_Tool` lazily resolves `WP_MCP_AI_Validator_Service::get_instance()` on first use |
| **Optional dependencies** | `symfony/validator`, `symfony/translation-contracts` (installed via Composer; absent → folder gracefully no-ops and the non-validated tool sibling stays registered) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service` | `class-wp-mcp-ai-validator-service.php` | `WP_MCP_AI_Validated_Tool`, REST validator, any code wanting a configured Symfony `ValidatorInterface` |
| `WP_MCP_AI_Validated_Tool` (abstract) | `class-wp-mcp-ai-validated-tool.php` | Every `*_validated` tool in [`includes/tools/`](../tools/) |
| `WP_MCP_AI\Validators\WP_MCP_AI_Identity_Translator` | `class-wp-mcp-ai-identity-translator.php` | Internal — supplies a serializable translator so the validator survives transient storage |
| `arguments/Class_*_Arguments` (e.g. `Create_Post_Arguments`, `Web_Search_Arguments`) | `arguments/class-*-arguments.php` | The matching validated tool — one argument class per validated tool |
| `constraints/WP_Capability_Constraint` + `WP_Capability_Validator` | `constraints/class-wp-capability-{constraint,validator}.php` | Argument classes that need a capability assertion against the current user (see [`.context/security-checklist.md`](../../.context/security-checklist.md)) |
| `constraints/WP_Post_Exists_Constraint` + `WP_Post_Exists_Validator` | `constraints/class-wp-post-exists-{constraint,validator}.php` | Argument classes that reference an existing post ID |
| `wp_mcp_ai_register_validated_tools()` | `validated-tools-init.php` | Hooked onto `wp_mcp_ai_register_tools` |

## Inputs / Outputs / Neighbors

- **Reads from:** the raw `$arguments` array passed to a tool's `execute()`; Symfony validator metadata (PHP 8.0+ attributes on argument classes); WordPress capability + post-existence state for custom constraints
- **Writes to:** nothing persistent — emits a typed argument object (or a `WP_Error` whose `data` carries the `ConstraintViolationListInterface` summary). Failed validation is surfaced through the tool's normal `WP_Error` return path.
- **Upstream callers:** [`includes/tools/`](../tools/) (every `*_validated` tool); [`includes/rest/`](../rest/) (`WP_MCP_AI_REST_Validator` may delegate to `WP_MCP_AI_Validator_Service` for shared schemas)
- **Downstream collaborators:** `symfony/validator` (vendored); the WordPress capability + posts API
- **Events fired:** none
- **Events listened to:** `wp_mcp_ai_register_tools` (priority 15) — runs after the base registry so validated tools can replace their non-validated siblings

## Conventions

Folder-specific deltas:

- The validated branch is **PHP 8.0+ only** — `validated-tools-init.php` early-returns on lower runtimes, so the non-validated sibling stays in service.
- One argument class per validated tool, lives in `arguments/`, declares Symfony constraints via PHP 8 attributes (`#[Assert\NotBlank]`, `#[Assert\Length]`, etc.) plus the custom constraints in `constraints/`.
- Argument classes MUST be plain DTOs — no business logic, no WordPress side effects. Translation between raw array and DTO is the only responsibility.
- Custom constraints live in `constraints/` as a pair: a `*_Constraint` (metadata) plus a `*_Validator` (logic). Follow Symfony's pairing convention.
- A `*_validated` tool MUST register the **same slug** as its non-validated sibling; the registry replaces the older instance during `wp_mcp_ai_register_tools`.
- This folder does NOT replace the entry-side sanitisation rule from [`.context/tool-registry.md`](../../.context/tool-registry.md) — validation complements sanitisation; it does not substitute for it.

## Tests

```bash
vendor/bin/phpunit tests/test-validator-service.php
vendor/bin/phpunit tests/test-validator-dependency.php
vendor/bin/phpunit tests/test-create-post-validated-tool.php
vendor/bin/phpunit tests/test-save-post-validated-tool.php
# …plus one tests/test-*-validated-tool.php per validated tool
```

Tests that run on PHP < 8.0 should call `markTestSkipped()` early — the validator service returns `null` there and the validated tools self-deregister.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — validation is not a replacement for sanitisation/escaping (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — canonical tool return envelope, slug swap rules
- [`.context/testing.md`](../../.context/testing.md) — running PHPUnit, PHP-version skips

## See Also

- Sibling surfaces: [`includes/tools/`](../tools/) — every `*_validated.php` tool is a consumer of this folder
- Pro counterpart: validated tools in `addons/pro/includes/tools/` reuse the same `WP_MCP_AI_Validated_Tool` base
- Upstream library: [Symfony Validator documentation](https://symfony.com/doc/current/validation.html)
