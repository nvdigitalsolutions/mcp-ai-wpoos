# TC-TOOLS-001: Tool Execution — Canonical Envelope Format

| Field              | Value                                                          |
|--------------------|----------------------------------------------------------------|
| **Test Case ID**   | `TC-TOOLS-001`                                                 |
| **Feature**        | Tool Execution — Canonical Return Envelope & Sanitisation      |
| **Priority**       | P0 (Critical)                                                  |
| **Type**           | Functional + Security                                          |
| **Preconditions**  | - NV oOS plugin v1.1.26+ activated                             |
|                    | - Docker QA env running at `http://localhost:8000`             |
|                    | - Logged in admin with valid nonce                             |
| **Test Data**      | Tool endpoint: `POST /wp-json/mcp-ai/v1/tools/run`            |

## Steps

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | POST to `/wp-json/mcp-ai/v1/tools/list` with valid nonce | Response 200; JSON array of all registered tools |
| 2 | Verify each tool has required fields | Every tool object has `slug`, `name`, `description`, `parameters` (JSON Schema) |
| 3 | POST to `/wp-json/mcp-ai/v1/tools/run` with `tool_slug=get_recent_posts` and `arguments={}` (no nonce) | Response 401 (Unauthorized) |
| 4 | POST with valid nonce but insufficient capabilities (subscriber role) | Response 403 (Forbidden) or WP_Error |
| 5 | POST with valid nonce + admin role + valid args | Response 200; response body is success array (never `success: false`) |
| 6 | POST with valid nonce + admin role + malicious `arguments` (XSS payload) | Response 200 but output is escaped; no raw HTML in response |
| 7 | Verify response structure follows canonical envelope | Response is `array(...)` for success or `WP_Error` object; never `array('success' => false)` |

## Postconditions
- No security bypass executed
- All responses properly formatted
- No PHP errors in `wp-content/debug.log`

## Automation Readiness
- [ ] Playwright API testing via `request.fetch()`
- [ ] JSON Schema validation of response structure
- [ ] XSS payload injection test
- [ ] Role-level testing (admin, editor, subscriber)
