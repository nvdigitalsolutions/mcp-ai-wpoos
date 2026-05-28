/**
 * HTTP Client Service for NV oOS Chat — TypeScript edition.
 *
 * Wraps `ky` for resilient HTTP requests with automatic retry and
 * exponential backoff.  Provides typed `postJson`, `get`, `delete`,
 * `uploadFile`, and `parseError` helpers.
 *
 * NOTE: This module imports `ky` at the top level.  esbuild handles
 * this during the build.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

import ky, { type Options as KyOptions, type HTTPError } from 'ky';

// ── Types ────────────────────────────────────────────────────────────

interface RetryEvent {
	url: string;
	error: Error;
	retryCount: number;
	maxRetries: number;
}

interface AuthFailureEvent {
	url: string;
	status: number;
	statusText: string;
}

export interface HttpClientOptions {
	timeout?: number;
	retryLimit?: number;
	onRetry?: ( event: RetryEvent ) => void;
	beforeRequest?: ( request: Request ) => void;
	afterResponse?: ( request: Request, response: Response ) => void;
	onAuthFailure?: ( event: AuthFailureEvent ) => void;
	signal?: AbortSignal;
}

interface RetryConfig {
	limit: number;
	methods: string[];
	statusCodes: number[];
	backoffLimit: number;
}

// ── Constants ────────────────────────────────────────────────────────

export const DEFAULT_TIMEOUT = 30000;

export const DEFAULT_RETRY_CONFIG: RetryConfig = {
	limit: 3,
	methods: [ 'get', 'post', 'put', 'patch', 'delete' ],
	statusCodes: [ 408, 413, 429, 500, 502, 503, 504 ],
	backoffLimit: 10000,
};

// ── Client factory ───────────────────────────────────────────────────

export function createHttpClient( options: HttpClientOptions = {} ): typeof ky {
	const retryConfig: RetryConfig = {
		...DEFAULT_RETRY_CONFIG,
		limit: options.retryLimit ?? DEFAULT_RETRY_CONFIG.limit,
	};

	const beforeRetry: NonNullable< KyOptions[ 'hooks' ] >[ 'beforeRetry' ] = [];
	const beforeRequest: NonNullable< KyOptions[ 'hooks' ] >[ 'beforeRequest' ] = [];
	const afterResponse: NonNullable< KyOptions[ 'hooks' ] >[ 'afterResponse' ] = [];

	if ( options.onRetry ) {
		beforeRetry.push( ( { request, error, retryCount } ) => {
			options.onRetry!( {
				url: request.url,
				error,
				retryCount,
				maxRetries: retryConfig.limit,
			} );
		} );
	}

	if ( options.beforeRequest ) {
		beforeRequest.push( ( request: Request ) => {
			options.beforeRequest!( request );
		} );
	}

	afterResponse.push( ( request, _opts, response ) => {
		if ( response.status === 401 && options.onAuthFailure ) {
			options.onAuthFailure( {
				url: request.url,
				status: response.status,
				statusText: response.statusText,
			} );
		}
		return response;
	} );

	if ( options.afterResponse ) {
		afterResponse.push( ( request, _opts, response ) => {
			options.afterResponse!( request, response );
			return response;
		} );
	}

	return ky.create( {
		retry: retryConfig,
		timeout: options.timeout ?? DEFAULT_TIMEOUT,
		hooks: {
			beforeRetry,
			beforeRequest,
			afterResponse,
		},
	} );
}

// ── Convenience methods ──────────────────────────────────────────────

export function postJson(
	url: string,
	data: unknown,
	headers: Record< string, string > = {},
	options: HttpClientOptions = {},
): Promise< Response > {
	const client = createHttpClient( options );

	const requestOptions: RequestInit & { json: unknown; headers: Record< string, string > } = {
		json: data,
		headers,
		credentials: 'same-origin' as RequestCredentials,
	};

	if ( options.signal ) {
		requestOptions.signal = options.signal;
	}

	return client.post( url, requestOptions );
}

export function uploadFile(
	url: string,
	file: File | Blob,
	headers: Record< string, string > = {},
	options: HttpClientOptions = {},
): Promise< Response > {
	const client = createHttpClient( options );

	const requestOptions: RequestInit & { body: File | Blob; headers: Record< string, string > } = {
		body: file,
		headers,
		credentials: 'same-origin' as RequestCredentials,
	};

	if ( options.signal ) {
		requestOptions.signal = options.signal;
	}

	return client.post( url, requestOptions );
}

export function httpGet(
	url: string,
	headers: Record< string, string > = {},
	options: HttpClientOptions = {},
): Promise< Response > {
	const client = createHttpClient( options );

	const requestOptions: RequestInit & { headers: Record< string, string > } = {
		headers,
		credentials: 'same-origin' as RequestCredentials,
	};

	if ( options.signal ) {
		requestOptions.signal = options.signal;
	}

	return client.get( url, requestOptions );
}

export function httpDelete(
	url: string,
	headers: Record< string, string > = {},
	options: HttpClientOptions = {},
): Promise< Response > {
	const client = createHttpClient( options );

	const requestOptions: RequestInit & { headers: Record< string, string > } = {
		headers,
		credentials: 'same-origin' as RequestCredentials,
	};

	if ( options.signal ) {
		requestOptions.signal = options.signal;
	}

	return client.delete( url, requestOptions );
}

// ── Error parsing ────────────────────────────────────────────────────

export interface ParsedError {
	message: string;
	status: number | null;
	statusText: string | null;
	data: unknown;
}

export async function parseError( error: HTTPError ): Promise< ParsedError > {
	const result: ParsedError = {
		message: error.message || 'Unknown error',
		status: null,
		statusText: null,
		data: null,
	};

	if ( error.response ) {
		result.status = error.response.status;
		result.statusText = error.response.statusText;

		try {
			result.data = await error.response.json();
		} catch {
			try {
				result.data = await error.response.text();
			} catch {
				/* ignore */
			}
		}
	}

	return result;
}

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiHttpClient = {
	createHttpClient,
	postJson,
	uploadFile,
	get: httpGet,
	delete: httpDelete,
	parseError,
	DEFAULT_TIMEOUT,
	DEFAULT_RETRY_CONFIG,
};
