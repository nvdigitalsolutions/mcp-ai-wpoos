/**
 * Exchange control-plane configuration.
 *
 * Twelve-Factor: every knob arrives via the environment. The module fails
 * closed in `live` mode — a half-configured control plane must never boot
 * and start provisioning estates by accident (the gateway's boot discipline).
 *
 * Modes:
 *   mock — no provider calls; every client returns deterministic fixtures.
 *          Used by the wizard shell, tests, and local development.
 *   live — real Cloudways / GitHub / gateway calls; required env enforced.
 */

export class ConfigError extends Error {
	constructor( message, missing = [] ) {
		super( message );
		this.name = 'ConfigError';
		this.missing = missing;
	}
}

function str( value, fallback = '' ) {
	return typeof value === 'string' ? value.trim() : fallback;
}

function int( value, fallback ) {
	const n = Number.parseInt( value, 10 );
	return Number.isFinite( n ) ? n : fallback;
}

function bool( value, fallback ) {
	if ( value === undefined || value === '' ) {
		return fallback;
	}
	return value === '1' || value === 'true' || value === 'yes';
}

/**
 * Load and validate configuration.
 *
 * @param {Record<string, string|undefined>} env Environment map.
 * @return {object} Frozen config object.
 */
export function loadConfig( env = process.env ) {
	const mode = str( env.EXCHANGE_MODE, 'mock' ) === 'live' ? 'live' : 'mock';

	const config = {
		mode,
		port: int( env.PORT, 4182 ),
		dataDir: str( env.EXCHANGE_DATA_DIR, 'data' ),
		jobsFile: str( env.EXCHANGE_JOBS_FILE, 'data/jobs.jsonl' ),
		sessionSecret: str( env.EXCHANGE_SESSION_SECRET ),
		encryptionKey: str( env.EXCHANGE_ENCRYPTION_KEY ),
		adminToken: str( env.EXCHANGE_ADMIN_TOKEN ),
		controlSiteUrl: str( env.EXCHANGE_CONTROL_SITE_URL ),
		rateLimit: {
			windowMs: int( env.EXCHANGE_RATE_WINDOW_MS, 60_000 ),
			maxRequests: int( env.EXCHANGE_RATE_MAX, 120 ),
		},
		cloudways: {
			email: str( env.CLOUDWAYS_EMAIL ),
			apiKey: str( env.CLOUDWAYS_API_KEY ),
			// API v2 is primary (verified against the live API 2026-11-12; the
			// production dashboard + fleet clients also target v2).
			baseUrl: str( env.CLOUDWAYS_BASE_URL, 'https://api.cloudways.com/api/v2' ),
			legacyBaseUrl: str( env.CLOUDWAYS_LEGACY_BASE_URL, 'https://api.cloudways.com/api/v1' ),
			defaultServerId: str( env.CLOUDWAYS_DEFAULT_SERVER_ID ),
			appTimeoutMs: int( env.CLOUDWAYS_APP_TIMEOUT_MS, 600_000 ),
			appPollMs: int( env.CLOUDWAYS_APP_POLL_MS, 15_000 ),
			timeoutMs: int( env.CLOUDWAYS_TIMEOUT_MS, 20_000 ),
			retries: int( env.CLOUDWAYS_RETRIES, 1 ),
		},
		wp: {
			sshKeyPresent: bool( env.EXCHANGE_SSH_KEY, false ),
			wpManagerEnabled: bool( env.EXCHANGE_WP_MANAGER, true ),
		},
		github: {
			token: str( env.EXCHANGE_GITHUB_TOKEN ),
			repo: str( env.EXCHANGE_CONSOLE_REPO, 'nvdigitalsolutions/nvoos-complete-velocity' ),
			baseUrl: str( env.EXCHANGE_GITHUB_API, 'https://api.github.com' ),
			timeoutMs: int( env.EXCHANGE_GITHUB_TIMEOUT_MS, 20_000 ),
		},
		gateway: {
			baseUrl: str( env.GATEWAY_BASE_URL, 'https://mcp.nvoos.pro' ),
			adminKey: str( env.GATEWAY_ADMIN_KEY ),
			timeoutMs: int( env.GATEWAY_TIMEOUT_MS, 20_000 ),
		},
	};

	if ( mode === 'live' ) {
		const missing = [];
		if ( ! config.cloudways.email ) {
			missing.push( 'CLOUDWAYS_EMAIL' );
		}
		if ( ! config.cloudways.apiKey ) {
			missing.push( 'CLOUDWAYS_API_KEY' );
		}
		if ( ! config.cloudways.defaultServerId ) {
			missing.push( 'CLOUDWAYS_DEFAULT_SERVER_ID' );
		}
		if ( ! config.encryptionKey ) {
			missing.push( 'EXCHANGE_ENCRYPTION_KEY' );
		}
		if ( ! config.adminToken ) {
			missing.push( 'EXCHANGE_ADMIN_TOKEN' );
		}
		if ( missing.length ) {
			throw new ConfigError(
				`EXCHANGE_MODE=live is missing required env: ${ missing.join( ', ' ) }`,
				missing
			);
		}
	}

	return Object.freeze( config );
}
