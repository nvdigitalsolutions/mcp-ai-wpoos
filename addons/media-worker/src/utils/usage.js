/**
 * In-memory per-site provider usage counters (Phase 2b — proposal 027).
 *
 * Counters are process-local and reset on restart. They exist so operators
 * can see which site is consuming which provider's quota (signal to split a
 * heavy site into its own worker/keys) — not for billing. Exposed as
 * `tenants.usage` in /api/health/full (counts only, no PII, no secrets).
 */

const VALID_OUTCOMES = new Set( [ 'success', 'provider_error', 'missing_key' ] );

/** `${site}:${provider}` -> { success, provider_error, missing_key }. */
const counters = new Map();

/**
 * Record one usage event.
 *
 * @param {string} site     Site slug ('default' in single-tenant mode).
 * @param {string} provider Provider id (e.g. 'openai', 'twitter').
 * @param {string} outcome  'success' | 'provider_error' | 'missing_key'.
 */
export function recordUsage( site, provider, outcome ) {
	if ( ! VALID_OUTCOMES.has( outcome ) ) {
		return;
	}
	const s = site || 'default';
	const p = String( provider || 'unknown' );
	const key = `${ s }:${ p }`;
	if ( ! counters.has( key ) ) {
		counters.set( key, { success: 0, provider_error: 0, missing_key: 0 } );
	}
	counters.get( key )[ outcome ]++;
}

/**
 * Snapshot of all counters: per-site/per-provider plus process totals.
 *
 * @return {Object} { sites: { [site]: { [provider]: counts } }, totals }.
 */
export function getUsage() {
	const sites = {};
	const totals = { success: 0, provider_error: 0, missing_key: 0 };
	for ( const [ key, counts ] of counters.entries() ) {
		const separator = key.indexOf( ':' );
		const site = key.slice( 0, separator );
		const provider = key.slice( separator + 1 );
		if ( ! sites[ site ] ) {
			sites[ site ] = {};
		}
		sites[ site ][ provider ] = { ...counts };
		totals.success += counts.success;
		totals.provider_error += counts.provider_error;
		totals.missing_key += counts.missing_key;
	}
	return { sites, totals };
}

/**
 * Test-only: clear all counters.
 */
export function resetUsage() {
	counters.clear();
}
