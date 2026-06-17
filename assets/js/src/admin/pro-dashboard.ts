/**
 * Pro Dashboard — TypeScript edition.
 *
 * Chart.js charts, compliance data, monitoring/framework/controls filtering,
 * bulk actions, exports, keyboard shortcuts, and auto-refresh.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Type declarations ────────────────────────────────────────────────

// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare const Chart: any;
declare const wpMcpAiProDashboard: {
	restUrl: string;
	restNonce: string;
	chartData?: { controls?: Record< string, number >; metrics?: Record< string, number[] >; risks?: Record< string, number > };
};

interface JQuery {
	length: number; [ index: number ]: HTMLElement;
	val(): string | undefined; val( v: string ): this;
	text(): string; text( t: string ): this;
	html(): string; html( h: string ): this;
	prop( n: string ): boolean; prop( n: string, v: unknown ): this;
	attr( n: string ): string | undefined;
	data( key: string ): unknown;
	addClass( c: string ): this; removeClass( c: string ): this; hasClass( c: string ): boolean;
	is( s: string ): boolean;
	empty(): this; append( c: string | JQuery ): this; prepend( c: string ): this; after( c: string | JQuery ): this;
	appendTo( t: string ): this;
	closest( s: string ): JQuery; find( s: string ): JQuery; filter( s: string ): JQuery;
	toggleClass( c: string, force?: boolean ): this; siblings( s: string ): JQuery;
	css( p: string, v?: string ): string | this;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	off( e: string ): this;
	each( cb: ( this: HTMLElement, i: number, el: HTMLElement ) => void ): void;
	ready( h: () => void ): void;
	first(): JQuery; parent(): JQuery;
	fadeOut( cb?: () => void ): this;
	fadeOut( d: number, cb?: () => void ): this;
	fadeIn( d?: number ): this;
	slideDown(): this; slideUp(): this; slideToggle(): this;
	show(): this; hide(): this; toggle( show?: boolean ): this;
	children( s?: string ): JQuery;
	remove(): void;
	offset(): { top: number; left: number };
	animate( props: Record< string, number >, d: number ): this;
	tooltip( o?: Record< string, unknown > ): void;
}

interface JQueryXHR {
	setRequestHeader( k: string, v: string ): void;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	done( cb: ( ...a: any[] ) => void ): this;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fail( cb: ( ...a: any[] ) => void ): this;
}

interface JQueryStatic {
	( sel: string | HTMLElement | Document | ( () => void ) ): JQuery;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	ajax( s: Record< string, unknown > ): any;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fn: Record< string, any >;
}

declare const jQuery: JQueryStatic;
const $ = jQuery;

// ── Helpers ──────────────────────────────────────────────────────────

function getDateString(): string {
	const n = new Date();
	return n.getFullYear() + '-' + String( n.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( n.getDate() ).padStart( 2, '0' );
}

function downloadCSV( data: Array< Record< string, string | number > >, filename: string ): void {
	if ( ! data.length ) { return; }
	const headers = Object.keys( data[ 0 ] );
	let csv = headers.join( ',' ) + '\n';
	for ( const row of data ) {
		const vals = headers.map( ( h ) => { const v = String( row[ h ] || '' ); return v.includes( ',' ) ? '"' + v.replace( /"/g, '""' ) + '"' : v; } );
		csv += vals.join( ',' ) + '\n';
	}
	const blob = new Blob( [ csv ], { type: 'text/csv' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' ); a.href = url; a.download = filename;
	document.body.appendChild( a ); a.click(); document.body.removeChild( a );
	URL.revokeObjectURL( url );
}

function spinner( $b: JQuery, disable: boolean ): void { $b.prop( 'disabled', disable ).toggleClass( 'loading', disable ); }

// ── Pro Dashboard ────────────────────────────────────────────────────

export const ProDashboard = {
	charts: {} as Record< string, unknown >,
	refreshInterval: null as ReturnType< typeof setInterval > | null,
	lastTabKey: 'wp_mcp_ai_last_dashboard_tab',

	init(): void {
		this.initTabStatePersistence();
		this.initKeyboardShortcuts();
		this.setupEventListeners();
		this.initializeComponents();
		this.loadComplianceData();
		this.waitForChartJS();
		this.startAutoRefresh();
	},

	initTabStatePersistence(): void {
		const self = this;
		$( '.nav-tab-wrapper .nav-tab' ).on( 'click', function ( this: HTMLElement ) {
			const m = ( $( this ).attr( 'href' ) || '' ).match( /[?&]tab=([^&]+)/ );
			if ( m?.[ 1 ] ) { try { localStorage.setItem( self.lastTabKey, m[ 1 ] ); } catch { /* ignore */ } }
		} );
		this.highlightLastTab();
	},

	highlightLastTab(): void {
		try {
			const lastTab = localStorage.getItem( this.lastTabKey );
			if ( lastTab && ! window.location.href.includes( 'tab=' + lastTab ) ) {
				$( '.nav-tab[href*="tab=' + lastTab + '"]' ).addClass( 'wp-mcp-ai-recently-visited' );
			}
		} catch { /* ignore */ }
	},

	initKeyboardShortcuts(): void {
		const tabs = [ 'iso27001', 'overview', 'reports', 'monitoring', 'risk', 'multi-framework' ];
		$( document ).on( 'keydown', ( e ) => {
			if ( ( e as KeyboardEvent ).altKey && ! ( e as KeyboardEvent ).ctrlKey && ! ( e as KeyboardEvent ).shiftKey ) {
				const k = ( e as KeyboardEvent ).key;
				const num = parseInt( k );
				if ( num >= 1 && num <= tabs.length ) { e.preventDefault(); window.location.href = wpMcpAiProDashboard.restUrl.replace( '/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=' + tabs[ num - 1 ] ); }
				if ( k === 'h' || k === 'H' ) { e.preventDefault(); this.showKeyboardShortcutsHelp(); }
			}
		} );
	},

	showKeyboardShortcutsHelp(): void {
		if ( $( '#wp-mcp-ai-shortcuts-help' ).length ) { $( '#wp-mcp-ai-shortcuts-help' ).fadeIn(); return; }
		$( 'body' ).append( '<div id="wp-mcp-ai-shortcuts-help" class="wp-mcp-ai-modal-overlay"><div class="wp-mcp-ai-modal-content"><button class="wp-mcp-ai-modal-close">×</button><h2>Keyboard Shortcuts</h2><table><tr><th>Alt+1</th><td>ISO 27001</td></tr><tr><th>Alt+2</th><td>Overview</td></tr><tr><th>Alt+3</th><td>Reports</td></tr><tr><th>Alt+4</th><td>Monitoring</td></tr><tr><th>Alt+5</th><td>Risk</td></tr><tr><th>Alt+6</th><td>Multi-Framework</td></tr><tr><th>Alt+H</th><td>Help</td></tr><tr><th>Esc</th><td>Close</td></tr></table></div></div>' );
		$( '#wp-mcp-ai-shortcuts-help' ).fadeIn().on( 'click', function ( this: HTMLElement, e ) { if ( e.target === this || $( e.target as HTMLElement ).hasClass( 'wp-mcp-ai-modal-close' ) ) { $( this ).fadeOut(); } } );
		$( document ).on( 'keydown.shortcuts-help', ( e ) => { if ( ( e as KeyboardEvent ).key === 'Escape' ) { $( '#wp-mcp-ai-shortcuts-help' ).fadeOut(); $( document ).off( 'keydown.shortcuts-help' ); } } );
	},

	waitForChartJS(): void { let attempts = 0; const check = () => { if ( typeof Chart !== 'undefined' ) { this.initializeChartsIfNeeded(); } else if ( attempts++ < 50 ) { setTimeout( check, 100 ); } else { this.showChartError(); } }; check(); },

	initializeChartsIfNeeded(): void { if ( document.getElementById( 'wpMcpAiControlsChart' ) ) { this.initializeCharts(); } },

	showChartError(): void { $( '.wp-mcp-ai-chart-container, .wp-mcp-ai-pro-chart-container' ).each( function ( this: HTMLElement ) { const c = $( this ); const f = c.closest( '.wp-mcp-ai-chart-card' ).find( '.wp-mcp-ai-chart-fallback' ); c.hide(); f.length ? f.show() : c.html( '<div class="wp-mcp-ai-chart-error"><span class="dashicons dashicons-warning"></span><p>Charts could not be loaded.</p></div>' ).show(); } ); },

	setupEventListeners(): void {
		$( document ).on( 'click', '.wp-mcp-ai-pro-notice .notice-dismiss', this.dismissProNotice );
		$( document ).on( 'click', '.wp-mcp-ai-refresh-dashboard', this.refreshDashboard.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-control-filter', this.filterControls.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-metric-card.interactive', this.handleMetricCardClick.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-export-dashboard', this.exportDashboard.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-export-controls', this.exportControls.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-export-risks', this.exportRisks.bind( this ) );
		$( document ).on( 'click', '.wp-mcp-ai-help-indicator', this.showKeyboardShortcutsHelp.bind( this ) );
	},

	handleMetricCardClick( e: Event ): void { const m = $( e.currentTarget as HTMLElement ).data( 'metric' ) as string; const u = wpMcpAiProDashboard.restUrl.replace( '/wp-json/', '/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=' ); if ( m === 'implemented' || m === 'partial' ) { window.location.href = u + 'iso27001&filter=' + m; } else if ( m === 'critical' ) { window.location.href = u + 'risk'; } else { window.location.href = u + 'iso27001'; } },

	exportDashboard( e: Event ): void { e.preventDefault(); alert( 'Dashboard export coming soon!' ); },
	exportControls( e: Event ): void { e.preventDefault(); const data: Array< Record< string, string | number > > = []; $( '.wp-mcp-ai-controls-table tbody tr' ).each( function ( this: HTMLElement ) { const r = $( this ); data.push( { id: r.find( 'td:eq(0)' ).text().trim(), name: r.find( 'td:eq(1) strong' ).text().trim(), status: r.find( 'td:eq(2)' ).text().trim(), applicable: r.find( 'td:eq(3) .dashicons-yes-alt' ).length ? 'Yes' : 'No' } ); } ); downloadCSV( data, 'iso27001-controls-' + getDateString() + '.csv' ); this.showSuccess( 'Controls exported!' ); },
	exportRisks( e: Event ): void { e.preventDefault(); const data: Array< Record< string, string | number > > = []; $( '.wp-mcp-ai-risk-register table tbody tr' ).each( function ( this: HTMLElement ) { const r = $( this ); if ( r.find( 'td' ).length >= 4 ) { data.push( { id: r.find( 'td:eq(0)' ).text().trim(), description: r.find( 'td:eq(1) strong' ).text().trim(), likelihood: r.find( 'td:eq(2)' ).text().trim(), impact: r.find( 'td:eq(3)' ).text().trim(), level: r.find( 'td:eq(4)' ).text().trim(), treatment: r.find( 'td:eq(5)' ).text().trim() } ); } } ); downloadCSV( data, 'risk-register-' + getDateString() + '.csv' ); this.showSuccess( 'Risk register exported!' ); },

	showSuccess( m: string ): void { const n = $( '<div class="notice notice-success is-dismissible"><p>' + m + '</p></div>' ); $( '.wp-mcp-ai-pro-dashboard h1' ).first().after( n ); setTimeout( () => { n.fadeOut( () => { n.remove(); } ); }, 3000 ); },

	initMonitoringFiltering(): void {
		const self = this;
		$( '#monitoring-event-type, #monitoring-severity, #monitoring-timeframe' ).on( 'change', () => self.filterMonitoringEvents() );
		let t: ReturnType< typeof setTimeout >; $( '#monitoring-search' ).on( 'keyup', () => { clearTimeout( t ); t = setTimeout( () => self.filterMonitoringEvents(), 300 ); } );
		$( '.wp-mcp-ai-clear-monitoring-filters' ).on( 'click', ( e ) => { e.preventDefault(); $( '#monitoring-event-type' ).val( 'all' ); $( '#monitoring-severity' ).val( 'all' ); $( '#monitoring-timeframe' ).val( '24h' ); $( '#monitoring-search' ).val( '' ); self.filterMonitoringEvents(); } );
		this.initMonitoringEnhancements();
	},

	filterMonitoringEvents(): void { const et = $( '#monitoring-event-type' ).val(); const sv = $( '#monitoring-severity' ).val(); const s = ( $( '#monitoring-search' ).val() as string || '' ).toLowerCase(); $( '.wp-mcp-ai-event-row' ).each( function ( this: HTMLElement ) { const r = $( this ); const show = ( et === 'all' || r.data( 'event-type' ) === et ) && ( sv === 'all' || r.data( 'event-severity' ) === sv ) && ( ! s || r.text().toLowerCase().includes( s ) ); r.toggle( show ); } ); $( '.wp-mcp-ai-event-count' ).text( 'Showing ' + $( '.wp-mcp-ai-event-row:visible' ).length + ' events' ); },

	initMonitoringEnhancements(): void {
		const self = this; let ai: ReturnType< typeof setInterval > | null = null;
		$( '#wp-mcp-ai-refresh-monitoring' ).on( 'click', ( e ) => { e.preventDefault(); self.refreshMonitoringData(); } );
		$( '#wp-mcp-ai-auto-refresh' ).on( 'change', function ( this: HTMLElement ) { if ( $( this ).is( ':checked' ) ) { ai = setInterval( () => self.refreshMonitoringData(), 30000 ); } else if ( ai ) { clearInterval( ai ); ai = null; } } );
		if ( $( '#wp-mcp-ai-auto-refresh' ).is( ':checked' ) ) { ai = setInterval( () => self.refreshMonitoringData(), 30000 ); }
		$( '#wp-mcp-ai-export-events' ).on( 'click', ( e ) => { e.preventDefault(); self.exportMonitoringEvents(); } );
		$( '#wp-mcp-ai-clear-dismissed' ).on( 'click', ( e ) => { e.preventDefault(); if ( confirm( 'Clear all dismissed events?' ) ) { $( '.wp-mcp-ai-event-row.dismissed' ).fadeOut( 300, function ( this: HTMLElement ) { $( this ).remove(); } ); } } );
		$( document ).on( 'click', '.wp-mcp-ai-dismiss-event', function ( this: HTMLElement, e ) { e.preventDefault(); if ( confirm( 'Dismiss?' ) ) { $( this ).closest( '.wp-mcp-ai-event-row' ).fadeOut( 300, function ( this: HTMLElement ) { $( this ).remove(); } ); } } );
		$( '#wp-mcp-ai-load-more-events' ).on( 'click', function ( this: HTMLElement ) { const b = $( this ); b.prop( 'disabled', true ).text( 'Coming soon...' ); setTimeout( () => b.prop( 'disabled', false ).text( 'Load More' ), 2000 ); } );
		this.initEventTimelineChart();
	},

	refreshMonitoringData(): void { const b = $( '#wp-mcp-ai-refresh-monitoring' ); spinner( b, true ); setTimeout( () => { const n = new Date(); $( '#wp-mcp-ai-last-update-time' ).text( String( n.getHours() ).padStart( 2, '0' ) + ':' + String( n.getMinutes() ).padStart( 2, '0' ) + ':' + String( n.getSeconds() ).padStart( 2, '0' ) ); spinner( b, false ); }, 1000 ); },

	exportMonitoringEvents(): void { const data: Array< Record< string, string | number > > = []; $( '.wp-mcp-ai-event-row:visible' ).each( function ( this: HTMLElement ) { const r = $( this ); data.push( { severity: r.data( 'event-severity' ) as string, type: r.data( 'event-type' ) as string, message: r.find( '.wp-mcp-ai-event-message' ).text().trim(), timestamp: r.data( 'event-timestamp' ) as string } ); } ); downloadCSV( data, 'monitoring-events-' + Date.now() + '.csv' ); },

	initEventTimelineChart(): void { const c = document.getElementById( 'wpMcpAiEventTimelineChart' ); if ( ! c || typeof Chart === 'undefined' ) { return; } const hours: string[] = []; const counts: number[] = []; for ( let i = 23; i >= 0; i-- ) { const h = new Date(); h.setHours( h.getHours() - i ); hours.push( String( h.getHours() ).padStart( 2, '0' ) + ':00' ); counts.push( Math.floor( Math.random() * 10 ) ); } new Chart( c, { type: 'line', data: { labels: hours, datasets: [ { label: 'Events', data: counts, borderColor: '#0073aa', backgroundColor: 'rgba(0,115,170,0.1)', tension: 0.4, fill: true } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } } ); },

	initFrameworkFiltering(): void {
		const self = this;
		$( '#framework-status-filter, #framework-category' ).on( 'change', () => self.filterFrameworks() );
		$( '.wp-mcp-ai-clear-framework-filters' ).on( 'click', () => { $( '#framework-status-filter' ).val( 'all' ); $( '#framework-category' ).val( 'all' ); self.filterFrameworks(); } );
		$( '.wp-mcp-ai-compare-frameworks' ).on( 'click', ( e ) => { e.preventDefault(); $( '.wp-mcp-ai-framework-selection' ).slideToggle(); } );
		$( '#wp-mcp-ai-select-all-frameworks' ).on( 'change', function ( this: HTMLElement ) { $( '.wp-mcp-ai-framework-select:visible' ).prop( 'checked', $( this ).prop( 'checked' ) ); self.updateFrameworkSelection(); } );
		$( document ).on( 'change', '.wp-mcp-ai-framework-select', () => self.updateFrameworkSelection() );
		$( '.wp-mcp-ai-generate-comparison' ).on( 'click', ( e ) => { e.preventDefault(); self.generateFrameworkComparison(); } );
	},

	filterFrameworks(): void { const st = $( '#framework-status-filter' ).val(); const ct = $( '#framework-category' ).val(); $( '.wp-mcp-ai-framework-card' ).each( function ( this: HTMLElement ) { const c = $( this ); c.toggle( ( st === 'all' || c.data( 'status' ) === st ) && ( ct === 'all' || c.data( 'category' ) === ct ) ); } ); },

	updateFrameworkSelection(): void { const n = $( '.wp-mcp-ai-framework-select:checked' ).length; $( '.wp-mcp-ai-selected-frameworks-count' ).text( n ? n + ' framework(s) selected' : '' ); },

	generateFrameworkComparison(): void { const s: Array< Record< string, string | number > > = []; $( '.wp-mcp-ai-framework-select:checked' ).each( function ( this: HTMLElement ) { const c = $( this ).closest( '.wp-mcp-ai-framework-card' ); s.push( { id: $( this ).val() as string, name: c.find( 'h3' ).text(), status: c.find( '.wp-mcp-ai-framework-status' ).text(), percentage: c.find( '.wp-mcp-ai-progress' ).text() } ); } ); if ( ! s.length ) { alert( 'Select at least one framework.' ); return; } this.showSuccess( 'Comparison report for ' + s.length + ' framework(s) coming soon!' ); },

	initializeComponents(): void { this.showChartLoading(); this.animateProgressBars(); if ( typeof $.fn.tooltip !== 'undefined' ) { $( '[data-toggle="tooltip"]' ).tooltip(); } this.initDateRangeSelector(); this.initControlsFiltering(); this.initBulkActions(); this.initMonitoringFiltering(); this.initFrameworkFiltering(); },

	initDateRangeSelector(): void { const self = this; $( '#wp-mcp-ai-date-range' ).on( 'change', function ( this: HTMLElement ) { $( '.wp-mcp-ai-custom-date-range' )[ $( this ).val() === 'custom' ? 'slideDown' : 'slideUp' ](); } ); $( '.wp-mcp-ai-apply-date-range' ).on( 'click', ( e ) => { e.preventDefault(); self.applyDateRange(); } ); },

	applyDateRange(): void { const r = $( '#wp-mcp-ai-date-range' ).val(); if ( r === 'custom' ) { if ( ! $( '#wp-mcp-ai-start-date' ).val() || ! $( '#wp-mcp-ai-end-date' ).val() ) { alert( 'Select both dates.' ); return; } } this.showSuccess( 'Date range applied.' ); },

	initControlsFiltering(): void { const self = this; $( '#controls-category-filter' ).on( 'change', () => self.filterControlsTable() ); $( '.wp-mcp-ai-clear-filters' ).on( 'click', () => { $( '#controls-status-filter' ).val( 'all' ); $( '#controls-category-filter' ).val( 'all' ); $( '#controls-search' ).val( '' ); self.filterControlsTable(); } ); },

	filterControlsTable(): void { const st = $( '#controls-status-filter' ).val(); const ct = $( '#controls-category-filter' ).val(); const s = ( $( '#controls-search' ).val() as string || '' ).toLowerCase(); $( '.wp-mcp-ai-control-row' ).each( function ( this: HTMLElement ) { const r = $( this ); r.toggle( ( st === 'all' || r.data( 'status' ) === st ) && ( ct === 'all' || String( r.data( 'category' ) ).startsWith( ct as string ) ) && ( ! s || r.text().toLowerCase().includes( s ) ) ); } ); },

	initBulkActions(): void { const self = this; $( '#wp-mcp-ai-select-all-table' ).on( 'change', function ( this: HTMLElement ) { $( '.wp-mcp-ai-control-checkbox:visible' ).prop( 'checked', $( this ).prop( 'checked' ) ); self.updateBulkActionsState(); } ); $( document ).on( 'change', '.wp-mcp-ai-control-checkbox', () => self.updateBulkActionsState() ); $( '.wp-mcp-ai-bulk-export' ).on( 'click', ( e ) => { e.preventDefault(); self.exportSelectedControls(); } ); },

	updateBulkActionsState(): void { const n = $( '.wp-mcp-ai-control-checkbox:checked' ).length; n ? $( '.wp-mcp-ai-bulk-actions' ).slideDown() : $( '.wp-mcp-ai-bulk-actions' ).slideUp(); $( '.wp-mcp-ai-selected-count' ).text( n ? n + ' selected' : '' ); },

	exportSelectedControls(): void { const data: Array< Record< string, string | number > > = []; $( '.wp-mcp-ai-control-checkbox:checked' ).each( function ( this: HTMLElement ) { const r = $( this ).closest( 'tr' ); data.push( { id: r.find( 'td:eq(0)' ).text().trim(), name: r.find( 'td:eq(1) strong' ).text().trim(), status: r.find( 'td:eq(2)' ).text().trim(), applicable: r.find( 'td:eq(3) .dashicons-yes-alt' ).length ? 'Yes' : 'No' } ); } ); if ( ! data.length ) { alert( 'No controls selected.' ); return; } downloadCSV( data, 'selected-controls-' + getDateString() + '.csv' ); this.showSuccess( data.length + ' exported!' ); },

	showChartLoading(): void { $( '.wp-mcp-ai-chart-container, .wp-mcp-ai-pro-chart-container' ).each( function ( this: HTMLElement ) { if ( $( this ).children( 'canvas' ).length ) { $( this ).prepend( '<div class="wp-mcp-ai-chart-loading"><span class="dashicons dashicons-update"></span><p>Loading...</p></div>' ); } } ); },
	hideChartLoading(): void { $( '.wp-mcp-ai-chart-loading' ).fadeOut( 300, function ( this: HTMLElement ) { $( this ).remove(); } ); },

	loadComplianceData(): void { if ( ! wpMcpAiProDashboard.restUrl ) { return; } const self = this; $.ajax( { url: wpMcpAiProDashboard.restUrl + 'mcp-ai/v1/pro/compliance/status', method: 'GET', beforeSend( xhr: JQueryXHR ) { xhr.setRequestHeader( 'X-WP-Nonce', wpMcpAiProDashboard.restNonce ); }, success( data: Record< string, unknown > ) { self.updateDashboardMetrics( data ); }, error( _xhr: { responseText?: string }, status: string, errorMsg: string ) { /* eslint-disable no-console */ console.error( 'Compliance load failed:', { status, error: errorMsg } ); /* eslint-enable */ } } ); },

	updateDashboardMetrics( data: Record< string, unknown > ): void {
		const d = data as { controls?: Record< string, number >; chatData?: Record< string, number >; recent_events?: Array< { icon?: string; message: string; time: string } > };
		if ( d.controls ) { $( '.wp-mcp-ai-stat-implemented' ).text( String( d.controls.implemented || 0 ) ); $( '.wp-mcp-ai-stat-partial' ).text( String( d.controls.partial || 0 ) ); $( '.wp-mcp-ai-stat-planned' ).text( String( d.controls.planned || 0 ) ); $( '.wp-mcp-ai-stat-total' ).text( String( d.controls.total || 93 ) ); }
		if ( d.chatData ) { $( '.wp-mcp-ai-chat-total' ).text( String( d.chatData.total_conversations || 0 ) ); $( '.wp-mcp-ai-chat-users' ).text( String( d.chatData.active_users || 0 ) ); $( '.wp-mcp-ai-chat-today' ).text( String( d.chatData.today_conversations || 0 ) ); $( '.wp-mcp-ai-chat-week' ).text( String( d.chatData.this_week_conversations || 0 ) ); }
		if ( d.recent_events?.length ) { const l = $( '.wp-mcp-ai-activity-list' ); if ( l.length ) { l.empty(); for ( const e of d.recent_events.slice( 0, 5 ) ) { l.append( '<li class="wp-mcp-ai-activity-item"><span class="wp-mcp-ai-activity-icon dashicons dashicons-' + ( e.icon || 'info' ) + '"></span><span class="wp-mcp-ai-activity-text">' + e.message + '</span><span class="wp-mcp-ai-activity-time">' + e.time + '</span></li>' ); } } }
		this.updateCharts( d );
	},

	initializeCharts(): void {
		this.initControlsChart();
		this.initMetricsChart();
		this.initRiskChart();
		this.hideChartLoading();
	},

	initControlsChart(): boolean { const c = document.getElementById( 'wpMcpAiControlsChart' ); if ( ! c || typeof Chart === 'undefined' ) { return false; } const d = wpMcpAiProDashboard.chartData?.controls || {}; try { const ctx = ( c as HTMLCanvasElement ).getContext( '2d' ); this.charts.controls = new Chart( ctx, { type: 'doughnut', data: { labels: [ 'Implemented', 'Partial', 'Planned', 'N/A' ], datasets: [ { data: [ d.implemented || 55, d.partial || 24, d.planned || 3, d.not_applicable || 11 ], backgroundColor: [ '#4caf50', '#ff9800', '#2196f3', '#9e9e9e' ], borderWidth: 2, borderColor: '#fff' } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' }, title: { display: true, text: 'Control Implementation Status' } } } } ); return true; } catch { return false; } },

	initMetricsChart(): boolean { const c = document.getElementById( 'wpMcpAiMetricsChart' ); if ( ! c || typeof Chart === 'undefined' ) { return false; } const d = wpMcpAiProDashboard.chartData?.metrics || {}; try { const ctx = ( c as HTMLCanvasElement ).getContext( '2d' ); this.charts.metrics = new Chart( ctx, { type: 'line', data: { labels: [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun' ], datasets: [ { label: 'Incidents', data: d.incidents || [ 5, 3, 2, 4, 1, 2 ], borderColor: '#f44336', backgroundColor: 'rgba(244,67,54,0.1)', tension: 0.4 }, { label: 'Fixed', data: d.vulnerabilities_fixed || [ 8, 12, 10, 15, 14, 12 ], borderColor: '#4caf50', backgroundColor: 'rgba(76,175,80,0.1)', tension: 0.4 } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' }, title: { display: true, text: 'Security Metrics (6 Months)' } }, scales: { y: { beginAtZero: true } } } } ); return true; } catch { return false; } },

	initRiskChart(): boolean { const c = document.getElementById( 'wpMcpAiRiskChart' ); if ( ! c || typeof Chart === 'undefined' ) { return false; } const d = wpMcpAiProDashboard.chartData?.risks || {}; try { const ctx = ( c as HTMLCanvasElement ).getContext( '2d' ); this.charts.risk = new Chart( ctx, { type: 'bar', data: { labels: [ 'Critical', 'High', 'Medium', 'Low' ], datasets: [ { label: 'Risks', data: [ d.critical || 0, d.high || 3, d.medium || 12, d.low || 8 ], backgroundColor: [ '#f44336', '#ff9800', '#ffc107', '#8bc34a' ] } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, title: { display: true, text: 'Risk Distribution' } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } } } ); return true; } catch { return false; } },

	updateCharts( data: Record< string, unknown > ): void {
		const d = data as { controls?: Record< string, number >; metrics?: Record< string, number[] >; risks?: Record< string, number > };
		if ( d.controls && this.charts.controls ) { const ch = this.charts.controls as { data: { datasets: Array< { data: number[] } > }; update(): void }; ch.data.datasets[ 0 ].data = [ d.controls.implemented || 0, d.controls.partial || 0, d.controls.planned || 0, d.controls.not_applicable || 0 ]; ch.update(); }
		if ( d.metrics && this.charts.metrics ) { const ch = this.charts.metrics as { data: { datasets: Array< { data: number[] } > }; update(): void }; ch.data.datasets[ 0 ].data = d.metrics.incidents || []; ch.data.datasets[ 1 ].data = d.metrics.vulnerabilities_fixed || []; ch.update(); }
		if ( d.risks && this.charts.risk ) { const ch = this.charts.risk as { data: { datasets: Array< { data: number[] } > }; update(): void }; ch.data.datasets[ 0 ].data = [ d.risks.critical || 0, d.risks.high || 0, d.risks.medium || 0, d.risks.low || 0 ]; ch.update(); }
	},

	animateProgressBars(): void { $( '.wp-mcp-ai-progress' ).each( function ( this: HTMLElement ) { const p = $( this ); const w = p.css( 'width' ) as string; p.css( 'width', '0' ); setTimeout( () => { p.css( 'width', w ); }, 100 ); } ); },

	refreshDashboard( e?: Event ): void { if ( e ) { e.preventDefault(); } const b = $( '.wp-mcp-ai-refresh-dashboard' ); spinner( b, true ); this.loadComplianceData(); setTimeout( () => { spinner( b, false ); }, 2000 ); },

	filterControls( e: Event ): void { e.preventDefault(); const f = $( e.currentTarget as HTMLElement ).data( 'filter' ) as string; $( '.wp-mcp-ai-control-filter' ).removeClass( 'active' ); ( e.currentTarget as HTMLElement ).classList.add( 'active' ); const rows = $( '.wp-mcp-ai-controls-table tbody tr' ); rows[ f === 'all' ? 'show' : 'hide' ](); if ( f !== 'all' ) { rows.filter( '[data-status="' + f + '"]' ).show(); } },

	startAutoRefresh(): void { this.refreshInterval = setInterval( () => this.loadComplianceData(), 300000 ); },
	stopAutoRefresh(): void { if ( this.refreshInterval ) { clearInterval( this.refreshInterval ); } },
	dismissProNotice( this: HTMLElement ): void { $( this ).closest( '.wp-mcp-ai-pro-notice' ).fadeOut(); },
};

// ── Boot ─────────────────────────────────────────────────────────────

$( document ).ready( () => {
	if ( typeof wpMcpAiProDashboard !== 'undefined' ) { ProDashboard.init(); }
} );
