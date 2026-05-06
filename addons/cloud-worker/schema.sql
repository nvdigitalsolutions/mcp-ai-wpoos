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
