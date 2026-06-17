-- NV oOS Cloud — D1 Schema
-- Apply with:
--   npx wrangler d1 execute nvoos-cloud-prod --file=./schema.sql --remote

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- wallets — one row per customer / account.
-- Balance is tracked as integer micro-USD (1 USD = 1_000_000) to avoid float
-- drift across millions of small per-request debits.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallets (
    id                  TEXT PRIMARY KEY,                 -- uuid
    stripe_customer_id  TEXT NOT NULL UNIQUE,
    email               TEXT,
    balance_micro_usd   INTEGER NOT NULL DEFAULT 0,
    created_at          INTEGER NOT NULL,
    updated_at          INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_wallets_stripe_customer_id
    ON wallets (stripe_customer_id);

-- ---------------------------------------------------------------------------
-- connect_tokens — issued to a WordPress site after first successful top-up.
-- The plaintext token is shown to the user once on /connect, then only its
-- SHA-256 hash is stored (token_hash). The site URL is bound at issue time
-- and verified on every inference request via the X-NV-Site-Url header.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_tokens (
    id           TEXT PRIMARY KEY,                        -- uuid
    wallet_id    TEXT NOT NULL,
    token_hash   TEXT NOT NULL UNIQUE,                    -- sha256(token) hex
    site_url     TEXT NOT NULL,                           -- normalized origin
    label        TEXT,                                    -- e.g. "production"
    created_at   INTEGER NOT NULL,
    last_used_at INTEGER,
    revoked_at   INTEGER,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_connect_tokens_wallet
    ON connect_tokens (wallet_id);
CREATE INDEX IF NOT EXISTS idx_connect_tokens_site
    ON connect_tokens (site_url);

-- ---------------------------------------------------------------------------
-- ledger — append-only per-request billing entries. NEVER stores prompt or
-- completion bodies — only token counts + costs.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ledger (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    wallet_id           TEXT NOT NULL,
    token_id            TEXT,                             -- connect_tokens.id
    request_id          TEXT NOT NULL UNIQUE,             -- cf-ray or uuid
    model               TEXT,
    prompt_tokens       INTEGER NOT NULL DEFAULT 0,
    completion_tokens   INTEGER NOT NULL DEFAULT 0,
    wholesale_micro_usd INTEGER NOT NULL DEFAULT 0,
    fee_micro_usd       INTEGER NOT NULL DEFAULT 0,
    total_micro_usd     INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL DEFAULT 'ok',       -- ok | error | refunded
    created_at          INTEGER NOT NULL,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    FOREIGN KEY (token_id) REFERENCES connect_tokens(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_ledger_wallet_created
    ON ledger (wallet_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- topup_sessions — Stripe Checkout sessions created via /v1/account/topup.
-- The webhook handler uses event_id as the idempotency key.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS topup_sessions (
    id                  TEXT PRIMARY KEY,                 -- stripe checkout session id
    wallet_id           TEXT NOT NULL,
    amount_micro_usd    INTEGER NOT NULL,
    processor_fee_micro_usd INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL DEFAULT 'pending',  -- pending | completed | expired
    event_id            TEXT UNIQUE,                      -- stripe event id (idempotency)
    created_at          INTEGER NOT NULL,
    completed_at        INTEGER,
    FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_topup_sessions_wallet
    ON topup_sessions (wallet_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- tenants — SaaS tenant records for Schedule Anything.
-- One row per subscribed tenant workspace.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id                     TEXT PRIMARY KEY,                  -- uuid
    slug                   TEXT NOT NULL UNIQUE,              -- subdomain slug
    tier                   TEXT NOT NULL
        CHECK (tier IN ('starter', 'professional', 'enterprise')),
    stripe_customer_id     TEXT NOT NULL,
    stripe_subscription_id TEXT,
    wp_origin_url          TEXT NOT NULL DEFAULT '',          -- WP instance origin
    wp_blog_id             INTEGER,                           -- Multisite subsite ID
    admin_email            TEXT,
    status                 TEXT NOT NULL DEFAULT 'provisioning'
        CHECK (status IN ('provisioning', 'active', 'suspended', 'cancelled')),
    created_at             INTEGER NOT NULL,
    updated_at             INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tenants_slug ON tenants (slug);
CREATE INDEX IF NOT EXISTS idx_tenants_stripe_customer ON tenants (stripe_customer_id);
CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants (status);

-- ---------------------------------------------------------------------------
-- webhook_events — idempotency guard for Stripe events.
-- Prevents double-processing of webhook deliveries.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
    event_id   TEXT PRIMARY KEY,                               -- stripe event id
    event_type TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

-- ---------------------------------------------------------------------------
-- tenant_usage — daily aggregated usage metrics per tenant.
-- Populated by the usage heartbeat from each WP instance.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_usage (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id            TEXT NOT NULL,
    date                 TEXT NOT NULL,                        -- YYYY-MM-DD
    blog_id              INTEGER NOT NULL,
    active_schedules     INTEGER NOT NULL DEFAULT 0,
    total_appointments   INTEGER NOT NULL DEFAULT 0,
    total_posts          INTEGER NOT NULL DEFAULT 0,
    storage_bytes        INTEGER NOT NULL DEFAULT 0,
    user_count           INTEGER NOT NULL DEFAULT 0,
    reported_at          INTEGER NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_tenant_usage_date
    ON tenant_usage (tenant_id, date);
