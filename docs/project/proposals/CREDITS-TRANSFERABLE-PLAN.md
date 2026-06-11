# In-Plugin Transferable Credits — Architecture Plan

> **Status:** ⏳ Not implemented (v1.1.29) — Zero credit service infrastructure
> **Date:** 2026-05-11
> **Author:** Copilot Coding Agent
> **Decision gate:** Choose Track A (recommended, ships in weeks) or Track B (requires legal counsel, ~12 months)

---

## Overview

Users should be able to earn, buy, and transfer credits to each other inside the NV oOS plugin ecosystem. Two implementation tracks are documented below:

- **Track A — Off-Chain Closed-Loop Credits (Recommended):** All credit lives in the WordPress DB. Legally a closed-loop promotional credit (exempt from US MTL most states, MiCA Recital 22, and WP.org guidelines). Ships in weeks with zero regulatory overhead.
- **Track B — Full On-Chain Token:** Real ERC-20 on a public chain. Requires legal counsel, MSB/EMI registration, smart-contract audit, KYC/AML integration. ~12 months, $300k–$1M+ minimum.

---

## Track A — Off-Chain Closed-Loop Credits

### Phase 0 — Data Model

Two new database tables created on plugin activation (via `dbDelta`):

**`{prefix}wp_mcp_ai_credits`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | FK to `wp_users` |
| `balance` | `BIGINT NOT NULL DEFAULT 0` | Satoshi-style integer units (e.g. 1 unit = 0.001 display credit) |
| `updated_at` | `DATETIME NOT NULL` | Last balance change |

**`{prefix}wp_mcp_ai_credit_ledger`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `from_user_id` | `BIGINT UNSIGNED NULL` | NULL = system top-up |
| `to_user_id` | `BIGINT UNSIGNED NOT NULL` | Recipient |
| `amount` | `BIGINT NOT NULL` | Units transferred |
| `type` | `ENUM('topup','spend','transfer','expire')` | Transaction type |
| `reference` | `VARCHAR(255) NULL` | Stripe payment ID, transfer nonce, etc. |
| `created_at` | `DATETIME NOT NULL` | Immutable timestamp |

**`wp_mcp_ai_credit_settings` option** (JSON in `wp_options`):

```json
{
  "enabled": true,
  "currency_name": "NVCoins",
  "currency_symbol": "💎",
  "expiry_days": 365,
  "max_balance": 100000,
  "max_daily_transfer": 5000,
  "min_transfer": 10
}
```

---

### Phase 1 — Core Service Class (`WP_MCP_AI_Credit_Service`)

Location: `includes/services/class-wp-mcp-ai-credit-service.php`

**Methods:**

- `get_balance( int $user_id ): int` — reads balance table
- `credit( int $user_id, int $amount, string $type, string $reference = '' ): bool|WP_Error` — atomic DB insert to ledger + balance increment. Fires `wp_mcp_ai_credit_topped_up` or `wp_mcp_ai_credit_spent`.
- `debit( int $user_id, int $amount, string $type, string $reference = '' ): bool|WP_Error` — returns `WP_Error` if insufficient balance. Fires `wp_mcp_ai_credit_spent`.
- `transfer( int $from_user_id, int $to_user_id, int $amount ): bool|WP_Error` — wraps debit + credit in a DB transaction. Enforces `max_daily_transfer` cap. Fires `wp_mcp_ai_credit_transferred`.
- `expire_old_credits(): int` — WP-Cron daily job; marks credits older than `expiry_days` as expired; returns number of users affected. Fires `wp_mcp_ai_credits_expired`.

**Lifecycle hooks fired:**

| Hook | When |
|------|------|
| `wp_mcp_ai_credit_topped_up` | After successful `credit()` with type `topup` |
| `wp_mcp_ai_credit_spent` | After successful `debit()` |
| `wp_mcp_ai_credit_transferred` | After successful `transfer()` |
| `wp_mcp_ai_credits_expired` | After cron expiry run |

---

### Phase 2 — Stripe Top-Up Integration

Location: `includes/services/class-wp-mcp-ai-credit-topup-handler.php`

- Listens for `checkout.session.completed` Stripe webhook event
- Resolves `metadata.user_id` and `metadata.credit_amount` from the session
- Calls `WP_MCP_AI_Credit_Service::credit()` with type `topup`, reference = Stripe payment ID
- Idempotency: stores processed event IDs in a transient (`wp_mcp_ai_stripe_event_{event_id}`) to prevent duplicate crediting
- Admin settings: configurable price-per-credit tiers (e.g. $5 = 500 credits, $25 = 3000 credits)
- ToS checkbox required before purchase: *"Credits are non-refundable promotional service credits with no monetary value"*

---

### Phase 3 — REST API (`/mcp-ai/v1/credits/`)

Location: `includes/class-wp-mcp-ai-rest.php` (new route group) or `includes/rest/class-wp-mcp-ai-credits-rest-controller.php`

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/mcp-ai/v1/credits/balance` | Nonce / Bearer | Returns own balance + last 50 ledger rows (paginated) |
| `GET` | `/mcp-ai/v1/credits/leaderboard` | Public (opt-in) | Top-N balances — admin-toggled |
| `POST` | `/mcp-ai/v1/credits/transfer` | Nonce / Bearer | Body: `{ to_user_id, amount, note }`. Rate-limited: 10 req/min via WP transient. Calls `Credit_Service::transfer()`. |
| `POST` | `/mcp-ai/v1/credits/spend` | Internal only | Called by agentic loop when a tool with `credit_cost` executes |

**Security:**
- All routes require `current_user_can('read')` minimum
- Transfer endpoint additionally validates `to_user_id` is a real, non-deleted user
- Nonce (`X-WP-Nonce`) for same-origin; bearer token for API callers
- `transfer` uses `$wpdb->prepare()` for all queries

---

### Phase 4 — Chat-SPA UI (`addons/chat-spa` extension)

New components (v0.7.0):

| Component | File | Description |
|-----------|------|-------------|
| `CreditBadge` | `src/components/CreditBadge.tsx` | Shows "💎 1,240 NVCoins" in chat header. Polls `GET /balance` on mount + after every AI response. |
| `TransferModal` | `src/components/TransferModal.tsx` | Search by username/email, enter amount, confirm. Calls `POST /mcp-ai/v1/credits/transfer`. |
| `LedgerDrawer` | `src/components/LedgerDrawer.tsx` | Sidebar showing last 50 transactions: type icon + amount + counterpart + date. |

**New API client:** `src/api/credits.ts` — `CreditsClient` wrapping all three REST routes.

---

### Phase 5 — Admin UI

Location: `includes/admin/` (new settings tab)

- **Settings panel:** enable/disable feature, set currency name/symbol, expiry days, transfer caps, price tiers
- **Users list column:** "NVCoins" balance with quick-edit for manual credit/deduct (capability: `manage_options`)
- **Manual credit tool:** admin can top-up or deduct any user's balance from the Users screen
- **Ledger export:** CSV export of full ledger or per-user subset

---

### Phase 6 — Tool Integration (Spend Credits on Premium Tools)

- Add optional `credit_cost` key to tool `get_definition()` array (integer units)
- Agentic loop (`class-wp-mcp-ai-rest.php`) checks balance before executing any tool with `credit_cost > 0`
- Deducts on success; refunds via `credit()` on `WP_Error` return
- Filter: `wp_mcp_ai_tool_credit_cost` — lets admins override per-tool cost at runtime

---

### Phase 7 — Audit Anchor Webhook (Optional)

- Every `transfer` and `topup` event can optionally POST a signed audit payload to an admin-configured BYO webhook URL
- Signing: HMAC-SHA256 (same pattern as `WP_MCP_AI_Pro_Schedule_Manager::fire_webhook_callback()`)
- Headers: `X-WP-MCP-AI-Signature: sha256=<hash>`, `X-WP-MCP-AI-Timestamp: <unix>`
- Receivers should reject timestamps >300s old to prevent replay attacks
- The plugin itself **never** touches a blockchain — the admin's listener can forward to Chainlink, a public chain explorer, or any logging endpoint

---

### Phase 8 — Tests

**PHPUnit:**

| File | Cases |
|------|-------|
| `tests/test-credit-service.php` | Balance read, credit, debit, transfer, daily cap enforcement, expiry cron |
| `tests/test-credit-rest.php` | All 4 REST routes: auth, rate-limit, insufficient balance, transfer cap |
| `tests/test-credit-topup-handler.php` | Stripe webhook replay, idempotency on duplicate event ID |

**Vitest (addons/chat-spa):**

| File | Cases |
|------|-------|
| `src/__tests__/CreditBadge.test.tsx` | Renders balance, refreshes after response |
| `src/__tests__/TransferModal.test.tsx` | User search, amount validation, submit |
| `src/__tests__/LedgerDrawer.test.tsx` | Renders ledger rows, pagination |

---

### Phase 9 — Documentation

- `docs/features/credits.md` — feature overview, currency naming, expiry, transfer limits, admin guide
- Update `docs/rest-api.md` with `/mcp-ai/v1/credits/` routes
- Update `docs/tool-reference.md` with `credit_cost` field documentation
- Update `CHANGELOG.md`

---

## Track B — Full On-Chain Token

> ⚠️ **Pre-condition: legal counsel + MSB/EMI registration required before any user-facing deployment.**

Track B is **additive** on top of Track A. Track A's DB schema and REST API are the correct foundation — Track B adds a thin blockchain bridge layer.

### What changes vs. Track A

| Component | Track A | Track B Delta |
|-----------|---------|---------------|
| Balance store | WordPress DB (authoritative) | DB still authoritative for plugin UX; on-chain is the auditable public record |
| Token contract | None | ERC-20 on an L2 (Base, Polygon, Arbitrum). Deployed via Hardhat/Foundry. Audited. |
| Mint authority | `Credit_Service::credit()` | Additionally calls `mint()` on the contract via RPC sidecar |
| Burn on spend | None | `Credit_Service::debit()` additionally calls `burn()` |
| Transfer | DB only | DB transfer + `safeTransfer()` on-chain; both succeed or both roll back |
| Wallet UX | None | Connect wallet (MetaMask/Coinbase/WalletConnect) or embedded wallet (Privy/Magic) |
| Gas | None | Paymaster (ERC-4337 account abstraction) or user pays |
| Indexer | None | Subgraph or Alchemy/Moralis webhooks to sync on-chain `Transfer` events back to DB |
| KYC/AML | None | Persona/Sumsub vendor; OFAC/EU/UN sanctions screening on every transfer; jurisdiction blocklist |
| Reserves | None | If pegged to USD: 1:1 segregated fiat reserve account (MiCA EMT requirement) |
| Disclosures | ToS clause | Published white paper, risk disclosures, jurisdiction blocklist |
| Audit | None | Smart-contract security audit ($30–80k per round; repeat on every contract change) |
| Legal entity | Current | Likely need a separate legal entity to hold the issuer role |

### Additional PHP modules (Pro addon)

| File | Purpose |
|------|---------|
| `addons/pro/includes/blockchain/class-wp-mcp-ai-token-bridge.php` | Wraps RPC calls (mint/burn/transfer/balanceOf). Configurable RPC URL + contract address + private-key reference (from env, never DB). |
| `addons/pro/includes/blockchain/class-wp-mcp-ai-token-indexer-webhook.php` | Receives Alchemy/Moralis webhook on `Transfer` events; reconciles DB balance. |
| `addons/pro/includes/compliance/class-wp-mcp-ai-kyc-gateway.php` | Wraps Persona/Sumsub SDK; stores `kyc_status` in user meta; gates `transfer` REST route. |
| `addons/pro/includes/compliance/class-wp-mcp-ai-sanctions-screener.php` | OFAC/EU/UN list check on wallet address before every transfer. |

### Smart contract

| File | Purpose |
|------|---------|
| `contracts/NvToken.sol` | ERC-20 with `onlyMinter` role, `pause()`, `blacklist()` (compliance kill-switch), `permit()` (ERC-2612 gasless approvals) |
| `contracts/hardhat.config.ts` | Hardhat config for deployment to testnets + mainnet |
| `contracts/test/NvToken.test.ts` | Hardhat/Mocha tests for mint, burn, pause, blacklist, permit |

### Realistic Track B timeline (minimum viable)

| Milestone | Duration |
|-----------|----------|
| Legal entity + counsel engagement | 1–3 months |
| Contract design + first security audit | 2–3 months |
| KYC/AML vendor contract + integration | 1–2 months |
| Regulator pre-application conversations (FinCEN, EU NCAs) | 3–6 months |
| Plugin integration coding (overlaps with above) | 2–3 months |
| Second audit (after code changes) | 1 month |
| Soft launch (geo-restricted, invite-only) | Month ~9–12 |

**Minimum realistic total:** 12 months, $300k–$1M+

---

## Recommendation

**Ship Track A first.** It delivers the intended UX — "I can send NVCoins to another user inside the plugin" — in weeks, not months, with zero regulatory overhead. The currency name, symbol, and expiry are fully configurable so it can feel like a real branded currency.

The optional audit-anchor webhook in Phase 7 gives "blockchain receipt" optics without making the plugin an on-chain issuer.

If Track B becomes commercially necessary later, Track A's schema and REST API are the right foundation — Track B is purely additive.

---

## GSD × BMAD Phase Mapping

| GSD Phase | Track A Work |
|-----------|-------------|
| Phase 0 | Initialize `.context/active/credits.md` |
| Phase 1 | This document as Project Brief |
| Phase 2 | PRD: `docs/proposals/CREDITS-PRD.md` |
| Phase 3 | Architecture: `docs/proposals/CREDITS-ARCHITECTURE.md` |
| Phase 4 | Story breakdown (8 stories, Phases 0–7 above) |
| Phase 5 | Implementation — atomic commits per story/phase |
| Phase 6 | QA — PHPUnit + Vitest + security review |
| Phase 7 | Version bump, CHANGELOG, release |
