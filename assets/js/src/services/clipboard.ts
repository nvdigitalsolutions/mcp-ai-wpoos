/**
 * Clipboard Service for NV oOS Chat — TypeScript edition.
 *
 * Handles copy-to-clipboard functionality with fallback support for older
 * browsers.  Self-contained; no imports from other NV oOS modules.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Constants ────────────────────────────────────────────────────────

const COPY_BUTTON_CLASS = 'wp-mcp-ai-copy-button';
const COPY_ENABLED_CLASS = 'wp-mcp-ai-copy-enabled';
const COPY_ERROR_CLASS = 'wp-mcp-ai-copy-button--error';
const COPY_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 5a2 2 0 012-2h7a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2zm2-1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1V5a1 1 0 00-1-1z"></path><path d="M4 7a2 2 0 012-2v1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1h1a2 2 0 01-2 2H6a2 2 0 01-2-2z"></path></svg>';
const COPY_SUCCESS_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.293 12.293l-2.147-2.146 1.414-1.414L9 10.586l3.44-3.44 1.414 1.415L9 13.414z"></path><path d="M6 3a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2zm0 1h8a1 1 0 011 1v10a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"></path></svg>';

type CopyState = 'idle' | 'copied' | 'error';

interface DomBatcher {
	schedule( fn: () => void ): void;
}

// ── DOM batcher fallback ─────────────────────────────────────────────

function getDomBatcher(): DomBatcher {
	const batcher = ( window as unknown as Record< string, unknown > ).wpMcpAiChatDomBatcher as DomBatcher | undefined;
	if ( batcher?.schedule ) {
		return batcher;
	}
	return {
		schedule( fn: () => void ) {
			if ( typeof fn === 'function' ) { fn(); }
		},
	};
}

const domBatcher = getDomBatcher();

// ── Exports ──────────────────────────────────────────────────────────

export function updateCopyButtonState( button: HTMLElement | null, stateName: CopyState ): void {
	if ( ! button ) { return; }

	button.classList.remove( COPY_ERROR_CLASS );
	button.dataset.state = stateName;

	if ( stateName === 'copied' ) {
		button.innerHTML = COPY_SUCCESS_ICON;
		button.setAttribute( 'aria-label', 'Copied response' );
		button.setAttribute( 'title', 'Copied response' );
	} else if ( stateName === 'error' ) {
		button.innerHTML = COPY_ICON;
		button.setAttribute( 'aria-label', 'Unable to copy' );
		button.setAttribute( 'title', 'Unable to copy' );
		button.classList.add( COPY_ERROR_CLASS );
	} else {
		button.innerHTML = COPY_ICON;
		button.setAttribute( 'aria-label', 'Copy response' );
		button.setAttribute( 'title', 'Copy response' );
	}
}

export function copyTextToClipboard( text: string ): Promise< boolean > {
	if ( ! text ) { return Promise.resolve( false ); }

	if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
		return navigator.clipboard.writeText( text )
			.then( () => true )
			.catch( () => fallbackCopyText( text ) );
	}

	return fallbackCopyText( text );
}

function fallbackCopyText( text: string ): Promise< boolean > {
	return new Promise( ( resolve ) => {
		const textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'absolute';
		textarea.style.left = '-9999px';
		document.body.appendChild( textarea );

		const selectionCount = document.getSelection()?.rangeCount ?? 0;
		textarea.select();
		textarea.setSelectionRange( 0, textarea.value.length );

		let succeeded = false;
		try {
			succeeded = document.execCommand( 'copy' );
		} catch {
			/* fallback failed */
		}

		document.body.removeChild( textarea );

		if ( selectionCount && document.getSelection ) {
			try { document.getSelection()?.removeAllRanges(); } catch { /* ignore */ }
		}

		resolve( succeeded );
	} );
}

function resolveCopyText( bubble: HTMLElement | null, text?: string ): string {
	if ( text && typeof text === 'string' ) { return text.trim(); }

	if ( bubble?.dataset?.copyText ) {
		const stored = bubble.dataset.copyText.trim();
		if ( stored ) { return stored; }
	}

	if ( ! bubble ) { return ''; }

	const textContent = bubble.textContent ?? bubble.innerText ?? '';
	return textContent.trim();
}

export function attachCopyButton( bubble: HTMLElement | null, text?: string ): void {
	if ( ! bubble ) { return; }

	const textToCopy = resolveCopyText( bubble, text );
	if ( ! textToCopy ) { return; }

	bubble.classList.add( COPY_ENABLED_CLASS );
	bubble.dataset.copyText = textToCopy;

	const existing = bubble.querySelector( '.' + COPY_BUTTON_CLASS ) as HTMLButtonElement | null;
	if ( existing ) {
		existing.dataset.copyText = textToCopy;
		existing.disabled = false;
		updateCopyButtonState( existing, 'idle' );
		return;
	}

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = COPY_BUTTON_CLASS;
	button.dataset.copyText = textToCopy;
	updateCopyButtonState( button, 'idle' );

	button.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		event.stopPropagation();

		const currentText = resolveCopyText( bubble, button.dataset.copyText || text );
		if ( ! currentText ) {
			updateCopyButtonState( button, 'error' );
			setTimeout( () => {
				domBatcher.schedule( () => updateCopyButtonState( button, 'idle' ) );
			}, 2000 );
			return;
		}

		button.disabled = true;

		copyTextToClipboard( currentText )
			.then( ( success ) => {
				updateCopyButtonState( button, success ? 'copied' : 'error' );
				setTimeout( () => {
					domBatcher.schedule( () => {
						updateCopyButtonState( button, 'idle' );
						button.disabled = false;
					} );
				}, 2000 );
			} )
			.catch( () => {
				updateCopyButtonState( button, 'error' );
				setTimeout( () => {
					domBatcher.schedule( () => {
						updateCopyButtonState( button, 'idle' );
						button.disabled = false;
					} );
				}, 2000 );
			} );
	} );

	bubble.appendChild( button );
}

// ── Backward-compatible global ───────────────────────────────────────

const _g = window as unknown as Record< string, unknown >;
_g.wpMcpAiChatClipboard = {
	copyTextToClipboard,
	attachCopyButton,
	updateCopyButtonState,
	COPY_BUTTON_CLASS,
	COPY_ENABLED_CLASS,
};
