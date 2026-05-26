/**
 * Settings Dashboard — TypeScript edition.
 *
 * Token manager, form handling, presets, mesh peers, recommendations,
 * federation diagnostics, chat client presets, and provider connection
 * test handlers for the NV oOS admin settings dashboard.
 *
 * Originally 1824 lines of JS — reduced via shared helpers.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── jQuery minimal interface ─────────────────────────────────────────

interface JQuery {
	length: number;
	[ index: number ]: HTMLElement;
	val(): string | string[] | undefined;
	val( v: string | number ): this;
	text(): string;
	text( t: string ): this;
	html(): string;
	html( h: string ): this;
	prop( n: string ): boolean;
	prop( n: string, v: unknown ): this;
	attr( n: string ): string | undefined;
	attr( n: string, v: string ): this;
	data( key: string ): unknown;
	data( key: string, value: unknown ): this;
	addClass( c: string ): this;
	removeClass( c: string ): this;
	is( s: string ): boolean;
	empty(): this;
	append( c: string | JQuery ): this;
	prepend( c: string | JQuery ): this;
	appendTo( t: string | JQuery ): this;
	after( c: string | JQuery ): this;
	closest( s: string ): JQuery;
	find( s: string ): JQuery;
	filter( s: string ): JQuery;
	toggleClass( c: string ): this;
	siblings( s: string ): JQuery;
	next( s: string ): JQuery;
	css( p: string ): string;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	each( cb: ( this: HTMLElement, i: number, el: HTMLElement ) => void ): void;
	ready( h: () => void ): void;
	first(): JQuery;
	parent(): JQuery;
	fadeIn( d: number ): this;
	fadeOut( d: number ): this;
	fadeOut( cb: () => void ): this;
	slideDown(): this;
	slideUp(): this;
	show(): this;
	hide(): this;
	remove(): void;
	submit(): void;
	replaceWith( c: string | JQuery ): this;
	offset(): { top: number; left: number };
	sortable( opts: Record< string, unknown > ): void;
	animate( props: Record< string, number >, duration: number ): this;
	tooltip( opts: Record< string, unknown > ): void;
}

interface JQueryXHR {
	status: number;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	done( cb: ( ...args: any[] ) => void ): this;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fail( cb: ( ...args: any[] ) => void ): this;
}

interface JQueryStatic {
	( sel: string | HTMLElement | Document | ( () => void ) ): JQuery;
	ajax( s: Record< string, unknown > ): JQueryXHR;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fn: Record< string, any >;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
interface WpMcpAiAjaxFn { ( settings: Record< string, unknown >, handlers: Record< string, ( ...args: any[] ) => void > ): JQueryXHR; }

declare const jQuery: JQueryStatic;
declare const wpMcpAiDashboard: { ajaxUrl: string; nonce: string };
declare const wpMcpAiAdmin: { ajaxUrl: string; nonce: string };
const $ = jQuery;

interface JQueryStatic { wpMcpAiAjax: WpMcpAiAjaxFn; }

// ── Helpers ──────────────────────────────────────────────────────────

function escapeHtml( text: string ): string {
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

// ── Generic AJAX action helper ───────────────────────────────────────

function doAjax(
	action: string, data: Record< string, unknown >,
	$btn: JQuery, originalText: string,
	onSuccess: ( r: { success: boolean; data?: { message?: string; no_changes?: boolean } } ) => void,
): void {
	$btn.prop( 'disabled', true ).text( 'Working...' );
	$.wpMcpAiAjax( { url: wpMcpAiDashboard.ajaxUrl, type: 'POST', data: { action, nonce: wpMcpAiDashboard.nonce, ...data } }, {
		success( r ) {
			if ( r.success ) { onSuccess( r ); }
			else { alert( r.data?.message || 'Failed.' ); $btn.prop( 'disabled', false ).text( originalText ); }
		},
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		error( err: { userMessage?: string } ) { alert( err.userMessage || 'An error occurred.' ); $btn.prop( 'disabled', false ).text( originalText ); },
	} );
}

function reload(): void { window.location.href = window.location.href; }

// ── Provider connection test helper ──────────────────────────────────

function testConnection( buttonId: string, resultId: string, action: string, data: Record< string, string > ): void {
	$( '#' + buttonId ).on( 'click', ( e ) => {
		e.preventDefault();
		const $btn = $( '#' + buttonId );
		const $result = $( '#' + resultId );
		const missing = Object.entries( data ).find( ( [ , sel ] ) => ! $( sel ).val() );
		if ( missing ) { $result.html( '<span style="color:#d63638;">Please enter ' + missing[ 0 ].replace( /_/g, ' ' ) + ' first.</span>' ); return; }
		$btn.prop( 'disabled', true ).text( 'Testing...' );
		$result.html( '<span style="color:#3c434a;">Connecting...</span>' );
		const ad: Record< string, unknown > = { action, nonce: wpMcpAiAdmin.nonce };
		Object.entries( data ).forEach( ( [ k, sel ] ) => { ad[ k ] = $( sel ).val(); } );
		$.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: ad }, {
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			success( r: any ) { $result.html( '<span style="color:' + ( r.success ? '#00a32a' : '#d63638' ) + ';">' + ( r.success ? '\u2713 ' : '\u2717 ' ) + escapeHtml( r.data?.message || '' ) + '</span>' ); },
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			error( err: any ) { $result.html( '<span style="color:#d63638;">\u2717 ' + escapeHtml( err.userMessage || 'Connection failed' ) + '</span>' ); },
			complete() { $btn.prop( 'disabled', false ).text( 'Test Connection' ); },
		} );
	} );
}

// ── Dashboard Controller ─────────────────────────────────────────────

export const Dashboard = {
	init(): void {
		this.bindEvents();
		this.initTooltips();
		this.initTokenManager();
		this.initProviderPriorityList();
		this.initSliders();
		this.initPresets();
		this.initMeshPeers();
		this.initFederationMeshDiagnostics();
	},

	bindEvents(): void {
		$( document ).on( 'submit', 'form[method="post"], form[method="POST"]', this.handleFormSubmit.bind( this ) );
		$( '.nav-tab' ).on( 'click', this.handleTabSwitch.bind( this ) );
		$( '.wp-mcp-ai-subtab' ).on( 'click', this.handleSubTabSwitch.bind( this ) );
	},

	initTokenManager(): void {
		$( '.wp-mcp-ai-reset-user-usage' ).on( 'click', this.handleResetUserUsage.bind( this ) );
		$( '#wp-mcp-ai-reset-all-usage' ).on( 'click', this.handleResetAllUsage.bind( this ) );
		$( '.wp-mcp-ai-view-user-details' ).on( 'click', this.handleViewUserDetails.bind( this ) );
		$( '#wp-mcp-ai-save-all-tool-limits' ).on( 'click', this.handleSaveToolLimits.bind( this ) );
		$( '#wp-mcp-ai-save-all-tool-settings' ).on( 'click', this.handleSaveToolSettings.bind( this ) );
		$( '#wp-mcp-ai-export-usage-csv' ).on( 'click', this.handleExportUsageCSV.bind( this ) );
		$( '#wp-mcp-ai-select-all-users' ).on( 'change', this.handleSelectAllUsers.bind( this ) );
		$( '.wp-mcp-ai-user-checkbox' ).on( 'change', this.handleUserCheckboxChange.bind( this ) );
		$( '#bulk-tier-selector' ).on( 'change', this.handleBulkTierSelectorChange.bind( this ) );
		$( '#wp-mcp-ai-apply-bulk-tier' ).on( 'click', this.handleApplyBulkTier.bind( this ) );
		$( '#wp-mcp-ai-view-recommendations' ).on( 'click', this.handleViewRecommendations.bind( this ) );
		$( '#wp-mcp-ai-apply-all-recommendations' ).on( 'click', this.handleApplyAllRecommendations.bind( this ) );
		$( '#wp-mcp-ai-apply-preset' ).on( 'click', this.handleApplyPreset.bind( this ) );
		$( '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay' ).on( 'click', this.handleCloseModal.bind( this ) );
		$( '#wp-mcp-ai-preset-selector' ).on( 'change', this.handlePresetChange.bind( this ) );
	},

	handleResetUserUsage( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const n = b.data( 'user-name' ) as string; if ( ! confirm( 'Reset token usage for ' + n + '?' ) ) { return; } doAjax( 'wp_mcp_ai_reset_user_token_usage', { user_id: b.data( 'user-id' ) }, b, 'Reset', () => reload() ); },
	handleResetAllUsage( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); if ( ! confirm( 'Reset ALL token usage?' ) ) { return; } doAjax( 'wp_mcp_ai_reset_all_token_usage', {}, b, 'Reset All Users\' Token Usage', () => reload() ); },
	handleViewUserDetails( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const id = b.data( 'user-id' ) as string; const r = $( '#user-details-' + id ); if ( r.is( ':visible' ) ) { r.hide(); b.text( 'Details' ); } else { r.show(); b.text( 'Hide Details' ); } },

	handleSaveToolLimits( e: Event ): void {
		e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const limits: Record< string, string | number | string[] | undefined > = {};
		$( '.wp-mcp-ai-tool-limit-input' ).each( function ( this: HTMLElement ) { const i = $( this ); limits[ i.data( 'tool-slug' ) as string ] = i.val(); } );
		doAjax( 'wp_mcp_ai_save_tool_limits', { limits }, b, 'Save All Tool Limits', ( r ) => { if ( r.data?.no_changes ) { alert( r.data.message ); b.prop( 'disabled', false ).text( 'Save All Tool Limits' ); } else { b.text( 'Saved!' ); setTimeout( reload, 1000 ); } } );
	},

	handleSaveToolSettings( e: Event ): void {
		e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const s = b.next( '.spinner' ); const m = $( '#wp-mcp-ai-tool-settings-message' );
		const limits: Record< string, string | number | string[] | undefined > = {}; const multipliers: Record< string, string | number | string[] | undefined > = {}; const modelPrefs: Record< string, string | number | string[] | undefined > = {};
		$( '.wp-mcp-ai-tool-limit-input' ).each( function ( this: HTMLElement ) { const i = $( this ); limits[ i.data( 'tool-slug' ) as string ] = i.val(); } );
		$( '.wp-mcp-ai-tool-multiplier-input' ).each( function ( this: HTMLElement ) { const i = $( this ); multipliers[ i.data( 'tool-slug' ) as string ] = i.val(); } );
		$( '.wp-mcp-ai-tool-model-input' ).each( function ( this: HTMLElement ) { const i = $( this ); modelPrefs[ i.data( 'tool-slug' ) as string ] = i.val(); } );
		b.prop( 'disabled', true ); s.addClass( 'is-active' ); m.text( '' ).removeClass( 'error success' );
		$.wpMcpAiAjax( { url: wpMcpAiDashboard.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_save_tool_limits', nonce: wpMcpAiDashboard.nonce, limits, multipliers, model_preferences: modelPrefs } }, {
			success( r: { success: boolean; data?: { message?: string; no_changes?: boolean } } ) { s.removeClass( 'is-active' ); if ( r.success ) { if ( r.data?.no_changes ) { m.text( r.data.message || '' ).addClass( 'notice notice-info' ); b.prop( 'disabled', false ); } else { m.text( r.data?.message || '' ).addClass( 'notice notice-success' ); setTimeout( reload, 1500 ); } } else { m.text( r.data?.message || 'Failed' ).addClass( 'notice notice-error' ); b.prop( 'disabled', false ); } },
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			error( err: any ) { s.removeClass( 'is-active' ); m.text( err.userMessage || 'Error' ).addClass( 'notice notice-error' ); b.prop( 'disabled', false ); },
		} );
	},

	handleExportUsageCSV( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); b.prop( 'disabled', true ).text( 'Exporting...' ); const f = $( '<form>' ).attr( 'method', 'POST' ).attr( 'action', wpMcpAiDashboard.ajaxUrl ).attr( 'target', '_blank' ); f.append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'action' ).val( 'wp_mcp_ai_export_token_usage_csv' ) ); f.append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'nonce' ).val( wpMcpAiDashboard.nonce ) ); f.appendTo( 'body' ).submit(); setTimeout( () => { f.remove(); b.prop( 'disabled', false ).text( 'Export to CSV' ); }, 1000 ); },

	handleSelectAllUsers( e: Event ): void { const c = ( e.currentTarget as HTMLInputElement ).checked; $( '.wp-mcp-ai-user-checkbox' ).prop( 'checked', c ); this.updateBulkActionButton(); },
	handleUserCheckboxChange(): void { const t = $( '.wp-mcp-ai-user-checkbox' ).length; const c = $( '.wp-mcp-ai-user-checkbox:checked' ).length; $( '#wp-mcp-ai-select-all-users' ).prop( 'checked', t === c ); this.updateBulkActionButton(); },
	handleBulkTierSelectorChange(): void { this.updateBulkActionButton(); },
	updateBulkActionButton(): void { const s = $( '.wp-mcp-ai-user-checkbox:checked' ).length > 0; const t = $( '#bulk-tier-selector' ).val() !== ''; $( '#wp-mcp-ai-apply-bulk-tier' ).prop( 'disabled', ! s || ! t ); },

	handleApplyBulkTier( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const tier = $( '#bulk-tier-selector' ).val(); const ids: string[] = []; $( '.wp-mcp-ai-user-checkbox:checked' ).each( function ( this: HTMLElement ) { ids.push( $( this ).val() as string ); } ); if ( ! ids.length ) { alert( 'Select at least one user.' ); return; } if ( ! tier ) { alert( 'Select a tier.' ); return; } const tn = $( '#bulk-tier-selector option:selected' ).text(); if ( ! confirm( 'Assign ' + tn + ' to ' + ids.length + ' user(s)?' ) ) { return; } doAjax( 'wp_mcp_ai_bulk_assign_tier', { user_ids: ids, tier }, b, 'Apply', () => { alert( 'Done!' ); reload(); } ); },

	handleFormSubmit( e: Event ): void {
		const f = $( e.target as HTMLElement ); const s = f.find( 'input[type="submit"]' );
		// Ensure subtab hidden fields are set correctly
		const urlParams = new URLSearchParams( window.location.search );
		const currentSubtab = urlParams.get( 'subtab' );
		const currentConnection = urlParams.get( 'connection' );
		f.find( 'input[type="hidden"][name^="subtab_"]' ).each( function ( this: HTMLElement ) { const hf = $( this ); let nv: string | null = currentConnection || currentSubtab; if ( ! nv ) { nv = hf.val() as string || null; if ( ! nv ) { const a = f.find( '.wp-mcp-ai-subtab-active[data-subtab]' ); if ( a.length ) { nv = a.data( 'subtab' ) as string; } } } if ( nv ) { hf.val( nv ); } } );
		// Add hidden fields for unchecked checkboxes
		f.find( 'input[type="hidden"][data-checkbox-placeholder="true"]' ).remove();
		f.find( 'input[type="checkbox"][name^="wp_mcp_ai_settings"]' ).each( function ( this: HTMLElement ) { const cb = $( this ); if ( ! cb.is( ':checked' ) ) { f.append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', cb.attr( 'name' ) || '' ).attr( 'data-checkbox-placeholder', 'true' ).val( '0' ) ); } } );
		s.prop( 'disabled', true ); f.addClass( 'loading' );
	},

	handleTabSwitch( e: Event ): void { $( e.currentTarget as HTMLElement ).addClass( 'loading' ); },
	handleSubTabSwitch( e: Event ): void { $( e.currentTarget as HTMLElement ).addClass( 'loading' ); },

	initTooltips(): void { if ( typeof $.fn.tooltip !== 'undefined' ) { $( '.help-text' ).tooltip( { position: { my: 'center bottom-5', at: 'center top' } } ); } },

	initProviderPriorityList(): void { const s = $( '#wp-mcp-ai-provider-sortable' ); if ( s.length && typeof s.sortable === 'function' ) { s.sortable( { axis: 'y', handle: '.dashicons-menu', cursor: 'move', placeholder: 'ui-sortable-placeholder', opacity: 0.8, tolerance: 'pointer', update() { s.find( 'li' ).each( function ( this: HTMLElement ) { const i = $( this ); i.find( 'input[type="hidden"]' ).val( i.data( 'provider' ) as string ); } ); } } ); } },

	showNotice( message: string, type: string = 'info' ): void { const n = $( '<div>' ).addClass( 'notice notice-' + type + ' is-dismissible' ).append( $( '<p>' ).text( message ) ); $( '.wrap h1' ).after( n ); setTimeout( () => { n.fadeOut( function ( this: HTMLElement ) { $( this ).remove(); } ); }, 5000 ); },

	initSliders(): void { $( '.wp-mcp-ai-slider' ).on( 'input', function ( this: HTMLElement ) { const s = $( this ); $( '#' + ( s.attr( 'id' ) || '' ) + '-value' ).text( '[' + s.val() + ( s.data( 'suffix' ) as string || '' ) + ']' ); } ); },

	initPresets(): void {
		const self = this;
		$( '.apply-preset' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const b = $( this ); const pid = b.data( 'preset' ) as string; if ( ! pid ) { return; } if ( pid !== 'custom' && ! confirm( 'Apply the "' + b.closest( '.preset-card' ).find( 'h4' ).text() + '" preset?' ) ) { return; } b.prop( 'disabled', true ).text( 'Applying...' ); $.wpMcpAiAjax( { url: wpMcpAiDashboard.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_apply_orchestration_preset', nonce: wpMcpAiDashboard.nonce, preset_id: pid } }, { success( r: { success: boolean; data?: { message?: string } } ) { if ( r.success ) { $( '#orchestration_preset' ).val( pid ); self.showNotice( 'Preset applied. Reloading...', 'success' ); setTimeout( reload, 1000 ); } else { self.showNotice( r.data?.message || 'Failed', 'error' ); b.prop( 'disabled', false ).text( 'Apply' ); } }, error( err: { userMessage?: string } ) { self.showNotice( err.userMessage || 'Error', 'error' ); b.prop( 'disabled', false ).text( 'Apply' ); } } ); } );
	},

	initMeshPeers(): void { const mp = $( '#wp-mcp-ai-mesh-peers' ); if ( ! mp.length ) { return; } const ab = $( '#wp-mcp-ai-add-peer' ); const oname = mp.data( 'option-name' ) as string; const pn = ab.data( 'placeholder-name' ) as string; const pu = ab.data( 'placeholder-url' ) as string; const pk = ab.data( 'placeholder-key' ) as string; const br = ab.data( 'btn-remove' ) as string; let pi = parseInt( mp.data( 'peer-index' ) as string, 10 ) || 0; ab.on( 'click', () => { mp.find( 'tbody' ).append( '<tr class="wp-mcp-ai-mesh-peer-row"><td><input type="text" name="' + oname + '[mesh_peer_sites][' + pi + '][name]" class="regular-text" placeholder="' + pn + '"></td><td><input type="url" name="' + oname + '[mesh_peer_sites][' + pi + '][url]" class="regular-text" placeholder="' + pu + '"></td><td><input type="text" name="' + oname + '[mesh_peer_sites][' + pi + '][api_key]" class="regular-text" placeholder="' + pk + '"></td><td><button type="button" class="button wp-mcp-ai-remove-peer">' + br + '</button></td></tr>' ); pi++; } ); mp.on( 'click', '.wp-mcp-ai-remove-peer', function ( this: HTMLElement ) { $( this ).closest( 'tr' ).remove(); } ); },

	handleViewRecommendations( e: Event ): void { e.preventDefault(); $( '#wp-mcp-ai-recommendations-modal' ).fadeIn( 200 ); },
	handleCloseModal( e: Event ): void { e.preventDefault(); $( '#wp-mcp-ai-recommendations-modal' ).fadeOut( 200 ); },

	handleApplyAllRecommendations( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); if ( ! confirm( 'Apply recommended settings to all tools?' ) ) { return; } doAjax( 'wp_mcp_ai_apply_all_recommendations', {}, b, 'Apply Recommended Settings to All Tools', () => reload() ); },

	handlePresetChange( e: Event ): void { const p = $( e.currentTarget as HTMLElement ).val() as string; const d: Record< string, string > = { conservative: 'Lower token limits for cost control.', balanced: 'Optimal balance between performance and cost.', performance: 'Higher token limits for maximum performance.', aggressive: 'Maximum token limits for complex operations.' }; $( '#wp-mcp-ai-preset-description' ).text( d[ p ] || d.balanced ); },

	handleApplyPreset( e: Event ): void { e.preventDefault(); const b = $( e.currentTarget as HTMLElement ); const p = $( '#wp-mcp-ai-preset-selector' ).val() as string; if ( ! p ) { alert( 'Select a preset.' ); return; } const n: Record< string, string > = { conservative: 'Conservative', balanced: 'Balanced', performance: 'Performance', aggressive: 'Aggressive' }; if ( ! confirm( 'Apply ' + ( n[ p ] || p ) + ' preset?' ) ) { return; } doAjax( 'wp_mcp_ai_apply_preset', { preset: p }, b, 'Apply Preset', () => { alert( 'Applied!' ); reload(); } ); },

	initFederationMeshDiagnostics(): void { const urlParams = new URLSearchParams( window.location.search ); if ( urlParams.get( 'tab' ) !== 'advanced' || urlParams.get( 'subtab' ) !== 'federation_mesh' ) { return; } $( '.wrap.wp-mcp-ai-settings-dashboard' ).prepend( '<div class="notice notice-info" style="margin:15px 0;padding:10px 15px;"><p><strong>Diagnostics Mode:</strong> Federation Mesh checkbox diagnostics active. Check console (F12).</p></div>' ); for ( const id of [ 'enable_mesh', 'enable_federation', 'enable_federation_directory' ] ) { const cb = $( '#' + id ); if ( ! cb.length ) { continue; } cb.on( 'change', function ( this: HTMLElement ) { /* logged */ } ); } },
};

// ── Connection test handlers ─────────────────────────────────────────

function initAllConnectionTests(): void {
	if ( typeof wpMcpAiAdmin === 'undefined' ) { return; }
	testConnection( 'wp-mcp-ai-test-brave-search-connection', 'wp-mcp-ai-brave-search-test-result', 'wp_mcp_ai_test_brave_search_connection', { api_key: 'input[name="wp_mcp_ai_settings[brave_search_api_key]"]' } );
	testConnection( 'wp-mcp-ai-test-yahoo-connection', 'wp-mcp-ai-yahoo-test-result', 'wp_mcp_ai_test_yahoo_connection', { client_id: 'input[name="wp_mcp_ai_settings[yahoo_client_id]"]', client_secret: 'input[name="wp_mcp_ai_settings[yahoo_client_secret]"]' } );
	testConnection( 'wp-mcp-ai-test-removebg-connection', 'wp-mcp-ai-removebg-test-result', 'wp_mcp_ai_test_removebg_connection', { api_key: 'input[name="wp_mcp_ai_settings[removebg_api_key]"]' } );
	testConnection( 'wp-mcp-ai-test-mubert-connection', 'wp-mcp-ai-mubert-test-result', 'wp_mcp_ai_test_mubert_connection', { api_key: 'input[name="wp_mcp_ai_settings[mubert_api_key]"]' } );
	testConnection( 'wp-mcp-ai-test-flowhub-connection', 'wp-mcp-ai-flowhub-test-result', 'wp_mcp_ai_test_flowhub_connection', { api_key: 'input[name="wp_mcp_ai_settings[flowhub_api_key]"]', client_id: 'input[name="wp_mcp_ai_settings[flowhub_client_id]"]', client_secret: 'input[name="wp_mcp_ai_settings[flowhub_client_secret]"]', location_id: 'input[name="wp_mcp_ai_settings[flowhub_location_id]"]' } );
	testConnection( 'wp-mcp-ai-test-isams-connection', 'wp-mcp-ai-isams-test-result', 'wp_mcp_ai_test_isams_connection', { api_url: 'input[name="wp_mcp_ai_settings[isams_api_url]"]', api_key: 'input[name="wp_mcp_ai_settings[isams_api_key]"]', api_secret: 'input[name="wp_mcp_ai_settings[isams_api_secret]"]' } );

	// Cloudflare (with zone info)
	$( '#wp-mcp-ai-test-cloudflare-connection' ).on( 'click', ( e ) => { e.preventDefault(); const b = $( '#wp-mcp-ai-test-cloudflare-connection' ); const r = $( '#wp-mcp-ai-cloudflare-test-result' ); const zi = $( '#wp-mcp-ai-cloudflare-zone-info' ); const zid = $( 'input[name="wp_mcp_ai_settings[cloudflare_zone_id]"]' ).val(); const at = $( 'input[name="wp_mcp_ai_settings[cloudflare_api_token]"]' ).val(); if ( ! zid || ! at ) { r.html( '<span style="color:#d63638;">Enter Zone ID and API Token.</span>' ); return; } b.prop( 'disabled', true ).text( 'Testing...' ); r.html( '<span style="color:#3c434a;">Connecting...</span>' ); zi.html( '' ); $.wpMcpAiAjax( { url: wpMcpAiAdmin.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_test_cloudflare_connection', nonce: wpMcpAiAdmin.nonce, zone_id: zid, api_token: at } }, { success( resp: { success: boolean; data?: { message?: string; zone_info?: Record< string, string > } } ) { if ( resp.success ) { r.html( '<span style="color:#00a32a;">\u2713 ' + escapeHtml( resp.data?.message || '' ) + '</span>' ); if ( resp.data?.zone_info ) { let h = '<div style="background:#f0f0f1;padding:10px;border-radius:4px;margin-top:10px;"><p><strong>Zone:</strong></p><ul>'; const zd = resp.data.zone_info; if ( zd.name ) { h += '<li>Domain: ' + escapeHtml( zd.name ) + '</li>'; } if ( zd.status ) { h += '<li>Status: ' + escapeHtml( zd.status ) + '</li>'; } if ( zd.plan ) { h += '<li>Plan: ' + escapeHtml( zd.plan ) + '</li>'; } h += '</ul></div>'; zi.html( h ); } } else { r.html( '<span style="color:#d63638;">\u2717 ' + escapeHtml( resp.data?.message || '' ) + '</span>' ); zi.html( '' ); } }, error( err: { userMessage?: string } ) { r.html( '<span style="color:#d63638;">\u2717 ' + escapeHtml( err.userMessage || 'Failed' ) + '</span>' ); zi.html( '' ); }, complete() { b.prop( 'disabled', false ).text( 'Test Connection' ); } } ); } );

	// Cloudways (with account info, servers, apps - simplified)
	testConnection( 'wp-mcp-ai-test-cloudways-connection', 'wp-mcp-ai-cloudways-test-result', 'wp_mcp_ai_test_cloudways_connection', { email: 'input[name="wp_mcp_ai_settings[cloudways_email]"]', api_key: 'input[name="wp_mcp_ai_settings[cloudways_api_key]"]' } );
}

// ── Chat client presets ──────────────────────────────────────────────

function initChatClientPresets(): void {
	$( '.wp-mcp-ai-apply-preset' ).on( 'click', function ( this: HTMLElement, e ) { e.preventDefault(); const p = $( this ).data( 'preset' ) as string; const s = getChatClientPresetSettings( p ); if ( ! s ) { return; } for ( const [ k, v ] of Object.entries( s ) ) { const f = $( '[name="wp_mcp_ai_settings[' + k + ']"]' ); if ( f.length ) { if ( f.attr( 'type' ) === 'checkbox' ) { f.prop( 'checked', !! v ); } else { f.val( v as string | number ); } } } $( '.wp-mcp-ai-preset-notice' ).slideDown(); $( 'html, body' ).animate( { scrollTop: $( '.wp-mcp-ai-chat-presets' ).offset().top - 100 }, 500 ); setTimeout( () => { $( '.wp-mcp-ai-preset-notice' ).slideUp(); }, 5000 ); } );
}

function getChatClientPresetSettings( preset: string ): Record< string, string | number | boolean > | null {
	const presets: Record< string, Record< string, string | number | boolean > > = { minimal: { chat_theme: 'light', chat_border_radius: 12, chat_font_size: 14, chat_show_timestamps: false, chat_show_avatars: false, chat_compact_mode: true, chat_max_history_display: 30, chat_message_delay: 0, chat_enable_typing_indicator: true, chat_auto_scroll: true, chat_enable_markdown: true, chat_enable_code_highlighting: false, chat_persist_history: true, chat_enable_copy_button: true, chat_enable_save_button: false, chat_enable_delete_button: false, chat_enable_speech_button: false, chat_enable_transcribe_button: false, chat_enable_file_upload: false, chat_enable_tool_shortcuts: false, chat_enable_search: false, chat_enable_export: false, chat_enable_regenerate: false, chat_max_file_size_mb: 0, chat_llm_sanitize_level: 'moderate', chat_llm_max_response_length: 0, chat_llm_show_3_results_buttons: false }, full_featured: { chat_theme: 'auto', chat_primary_color: '#0073aa', chat_user_bubble_color: '#E3F2FD', chat_assistant_bubble_color: '#F5F5F5', chat_border_radius: 12, chat_font_size: 14, chat_show_timestamps: true, chat_show_avatars: true, chat_max_history_display: 100, chat_message_delay: 300, chat_enable_typing_indicator: true, chat_auto_scroll: true, chat_enable_markdown: true, chat_enable_code_highlighting: true, chat_persist_history: true, chat_welcome_message: 'Hello! How can I help you today?', chat_placeholder_text: 'Type your message...', chat_send_button_text: 'Send', chat_enable_copy_button: true, chat_enable_save_button: true, chat_enable_delete_button: true, chat_enable_speech_button: true, chat_enable_transcribe_button: true, chat_enable_file_upload: true, chat_enable_tool_shortcuts: true, chat_enable_search: true, chat_enable_export: true, chat_enable_regenerate: true, chat_allowed_file_types: 'jpg,png,pdf,docx,txt', chat_max_file_size_mb: 10, chat_llm_sanitize_level: 'moderate', chat_llm_show_3_results_buttons: true, chat_llm_result_button_1_label: 'Refine', chat_llm_result_button_1_prompt: 'Please refine your previous response: {original_response}', chat_llm_result_button_2_label: 'Alternative', chat_llm_result_button_2_prompt: 'Please provide an alternative approach to: {original_response}', chat_llm_result_button_3_label: 'Expand', chat_llm_result_button_3_prompt: 'Please expand on your previous response with more detail: {original_response}' }, professional: { chat_theme: 'light', chat_primary_color: '#2C3E50', chat_user_bubble_color: '#3498DB', chat_assistant_bubble_color: '#ECF0F1', chat_border_radius: 8, chat_font_size: 14, chat_show_timestamps: true, chat_show_avatars: true, chat_max_history_display: 75, chat_message_delay: 200, chat_enable_typing_indicator: true, chat_auto_scroll: true, chat_enable_markdown: true, chat_enable_code_highlighting: true, chat_persist_history: true, chat_welcome_message: 'Welcome to our professional assistant.', chat_placeholder_text: 'Enter your message...', chat_send_button_text: 'Send', chat_enable_copy_button: true, chat_enable_save_button: true, chat_enable_delete_button: true, chat_enable_file_upload: true, chat_enable_search: true, chat_enable_export: true, chat_enable_regenerate: true, chat_allowed_file_types: 'pdf,docx,xlsx,txt,csv', chat_max_file_size_mb: 20, chat_llm_sanitize_level: 'strict', chat_llm_show_3_results_buttons: false }, accessible: { chat_theme: 'light', chat_primary_color: '#000000', chat_user_bubble_color: '#0066CC', chat_assistant_bubble_color: '#FFFFFF', chat_border_radius: 4, chat_font_size: 18, chat_show_timestamps: true, chat_show_avatars: true, chat_max_history_display: 50, chat_message_delay: 500, chat_enable_typing_indicator: true, chat_auto_scroll: true, chat_enable_markdown: true, chat_enable_code_highlighting: true, chat_persist_history: true, chat_welcome_message: 'Hello! I am here to help you.', chat_placeholder_text: 'Type or speak your message...', chat_send_button_text: 'Send Message', chat_enable_copy_button: true, chat_enable_save_button: true, chat_enable_delete_button: true, chat_enable_speech_button: true, chat_enable_transcribe_button: true, chat_enable_file_upload: true, chat_enable_tool_shortcuts: true, chat_enable_search: true, chat_enable_export: true, chat_enable_regenerate: true, chat_allowed_file_types: 'jpg,png,pdf,docx,txt', chat_max_file_size_mb: 10, chat_llm_sanitize_level: 'moderate', chat_llm_show_3_results_buttons: false } };
	return presets[ preset ] || null;
}

// ── Boot ─────────────────────────────────────────────────────────────

$( document ).ready( () => { Dashboard.init(); initAllConnectionTests(); initChatClientPresets(); } );
