# TC-AUTH-001: WordPress Nonce Authentication — REST API Access

| Field              | Value                                                          |
|--------------------|----------------------------------------------------------------|
| **Test Case ID**   | `TC-AUTH-001`                                                  |
| **Feature**        | REST API Authentication — WordPress Nonce                      |
| **Priority**       | P0 (Critical)                                                  |
| **Type**           | Security + Functional                                          |
| **Preconditions**  | - NV oOS plugin v1.1.26+ activated                             |
|                    | - Docker QA env running at `http://localhost:8000`             |
|                    | - Admin user `admin` / `password` exists                       |
| **Test Data**      | Admin credentials, REST endpoint `/wp-json/mcp-ai/v1/assistants` |

## Steps

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Send unauthenticated GET to `/wp-json/mcp-ai/v1/assistants` | Response status 401 (Unauthorized) |
| 2 | Log in to wp-admin at `http://localhost:8000/wp-admin` | Dashboard loads; admin bar visible |
| 3 | Extract `wp_rest` nonce from the page (available in `wpApiSettings.nonce`) | Nonce is a non-empty string |
| 4 | Send GET to `/wp-json/mcp-ai/v1/assistants` with `X-WP-Nonce` header set to the extracted nonce | Response status 200; JSON array of assistants returned |
| 5 | Verify response structure | Each assistant object has `id`, `name`, `description` fields |

## Postconditions
- Authenticated user session remains active
- No security errors in `wp-content/debug.log`

## Automation Readiness
- [ ] Playwright: `request.fetch()` for direct API calls
- [ ] Playwright: `page.evaluate(() => wpApiSettings.nonce)` to extract nonce
- [ ] API assertion: status code checks on response
