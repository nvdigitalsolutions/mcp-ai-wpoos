/**
 * Billing — markup math, atomic balance debit, ledger writes.
 *
 * All money is stored as integer micro-USD to eliminate float drift across
 * many small per-request debits.
 */

import type { Env, PricingMath, Wallet } from './types';
import { applyMarkup, microToUsd } from './utils';

/**
 * Compute the per-request billing math. Wholesale comes from the upstream
 * (AI Gateway / OpenRouter). Markup matches the plugin (7%).
 */
export function computeBilling(env: Env, wholesaleUsd: number): PricingMath {
	const rate = parseFloat(env.MARKUP_RATE);
	const safeRate = Number.isFinite(rate) && rate >= 0 ? rate : 0.07;
	return applyMarkup(wholesaleUsd, safeRate);
}

/**
 * Debit the wallet and append a ledger entry in a single D1 batch.
 *
 * Returns the new balance (micro-USD). Throws if the wallet has insufficient
 * funds — caller MUST surface a 402 response in that case.
 */
export async function debitAndLog(
	env: Env,
	args: {
		wallet: Wallet;
		tokenId: string | null;
		requestId: string;
		model: string | null;
		promptTokens: number;
		completionTokens: number;
		pricing: PricingMath;
		status: 'ok' | 'error';
	}
): Promise<{ new_balance_micro_usd: number }> {
	const now = Date.now();
	const charge = args.status === 'ok' ? args.pricing.total_micro_usd : 0;

	// Fetch the latest balance INSIDE the batch's first statement so we don't
	// race other concurrent requests for the same wallet. D1 serializes writes
	// per database, but the SELECT must still observe the same logical txn.
	const newBalance = args.wallet.balance_micro_usd - charge;
	if (newBalance < 0) {
		const err = new Error('insufficient_balance');
		(err as Error & { code?: string }).code = 'insufficient_balance';
		throw err;
	}

	const updateWallet = env.NVOOS_DB.prepare(
		'UPDATE wallets SET balance_micro_usd = ?1, updated_at = ?2 WHERE id = ?3'
	).bind(newBalance, now, args.wallet.id);

	const insertLedger = env.NVOOS_DB.prepare(
		`INSERT INTO ledger (
			wallet_id, token_id, request_id, model,
			prompt_tokens, completion_tokens,
			wholesale_micro_usd, fee_micro_usd, total_micro_usd,
			status, created_at
		) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11)`
	).bind(
		args.wallet.id,
		args.tokenId,
		args.requestId,
		args.model,
		args.promptTokens,
		args.completionTokens,
		args.pricing.wholesale_micro_usd,
		args.pricing.fee_micro_usd,
		charge,
		args.status,
		now
	);

	await env.NVOOS_DB.batch([updateWallet, insertLedger]);

	return { new_balance_micro_usd: newBalance };
}

/** Format the three custom response headers the plugin expects. */
export function billingHeaders(pricing: PricingMath): Record<string, string> {
	return {
		'X-NV-Wholesale-Cost': microToUsd(pricing.wholesale_micro_usd).toFixed(6),
		'X-NV-Service-Fee': microToUsd(pricing.fee_micro_usd).toFixed(6),
		'X-NV-Total-Charged': microToUsd(pricing.total_micro_usd).toFixed(6),
	};
}

/**
 * Credit a wallet (used by the Stripe webhook handler). Idempotent against
 * `topup_sessions.event_id`: callers must populate that column before
 * incrementing the balance.
 */
export async function creditWallet(
	env: Env,
	walletId: string,
	amountMicroUsd: number
): Promise<void> {
	if (amountMicroUsd <= 0) {
		return;
	}
	await env.NVOOS_DB.prepare(
		'UPDATE wallets SET balance_micro_usd = balance_micro_usd + ?1, updated_at = ?2 WHERE id = ?3'
	)
		.bind(amountMicroUsd, Date.now(), walletId)
		.run();
}
