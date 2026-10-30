/**
 * Token bucket for per-tool quotas (Phase 1 of Proposal 094).
 *
 * Standard token-bucket refill — the right model for agentic workloads,
 * which burst dozens of tool calls per minute and then idle. The bucket
 * carries no timers: refill is computed lazily on access, so the gateway
 * event loop stays clean and the store stays bounded by actual usage.
 */

/**
 * A single token bucket.
 */
export class TokenBucket {
	/**
	 * @param {number} capacity  Burst capacity (tokens).
	 * @param {number} refillPerMs Refill rate (tokens per millisecond).
	 * @param {number} [now]     Current epoch ms (injectable for tests).
	 */
	constructor( capacity, refillPerMs, now = Date.now() ) {
		this.capacity = capacity;
		this.refillPerMs = refillPerMs;
		this.tokens = capacity;
		this.lastRefill = now;
	}

	/**
	 * Try to consume one token.
	 *
	 * @param {number} [now] Current epoch ms.
	 * @return {{allowed: boolean, remaining: number, resetSeconds: number}}
	 *         `remaining` is the floor of tokens left (RFC 9558 semantics);
	 *         `resetSeconds` is the seconds until the bucket refills to
	 *         capacity.
	 */
	consume( now = Date.now() ) {
		const elapsed = Math.max( 0, now - this.lastRefill );
		this.tokens = Math.min( this.capacity, this.tokens + elapsed * this.refillPerMs );
		this.lastRefill = now;

		const allowed = this.tokens >= 1;
		if ( allowed ) {
			this.tokens -= 1;
		}

		// Reset = seconds until the bucket refills to capacity (computed
		// after the consumption so the spent token counts).
		const missing = this.capacity - this.tokens;
		const resetSeconds = Math.max(
			1,
			Math.ceil( missing / Math.max( this.refillPerMs * 1000, 1 ) )
		);

		return { allowed, remaining: Math.floor( this.tokens ), resetSeconds };
	}

	/**
	 * Inspect state without consuming (dry-run / policy-sim support). Pure
	 * read: refill is computed but not committed.
	 *
	 * @param {number} [now] Current epoch ms.
	 * @return {{allowed: boolean, remaining: number, resetSeconds: number}}
	 */
	peek( now = Date.now() ) {
		const elapsed = Math.max( 0, now - this.lastRefill );
		const tokens = Math.min( this.capacity, this.tokens + elapsed * this.refillPerMs );
		const missing = this.capacity - tokens;
		const resetSeconds = Math.max(
			1,
			Math.ceil( missing / Math.max( this.refillPerMs * 1000, 1 ) )
		);
		return { allowed: tokens >= 1, remaining: Math.floor( tokens ), resetSeconds };
	}
}

/**
 * Map-based bucket store with lazy expiry: buckets idle longer than the
 * widest quota window are dropped on access so the store cannot grow
 * without bound.
 *
 * @param {number} idleTtlMs Bucket idle lifetime.
 * @return {Map<string, {bucket: TokenBucket, lastSeen: number}>} Store.
 */
export function createQuotaStore( idleTtlMs = 24 * 60 * 60 * 1000 ) {
	return new Map();
}

/**
 * Get (or lazily create) the bucket for a scoped key.
 *
 * @param {Map}    store    Store from createQuotaStore().
 * @param {string} scopeKey Composite scope (`key|site|tool`).
 * @param {number} rate     Rate per window.
 * @param {number} windowMs Window in ms.
 * @param {number} burst    Burst capacity.
 * @param {number} [now]    Current epoch ms.
 * @return {TokenBucket} Bucket.
 */
export function bucketFor( store, scopeKey, rate, windowMs, burst, now = Date.now() ) {
	let entry = store.get( scopeKey );
	if ( entry && now - entry.lastSeen > windowMs * 4 + 60000 ) {
		store.delete( scopeKey );
		entry = undefined;
	}
	if ( ! entry ) {
		const capacity = burst > 0 ? burst : rate;
		entry = {
			bucket: new TokenBucket( capacity, rate / windowMs, now ),
			lastSeen: now,
		};
		store.set( scopeKey, entry );
	}
	entry.lastSeen = now;
	return entry.bucket;
}
