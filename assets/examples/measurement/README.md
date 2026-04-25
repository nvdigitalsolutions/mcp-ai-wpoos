# Measurement Examples

This directory ships **reference snippets** for the NV oOS measurement
subsystem. Files here are *not* loaded by the plugin — copy whichever
piece you need into your own theme, mu-plugin, or site-glue plugin.

## Contents

| File | What it shows |
|------|---------------|
| [`example-custom-verifier.php`](example-custom-verifier.php) | Minimal verifier extending `WP_MCP_AI_Verifier_Base` with proper independence-profile declarations. |
| [`example-eval-suite.php`](example-eval-suite.php) | Registering an eval suite via `wp_mcp_ai_register_eval_suites`, with cases that reference both a built-in rubric preset and the custom verifier above. |
| [`example-cli-generator.php`](example-cli-generator.php) | Wiring a generator callable for `wp mcp-ai measurement run <suite>` via `wp_mcp_ai_cli_measurement_generator`. |

## Quick start

1. Copy the three example files into your site-glue plugin (or paste
   them into a single mu-plugin if you prefer).
2. Adjust the suite's `slug`, the cases' inputs/expected values, and
   the generator's provider routing to match your assistant.
3. Run the suite from the command line:

   ```bash
   wp mcp-ai measurement run example-suite
   wp mcp-ai measurement alert-check example-suite --window=10
   ```

4. The persisted run summaries surface in the **Tools → Measurement**
   admin dashboard and in the OTel exporter (when enabled).

## Anti-Goodhart reminders

- Always declare a sensible `disallowed_providers` / `disallowed_models`
  set when your verifier uses an LLM judge — the runner enforces
  independence and refuses to run a verifier that shares provenance
  with the candidate.
- Pair any `pass_rate` you optimise with the matching `error_rate`
  and `abstention_rate`. The stock metric definitions already declare
  their counter metrics; honour those pairings in your dashboards.
- Use `wp mcp-ai measurement alert-check` in CI rather than reading
  pass-rate by eye. Stable thresholds beat human attention every time.
