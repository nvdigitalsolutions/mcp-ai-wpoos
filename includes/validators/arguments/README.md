# Arguments

## Purpose

Contains the 23 argument DTO classes — one per validated tool — that declare Symfony Validator constraints (via PHP 8.0+ attributes) on LLM-supplied or REST-supplied tool arguments, transforming untrusted associative arrays into typed, constraint-checked value objects before they reach `execute()`.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ runtime (folder is inert on PHP < 8.0; validated-tool branch is gated to PHP 8.0+ for Symfony attribute mapping) |
| **Loaded by** | `includes/validators/validated-tools-init.php` wires validated tools that each reference one argument class |
| **Optional dependencies** | `symfony/validator`, `symfony/translation-contracts` (Composer); validated-tool branch gracefully no-ops when absent |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `CreateAssistantArguments` | `class-create-assistant-arguments.php` | `WP_MCP_AI_Validated_Tool` → `create_assistant_validated` tool |
| `CreateChartArguments` | `class-create-chart-arguments.php` | matched validated tool |
| `CreateCronJobArguments` | `class-create-cron-job-arguments.php` | matched validated tool |
| `CreatePostArguments` | `class-create-post-arguments.php` | `create_post_validated` tool |
| `CreateWooProductArguments` | `class-create-woo-product-arguments.php` | matched validated tool |
| `EditGeminiImageArguments` | `class-edit-gemini-image-arguments.php` | matched validated tool |
| `GenerateGeminiImageArguments` | `class-generate-gemini-image-arguments.php` | matched validated tool |
| `GenerateImageAltTextArguments` | `class-generate-image-alt-text-arguments.php` | matched validated tool |
| `GenerateImageCaptionArguments` | `class-generate-image-caption-arguments.php` | matched validated tool |
| `GenerateMusicArguments` | `class-generate-music-arguments.php` | matched validated tool |
| `GenerateOpenAIImageArguments` | `class-generate-openai-image-arguments.php` | matched validated tool |
| `GenerateOpenAISpeechArguments` | `class-generate-openai-speech-arguments.php` | matched validated tool |
| `GenerateVeoVideoArguments` | `class-generate-veo-video-arguments.php` | matched validated tool |
| `GetRecentPostsArguments` | `class-get-recent-posts-arguments.php` | matched validated tool |
| `GetSystemLogsArguments` | `class-get-system-logs-arguments.php` | matched validated tool |
| `GetUserInfoArguments` | `class-get-user-info-arguments.php` | matched validated tool |
| `RunCrawl4AIJobArguments` | `class-run-crawl4ai-job-arguments.php` | matched validated tool |
| `SavePostArguments` | `class-save-post-arguments.php` | `save_post_validated` tool |
| `ScrapeProductArguments` | `class-scrape-product-arguments.php` | matched validated tool |
| `SearchContentArguments` | `class-search-content-arguments.php` | matched validated tool |
| `SendGroupEmailArguments` | `class-send-group-email-arguments.php` | matched validated tool |
| `TranscribeOpenAIAudioArguments` | `class-transcribe-openai-audio-arguments.php` | matched validated tool |
| `WebSearchArguments` | `class-web-search-arguments.php` | matched validated tool |

## Inputs / Outputs / Neighbors

- **Reads from:** the raw `$arguments` array passed to a validated tool's `execute()`; Symfony validator metadata from PHP 8.0+ attributes.
- **Writes to:** nothing persistent — emits a typed argument object or a `WP_Error` whose `data` carries the `ConstraintViolationListInterface` summary.
- **Upstream callers:** `includes/tools/` (every `*_validated.php` tool), `includes/rest/` (REST validator may delegate for shared schemas).
- **Downstream collaborators:** `symfony/validator` (vendored), custom constraints in `../constraints/`.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- One argument class per validated tool. Class name: `{ToolName}Arguments`.
- All classes live in the `WP_MCP_AI\Tools\Arguments` namespace.
- Argument classes are plain DTOs — public properties with `#[Assert\*]` attributes, no business logic, no WordPress side effects.
- Constraints include Symfony built-ins (`NotBlank`, `Length`, `Type`, `Choice`, `Count`, `All`, `Positive`, `Regex`, `Email`) plus custom constraints from `../constraints/` (`WPCapability`, `WPPostExists`).
- The validated-tool branch is PHP 8.0+ only; argument files are never loaded on PHP < 8.0 because `validated-tools-init.php` early-returns.
- Validation complements the entry-side two-gate sanitisation rule — it does not substitute for it.

## Tests

```bash
vendor/bin/phpunit tests/test-validator-service.php
vendor/bin/phpunit tests/test-create-post-validated-tool.php
vendor/bin/phpunit tests/test-save-post-validated-tool.php
# Plus one tests/test-*-validated-tool.php per validated tool
```

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — validation is not a replacement for sanitisation/escaping (always)
- [`.context/tool-registry.md`](../../../.context/tool-registry.md) — canonical tool return envelope
- Parent folder: [`includes/validators/README.md`](../README.md) — full validators layer overview

## See Also

- Upstream parent: [`includes/validators/`](../) — validators layer
- Custom constraints: [`includes/validators/constraints/`](../constraints/) — `WPCapability`, `WPPostExists`
- Validated tools: [`includes/tools/`](../../tools/) — each `*_validated.php` file consumes one argument class
- Validator service: [`includes/validators/class-wp-mcp-ai-validator-service.php`](../class-wp-mcp-ai-validator-service.php)
