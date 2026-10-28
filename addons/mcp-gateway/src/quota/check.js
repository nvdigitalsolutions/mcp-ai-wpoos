/**
 * Per-tool quota enforcement (Phase 1 of Proposal 094).
 *
 * Scopes buckets on `(public key × site × tool)` — the same per-identity
 * discipline as the MCP group limiter, so one noisy key cannot exhaust
 * another's tool budget. Enforcement is additive to the existing group
 * budgets: the gateway can only tighten what the sites already enforce
 * (the delegate model — sites remain the deep enforcement layer).
 */

import { bucketFor } from './token-bucket.js';
import { resolveQuota } from './config.js';

/**
 * Consume (or dry-run inspect) one token of the effective quota for a tool
 * call.
 *
 * @param {Map}     store  Quota store (createQuotaStore()).
 * @param {Array}   rules  Quota rules (parseToolQuotas()).
 * @param {string}  keyId  Authenticated public key id.
 * @param {string}  site   Target site slug.
 * @param {string}  tool   Tool slug (unprefixed upstream name).
 * @param {number}  [now]  Current epoch ms (injectable for tests).
 * @param {boolean} [dryRun] Inspect without consuming (dryRun tools/call).
 * @return {object} `{ allowed, limit, remaining, resetSeconds, windowSeconds,
 *                   policy, scoped }` — `scoped` is false when no quota rule
 *                   matched (group budgets still apply upstream of this).
 */
export function consumeToolQuota( store, rules, keyId, site, tool, now = Date.now(), dryRun = false ) {
	const rule = resolveQuota( rules, tool );
	if ( ! rule ) {
		return { allowed: true, scoped: false };
	}

	const scopeKey = `${ keyId }|${ site }|${ tool }`;
	const bucket = bucketFor( store, scopeKey, rule.rate, rule.windowMs, rule.burst, now );
	const outcome = dryRun ? bucket.peek( now ) : bucket.consume( now );

	return {
		allowed: outcome.allowed,
		scoped: true,
		limit: rule.rate,
		remaining: outcome.remaining,
		resetSeconds: Math.max( 1, outcome.resetSeconds ),
		windowSeconds: Math.round( rule.windowMs / 1000 ),
		policy: `${ rule.rate };w=${ Math.round( rule.windowMs / 1000 ) }`,
	};
}

/**
 * RFC 9558 rate-limit headers for a breached quota.
 *
 * @param {object} outcome consumeToolQuota() result.
 * @return {object} Header name → value map.
 */
export function quotaHeaders( outcome ) {
	if ( outcome.allowed || ! outcome.scoped ) {
		return {};
	}
	return {
		'RateLimit-Limit': String( outcome.limit ),
		'RateLimit-Remaining': '0',
		'RateLimit-Reset': String( outcome.resetSeconds ),
		'RateLimit-Policy': outcome.policy,
		'Retry-After': String( outcome.resetSeconds ),
	};
}
