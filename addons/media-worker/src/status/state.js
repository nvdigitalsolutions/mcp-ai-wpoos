/**
 * Per-site state machine for the status monitor.
 *
 * Pure functions — no storage or timers — so the transition logic is
 * testable in isolation. The status taxonomy is shared with the WordPress
 * plugin (Interface_WP_MCP_AI_Service_Status_Source); the internal
 * `at_risk` state is a pre-outage signal that sits between `operational`
 * and `degraded_performance` in severity.
 *
 * Truth table (plan §4.4):
 *   - Fresh heartbeat + maintenance   -> under_maintenance
 *   - Fresh heartbeat + synthetic down -> partial_outage (split-brain)
 *   - Fresh heartbeat                 -> payload-reported status (worst-wins)
 *   - Stale heartbeat, within grace   -> at_risk (1st miss)
 *   - Stale heartbeat, misses >= N    -> major_outage
 *   - Any heartbeat after an outage   -> recovery (confirmRecoveries hits)
 */

/**
 * Severity ordering for fleet rollups. Worst status wins.
 *
 * @param {string} status Status value.
 * @return {number} Severity rank (higher = worse).
 */
export function severityOf( status ) {
	const table = {
		unknown: 0,
		operational: 1,
		at_risk: 2,
		under_maintenance: 3,
		degraded_performance: 4,
		partial_outage: 5,
		major_outage: 6,
	};
	return Object.prototype.hasOwnProperty.call( table, status ) ? table[ status ] : 0;
}

/**
 * Compute the worst status across a list.
 *
 * @param {string[]} statuses Status values.
 * @return {string} Worst status (operational when the list is empty).
 */
export function worstOf( statuses ) {
	let worst = 'operational';
	let worstSeverity = severityOf( 'operational' );
	for ( const status of statuses ) {
		const severity = severityOf( status );
		if ( severity > worstSeverity ) {
			worst = status;
			worstSeverity = severity;
		}
	}
	return worst;
}

/**
 * Grace window for a site: heartbeat interval × multiplier.
 *
 * @param {Object} cfg Site config.
 * @return {number} Grace window in ms.
 */
export function graceMs( cfg ) {
	return cfg.heartbeatIntervalMs * cfg.graceMultiplier;
}

/**
 * Compute the current state for a site record.
 *
 * Freshness rule: a heartbeat is on-time when its age is within one
 * heartbeat interval (WP-Cron drift beyond that is already a soft miss).
 * The grace window (interval × multiplier) is a safety floor: a site never
 * escalates to major_outage inside it, even if an operator raises
 * STATUS_CONFIRM_MISSES without widening the grace.
 *
 * @param {Object} record Site record (see store.js shape).
 * @param {number} now    Current time (ms epoch).
 * @param {Object} cfg    Site config.
 * @return {Object} Computed state: { status, missCount, since, lastSeenAt }.
 */
export function computeState( record, now, cfg ) {
	const lastHeartbeatAt = record.lastHeartbeatAt || 0;
	const heartbeatAge = now - lastHeartbeatAt;
	const maintenanceUntil = Math.max( record.maintenanceUntil || 0, maintenanceOverride( cfg ) );
	const synthetic = record.synthetic || {};
	const syntheticEnabled = cfg.syntheticEnabled || ( record.synthetic && record.synthetic.checked );

	let missCount = 0;
	if ( record.missCount ) {
		missCount = record.missCount;
	}

	// An on-time heartbeat resets the miss counter and drives the status.
	if ( lastHeartbeatAt > 0 && heartbeatAge <= cfg.heartbeatIntervalMs ) {
		missCount = 0;

		// Heartbeat-carried maintenance wins over everything.
		if ( maintenanceUntil > now ) {
			return {
				status: 'under_maintenance',
				missCount,
				since: record.since || now,
				lastSeenAt: lastHeartbeatAt,
			};
		}

		// Split-brain: the site says it is fine but the external view
		// cannot reach it — partial outage, not full.
		if ( syntheticEnabled && false === synthetic.ok ) {
			return {
				status: 'partial_outage',
				missCount,
				since: record.since || now,
				lastSeenAt: lastHeartbeatAt,
			};
		}

		// Reported status from the heartbeat, worst-of the components.
		const reported = worstOf( [
			record.reportedStatus || 'operational',
			...Object.values( record.components || {} )
				.map( ( component ) => component.status )
				.filter( ( status ) => 'unknown' !== status ),
		] );

		return {
			status: reported,
			missCount,
			since: record.since || now,
			lastSeenAt: lastHeartbeatAt,
		};
	}

	// No heartbeat yet, or heartbeat is stale.
	if ( 0 === lastHeartbeatAt ) {
		return {
			status: syntheticEnabled && false === synthetic.ok ? 'major_outage' : 'unknown',
			missCount: 0,
			since: record.since || 0,
			lastSeenAt: 0,
		};
	}

	const missed = Math.max( 1, Math.floor( heartbeatAge / Math.max( cfg.heartbeatIntervalMs, 1 ) ) );
	missCount = Math.max( missCount, missed );

	// Escalation floor: never declare a major outage inside the grace
	// window, even when the configured miss threshold would allow it.
	if ( missCount >= cfg.confirmMisses && heartbeatAge >= graceMs( cfg ) ) {
		return {
			status: 'major_outage',
			missCount,
			since: record.downSince || now,
			lastSeenAt: lastHeartbeatAt,
		};
	}

	return {
		status: 'at_risk',
		missCount,
		since: record.since || now,
		lastSeenAt: lastHeartbeatAt,
	};
}

/**
 * Worker-side maintenance override for a site (env-based).
 *
 * @param {Object} cfg Site config.
 * @return {number} Unix ms until which maintenance applies (0 = none).
 */
function maintenanceOverride( cfg ) {
	if ( ! cfg.maintenanceUntil ) {
		return 0;
	}
	const parsed = Date.parse( cfg.maintenanceUntil );
	return Number.isFinite( parsed ) ? parsed : 0;
}

/**
 * Daily uptime percentage from transition history.
 *
 * Spans between transitions are attributed to the bucket of the entry that
 * ends them (a day-crossing span lands wholly in the newer day — an
 * accepted approximation for daily uptime reporting). The tail span from
 * the last transition to "now" always lands in today's bucket, and state
 * that started before the window is carried into it.
 *
 * @param {Array<{status: string, at: number}>} history Transition history.
 * @param {number} now    Current time (ms epoch).
 * @param {number} days   Number of days to consider.
 * @return {Object} { overall, byDay } — percentages as 0–100 floats.
 */
export function computeUptime( history, now, days ) {
	const dayMs = 24 * 60 * 60 * 1000;
	const start = now - days * dayMs;
	const finalDate = new Date( now ).toISOString().slice( 0, 10 );
	const buckets = new Map();
	buckets.set( finalDate, { total: 0, down: 0 } );

	let prevAt = start;
	let prevStatus = 'operational';

	for ( const entry of history ) {
		if ( entry.at < start ) {
			prevStatus = entry.status; // Carry pre-window state into the window.
			continue;
		}
		const date = new Date( entry.at ).toISOString().slice( 0, 10 );
		if ( ! buckets.has( date ) ) {
			buckets.set( date, { total: 0, down: 0 } );
		}
		const bucket = buckets.get( date );
		const span = Math.max( 0, entry.at - prevAt );
		bucket.total += span;
		if ( isDowntime( prevStatus ) ) {
			bucket.down += span;
		}
		prevAt = entry.at;
		prevStatus = entry.status;
	}

	// Close the final span to "now".
	const tail = buckets.get( finalDate );
	const tailSpan = Math.max( 0, now - prevAt );
	tail.total += tailSpan;
	if ( isDowntime( prevStatus ) ) {
		tail.down += tailSpan;
	}

	const byDay = {};
	let totalSum = 0;
	let downSum = 0;
	for ( const [ date, bucket ] of buckets.entries() ) {
		if ( bucket.total <= 0 ) {
			continue;
		}
		const uptime = ( ( bucket.total - bucket.down ) / bucket.total ) * 100;
		byDay[ date ] = Math.round( uptime * 100 ) / 100;
		totalSum += bucket.total;
		downSum += bucket.down;
	}

	const overall = totalSum > 0 ? Math.round( ( ( totalSum - downSum ) / totalSum ) * 10000 ) / 100 : null;

	return { overall, byDay };
}

/**
 * Whether a status counts as downtime for uptime math (Statuspage rule:
 * only major/partial outages count; maintenance and degraded do not).
 *
 * @param {string} status Status value.
 * @return {boolean} True when the status is downtime.
 */
export function isDowntime( status ) {
	return 'major_outage' === status || 'partial_outage' === status;
}
