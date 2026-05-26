/**
 * Chat Memory Drawer — TypeScript edition.
 *
 * Side panel for viewing, editing, deleting, and scoping long-term
 * memories inside the chat surface. Auto-injects into initialized
 * chat containers when wpMcpAiChatMemory reports as available.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── i18n fallback ────────────────────────────────────────────────────

const _wp = ( window as unknown as unknown as Record< string, unknown > ).wp as { i18n?: { __( t: string ): string; sprintf( f: string, ...args: string[] ): string } } | undefined;
const i18n = _wp?.i18n || {
	__( t: string ) { return t; },
	sprintf( f: string, ...args: string[] ) { let i = 0; return f.replace( /%s/g, () => args[ i++ ] ); },
};
const __ = i18n.__.bind( i18n );

// ── Constants ────────────────────────────────────────────────────────

const TOAST_REGION_ID = 'wp-mcp-ai-memory-toasts';
const TOOLS_THAT_RETRIEVE_MEMORY = [ 'recall_memory', 'wake_up_context', 'semantic_context_search', 'retrieve_agent_memory' ];
const TOOLS_THAT_STORE_MEMORY = [ 'store_agent_context', 'update_agent_memory', 'capture_memory' ];
const MIN_WATERFALL_BAR_WIDTH_PERCENT = 6;

let pendingSseToasts = 0;

// ── Types ────────────────────────────────────────────────────────────

interface MemoryRecord {
	context_id?: string; id?: string; uuid?: string;
	title?: string; content?: string; tags?: string[];
	tier?: string; memory_tier?: string; importance?: string;
	context_data?: { title?: string; content?: string; tags?: string[]; importance?: string };
	agent_id?: string;
	rrf_breakdown?: { bm25_rank?: number; vector_rank?: number; graph_rank?: number };
	boost_breakdown?: { keyword?: number; temporal?: number; exact_match?: number };
}

interface MemoryService {
	isAvailable(): boolean;
	remove( id: string, opts: { agentId?: string } ): Promise< unknown >;
	update( id: string, patch: Record< string, string > ): Promise< unknown >;
	recall( query: string, f: Record< string, string | number > ): Promise< { contexts?: MemoryRecord[]; results?: MemoryRecord[]; memories?: MemoryRecord[]; data?: { contexts?: MemoryRecord[]; results?: MemoryRecord[]; memories?: MemoryRecord[] }; retrieval_path?: string } >;
	audit?( o: { agentId: string; limit: number; actionType?: string } ): Promise< { entries?: Array< { action: string; timestamp: string; context_id?: string } >; data?: { entries?: Array< { action: string; timestamp: string; context_id?: string } > } } >;
	sessionReplay?( sid: string, o: { limit: number } ): Promise< { events?: Array< { event: string; timestamp: string; data?: Record< string, string > } >; data?: { events?: Array< { event: string; timestamp: string; data?: Record< string, string > } > } } >;
	getPreferences(): Promise< { enabled: boolean; autosummarize: boolean } >;
	storeBeacon( p: Record< string, unknown > ): Promise< unknown >;
}

interface ChatConfig {
	assistantId?: string; embeddedAssistantId?: string;
	sessionKey?: string;
	memoryWing?: string; memoryRoom?: string;
}

interface ChatState {
	config: ChatConfig;
}

interface DrawerController {
	open( t?: HTMLElement ): void;
	close(): void;
	isOpen(): boolean;
	root: HTMLElement;
	refresh(): void;
}

// ── Helpers ──────────────────────────────────────────────────────────

function memoryService(): MemoryService | null { return ( window as unknown as unknown as Record< string, unknown > ).wpMcpAiChatMemory as MemoryService || null; }
function isAvailable(): boolean { const s = memoryService(); return !!( s?.isAvailable?.() ); }
function resolveReplaySessionId( config: ChatConfig, agentId: string ): string {
	if ( config.sessionKey?.trim() ) { return config.sessionKey.trim(); }
	const key = 'wp_mcp_ai_chat_session_id_' + ( config.assistantId || agentId || 'default' );
	try { const s = window.localStorage?.getItem( key ); if ( s && /^[a-zA-Z0-9_-]{1,64}$/.test( s ) ) { return s; } } catch { /* ignore */ }
	return '';
}

function memoryToastCopy( retrieved: boolean, stored: boolean ): string {
	if ( retrieved && stored ) { return __( '🧠 Used and saved long-term memory.' ); }
	if ( stored ) { return __( '🧠 Saved a memory.' ); }
	return __( '🧠 Used long-term memory.' );
}

export function handleSseMemoryEvent( payload: { action?: string } ): void {
	if ( ! payload || typeof payload.action !== 'string' ) { return; }
	const retrieved = payload.action === 'retrieved';
	const stored = payload.action === 'stored';
	if ( ! retrieved && ! stored ) { return; }
	pendingSseToasts++;
	announceToast( memoryToastCopy( retrieved, stored ), 'info' );
}

function ensureToastRegion(): HTMLElement {
	let r = document.getElementById( TOAST_REGION_ID );
	if ( r ) { return r; }
	r = document.createElement( 'div' ); r.id = TOAST_REGION_ID;
	r.className = 'wp-mcp-ai-memory-toasts'; r.setAttribute( 'aria-live', 'polite' ); r.setAttribute( 'aria-atomic', 'true' ); r.setAttribute( 'role', 'status' );
	document.body.appendChild( r );
	return r;
}

export function announceToast( message: string, variant = 'info' ): void {
	if ( ! message ) { return; }
	const region = ensureToastRegion();
	const toast = document.createElement( 'div' );
	toast.className = 'wp-mcp-ai-memory-toast wp-mcp-ai-memory-toast--' + variant;
	toast.setAttribute( 'data-testid', 'wp-mcp-ai-memory-toast' );
	toast.textContent = String( message );
	region.appendChild( toast );
	setTimeout( () => { toast.parentNode?.removeChild( toast ); }, 4000 );
}

export function decorateMessageWithBadge( bubble: HTMLElement, toolCalls: Array< { tool?: string; name?: string; function?: { name: string } } > ): void {
	if ( ! bubble || ! Array.isArray( toolCalls ) || ! toolCalls.length ) { return; }
	if ( bubble.querySelector( '.wp-mcp-ai-memory-badge' ) ) { return; }
	let retrieved = false, stored = false;
	for ( const call of toolCalls ) {
		const name = call?.tool || call?.name || call?.function?.name;
		if ( ! name ) { continue; }
		if ( TOOLS_THAT_RETRIEVE_MEMORY.includes( name ) ) { retrieved = true; }
		if ( TOOLS_THAT_STORE_MEMORY.includes( name ) ) { stored = true; }
	}
	if ( ! retrieved && ! stored ) { return; }

	const badge = document.createElement( 'span' );
	badge.className = 'wp-mcp-ai-memory-badge'; badge.setAttribute( 'data-testid', 'wp-mcp-ai-memory-badge' );
	badge.setAttribute( 'title', __( 'This response used long-term memory.' ) );
	badge.setAttribute( 'aria-label', __( 'Memory in use' ) );
	badge.innerHTML = '<span class="wp-mcp-ai-memory-badge__icon" aria-hidden="true">🧠</span><span class="wp-mcp-ai-memory-badge__label">' + __( 'Memory' ) + '</span>';
	const header = bubble.querySelector( '.wp-mcp-ai-chat__message-header' ) || bubble.querySelector( '.wp-mcp-ai-chat__message-meta' );
	header ? header.appendChild( badge ) : bubble.insertBefore( badge, bubble.firstChild );

	if ( ! bubble.getAttribute( 'data-wp-mcp-ai-memory-toast' ) ) {
		bubble.setAttribute( 'data-wp-mcp-ai-memory-toast', '1' );
		if ( pendingSseToasts > 0 ) { pendingSseToasts--; }
		else { announceToast( memoryToastCopy( retrieved, stored ), 'info' ); }
	}
}

function trapFocus( root: HTMLElement ): () => void {
	function focusables(): HTMLElement[] { return Array.from( root.querySelectorAll( 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])' ) ); }
	function onKey( e: KeyboardEvent ): void { if ( e.key !== 'Tab' ) { return; } const f = focusables(); if ( ! f.length ) { return; } const first = f[ 0 ]; const last = f[ f.length - 1 ]; if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); } else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); } }
	root.addEventListener( 'keydown', onKey );
	return () => root.removeEventListener( 'keydown', onKey );
}

function renderMemoryItem( memory: MemoryRecord, onUpdate: ( m: MemoryRecord, applied: boolean ) => void, onDelete: ( id: string ) => void ): HTMLElement {
	const id = memory.context_id || memory.id || memory.uuid || '';
	const title = memory.title || memory.context_data?.title || __( 'Untitled memory' );
	const content = memory.content || memory.context_data?.content || '';
	const tags = memory.tags || memory.context_data?.tags || [];
	const tier = memory.tier || memory.memory_tier || '';
	const importance = memory.importance || memory.context_data?.importance || '';

	const item = document.createElement( 'li' ); item.className = 'wp-mcp-ai-memory-item'; item.setAttribute( 'data-context-id', id ); item.setAttribute( 'data-testid', 'wp-mcp-ai-memory-item' );
	const header = document.createElement( 'div' ); header.className = 'wp-mcp-ai-memory-item__header';
	const titleEl = document.createElement( 'h4' ); titleEl.className = 'wp-mcp-ai-memory-item__title'; titleEl.textContent = title; header.appendChild( titleEl );
	if ( tier || importance ) { const meta = document.createElement( 'span' ); meta.className = 'wp-mcp-ai-memory-item__meta'; meta.textContent = [ tier, importance ].filter( Boolean ).join( ' \u00b7 ' ); header.appendChild( meta ); }
	item.appendChild( header );

	const body = document.createElement( 'p' ); body.className = 'wp-mcp-ai-memory-item__content'; body.textContent = content; item.appendChild( body );
	if ( tags.length ) { const tl = document.createElement( 'div' ); tl.className = 'wp-mcp-ai-memory-item__tags'; for ( const t of tags ) { const c = document.createElement( 'span' ); c.className = 'wp-mcp-ai-memory-item__tag'; c.textContent = String( t ); tl.appendChild( c ); } item.appendChild( tl ); }

	const actions = document.createElement( 'div' ); actions.className = 'wp-mcp-ai-memory-item__actions';
	const editBtn = document.createElement( 'button' ); editBtn.type = 'button'; editBtn.className = 'wp-mcp-ai-memory-item__edit'; editBtn.textContent = __( 'Edit' ); editBtn.setAttribute( 'data-testid', 'wp-mcp-ai-memory-edit' );
	editBtn.addEventListener( 'click', () => { renderEditForm( item, memory, onUpdate ); } );
	const deleteBtn = document.createElement( 'button' ); deleteBtn.type = 'button'; deleteBtn.className = 'wp-mcp-ai-memory-item__delete'; deleteBtn.textContent = __( 'Delete' ); deleteBtn.setAttribute( 'data-testid', 'wp-mcp-ai-memory-delete' );
	deleteBtn.addEventListener( 'click', () => { if ( ! id ) { announceToast( __( 'This memory has no ID and cannot be deleted.' ), 'error' ); return; } if ( ! confirm( __( 'Delete this memory? This cannot be undone.' ) ) ) { return; } memoryService()?.remove( id, { agentId: memory.agent_id } )?.then( () => { announceToast( __( 'Memory deleted.' ), 'success' ); onDelete( id ); } ).catch( ( err: { message?: string } ) => { announceToast( err?.message || __( 'Could not delete memory.' ), 'error' ); } ); } );
	actions.appendChild( editBtn ); actions.appendChild( deleteBtn ); item.appendChild( actions );
	return item;
}

function renderEditForm( item: HTMLElement, memory: MemoryRecord, onUpdate: ( m: MemoryRecord, applied: boolean ) => void ): void {
	const id = memory.context_id || memory.id || memory.uuid || '';
	if ( ! id ) { announceToast( __( 'This memory has no ID and cannot be edited.' ), 'error' ); return; }
	while ( item.firstChild ) { item.removeChild( item.firstChild ); }
	const form = document.createElement( 'form' ); form.className = 'wp-mcp-ai-memory-item__edit-form'; form.setAttribute( 'data-testid', 'wp-mcp-ai-memory-edit-form' );
	const ti = document.createElement( 'input' ); ti.type = 'text'; ti.className = 'wp-mcp-ai-memory-item__edit-title'; ti.value = memory.title || memory.context_data?.title || '';
	const ci = document.createElement( 'textarea' ); ci.className = 'wp-mcp-ai-memory-item__edit-content'; ci.rows = 4; ci.value = memory.content || memory.context_data?.content || '';
	const save = document.createElement( 'button' ); save.type = 'submit'; save.className = 'wp-mcp-ai-memory-item__edit-save'; save.textContent = __( 'Save' );
	const cancel = document.createElement( 'button' ); cancel.type = 'button'; cancel.className = 'wp-mcp-ai-memory-item__edit-cancel'; cancel.textContent = __( 'Cancel' );
	cancel.addEventListener( 'click', () => onUpdate( memory, false ) );
	const btns = document.createElement( 'div' ); btns.className = 'wp-mcp-ai-memory-item__edit-buttons'; btns.appendChild( save ); btns.appendChild( cancel );
	[ document.createElement( 'label' ), document.createElement( 'label' ) ].forEach( ( l, i ) => { l.className = 'wp-mcp-ai-memory-item__edit-label'; l.textContent = i ? __( 'Content' ) : __( 'Title' ); l.appendChild( i ? ci : ti ); form.appendChild( l ); } );
	form.appendChild( btns );
	form.addEventListener( 'submit', ( e ) => { e.preventDefault(); save.disabled = true; memoryService()?.update( id, { agentId: memory.agent_id || '', title: ti.value, content: ci.value } )?.then( () => { announceToast( __( 'Memory updated.' ), 'success' ); onUpdate( { ...memory, title: ti.value, content: ci.value }, true ); } ).catch( ( err: { message?: string } ) => { save.disabled = false; announceToast( err?.message || __( 'Could not update memory.' ), 'error' ); } ); } );
	item.appendChild( form ); ti.focus();
}

// ── Drawer builder ───────────────────────────────────────────────────

function buildDrawer( container: HTMLElement, state: ChatState ): DrawerController {
	const config = state?.config || {};
	const agentId = ( config.embeddedAssistantId || config.assistantId || 0 ).toString();
	const drawer = document.createElement( 'aside' ); drawer.className = 'wp-mcp-ai-memory-drawer'; drawer.setAttribute( 'role', 'dialog' ); drawer.setAttribute( 'aria-modal', 'false' ); drawer.setAttribute( 'aria-hidden', 'true' ); drawer.setAttribute( 'data-testid', 'wp-mcp-ai-memory-drawer' ); drawer.hidden = true;
	const heading = document.createElement( 'h3' ); heading.className = 'wp-mcp-ai-memory-drawer__heading'; heading.id = 'wp-mcp-ai-memory-drawer-heading-' + Math.floor( Math.random() * 1e9 ); heading.textContent = __( 'Long-term memory' ); drawer.setAttribute( 'aria-labelledby', heading.id );
	const closeBtn = document.createElement( 'button' ); closeBtn.type = 'button'; closeBtn.className = 'wp-mcp-ai-memory-drawer__close'; closeBtn.setAttribute( 'aria-label', __( 'Close memory drawer' ) ); closeBtn.textContent = '\u00d7';
	const tabs = document.createElement( 'div' ); tabs.className = 'wp-mcp-ai-memory-drawer__tabs'; tabs.setAttribute( 'role', 'tablist' );

	const makeTab = ( label: string, active = false ): HTMLButtonElement => { const b = document.createElement( 'button' ); b.type = 'button'; b.className = 'wp-mcp-ai-memory-drawer__tab' + ( active ? ' is-active' : '' ); b.setAttribute( 'role', 'tab' ); b.setAttribute( 'aria-selected', active ? 'true' : 'false' ); b.textContent = label; tabs.appendChild( b ); return b; };
	const memoriesTab = makeTab( __( 'Memories' ), true ); const scopeTab = makeTab( __( 'Scope' ) ); const auditTab = makeTab( __( 'Audit' ) ); const replayTab = makeTab( __( 'Session Replay' ) );
	auditTab.setAttribute( 'data-testid', 'wp-mcp-ai-memory-audit-tab' ); replayTab.setAttribute( 'data-testid', 'wp-mcp-ai-memory-replay-tab' );

	const makePanel = (): HTMLDivElement => { const p = document.createElement( 'div' ); p.className = 'wp-mcp-ai-memory-drawer__panel'; p.setAttribute( 'role', 'tabpanel' ); p.hidden = true; return p; };
	const makeFilterRow = (): HTMLDivElement => { const f = document.createElement( 'div' ); f.className = 'wp-mcp-ai-memory-drawer__filter'; return f; };
	const makeRefreshBtn = ( label: string ): HTMLButtonElement => { const b = document.createElement( 'button' ); b.type = 'button'; b.className = 'wp-mcp-ai-memory-drawer__refresh'; b.textContent = label; return b; };
	const makeEmpty = ( text: string ): HTMLParagraphElement => { const p = document.createElement( 'p' ); p.className = 'wp-mcp-ai-memory-drawer__empty'; p.hidden = true; p.textContent = text; return p; };
	const makeError = (): HTMLParagraphElement => { const p = document.createElement( 'p' ); p.className = 'wp-mcp-ai-memory-drawer__error'; p.setAttribute( 'role', 'alert' ); p.hidden = true; return p; };

	// Memories panel
	const memoriesPanel = makePanel(); memoriesPanel.hidden = false;
	const filterRow = makeFilterRow();
	const queryInput = document.createElement( 'input' ); queryInput.type = 'search'; queryInput.className = 'wp-mcp-ai-memory-drawer__query'; queryInput.placeholder = __( 'Filter memories\u2026' ); queryInput.setAttribute( 'aria-label', __( 'Search memories' ) ); queryInput.setAttribute( 'data-testid', 'wp-mcp-ai-memory-query' ); filterRow.appendChild( queryInput );
	const refreshBtn = makeRefreshBtn( __( 'Refresh' ) ); filterRow.appendChild( refreshBtn );
	const exportBtn = document.createElement( 'button' ); exportBtn.type = 'button'; exportBtn.className = 'wp-mcp-ai-memory-drawer__export'; exportBtn.setAttribute( 'data-testid', 'wp-mcp-ai-memory-export' ); exportBtn.textContent = __( 'Export' ); filterRow.appendChild( exportBtn );
	const list = document.createElement( 'ul' ); list.className = 'wp-mcp-ai-memory-drawer__list'; list.setAttribute( 'data-testid', 'wp-mcp-ai-memory-list' );
	const waterfall = document.createElement( 'section' ); waterfall.className = 'wp-mcp-ai-memory-drawer__waterfall'; waterfall.setAttribute( 'data-testid', 'wp-mcp-ai-memory-waterfall' ); waterfall.hidden = true;
	const memEmpty = makeEmpty( __( 'No memories yet.' ) ); const memError = makeError();
	memoriesPanel.append( filterRow, waterfall, memEmpty, memError, list );

	// Scope panel
	const scopePanel = makePanel();
	const wingInput = document.createElement( 'input' ); wingInput.type = 'text'; wingInput.className = 'wp-mcp-ai-memory-drawer__wing'; wingInput.value = config.memoryWing || '';
	const roomInput = document.createElement( 'input' ); roomInput.type = 'text'; roomInput.className = 'wp-mcp-ai-memory-drawer__room'; roomInput.value = config.memoryRoom || '';
	const scopeForm = document.createElement( 'form' ); scopeForm.className = 'wp-mcp-ai-memory-drawer__scope-form'; scopeForm.setAttribute( 'data-testid', 'wp-mcp-ai-memory-scope-form' );
	const scopeSave = document.createElement( 'button' ); scopeSave.type = 'submit'; scopeSave.textContent = __( 'Apply scope' );
	[ [ __( 'Wing (project / matter)' ), wingInput ], [ __( 'Room (topic)' ), roomInput ] ].forEach( ( [ l, i ] ) => { const el = document.createElement( 'label' ); el.textContent = l as string; el.appendChild( i as HTMLInputElement ); scopeForm.appendChild( el ); } );
	scopeForm.appendChild( scopeSave ); scopePanel.appendChild( scopeForm );
	scopeForm.addEventListener( 'submit', ( e ) => { e.preventDefault(); config.memoryWing = wingInput.value; config.memoryRoom = roomInput.value; announceToast( config.memoryWing ? i18n.sprintf( __( 'Scope set to wing "%s".' ), config.memoryWing ) : __( 'Scope cleared.' ), 'success' ); loadMemories(); } );

	// Audit panel
	const auditPanel = makePanel(); auditPanel.setAttribute( 'data-testid', 'wp-mcp-ai-memory-audit-panel' );
	const auditFilterRow = makeFilterRow();
	const auditActionFilter = document.createElement( 'select' ); auditActionFilter.className = 'wp-mcp-ai-memory-drawer__audit-filter'; auditActionFilter.setAttribute( 'aria-label', __( 'Filter audit log by action type' ) ); auditActionFilter.setAttribute( 'data-testid', 'wp-mcp-ai-memory-audit-filter' );
	for ( const [ v, l ] of [ [ '', 'All actions' ], [ 'create', 'Created' ], [ 'update', 'Updated' ], [ 'delete', 'Deleted' ], [ 'access', 'Accessed' ] ] ) { const o = document.createElement( 'option' ); o.value = v; o.textContent = __( l ); auditActionFilter.appendChild( o ); }
	auditFilterRow.appendChild( auditActionFilter );
	const auditRefreshBtn = makeRefreshBtn( __( 'Refresh' ) ); auditFilterRow.appendChild( auditRefreshBtn );
	const auditList = document.createElement( 'ul' ); auditList.className = 'wp-mcp-ai-memory-drawer__audit-list'; auditList.setAttribute( 'data-testid', 'wp-mcp-ai-memory-audit-list' );
	const auditEmpty = makeEmpty( __( 'No audit entries yet.' ) ); const auditError = makeError();
	auditPanel.append( auditFilterRow, auditEmpty, auditError, auditList );

	// Replay panel
	const replayPanel = makePanel(); replayPanel.setAttribute( 'data-testid', 'wp-mcp-ai-memory-replay-panel' );
	const replayFilterRow = makeFilterRow();
	const replaySessionInput = document.createElement( 'input' ); replaySessionInput.type = 'text'; replaySessionInput.className = 'wp-mcp-ai-memory-drawer__query'; replaySessionInput.placeholder = __( 'Session ID\u2026' ); replaySessionInput.setAttribute( 'aria-label', __( 'Session ID for replay' ) ); replaySessionInput.setAttribute( 'data-testid', 'wp-mcp-ai-memory-replay-session' ); replaySessionInput.value = resolveReplaySessionId( config, agentId ); replayFilterRow.appendChild( replaySessionInput );
	const replayRefreshBtn = makeRefreshBtn( __( 'Load' ) ); replayFilterRow.appendChild( replayRefreshBtn );
	const replayList = document.createElement( 'ul' ); replayList.className = 'wp-mcp-ai-memory-drawer__audit-list'; replayList.setAttribute( 'data-testid', 'wp-mcp-ai-memory-replay-list' );
	const replayEmpty = makeEmpty( __( 'No session replay events yet.' ) ); const replayError = makeError();
	replayPanel.append( replayFilterRow, replayEmpty, replayError, replayList );

	drawer.append( closeBtn, heading, tabs, memoriesPanel, scopePanel, auditPanel, replayPanel );
	container.appendChild( drawer );

	let auditLoaded = false, replayLoaded = false;

	function setTab( name: string ): void {
		const is = ( n: string ) => name === n;
		[ memoriesTab, scopeTab, auditTab, replayTab ].forEach( ( t, i ) => { const a = is( [ 'memories', 'scope', 'audit', 'replay' ][ i ] ); t.classList.toggle( 'is-active', a ); t.setAttribute( 'aria-selected', a ? 'true' : 'false' ); } );
		memoriesPanel.hidden = ! is( 'memories' ); scopePanel.hidden = ! is( 'scope' ); auditPanel.hidden = ! is( 'audit' ); replayPanel.hidden = ! is( 'replay' );
		if ( is( 'audit' ) && ! auditLoaded ) { auditLoaded = true; loadAudit(); }
		if ( is( 'replay' ) && ! replayLoaded ) { replayLoaded = true; loadReplay(); }
	}
	memoriesTab.addEventListener( 'click', () => setTab( 'memories' ) ); scopeTab.addEventListener( 'click', () => setTab( 'scope' ) ); auditTab.addEventListener( 'click', () => setTab( 'audit' ) ); replayTab.addEventListener( 'click', () => setTab( 'replay' ) );

	function clearList(): void { while ( list.firstChild ) { list.removeChild( list.firstChild ); } }
	function showError( m: string ): void { memError.textContent = m; memError.hidden = false; }

	function extractRecords( r: Record< string, unknown > | null ): MemoryRecord[] {
		if ( ! r || typeof r !== 'object' ) { return []; }
		for ( const k of [ 'contexts', 'results', 'memories' ] ) { if ( Array.isArray( r[ k ] ) ) { return r[ k ] as MemoryRecord[]; } }
		const d = r.data as unknown as Record< string, unknown > | undefined; if ( d ) { for ( const k of [ 'contexts', 'results', 'memories' ] ) { if ( Array.isArray( d[ k ] ) ) { return d[ k ] as MemoryRecord[]; } } }
		return [];
	}

	function extractWaterfall( records: MemoryRecord[], response: { retrieval_path?: string } ): { label: string; rows: Array< { label: string; count: number } > } | null {
		const rrf: Record< string, number > = { BM25: 0, Vector: 0, Graph: 0 }; let hasRrf = false;
		for ( const r of records ) { if ( r.rrf_breakdown ) { hasRrf = true; if ( r.rrf_breakdown.bm25_rank !== undefined ) { rrf.BM25++; } if ( r.rrf_breakdown.vector_rank !== undefined ) { rrf.Vector++; } if ( r.rrf_breakdown.graph_rank !== undefined ) { rrf.Graph++; } } }
		if ( hasRrf ) { return { label: __( 'RRF hybrid retrieval' ), rows: Object.entries( rrf ).map( ( [ l, c ] ) => ( { label: __( l ), count: c } ) ) }; }
		const leg: Record< string, number > = { Keyword: 0, Temporal: 0, Exact: 0 }; let hasLeg = false;
		for ( const r of records ) { if ( r.boost_breakdown ) { hasLeg = true; if ( Number( r.boost_breakdown.keyword || 0 ) > 0 ) { leg.Keyword++; } if ( Number( r.boost_breakdown.temporal || 0 ) > 0 ) { leg.Temporal++; } if ( Number( r.boost_breakdown.exact_match || 0 ) > 0 ) { leg.Exact++; } } }
		if ( hasLeg ) { return { label: __( 'Legacy booster retrieval' ), rows: Object.entries( leg ).map( ( [ l, c ] ) => ( { label: __( l ), count: c } ) ) }; }
		if ( response?.retrieval_path ) { return { label: __( 'Retrieval path' ), rows: [ { label: String( response.retrieval_path ), count: records.length } ] }; }
		return null;
	}

	function renderWaterfall( d: { label: string; rows: Array< { label: string; count: number } > } ): void {
		while ( waterfall.firstChild ) { waterfall.removeChild( waterfall.firstChild ); }
		if ( ! d.rows.length ) { waterfall.hidden = true; return; }
		const h = document.createElement( 'h4' ); h.className = 'wp-mcp-ai-memory-drawer__waterfall-heading'; h.textContent = __( 'Retrieval waterfall' );
		const s = document.createElement( 'p' ); s.className = 'wp-mcp-ai-memory-drawer__waterfall-label'; s.textContent = d.label;
		waterfall.append( h, s );
		const max = d.rows.reduce( ( mx, r ) => Math.max( mx, r.count ), 0 );
		const ul = document.createElement( 'ul' ); ul.className = 'wp-mcp-ai-memory-drawer__waterfall-rows';
		for ( const r of d.rows ) { const w = max ? Math.max( MIN_WATERFALL_BAR_WIDTH_PERCENT, Math.round( ( r.count / max ) * 100 ) ) : MIN_WATERFALL_BAR_WIDTH_PERCENT; const li = document.createElement( 'li' ); li.className = 'wp-mcp-ai-memory-drawer__waterfall-row'; li.setAttribute( 'data-testid', 'wp-mcp-ai-memory-waterfall-row' ); li.innerHTML = '<span class="wp-mcp-ai-memory-drawer__waterfall-row-label">' + r.label + '</span><span class="wp-mcp-ai-memory-drawer__waterfall-meter"><span class="wp-mcp-ai-memory-drawer__waterfall-meter-fill" style="width:' + w + '%"></span></span><span class="wp-mcp-ai-memory-drawer__waterfall-row-value">' + r.count + '</span>'; ul.appendChild( li ); }
		waterfall.appendChild( ul ); waterfall.hidden = false;
	}

	function loadMemories(): void {
		if ( ! isAvailable() ) { return; }
		memError.hidden = true; memEmpty.hidden = true; waterfall.hidden = true; clearList();
		list.innerHTML = '<li class="wp-mcp-ai-memory-drawer__loading">' + __( 'Loading memories\u2026' ) + '</li>';
		memoryService()!.recall( ( queryInput as HTMLInputElement ).value || '', { agentId, wing: config.memoryWing || '', room: config.memoryRoom || '', limit: 25 } ).then( ( r ) => {
			clearList(); const records = extractRecords( r as unknown as Record< string, unknown > ); const wf = extractWaterfall( records, r as { retrieval_path?: string } ); if ( wf ) { renderWaterfall( wf ); } else { waterfall.hidden = true; }
			if ( ! records.length ) { memEmpty.hidden = false; return; }
			for ( const rec of records ) { const item = renderMemoryItem( rec, ( u, a ) => { const rep = renderMemoryItem( a ? u : rec, arguments[ 0 ], arguments[ 1 ] ); item.parentNode?.replaceChild( rep, item ); }, ( delId ) => { list.removeChild( list.querySelector( '[data-context-id="' + delId + '"]' ) as HTMLElement ); if ( ! list.children.length ) { memEmpty.hidden = false; } } ); list.appendChild( item ); }
		} ).catch( ( err: { message?: string } ) => { clearList(); waterfall.hidden = true; showError( err?.message || __( 'Could not load memories.' ) ); } );
	}

	function loadAudit(): void { if ( ! isAvailable() || ! memoryService()?.audit ) { return; } auditError.hidden = true; auditEmpty.hidden = true; while ( auditList.firstChild ) { auditList.removeChild( auditList.firstChild ); } auditList.innerHTML = '<li class="wp-mcp-ai-memory-drawer__loading">' + __( 'Loading audit log\u2026' ) + '</li>'; memoryService()!.audit!( { agentId, limit: 50, actionType: auditActionFilter.value || undefined } ).then( ( r ) => { while ( auditList.firstChild ) { auditList.removeChild( auditList.firstChild ); } const entries = ( Array.isArray( ( r as unknown as Record< string, unknown > ).entries ) ? ( r as unknown as Record< string, unknown > ).entries : ( ( r as unknown as Record< string, unknown > ).data && Array.isArray( ( ( r as unknown as Record< string, unknown > ).data as unknown as Record< string, unknown > ).entries ) ? ( ( r as unknown as Record< string, unknown > ).data as unknown as Record< string, unknown > ).entries : [] ) ) as Array< { action: string; timestamp: string; context_id?: string } >; if ( ! entries.length ) { auditEmpty.hidden = false; return; } for ( const e of entries ) { const li = document.createElement( 'li' ); li.className = 'wp-mcp-ai-memory-drawer__audit-item'; li.innerHTML = '<time class="wp-mcp-ai-memory-drawer__audit-time">' + ( e.timestamp || __( '(no timestamp)' ) ) + '</time> <span class="wp-mcp-ai-memory-drawer__audit-action">' + ( e.action || 'unknown' ) + '</span>' + ( e.context_id ? ' \u2014 <span class="wp-mcp-ai-memory-drawer__audit-meta">' + e.context_id + '</span>' : '' ); auditList.appendChild( li ); } } ).catch( ( err: { message?: string } ) => { while ( auditList.firstChild ) { auditList.removeChild( auditList.firstChild ); } auditError.textContent = err?.message || __( 'Could not load audit log.' ); auditError.hidden = false; } ); }

	function loadReplay(): void { if ( ! isAvailable() || ! memoryService()?.sessionReplay ) { return; } replayError.hidden = true; replayEmpty.hidden = true; while ( replayList.firstChild ) { replayList.removeChild( replayList.firstChild ); } const sid = ( ( replaySessionInput as HTMLInputElement ).value || '' ).trim(); if ( ! sid ) { replayEmpty.textContent = __( 'Enter a session ID to replay events.' ); replayEmpty.hidden = false; return; } replayList.innerHTML = '<li class="wp-mcp-ai-memory-drawer__loading">' + __( 'Loading session replay\u2026' ) + '</li>'; memoryService()!.sessionReplay!( sid, { limit: 100 } ).then( ( r ) => { while ( replayList.firstChild ) { replayList.removeChild( replayList.firstChild ); } const events = ( Array.isArray( ( r as unknown as Record< string, unknown > ).events ) ? ( r as unknown as Record< string, unknown > ).events : ( ( r as unknown as Record< string, unknown > ).data && Array.isArray( ( ( r as unknown as Record< string, unknown > ).data as unknown as Record< string, unknown > ).events ) ? ( ( r as unknown as Record< string, unknown > ).data as unknown as Record< string, unknown > ).events : [] ) ) as Array< { event: string; timestamp: string; data?: Record< string, string > } >; if ( ! events.length ) { replayEmpty.hidden = false; return; } for ( const e of events ) { const li = document.createElement( 'li' ); li.className = 'wp-mcp-ai-memory-drawer__audit-item'; li.setAttribute( 'data-testid', 'wp-mcp-ai-memory-replay-item' ); const msg = e.data?.message || e.data?.error || e.data?.action || ''; li.innerHTML = '<time class="wp-mcp-ai-memory-drawer__audit-time">' + ( e.timestamp || __( '(no timestamp)' ) ) + '</time> <span class="wp-mcp-ai-memory-drawer__audit-action">' + ( e.event || 'event' ) + '</span>' + ( msg ? ' \u2014 <span class="wp-mcp-ai-memory-drawer__audit-meta">' + msg + '</span>' : '' ); replayList.appendChild( li ); } } ).catch( ( err: { message?: string } ) => { while ( replayList.firstChild ) { replayList.removeChild( replayList.firstChild ); } replayError.textContent = err?.message || __( 'Could not load session replay.' ); replayError.hidden = false; } ); }

	auditRefreshBtn.addEventListener( 'click', loadAudit ); auditActionFilter.addEventListener( 'change', loadAudit ); replayRefreshBtn.addEventListener( 'click', loadReplay );
	let filterTimer: ReturnType< typeof setTimeout > | null = null;
	queryInput.addEventListener( 'input', () => { if ( filterTimer ) { clearTimeout( filterTimer ); } filterTimer = setTimeout( loadMemories, 250 ); } );
	refreshBtn.addEventListener( 'click', loadMemories );

	// Export
	let exportInFlight = false;
	exportBtn.addEventListener( 'click', () => { if ( exportInFlight ) { return; } if ( ! isAvailable() ) { announceToast( __( 'Memory is not available right now.' ), 'error' ); return; } exportInFlight = true; exportBtn.disabled = true; memoryService()!.recall( ( queryInput as HTMLInputElement ).value, { agentId, wing: config.memoryWing || '', room: config.memoryRoom || '', limit: 200 } ).then( ( r ) => { const records = extractRecords( r as unknown as Record< string, unknown > ); const json = JSON.stringify( { exported_at: new Date().toISOString(), agent_id: agentId, scope: { wing: config.memoryWing || null, room: config.memoryRoom || null, query: ( queryInput as HTMLInputElement ).value }, count: records.length, memories: records }, null, 2 ); const blob = new Blob( [ json ], { type: 'application/json' } ); const url = URL.createObjectURL( blob ); const a = document.createElement( 'a' ); a.href = url; a.download = 'mcp-ai-memory-' + agentId.replace( /[^A-Za-z0-9_-]/g, '_' ) + '-' + new Date().toISOString().replace( /[:.]/g, '-' ) + '.json'; a.style.display = 'none'; document.body.appendChild( a ); a.click(); document.body.removeChild( a ); setTimeout( () => URL.revokeObjectURL( url ), 0 ); announceToast( i18n.sprintf( __( "Exported %d memor(y/ies)." ), String( records.length ) ), 'success' ); } ).catch( ( err: { message?: string } ) => { announceToast( err?.message || __( 'Could not export memories.' ), 'error' ); } ).finally( () => { exportInFlight = false; exportBtn.disabled = false; } ); } );

	let opened = false, releaseTrap: ( () => void ) | null = null, lastFocus: HTMLElement | null = null;

	function open( returnTarget?: HTMLElement ): void { if ( opened ) { return; } drawer.hidden = false; drawer.setAttribute( 'aria-hidden', 'false' ); drawer.classList.add( 'is-open' ); opened = true; lastFocus = returnTarget || document.activeElement as HTMLElement; loadMemories(); releaseTrap = trapFocus( drawer ); setTimeout( () => memoriesTab.focus(), 0 ); }
	function close(): void { if ( ! opened ) { return; } drawer.classList.remove( 'is-open' ); drawer.setAttribute( 'aria-hidden', 'true' ); drawer.hidden = true; opened = false; releaseTrap?.(); releaseTrap = null; lastFocus?.focus?.(); }
	closeBtn.addEventListener( 'click', close ); drawer.addEventListener( 'keydown', ( e ) => { if ( ( e as KeyboardEvent ).key === 'Escape' ) { close(); } } );

	return { open, close, isOpen: () => opened, root: drawer, refresh: loadMemories };
}

// ── Injection ────────────────────────────────────────────────────────

function injectToggle( container: HTMLElement, controller: DrawerController ): void {
	const controls = container.querySelector( '.wp-mcp-ai-chat__transcript-controls' );
	if ( ! controls || controls.querySelector( '.wp-mcp-ai-memory-toggle' ) ) { return; }
	const toggle = document.createElement( 'button' ); toggle.type = 'button'; toggle.className = 'wp-mcp-ai-memory-toggle';
	toggle.setAttribute( 'aria-haspopup', 'dialog' ); toggle.setAttribute( 'aria-expanded', 'false' ); toggle.setAttribute( 'aria-label', __( 'Open long-term memory drawer' ) ); toggle.setAttribute( 'data-testid', 'wp-mcp-ai-memory-toggle' );
	toggle.innerHTML = '<span aria-hidden="true">🧠</span><span class="screen-reader-text">' + __( 'Memory' ) + '</span>';
	toggle.addEventListener( 'click', () => { if ( controller.isOpen() ) { controller.close(); toggle.setAttribute( 'aria-expanded', 'false' ); } else { controller.open( toggle ); toggle.setAttribute( 'aria-expanded', 'true' ); } } );
	controls.appendChild( toggle );
}

export function attach( container: HTMLElement ): void {
	if ( ! container || ( container as unknown as Record< string, unknown > ).__wpMcpAiMemoryDrawer ) { return; }
	if ( ! isAvailable() ) { return; }
	const state = ( container as unknown as Record< string, unknown > ).__wpMcpAiChatState as ChatState | undefined;
	if ( ! state ) { return; }
	const controller = buildDrawer( container, state );
	( container as unknown as Record< string, unknown > ).__wpMcpAiMemoryDrawer = controller;
	injectToggle( container, controller );
	registerAutoSummary( container, state );
}

export function registerAutoSummary( _container: HTMLElement, state: ChatState ): void {
	const config = state?.config || {};
	const agentId = config.embeddedAssistantId || config.assistantId || '0';
	if ( ! agentId ) { return; }
	const flagKey = 'wp-mcp-ai-memory-autosummary:' + agentId;
	let cachedPrefs: { enabled: boolean; autosummarize: boolean } | null = null;
	try { memoryService()?.getPreferences()?.then( ( p ) => { cachedPrefs = p || null; } ).catch( () => { cachedPrefs = null; } ); } catch { cachedPrefs = null; }
	function fireOnce(): void { try { if ( window.sessionStorage?.getItem( flagKey ) ) { return; } } catch { /* ignore */ } if ( ! cachedPrefs?.enabled || ! cachedPrefs?.autosummarize ) { return; } const transcript = readTranscript( agentId ); if ( ! transcript?.text ) { return; } try { window.sessionStorage?.setItem( flagKey, '1' ); } catch { /* ignore */ } const payload = { agentId, wing: config.memoryWing || '', room: config.memoryRoom || '', title: i18n.sprintf( __( 'Conversation summary \u2014 %s' ), new Date().toISOString().slice( 0, 10 ) ), content: transcript.text, tags: [ 'transcript-summary', 'autosummary' ], contextType: 'transcript_summary', importance: 'medium', verbatim: true, summarize: true }; try { memoryService()?.storeBeacon( payload )?.catch( () => { /* fire-and-forget */ } ); } catch { /* ignore */ } }
	window.addEventListener( 'pagehide', fireOnce ); document.addEventListener( 'visibilitychange', () => { if ( document.visibilityState === 'hidden' ) { fireOnce(); } } );
}

export function readTranscript( agentId: string ): { text: string; count: number } | null {
	const storage = ( window as unknown as unknown as Record< string, unknown > ).wpMcpAiChatStorage as { loadConversationFromStorage( aid: string ): { conversation?: Array< { role: string; content: string } > } | null } | undefined;
	if ( ! storage?.loadConversationFromStorage ) { return null; }
	let conv; try { conv = storage.loadConversationFromStorage( agentId ); } catch { return null; }
	const turns = conv?.conversation || []; if ( turns.length < 2 ) { return null; }
	const lines: string[] = [];
	for ( const t of turns ) { if ( t.role !== 'user' && t.role !== 'assistant' ) { continue; } const c = typeof t.content === 'string' ? t.content : ''; if ( ! c.trim() ) { continue; } lines.push( ( t.role === 'user' ? 'User: ' : 'Assistant: ' ) + c ); }
	if ( ! lines.length ) { return null; }
	let text = lines.join( '\n\n' ); const MAX_BYTES = 4096;
	if ( text.length > MAX_BYTES ) { text = '\u2026\n\n' + text.slice( text.length - MAX_BYTES ); }
	return { text, count: lines.length };
}

function attachAll(): void { for ( const el of document.querySelectorAll( '[data-wp-mcp-ai-chat][data-wp-mcp-ai-initialized="true"]' ) ) { attach( el as HTMLElement ); } }

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as unknown as Record< string, unknown > ).wpMcpAiChatMemoryDrawer = { attach, attachAll, decorateMessageWithBadge, announceToast, ensureToastRegion, isAvailable, registerAutoSummary, readTranscript, handleSseMemoryEvent };

// ── Bootstrap ────────────────────────────────────────────────────────

function bootstrap(): void {
	ensureToastRegion(); attachAll();
	const observer = new MutationObserver( ( mutations ) => { for ( const m of mutations ) { if ( m.type === 'attributes' && m.target instanceof HTMLElement && m.target.getAttribute( 'data-wp-mcp-ai-initialized' ) === 'true' ) { attach( m.target ); } } } );
	observer.observe( document.body, { subtree: true, attributes: true, attributeFilter: [ 'data-wp-mcp-ai-initialized' ] } );
}
if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', bootstrap ); } else { bootstrap(); }
