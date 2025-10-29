# WP MCP AI Code Review Report

## Summary
- Overall architecture cleanly separates REST handling, tool execution, and vendor integrations, with comprehensive sanitisation for most request paths.
- Identified several areas that require hardening to avoid security or robustness regressions, particularly around the group email tool and external action variable handling.
- Documentation and inline hooks provide good extension points, though some behaviours (for example, token-scoped assistants) would benefit from additional developer guidance.

## Detailed Findings

### 1. User-controlled headers allow email header injection (High)
**File:** `includes/tools/class-wp-mcp-ai-tool-send-group-email.php`

Custom headers passed via the `headers` argument are concatenated directly into the `wp_mail()` header list without stripping newline characters. Attackers with access to the tool (default capability is `publish_posts`) could inject `\r\n` sequences to add or manipulate headers (BCC, Reply-To, etc.), leading to email spoofing or blind carbon-copy exfiltration.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L582-L589】

**Recommendation:** Reject header values containing control characters before appending, or normalise to a safe whitelist (e.g., limit to `Header: value` patterns with `strpos` checks). Consider running each value through `wp_kses_nohtml()` and explicitly disallowing `\r`/`\n`.

### 2. Large attachment parsing risks exhausting memory (Medium)
**File:** `includes/tools/class-wp-mcp-ai-tool-send-group-email.php`

`parse_email_definition_attachment()` reads the entire attachment into memory with `file_get_contents()` but does not enforce a size cap. A sufficiently large CSV/JSON upload would be fully loaded when the tool executes, potentially exhausting memory on modest hosts.【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L348-L351】

**Recommendation:** Check the attachment size via `filesize()` before loading and reject files above a sensible threshold, or stream the file line-by-line instead of loading it entirely into memory.

### 3. Workflow variable keys lose casing during sanitisation (Medium)
**File:** `includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php`

`sanitize_input_variables()` lowercases every variable key via `sanitize_key()`. Many OpenAI workflows and assistants expect camelCase keys; coercing them to lowercase (e.g., `customerId` → `customerid`) breaks input resolution and causes subtle execution failures.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L288-L301】

**Recommendation:** Preserve the original case while still filtering disallowed characters. A lightweight regex (e.g. `preg_replace('/[^A-Za-z0-9_\-]/', '', $key)`) would maintain casing and meet API expectations.

## Additional Observations
- The JetEngine proxy helper copies all cookies into remote requests. While these calls are same-origin, consider documenting the behaviour because it can surprise hosts with strict cookie governance.
- Logging utilities aggressively truncate entries to avoid PHP-FPM limits, which is good, but operators might appreciate a note in the README about where the recent activity data is stored for troubleshooting.
