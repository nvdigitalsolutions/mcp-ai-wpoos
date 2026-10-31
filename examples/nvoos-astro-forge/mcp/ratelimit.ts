// ---------------------------------------------------------------------------
// ratelimit.ts — dependency-free in-memory rate limiter for the /mcp mount
// (Proposal 096 Phase 4; the 095 hardening pattern — closes the unbounded-
// request gap). Per-key fixed-window counting with an injectable `now` clock
// for deterministic tests, and lazy pruning of expired entries.
// ---------------------------------------------------------------------------

export interface RateLimiterOptions {
	max: number;
	windowMs: number;
	/** Injectable clock for tests; defaults to Date.now. */
	now?: () => number;
}

export interface RateLimiter {
	allow( key: string ): boolean;
}

/** Full-sweep cadence — admitted requests between whole-map sweeps. */
const SWEEP_EVERY = 100;

/** Sweep early when the tracked key count balloons past this size. */
const MAX_TRACKED_KEYS = 1000;

export function createRateLimiter( options: RateLimiterOptions ): RateLimiter {
	const max = Math.max( 1, Math.floor( options.max ) );
	const windowMs = Math.max( 1, Math.floor( options.windowMs ) );
	const now = options.now ?? Date.now;
	const hits = new Map<string, number[]>();
	let calls = 0;

	function sweep( current: number ): void {
		for ( const [ key, stamps ] of hits ) {
			const live = stamps.filter( ( timestamp ) => current - timestamp < windowMs );
			if ( live.length === 0 ) {
				hits.delete( key );
			} else {
				hits.set( key, live );
			}
		}
	}

	return {
		allow( key: string ): boolean {
			const current = now();
			const live = ( hits.get( key ) ?? [] ).filter( ( timestamp ) => current - timestamp < windowMs );
			if ( live.length >= max ) {
				hits.set( key, live );
				return false;
			}
			live.push( current );
			hits.set( key, live );
			calls++;
			if ( calls % SWEEP_EVERY === 0 || hits.size > MAX_TRACKED_KEYS ) {
				sweep( current );
			}
			return true;
		},
	};
}
