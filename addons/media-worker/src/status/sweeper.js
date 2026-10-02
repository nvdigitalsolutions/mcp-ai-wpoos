/**
 * Dead man's switch sweeper.
 *
 * Recomputes every site's state on a fixed interval and persists
 * transitions. Alerts fire on confirmed transitions only (site.down after
 * STATUS_CONFIRM_MISSES misses, site.recovered on the first heartbeat
 * back, site.degraded on split-brain partial outages) — the confirmation
 * thresholds themselves live in state.js / config.js.
 */

import { computeState, graceMs } from './state.js';
import { siteConfig } from './config.js';

/** Set of event names that trigger alerts on transitions. */
const ALERT_EVENTS = {
	major_outage: 'site.down',
	partial_outage: 'site.degraded',
};

/**
 * Dispatch transition alerts for a status change (shared with the
 * heartbeat ingestion path so alerting logic lives in one place).
 *
 * @param {Object|null} alertBus Alert bus (no-op when null).
 * @param {string}      slug     Site slug.
 * @param {string}      previous Previous status.
 * @param {Object}      state    New computed state.
 * @return {void}
 */
export function emitTransitionAlerts( alertBus, slug, previous, state ) {
	if ( ! alertBus ) {
		return;
	}
	if ( 'major_outage' === state.status && previous !== state.status ) {
		alertBus.dispatch( ALERT_EVENTS.major_outage, slug, state );
	} else if ( 'partial_outage' === state.status && previous !== state.status ) {
		alertBus.dispatch( ALERT_EVENTS.partial_outage, slug, state );
	} else if (
		'operational' === state.status &&
		( 'major_outage' === previous || 'partial_outage' === previous || 'at_risk' === previous )
	) {
		alertBus.dispatch( 'site.recovered', slug, state );
	}
}

/**
 * Start the sweeper.
 *
 * @param {Object}  deps                       Dependencies.
 * @param {Object}  deps.store                 Status store.
 * @param {Object}  [deps.alertBus]            Alert bus (no alerts when omitted).
 * @param {Function} [deps.now]                Clock (tests).
 * @param {Function} [deps.siteConfigFn]       Config resolver (tests; defaults to config.js siteConfig).
 * @param {Function} [deps.onTransition]       Observer callback (slug, from, to, state).
 * @param {number}  [deps.intervalMs]          Sweep interval override.
 * @return {{ stop: Function, sweepOnce: Function }} Controls.
 */
export function startSweeper( { store, alertBus = null, now = Date.now, siteConfigFn = siteConfig, onTransition = null, intervalMs } ) {
	const interval = intervalMs || siteConfigFn( 'default' ).sweeperIntervalMs;

	/**
	 * Single sweep over every known site.
	 *
	 * @return {Promise<void>}
	 */
	async function sweepOnce() {
		let slugs = [];
		try {
			slugs = await store.listSites();
		} catch ( err ) {
			console.warn( '[Status] Sweep listSites failed:', err.message );
			return;
		}

		for ( const slug of slugs ) {
			let record = null;
			try {
				record = await store.getLatest( slug );
			} catch ( err ) {
				console.warn( `[Status] Sweep getLatest failed for "${ slug }":`, err.message );
				continue;
			}
			if ( ! record ) {
				continue;
			}

			const cfg = siteConfigFn( slug );
			const state = computeState( record, now(), cfg );
			const previous = record.status || 'unknown';

			// Nothing changed (or the miss counter advanced without a state
			// change) — persist counters only.
			if ( previous === state.status && record.missCount === state.missCount ) {
				continue;
			}

			const updated = {
				...record,
				status: state.status,
				missCount: state.missCount,
				since: state.since,
				downSince: 'major_outage' === state.status ? state.since : record.downSince || 0,
			};

			try {
				await store.putLatest( slug, updated );
				await store.appendTransition( slug, state.status, now() );
			} catch ( err ) {
				console.warn( `[Status] Sweep persist failed for "${ slug }":`, err.message );
				continue;
			}

			if ( onTransition ) {
				onTransition( slug, previous, state.status, state );
			}

			// Alerting: confirmed outage transitions and recoveries.
			emitTransitionAlerts( alertBus, slug, previous, state );
		}
	}

	const timer = setInterval( () => {
		sweepOnce().catch( ( err ) => {
			console.warn( '[Status] Sweep failed:', err.message );
		} );
	}, interval );
	timer.unref();

	return {
		stop() {
			clearInterval( timer );
		},
		sweepOnce,
	};
}

/** Re-export for consumers that need the grace math. */
export { graceMs };
