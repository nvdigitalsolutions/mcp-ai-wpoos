/**
 * Chat Memory Service for NV oOS Chat — TypeScript edition.
 *
 * Thin client for `/mcp-ai/v1/chat-memory/*` REST proxy. Provides
 * promise-based wrappers: wakeUp, recall, store, storeBeacon, update,
 * delete, audit, sessionReplay, getPreferences, setPreferences.
 *
 * @package NV_MCP_AI
 * @since   1.6.0
 */

// ── Types ────────────────────────────────────────────────────────────

interface MemoryEndpoints {
	recall: string;
	wakeUp: string;
	store: string;
	itemBase: string;
	preferences: string;
	audit?: string;
	sessionBase?: string;
}

interface ChatConfig {
	nonce?: string;
	memoryEndpoints?: MemoryEndpoints | null;
}

interface MemoryError extends Error {
	status?: number;
	data?: unknown;
	code?: string;
}

interface WakeUpParams {
	agentId?: string;
	wing?: string;
	room?: string;
}

interface RecallFilters {
	agentId?: string;
	wing?: string;
	room?: string;
	limit?: number;
}

interface StorePayload {
	agentId?: string;
	wing?: string;
	room?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number;
	contextType?: string;
	verbatim?: boolean;
	summarize?: boolean;
}

interface StoreBody {
	agent_id?: string;
	wing?: string;
	room?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number;
	context_type?: string;
	verbatim: boolean;
	summarize: boolean;
}

interface MemoryPatch {
	agentId?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number;
}

interface AuditOptions {
	agentId?: string;
	limit?: number;
	actionType?: string;
}

interface ReplayOptions {
	limit?: number;
}

interface Preferences {
	enabled: boolean;
	autosummarize: boolean;
}

// ── Helpers ──────────────────────────────────────────────────────────

const SERVICE_NAME = 'wpMcpAiChatMemory';

function getConfig(): ChatConfig {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiChat as ChatConfig || {};
}

function getEndpoints(): MemoryEndpoints | null {
	return getConfig().memoryEndpoints || null;
}

function getNonce(): string {
	return getConfig().nonce || '';
}

// ── Public API ───────────────────────────────────────────────────────

export function isAvailable(): boolean {
	const eps = getEndpoints();
	return !!( eps?.recall && eps?.wakeUp && eps?.store );
}

function disabledError(): MemoryError {
	const error = new Error( 'Chat memory surface is not enabled.' ) as MemoryError;
	error.code = 'chat_memory_disabled';
	return error;
}

function buildQuery( params: Record< string, unknown > = {} ): string {
	const usp = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( value === undefined || value === null || value === '' ) { continue; }
		usp.append( key, String( value ) );
	}
	const qs = usp.toString();
	return qs ? '?' + qs : '';
}

function request( url: string, options: { method?: string; headers?: Record< string, string >; body?: unknown } = {} ): Promise< unknown > {
	const reqHeaders: Record< string, string > = {
		Accept: 'application/json',
		'X-WP-Nonce': getNonce(),
		...( options.headers || {} ),
	};

	let reqBody: BodyInit | undefined;
	if ( options.body !== undefined && typeof options.body === 'object' ) {
		reqHeaders[ 'Content-Type' ] = 'application/json';
		reqBody = JSON.stringify( options.body );
	}

	return window.fetch( url, {
		method: options.method || 'GET',
		credentials: 'same-origin',
		headers: reqHeaders,
		body: reqBody,
	} ).then( ( response ) => {
		return response.json().then(
			( data ) => {
				if ( ! response.ok ) {
					const error = new Error(
						( data?.message || data?.code ) || ( 'HTTP ' + response.status )
					) as MemoryError;
					error.status = response.status;
					error.data = data;
					throw error;
				}
				return data;
			},
			() => {
				if ( ! response.ok ) {
					const error = new Error( 'HTTP ' + response.status ) as MemoryError;
					error.status = response.status;
					throw error;
				}
				return null;
			},
		);
	} );
}

function buildStoreBody( payload: StorePayload | undefined ): StoreBody {
	return {
		agent_id: payload?.agentId,
		wing: payload?.wing,
		room: payload?.room,
		title: payload?.title,
		content: payload?.content,
		tags: payload?.tags,
		importance: payload?.importance,
		context_type: payload?.contextType,
		verbatim: payload?.verbatim !== undefined ? !! payload.verbatim : true,
		summarize: payload?.summarize !== undefined ? !! payload.summarize : false,
	};
}

// ── Verbs ────────────────────────────────────────────────────────────

export function wakeUp( params: WakeUpParams = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	const qs = buildQuery( { agent_id: params.agentId, wing: params.wing, room: params.room } );
	return request( eps.wakeUp + qs, { method: 'GET' } );
}

export function recall( query: string, filters: RecallFilters = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	const qs = buildQuery( {
		agent_id: filters.agentId, wing: filters.wing, room: filters.room,
		query: query || '', limit: filters.limit,
	} );
	return request( eps.recall + qs, { method: 'GET' } );
}

export function store( payload: StorePayload ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	return request( eps.store, { method: 'POST', body: buildStoreBody( payload ) } );
}

export function storeBeacon( payload: StorePayload ): Promise< unknown | null > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	return window.fetch( eps.store, {
		method: 'POST',
		credentials: 'same-origin',
		keepalive: true,
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-WP-Nonce': getNonce(),
		},
		body: JSON.stringify( buildStoreBody( payload ) ),
	} ).then( ( response ) => {
		if ( ! response.ok ) {
			const error = new Error( 'HTTP ' + response.status ) as MemoryError;
			error.status = response.status;
			throw error;
		}
		return response.json().catch( () => null );
	} );
}

export function update( contextId: string, patch: MemoryPatch = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	if ( ! contextId ) { return Promise.reject( new Error( 'contextId is required.' ) ); }
	const eps = getEndpoints()!;
	const body: Record< string, unknown > = { agent_id: patch.agentId, ...patch };
	delete body.agentId;
	return request( eps.itemBase + encodeURIComponent( contextId ), { method: 'PUT', body } );
}

export function remove( contextId: string, options: { agentId?: string } = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	if ( ! contextId ) { return Promise.reject( new Error( 'contextId is required.' ) ); }
	const eps = getEndpoints()!;
	const qs = buildQuery( { agent_id: options.agentId } );
	return request( eps.itemBase + encodeURIComponent( contextId ) + qs, { method: 'DELETE' } );
}

export function getPreferences(): Promise< Preferences > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	return request( eps.preferences, { method: 'GET' } ) as Promise< Preferences >;
}

export function setPreferences( prefs: Partial< Preferences > = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	return request( eps.preferences, { method: 'POST', body: prefs } );
}

export function audit( options: AuditOptions = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	if ( ! eps.audit ) { return Promise.reject( disabledError() ); }
	const qs = buildQuery( { agent_id: options.agentId, limit: options.limit, action_type: options.actionType } );
	return request( eps.audit + qs, { method: 'GET' } );
}

export function sessionReplay( sessionId: string, options: ReplayOptions = {} ): Promise< unknown > {
	if ( ! isAvailable() ) { return Promise.reject( disabledError() ); }
	const eps = getEndpoints()!;
	if ( ! eps.sessionBase || ! sessionId ) { return Promise.reject( disabledError() ); }
	const qs = buildQuery( { limit: options.limit } );
	return request( eps.sessionBase + encodeURIComponent( sessionId ) + qs, { method: 'GET' } );
}

export function isMemoryRetrievalResult( result: unknown ): boolean {
	return !! (
		result &&
		typeof result === 'object' &&
		( Array.isArray( ( result as Record< string, unknown > ).contexts ) ||
			Array.isArray( ( result as Record< string, unknown > ).results ) ||
			Array.isArray( ( result as Record< string, unknown > ).memories ) )
	);
}

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > )[ SERVICE_NAME ] = {
	isAvailable,
	wakeUp,
	recall,
	store,
	storeBeacon,
	update,
	delete: remove,
	remove,
	audit,
	sessionReplay,
	getPreferences,
	setPreferences,
	isMemoryRetrievalResult,
};
