/**
 * Shared types and the Workers Env binding for the NV oOS Cloud Worker.
 */

export interface Env {
	// Bindings.
	NVOOS_DB: D1Database;
	RATE_KV: KVNamespace;
	TENANT_KV?: KVNamespace;

	// Public vars.
	WORKER_VERSION: string;
	DEFAULT_CURRENCY: string;
	MARKUP_RATE: string;
	MIN_TOPUP_USD: string;
	LOW_BALANCE_THRESHOLD_USD: string;
	STRIPE_FEE_PERCENT: string;
	STRIPE_FEE_FIXED_USD: string;

	// Secrets (set via `wrangler secret put`).
	OPENROUTER_API_KEY: string;
	STRIPE_SECRET_KEY: string;
	STRIPE_WEBHOOK_SECRET: string;
	CF_AI_GATEWAY_URL: string;

	// SaaS-specific (Schedule Anything).
	PLATFORM_ORIGIN?: string;
	SAAS_API_KEY?: string;
}

/** A row from the `wallets` table. Balances stored in micro-USD (1 USD = 1_000_000). */
export interface Wallet {
	id: string;
	stripe_customer_id: string;
	email: string | null;
	balance_micro_usd: number;
	created_at: number;
	updated_at: number;
}

/** A row from the `connect_tokens` table. */
export interface ConnectToken {
	id: string;
	wallet_id: string;
	token_hash: string;
	site_url: string;
	label: string | null;
	created_at: number;
	last_used_at: number | null;
	revoked_at: number | null;
}

/** A single ledger entry. */
export interface LedgerEntry {
	id: number;
	wallet_id: string;
	token_id: string | null;
	request_id: string;
	model: string | null;
	prompt_tokens: number;
	completion_tokens: number;
	wholesale_micro_usd: number;
	fee_micro_usd: number;
	total_micro_usd: number;
	status: 'ok' | 'error' | 'refunded';
	created_at: number;
}

/** Hono `c.var` extension — populated by the `requireToken` middleware. */
export interface AuthContext {
	wallet: Wallet;
	token: ConnectToken;
}

/** What we return on `/v1/account/balance`. */
export interface BalanceResponse {
	balance_usd: number;
	currency: string;
	min_topup_usd: number;
	low_balance_threshold_usd: number;
	auto_topup_enabled: boolean;
}

/** Body for `/v1/account/topup`. */
export interface TopupRequest {
	amount_usd: number;
	return_url: string;
	cancel_url: string;
	site_url: string;
}

/** Body for the public `/connect` flow (issue Connect Token). */
export interface ConnectRequest {
	stripe_session_id: string;
	site_url: string;
	label?: string;
}

/** Pricing math result. */
export interface PricingMath {
	wholesale_micro_usd: number;
	fee_micro_usd: number;
	total_micro_usd: number;
}
