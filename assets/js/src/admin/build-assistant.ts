/**
 * Build Assistant Page — TypeScript edition.
 *
 * Handles the Build Assistant admin page: manual tab (form submission,
 * file uploads) and prompt tab (chat modal for AI-assisted building).
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── jQuery minimal interface ─────────────────────────────────────────

interface JQuery {
	length: number;
	[ index: number ]: HTMLElement;
	is( sel: string ): boolean;
	val(): string | string[] | undefined;
	val( v: string ): this;
	text( t: string ): this;
	html( h: string ): this;
	data( key: string ): unknown;
	data( key: string, value: unknown ): this;
	prop( n: string ): boolean;
	prop( n: string, v: unknown ): this;
	addClass( c: string ): this;
	removeClass( c: string ): this;
	attr( n: string ): string | undefined;
	append( c: string | JQuery ): this;
	prepend( c: string | JQuery ): this;
	replaceWith( c: string | JQuery ): this;
	closest( s: string ): JQuery;
	find( s: string ): JQuery;
	filter( s: string ): JQuery;
	parent(): JQuery;
	children(): JQuery;
	empty(): this;
	remove(): void;
	show(): this;
	hide(): this;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	ready( h: () => void ): void;
	first(): JQuery;
	each( cb: ( this: HTMLElement, i: number, el: HTMLElement ) => void ): void;
	animate( props: Record< string, number >, duration: number ): this;
	offset(): { top: number; left: number };
}

interface JQueryXHR {
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	done( cb: ( ...args: any[] ) => void ): this;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	fail( cb: ( ...args: any[] ) => void ): this;
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
		createAssistant?: string;
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
	restUrl: string;
	filesEndpoint?: string;
	transcriptsEndpoint?: string;
	uploadEndpoint?: string;
	nonce?: string;
	currentUserId?: number;
	fileAccept?: string;
	allowedImageMimes?: string[];
	allowedFileMimes?: string[];
	allowedExtensions?: string[];
	strings?: {
		placeholder?: string;
		send?: string;
		attachFile?: string;
		transcribeAudio?: string;
	};
}

function getGlobal(): CreateAssistantGlobal | undefined {
	return ( window as unknown as Record< string, unknown > )
		.wpMcpAiCreateAssistant as CreateAssistantGlobal | undefined;
}

function getChatConfig(): ChatConfig {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiChat as ChatConfig || { restUrl: '/wp-json/mcp-ai/v1' };
}

function escapeHtml( text: string ): string {
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

// ── Build Assistant Page Controller ──────────────────────────────────

export const BuildAssistantPage = {
	init(): void {
		this.initManualTab();
		this.initPromptTab();
	},

	initManualTab(): void {
		const self = this;
		const form = $( '#wp-mcp-ai-create-assistant-form' );
		if ( ! form.length ) { return; }

		form.on( 'submit', ( e ) => {
			e.preventDefault();
			self.handleManualFormSubmit( form );
		} );

		$( '#assistant-attachments' ).on( 'change', function ( this: HTMLElement ) {
			self.handleFileAttachments( ( this as HTMLInputElement ).files );
		} );

		$( document ).on( 'click', '.remove-attachment', function ( this: HTMLElement ) {
			$( this ).closest( '.wp-mcp-ai-attachment-item' ).remove();
		} );
	},

	handleManualFormSubmit( form: JQuery ): void {
		const self = this;
		const g = getGlobal();
		if ( ! g ) { return; }

		$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();

		const professions = $( '#assistant-professions' ).val() as string[];
		if ( ! professions?.length ) {
			self.showMessage( 'error', g.strings.required || 'Required' );
			return;
		}
		if ( professions.length > 3 ) {
			self.showMessage( 'error', g.strings.maxProfessions || 'Max 3 professions' );
			return;
		}

		const regions = $( '#assistant-regions' ).val() as string[];
		if ( ! regions?.length ) {
			self.showMessage( 'error', g.strings.required || 'Required' );
			return;
		}
		if ( regions.length > 2 ) {
			self.showMessage( 'error', g.strings.maxRegions || 'Max 2 regions' );
			return;
		}

		const submitButton = $( '#wp-mcp-ai-submit-create' );
		submitButton.prop( 'disabled', true ).text( g.strings.creating || 'Creating...' );

		const attachmentIds: number[] = [];
		$( '#assistant-attachments-list .wp-mcp-ai-attachment-item' ).each( function ( this: HTMLElement ) {
			const id = $( this ).data( 'attachment-id' ) as number | undefined;
			if ( id ) { attachmentIds.push( id ); }
		} );

		const formData = {
			action: 'wp_mcp_ai_create_assistant_from_modal',
			nonce: g.nonce,
			title: $( '#assistant-title' ).val(),
			professions,
			regions,
			industry_focus: $( '#assistant-industry' ).val(),
			provider: $( '#assistant-provider' ).val(),
			model: $( '#assistant-model' ).val(),
			temperature: $( '#assistant-temperature' ).val(),
			async: $( '#assistant-async' ).is( ':checked' ) ? '1' : '0',
			attachment_ids: attachmentIds,
		};

		$.ajax( {
			url: g.ajaxUrl,
			type: 'POST',
			data: formData,
			success( response: AjaxResponse ) {
				submitButton.prop( 'disabled', false ).text( g.strings.createAssistant || 'Create Assistant' );
				if ( response.success ) {
					self.showMessage( 'success', response.data?.message || g.strings.success || 'Success' );
					if ( response.data?.assistant_id ) {
						setTimeout( () => {
							window.location.href = response.data!.edit_link || 'post.php?post=' + response.data!.assistant_id + '&action=edit';
						}, 1000 );
					} else if ( response.data?.status === 'scheduled' ) {
						setTimeout( () => {
							( form[ 0 ] as HTMLFormElement ).reset();
							location.reload();
						}, 2000 );
					}
				} else {
					self.showMessage( 'error', response.data?.message || g.strings.error || 'Error' );
				}
			},
			error( _xhr: unknown, _status: string, error: string ) {
				submitButton.prop( 'disabled', false ).text( g.strings.createAssistant || 'Create Assistant' );
				self.showMessage( 'error', ( g.strings.error || 'Error' ) + ' (' + error + ')' );
			},
		} as Record< string, unknown > );
	},

	handleFileAttachments( files: FileList | null ): void {
		const self = this;
		const $list = $( '#assistant-attachments-list' );
		const allowedTypes = [
			'text/plain', 'text/markdown', 'application/pdf',
			'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		];

		if ( ! files ) { return; }
		for ( let i = 0; i < files.length; i++ ) {
			const file = files[ i ];
			const isValid = allowedTypes.some( ( t ) => file.type === t || !! file.name.match( /\.(txt|md|pdf|doc|docx)$/i ) );
			if ( ! isValid ) {
				self.showMessage( 'error', 'File "' + file.name + '" is not a supported type.' );
				continue;
			}
			self.uploadAttachment( file, $list );
		}
		$( '#assistant-attachments' ).val( '' );
	},

	uploadAttachment( file: File, $list: JQuery ): void {
		const g = getGlobal();
		if ( ! g ) { return; }
		const formData = new FormData();
		formData.append( 'file', file );
		formData.append( 'action', 'wp_mcp_ai_upload_assistant_attachment' );
		formData.append( 'nonce', g.nonce );

		const $item = $( '<li class="wp-mcp-ai-attachment-item uploading">' +
			'<span class="name">' + file.name + '</span>' +
			'<span class="status">Uploading...</span></li>' );
		$list.append( $item );

		$.ajax( {
			url: g.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success( response: AjaxResponse ) {
				if ( response.success ) {
					$item.removeClass( 'uploading' )
						.data( 'attachment-id', response.data?.attachment_id )
						.find( '.status' ).html( '<button type="button" class="remove-attachment">&times;</button>' );
				} else {
					$item.addClass( 'error' ).find( '.status' ).text( 'Failed' );
					setTimeout( () => $item.remove(), 2000 );
				}
			},
			error() {
				$item.addClass( 'error' ).find( '.status' ).text( 'Failed' );
				setTimeout( () => $item.remove(), 2000 );
			},
		} as Record< string, unknown > );
	},

	initPromptTab(): void {
		const self = this;
		const $buildButton = $( '.wp-mcp-ai-build-with-ai-btn' );
		const $modal = $( '#wp-mcp-ai-build-assistant-modal' );
		if ( ! $buildButton.length || ! $modal.length ) { return; }

		const $modalClose = $modal.find( '.wp-mcp-ai-test-modal__close' );
		const $modalBackdrop = $modal.find( '.wp-mcp-ai-test-modal__backdrop' );

		$buildButton.on( 'click', function ( this: HTMLElement ) {
			const assistantId = $( this ).data( 'assistant-id' ) as string | undefined;
			const title = $( this ).data( 'assistant-title' ) as string | undefined;
			if ( assistantId ) { self.openBuildModal( assistantId, title ); }
		} );

		$modalClose.on( 'click', () => self.closeBuildModal() );

		$modal.on( 'click', ( event: Event ) => {
			const t = event.target as HTMLElement;
			if ( t === $modal[ 0 ] || t === $modalBackdrop[ 0 ] ) {
				self.closeBuildModal();
			}
		} );

		$( document ).on( 'keydown', ( e: Event ) => {
			if ( ( e as KeyboardEvent ).key === 'Escape' && $modal.is( ':visible' ) ) {
				self.closeBuildModal();
			}
		} );
	},

	openBuildModal( assistantId: string, assistantTitle?: string ): void {
		const $modal = $( '#wp-mcp-ai-build-assistant-modal' );
		const $modalTitle = $( '#wp-mcp-ai-build-assistant-modal__title' );
		const $chatContainer = $( '#wp-mcp-ai-build-assistant-chat-container' );
		if ( ! $modal.length || ! $chatContainer.length ) { return; }

		if ( $modalTitle.length ) {
			$modalTitle.text( escapeHtml( assistantTitle || 'Build with AI' ) );
		}

		$chatContainer.empty();
		const instanceId = 'wp-mcp-ai-build-chat-' + assistantId + '-' + Date.now();
		$chatContainer.html( this.buildChatHTML( instanceId ) );

		const win = window as unknown as Record< string, unknown >;
		if ( ! win.wpMcpAiChatInstances ) { win.wpMcpAiChatInstances = {}; }

		const cfg = getChatConfig();
		const baseUrl = cfg.restUrl || '/wp-json/mcp-ai/v1';

		( win.wpMcpAiChatInstances as Record< string, unknown > )[ instanceId ] = {
			assistantId,
			userId: cfg.currentUserId ?? 0,
			messagesEndpoint: baseUrl + 'chat-client',
			toolsEndpoint: baseUrl + 'tools',
			filesEndpoint: cfg.filesEndpoint || baseUrl + 'files/',
			transcriptsEndpoint: cfg.transcriptsEndpoint || baseUrl + 'chat-transcripts',
			crawl4aiTaskEndpoint: baseUrl + 'crawl4ai/task/',
			uploadEndpoint: cfg.uploadEndpoint || '/wp-json/wp/v2/media',
			sessionKey: this.generateSessionKey(),
			enableStreaming: true,
			canUploadAttachments: true,
			saveTranscript: false,
			allowSensitiveTools: true,
			toolShortcuts: [],
			fileAccept: cfg.fileAccept || '',
			allowedImageMimes: cfg.allowedImageMimes || [],
			allowedFileMimes: cfg.allowedFileMimes || [],
			allowedExtensions: cfg.allowedExtensions || [],
			restNonce: cfg.nonce || '',
			historyPerPage: 20,
		};

		$modal.show();
		$( 'body' ).addClass( 'wp-mcp-ai-test-modal-open' );
		this.initializeChatInstance( instanceId );
	},

	closeBuildModal(): void {
		const $modal = $( '#wp-mcp-ai-build-assistant-modal' );
		if ( $modal.length ) {
			$modal.hide();
			$( 'body' ).removeClass( 'wp-mcp-ai-test-modal-open' );
		}
		const $chatContainer = $( '#wp-mcp-ai-build-assistant-chat-container' );
		if ( $chatContainer.length ) { $chatContainer.empty(); }
	},

	buildChatHTML( instanceId: string ): string {
		const placeholderEscaped = escapeHtml( this.getPlaceholder() );
		const sendLabelEscaped = escapeHtml( this.getSendLabel() );

		return '<div class="wp-mcp-ai-chat" id="' + instanceId + '" data-wp-mcp-ai-chat>' +
			'<div class="wp-mcp-ai-chat__transcript-controls">' +
			'<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false" aria-label="Expand conversation">' +
			'<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24"><path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"/></svg>' +
			'<span class="screen-reader-text">Expand conversation</span></button></div>' +
			'<div class="wp-mcp-ai-chat__messages" role="log" aria-live="polite" aria-atomic="false"></div>' +
			'<form class="wp-mcp-ai-chat__form">' +
			'<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>' +
			'<textarea class="wp-mcp-ai-chat__input" rows="4" placeholder="' + placeholderEscaped + '" required></textarea>' +
			'<div class="wp-mcp-ai-chat__actions">' +
			'<button type="submit" class="wp-mcp-ai-chat__submit">' + sendLabelEscaped + '</button></div></form></div>';
	},

	initializeChatInstance( instanceId: string ): void {
		setTimeout( () => {
			const container = document.getElementById( instanceId );
			if ( ! container ) { return; }
			const event = document.createEvent( 'Event' );
			event.initEvent( 'DOMContentLoaded', true, true );
			document.dispatchEvent( event );
			setTimeout( () => {
				const textarea = container.querySelector( '.wp-mcp-ai-chat__input' ) as HTMLTextAreaElement | null;
				textarea?.focus();
			}, 200 );
		}, 100 );
	},

	generateSessionKey(): string {
		const array = new Uint8Array( 16 );
		crypto.getRandomValues( array );
		return 'build-' + Array.from( array, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	},

	getPlaceholder(): string {
		return getChatConfig().strings?.placeholder || 'Describe the assistant you want to create...';
	},
	getSendLabel(): string {
		return getChatConfig().strings?.send || 'Send';
	},
	getAttachLabel(): string {
		return getChatConfig().strings?.attachFile || 'Attach file';
	},
	getTranscribeLabel(): string {
		return getChatConfig().strings?.transcribeAudio || 'Transcribe audio';
	},

	showMessage( type: string, message: string ): void {
		$( '.wp-mcp-ai-error-message, .wp-mcp-ai-success-message' ).remove();
		const className = type === 'success' ? 'wp-mcp-ai-success-message' : 'wp-mcp-ai-error-message';
		$( '<div class="' + className + '">' + message + '</div>' )
			.prepend( $( '.wp-mcp-ai-section' ).first() );
		$( 'html, body' ).animate(
			{ scrollTop: $( '.wp-mcp-ai-section' ).first().offset().top - 50 },
			300,
		);
	},
};

// ── Auto-init ────────────────────────────────────────────────────────

$( document ).ready( () => {
	BuildAssistantPage.init();
} );
