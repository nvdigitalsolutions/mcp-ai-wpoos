/**
 * Synthetic external checks: seeding + the probe loop.
 *
 * The state machine (state.js) and the sweeper own status transitions; this
 * module only writes the latest `record.synthetic` probe result and leaves
 * status recomputation to the sweeper's next pass — exactly one writer per
 * field. External targets (seeded from STATUS_EXTERNAL_TARGETS) never send
 * heartbeats, so `record.syntheticOnly = true` lets computeState() treat the
 * probe as the single source of truth.
 */

import { runSyntheticCheck } from './checks.js';
import { siteConfig } from './config.js';

/**
 * Seed synthetic-only external targets into the store (idempotent).
 *
 * Existing records — including heartbeat sites that happen to share a slug —
 * are never touched. Targets with no stored record get a minimal
 * synthetic-only record so the sweeper and the probe loop can see them.
 *
 * @param {Object} store   Status store ({ getLatest, putLatest }).
 * @param {Array<{slug: string, url: string}>} targets Parsed targets.
 * @return {Promise<number>} Number of records seeded.
 */
export async function seedExternalTargets( store, targets ) {
	let seeded = 0;
	for ( const { slug, url } of targets ) {
		try {
			const existing = await store.getLatest( slug );
			if ( existing ) {
				continue;
			}
			await store.putLatest( slug, {
				slug,
				siteUrl: url,
				syntheticUrl: url,
				lastHeartbeatAt: 0,
				missCount: 0,
				status: 'unknown',
				since: 0,
				downSince: 0,
				syntheticOnly: true,
				components: {},
				meta: {},
			} );
			seeded += 1;
		} catch ( err ) {
			console.warn( `[Status] Seed failed for external target "${ slug }":`, err.message );
		}
	}
	return seeded;
}

/**
 * Start the synthetic probe loop.
 *
 * Every interval, probe each stored site whose config enables synthetic
 * checks (STATUS_SYNTHETIC_ENABLED=1 globally or STATUS_<SLUG>_SYNTHETIC=1)
 * and persist the probe result onto the record. The sweeper then translates
 * the result into a status on its own cadence.
 *
 * @param {Object}   deps                  Dependencies.
 * @param {Object}   deps.store            Status store ({ listSites, getLatest, putLatest }).
 * @param {Object}   [deps.counters]       Counter bag ({ synthetic }).
 * @param {Function} [deps.now]            Clock (tests).
 * @param {Function} [deps.siteConfigFn]   Config resolver (tests; defaults to config.js siteConfig).
 * @param {Function} [deps.checkFn]        Probe fn(record, cfg, now) (tests; defaults to runSyntheticCheck).
 * @param {number}   [deps.intervalMs]     Loop interval override.
 * @return {{ stop: Function, runOnce: Function }} Controls.
 */
export function startSyntheticLoop( {
	store,
	counters = null,
	now = Date.now,
	siteConfigFn = siteConfig,
	checkFn = runSyntheticCheck,
	intervalMs,
} ) {
	const interval = intervalMs || siteConfigFn( 'default' ).syntheticIntervalMs;

	/**
	 * Single probe pass over every known site.
	 *
	 * @return {Promise<void>}
	 */
	async function runOnce() {
		if ( ! siteConfigFn( 'default' ).enabled ) {
			return;
		}

		let slugs = [];
		try {
			slugs = await store.listSites();
		} catch ( err ) {
			console.warn( '[Status] Synthetic listSites failed:', err.message );
			return;
		}

		for ( const slug of slugs ) {
			const cfg = siteConfigFn( slug );
			if ( ! cfg.syntheticEnabled ) {
				continue;
			}

			let record = null;
			try {
				record = await store.getLatest( slug );
			} catch ( err ) {
				console.warn( `[Status] Synthetic getLatest failed for "${ slug }":`, err.message );
				continue;
			}
			if ( ! record ) {
				continue;
			}

			let result;
			try {
				result = await checkFn( record, cfg, now() );
			} catch ( err ) {
				result = {
					ok: false,
					checkedAt: now(),
					statusCode: 0,
					latencyMs: null,
					tlsDaysLeft: null,
					error: err.message || 'synthetic check failed',
				};
			}

			try {
				await store.putLatest( slug, { ...record, synthetic: result } );
			} catch ( err ) {
				console.warn( `[Status] Synthetic persist failed for "${ slug }":`, err.message );
				continue;
			}

			if ( counters ) {
				const outcomes = counters.synthetic[ slug ] || ( counters.synthetic[ slug ] = {} );
				const bucket = result.ok ? 'ok' : 'fail';
				outcomes[ bucket ] = ( outcomes[ bucket ] || 0 ) + 1;
			}
		}
	}

	const timer = setInterval( () => {
		runOnce().catch( ( err ) => {
			console.warn( '[Status] Synthetic sweep failed:', err.message );
		} );
	}, interval );
	timer.unref();

	return {
		stop() {
			clearInterval( timer );
		},
		runOnce,
	};
}
