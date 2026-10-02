/**
 * Status monitoring request handlers (express-free).
 *
 * Kept separate from routes/status.js so the logic is testable without
 * importing express (the monorepo test environment resolves a
 * path-to-regexp version that breaks Router construction).
 */

import { validateHeartbeat } from './validate.js';
import { computeState, computeUptime, severityOf, worstOf } from './state.js';
import { siteConfig } from './config.js';
import { store } from './store.js';
import { emitTransitionAlerts } from './sweeper.js';

/** Worker version (kept in sync with package.json by the release process). */
const WORKER_VERSION = '3.3.0';

/** Monotonic counters for the metrics endpoint. */
export const counters = {
	heartbeats: {},
	synthetic: {},
	transitions: {},
	alertBus: null, // Set by index.js when alerting is configured.
};

/**
 * Build a summary entry for a site from its stored record.
 *
 * @param {string} slug   Site slug.
 * @param {Object} record Stored record.
 * @param {number} now    Current time (ms).
 * @return {Object} Summary entry.
 */
export function summarizeSite( slug, record, now ) {
	const cfg = siteConfig( slug );
	const state = computeState( record, now, cfg );
	const heartbeatAge = record.lastHeartbeatAt ? Math.max( 0, Math.floor( ( now - record.lastHeartbeatAt ) / 1000 ) ) : null;

	return {
		slug,
		status: state.status,
		severity: severityOf( state.status ),
		message: record.components && Object.keys( record.components ).length
			? `Reported: ${ state.status } (${ Object.keys( record.components ).length } component(s))`
			: '',
		site_url: record.siteUrl || null,
		heartbeat_age_s: heartbeatAge,
		latency_ms: record.synthetic && Number.isFinite( record.synthetic.latencyMs ) ? record.synthetic.latencyMs : null,
		last_heartbeat_at: record.lastHeartbeatAt || null,
		checked_at: record.synthetic ? record.synthetic.checkedAt || null : null,
		since: state.since || null,
	};
}

/**
 * Fleet summary: every known site + worst-status rollup.
 *
 * @param {number} [now] Clock (tests).
 * @return {Promise<Object>} Summary object.
 */
export async function buildSummary( now = Date.now() ) {
	const slugs = await store.listSites();
	const sites = [];

	for ( const slug of slugs ) {
		const record = await store.getLatest( slug );
		if ( record ) {
			sites.push( summarizeSite( slug, record, now ) );
		}
	}

	const overall = worstOf( sites.map( ( site ) => site.status ) );

	return {
		version: WORKER_VERSION,
		generated_at: now,
		overall_status: overall,
		sites,
	};
}

/** Days param clamp for history routes. */
export function clampDays( raw, fallback = 30, max = 90 ) {
	const days = Number.parseInt( raw, 10 );
	return Number.isFinite( days ) && days > 0 ? Math.min( days, max ) : fallback;
}

/**
 * Heartbeat ingestion handler.
 *
 * @param {Object} req  Request ({ site, siteUrl, body }).
 * @param {Object} res  Response ({ status, json }).
 * @param {number} [now] Clock (tests).
 * @return {Promise<Object>} Response.
 */
export async function handleHeartbeat( req, res, now = Date.now() ) {
	const slug = req.site || 'default';

	// The status module answers 503 when disabled; the router is only
	// mounted when enabled, but keep the guard for direct wiring.
	if ( ! siteConfig( slug ).enabled && ! siteConfig( 'default' ).enabled ) {
		return res.status( 503 ).json( { error: 'status_not_enabled' } );
	}

	const result = validateHeartbeat( req.body );
	if ( ! result.ok ) {
		return res.status( 400 ).json( { success: false, error: result.error } );
	}

	const payload = result.payload;
	const existing = ( await store.getLatest( slug ) ) || {};
	const previous = existing.status || 'unknown';

	// X-Site-Url cross-check: warn (never reject) when the payload's origin
	// disagrees with a previously seen origin for this token.
	if ( payload.site_url && existing.siteUrl && payload.site_url !== existing.siteUrl ) {
		console.warn(
			`[Status] Heartbeat site_url changed for "${ slug }": ${ existing.siteUrl } -> ${ payload.site_url }`
		);
	}

	const record = {
		...existing,
		slug,
		siteUrl: payload.site_url || existing.siteUrl || req.siteUrl || '',
		lastHeartbeatAt: now,
		lastSeenAt: now,
		reportedStatus: payload.checks.overall,
		components: payload.checks.components,
		meta: payload.meta,
		maintenanceUntil: payload.meta.maintenance_until ? payload.meta.maintenance_until * 1000 : 0,
		missCount: 0,
	};

	const cfg = siteConfig( slug );
	const state = computeState( record, now, cfg );
	record.status = state.status;
	record.missCount = state.missCount;
	record.since = state.since;
	if ( 'major_outage' !== state.status ) {
		record.downSince = 0;
	}

	await store.putLatest( slug, record );
	counters.heartbeats[ slug ] = ( counters.heartbeats[ slug ] || 0 ) + 1;

	if ( state.status !== previous ) {
		await store.appendTransition( slug, state.status, now );
		counters.transitions[ slug ] = ( counters.transitions[ slug ] || 0 ) + 1;
		emitTransitionAlerts( counters.alertBus, slug, previous, state );
	}

	return res.json( { ok: true, server_time: now } );
}

/**
 * Fleet summary handler.
 *
 * @param {Object} _req Request (unused).
 * @param {Object} res  Response ({ status, json }).
 * @return {Promise<Object>} Response.
 */
export async function handleSummary( _req, res ) {
	try {
		const summary = await buildSummary();
		return res.json( summary );
	} catch ( err ) {
		console.warn( '[Status] Summary failed:', err.message );
		return res.status( 500 ).json( { error: 'summary_failed' } );
	}
}

/**
 * Site detail handler.
 *
 * @param {Object} req Request ({ params }).
 * @param {Object} res Response ({ status, json }).
 * @return {Promise<Object>} Response.
 */
export async function handleSiteDetail( req, res ) {
	const slug = String( req.params.slug || '' ).slice( 0, 64 );
	if ( ! slug ) {
		return res.status( 400 ).json( { error: 'invalid_slug' } );
	}

	const record = await store.getLatest( slug );
	if ( ! record ) {
		return res.status( 404 ).json( { error: 'site_not_found' } );
	}

	const now = Date.now();
	const state = computeState( record, now, siteConfig( slug ) );

	return res.json( {
		slug,
		...summarizeSite( slug, record, now ),
		components: record.components || {},
		meta: record.meta || {},
		synthetic: record.synthetic || null,
		reported_status: record.reportedStatus || state.status,
	} );
}

/**
 * Site uptime history handler.
 *
 * @param {Object} req Request ({ params, query }).
 * @param {Object} res Response ({ status, json }).
 * @return {Promise<Object>} Response.
 */
export async function handleHistory( req, res ) {
	const slug = String( req.params.slug || '' ).slice( 0, 64 );
	if ( ! slug ) {
		return res.status( 400 ).json( { error: 'invalid_slug' } );
	}

	const days = clampDays( req.query.days, 30, 90 );
	const now = Date.now();
	const history = await store.history( slug, days, now );
	const uptime = computeUptime( history, now, days );

	return res.json( {
		slug,
		days,
		overall_uptime: uptime.overall,
		history: uptime.byDay,
		transitions: history.slice( -500 ),
	} );
}
