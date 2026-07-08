/**
 * NV oOS Chat SPA — single message renderer (Phase 8 enhanced).
 *
 * Renders per message:
 *   1. Markdown → HTML content (`m.content`) — parsed through marked + DOMPurify
 *      for XSS-safe rich-text display.  Code blocks get inline "Copy" buttons
 *      injected post-render.
 *   2. Tool-invocation cards (`m.toolInvocations`) — populated automatically
 *      by `useChat` from the AI SDK Data Stream `9:` tool_call and `a:`
 *      tool_result chunks emitted by `../sse-adapter.ts`.
 *   3. Annotation pills (`m.annotations`) — memory events and unknown
 *      frames forwarded as `8:` message_annotations.
 *   4. Special content blocks — JSON responses, truncated responses, chart
 *      blocks, video attachments, and inline images rendered natively instead
 *      of being dumped as raw text.
 *   5. Message toolbar — copy, save/bookmark, delete, and feedback (👍/👎)
 *      buttons on each message bubble.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { renderMarkdown } from '../api/markdown';
import { useCopyToClipboard } from '../hooks/useCopyToClipboard';

// ── Types ──────────────────────────────────────────────────────────────────────

interface ToolInvocation {
	state: 'partial-call' | 'call' | 'result';
	toolCallId: string;
	toolName: string;
	args?: unknown;
	result?: unknown;
}

interface Annotation {
	type?: string;
	[ k: string ]: unknown;
}

interface ChatMessage {
	id: string;
	role: 'system' | 'user' | 'assistant' | 'data' | string;
	content?: string;
	toolInvocations?: ToolInvocation[];
	annotations?: Annotation[];
}

export interface MessageActionProps {
	/** The message being rendered. */
	message: ChatMessage;
	/** Index of this message within the full list. */
	index: number;
	/** Total message count — used to determine if this is the last assistant msg. */
	totalCount: number;
	/** True while the chat is streaming. */
	isStreaming: boolean;
	/** Callback to delete this message by id. */
	onDelete?: ( msgId: string ) => void;
	/** Callback when the user rates a message (👍 or 👎). */
	onFeedback?: ( msgId: string, rating: 'up' | 'down' ) => void;
	/** Previously recorded feedback for this message. */
	feedback?: 'up' | 'down' | null;
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function safeStringify( value: unknown, space: number = 2 ): string {
	try {
		return JSON.stringify( value, null, space ) ?? '';
	} catch {
		return String( value );
	}
}

function isLikelyJson( text: string ): boolean {
	const trimmed = text.trim();
	return (
		( trimmed.startsWith( '{' ) && trimmed.endsWith( '}' ) ) ||
		( trimmed.startsWith( '[' ) && trimmed.endsWith( ']' ) )
	);
}

function isTruncatedContent( text: string ): boolean {
	// The legacy client checks for orchestration truncation markers.
	return text.includes( '…[truncated]' ) || text.includes( '…[content truncated' );
}

interface ChartData {
	html: string;
	chartType?: string;
	chartTitle?: string;
	width?: number;
	height?: number;
}

function tryExtractChart( text: string ): ChartData | null {
	// Detect chart HTML responses produced by the chart tool.
	const chartMatch = text.match(
		/<div[^>]*data-chart-type\s*=\s*["']([^"']*)["'][^>]*>([\s\S]*?)<\/div>/
	);
	if ( ! chartMatch ) {
		// Also check for canvas/script-based charts.
		if ( text.includes( '<canvas' ) && text.includes( 'new Chart(' ) ) {
			return { html: text };
		}
		return null;
	}
	const chartType = chartMatch[ 1 ] || undefined;
	const html = chartMatch[ 0 ];
	const titleMatch = text.match( /<h[2-4][^>]*>([^<]*)<\/h[2-4]>/ );
	const widthMatch = text.match( /width\s*[:=]\s*(\d+)/ );
	const heightMatch = text.match( /height\s*[:=]\s*(\d+)/ );
	return {
		html,
		chartType,
		chartTitle: titleMatch?.[ 1 ],
		width: widthMatch ? parseInt( widthMatch[ 1 ], 10 ) : undefined,
		height: heightMatch ? parseInt( heightMatch[ 1 ], 10 ) : undefined,
	};
}

/**
 * Check whether the content contains an inline video URL that should be
 * rendered as a native <video> element.
 */
function extractVideoUrl( text: string ): string | null {
	const videoRegex = /(https?:\/\/\S+\.(?:mp4|webm|ogg|mov)(?:\?\S*)?)/i;
	const match = text.match( videoRegex );
	return match ? match[ 1 ] : null;
}

/**
 * Check whether the content contains an inline image that should be rendered
 * natively (data-URL or standard image URL).
 */
function extractImageUrls( text: string ): string[] {
	const urls: string[] = [];
	// Data-URL images.
	const dataRegex = /data:image\/[^;"'\s]+;base64,[A-Za-z0-9+/=]+/g;
	let match;
	while ( ( match = dataRegex.exec( text ) ) !== null ) {
		urls.push( match[ 0 ] );
	}
	// Standard image URLs.
	const urlRegex = /(https?:\/\/\S+\.(?:png|jpg|jpeg|gif|webp|svg)(?:\?\S*)?)/gi;
	while ( ( match = urlRegex.exec( text ) ) !== null ) {
		if ( ! urls.includes( match[ 1 ] ) ) {
			urls.push( match[ 1 ] );
		}
	}
	return urls;
}

// ── Main component ─────────────────────────────────────────────────────────────

export function MessageView( {
	message,
	index,
	totalCount,
	isStreaming,
	onDelete,
	onFeedback,
	feedback,
}: MessageActionProps ): JSX.Element {
	const tools = Array.isArray( message.toolInvocations ) ? message.toolInvocations : [];
	const annotations = Array.isArray( message.annotations ) ? message.annotations : [];
	const content = typeof message.content === 'string' ? message.content : '';

	const isAssistant = message.role === 'assistant';
	const isUser = message.role === 'user';
	const isLastAssistant = isAssistant && index === totalCount - 1 && ! isStreaming;
	const showToolbar = ! isStreaming && ( isAssistant || isUser );

	return (
		<div
			className={ `nvoos-chat-spa-message nvoos-chat-spa-message--${ message.role }` }
			data-message-id={ message.id }
		>
			<span className="nvoos-chat-spa-role">{ message.role }</span>

			{ content !== '' && (
				<MessageContent content={ content } />
			) }

			{ tools.length > 0 && (
				<div className="nvoos-chat-spa-tools">
					{ tools.map( ( inv ) => (
						<ToolCallCard key={ inv.toolCallId } invocation={ inv } />
					) ) }
				</div>
			) }

			{ annotations.length > 0 && (
				<div className="nvoos-chat-spa-annotations">
					{ annotations.map( ( ann, idx ) => (
						<AnnotationPill
							key={ `${ message.id }-ann-${ idx }` }
							annotation={ ann }
						/>
					) ) }
				</div>
			) }

			{ showToolbar && content !== '' && (
				<MessageToolbar
					messageId={ message.id }
					content={ content }
					isAssistant={ isAssistant }
					isLastAssistant={ isLastAssistant }
					feedback={ feedback }
					onDelete={ onDelete }
					onFeedback={ onFeedback }
				/>
			) }
		</div>
	);
}

// ── Message content router ─────────────────────────────────────────────────────

function MessageContent( { content }: { content: string } ): JSX.Element {
	// ── JSON response ──────────────────────────────────────────────────
	if ( isLikelyJson( content ) ) {
		try {
			const parsed = JSON.parse( content );
			return <JsonResponseBlock data={ parsed } raw={ content } />;
		} catch {
			// Not valid JSON after all — render as markdown.
		}
	}

	// ── Truncated response ──────────────────────────────────────────────
	if ( isTruncatedContent( content ) ) {
		return <TruncatedResponseBlock content={ content } />;
	}

	// ── Chart block ─────────────────────────────────────────────────────
	const chart = tryExtractChart( content );
	if ( chart ) {
		return <ChartBlock chart={ chart } />;
	}

	// ── Video ───────────────────────────────────────────────────────────
	const videoUrl = extractVideoUrl( content );
	if ( videoUrl ) {
		return <VideoBlock url={ videoUrl } />;
	}

	// ── Image(s) ────────────────────────────────────────────────────────
	const imageUrls = extractImageUrls( content );
	if ( imageUrls.length > 0 ) {
		return <ImageGalleryBlock urls={ imageUrls } />;
	}

	// ── Standard markdown ───────────────────────────────────────────────
	return <SafeMarkdownContent text={ content } />;
}

// ── Safe markdown with inline code-copy buttons ─────────────────────────────────

function SafeMarkdownContent( { text }: { text: string } ): JSX.Element {
	const html = useMemo( () => renderMarkdown( text ), [ text ] );
	const containerRef = useRef< HTMLDivElement | null >( null );

	// Inject "Copy" buttons into code blocks after render.
	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) return;

		const pres = container.querySelectorAll< HTMLPreElement >(
			'.nvoos-chat-spa-code-block'
		);
		pres.forEach( ( pre ) => {
			if ( pre.querySelector( '.nvoos-chat-spa-code-copy' ) ) return;
			const wrapper = document.createElement( 'div' );
			wrapper.className = 'nvoos-chat-spa-code-wrapper';
			pre.parentNode?.insertBefore( wrapper, pre );
			wrapper.appendChild( pre );

			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'nvoos-chat-spa-code-copy';
			btn.setAttribute( 'aria-label', __( 'Copy code', 'nvoos-chat-spa' ) );
			btn.textContent = __( 'Copy', 'nvoos-chat-spa' );
			btn.addEventListener( 'click', () => {
				const codeText = pre.textContent || '';
				void copyViaNavigator( codeText, btn );
			} );
			wrapper.appendChild( btn );
		} );
	}, [ html ] );

	return (
		<div
			ref={ containerRef }
			className="nvoos-chat-spa-content"
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}

async function copyViaNavigator(
	text: string,
	btn: HTMLButtonElement
): Promise< void > {
	try {
		if ( navigator.clipboard?.writeText ) {
			await navigator.clipboard.writeText( text );
		} else {
			fallbackCopyLegacy( text );
		}
		btn.textContent = __( 'Copied!', 'nvoos-chat-spa' );
		btn.classList.add( 'nvoos-chat-spa-code-copy--success' );
		setTimeout( () => {
			btn.textContent = __( 'Copy', 'nvoos-chat-spa' );
			btn.classList.remove( 'nvoos-chat-spa-code-copy--success' );
		}, 2000 );
	} catch {
		// Silently fail.
	}
}

function fallbackCopyLegacy( text: string ): void {
	const ta = document.createElement( 'textarea' );
	ta.value = text;
	ta.style.position = 'fixed';
	ta.style.left = '-9999px';
	document.body.appendChild( ta );
	ta.select();
	document.execCommand( 'copy' );
	document.body.removeChild( ta );
}

// ── Special content blocks ─────────────────────────────────────────────────────

function JsonResponseBlock( {
	data,
	raw,
}: {
	data: unknown;
	raw: string;
} ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	return (
		<details
			className="nvoos-chat-spa-special nvoos-chat-spa-special--json"
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-chat-spa-special-summary">
				<span className="nvoos-chat-spa-special-icon" aria-hidden="true">{ '{ }' }</span>
				{ __( 'JSON response', 'nvoos-chat-spa' ) }
			</summary>
			<pre className="nvoos-chat-spa-special-body">
				{ safeStringify( data ) }
			</pre>
		</details>
	);
}

function TruncatedResponseBlock( { content }: { content: string } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	return (
		<details
			className="nvoos-chat-spa-special nvoos-chat-spa-special--truncated"
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-chat-spa-special-summary">
				<span className="nvoos-chat-spa-special-icon" aria-hidden="true">{ '…' }</span>
				{ __( 'Truncated response', 'nvoos-chat-spa' ) }
			</summary>
			<SafeMarkdownContent text={ content } />
		</details>
	);
}

function ChartBlock( { chart }: { chart: ChartData } ): JSX.Element {
	const safeWidth = chart.width || 600;
	const safeHeight = chart.height || 400;
	const blob = new Blob(
		[
			`<!DOCTYPE html><html><head><meta charset="utf-8">` +
			`<script src="https://cdn.jsdelivr.net/npm/chart.js@4"><\/script>` +
			`</head><body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh">` +
			chart.html +
			`</body></html>`,
		],
		{ type: 'text/html' }
	);
	const src = URL.createObjectURL( blob );

	return (
		<div
			className="nvoos-chat-spa-chart"
			style={ { maxWidth: safeWidth } }
		>
			{ chart.chartTitle && (
				<div className="nvoos-chat-spa-chart-title">{ chart.chartTitle }</div>
			) }
			<iframe
				src={ src }
				width={ safeWidth }
				height={ safeHeight }
				className="nvoos-chat-spa-chart-iframe"
				title={ chart.chartType || __( 'Chart', 'nvoos-chat-spa' ) }
				sandbox="allow-scripts allow-same-origin"
				onLoad={ () => URL.revokeObjectURL( src ) }
			/>
		</div>
	);
}

function VideoBlock( { url }: { url: string } ): JSX.Element {
	// Determine MIME type from extension.
	const lower = url.toLowerCase();
	let mimeType = 'video/mp4';
	if ( lower.endsWith( '.webm' ) ) mimeType = 'video/webm';
	else if ( lower.endsWith( '.ogg' ) || lower.endsWith( '.ogv' ) ) mimeType = 'video/ogg';
	else if ( lower.endsWith( '.mov' ) ) mimeType = 'video/quicktime';

	return (
		<div className="nvoos-chat-spa-video">
			<video controls className="nvoos-chat-spa-video-player" preload="metadata">
				<source src={ url } type={ mimeType } />
				<track kind="captions" />
				{ __( 'Your browser does not support the video tag.', 'nvoos-chat-spa' ) }
			</video>
			<a
				href={ url }
				className="nvoos-chat-spa-video-download"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ __( 'Download video', 'nvoos-chat-spa' ) }
			</a>
		</div>
	);
}

function ImageGalleryBlock( { urls }: { urls: string[] } ): JSX.Element {
	const displayUrls = urls.slice( 0, 10 ); // Cap at 10 to avoid layout abuse.
	return (
		<div className="nvoos-chat-spa-gallery">
			{ displayUrls.map( ( url, idx ) => (
				<img
					key={ `${ url.slice( 0, 50 ) }-${ idx }` }
					src={ url }
					alt={ sprintf(
						/* translators: %d: image index */
						__( 'Attached image %d', 'nvoos-chat-spa' ),
						idx + 1
					) }
					className="nvoos-chat-spa-gallery-img"
					loading="lazy"
				/>
			) ) }
			{ urls.length > 10 && (
				<p className="nvoos-chat-spa-gallery-more">
					{ sprintf(
						/* translators: %d: number of additional images */
						__( '+%d more images', 'nvoos-chat-spa' ),
						urls.length - 10
					) }
				</p>
			) }
		</div>
	);
}

// ── Tool invocation card (existing, unchanged) ──────────────────────────────────

function ToolCallCard( { invocation }: { invocation: ToolInvocation } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const isResult = invocation.state === 'result';
	const label = isResult
		? __( 'tool result', 'nvoos-chat-spa' )
		: __( 'running…', 'nvoos-chat-spa' );

	return (
		<details
			className={ `nvoos-chat-spa-tool nvoos-chat-spa-tool--${ invocation.state }` }
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
			data-tool-name={ invocation.toolName }
		>
			<summary>
				<span className="nvoos-chat-spa-tool-name">{ invocation.toolName }</span>
				<span className="nvoos-chat-spa-tool-state">{ label }</span>
			</summary>
			{ invocation.args !== undefined && (
				<div className="nvoos-chat-spa-tool-block">
					<div className="nvoos-chat-spa-tool-block-label">
						{ __( 'Arguments', 'nvoos-chat-spa' ) }
					</div>
					<pre>{ safeStringify( invocation.args ) }</pre>
				</div>
			) }
			{ isResult && (
				<div className="nvoos-chat-spa-tool-block">
					<div className="nvoos-chat-spa-tool-block-label">
						{ __( 'Result', 'nvoos-chat-spa' ) }
					</div>
					<pre>{ safeStringify( invocation.result ) }</pre>
				</div>
			) }
		</details>
	);
}

/**
 * Derive a human-readable label from an annotation, handling wrapped unknown
 * frames that carry sub-type information (start, tool_start, tool_result, etc.).
 */
function annotationLabel( ann: Annotation ): string {
	const type = typeof ann.type === 'string' ? ann.type : '';

	// Status events — use the message text if available
	if (
		type === 'thinking' ||
		type === 'generating' ||
		type === 'processing_attachments' ||
		type === 'loading_memory'
	) {
		const msg = typeof ann.message === 'string' ? ann.message : '';
		return msg || type;
	}

	// Memory event — use title
	if ( type === 'memory_event' ) {
		const title = typeof ann.title === 'string' && ann.title ? ann.title : __( 'memory', 'nvoos-chat-spa' );
		return title;
	}

	// Tool result as annotation (from completion frame tool_results)
	if ( ann.role === 'tool' && typeof ann.name === 'string' ) {
		return ann.name;
	}

	// Wrapped unknown frames — peek inside to find the real type
	if ( type === 'unknown' ) {
		const frame = ann.frame as Record<string, unknown> | undefined;
		if ( frame && typeof frame.type === 'string' ) {
			switch ( frame.type ) {
				case 'start':
					return __( 'agent loop', 'nvoos-chat-spa' );
				case 'tool_start':
					return typeof frame.tool_name === 'string' ? ( frame.tool_name as string ) : __( 'tool start', 'nvoos-chat-spa' );
				case 'tool_result':
					return typeof frame.tool_name === 'string'
						? ( frame.tool_name as string )
						: __( 'tool result', 'nvoos-chat-spa' );
				default:
					return frame.type as string;
			}
		}
		// tool_results array items sometimes come through without the unknown wrapper
		if ( ann.name && typeof ann.name === 'string' ) {
			return ann.name;
		}
		return __( 'unknown', 'nvoos-chat-spa' );
	}

	if ( type === 'annotation' || type === '' ) {
		return typeof ann.name === 'string' ? ann.name : __( 'annotation', 'nvoos-chat-spa' );
	}

	// Data/completion frames (from SSE adapter data type)
	if ( type === 'data' ) {
		return __( 'response info', 'nvoos-chat-spa' );
	}

	return type;
}

function AnnotationPill( { annotation }: { annotation: Annotation } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const type = typeof annotation.type === 'string' ? annotation.type : 'annotation';
	const label = annotationLabel( annotation );

	return (
		<details
			className={ `nvoos-chat-spa-annotation nvoos-chat-spa-annotation--${ type }` }
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-chat-spa-annotation__summary">
				{ label }
			</summary>
			<pre className="nvoos-chat-spa-annotation__body">{ safeStringify( annotation ) }</pre>
		</details>
	);
}

// ── Message toolbar ────────────────────────────────────────────────────────────

interface MessageToolbarProps {
	messageId: string;
	content: string;
	isAssistant: boolean;
	isLastAssistant: boolean;
	feedback?: 'up' | 'down' | null;
	onDelete?: ( msgId: string ) => void;
	onFeedback?: ( msgId: string, rating: 'up' | 'down' ) => void;
}

function MessageToolbar( {
	messageId,
	content,
	isAssistant,
	isLastAssistant,
	feedback,
	onDelete,
	onFeedback,
}: MessageToolbarProps ): JSX.Element {
	const { copy, justCopied } = useCopyToClipboard();

	// Saved-messages state — kept local so each toolbar instance is independent.
	const [ saved, setSaved ] = useState( false );

	const handleSave = useCallback( () => {
		setSaved( ( prev ) => ! prev );
	}, [] );

	const handleCopy = useCallback( () => {
		void copy( content );
	}, [ copy, content ] );

	return (
		<div className="nvoos-chat-spa-toolbar" role="toolbar" aria-label={ __( 'Message actions', 'nvoos-chat-spa' ) }>
			{ /* Copy */ }
			<button
				type="button"
				className={ `nvoos-chat-spa-toolbar-btn nvoos-chat-spa-toolbar-copy${ justCopied ? ' nvoos-chat-spa-toolbar-copy--success' : '' }` }
				aria-label={ justCopied ? __( 'Copied!', 'nvoos-chat-spa' ) : __( 'Copy message', 'nvoos-chat-spa' ) }
				title={ __( 'Copy message', 'nvoos-chat-spa' ) }
				onClick={ handleCopy }
			>
				{ justCopied ? '✓' : '📋' }
			</button>

			{ /* Save / Bookmark */ }
			{ isAssistant && (
				<button
					type="button"
					className={ `nvoos-chat-spa-toolbar-btn nvoos-chat-spa-toolbar-save${ saved ? ' nvoos-chat-spa-toolbar-save--active' : '' }` }
					aria-label={ saved ? __( 'Unsave message', 'nvoos-chat-spa' ) : __( 'Save message', 'nvoos-chat-spa' ) }
					title={ saved ? __( 'Saved', 'nvoos-chat-spa' ) : __( 'Save for later', 'nvoos-chat-spa' ) }
					onClick={ handleSave }
				>
					{ saved ? '🔖' : '🏷' }
				</button>
			) }

			{ /* Feedback — only on last assistant message when idle */ }
			{ isLastAssistant && onFeedback && (
				<>
					<button
						type="button"
						className={ `nvoos-chat-spa-toolbar-btn nvoos-chat-spa-toolbar-feedback${ feedback === 'up' ? ' nvoos-chat-spa-toolbar-feedback--active' : '' }` }
						aria-label={ __( 'Thumbs up', 'nvoos-chat-spa' ) }
						title={ __( 'Helpful', 'nvoos-chat-spa' ) }
						onClick={ () => onFeedback( messageId, 'up' ) }
					>
						👍
					</button>
					<button
						type="button"
						className={ `nvoos-chat-spa-toolbar-btn nvoos-chat-spa-toolbar-feedback${ feedback === 'down' ? ' nvoos-chat-spa-toolbar-feedback--active' : '' }` }
						aria-label={ __( 'Thumbs down', 'nvoos-chat-spa' ) }
						title={ __( 'Not helpful', 'nvoos-chat-spa' ) }
						onClick={ () => onFeedback( messageId, 'down' ) }
					>
						👎
					</button>
				</>
			) }

			{ /* Delete */ }
			{ onDelete && (
				<button
					type="button"
					className="nvoos-chat-spa-toolbar-btn nvoos-chat-spa-toolbar-delete"
					aria-label={ __( 'Delete message', 'nvoos-chat-spa' ) }
					title={ __( 'Delete message', 'nvoos-chat-spa' ) }
					onClick={ () => {
						// eslint-disable-next-line no-alert
						if ( window.confirm( __( 'Delete this message?', 'nvoos-chat-spa' ) ) ) {
							onDelete( messageId );
						}
					} }
				>
					🗑
				</button>
			) }
		</div>
	);
}
