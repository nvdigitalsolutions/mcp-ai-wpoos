# TC-ADMIN-001: Settings Page — Tab Navigation & Persistence

| Field              | Value                                                          |
|--------------------|----------------------------------------------------------------|
| **Test Case ID**   | `TC-ADMIN-001`                                                 |
| **Feature**        | Admin Dashboard — Settings Page Tabs & Data Persistence        |
| **Priority**       | P1 (High)                                                      |
| **Type**           | Functional + UI                                                |
| **Preconditions**  | - NV oOS plugin v1.1.26+ activated                             |
|                    | - Docker QA env running at `http://localhost:8000`             |
|                    | - Logged in as `admin`                                         |
| **Test Data**      | Settings page URL: `/wp-admin/admin.php?page=wp-mcp-ai-settings` |

## Steps

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to NV oOS settings page | Page loads with tab navigation visible |
| 2 | Click each visible tab in sequence | Each tab activates and shows its content without page reload |
| 3 | Navigate to "Providers" tab | Provider subtabs are visible (OpenAI, Google, Anthropic, etc.) |
| 4 | Toggle an API key input visibility | Click "Show" to reveal; "Hide" to mask |
| 5 | Enter a test value in a text field (e.g., "test-value-123") | Value appears in the field |
| 6 | Click "Save Changes" | Success notice appears: "Settings saved" |
| 7 | Refresh the page | Previously entered value "test-value-123" is still present |
| 8 | Navigate to a different tab, then back to the original tab | Value persists across tab navigation |

## Postconditions
- Settings saved to WordPress options table
- No PHP errors in `wp-content/debug.log`

## Automation Readiness
- [ ] Playwright locators: `[data-testid="settings-tab-*"]`, `.nav-tab-wrapper .nav-tab`
- [ ] Playwright locators: `#submit` (Save button), `.notice-success`
- [ ] Playwright: `page.waitForSelector('.notice-success')` after save
- [ ] Visual checkpoint: Screenshot of each tab's content
