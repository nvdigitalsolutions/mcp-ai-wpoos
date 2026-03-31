# Telegram Mini App Templates

The **NV oOS Pro** addon ships two health-focused Telegram Mini App templates that are registered automatically alongside the default CMS template. Both are fully self-contained HTML pages served by the `/wp-json/mcp-ai/v1/telegram-mini-app` REST endpoint.

---

## Available Templates

| Slug | Class | Primary use-case |
|------|-------|-----------------|
| `health_wellness` | `WP_MCP_AI_TMA_Template_Health_Wellness` | Daily wellness tracking — steps, hydration, sleep, calories, sodium, mood |
| `medical_vitals` | `WP_MCP_AI_TMA_Template_Medical_Vitals` | Treatment-plan monitoring — BP, HR, SpO₂, temperature, glucose, kidney labs |

Activate a template globally via **NV oOS → Settings → Telegram → Mini App Template**, or override it per-connection in the Telegram connection settings.

---

## Health & Wellness Template (`health_wellness`)

### Feature Overview

| Tab | Contents |
|-----|----------|
| **Dashboard** | Streak banner, 2-column KPI grid (steps/water/sleep/calories/sodium/mood), 7-day line chart, donut chart |
| **Log** | Number inputs for all six daily metrics, mood emoji picker (1–5), save to server via `log_health_metrics` |
| **Goals** | 7-day aggregate goal progress bars with kidney-friendly sodium target; achievement badge wall |
| **Coach** | AI chat connected to the configured assistant; pre-seeds today's wellness data as context |

### Data Flow

```
Page load → resolve SERVER_MEMBER_ID (PHP, server-side)
         → read localStorage ("hw_member_id")
         → if member known: hide picker, load data
         → if Telegram: call /validate → get TMA token → re-try if picker still open
         → hwSyncFromServer() → log_health_metrics get_history (90 days)
         → merge into localStorage → re-render KPIs/charts
```

---

## Medical Vitals Template (`medical_vitals`)

### Feature Overview

| Tab | Contents |
|-----|----------|
| **Dashboard** | Most Recent Reading summary card (relative timestamp + colour-coded vital values) + Vitals KPI grid (BP, HR, SpO₂, temp, glucose) + Kidney Health grid (eGFR/CKD stage, creatinine, BUN, K⁺, Na⁺, phosphorus, albumin) + 7-day BP sparkline chart |
| **Log** | Full vitals + kidney lab value form; saves via `log_vital_signs` |
| **Trends** | Selectable date-range (7 / 14 / 30 / 90 days) Chart.js line charts per metric with reference-range shading |
| **Dosage** | Add/edit/delete medication cards; dose, frequency, and instructions stored in localStorage |
| **Doctor** | AI assistant chat; pre-seeds current member vitals as context on first message |
| **Settings** | Switch default member; unit preferences |

### Data Flow

```
Page load → resolve SERVER_MEMBER_ID (PHP, server-side)
         → read localStorage ("mv_member_id")
         → if member known: hide picker, mvRefresh() from cache
         → if Telegram: call /validate → TMA token → re-try if picker open
         → mvSyncFromServer() → log_vital_signs get_history (90 days)
         → merge into localStorage → re-render dashboard
```

---

## Member Selection & Data Access

### Priority Order

When the mini app page loads, the member is resolved in this order:

1. **`localStorage`** — fastest, avoids flicker; used on every subsequent page load.
2. **`SERVER_MEMBER_ID`** — resolved server-side at render time for the current WordPress user via `wp_mcp_ai_get_member_id_by_user_id()`. Skips the picker entirely when the user has a linked member.
3. **Member picker** — shown when neither source resolves a member. The user can select from the list or create a new profile.

### Auto-Select Single Member

When `list_members` returns **exactly one** member, both templates auto-select it without displaying the picker. This covers the common case where a Telegram user creates their first member profile and then re-opens the app.

### Role-Based Visibility (`list_members` tool)

| WordPress role | Members shown |
|---------------|---------------|
| **Subscriber** (`read` only) | Only members the user authored (`post_author = user_id`) |
| **Author / Editor / Admin** (`edit_posts`+) | **All** published `mcp_ai_member` posts site-wide |

This allows care-givers and clinic staff to manage every patient's data while keeping individual patient accounts scoped to their own profiles.

### Server-Side Pre-Selection (`wp_mcp_ai_get_member_id_by_user_id`)

```php
// health-wellness-management-init.php
function wp_mcp_ai_get_member_id_by_user_id( int $user_id ): int
```

- Returns the `mcp_ai_member` post ID authored by `$user_id`.
- Returns `0` for users with `edit_posts` or higher — they get the full picker so they can choose any patient.
- Called by the mini app page controller; result injected as `SERVER_MEMBER_ID` in the rendered HTML.

---

## Authentication Flow

### Inside Telegram

```
1. Page renders for unauthenticated user
   → SERVER_MEMBER_ID = 0 (user not logged in yet)
   → Member picker shows with "Loading…"

2. mvInitSession() / hwInitSession() fires
   → POST /validate with window.Telegram.WebApp.initData
   → Server verifies HMAC-SHA256 signature (bot token)
   → Finds or creates WordPress user for Telegram ID
   → Returns wp_nonce + tma_token (1 h TTL)

3. JS stores nonce + TMA token
   → If picker still open: re-fetches member list with auth
   → Single member → auto-select; multiple → show picker

4. After member selected:
   → Sync from server (log_vital_signs / log_health_metrics get_history)
   → Dashboard populates
```

### Outside Telegram (browser, logged-in WP user)

```
1. Page renders for authenticated WordPress user
   → SERVER_MEMBER_ID = linked member ID (or 0 for editors+)
   → If subscriber + member found: picker skipped, dashboard loads immediately
   → If editor/admin: picker opens with all members visible

2. All REST calls authenticated via X-WP-Nonce header
   → Nonce generated server-side at render time for the current user's session

3. If fetch fails: Retry button shown (no infinite "Loading…")
```

---

## REST Endpoints Used

All tool calls go through the Mini App tools proxy endpoint, which accepts both WordPress nonces and TMA session tokens:

| Endpoint | Purpose |
|----------|---------|
| `POST /mcp-ai/v1/telegram-mini-app/validate` | Verify Telegram initData, log in user, return nonce + TMA token |
| `POST /mcp-ai/v1/telegram-mini-app/tools/execute` | Execute a registered tool (`list_members`, `log_vital_signs`, etc.) |
| `POST /mcp-ai/v1/telegram-mini-app/chat` | AI chat (doctor/coach tabs) with TMA-token auth support |

### Authentication Headers

```javascript
function tmaToolHeaders() {
    var h = { 'Content-Type': 'application/json' };
    if (NONCE)     h['X-WP-Nonce']             = NONCE;
    if (TMA_TOKEN) h['X-WP-MCP-AI-TMA-Token']  = TMA_TOKEN;
    return h;
}
```

---

## Tools Used by Templates

### Health & Wellness

| Tool slug | Action | Purpose |
|-----------|--------|---------|
| `list_members` | — | Populate member picker |
| `create_member` | — | Create new member profile from picker form |
| `log_health_metrics` | `log` | Save daily wellness metrics |
| `log_health_metrics` | `get_history` | Sync 90-day history to localStorage |

### Medical Vitals

| Tool slug | Action | Purpose |
|-----------|--------|---------|
| `list_members` | — | Populate member picker |
| `create_member` | — | Create new member profile from picker form |
| `log_vital_signs` | `log` | Save a vitals reading |
| `log_vital_signs` | `get_history` | Sync 90-day vitals history to localStorage |

---

## Template Registration (Custom Templates)

Register a custom template with the Template Registry:

```php
add_action( 'wp_mcp_ai_register_tma_templates', function( $registry ) {
    $registry->register( new My_Custom_TMA_Template() );
} );
```

Your template class must extend `WP_MCP_AI_Telegram_Mini_App_Template_Base` and implement:

```php
public function get_slug(): string        // e.g. 'my_template'
public function get_name(): string        // Human-readable name for admin dropdown
public function get_description(): string // Brief feature description
public function get_toolkit(): string     // e.g. 'health_wellness'
public function get_icon(): string        // Emoji icon shown in admin
public function get_accent_color(): string // Hex colour for admin preview
public function render_html( array $ctx ): string  // Return <body>…</body> HTML fragment
```

### Context Array (`$ctx`)

| Key | Type | Description |
|-----|------|-------------|
| `site_name` | string | Blog name (already `esc_html`'d) |
| `nonce` | string | `wp_rest` nonce for the current user |
| `tools_url` | string | Base URL for `/tools` endpoint |
| `chat_url` | string | TMA chat endpoint URL |
| `validate_url` | string | `/validate` endpoint URL |
| `assistant_id` | string\|int | Assistant resolved from connection |
| `chart_js_url` | string | Bundled Chart.js URL |
| `markdown_js_url` | string | Bundled TMA markdown renderer URL |
| `member_id` | int | Linked `mcp_ai_member` post ID for the current WP user (0 = none or editor+) |
| `woo_source` | string | `local` or `remote` (WooCommerce templates) |
| `woo_connection_id` | string | Remote site ID (WooCommerce templates) |

---

## Offline-First Architecture

Both health templates follow an **offline-first** pattern:

1. **Write** → save to localStorage immediately, then sync to server via tool in the background.
2. **Read** → render from localStorage instantly, then pull fresh data from server and re-render.
3. **Sync** — `*SyncFromServer()` fetches the last 90 days of history and only fills dates that have **no local data** (server is authoritative for new devices / shared accounts).

This ensures the app feels instant even on slow connections and retains data between Telegram WebView sessions where persistent storage is not guaranteed.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| Picker stuck on "Loading…" | Auth not established; fetch returning 403 | Tap **Retry**; ensure TMA token is returned by `/validate` |
| Dashboard always shows "No readings yet" | No server sync yet or new device | Wait for background sync; check `log_vital_signs` get_history response in browser console |
| Wrong member pre-selected | Another member authored by same user exists | Use "Switch Member" (👤 icon) to choose the correct profile |
| Editor/admin always sees picker | Expected — users above subscriber see the full list | Select the member from the picker as intended |
| Members not showing for subscriber | `list_members` scoped to authored members | Create a member for that WP user account; admin can also `wp post create --post_type=mcp_ai_member --post_author=<uid>` |

---

*See also: [Health & Wellness Enhancement Summary](health-wellness-enhancement-summary.md) · [Health Document Tools](health-wellness-document-tools.md)*
