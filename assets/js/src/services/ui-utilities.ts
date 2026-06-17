/**
 * UI Utilities Service for NV oOS Chat — TypeScript edition.
 *
 * Dom batching, scroll batching, formatting, status management, button
 * helpers, cross-chat communication, and file validation utilities.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Internal types ───────────────────────────────────────────────────

interface DomBatcher {
	schedule( updateFn: () => void ): void;
}

interface ScrollBatcher {
	scrollToBottom( element: Element | null ): void;
}

// ── Module-level state ───────────────────────────────────────────────

const _win = window as unknown as Record< string, unknown >;
const DEBUG_MODE = _win.wpMcpAiChatDebugMode === true;
const OPTIMIZATIONS_ENABLED = ! DEBUG_MODE;

// ── DOM update batcher ───────────────────────────────────────────────

function createDomBatcher(): DomBatcher {
	const pendingUpdates: Array< () => void > = [];
	let rafScheduled = false;

	function performUpdates(): void {
		rafScheduled = false;
		const updates = pendingUpdates.slice();
		pendingUpdates.length = 0;
		for ( const fn of updates ) {
			try { fn(); } catch { /* batched update failed */ }
		}
	}

	return {
		schedule( updateFn ) {
			if ( ! OPTIMIZATIONS_ENABLED || typeof updateFn !== 'function' ) {
				if ( typeof updateFn === 'function' ) { updateFn(); }
				return;
			}
			pendingUpdates.push( updateFn );
			if ( ! rafScheduled ) {
				rafScheduled = true;
				requestAnimationFrame( performUpdates );
			}
		},
	};
}

export const domUpdateBatcher = createDomBatcher();

// ── Scroll batcher ───────────────────────────────────────────────────

function createScrollBatcher(): ScrollBatcher {
	const pendingScrolls = new Map< Element, string >();
	let rafScheduled = false;

	function performScrolls(): void {
		rafScheduled = false;
		const ops = new Map< Element, number >();
		pendingScrolls.forEach( ( _, el ) => {
			if ( el?.parentNode ) { ops.set( el, el.scrollHeight ); }
		} );
		pendingScrolls.clear();
		ops.forEach( ( h, el ) => { el.scrollTop = h; } );
	}

	return {
		scrollToBottom( element ) {
			if ( ! element || ! OPTIMIZATIONS_ENABLED ) {
				if ( element ) { element.scrollTop = element.scrollHeight; }
				return;
			}
			pendingScrolls.set( element, 'bottom' );
			if ( ! rafScheduled ) {
				rafScheduled = true;
				requestAnimationFrame( performScrolls );
			}
		},
	};
}

export const scrollBatcher = createScrollBatcher();

// ── Formatting ───────────────────────────────────────────────────────

export function escapeHtml( text: string ): string {
	return String( text ).replace( /[&<>"']/g, ( ch ) => {
		switch ( ch ) {
			case '&': return '&amp;';
			case '<': return '&lt;';
			case '>': return '&gt;';
			case '"': return '&quot;';
			case '\'': return '&#39;';
			default: return ch;
		}
	} );
}

export function formatBytes( bytes: number ): string {
	if ( bytes === 0 ) { return '0 Bytes'; }
	const k = 1024;
	const sizes = [ 'Bytes', 'KB', 'MB', 'GB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
}

export function formatDuration( value: number ): string {
	const seconds = Number( value );
	if ( ! isFinite( seconds ) || seconds < 0 ) { return ''; }
	const totalSeconds = Math.round( seconds );
	const hours = Math.floor( totalSeconds / 3600 );
	const minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
	const secs = totalSeconds % 60;
	const parts: string[] = [];
	if ( hours ) { parts.push( String( hours ) ); }
	parts.push( hours ? String( minutes ).padStart( 2, '0' ) : String( minutes ) );
	parts.push( String( secs ).padStart( 2, '0' ) );
	return parts.join( ':' );
}

export function formatElapsedTime( seconds: number ): string {
	if ( seconds < 60 ) { return seconds + 's'; }
	const minutes = Math.floor( seconds / 60 );
	const rem = seconds % 60;
	if ( rem === 0 ) { return minutes + 'm'; }
	return minutes + 'm ' + rem + 's';
}

// ── Status management ────────────────────────────────────────────────

interface StatusOptions {
	type?: string;
	message?: string;
	showTime?: boolean;
	startTime?: number;
}

export function setStatus(
	container: Element | null,
	message: string | StatusOptions,
	options: StatusOptions = {},
): void {
	const statusEl = container?.querySelector( '.wp-mcp-ai-chat__status' ) as HTMLElement | null;
	if ( ! statusEl ) { return; }

	let messageText = '';
	let opts = options;

	if ( typeof message === 'object' && message !== null ) {
		opts = message;
		messageText = opts.message || '';
	} else {
		messageText = message || '';
	}

	if ( ! messageText ) {
		statusEl.innerHTML = '';
		statusEl.hidden = true;
		statusEl.className = 'wp-mcp-ai-chat__status';
		if ( ( statusEl as unknown as Record< string, unknown > )._timeInterval ) {
			clearInterval( ( statusEl as unknown as Record< string, unknown > )._timeInterval as number );
			( statusEl as unknown as Record< string, unknown > )._timeInterval = null;
		}
		return;
	}

	const prevInterval = ( statusEl as unknown as Record< string, unknown > )._timeInterval;
	if ( prevInterval ) { clearInterval( prevInterval as number ); }
	( statusEl as unknown as Record< string, unknown > )._timeInterval = null;

	const type = opts.type || 'default';
	const showTime = opts.showTime !== false;
	const startTime = opts.startTime || Date.now();

	let indicatorHTML = '';
	let statusClass = 'wp-mcp-ai-chat__status';

	if ( type === 'thinking' || type === 'processing' ) {
		statusClass += type === 'thinking' ? ' wp-mcp-ai-chat__status--thinking' : ' wp-mcp-ai-chat__status--processing';
		indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator"><span class="wp-mcp-ai-chat__status-spinner"></span></span>';
	} else if ( type === 'streaming' ) {
		statusClass += ' wp-mcp-ai-chat__status--streaming';
		indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
			'<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20"><path d="M2 10a8 8 0 0116 0H2zm8-8a8 8 0 010 16V2z" opacity="0.3"/><path d="M10 2a8 8 0 018 8h-2a6 6 0 00-6-6V2z"><animateTransform attributeName="transform" type="rotate" from="0 10 10" to="360 10 10" dur="1s" repeatCount="indefinite"/></path></svg></span>';
	} else if ( type === 'text-stream' ) {
		statusClass += ' wp-mcp-ai-chat__status--text-stream';
		indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator"><svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1z"/></svg></span>';
	} else if ( type === 'tool' ) {
		statusClass += ' wp-mcp-ai-chat__status--tool';
		indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator"><span class="wp-mcp-ai-chat__status-spinner"></span></span>';
	} else if ( type === 'success' ) {
		statusClass += ' wp-mcp-ai-chat__status--success';
		indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator"><svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20"><path fill="currentColor" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg></span>';
	}

	let timeHTML = '';
	if ( showTime && ( type === 'thinking' || type === 'processing' || type === 'tool' ) ) {
		timeHTML = '<span class="wp-mcp-ai-chat__status-time" data-start-time="' + startTime + '">0s</span>';
	}

	const escapedMessage = escapeHtml( messageText );
	statusEl.className = statusClass;
	statusEl.innerHTML = indicatorHTML + '<span class="wp-mcp-ai-chat__status-text">' + escapedMessage + '</span>' + timeHTML;
	statusEl.hidden = false;

	if ( timeHTML ) {
		const timeEl = statusEl.querySelector( '.wp-mcp-ai-chat__status-time' );
		if ( timeEl ) {
			const interval = setInterval( () => {
				const elapsed = Math.floor( ( Date.now() - startTime ) / 1000 );
				domUpdateBatcher.schedule( () => {
					if ( timeEl?.parentNode ) {
						timeEl.textContent = formatElapsedTime( elapsed );
					} else {
						clearInterval( interval );
						( statusEl as unknown as Record< string, unknown > )._timeInterval = null;
					}
				} );
			}, 1000 );
			( statusEl as unknown as Record< string, unknown > )._timeInterval = interval;
		}
	}
}

export function clearStatus( container: Element | null ): void {
	setStatus( container, '' );
}

// ── Cross-chat communication ─────────────────────────────────────────

interface JobBus {
	emit( event: string, data: unknown ): void;
	on( event: string, handler: ( data: unknown ) => void ): void;
	off( event: string, handler: ( data: unknown ) => void ): void;
}

function getJobBus(): JobBus | undefined {
	return _win.wpMcpAiJobBus as JobBus | undefined;
}

export function broadcastMessage( eventType: string, data: unknown ): void {
	const bus = getJobBus();
	if ( bus && typeof eventType === 'string' ) {
		bus.emit( 'chat:' + eventType, data );
	}
}

export function listenToChatEvents( eventType: string, handler: ( data: unknown ) => void ): () => void {
	const bus = getJobBus();
	if ( ! bus || typeof eventType !== 'string' || typeof handler !== 'function' ) {
		return () => {};
	}
	const fullEventType = 'chat:' + eventType;
	bus.on( fullEventType, handler );
	return () => { bus.off( fullEventType, handler ); };
}

export function getOtherChatInstances( currentInstanceId: string ): Array< {
	id: string;
	config: unknown;
	state: unknown;
	container: HTMLElement;
} > {
	const instances = _win.wpMcpAiChatInstances as Record< string, unknown > | undefined;
	if ( ! instances ) { return []; }

	const result: ReturnType< typeof getOtherChatInstances > = [];
	for ( const id of Object.keys( instances ) ) {
		if ( id === currentInstanceId ) { continue; }
		const container = document.getElementById( id ) as HTMLElement & { __wpMcpAiChatState?: unknown };
		if ( container?.__wpMcpAiChatState ) {
			result.push( { id, config: instances[ id ], state: container.__wpMcpAiChatState, container } );
		}
	}
	return result;
}

// ── Button helpers ───────────────────────────────────────────────────

export function toggleButtonClass( button: HTMLElement | null, className: string, force?: boolean ): void {
	if ( ! button?.classList || ! className ) { return; }
	if ( typeof force === 'boolean' ) {
		force ? button.classList.add( className ) : button.classList.remove( className );
	} else {
		button.classList.toggle( className );
	}
}

interface ButtonStateOpts {
	disabled?: boolean;
	hidden?: boolean;
	addClass?: string;
	removeClass?: string;
}

export function setButtonState( button: HTMLElement | null, options: ButtonStateOpts = {} ): void {
	if ( ! button ) { return; }
	if ( typeof options.disabled === 'boolean' ) { ( button as HTMLButtonElement ).disabled = options.disabled; }
	if ( typeof options.hidden === 'boolean' ) { button.hidden = options.hidden; }
	if ( options.addClass && button.classList ) { button.classList.add( options.addClass ); }
	if ( options.removeClass && button.classList ) { button.classList.remove( options.removeClass ); }
}

export function setButtonIcon( button: HTMLElement | null, iconHTML: string, selector?: string ): void {
	if ( ! button || typeof iconHTML !== 'string' ) { return; }

	const lowerHTML = iconHTML.toLowerCase();
	const dangerousPatterns = [ 'javascript:', 'data:text/html', 'vbscript:', '<script', 'onerror=', 'onload=', 'onclick=', 'onmouseover=' ];
	for ( const pattern of dangerousPatterns ) {
		if ( lowerHTML.includes( pattern ) ) { return; }
	}

	const iconElement = selector
		? ( button.querySelector( selector ) as HTMLElement | null )
		: ( button.firstElementChild as HTMLElement | null );

	if ( iconElement ) { iconElement.innerHTML = iconHTML; }
}

export function updateButtonLabel( button: HTMLElement | null, label: string ): void {
	if ( ! button || typeof label !== 'string' ) { return; }
	button.setAttribute( 'aria-label', label );
	button.setAttribute( 'title', label );
}

// ── File validation ──────────────────────────────────────────────────

interface ValidationConstraints {
	maxSize?: number;
	allowedTypes?: string[];
	allowedExtensions?: string[];
}

interface ValidationResult {
	valid: boolean;
	errors: string[];
	warnings: string[];
}

export function validateAttachment( file: File | null, constraints: ValidationConstraints = {} ): ValidationResult {
	const result: ValidationResult = { valid: true, errors: [], warnings: [] };
	if ( ! file ) { result.valid = false; result.errors.push( 'No file provided' ); return result; }

	if ( constraints.maxSize && file.size > constraints.maxSize ) {
		result.valid = false;
		result.errors.push( 'File size exceeds maximum allowed (' + formatBytes( constraints.maxSize ) + ')' );
	}

	if ( constraints.allowedTypes?.length ) {
		const fileType = file.type || '';
		const typeAllowed = constraints.allowedTypes.some( ( t ) => {
			return fileType === t || ( t.includes( '*' ) && fileType.startsWith( t.replace( '*', '' ) ) );
		} );
		if ( ! typeAllowed ) { result.valid = false; result.errors.push( 'File type not allowed: ' + fileType ); }
	}

	if ( constraints.allowedExtensions?.length ) {
		const ext = ( file.name || '' ).split( '.' ).pop()?.toLowerCase();
		if ( ext && ! constraints.allowedExtensions.includes( ext ) ) {
			result.valid = false; result.errors.push( 'File extension not allowed: .' + ext );
		}
	}

	if ( file.size > 10 * 1024 * 1024 ) { result.warnings.push( 'Large file may take time to process' ); }
	return result;
}

// ── Attachment library helpers ───────────────────────────────────────

export function addToAttachmentLibrary(
	lib: Record< string, unknown >,
	attachment: { fileId?: string } & Record< string, unknown >,
): string | null {
	if ( ! lib || ! attachment?.fileId ) { return null; }
	if ( lib[ attachment.fileId ] ) { return attachment.fileId; }
	lib[ attachment.fileId ] = attachment;
	return attachment.fileId;
}

export function getFromAttachmentLibrary( lib: Record< string, unknown >, fileId: string ): unknown {
	return ( lib && fileId ) ? ( lib[ fileId ] ?? null ) : null;
}

export function removeFromAttachmentLibrary( lib: Record< string, unknown >, fileId: string ): boolean {
	if ( ! lib || ! fileId || ! lib[ fileId ] ) { return false; }
	delete lib[ fileId ];
	return true;
}

// ── Recording timer ──────────────────────────────────────────────────

export function displayRecordingTimer( element: HTMLElement | null, startTime: number ): () => void {
	if ( ! element || typeof startTime !== 'number' ) { return () => {}; }

	const interval = setInterval( () => {
		if ( ! element.parentNode ) { clearInterval( interval ); return; }
		const elapsed = Date.now() - startTime;
		const totalSeconds = Math.floor( elapsed / 1000 );
		const minutes = Math.floor( totalSeconds / 60 );
		const seconds = totalSeconds % 60;
		element.textContent = minutes + ':' + String( seconds ).padStart( 2, '0' );
	}, 1000 );

	return () => { clearInterval( interval ); };
}

// ── Backward-compatible global ───────────────────────────────────────

_win.wpMcpAiChatUIUtils = {
	domUpdateBatcher, scrollBatcher,
	escapeHtml, formatBytes, formatDuration, formatElapsedTime,
	setStatus, clearStatus,
	toggleButtonClass, setButtonState, setButtonIcon, updateButtonLabel,
	broadcastMessage, listenToChatEvents, getOtherChatInstances,
	copyMessageToClipboard: ( msg: { content?: string } ) => {
		const text = msg?.content ? ( typeof msg.content === 'string' ? msg.content : JSON.stringify( msg.content ) ) : '';
		if ( navigator.clipboard?.writeText ) { return navigator.clipboard.writeText( text ); }
		return Promise.reject( new Error( 'Clipboard API unavailable' ) );
	},
	validateAttachment, addToAttachmentLibrary, getFromAttachmentLibrary, removeFromAttachmentLibrary,
	displayRecordingTimer,
};
