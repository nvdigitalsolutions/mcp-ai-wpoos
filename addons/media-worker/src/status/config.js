/**
 * Status monitoring module configuration.
 *
 * All settings are env-tunable with safe defaults, and the whole module is
 * inert unless STATUS_ENABLED=1. Per-site overrides use the established
 * env pattern STATUS_<SLUG>_* (slug uppercased, hyphens -> underscores),
 * mirroring SITE_PROVIDER_KEYS_<SLUG> / RATE_LIMIT_*_<SLUG>.
 */

/**
 * Parse a bounded integer env var.
 *
 * @param {string} name     Env var name.
 * @param {number} fallback Default value.
 * @param {number} min      Minimum allowed value.
 * @return {number} Effective value.
 */
function envInt( name, fallback, min ) {
	const raw = process.env[ name ];
	const value = Number( raw );
	if ( Number.isFinite( value ) && value >= min ) {
		return Math.floor( value );
	}
	return fallback;
}

/** Env var name for a per-site override: STATUS_<SLUG>_INTERVAL_MS. */
export function siteEnvKey( suffix, slug ) {
	return `STATUS_${ String( slug ).toUpperCase().replace( /-/g, '_' ) }_${ suffix }`;
}

/**
 * Parse STATUS_EXTERNAL_TARGETS — semicolon-separated `slug=url` pairs for
 * monitoring targets that never send heartbeats (e.g. the MCP gateway).
 *
 * Malformed entries are dropped, never fatal. Slugs are normalized to
 * [a-z0-9-], capped at 64 chars; URLs must be absolute http(s).
 *
 * @param {string} raw Raw env value.
 * @return {Array<{slug: string, url: string}>} Valid targets.
 */
export function parseExternalTargets( raw ) {
	if ( ! raw || 'string' !== typeof raw ) {
		return [];
	}
	const targets = [];
	for ( const chunk of raw.split( ';' ) ) {
		const eq = chunk.indexOf( '=' );
		if ( eq <= 0 ) {
			continue;
		}
		const slug = chunk
			.slice( 0, eq )
			.trim()
			.toLowerCase()
			.replace( /[^a-z0-9-]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 64 );
		const url = chunk.slice( eq + 1 ).trim();
		if ( ! slug || ! url ) {
			continue;
		}
		try {
			const parsed = new URL( url );
			if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
				continue;
			}
		} catch {
			continue;
		}
		targets.push( { slug, url } );
	}
	return targets;
}

/** Base (shared) configuration. */
export function baseConfig() {
	return {
		enabled: '1' === process.env.STATUS_ENABLED,
		heartbeatIntervalMs: envInt( 'STATUS_HEARTBEAT_INTERVAL_MS', 300000, 10000 ),
		graceMultiplier: envInt( 'STATUS_GRACE_MULTIPLIER', 2, 1 ),
		confirmMisses: envInt( 'STATUS_CONFIRM_MISSES', 2, 1 ),
		confirmRecoveries: envInt( 'STATUS_CONFIRM_RECOVERIES', 1, 1 ),
		historyDays: envInt( 'STATUS_HISTORY_DAYS', 90, 1 ),
		rollupBucketMs: envInt( 'STATUS_ROLLUP_BUCKET_MS', 1800000, 60000 ),
		syntheticEnabled: '1' === process.env.STATUS_SYNTHETIC_ENABLED,
		syntheticIntervalMs: envInt( 'STATUS_SYNTHETIC_INTERVAL_MS', 60000, 10000 ),
		syntheticTimeoutMs: envInt( 'STATUS_SYNTHETIC_TIMEOUT_MS', 10000, 1000 ),
		externalTargets: parseExternalTargets( process.env.STATUS_EXTERNAL_TARGETS ),
		sslExpiryWarnDays: envInt( 'STATUS_SSL_EXPIRY_WARN_DAYS', 14, 1 ),
		alertCooldownMs: envInt( 'STATUS_ALERT_COOLDOWN_MS', 900000, 1000 ),
		alertWebhooks: parseJsonArray( process.env.STATUS_ALERT_WEBHOOKS ),
		alertWebhookSecret: process.env.STATUS_ALERT_WEBHOOK_SECRET || '',
		alertEmailTo: process.env.STATUS_ALERT_EMAIL_TO || '',
		publicPage: '1' === process.env.STATUS_PUBLIC_PAGE,
		metrics: '1' === process.env.STATUS_METRICS,
		sweeperIntervalMs: envInt( 'STATUS_SWEEPER_INTERVAL_MS', 60000, 5000 ),
	};
}

/**
 * Parse a JSON array of webhook URLs, tolerating malformed config.
 *
 * @param {string} raw Raw env value.
 * @return {string[]} Valid http(s) URLs.
 */
export function parseJsonArray( raw ) {
	if ( ! raw ) {
		return [];
	}
	try {
		const parsed = JSON.parse( raw );
		if ( ! Array.isArray( parsed ) ) {
			return [];
		}
		return parsed.filter( ( entry ) => {
			if ( 'string' !== typeof entry ) {
				return false;
			}
			try {
				const url = new URL( entry );
				return 'http:' === url.protocol || 'https:' === url.protocol;
			} catch {
				return false;
			}
		} );
	} catch {
		return [];
	}
}

/**
 * Effective configuration for one site (base + per-site overrides).
 *
 * @param {string} slug Site slug.
 * @return {Object} Site config.
 */
export function siteConfig( slug ) {
	const base = baseConfig();
	const syntheticEnv = siteEnvKey( 'SYNTHETIC', slug );
	const overrides = {
		heartbeatIntervalMs: envInt( siteEnvKey( 'HEARTBEAT_INTERVAL_MS', slug ), base.heartbeatIntervalMs, 10000 ),
		graceMultiplier: envInt( siteEnvKey( 'GRACE_MULTIPLIER', slug ), base.graceMultiplier, 1 ),
		confirmMisses: envInt( siteEnvKey( 'CONFIRM_MISSES', slug ), base.confirmMisses, 1 ),
		syntheticEnabled: undefined !== process.env[ syntheticEnv ]
			? '1' === process.env[ syntheticEnv ]
			: base.syntheticEnabled,
		syntheticUrl: process.env[ siteEnvKey( 'SYNTHETIC_URL', slug ) ] || '',
		syntheticIntervalMs: envInt( siteEnvKey( 'SYNTHETIC_INTERVAL_MS', slug ), base.syntheticIntervalMs, 10000 ),
		maintenanceUntil: process.env[ siteEnvKey( 'MAINTENANCE_UNTIL', slug ) ] || '',
	};
	return { ...base, ...overrides };
}
