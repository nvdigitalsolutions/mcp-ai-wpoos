/**
 * Create Assistant Modal — TypeScript edition.
 *
 * Handles the admin modal for creating assistants via manual form or
 * AI-powered prompt tab with chat interface.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

interface JQuery {
	length: number;
	[ index: number ]: HTMLElement;
	is( sel: string ): boolean;
	val(): string | string[] | undefined;
	val( v: string ): this;
	text(): string;
	text( t: string ): this;
	prop( n: string ): boolean;
	prop( n: string, v: unknown ): this;
	attr( n: string ): string | undefined;
	data( key: string ): unknown;
	data( key: string, value: unknown ): this;
	addClass( c: string ): this;
	removeClass( c: string ): this;
	hasClass( c: string ): boolean;
	fadeIn( d: number ): this;
	fadeOut( d: number ): this;
	show(): this;
	hide(): this;
	find( s: string ): JQuery;
	closest( s: string ): JQuery;
	prepend( c: string | JQuery ): this;
	append( c: string | JQuery ): this;
	html(): string;
	html( h: string ): this;
	remove(): void;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	each( cb: ( this: HTMLElement, i: number, el: HTMLElement ) => void ): void;
	scrollTop( n: number ): this;
	ready( h: () => void ): void;
	replace( s: string ): string;
}

interface JQueryXHR {
	done( cb: ( ...args: unknown[] ) => void ): this;
	fail( cb: ( ...args: unknown[] ) => void ): this;
}

interface JQueryStatic {
	( sel: string | HTMLElement | Document | ( () => void ) ): JQuery;
	ajax( s: Record< string, unknown > ): JQueryXHR;
}

declare const jQuery: JQueryStatic;
const $ = jQuery;

// ── Types ────────────────────────────────────────────────────────────

interface CreateAssistantGlobal {
	ajaxUrl: string;
	nonce: string;
	strings: {
		required?: string;
		maxProfessions?: string;
		maxRegions?: string;
		creating?: string;
		success?: string;
		error?: string;
	};
}

interface AjaxResponse {
	success: boolean;
	data?: {
		message?: string;
		assistant_id?: number;
		edit_link?: string;
		status?: string;
		attachment_id?: number;
	};
}

interface ChatConfig {
	strings?: {
		building?: string;
	};
}

function getG(): CreateAssistantGlobal | undefined {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiCreateAssistant as CreateAssistantGlobal | undefined;
}
function getChat(): ChatConfig | undefined {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiChat as ChatConfig | undefined;
}

// ── Helpers ──────────────────────────────────────────────────────────

function showModalMessage( type: string, message: string ): void {
	$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();
	const className = type === 'success' ? 'wp-mcp-ai-success-message' : 'wp-mcp-ai-error-message';
	$( '.wp-mcp-ai-modal-body' ).prepend( '<div class="' + className + '">' + message + '</div>' );
	$( '.wp-mcp-ai-modal-body' ).scrollTop( 0 );
}

function collectConversationData( $chat: JQuery ): { messages: Array< { role: string; content: string } >; assistantId: string | null } {
	const messages: Array< { role: string; content: string } > = [];
	$chat.find( '.wp-mcp-ai-chat__message' ).each( function ( this: HTMLElement ) {
		const $msg = $( this );
		let role = 'user';
		if ( $msg.hasClass( 'wp-mcp-ai-chat__message--assistant' ) ) { role = 'assistant'; }
		else if ( $msg.hasClass( 'wp-mcp-ai-chat__message--system' ) ) { role = 'system'; }
		const content = ( $msg.find( '.wp-mcp-ai-chat__message-content' ).text() || '' ).trim();
		if ( content ) { messages.push( { role, content } ); }
	} );
	const inputVal = ( $chat.find( '.wp-mcp-ai-chat__input' ).val() as string || '' ).trim();
	if ( inputVal ) { messages.push( { role: 'user', content: inputVal } ); }
	return { messages, assistantId: $chat.attr( 'id' )?.replace( 'wp-mcp-ai-chat-', '' ) || null };
}

function collectAttachmentIds( $chat: JQuery ): number[] {
	const ids: number[] = [];
	$chat.find( '.wp-mcp-ai-chat__attachments-list [data-attachment-id]' ).each( function ( this: HTMLElement ) {
		const id = $( this ).data( 'attachment-id' ) as number | undefined;
		if ( id ) { ids.push( id ); }
	} );
	return ids;
}

function uploadAttachment( file: File, $list: JQuery ): void {
	const g = getG();
	if ( ! g ) { return; }
	const formData = new FormData();
	formData.append( 'file', file );
	formData.append( 'action', 'wp_mcp_ai_upload_assistant_attachment' );
	formData.append( 'nonce', g.nonce );
	const $item = $( '<li class="wp-mcp-ai-attachment-item uploading"><span class="name">' + file.name + '</span><span class="status">Uploading...</span></li>' );
	$list.append( $item );
	$.ajax( {
		url: g.ajaxUrl, type: 'POST', data: formData, processData: false, contentType: false,
		success( r: AjaxResponse ) {
			if ( r.success ) { $item.removeClass( 'uploading' ).data( 'attachment-id', r.data?.attachment_id ).find( '.status' ).html( '<button type="button" class="remove-attachment">&times;</button>' ); }
			else { $item.addClass( 'error' ).find( '.status' ).text( 'Failed' ); setTimeout( () => $item.remove(), 2000 ); }
		},
		error() { $item.addClass( 'error' ).find( '.status' ).text( 'Failed' ); setTimeout( () => $item.remove(), 2000 ); },
	} as Record< string, unknown > );
}

// ── Init ─────────────────────────────────────────────────────────────

export function initCreateAssistantModal(): void {
	$( document ).ready( () => {
		const g = getG();
		if ( ! g ) { return; }
		const modal = $( '#wp-mcp-ai-create-assistant-modal' );
		const form = $( '#wp-mcp-ai-create-assistant-form' );
		let activeTab = 'manual';

		$( document ).on( 'click', '#wp-mcp-ai-open-create-modal', ( e ) => { e.preventDefault(); modal.fadeIn( 200 ); } );
		$( document ).on( 'click', '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay', () => modal.fadeOut( 200 ) );

		modal.on( 'click', '.wp-mcp-ai-modal-tabs .nav-tab', function ( this: HTMLElement, e: Event ) {
			e.preventDefault(); e.stopPropagation();
			const tabId = $( this ).data( 'tab' ) as string;
			if ( tabId === activeTab ) { return; }
			modal.find( '.wp-mcp-ai-modal-tabs .nav-tab' ).removeClass( 'nav-tab-active' );
			$( this ).addClass( 'nav-tab-active' );
			modal.find( '.wp-mcp-ai-modal-tab-content' ).removeClass( 'active' );
			modal.find( '#wp-mcp-ai-tab-' + tabId ).addClass( 'active' );
			activeTab = tabId;
			if ( tabId === 'prompt' ) { $( '#wp-mcp-ai-submit-create' ).hide(); }
			else { $( '#wp-mcp-ai-submit-create' ).show(); }
		} );

		modal.find( '.wp-mcp-ai-modal-content' ).on( 'click', ( e ) => e.stopPropagation() );
		$( document ).on( 'keydown', ( e ) => { if ( ( e as KeyboardEvent ).key === 'Escape' && modal.is( ':visible' ) ) { modal.fadeOut( 200 ); } } );

		// Build button
		$( document ).on( 'click', '.wp-mcp-ai-modal-chat-container .wp-mcp-ai-chat__build', function ( this: HTMLElement, e: Event ) {
			e.preventDefault();
			const $button = $( this );
			if ( $button.prop( 'disabled' ) ) { return; }
			$button.prop( 'disabled', true );
			const originalText = $button.text();
			const chat = getChat();
			$button.text( chat?.strings?.building || 'Building...' );
			const $chat = $button.closest( '.wp-mcp-ai-chat' );
			const conv = collectConversationData( $chat );
			if ( ! conv.messages.length ) {
				alert( 'Please describe what kind of assistant you want to create before clicking Build.' );
				$button.prop( 'disabled', false ).text( originalText ); return;
			}
			const ids = collectAttachmentIds( $chat );
			$.ajax( {
				url: g.ajaxUrl, type: 'POST', data: { action: 'wp_mcp_ai_build_assistant_from_conversation', nonce: g.nonce, conversation: JSON.stringify( conv ), attachment_ids: ids },
				success( r: AjaxResponse ) {
					$button.prop( 'disabled', false ).text( originalText );
					if ( r.success ) {
						showModalMessage( 'success', r.data?.message || 'Assistant created successfully!' );
						if ( r.data?.assistant_id && r.data?.edit_link ) { setTimeout( () => { window.location.href = r.data!.edit_link!; }, 1500 ); }
						else if ( r.data?.status === 'scheduled' ) { setTimeout( () => { modal.fadeOut( 200 ); location.reload(); }, 2000 ); }
					} else { showModalMessage( 'error', r.data?.message || 'Failed to create assistant.' ); }
				},
				error( _x: unknown, _s: string, error: string ) { $button.prop( 'disabled', false ).text( originalText ); showModalMessage( 'error', 'An error occurred: ' + error ); },
			} as Record< string, unknown > );
		} );

		// Manual form submit
		form.on( 'submit', ( e ) => {
			e.preventDefault();
			if ( activeTab !== 'manual' ) { return; }
			$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();
			const professions = $( '#assistant-professions' ).val() as string[];
			if ( ! professions?.length ) { showModalMessage( 'error', g.strings.required || 'Required' ); return; }
			if ( professions.length > 3 ) { showModalMessage( 'error', g.strings.maxProfessions || 'Max 3' ); return; }
			const regions = $( '#assistant-regions' ).val() as string[];
			if ( ! regions?.length ) { showModalMessage( 'error', g.strings.required || 'Required' ); return; }
			if ( regions.length > 2 ) { showModalMessage( 'error', g.strings.maxRegions || 'Max 2' ); return; }
			modal.addClass( 'loading' );
			$( '#wp-mcp-ai-submit-create' ).prop( 'disabled', true ).text( g.strings.creating || 'Creating...' );
			const attachmentIds: number[] = [];
			$( '#assistant-attachments-list .wp-mcp-ai-attachment-item' ).each( function ( this: HTMLElement ) {
				const id = $( this ).data( 'attachment-id' ) as number | undefined;
				if ( id ) { attachmentIds.push( id ); }
			} );
			$.ajax( {
				url: g.ajaxUrl, type: 'POST', data: {
					action: 'wp_mcp_ai_create_assistant_from_modal', nonce: g.nonce,
					title: $( '#assistant-title' ).val(), professions, regions,
					industry_focus: $( '#assistant-industry' ).val(), provider: $( '#assistant-provider' ).val(),
					model: $( '#assistant-model' ).val(), temperature: $( '#assistant-temperature' ).val(),
					async: $( '#assistant-async' ).is( ':checked' ) ? '1' : '0', attachment_ids: attachmentIds,
				},
				success( r: AjaxResponse ) {
					modal.removeClass( 'loading' );
					$( '#wp-mcp-ai-submit-create' ).prop( 'disabled', false ).text( 'Create Assistant' );
					if ( r.success ) {
						showModalMessage( 'success', r.data?.message || g.strings.success || 'Success' );
						if ( r.data?.assistant_id ) { setTimeout( () => { window.location.href = r.data!.edit_link || 'post.php?post=' + r.data!.assistant_id + '&action=edit'; }, 1000 ); }
						else if ( r.data?.status === 'scheduled' ) { setTimeout( () => { modal.fadeOut( 200 ); ( form[ 0 ] as HTMLFormElement ).reset(); location.reload(); }, 2000 ); }
					} else { showModalMessage( 'error', r.data?.message || g.strings.error || 'Error' ); }
				},
				error( _x: unknown, _s: string, error: string ) { modal.removeClass( 'loading' ); $( '#wp-mcp-ai-submit-create' ).prop( 'disabled', false ).text( 'Create Assistant' ); showModalMessage( 'error', ( g.strings.error || 'Error' ) + ' (' + error + ')' ); },
			} as Record< string, unknown > );
		} );

		// File attachments
		$( '#assistant-attachments' ).on( 'change', function ( this: HTMLElement ) {
			const files = ( this as HTMLInputElement ).files;
			if ( ! files?.length ) { return; }
			const $list = $( '#assistant-attachments-list' );
			const allowedTypes = [ 'text/plain', 'text/markdown', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ];
			for ( let i = 0; i < files.length; i++ ) {
				const file = files[ i ];
				if ( ! allowedTypes.some( t => file.type === t || !! file.name.match( /\.(txt|md|pdf|doc|docx)$/i ) ) ) { showModalMessage( 'error', 'File "' + file.name + '" is not a supported type.' ); continue; }
				uploadAttachment( file, $list );
			}
			$( this ).val( '' );
		} );

		$( document ).on( 'click', '.remove-attachment', function ( this: HTMLElement ) { $( this ).closest( '.wp-mcp-ai-attachment-item' ).remove(); } );
	} );
}

// Auto-init
$( document ).ready( () => { if ( getG() ) { initCreateAssistantModal(); } } );
