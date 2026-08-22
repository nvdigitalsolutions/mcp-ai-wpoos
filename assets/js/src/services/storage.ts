/**
 * Storage Service for NV oOS Chat — TypeScript edition.
 *
 * Handles localStorage management, conversation persistence, and quota
 * monitoring.  Exports everything as named ESM exports while also
 * registering on `window.wpMcpAiChatStorage` for backward compatibility
 * with the plain-JS chat.js.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

import type { ChatMessage } from '@shared/types';

// ── Constants ────────────────────────────────────────────────────────

const STORAGE_KEY_PREFIX = 'wp_mcp_ai_chat_';
const STORAGE_EXPIRY_MS = 24 * 60 * 60 * 1000; // 24 hours
const STORAGE_SAVE_DEBOUNCE_MS = 300;
const _win = window as unknown as Record< string, unknown >;
const _console = ( window.console ?? null ) as Console | null;
const DEBUG_MODE = _win.wpMcpAiChatDebugMode === true;
const OPTIMIZATIONS_ENABLED = ! DEBUG_MODE;

// ── State ────────────────────────────────────────────────────────────

const storageSaveTimers: Record< string, ReturnType< typeof setTimeout > > = {};

// ── Types ────────────────────────────────────────────────────────────

export interface QuotaData {
	used: number;
	wpMcpAiUsed?: number;
	total: number;
	percentage: number;
	available: boolean;
	formattedUsed?: string;
	formattedWpMcpAiUsed?: string;
	formattedTotal?: string;
}

export interface SaveResult {
	success: boolean;
	skipped?: boolean;
	cleaned?: number;
	debounced?: boolean;
	offloaded?: boolean;
	error?: string;
}

export interface LoadResult {
	conversation: ChatMessage[];
	sessionKey: string;
	assistantId: string;
}

export interface ExportResult {
	success: boolean;
	content?: string;
	filename?: string;
	mimeType?: string;
	error?: string;
}

/** Minimal state shape needed by storage functions. */
export interface StorageState {
	conversation: ChatMessage[];
	config: {
		assistantId?: string;
		sessionKey?: string;
	};
	originalAssistantId?: string;
}

// ── Quota Monitor ────────────────────────────────────────────────────

const quotaMonitorCache = {
	lastCalculated: 0,
	cachedQuota: { used: 0, total: 0, percentage: 0, available: false } as QuotaData,
	calculating: false,
	CACHE_DURATION: 30000,

	getQuota( callback?: ( quota: QuotaData ) => void ): void {
		const now = Date.now();
		const cacheValid = ( now - this.lastCalculated ) < this.CACHE_DURATION;

		if ( cacheValid && this.cachedQuota.available ) {
			callback?.( this.cachedQuota );
			return;
		}

		if ( this.calculating ) {
			callback?.( this.cachedQuota );
			return;
		}

		this.calculating = true;

		const performCalculation = (): void => {
			try {
				const quota = calculateQuotaSync();
				this.cachedQuota = quota;
				this.lastCalculated = Date.now();
				this.calculating = false;
				callback?.( quota );
			} catch ( _error ) {
				this.calculating = false;
				if ( _console?.error ) {
					_console.error( 'Error calculating localStorage quota:', _error );
				}
			}
		};

		if ( OPTIMIZATIONS_ENABLED && window.requestIdleCallback ) {
			window.requestIdleCallback( performCalculation, { timeout: 2000 } );
		} else {
			setTimeout( performCalculation, 0 );
		}
	},
};

// ── Helpers ──────────────────────────────────────────────────────────

export function formatBytes( bytes: number, decimals = 2 ): string {
	if ( bytes === 0 ) { return '0 Bytes'; }
	const k = 1024;
	const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( decimals ) ) + ' ' + sizes[ i ];
}

function calculateQuotaSync(): QuotaData {
	if ( ! window.localStorage ) {
		return { used: 0, total: 0, percentage: 0, available: false };
	}

	let totalSize = 0;
	let wpMcpAiSize = 0;

	for ( let i = 0; i < window.localStorage.length; i++ ) {
		const key = window.localStorage.key( i );
		if ( ! key ) { continue; }

		const value = window.localStorage.getItem( key );
		if ( value ) {
			const itemSize = key.length + value.length;
			totalSize += itemSize;
			if ( key.startsWith( STORAGE_KEY_PREFIX ) ) {
				wpMcpAiSize += itemSize;
			}
		}
	}

	const estimatedQuota = 5 * 1024 * 1024; // 5 MB
	const percentage = Math.min( ( totalSize / estimatedQuota ) * 100, 100 );

	return {
		used: totalSize,
		wpMcpAiUsed: wpMcpAiSize,
		total: estimatedQuota,
		percentage,
		available: true,
		formattedUsed: formatBytes( totalSize ),
		formattedWpMcpAiUsed: formatBytes( wpMcpAiSize ),
		formattedTotal: formatBytes( estimatedQuota ),
	};
}

function getStorageKey( assistantId: string ): string {
	return STORAGE_KEY_PREFIX + assistantId;
}

export function sanitizeSessionKey( sessionKey: string ): string {
	if ( ! sessionKey || typeof sessionKey !== 'string' ) { return ''; }
	return sessionKey.replace( /[^a-zA-Z0-9_-]/g, '' );
}

export function getLocalStorageQuota( callback?: ( quota: QuotaData ) => void ): void {
	quotaMonitorCache.getQuota( callback );
}

// ── Cleanup ──────────────────────────────────────────────────────────

export function cleanupOldStorageEntries(): number {
	if ( ! window.localStorage ) { return 0; }

	let cleaned = 0;
	const now = Date.now();
	const keysToRemove: string[] = [];

	try {
		for ( let i = 0; i < window.localStorage.length; i++ ) {
			const key = window.localStorage.key( i );
			if ( ! key || ! key.startsWith( STORAGE_KEY_PREFIX ) ) { continue; }

			try {
				const stored = window.localStorage.getItem( key );
				if ( ! stored ) {
					keysToRemove.push( key );
					continue;
				}
				const data = JSON.parse( stored ) as { timestamp?: number };
				if ( data?.timestamp && ( now - data.timestamp ) > STORAGE_EXPIRY_MS ) {
					keysToRemove.push( key );
				}
			} catch {
				keysToRemove.push( key );
			}
		}

		for ( const key of keysToRemove ) {
			try {
				window.localStorage.removeItem( key );
				cleaned++;
			} catch { /* ignore */ }
		}

		if ( cleaned > 0 && _console?.info ) {
			_console.info( 'Cleaned up ' + cleaned + ' old conversation(s) from localStorage' );
		}
	} catch ( error ) {
		if ( _console?.warn ) {
			_console.warn( 'Error during localStorage cleanup:', error );
		}
	}

	return cleaned;
}

// ── Save / Load / Clear ──────────────────────────────────────────────

/**
 * Cheap size estimate for the offload threshold decision — avoids the
 * expensive JSON.stringify() on the main thread (proposal 032, D1).
 */
function estimateDataSize( value: unknown ): number {
	let size = 0;
	let nodeCount = 0;
	const stack: unknown[] = [ value ];

	while ( stack.length > 0 ) {
		const current = stack.pop();
		if ( typeof current === 'string' ) { size += current.length; continue; }
		if ( current && typeof current === 'object' ) {
			nodeCount++;
			if ( nodeCount > 10000 ) {
				// Pathological structure — assume it is large.
				size += 1000000;
				break;
			}
			for ( const key of Object.keys( current ) ) {
				size += key.length + 2;
				stack.push( ( current as Record< string, unknown > )[ key ] );
			}
		}
	}

	return size;
}

export function saveConversationToStorage(
	state: StorageState,
	options: { immediate?: boolean } = {},
): SaveResult {
	if ( ! state?.config ) { return { success: false, skipped: true }; }

	const assistantId: string = state.originalAssistantId || state.config.assistantId || '';
	if ( ! assistantId ) { return { success: false, skipped: true }; }
	if ( ! window.localStorage ) { return { success: false, error: 'localStorage not available' }; }

	const forceImmediate = options.immediate === true;

	// Storage-worker offload config (proposal 032, D4/D5). A non-positive
	// threshold disables offload (kill switch); the unload flush (immediate)
	// always writes synchronously.
	const storageUtil = ( window as unknown as {
		wpMcpAiStorageUtil?: { stringifyJSON( obj: unknown, threshold?: number ): Promise< string > };
	} ).wpMcpAiStorageUtil || null;
	const chatConfig = window.wpMcpAiChat as Record< string, unknown > | undefined;
	const workerThreshold = typeof chatConfig?.storageWorkerThreshold === 'number' ? ( chatConfig.storageWorkerThreshold as number ) : 10000;
	const offloadEnabled = !!( storageUtil && typeof storageUtil.stringifyJSON === 'function' && workerThreshold > 0 );

	function buildData(): Record< string, unknown > {
		return {
			conversation: state.conversation || [],
			sessionKey: sanitizeSessionKey( state.config.sessionKey || '' ),
			timestamp: Date.now(),
			assistantId,
		};
	}

	function writeSerialised( storageKey: string, serialised: string ): SaveResult {
		try {
			window.localStorage.setItem( storageKey, serialised );
			return { success: true };
		} catch ( error ) {
			const err = error as DOMException;
			const isQuotaError = err.name === 'QuotaExceededError' ||
				err.code === 22 ||
				( err as unknown as { code: number } ).code === 1014;

			if ( isQuotaError ) {
				const cleaned = cleanupOldStorageEntries();
				if ( cleaned > 0 ) {
					try {
						window.localStorage.setItem( storageKey, serialised );
						return { success: true, cleaned };
					} catch ( _retryError ) {
						return { success: false, error: 'localStorage quota exceeded', cleaned };
					}
				}
				return { success: false, error: 'localStorage quota exceeded' };
			}

			return { success: false, error: err.message || 'localStorage error' };
		}
	}

	function performSave(): SaveResult {
		const storageKey = getStorageKey( assistantId );
		const data = buildData();

		// Unload flushes must persist synchronously (D5); everything else can
		// offload the expensive stringify to the storage worker.
		if ( forceImmediate || ! offloadEnabled ) {
			return writeSerialised( storageKey, JSON.stringify( data ) );
		}

		// Cheap size gate: only delegate genuinely large payloads so the
		// main-thread stringify is skipped; the util then posts directly.
		if ( estimateDataSize( data ) < workerThreshold ) {
			return writeSerialised( storageKey, JSON.stringify( data ) );
		}

		storageUtil!.stringifyJSON( data, workerThreshold ).then( ( serialised ) => {
			writeSerialised( storageKey, serialised );
		} ).catch( () => {
			// The util already falls back internally; this is a last resort.
			writeSerialised( storageKey, JSON.stringify( data ) );
		} );

		return { success: true, offloaded: true };
	}

	if ( ! OPTIMIZATIONS_ENABLED || forceImmediate ) {
		return performSave();
	}

	if ( storageSaveTimers[ assistantId ] ) {
		clearTimeout( storageSaveTimers[ assistantId ] );
	}

	storageSaveTimers[ assistantId ] = setTimeout( () => {
		performSave();
		delete storageSaveTimers[ assistantId ];
	}, STORAGE_SAVE_DEBOUNCE_MS );

	return { success: true, debounced: true };
}

export function loadConversationFromStorage( state: StorageState ): LoadResult | null {
	if ( ! state?.config ) { return null; }

	const assistantId: string = state.originalAssistantId || state.config.assistantId || '';
	if ( ! assistantId ) { return null; }
	if ( ! window.localStorage ) { return null; }

	try {
		const storageKey = getStorageKey( assistantId );
		const stored = window.localStorage.getItem( storageKey );
		if ( ! stored ) { return null; }

		const data = JSON.parse( stored ) as {
			conversation?: ChatMessage[];
			sessionKey?: string;
			timestamp?: number;
			assistantId?: string;
		};

		if ( ! data || typeof data !== 'object' ) { return null; }

		const age = Date.now() - ( data.timestamp || 0 );
		if ( age > STORAGE_EXPIRY_MS ) {
			window.localStorage.removeItem( storageKey );
			return null;
		}

		return {
			conversation: Array.isArray( data.conversation ) ? data.conversation : [],
			sessionKey: sanitizeSessionKey( data.sessionKey || '' ),
			assistantId: data.assistantId || assistantId,
		};
	} catch {
		return null;
	}
}

export function clearConversationFromStorage( state: StorageState ): void {
	if ( ! state?.config ) { return; }
	const assistantId: string = state.originalAssistantId || state.config.assistantId || '';
	if ( ! assistantId || ! window.localStorage ) { return; }

	try {
		const storageKey = getStorageKey( assistantId );
		window.localStorage.removeItem( storageKey );
	} catch { /* silent */ }
}

// ── Export ───────────────────────────────────────────────────────────

export function exportConversation( state: StorageState, format: string ): ExportResult {
	if ( ! state?.conversation || ! Array.isArray( state.conversation ) ) {
		return { success: false, error: 'No conversation to export' };
	}

	const conversation = state.conversation;
	const assistantId = state.config.assistantId || 'unknown';
	const sessionKey = state.config.sessionKey || '';
	const timestamp = new Date().toISOString().replace( /[:.]/g, '-' );

	let content = '';
	let filename = '';
	let mimeType = 'text/plain';

	try {
		if ( format === 'json' ) {
			content = JSON.stringify( {
				assistant_id: assistantId,
				session_key: sessionKey,
				exported_at: new Date().toISOString(),
				messages: conversation,
			}, null, 2 );
			filename = 'chat-' + assistantId + '-' + timestamp + '.json';
			mimeType = 'application/json';
		} else if ( format === 'markdown' ) {
			const lines = [
				'# Chat Conversation', '',
				'**Assistant ID:** ' + assistantId,
				sessionKey ? '**Session Key:** ' + sessionKey : '',
				'**Exported:** ' + new Date().toLocaleString(),
				'', '---', '',
			].filter( Boolean );

			for ( const message of conversation ) {
				const role = message.role || 'unknown';
				const text = typeof message.content === 'string' ? message.content : JSON.stringify( message.content );
				lines.push( '## ' + role.charAt( 0 ).toUpperCase() + role.slice( 1 ), '', text, '' );
			}
			content = lines.join( '\n' );
			filename = 'chat-' + assistantId + '-' + timestamp + '.md';
			mimeType = 'text/markdown';
		} else {
			const lines = [
				'Chat Conversation', '',
				'Assistant ID: ' + assistantId,
				sessionKey ? 'Session Key: ' + sessionKey : '',
				'Exported: ' + new Date().toLocaleString(),
				'', '----------------------------------------', '',
			].filter( Boolean );

			for ( const message of conversation ) {
				const role = message.role || 'unknown';
				const text = typeof message.content === 'string' ? message.content : JSON.stringify( message.content );
				lines.push( role.toUpperCase() + ':', text, '' );
			}
			content = lines.join( '\n' );
			filename = 'chat-' + assistantId + '-' + timestamp + '.txt';
		}

		return { success: true, content, filename, mimeType };
	} catch ( error ) {
		return { success: false, error: ( error as Error ).message || 'Export failed' };
	}
}

// ── Backward-compatible global registration ──────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiChatStorage = {
	getStorageKey,
	sanitizeSessionKey,
	getLocalStorageQuota,
	formatBytes,
	cleanupOldStorageEntries,
	saveConversationToStorage,
	loadConversationFromStorage,
	clearConversationFromStorage,
	exportConversation,
};
