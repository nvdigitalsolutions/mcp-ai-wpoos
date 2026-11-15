/**
 * Cloudways API client — verified against the live API (2026-11-12).
 *
 * Evidence base (see docs/operations/deployment/cloudways-api-verification-spike.md):
 *  - API v2 is the primary surface: the repo's production dashboard addon
 *    (`addons/cloudways-dashboard`) and the Pro fleet client
 *    (`addons/pro/includes/cloudways/`) both target
 *    `https://api.cloudways.com/api/v2`.
 *  - Live unauthenticated probes confirm v2 `/oauth/access_token`
 *    (JSON `invalid_credentials`), `/server`, `/servers`, `/app/{id}`,
 *    `POST /app`, and `POST /app/cache/purge` exist and answer JSON.
 *  - Create-app contract (production REST controller): `POST /app` with
 *    `{ server_id: "<string>", application, app_label, project_name }`.
 *  - App details: `GET /app/{id}` → `{ app: { status, cname, app_fqdn, sys_user, … } }`.
 *    `sys_user` is CONFIRMED present on the app record: the Cloudways MCP
 *    `app_get` tool returns it, cross-validated against the fleet folder map
 *    (`tvhqswptzh` for "MCP Server - Samtani").
 *  - Status polling: `running` = ready; `provisioning|pending|deploying` =
 *    keep waiting; `failed|error` = terminal failure.
 *
 * Still unverified (kept as explicit inputs / marked below):
 *  - `app_version`, `project_name`, and the cache-purge payload shape are
 *    accepted-but-unconfirmed; they are optional in the request builders.
 */

import { ApiError } from '../util/envelope.js';
import { requestJson } from '../util/http.js';

const RUNNING = 'running';
const FAILED = new Set( [ 'failed', 'error' ] );
const PENDING = new Set( [ 'provisioning', 'pending', 'deploying', 'unknown' ] );

function providerMessage( data ) {
	if ( ! data || typeof data !== 'object' ) {
		return '';
	}
	const text = data.error_description ?? data.message ?? data.error ?? '';
	return typeof text === 'string' ? text.slice( 0, 200 ) : '';
}

function providerCode( data ) {
	if ( ! data || typeof data !== 'object' ) {
		return null;
	}
	return typeof data.error === 'string' ? data.error : null;
}

export function createCloudwaysClient( {
	config,
	fetchImpl = globalThis.fetch,
	now = () => Date.now(),
	sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) ),
} ) {
	const { cloudways, mode } = config;
	let cachedToken = null;
	let expiresAt = 0;

	async function accessToken() {
		if ( mode === 'mock' ) {
			return 'mock-cloudways-token';
		}
		if ( cachedToken && now() < expiresAt - 60_000 ) {
			return cachedToken;
		}
		let response;
		try {
			response = await requestJson( `${ cloudways.baseUrl }/oauth/access_token`, {
				method: 'POST',
				body: { email: cloudways.email, api_key: cloudways.apiKey },
				bodyType: 'form',
				timeoutMs: cloudways.timeoutMs,
				retries: cloudways.retries,
				fetchImpl,
			} );
		} catch {
			throw new ApiError(
				'provider_error',
				'Cloudways authentication request failed',
				{ provider: 'cloudways' },
				502
			);
		}
		if ( ! response.ok || ! response.data || ! response.data.access_token ) {
			throw new ApiError(
				'provider_error',
				`Cloudways authentication failed (${ response.status })${ providerMessage( response.data ) ? `: ${ providerMessage( response.data ) }` : '' }`,
				{ provider: 'cloudways', status: response.status, providerCode: providerCode( response.data ) },
				502
			);
		}
		cachedToken = response.data.access_token;
		const ttlSeconds = Number( response.data.expires_in ) || 3600;
		expiresAt = now() + ttlSeconds * 1000;
		return cachedToken;
	}

	async function call( path, { method = 'GET', body, legacy = false } = {} ) {
		const token = await accessToken();
		const baseUrl = legacy ? cloudways.legacyBaseUrl : cloudways.baseUrl;
		let response;
		try {
			response = await requestJson( `${ baseUrl }${ path }`, {
				method,
				headers: {
					Authorization: `Bearer ${ token }`,
					Accept: 'application/json',
				},
				body,
				timeoutMs: cloudways.timeoutMs,
				retries: cloudways.retries,
				fetchImpl,
			} );
		} catch {
			throw new ApiError(
				'provider_error',
				`Cloudways ${ method } ${ path } failed (transport)`,
				{ provider: 'cloudways' },
				502
			);
		}
		if ( ! response.ok ) {
			throw new ApiError(
				'provider_error',
				`Cloudways ${ method } ${ path } failed (${ response.status })${ providerMessage( response.data ) ? `: ${ providerMessage( response.data ) }` : '' }`,
				{
					provider: 'cloudways',
					status: response.status,
					providerCode: providerCode( response.data ),
				},
				502
			);
		}
		return response.data;
	}

	/**
	 * List servers. v2 exposes both `/servers` (production dashboard) and
	 * `/server` (legacy shape, still live). Prefer `/servers`, fall back on
	 * a 404 only.
	 */
	async function listServers() {
		if ( mode === 'mock' ) {
			return [ { id: 1, label: 'Mock Server', apps: 0 } ];
		}
		let data;
		try {
			data = await call( '/servers' );
		} catch ( error ) {
			if ( error?.extra?.status !== 404 ) {
				throw error;
			}
			data = await call( '/server' );
		}
		if ( Array.isArray( data?.servers ) ) {
			return data.servers;
		}
		if ( Array.isArray( data?.server ) ) {
			return [ data.server ];
		}
		return [];
	}

	async function listApps( serverId ) {
		if ( mode === 'mock' ) {
			return [];
		}
		const data = await call( `/server/${ encodeURIComponent( String( serverId ) ) }/apps` );
		return Array.isArray( data?.apps ) ? data.apps : [];
	}

	async function getApp( appId ) {
		if ( mode === 'mock' ) {
			return { id: appId, status: RUNNING, cname: `mock-${ appId }.example.test` };
		}
		const data = await call( `/app/${ encodeURIComponent( String( appId ) ) }` );
		return data?.app ?? data;
	}

	async function findAppByLabel( { serverId, label } ) {
		const apps = await listApps( serverId );
		return apps.find( ( app ) => ( app.label ?? app.app_label ) === label ) ?? null;
	}

	/**
	 * Poll `GET /app/{id}` until the app is running.
	 *
	 * @param {object} input
	 * @param {string|number} input.appId
	 * @param {number} [input.timeoutMs]
	 * @param {number} [input.intervalMs]
	 * @return {Promise<object>} The running app record.
	 */
	async function waitForApp( {
		appId,
		timeoutMs = cloudways.appTimeoutMs ?? 600_000,
		intervalMs = cloudways.appPollMs ?? 15_000,
	} = {} ) {
		const deadline = now() + timeoutMs;
		let last = null;

		while ( now() < deadline ) {
			last = await getApp( appId );
			const status = String( last?.status ?? '' ).toLowerCase();
			if ( status === RUNNING ) {
				return last;
			}
			if ( FAILED.has( status ) ) {
				throw new ApiError(
					'provider_error',
					`Cloudways app ${ appId } entered status "${ status }"`,
					{ provider: 'cloudways', appId, appStatus: status },
					502
				);
			}
			await sleep( intervalMs );
		}

		throw new ApiError(
			'provider_error',
			`Cloudways app ${ appId } did not reach "running" within ${ Math.round( timeoutMs / 1000 ) }s`,
			{
				provider: 'cloudways',
				appId,
				appStatus: last?.status ?? null,
				remediation: 'Poll GET /app/{id} again or check the Cloudways console; provisioning can exceed the timeout on busy servers.',
			},
			502
		);
	}

	/**
	 * Idempotent app creation: returns the existing app when the label is
	 * already present on the server.
	 *
	 * Body verified against the production dashboard REST controller:
	 * `{ server_id: "<string>", application, app_label, project_name }`.
	 */
	async function ensureApp( {
		serverId,
		label,
		application = 'wordpress',
		projectName = '',
		appVersion = null,
	} ) {
		if ( mode === 'mock' ) {
			return { id: `mock-${ label }`, label, server_id: serverId, application, status: RUNNING };
		}
		const existing = await findAppByLabel( { serverId, label } );
		if ( existing ) {
			return existing;
		}
		const data = await call( '/app', {
			method: 'POST',
			body: {
				server_id: String( serverId ),
				application,
				app_label: label,
				...( projectName ? { project_name: projectName } : {} ),
				...( appVersion ? { app_version: appVersion } : {} ),
			},
		} );
		const created = data && typeof data === 'object' && data.app ? data.app : data;
		if ( ! created || typeof created !== 'object' ) {
			throw new ApiError(
				'provider_error',
				'Cloudways app creation returned no data',
				{ provider: 'cloudways' },
				502
			);
		}

		// Hydrate from GET /app/{id} when the creation response is minimal.
		// The app record carries status, cname/app_fqdn, and sys_user
		// (verified via the Cloudways MCP `app_get` tool).
		if (
			created.id &&
			( created.sys_user === undefined || created.status === undefined || created.cname === undefined )
		) {
			try {
				const full = await getApp( created.id );
				return { ...created, ...full };
			} catch {
				// Non-fatal: the caller can still poll/hydrate later.
			}
		}

		return created;
	}

	async function purgeCache( { serverId, appId } ) {
		if ( mode === 'mock' ) {
			return { ok: true };
		}
		// Route existence confirmed live (POST /app/cache/purge → JSON
		// invalid_request when unauthenticated). Payload shape unverified.
		return call( '/app/cache/purge', {
			method: 'POST',
			body: { server_id: String( serverId ), app_id: String( appId ) },
		} );
	}

	/**
	 * Public site URL from an app record (`cname` preferred, then `app_fqdn`).
	 *
	 * @param {object|null} app
	 * @return {string|null}
	 */
	function siteUrlFromApp( app ) {
		const host = app?.cname || app?.app_fqdn;
		return host ? `https://${ String( host ).replace( /^https?:\/\//, '' ) }` : null;
	}

	return {
		accessToken,
		listServers,
		listApps,
		getApp,
		waitForApp,
		findAppByLabel,
		ensureApp,
		purgeCache,
		siteUrlFromApp,
	};
}
