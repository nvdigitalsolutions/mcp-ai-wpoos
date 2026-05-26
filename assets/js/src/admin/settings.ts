/**
 * Admin Settings — TypeScript edition.
 *
 * Provider connection tests, model fetching, accordion, token usage,
 * provider priority, embedded model management, and Llama download
 * handlers for the NV oOS admin settings dashboard.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── jQuery minimal interface ─────────────────────────────────────────

interface JQuery {
	length: number;
	[ index: number ]: HTMLElement;
	val(): string | undefined;
	val( v: string ): this;
	text( t: string ): this;
	html(): string;
	html( h: string ): this;
	prop( n: string ): boolean;
	prop( n: string, v: unknown ): this;
	attr( n: string, v: string ): this;
	data( key: string ): unknown;
	data( key: string, value: unknown ): this;
	addClass( c: string ): this;
	removeClass( c: string ): this;
	is( s: string ): boolean;
	empty(): this;
	append( c: string | JQuery ): this;
	prepend( c: string ): this;
	closest( s: string ): JQuery;
	find( s: string ): JQuery;
	filter( s: string ): JQuery;
	toggleClass( c: string ): this;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	each( cb: ( this: HTMLElement, i: number, el: HTMLElement ) => void ): void;
	ready( h: () => void ): void;
	wpColorPicker( opts: Record< string, unknown > ): void;
	first(): JQuery;
	parent(): JQuery;
	show(): this;
	hide(): this;
	remove(): void;
	replaceWith( c: string | JQuery ): this;
	offset(): { top: number; left: number };
	sortable( opts: Record< string, unknown > ): void;
	animate( props: Record< string, number >, duration: number ): this;
	scrollTop(): number;
}

interface JQueryXHR {
	status: number;
	responseJSON?: unknown;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	done( cb: ( ...args: any[] ) => void ): this;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fail( cb: ( ...args: any[] ) => void ): this;
}

interface JQueryStatic {
	( sel: string | HTMLElement | Document | ( () => void ) ): JQuery;
	ajax( s: Record< string, unknown > ): JQueryXHR;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
interface WpMcpAiAjaxFn { ( settings: Record< string, unknown >, handlers: Record< string, ( ...args: any[] ) => void > ): JQueryXHR; }

declare const jQuery: JQueryStatic;
declare const wpMcpAiAdmin: {
	ajaxUrl: string;
	nonce: string;
	i18n: Record< string, string >;
};
declare const wp: { notices?: { initialize(): void }; hooks?: { addAction( tag: string, cb: ( ...args: unknown[] ) => void ): void } };

const $ = jQuery;

// Augment jQuery with wpMcpAiAjax plugin
interface JQueryStatic {
	wpMcpAiAjax: WpMcpAiAjaxFn;
}

// ── Helpers ──────────────────────────────────────────────────────────

const DEBUG = false;
function log( message: string, data?: unknown ): void {
	if ( DEBUG && console?.log ) { console.log( '[NV oOS] ' + message, data ); }
}

function formatBytes( bytes: number, decimals = 2 ): string {
	if ( bytes === 0 ) return '0 Bytes';
	const k = 1024;
	const sizes = [ 'Bytes', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( decimals ) ) + ' ' + sizes[ i ];
}

// ── Generic provider test helper ─────────────────────────────────────

interface ProviderTestConfig {
	buttonId: string;
	resultId: string;
	action: string;
	data: Record< string, string >;
	successMsg?: ( r: { data?: { message?: string } } ) => string;
}

function initConnectionTest( cfg: ProviderTestConfig ): void {
	$( '#' + cfg.buttonId ).on( 'click', ( e ) => {
		e.preventDefault();
		const $btn = $( '#' + cfg.buttonId );
		const $result = $( '#' + cfg.resultId );
		const missing = Object.entries( cfg.data ).find( ( [ , sel ] ) => ! $( sel ).val() );
		if ( missing ) { $result.html( '<span style="color:#d63638;">Please enter ' + missing[ 0 ].replace( /_/g, ' ' ) + ' first.</span>' ); return; }

		$btn.prop( 'disabled', true ).text( 'Testing...' );
		$result.html( '<span style="color:#3c434a;">Connecting...</span>' );

		const ajaxData: Record< string, unknown > = { action: cfg.action, nonce: wpMcpAiAdmin.nonce };
		Object.entries( cfg.data ).forEach( ( [ k, sel ] ) => { ajaxData[ k ] = $( sel ).val(); } );

		$.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: ajaxData }, {
			success( response: { success: boolean; data?: { message?: string; models?: Array< { name: string; id: string; size: number; is_cloud?: boolean; family?: string } >; zone_info?: Record< string, string >; servers?: Array< { id: string; label: string; status: string } >; apps?: Array< { id: string; label: string; server_id: string } > } } ) {
				if ( response.success ) {
					$result.html( '<span style="color:#00a32a;">\u2713 ' + ( cfg.successMsg?.( response ) || response.data?.message || 'Connected' ) + '</span>' );
				} else {
					$result.html( '<span style="color:#d63638;">\u2717 ' + ( response.data?.message || 'Failed' ) + '</span>' );
				}
			},
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			error( err: { userMessage?: string } ) {
				$result.html( '<span style="color:#d63638;">\u2717 ' + ( err.userMessage || 'Connection failed' ) + '</span>' );
			},
			complete() { $btn.prop( 'disabled', false ).text( 'Test Connection' ); },
		} );
	} );
}

// ── Initialization functions ─────────────────────────────────────────

export function initColorPickers(): void {
	$( '.wp-mcp-ai-color-field' ).each( function ( this: HTMLElement ) {
		const $field = $( this );
		if ( ( $field.data( 'format' ) || 'hex' ).toString().toLowerCase() === 'rgba' ) { return; }
		if ( typeof $field.wpColorPicker === 'function' ) {
			$field.wpColorPicker( { defaultColor: $field.data( 'default-color' ) || false, change( _e: unknown, ui: { color: { toString(): string } } ) { $field.val( ui.color.toString() ); }, clear() { $field.val( '' ); } } );
		}
	} );
}

export function initOllamaHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-ollama-connection', resultId: 'wp-mcp-ai-ollama-test-result', action: 'wp_mcp_ai_test_ollama_connection', data: { endpoint_url: 'input[name="wp_mcp_ai_settings[ollama_endpoint_url]"]' } } );
	initModelFetcher( 'wp-mcp-ai-fetch-ollama-models', 'wp-mcp-ai-ollama-models-list', 'ollama_endpoint_url', 'wp_mcp_ai_fetch_ollama_models', 'wp-mcp-ai-select-ollama-model', 'ollama_model', 'name' );
}

export function initLMStudioHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-lm-studio-connection', resultId: 'wp-mcp-ai-lm-studio-test-result', action: 'wp_mcp_ai_test_lm_studio_connection', data: { endpoint_url: 'input[name="wp_mcp_ai_settings[lm_studio_endpoint_url]"]' } } );
	initModelFetcher( 'wp-mcp-ai-fetch-lm-studio-models', 'wp-mcp-ai-lm-studio-models-list', 'lm_studio_endpoint_url', 'wp_mcp_ai_fetch_lm_studio_models', 'wp-mcp-ai-select-lm-studio-model', 'lm_studio_model', 'id' );
}

export function initCloudwaysHandlers(): void {
	const $btn = $( '#wp-mcp-ai-fetch-cloudways-data' );
	if ( ! $btn.length ) { return; }
	$btn.on( 'click', ( e ) => {
		e.preventDefault();
		const $button = $( '#wp-mcp-ai-fetch-cloudways-data' );
		const $result = $( '#wp-mcp-ai-cloudways-fetch-result' );
		const email = $( 'input[name="wp_mcp_ai_settings[cloudways_email]"]' ).val();
		const apiKey = $( 'input[name="wp_mcp_ai_settings[cloudways_api_key]"]' ).val();
		if ( ! email || ! apiKey ) { $result.html( '<span style="color:#d63638;">Please enter both email and API key.</span>' ); return; }
		$button.prop( 'disabled', true ).text( 'Fetching...' ); $result.html( '<span style="color:#3c434a;">Connecting...</span>' );
		$( '#wp-mcp-ai-cloudways-servers-list, #wp-mcp-ai-cloudways-apps-list' ).html( '' );
		$.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_fetch_cloudways_data', nonce: wpMcpAiAdmin.nonce, email, api_key: apiKey } }, {
			success( r: { success: boolean; data?: { message?: string; servers?: Array< { id: string; label: string; status: string } >; apps?: Array< { id: string; label: string; server_id: string } > } } ) {
				if ( r.success ) { $result.html( '<span style="color:#00a32a;">\u2713 Successfully fetched Cloudways data</span>' ); if ( r.data?.servers?.length ) { renderSelectList( 'wp-mcp-ai-cloudways-servers-list', 'server', 'wp-mcp-ai-select-cloudways-server', r.data.servers, ( s ) => s.label + ' (ID: ' + s.id + ', Status: ' + s.status + ')', ( s ) => ( { 'data-server-id': s.id } ) ); } if ( r.data?.apps?.length ) { renderSelectList( 'wp-mcp-ai-cloudways-apps-list', 'application', 'wp-mcp-ai-select-cloudways-app', r.data.apps, ( a ) => a.label + ' (ID: ' + a.id + ')', ( a ) => ( { 'data-app-id': a.id, 'data-server-id': a.server_id } ) ); } }
				else { $result.html( '<span style="color:#d63638;">\u2717 ' + ( r.data?.message || 'Failed' ) + '</span>' ); }
			},
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			error( err: { userMessage?: string } ) { $result.html( '<span style="color:#d63638;">\u2717 ' + ( err.userMessage || 'Failed' ) + '</span>' ); },
			complete() { $button.prop( 'disabled', false ).text( 'Fetch Cloudways Data' ); },
		} );
	} );
	$( document ).on( 'click', '.wp-mcp-ai-select-cloudways-server', function ( this: HTMLElement, e ) { e.preventDefault(); const id = $( this ).data( 'server-id' ) as string; $( 'input[name="wp_mcp_ai_settings[cloudways_server_id]"]' ).val( id ); $( '#wp-mcp-ai-cloudways-servers-list' ).prepend( '<p style="color:#00a32a;font-weight:bold;">Selected Server ID: ' + id + '</p>' ); } );
	$( document ).on( 'click', '.wp-mcp-ai-select-cloudways-app', function ( this: HTMLElement, e ) { e.preventDefault(); const appId = $( this ).data( 'app-id' ) as string; const serverId = $( this ).data( 'server-id' ) as string; $( 'input[name="wp_mcp_ai_settings[cloudways_app_id]"]' ).val( appId ); $( 'input[name="wp_mcp_ai_settings[cloudways_server_id]"]' ).val( serverId ); $( '#wp-mcp-ai-cloudways-apps-list' ).prepend( '<p style="color:#00a32a;font-weight:bold;">Selected App ID: ' + appId + ' (Server: ' + serverId + ')</p>' ); } );
}

export function initCloudflareHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-cloudflare-connection', resultId: 'wp-mcp-ai-cloudflare-test-result', action: 'wp_mcp_ai_test_cloudflare_connection', data: { zone_id: 'input[name="wp_mcp_ai_settings[cloudflare_zone_id]"]', api_token: 'input[name="wp_mcp_ai_settings[cloudflare_api_token]"]' } } );
}

export function initBraveSearchHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-brave-search-connection', resultId: 'wp-mcp-ai-brave-search-test-result', action: 'wp_mcp_ai_test_brave_search_connection', data: { api_key: 'input[name="wp_mcp_ai_settings[brave_search_api_key]"]' } } );
}

export function initTavilyHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-tavily-connection', resultId: 'wp-mcp-ai-tavily-test-result', action: 'wp_mcp_ai_test_tavily_connection', data: { api_key: 'input[name="wp_mcp_ai_settings[tavily_api_key]"]' } } );
}

export function initMubertHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-mubert-connection', resultId: 'wp-mcp-ai-mubert-test-result', action: 'wp_mcp_ai_test_mubert_connection', data: { api_key: 'input[name="wp_mcp_ai_settings[mubert_api_key]"]' } } );
}

export function initYahooHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-yahoo-connection', resultId: 'wp-mcp-ai-yahoo-test-result', action: 'wp_mcp_ai_test_yahoo_connection', data: { client_id: 'input[name="wp_mcp_ai_settings[yahoo_client_id]"]', client_secret: 'input[name="wp_mcp_ai_settings[yahoo_client_secret]"]' } } );
}

export function initRemovebgHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-removebg-connection', resultId: 'wp-mcp-ai-removebg-test-result', action: 'wp_mcp_ai_test_removebg_connection', data: { api_key: 'input[name="wp_mcp_ai_settings[removebg_api_key]"]' } } );
}

export function initFlowhubHandlers(): void {
	initConnectionTest( { buttonId: 'wp-mcp-ai-test-flowhub-connection', resultId: 'wp-mcp-ai-flowhub-test-result', action: 'wp_mcp_ai_test_flowhub_connection', data: { api_key: 'input[name="wp_mcp_ai_settings[flowhub_api_key]"]', client_id: 'input[name="wp_mcp_ai_settings[flowhub_client_id]"]', client_secret: 'input[name="wp_mcp_ai_settings[flowhub_client_secret]"]', location_id: 'input[name="wp_mcp_ai_settings[flowhub_location_id]"]' } } );
}

// ── Model fetcher helper ─────────────────────────────────────────────

function initModelFetcher(
	buttonId: string, listId: string, endpointField: string,
	action: string, selectClass: string, targetField: string,
	idKey: string,
): void {
	$( '#' + buttonId ).on( 'click', ( e ) => {
		e.preventDefault();
		const $btn = $( '#' + buttonId );
		const $list = $( '#' + listId );
		const endpoint = $( 'input[name="wp_mcp_ai_settings[' + endpointField + ']"]' ).val();
		if ( ! endpoint ) { $list.html( '<p style="color:#d63638;">Please enter an endpoint URL first.</p>' ); return; }
		$btn.prop( 'disabled', true ).text( 'Fetching...' ); $list.html( '<p>Loading models...</p>' );
		$.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action, nonce: wpMcpAiAdmin.nonce, endpoint_url: endpoint } }, {
			success( r: { success: boolean; data?: { message?: string; models: Array< Record< string, unknown > > } } ) {
				if ( r.success && r.data?.models?.length ) { let html = '<p><strong>Available models:</strong></p><ul style="list-style:disc;margin-left:20px;">'; for ( const m of r.data.models ) { const size = m.size ? ' (' + formatBytes( m.size as number ) + ')' : ''; html += '<li style="margin-bottom:5px;"><a href="#" class="' + selectClass + '" data-model="' + m[ idKey ] + '">' + ( m[ idKey ] || m.name ) + size + '</a>' + ( m.is_cloud ? ' \u2601' : '' ) + ( m.family ? ' - ' + m.family : '' ) + '</li>'; } html += '</ul>'; $list.html( html ); }
				else if ( r.success ) { $list.html( '<p style="color:#d63638;">No models found.</p>' ); }
				else { $list.html( '<p style="color:#d63638;">Error: ' + ( r.data?.message || 'Unknown' ) + '</p>' ); }
			},
			error( err: { userMessage?: string } ) { $list.html( '<p style="color:#d63638;">' + ( err.userMessage || 'Failed' ) + '</p>' ); },
			complete() { $btn.prop( 'disabled', false ).text( 'Fetch Models' ); },
		} );
	} );
	$( document ).on( 'click', '.' + selectClass, function ( this: HTMLElement, e ) { e.preventDefault(); const name = $( this ).data( 'model' ) as string; $( 'input[name="wp_mcp_ai_settings[' + targetField + ']"]' ).val( name ); $( '#' + listId ).prepend( '<p style="color:#00a32a;font-weight:bold;">Selected: ' + name + '</p>' ); } );
}

// ── Render select list ───────────────────────────────────────────────

function renderSelectList(
	listId: string, label: string, itemClass: string,
	items: Array< Record< string, string > >,
	labelFn: ( i: Record< string, string > ) => string,
	attrsFn: ( i: Record< string, string > ) => Record< string, string >,
): void {
	const $list = $( '#' + listId ); $list.empty();
	$list.append( '<p><strong>Select a ' + label + ':</strong></p>' );
	const $ul = $( '<ul style="list-style:disc;margin-left:20px;"></ul>' );
	for ( const item of items ) { const $li = $( '<li style="margin-bottom:5px;"></li>' ); const $a = $( '<a href="#" class="' + itemClass + '"></a>' ).text( labelFn( item ) ); for ( const [ k, v ] of Object.entries( attrsFn( item ) ) ) { $a.attr( k, v ); } $li.append( $a ); $ul.append( $li ); }
	$list.append( $ul );
}

// ── Accordion ────────────────────────────────────────────────────────

export function initAccordion(): void {
	const $allSections = $( '.wp-mcp-ai-section' );
	const $allHeaders = $( '.wp-mcp-ai-section__header' );

	function saveState(): void {
		const expandedIds: string[] = [];
		$allSections.filter( '.wp-mcp-ai-section--expanded' ).each( function ( this: HTMLElement ) { const id = this.id; if ( id ) { expandedIds.push( id ); } } );
		try { localStorage.setItem( 'wp_mcp_ai_expanded_sections', JSON.stringify( expandedIds ) ); } catch { /* ignore */ }
	}

	$allHeaders.on( 'click', function ( this: HTMLElement ) { const $section = $( this ).closest( '.wp-mcp-ai-section' ); $section.toggleClass( 'wp-mcp-ai-section--expanded' ); saveState(); } );

	// Restore
	try { const stored = localStorage.getItem( 'wp_mcp_ai_expanded_sections' ); if ( stored ) { const ids: string[] = JSON.parse( stored ); for ( const id of ids ) { $( '#' + id ).addClass( 'wp-mcp-ai-section--expanded' ); } } } catch { /* ignore */ }
}

// ── Token usage handlers ─────────────────────────────────────────────

export function initTokenUsageHandlers(): void {
	$( '.wp-mcp-ai-reset-user-usage' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const $btn = $( this ); if ( ! confirm( 'Reset token usage for this user?' ) ) { return; } $btn.prop( 'disabled', true ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_reset_user_usage', nonce: wpMcpAiAdmin.nonce, user_id: $btn.data( 'user-id' ) } }, { success() { $btn.text( 'Reset' ).prop( 'disabled', false ); }, error() { $btn.text( 'Error' ).prop( 'disabled', false ); } } ); } );
	$( '.wp-mcp-ai-reset-all-usage' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const $btn = $( this ); if ( ! confirm( 'Reset ALL token usage?' ) ) { return; } $btn.prop( 'disabled', true ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_reset_all_usage', nonce: wpMcpAiAdmin.nonce } }, { success() { $btn.text( 'All Reset' ).prop( 'disabled', false ); }, error() { $btn.text( 'Error' ).prop( 'disabled', false ); } } ); } );
}

// ── Provider priority list ───────────────────────────────────────────

export function initProviderPriorityList(): void {
	const $sortable = $( '.wp-mcp-ai-provider-priority-list' );
	if ( ! $sortable.length ) { return; }
	$sortable.sortable( { axis: 'y', handle: '.wp-mcp-ai-provider-priority-handle', cursor: 'move', placeholder: 'wp-mcp-ai-provider-priority-placeholder', opacity: 0.7, tolerance: 'pointer', update() { $( '.wp-mcp-ai-provider-priority-item' ).each( function ( this: HTMLElement, i ) { $( this ).find( 'input' ).val( String( i + 1 ) ); } ); } } );
}

// ── Embedded model management ────────────────────────────────────────

export function initEmbeddedModelManagement(): void {
	const $container = $( '.wp-mcp-ai-embedded-models' );
	if ( ! $container.length ) { return; }
	$( '.wp-mcp-ai-download-llama-model' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const $btn = $( this ); const slug = $btn.data( 'model-slug' ) as string; const $row = $btn.closest( 'tr' ); $btn.prop( 'disabled', true ).text( 'Downloading...' ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', timeout: 300000, data: { action: 'wp_mcp_ai_download_llama_model', nonce: wpMcpAiAdmin.nonce, model: slug } }, { success( r: { success: boolean; data?: { message?: string } } ) { if ( r.success ) { $row.find( '.wp-mcp-ai-model-status' ).html( '<span style="color:#00a32a;">Downloaded</span>' ); } else { $row.find( '.wp-mcp-ai-model-status' ).html( '<span style="color:#d63638;">' + ( r.data?.message || 'Failed' ) + '</span>' ); } $btn.prop( 'disabled', false ).text( 'Download' ); }, error() { $btn.prop( 'disabled', false ).text( 'Error' ); } } ); } );
	$( '.wp-mcp-ai-delete-llama-model' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const $btn = $( this ); const slug = $btn.data( 'model-slug' ) as string; if ( ! confirm( 'Delete model "' + slug + '"?' ) ) { return; } const $row = $btn.closest( 'tr' ); $btn.prop( 'disabled', true ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_delete_llama_model', nonce: wpMcpAiAdmin.nonce, model: slug } }, { success() { $row.remove(); }, error() { $btn.prop( 'disabled', false ); } } ); } );
	$( '.wp-mcp-ai-reinstall-llama-binary' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const $btn = $( this ); if ( ! confirm( 'Reinstall the Llama.cpp binary?' ) ) { return; } $btn.prop( 'disabled', true ).text( 'Reinstalling...' ); const $status = $( '.wp-mcp-ai-llama-binary-status' ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', timeout: 300000, data: { action: 'wp_mcp_ai_reinstall_llama_binary', nonce: wpMcpAiAdmin.nonce } }, { success() { $status.html( '<span style="color:#00a32a;">Reinstalled</span>' ); $btn.text( 'Reinstall Binary' ).prop( 'disabled', false ); }, error() { $btn.prop( 'disabled', false ).text( 'Error' ); } } ); } );
}

// ── Save expanded state (global function) ────────────────────────────

export function saveExpandedState(): void {
	const sections = document.querySelectorAll( '.wp-mcp-ai-section--expanded' );
	const ids = Array.from( sections ).map( ( s ) => s.id ).filter( Boolean );
	try { localStorage.setItem( 'wp_mcp_ai_expanded_sections', JSON.stringify( ids ) ); } catch { /* ignore */ }
}

// ── Boot ─────────────────────────────────────────────────────────────

export function initAdminSettings(): void {
	log( 'Initializing admin settings...' );
	initColorPickers();
	initAccordion();
	initOllamaHandlers();
	initLMStudioHandlers();
	initCloudwaysHandlers();
	initCloudflareHandlers();
	initBraveSearchHandlers();
	initTavilyHandlers();
	initMubertHandlers();
	initYahooHandlers();
	initRemovebgHandlers();
	initFlowhubHandlers();
	initTokenUsageHandlers();
	initProviderPriorityList();
	initEmbeddedModelManagement();
	( window as unknown as Record< string, unknown > ).wpMcpAiSaveExpandedState = saveExpandedState;
	log( 'Admin settings initialized' );
}

$( document ).ready( () => { initAdminSettings(); } );
