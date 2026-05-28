# Constraints

## Purpose

Provides the four custom Symfony Validator constraint/validator pairs — WordPress capability assertion and post-existence validation — used by argument DTO classes in `includes/validators/arguments/` to enforce domain-specific validation rules that go beyond Symfony's built-in constraints.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ runtime (inert on PHP < 8.0, same gate as the validated-tool branch) |
| **Loaded by** | `includes/validators/validated-tools-init.php`; constraint classes are discovered via Symfony attribute mapping on argument DTOs |
| **Optional dependencies** | `symfony/validator` (Composer) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WPCapability` (Constraint) | `class-wp-capability-constraint.php` | Argument DTOs needing `current_user_can()` checks (e.g. `CreatePostArguments::$user_id`) |
| `WPCapabilityValidator` | `class-wp-capability-validator.php` | Symfony Validator → resolves `WPCapability` constraints |
| `WPPostExists` (Constraint) | `class-wp-post-exists-constraint.php` | Argument DTOs referencing existing post IDs |
| `WPPostExistsValidator` | `class-wp-post-exists-validator.php` | Symfony Validator → resolves `WPPostExists` constraints |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress `current_user_can()` (capability check), `get_post()` (post existence), optional `post_type` filter.
- **Writes to:** nothing persistent — adds `ConstraintViolation` entries to the Symfony validation context on failure.
- **Upstream callers:** `includes/validators/arguments/` (attribute-decorated DTO properties), Symfony Validator engine.
- **Downstream collaborators:** WordPress capability API, WordPress posts API, Symfony `ConstraintValidator` base class.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- Follows Symfony's constraint/validator pairing convention: one `*Constraint` class (metadata: message template, options) + one `*Validator` class (validation logic).
- All classes live in the `WP_MCP_AI\Validators\Constraints` namespace.
- **`WPCapability`:** Accepts a `capability` string (e.g. `'edit_posts'`). Supports both annotation and PHP 8.0 attribute syntax. Validator calls `current_user_can($constraint->capability)`. Error message: `"User lacks required capability: {{ capability }}"`.
- **`WPPostExists`:** Accepts an optional `post_type` to narrow the check. Validator verifies the post ID exists and (if post_type is set) matches the expected type. Error message: `"Post with ID {{ post_id }} does not exist."`.
- Constraint classes are `#[\Attribute]`-decorated for PHP 8.0+ attribute mapping but support annotation syntax for backward compatibility with Symfony's annotation reader.
- These constraints are only active when the validated-tool branch is running (PHP 8.0+ with `symfony/validator` present).

## Tests

```bash
vendor/bin/phpunit tests/test-validator-service.php
vendor/bin/phpunit tests/test-create-post-validated-tool.php
```

Constraint validation is tested indirectly through validated-tool tests.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — capability checks as a security boundary (always)
- [`.context/tool-registry.md`](../../../.context/tool-registry.md) — tool execution permission model
- Parent folder: [`includes/validators/README.md`](../README.md) — full validators layer overview

## See Also

- Upstream parent: [`includes/validators/`](../) — validators layer
- Arguments: [`includes/validators/arguments/`](../arguments/) — DTO classes that use these constraints
- Validated tools: [`includes/tools/`](../../tools/) — tools that consume the validated argument objects
- Symfony reference: [Custom Validation Constraints](https://symfony.com/doc/current/validation/custom_constraint.html)
