/**
 * SSE Service for NV oOS Chat — TypeScript edition.
 *
 * Wraps `@microsoft/fetch-event-source` for reliable Server-Sent Events
 * with POST support, custom headers, and automatic reconnection.
 * Backward-compatible with the original EventSource-based API.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

import { fetchEventSource, type EventSourceMessage } from '@microsoft/fetch-event-source';

// ── Constants ────────────────────────────────────────────────────────

const MAX_RECONNECT_ATTEMPTS = 10;

const READY_STATE = {
	CONNECTING: 0,
	OPEN: 1,
	CLOSED: 2,
} as const;

const READY_STATE_NAMES: Record< number, string > = {
	0: 'CONNECTING',
	1: 'OPEN',
	2: 'CLOSED',
};

// ── Types ────────────────────────────────────────────────────────────

export type ReadyState = typeof READY_STATE[ keyof typeof READY_STATE ];

export interface SseConnectionOptions {
	method?: string;
	headers?: Record< string, string >;
	body?: string | Record< string, unknown >;
	onMessage?: ( data: unknown, event: EventSourceMessage ) => void;
	onError?: ( error: unknown ) => void;
	onOpen?: ( response: Response ) => void;
	eventHandlers?: Record< string, ( data: unknown, event: EventSourceMessage ) => void >;
	openWhenHidden?: boolean;
}

export type ConnectionStatus = 'connecting' | 'open' | 'closed';

interface ConnectionEntry {
	ctrl: AbortController;
	url: string;
	createdAt: number;
	promise: Promise< void >;
	status: ConnectionStatus;
}

// ── Service ──────────────────────────────────────────────────────────

const connections: Record< string, ConnectionEntry > = {};

function generateConnectionKey( url: string ): string {
	return 'sse_fetch_' + url.replace( /[^a-zA-Z0-9]/g, '_' ) + '_' + Date.now();
}

export function isSseSupported(): boolean {
	return typeof fetch !== 'undefined' && typeof AbortController !== 'undefined';
}

export function getReadyStateName( readyState: number ): string {
	return READY_STATE_NAMES[ readyState ] || 'UNKNOWN';
}

export function connect( url: string, options: SseConnectionOptions = {} ): {
	ctrl: AbortController;
	close: () => void;
	abort: () => void;
	getStatus: () => ConnectionStatus;
} | null {
	if ( ! isSseSupported() ) {
		options.onError?.( new Error( 'fetch or AbortController not supported' ) );
		return null;
	}
	if ( ! url || typeof url !== 'string' ) {
		options.onError?.( new Error( 'Invalid URL' ) );
		return null;
	}

	try {
		const ctrl = new AbortController();
		const connectionKey = generateConnectionKey( url );
		let reconnectAttempts = 0;

		const fetchOptions: Parameters< typeof fetchEventSource >[ 1 ] = {
			method: options.method || 'GET',
			headers: options.headers || {},
			signal: ctrl.signal,
			openWhenHidden: options.openWhenHidden ?? false,

			async onopen( response ) {
				reconnectAttempts = 0;
				if ( connections[ connectionKey ] ) {
					connections[ connectionKey ].status = 'open';
				}

				if ( response.ok && response.headers.get( 'content-type' )?.includes( 'text/event-stream' ) ) {
					options.onOpen?.( response );
					return;
				}

				if ( response.status >= 400 && response.status < 500 && response.status !== 429 ) {
					const errorText = await response.text();
					throw new Error( 'Client error (' + response.status + '): ' + errorText );
				}
				throw new Error( 'Server error (' + response.status + ')' );
			},

			onmessage( event ) {
				try {
					if ( event.data === '[DONE]' ) { return; }

					let data: unknown = event.data;
					try {
						data = JSON.parse( event.data );
					} catch { /* raw string is fine */ }

					if ( event.event && options.eventHandlers?.[ event.event ] ) {
						options.eventHandlers[ event.event ]( data, event );
					}

					options.onMessage?.( data, event );
				} catch ( _parseError ) {
					if ( ( window as unknown as { console?: Console } ).console?.error ) {
						console.error( '[NV oOS SSE] Failed to parse message:', _parseError );
					}
				}
			},

			onclose() {
				if ( connections[ connectionKey ] ) {
					connections[ connectionKey ].status = 'closed';
				}
			},

			onerror( err ) {
				reconnectAttempts++;
				options.onError?.( err );

				// Stop reconnecting on client errors (4xx except 429).
				if ( err instanceof Error && err.message.includes( 'Client error' ) ) {
					throw err;
				}

				if ( reconnectAttempts >= MAX_RECONNECT_ATTEMPTS ) {
					throw new Error( 'Max reconnection attempts reached' );
				}
				// For other errors, allow automatic retry.
			},
		};

		// Add body for POST/PUT.
		if ( options.body ) {
			const method = ( options.method || 'GET' ).toUpperCase();
			if ( method === 'POST' || method === 'PUT' ) {
				fetchOptions.body = typeof options.body === 'string'
					? options.body
					: JSON.stringify( options.body );
			}
		}

		connections[ connectionKey ] = {
			ctrl,
			url,
			createdAt: Date.now(),
			promise: fetchEventSource( url, fetchOptions ),
			status: 'connecting',
		};

		return {
			ctrl,
			close() { closeConnection( connectionKey ); },
			abort() { ctrl.abort(); },
			getStatus() { return connections[ connectionKey ]?.status ?? 'closed'; },
		};
	} catch ( error ) {
		options.onError?.( error );
		return null;
	}
}

export function closeConnection( key: string ): void {
	if ( connections[ key ] ) {
		connections[ key ].ctrl.abort();
		delete connections[ key ];
	}
}

export function closeAll(): void {
	for ( const key of Object.keys( connections ) ) {
		closeConnection( key );
	}
}

export function getConnectionCount(): number {
	return Object.keys( connections ).length;
}

export function getConnectionStatus( url: string ): ConnectionStatus {
	for ( const key of Object.keys( connections ) ) {
		const conn = connections[ key ];
		if ( conn?.url === url ) {
			return conn.status;
		}
	}
	return 'closed';
}

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiSSE = {
	isSupported: isSseSupported,
	getReadyStateName,
	connect,
	closeConnection,
	closeAll,
	generateConnectionKey,
	getConnectionCount,
	getConnectionStatus,
};

// Clean up on page unload.
window.addEventListener( 'beforeunload', () => { closeAll(); } );
