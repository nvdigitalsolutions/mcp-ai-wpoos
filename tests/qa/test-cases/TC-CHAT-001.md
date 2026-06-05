# TC-CHAT-001: Guest Token — Basic Chat Flow

| Field              | Value                                                          |
|--------------------|----------------------------------------------------------------|
| **Test Case ID**   | `TC-CHAT-001`                                                  |
| **Feature**        | Chat UI — Guest Token Authentication & Basic Conversation      |
| **Priority**       | P0 (Critical)                                                  |
| **Type**           | Functional + UI                                                |
| **Preconditions**  | - NV oOS plugin v1.1.26+ activated                             |
|                    | - Docker QA env running at `http://localhost:8000`             |
|                    | - No user logged in (guest state)                              |
|                    | - Chat shortcode `[wp_mcp_ai_chat]` present on a page          |
| **Test Data**      | Test message: "Hello, what can you do?"                        |

## Steps

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to `http://localhost:8000` | Homepage loads without errors |
| 2 | Locate the chat widget on the page | Chat input field and send button are visible |
| 3 | Type "Hello, what can you do?" into the chat input | Text appears in the input field |
| 4 | Click Send (or press Enter) | Message appears in the chat transcript area; loading indicator shown |
| 5 | Wait for AI response (max 30s) | AI response message appears with text content |
| 6 | Open DevTools → Network tab → filter by `chat` | A POST request to `/wp-json/mcp-ai/v1/chat` returns 200 |
| 7 | Verify guest token header | `X-WP-MCP-AI-Guest` header present in the POST request |
| 8 | Refresh the page | Chat transcript reloads from localStorage and shows previous messages |

## Postconditions
- Guest token generated and stored in localStorage
- Chat transcript (2 messages) visible in the chat UI
- No PHP errors in `wp-content/debug.log`
- No browser console errors related to the plugin

## Automation Readiness
- [ ] Playwright locators identified: `[data-testid="chat-input"]`, `[data-testid="chat-messages"]`, `[data-testid="chat-submit"]`
- [ ] API assertion: `page.waitForResponse(r => r.url().includes('/chat') && r.status() === 200)`
- [ ] Visual checkpoint: Screenshot of chat UI with messages displayed
- [ ] localStorage assertion: `page.evaluate(() => localStorage.getItem('...'))`
