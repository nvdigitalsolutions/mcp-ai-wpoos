// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Intuit OAuth token manager and QuickBooks Online accounting API client.
 *
 * The access token lives ~60 minutes. The refresh token rotates on most
 * refreshes, so the newest one is persisted to disk — losing it strands the
 * connection permanently (Intuit only issues a new one through a fresh
 * browser sign-in).
 */

import { promises as fs } from 'node:fs';

const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

/**
 * Accounting API failure.
 */
export class IntuitError extends Error {
	/**
	 * @param {string} message      Human-readable message.
	 * @param {Object} [details]    Optional detail bag.
	 * @param {number} [details.status] HTTP status.
	 * @param {string} [details.body]   Raw response body.
	 * @param {Object} [details.fault]  Parsed Intuit Fault object.
	 */
	constructor( message, { status = 0, body = '', fault = null } = {} ) {
		super( message );
		this.name = 'IntuitError';
		this.status = status;
		this.body = body;
		this.fault = fault;
	}
}

/**
 * QuickBooks Online client.
 */
export class IntuitClient {
	#accessToken = null;

	#expiresAt = 0;

	#refreshToken;

	#inflight = null;

	/**
	 * @param {Object} config Configuration from loadConfig().
	 */
	constructor( config ) {
		this.config = config;
		this.#refreshToken = config.refreshToken;
	}

	/**
	 * Load a persisted rotated refresh token, if one exists.
	 *
	 * The state file wins over the environment: after the first refresh the
	 * env value is stale and would fail with invalid_grant.
	 *
	 * @return {Promise<void>}
	 */
	async init() {
		try {
			const raw = await fs.readFile( this.config.stateFile, 'utf8' );
			const parsed = JSON.parse( raw );

			if ( parsed && 'string' === typeof parsed.refresh_token && '' !== parsed.refresh_token ) {
				this.#refreshToken = parsed.refresh_token;
			}

			if ( parsed && 'number' === typeof parsed.expires_at && parsed.expires_at > Date.now() && parsed.access_token ) {
				this.#accessToken = parsed.access_token;
				this.#expiresAt = parsed.expires_at;
			}
		} catch ( error ) {
			if ( 'ENOENT' !== error.code ) {
				console.warn( `[qbo] could not read state file: ${ error.message }` );
			}
		}
	}

	/**
	 * Return a valid access token, refreshing when it is within 60 s of expiry.
	 *
	 * Refreshes are single-flight: concurrent tool calls share one token
	 * request, so a rotation cannot race itself.
	 *
	 * @return {Promise<string>} Access token.
	 */
	async getAccessToken() {
		if ( this.#accessToken && Date.now() < this.#expiresAt - 60_000 ) {
			return this.#accessToken;
		}

		if ( ! this.#inflight ) {
			this.#inflight = this.#refresh().finally( () => {
				this.#inflight = null;
			} );
		}

		return this.#inflight;
	}

	/**
	 * Exchange the refresh token for a new access token.
	 *
	 * @return {Promise<string>} Access token.
	 */
	async #refresh() {
		const basic = Buffer.from(
			`${ this.config.clientId }:${ this.config.clientSecret }`,
			'utf8'
		).toString( 'base64' );

		const response = await fetch( this.config.tokenUrl || TOKEN_URL, {
			method: 'POST',
			headers: {
				Authorization: `Basic ${ basic }`,
				Accept: 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				grant_type: 'refresh_token',
				refresh_token: this.#refreshToken,
			} ).toString(),
		} );

		const text = await response.text();

		if ( ! response.ok ) {
			throw new IntuitError(
				`Intuit token refresh failed (${ response.status }). Re-run the browser sign-in if the refresh token has expired or been rotated elsewhere.`,
				{ status: response.status, body: text }
			);
		}

		let data;
		try {
			data = JSON.parse( text );
		} catch ( error ) {
			throw new IntuitError( 'Intuit token endpoint returned invalid JSON.', {
				status: response.status,
				body: text,
			} );
		}

		if ( ! data.access_token ) {
			throw new IntuitError( 'Intuit token endpoint returned no access token.', {
				status: response.status,
				body: text,
			} );
		}

		this.#accessToken = data.access_token;
		this.#expiresAt = Date.now() + Number( data.expires_in || 3600 ) * 1000;

		if ( data.refresh_token && data.refresh_token !== this.#refreshToken ) {
			this.#refreshToken = data.refresh_token;
			await this.#persist();
		}

		return this.#accessToken;
	}

	/**
	 * Persist the current refresh token so a restart does not lose the grant.
	 *
	 * @return {Promise<void>}
	 */
	async #persist() {
		try {
			await fs.writeFile(
				this.config.stateFile,
				JSON.stringify(
					{ refresh_token: this.#refreshToken, updated_at: new Date().toISOString() },
					null,
					'\t'
				) + '\n',
				{ mode: 0o600 }
			);
		} catch ( error ) {
			console.error(
				`[qbo] CRITICAL: could not persist rotated refresh token (${ error.message }). A restart or a concurrent refresh will invalidate the grant.`
			);
		}
	}

	/**
	 * Call the accounting API.
	 *
	 * @param {string} method        HTTP method.
	 * @param {string} path          Path below /company/{realm}, starting with "/".
	 * @param {Object} [options]     Request options.
	 * @param {Object} [options.query] Query parameters.
	 * @param {Object} [options.body]  JSON body.
	 * @return {Promise<Object>} Parsed response.
	 */
	async request( method, path, { query, body } = {} ) {
		const token = await this.getAccessToken();
		const base = `${ this.config.baseUrl }/company/${ encodeURIComponent(
			this.config.realmId
		) }`;
		const url = new URL( `${ base }${ path }` );

		for ( const [ key, value ] of Object.entries( query || {} ) ) {
			if ( undefined !== value && null !== value && '' !== value ) {
				url.searchParams.set( key, String( value ) );
			}
		}

		const headers = { Authorization: `Bearer ${ token }`, Accept: 'application/json' };
		const init = { method, headers };

		if ( undefined !== body ) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify( body );
		}

		const response = await fetch( url, init );
		const text = await response.text();

		if ( ! response.ok ) {
			let fault = null;
			try {
				fault = JSON.parse( text )?.Fault || null;
			} catch ( error ) {
				// Intuit returns XML on some auth failures; keep the raw body.
			}

			const detail = fault?.Error?.[ 0 ]?.Message || '';
			throw new IntuitError(
				`QuickBooks API ${ method } ${ path } failed (${ response.status })${ detail ? `: ${ detail }` : '' }`,
				{ status: response.status, body: text, fault }
			);
		}

		if ( '' === text ) {
			return {};
		}

		try {
			return JSON.parse( text );
		} catch ( error ) {
			throw new IntuitError( 'QuickBooks API returned invalid JSON.', {
				status: response.status,
				body: text,
			} );
		}
	}

	/**
	 * Run a QuickBooks SQL query.
	 *
	 * @param {string} statement QuickBooks query statement.
	 * @return {Promise<Object>} Query response.
	 */
	async query( statement ) {
		return this.request( 'GET', '/query', { query: { query: statement } } );
	}

	/**
	 * Fetch a report.
	 *
	 * @param {string} name        Report name, e.g. ProfitAndLoss.
	 * @param {Object} [params]    Report parameters.
	 * @return {Promise<Object>} Report response.
	 */
	async report( name, params = {} ) {
		return this.request( 'GET', `/reports/${ encodeURIComponent( name ) }`, { query: params } );
	}

	/**
	 * Read a single entity by ID.
	 *
	 * @param {string} entity Entity name, e.g. Invoice.
	 * @param {string} id     Entity ID.
	 * @return {Promise<Object>} Entity response.
	 */
	async read( entity, id ) {
		return this.request(
			'GET',
			`/${ encodeURIComponent( entity ) }/${ encodeURIComponent( id ) }`
		);
	}

	/**
	 * Create an entity.
	 *
	 * @param {string} entity Entity name, e.g. Invoice.
	 * @param {Object} payload Entity payload.
	 * @return {Promise<Object>} Created entity response.
	 */
	async create( entity, payload ) {
		return this.request( 'POST', `/${ encodeURIComponent( entity ) }`, { body: payload } );
	}
}
