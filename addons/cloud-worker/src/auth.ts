/**
 * Authentication middleware — validates the Bearer Connect Token, looks up the
 * wallet, and verifies the X-NV-Site-Url header matches the site bound at
 * issue time.
 *
 * On success, populates `c.var.wallet` and `c.var.token`. On failure, returns
 * HTTP 401 / 403 with a stable `error.code`.
 */

import type { Context, MiddlewareHandler } from 'hono';
import type { ConnectToken, Env, Wallet } from './types';
import { errorResponse, normalizeSiteUrl, sha256Hex } from './utils';

interface AuthVars {
	wallet: Wallet;
	token: ConnectToken;
}

export type AuthEnv = { Bindings: Env; Variables: AuthVars };

export const requireToken: MiddlewareHandler<AuthEnv> = async (c, next) => {
	const authHeader = c.req.header('Authorization') ?? '';
	const match = /^Bearer\s+(.+)$/i.exec(authHeader.trim());
	if (!match) {
		return errorResponse(401, 'missing_token', 'Authorization: Bearer <token> required.');
	}
	const plaintext = match[1]!.trim();
	if (plaintext.length < 16 || plaintext.length > 256) {
		return errorResponse(401, 'invalid_token', 'Connect token has an invalid format.');
	}

	const tokenHash = await sha256Hex(plaintext);

	const tokenRow = await c.env.NVOOS_DB.prepare(
		'SELECT id, wallet_id, token_hash, site_url, label, created_at, last_used_at, revoked_at ' +
			'FROM connect_tokens WHERE token_hash = ?1 LIMIT 1'
	)
		.bind(tokenHash)
		.first<ConnectToken>();

	if (!tokenRow) {
		return errorResponse(401, 'invalid_token', 'Connect token not recognized.');
	}
	if (tokenRow.revoked_at !== null && tokenRow.revoked_at !== undefined) {
		return errorResponse(401, 'revoked_token', 'Connect token has been revoked.');
	}

	// Site binding — every inference request MUST send X-NV-Site-Url and it
	// MUST match the site URL the token was issued for.
	const siteHeader = c.req.header('X-NV-Site-Url');
	if (!siteHeader) {
		return errorResponse(403, 'missing_site_url', 'X-NV-Site-Url header is required.');
	}
	let normalized: string;
	try {
		normalized = normalizeSiteUrl(siteHeader);
	} catch {
		return errorResponse(403, 'invalid_site_url', 'X-NV-Site-Url could not be parsed.');
	}
	if (normalized !== tokenRow.site_url) {
		return errorResponse(403, 'site_mismatch', 'Site URL does not match the bound site.');
	}

	const walletRow = await c.env.NVOOS_DB.prepare(
		'SELECT id, stripe_customer_id, email, balance_micro_usd, created_at, updated_at ' +
			'FROM wallets WHERE id = ?1 LIMIT 1'
	)
		.bind(tokenRow.wallet_id)
		.first<Wallet>();

	if (!walletRow) {
		return errorResponse(401, 'wallet_missing', 'Wallet not found for token.');
	}

	c.set('wallet', walletRow);
	c.set('token', tokenRow);

	// Best-effort touch — not transactional, fire-and-forget is fine.
	c.executionCtx.waitUntil(
		c.env.NVOOS_DB.prepare('UPDATE connect_tokens SET last_used_at = ?1 WHERE id = ?2')
			.bind(Date.now(), tokenRow.id)
			.run()
			.catch(() => undefined)
	);

	await next();
};

/** Helper for handlers that have already passed `requireToken`. */
export function getAuth(c: Context<AuthEnv>): AuthVars {
	return {
		wallet: c.get('wallet'),
		token: c.get('token'),
	};
}
