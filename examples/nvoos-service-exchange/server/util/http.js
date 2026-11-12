/**
 * Minimal JSON HTTP client with timeout + bounded retry.
 *
 * Zero-dependency (global fetch). The `fetchImpl` seam exists so every test
 * runs against an in-process fake — no real network in the suite.
 */

export class HttpError extends Error {
	constructor( message, { status = 0, url = '', body = null, cause = null } = {} ) {
		super( message );
		this.name = 'HttpError';
		this.status = status;
		this.url = url;
		this.body = body;
		this.cause = cause;
	}
}

function delay( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

function isRetryableNetworkError( error ) {
	// Abort/timeout and transport resets are worth one retry; programming
	// errors (TypeError from a bad URL) are not.
	if ( ! error ) {
		return false;
	}
	return (
		error.name === 'TimeoutError' ||
		error.name === 'AbortError' ||
		error.code === 'ECONNRESET' ||
		error.code === 'ECONNREFUSED' ||
		error.code === 'ETIMEDOUT' ||
		error.code === 'ENOTFOUND' ||
		error.name === 'FetchError' ||
		error.name === 'TypeError'
	);
}

/**
 * Perform a JSON request.
 *
 * @param {string} url
 * @param {object} options
 * @param {string} [options.method]        HTTP method.
 * @param {object} [options.headers]       Request headers.
 * @param {any}    [options.body]          Object → JSON, string → verbatim.
 * @param {string} [options.bodyType]      'json' (default) | 'form'.
 * @param {number} [options.timeoutMs]     Per-attempt timeout.
 * @param {number} [options.retries]       Additional attempts on 5xx/network.
 * @param {Function} [options.fetchImpl]   Injectable fetch (tests).
 * @return {Promise<{ok:boolean,status:number,headers:Headers,data:any}>}
 */
export async function requestJson( url, options = {} ) {
	const {
		method = 'GET',
		headers = {},
		body,
		bodyType = 'json',
		timeoutMs = 20_000,
		retries = 1,
		fetchImpl = globalThis.fetch,
	} = options;

	if ( typeof fetchImpl !== 'function' ) {
		throw new HttpError( 'No fetch implementation available', { url } );
	}

	let payload = body;
	let finalHeaders = { ...headers };
	if ( body !== undefined && body !== null ) {
		if ( bodyType === 'form' ) {
			payload = new URLSearchParams( body ).toString();
			finalHeaders[ 'Content-Type' ] = 'application/x-www-form-urlencoded';
		} else if ( typeof body === 'string' ) {
			payload = body;
		} else {
			payload = JSON.stringify( body );
			finalHeaders[ 'Content-Type' ] = finalHeaders[ 'Content-Type' ] || 'application/json';
		}
	}

	let lastError = null;
	for ( let attempt = 0; attempt <= retries; attempt += 1 ) {
		try {
			const response = await fetchImpl( url, {
				method,
				headers: finalHeaders,
				body: payload,
				signal: AbortSignal.timeout( timeoutMs ),
			} );

			const text = await response.text();
			let data = null;
			if ( text ) {
				try {
					data = JSON.parse( text );
				} catch {
					data = text;
				}
			}

			if ( response.status >= 500 && attempt < retries ) {
				await delay( 250 * 2 ** attempt );
				continue;
			}

			return {
				ok: response.ok,
				status: response.status,
				headers: response.headers,
				data,
			};
		} catch ( error ) {
			lastError = error;
			if ( attempt < retries && isRetryableNetworkError( error ) ) {
				await delay( 250 * 2 ** attempt );
				continue;
			}
			throw new HttpError( `Request to ${ url } failed: ${ error.message }`, {
				url,
				cause: error,
			} );
		}
	}

	throw new HttpError( `Request to ${ url } failed after retries`, {
		url,
		cause: lastError,
	} );
}
