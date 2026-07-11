/**
 * MessageView — Renders a single chat message with role badge,
 * rich markdown content routing, tool cards, annotations, and toolbar.
 *
 * Mirrors chat-spa's MessageView.tsx with pro text domain and BEM prefix.
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { type Message } from '@ai-sdk/react';
import { renderMarkdown } from '../../components/shared/MarkdownContent';
import { useCopyToClipboard } from '../../hooks/useCopyToClipboard';
import { UsageBadges, type UsageData } from '../../components/shared/UsageBadges';
import { CapabilityFlagBadges } from '../../components/shared/CapabilityFlagBadges';
import { SpeechButton, type SpeechState } from '../../components/shared/SpeechButton';
import { JobCard, type JobRecord } from '../../components/shared/JobCard';
import { WorkflowTracker, type WorkflowState } from '../../components/shared/WorkflowTracker';
import { DelegationNotice, type DelegationData } from '../../components/shared/DelegationNotice';
import { normaliseToolResult, type NormalisedToolResult, type AttachmentEntry } from '../../utils/normalise-tool-result';

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

export interface MessageViewProps {
	message: Message;
	index: number;
	totalCount: number;
	isStreaming: boolean;
	onDelete?: ( msgId: string ) => void;
	onFeedback?: ( msgId: string, rating: 'up' | 'down' ) => void;
	feedback?: 'up' | 'down' | null;
	onEdit?: ( msgId: string ) => void;
	onRegenerate?: () => void;
	/** Usage data (v0.9.0). */
	usage?: UsageData | null;
	/** Speech (v0.9.0). */
	onSpeechPlay?: ( text: string ) => void;
	onSpeechStop?: () => void;
	speechStateFor?: ( text: string ) => SpeechState;
	/** Job cards (v0.9.0). */
	jobs?: Record< string, JobRecord >;
	onCancelJob?: ( id: string ) => void;
	onRetryJob?: ( id: string ) => void;
	/** Workflow + delegation (v0.9.0). */
	workflow?: WorkflowState | null;
	delegations?: DelegationData[];
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
	return (
		text.includes( '…[truncated]' ) ||
		text.includes( '…[content truncated' ) ||
		text.includes( '[tool_result_truncated]' )
	);
}

interface ChartData {
	html: string;
	chartType?: string;
	chartTitle?: string;
	width?: number;
	height?: number;
}

function tryExtractChart( text: string ): ChartData | null {
	const chartMatch = text.match(
		/<div[^>]*data-chart-type\s*=\s*["']([^"']*)["'][^>]*>([\s\S]*?)<\/div>/
	);
	if ( ! chartMatch ) {
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

function extractVideoUrl( text: string ): string | null {
	const videoRegex = /(https?:\/\/\S+\.(?:mp4|webm|ogg|mov)(?:\?\S*)?)/i;
	const match = text.match( videoRegex );
	return match ? match[ 1 ] : null;
}

function extractImageUrls( text: string ): string[] {
	const urls: string[] = [];
	const dataRegex = /data:image\/[^;"'\s]+;base64,[A-Za-z0-9+/=]+/g;
	let match;
	while ( ( match = dataRegex.exec( text ) ) !== null ) {
		urls.push( match[ 0 ] );
	}
	const urlRegex = /(https?:\/\/\S+\.(?:png|jpg|jpeg|gif|webp|svg)(?:\?\S*)?)/gi;
	while ( ( match = urlRegex.exec( text ) ) !== null ) {
		if ( ! urls.includes( match[ 1 ] ) ) {
			urls.push( match[ 1 ] );
		}
	}
	return urls;
}

function messageText( message: Message ): string {
	if ( typeof message.content === 'string' ) {
		return message.content;
	}
	if ( Array.isArray( message.content ) ) {
		return ( message.content as unknown[] )
			.map( ( part ) => {
				if ( typeof part === 'string' ) return part;
				if ( part && typeof part === 'object' && 'text' in part ) {
					return String( ( part as unknown as { text: string } ).text );
				}
				if ( part && typeof part === 'object' && 'type' in part && ( part as { type: string } ).type === 'text' ) {
					return String( ( part as unknown as { text: string } ).text );
				}
				return '';
			} )
			.join( '' );
	}
	return '';
}

function roleLabel( role: string ): string {
	switch ( role ) {
		case 'user': return __( 'You', 'nvoos-pro-spa' );
		case 'assistant': return __( 'Assistant', 'nvoos-pro-spa' );
		case 'system': return __( 'System', 'nvoos-pro-spa' );
		case 'tool': return __( 'Tool', 'nvoos-pro-spa' );
		default: return role || __( 'Unknown', 'nvoos-pro-spa' );
	}
}

function roleEmoji( role: string ): string {
	switch ( role ) {
		case 'assistant': return '🤖';
		case 'user': return '👤';
		case 'tool': return '🔧';
		default: return '📋';
	}
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
	onEdit,
	onRegenerate,
	usage,
	onSpeechPlay,
	onSpeechStop,
	speechStateFor,
	jobs,
	onCancelJob,
	onRetryJob,
	workflow,
	delegations,
}: MessageViewProps ): JSX.Element {
	const tools = Array.isArray( ( message as unknown as Record<string,unknown> ).toolInvocations )
		? ( message as unknown as Record<string,unknown> ).toolInvocations as ToolInvocation[]
		: [];
	const annotations = Array.isArray( ( message as unknown as Record<string,unknown> ).annotations )
		? ( message as unknown as Record<string,unknown> ).annotations as Annotation[]
		: [];
	const content = messageText( message );

	const isAssistant = message.role === 'assistant';
	const isUser = message.role === 'user';
	const isLast = index === totalCount - 1;
	const isLastAssistant = isAssistant && isLast && ! isStreaming;
	const showToolbar = ! isStreaming && ( isAssistant || isUser );

	return (
		<article
			className={ [
				'nvoos-pro-spa-message-view',
				`nvoos-pro-spa-message-view--${ message.role }`,
				isLast ? 'nvoos-pro-spa-message-view--last' : '',
				isStreaming && isLast ? 'nvoos-pro-spa-message-view--streaming' : '',
			].filter( Boolean ).join( ' ' ) }
			data-message-id={ message.id }
			aria-label={ sprintf(
				/* translators: %s: message role label */
				__( 'Message from %s', 'nvoos-pro-spa' ),
				roleLabel( message.role )
			) }
		>
			{/* Role badge */}
			<div className="nvoos-pro-spa-message-view__header">
				<span
					className={ `nvoos-pro-spa-message-view__badge nvoos-pro-spa-message-view__badge--${ message.role }` }
					aria-hidden="true"
				>
					{ roleEmoji( message.role ) }
				</span>
				<span className="nvoos-pro-spa-message-view__role">{ roleLabel( message.role ) }</span>
				{ isStreaming && isLast && isAssistant && (
					<span
						className="nvoos-pro-spa-message-view__streaming-indicator"
						aria-label={ __( 'Generating response…', 'nvoos-pro-spa' ) }
					>
						<span className="nvoos-pro-spa-message-view__dot" aria-hidden="true" />
						<span className="nvoos-pro-spa-message-view__dot" aria-hidden="true" />
						<span className="nvoos-pro-spa-message-view__dot" aria-hidden="true" />
					</span>
				) }
			</div>

			{/* Message body */}
			<div className="nvoos-pro-spa-message-view__body">
				{ content ? (
					<MessageContent content={ content } />
				) : (
					isStreaming && isLast && isAssistant && (
						<p className="nvoos-pro-spa-message-view__thinking">
							{ __( 'Thinking…', 'nvoos-pro-spa' ) }
						</p>
					)
				) }
			</div>

			{/* Tool invocations */}
			{ tools.length > 0 && (
				<div className="nvoos-pro-spa-message-view__tools">
					{ tools.map( ( inv ) => {
						const result = inv.result as Record< string, unknown > | undefined;
						const asyncJobId = inv.state === 'result' && result && ( typeof result.job_id === 'string' || typeof result.jobId === 'string' )
							? ( ( result.job_id ?? result.jobId ) as string ) : null;
						if ( asyncJobId && jobs?.[ asyncJobId ] ) {
							return <JobCard key={ inv.toolCallId } job={ jobs[ asyncJobId ] } onCancel={ onCancelJob } onRetry={ onRetryJob } />;
						}
						return <ToolCallCard key={ inv.toolCallId } invocation={ inv } />;
					} ) }
				</div>
			) }

			{/* Annotations */}
				{ annotations.length > 0 && (
					<div className="nvoos-pro-spa-message-view__annotations">
						{ annotations.map( ( ann, idx ) => (
							<AnnotationPill
								key={ `${ message.id }-ann-${ idx }` }
								annotation={ ann }
							/>
						) ) }
					</div>
				) }

			{/* Toolbar */}
			{ showToolbar && content !== '' && (
				<MessageToolbar
					messageId={ message.id }
					content={ content }
					isAssistant={ isAssistant }
					isLastAssistant={ isLastAssistant }
					feedback={ feedback }
					onDelete={ onDelete }
					onFeedback={ onFeedback }
					onEdit={ onEdit }
					onRegenerate={ onRegenerate }
					onSpeechPlay={ onSpeechPlay }
					onSpeechStop={ onSpeechStop }
					speechStateFor={ speechStateFor }
				/>
			) }

			{/* Usage badges (v0.9.0) */}
			{ isAssistant && usage && <UsageBadges usage={ usage } /> }

			{/* Capability flags (v0.9.0) */}
			{ isAssistant && (
				<CapabilityFlagBadges
					flags={ annotations
						.filter( ( a ) => a?.type === 'capabilities' )
						.flatMap( ( a ) => ( Array.isArray( ( a as Record< string, unknown > ).flags ) ? ( a as Record< string, unknown > ).flags as string[] : [] ) ) }
				/>
			) }

			{/* Delegation notices (v0.9.0) */}
			{ isAssistant && delegations && delegations.length > 0 && delegations.map( ( d, di ) => (
				<DelegationNotice key={ di } delegation={ d } />
			) ) }

			{/* Workflow tracker (v0.9.0) */}
			{ isLastAssistant && workflow && workflow.steps.length > 0 && (
				<WorkflowTracker workflow={ workflow } />
			) }
		</article>
	);
}

// ── Message content router ─────────────────────────────────────────────────────

function MessageContent( { content }: { content: string } ): JSX.Element {
	// JSON response
	if ( isLikelyJson( content ) ) {
		try {
			const parsed = JSON.parse( content );
			return <JsonResponseBlock data={ parsed } />;
		} catch {
			// Not valid JSON — fall through to markdown.
		}
	}

	// Truncated response
	if ( isTruncatedContent( content ) ) {
		return <TruncatedResponseBlock content={ content } />;
	}

	// Chart block
	const chart = tryExtractChart( content );
	if ( chart ) {
		return <ChartBlock chart={ chart } />;
	}

	// Video block
	const videoUrl = extractVideoUrl( content );
	if ( videoUrl ) {
		return <VideoBlock url={ videoUrl } />;
	}

	// Image gallery
	const imageUrls = extractImageUrls( content );
	if ( imageUrls.length > 0 ) {
		return <ImageGalleryBlock urls={ imageUrls } />;
	}

	// Standard markdown
	return <SafeMarkdownContent text={ content } />;
}

// ── Safe markdown with inline code-copy buttons ─────────────────────────────────

function SafeMarkdownContent( { text }: { text: string } ): JSX.Element {
	const html = useMemo( () => renderMarkdown( text ), [ text ] );
	const containerRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) return;

		const pres = container.querySelectorAll< HTMLPreElement >( '.nvoos-pro-spa-code-block' );
		pres.forEach( ( pre ) => {
			if ( pre.querySelector( '.nvoos-pro-spa-code-copy' ) ) return;
			const wrapper = document.createElement( 'div' );
			wrapper.className = 'nvoos-pro-spa-code-wrapper';
			pre.parentNode?.insertBefore( wrapper, pre );
			wrapper.appendChild( pre );

			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'nvoos-pro-spa-code-copy';
			btn.setAttribute( 'aria-label', __( 'Copy code', 'nvoos-pro-spa' ) );
			btn.textContent = __( 'Copy', 'nvoos-pro-spa' );
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
			className="nvoos-pro-spa-markdown"
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}

async function copyViaNavigator( text: string, btn: HTMLButtonElement ): Promise< void > {
	try {
		if ( navigator.clipboard?.writeText ) {
			await navigator.clipboard.writeText( text );
		} else {
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
		}
		btn.textContent = __( 'Copied!', 'nvoos-pro-spa' );
		btn.classList.add( 'nvoos-pro-spa-code-copy--success' );
		setTimeout( () => {
			btn.textContent = __( 'Copy', 'nvoos-pro-spa' );
			btn.classList.remove( 'nvoos-pro-spa-code-copy--success' );
		}, 2000 );
	} catch {
		// Silently fail.
	}
}

// ── Special content blocks ─────────────────────────────────────────────────────

function JsonResponseBlock( { data }: { data: unknown } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	return (
		<details
			className="nvoos-pro-spa-special nvoos-pro-spa-special--json"
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-pro-spa-special-summary">
				<span className="nvoos-pro-spa-special-icon" aria-hidden="true">{ '{ }' }</span>
				{ __( 'JSON response', 'nvoos-pro-spa' ) }
			</summary>
			<pre className="nvoos-pro-spa-special-body">{ safeStringify( data ) }</pre>
		</details>
	);
}

function TruncatedResponseBlock( { content }: { content: string } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	return (
		<details
			className="nvoos-pro-spa-special nvoos-pro-spa-special--truncated"
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-pro-spa-special-summary">
				<span className="nvoos-pro-spa-special-icon" aria-hidden="true">{ '…' }</span>
				{ __( 'Truncated response', 'nvoos-pro-spa' ) }
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
			'<!DOCTYPE html><html><head><meta charset="utf-8">' +
			'<script src="https://cdn.jsdelivr.net/npm/chart.js@4"><\/script>' +
			'</head><body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh">' +
			chart.html +
			'</body></html>',
		],
		{ type: 'text/html' }
	);
	const src = URL.createObjectURL( blob );

	return (
		<div className="nvoos-pro-spa-chart" style={ { maxWidth: safeWidth } }>
			{ chart.chartTitle && (
				<div className="nvoos-pro-spa-chart-title">{ chart.chartTitle }</div>
			) }
			<iframe
				src={ src }
				width={ safeWidth }
				height={ safeHeight }
				className="nvoos-pro-spa-chart-iframe"
				title={ chart.chartType || __( 'Chart', 'nvoos-pro-spa' ) }
				sandbox="allow-scripts allow-same-origin"
				onLoad={ () => URL.revokeObjectURL( src ) }
			/>
		</div>
	);
}

function VideoBlock( { url }: { url: string } ): JSX.Element {
	const lower = url.toLowerCase();
	let mimeType = 'video/mp4';
	if ( lower.endsWith( '.webm' ) ) mimeType = 'video/webm';
	else if ( lower.endsWith( '.ogg' ) || lower.endsWith( '.ogv' ) ) mimeType = 'video/ogg';
	else if ( lower.endsWith( '.mov' ) ) mimeType = 'video/quicktime';

	return (
		<div className="nvoos-pro-spa-video">
			<video controls className="nvoos-pro-spa-video-player" preload="metadata">
				<source src={ url } type={ mimeType } />
				<track kind="captions" />
				{ __( 'Your browser does not support the video tag.', 'nvoos-pro-spa' ) }
			</video>
			<a href={ url } className="nvoos-pro-spa-video-download" target="_blank" rel="noopener noreferrer">
				{ __( 'Download video', 'nvoos-pro-spa' ) }
			</a>
		</div>
	);
}

function ImageGalleryBlock( { urls }: { urls: string[] } ): JSX.Element {
	const displayUrls = urls.slice( 0, 10 );
	return (
		<div className="nvoos-pro-spa-gallery">
			{ displayUrls.map( ( url, idx ) => (
				<img
					key={ `${ url.slice( 0, 50 ) }-${ idx }` }
					src={ url }
					alt={ sprintf(
						/* translators: %d: image index */
						__( 'Attached image %d', 'nvoos-pro-spa' ),
						idx + 1
					) }
					className="nvoos-pro-spa-gallery-img"
					loading="lazy"
				/>
			) ) }
			{ urls.length > 10 && (
				<p className="nvoos-pro-spa-gallery-more">
					{ sprintf(
						/* translators: %d: number of additional images */
						__( '+%d more images', 'nvoos-pro-spa' ),
						urls.length - 10
					) }
				</p>
			) }
		</div>
	);
}

// ── Tool invocation card ───────────────────────────────────────────────────────

/** Render a single attachment with thumbnail, label, and download link. */
function AttachmentItem( { att }: { att: AttachmentEntry } ): JSX.Element {
	const isImg = att.isImage || ( att.mimeType?.startsWith( 'image/' ) ?? false ) || /^data:image\//i.test( att.url );
	const isVid = att.isVideo || ( att.mimeType?.startsWith( 'video/' ) ?? false );

	return (
		<div className="nvoos-pro-spa-tool-attachment">
			{ isImg ? (
				<a href={ att.url } target="_blank" rel="noopener noreferrer" className="nvoos-pro-spa-tool-attachment-thumb">
					<img src={ att.url } alt={ att.label } loading="lazy" />
				</a>
			) : isVid ? (
				<video controls className="nvoos-pro-spa-tool-attachment-video" preload="metadata" src={ att.url } />
			) : (
				<span className="nvoos-pro-spa-tool-attachment-icon" aria-hidden="true">📄</span>
			) }
			<div className="nvoos-pro-spa-tool-attachment-info">
				<a
					href={ att.url }
					target="_blank"
					rel="noopener noreferrer"
					download={ att.downloadName || undefined }
					className="nvoos-pro-spa-tool-attachment-label"
				>
					{ att.label }
				</a>
				{ att.meta && (
					<span className="nvoos-pro-spa-tool-attachment-meta">{ att.meta }</span>
				) }
			</div>
		</div>
	);
}

/** Render chart HTML in a sandboxed iframe. */
function ToolChart( { html, width = 600, height = 400, title }: { html: string; width?: number; height?: number; title?: string } ): JSX.Element {
	const blob = useMemo(
		() =>
			new Blob(
				[
					'<!DOCTYPE html><html><head><meta charset="utf-8">' +
					'<script src="https://cdn.jsdelivr.net/npm/chart.js@4"><\/script>' +
					'</head><body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh">' +
					html +
					'</body></html>',
				],
				{ type: 'text/html' }
			),
		[ html ]
	);
	const src = useMemo( () => URL.createObjectURL( blob ), [ blob ] );
	useEffect( () => () => URL.revokeObjectURL( src ), [ src ] );

	return (
		<div className="nvoos-pro-spa-tool-chart">
			{ title && <div className="nvoos-pro-spa-tool-chart-title">{ title }</div> }
			<iframe
				src={ src }
				width={ width }
				height={ height }
				className="nvoos-pro-spa-tool-chart-iframe"
				title={ __( 'Chart', 'nvoos-pro-spa' ) }
				sandbox="allow-scripts allow-same-origin"
			/>
		</div>
	);
}

function ToolCallCard( { invocation }: { invocation: ToolInvocation } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const isResult = invocation.state === 'result';

	// Normalise the result for structured display.
	const normalised: NormalisedToolResult | null = useMemo(
		() => ( isResult ? normaliseToolResult( invocation.toolName, invocation.result ) : null ),
		[ isResult, invocation.toolName, invocation.result ]
	);

	// Build the summary label.
	const summaryLabel = useMemo( () => {
		if ( ! isResult ) return __( 'running…', 'nvoos-pro-spa' );
		if ( normalised?.isAsyncPending ) return __( 'pending…', 'nvoos-pro-spa' );
		if ( normalised?.isError ) return __( 'error', 'nvoos-pro-spa' );
		return __( 'completed', 'nvoos-pro-spa' );
	}, [ isResult, normalised ] );

	const stateClass = normalised?.isError ? 'error' : normalised?.isAsyncPending ? 'pending' : invocation.state;

	return (
		<details
			className={ `nvoos-pro-spa-tool nvoos-pro-spa-tool--${ stateClass }` }
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
			data-tool-name={ invocation.toolName }
		>
			<summary>
				<span className="nvoos-pro-spa-tool-name">{ invocation.toolName }</span>
				<span className="nvoos-pro-spa-tool-state">{ summaryLabel }</span>
			</summary>

			{/* Arguments (always show if present) */}
			{ invocation.args !== undefined && Object.keys( invocation.args as object ).length > 0 && (
				<div className="nvoos-pro-spa-tool-block">
					<div className="nvoos-pro-spa-tool-block-label">
						{ __( 'Arguments', 'nvoos-pro-spa' ) }
					</div>
					<pre>{ safeStringify( invocation.args ) }</pre>
				</div>
			) }

			{/* Result */}
			{ isResult && normalised && (
				<>
					{/* Summary text */}
					{ normalised.text && ! normalised.isTruncated && (
						<div className="nvoos-pro-spa-tool-result-text">
							{ normalised.isError && (
								<span className="nvoos-pro-spa-tool-result-prefix" aria-hidden="true">⚠️ </span>
							) }
							{ normalised.isAsyncPending && (
								<span className="nvoos-pro-spa-tool-result-prefix" aria-hidden="true">⏳ </span>
							) }
							{ normalised.text }
						</div>
					) }

					{/* Chart */}
					{ normalised.chartHtml && (
						<ToolChart
							html={ normalised.chartHtml }
							width={ normalised.chartWidth }
							height={ normalised.chartHeight }
						/>
					) }

					{/* Attachments (images, videos, files) */}
					{ normalised.attachments.length > 0 && (
						<div className="nvoos-pro-spa-tool-attachments">
							{ normalised.attachments.map( ( att, i ) => (
								<AttachmentItem key={ `att-${ i }-${ att.url.slice( 0, 40 ) }` } att={ att } />
							) ) }
						</div>
					) }

					{/* Truncated content — show raw in a collapsible block */}
					{ normalised.isTruncated && (
						<details className="nvoos-pro-spa-tool-truncated">
							<summary className="nvoos-pro-spa-tool-truncated-summary">
								<span aria-hidden="true">{ '…' }</span>{ ' ' }
								{ __( 'Truncated result', 'nvoos-pro-spa' ) }
							</summary>
							<pre>{ safeStringify( invocation.result ) }</pre>
						</details>
					) }

					{/* Raw JSON fallback — always collapsible for in-depth inspection */}
					{ ! normalised.isTruncated && (
						<details className="nvoos-pro-spa-tool-raw">
							<summary className="nvoos-pro-spa-tool-raw-summary">
								{ __( 'Raw result', 'nvoos-pro-spa' ) }
							</summary>
							<pre>{ safeStringify( invocation.result ) }</pre>
						</details>
					) }
				</>
			) }

			{/* Still running — show a spinner placeholder */}
			{ ! isResult && (
				<div className="nvoos-pro-spa-tool-running">
					<span className="nvoos-pro-spa-tool-running-spinner" aria-hidden="true" />
					{ __( 'Executing tool…', 'nvoos-pro-spa' ) }
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
		const title = typeof ann.title === 'string' && ann.title ? ann.title : __( 'memory', 'nvoos-pro-spa' );
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
					return __( 'agent loop', 'nvoos-pro-spa' );
				case 'tool_start':
					return typeof frame.tool_name === 'string' ? ( frame.tool_name as string ) : __( 'tool start', 'nvoos-pro-spa' );
				case 'tool_result':
					return typeof frame.tool_name === 'string'
						? ( frame.tool_name as string )
						: __( 'tool result', 'nvoos-pro-spa' );
				default:
					return frame.type as string;
			}
		}
		// tool_results array items sometimes come through without the unknown wrapper
		if ( ann.name && typeof ann.name === 'string' ) {
			return ann.name;
		}
		return __( 'unknown', 'nvoos-pro-spa' );
	}

	if ( type === 'annotation' || type === '' ) {
		return typeof ann.name === 'string' ? ann.name : __( 'annotation', 'nvoos-pro-spa' );
	}

	// Data/completion frames (from SSE adapter data type)
	if ( type === 'data' ) {
		return __( 'response info', 'nvoos-pro-spa' );
	}

	return type;
}

function AnnotationPill( { annotation }: { annotation: Annotation } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const type = typeof annotation.type === 'string' ? annotation.type : 'annotation';
	const label = annotationLabel( annotation );

	return (
		<details
			className={ `nvoos-pro-spa-annotation nvoos-pro-spa-annotation--${ type }` }
			open={ open }
			onToggle={ ( e ) => setOpen( ( e.currentTarget as HTMLDetailsElement ).open ) }
		>
			<summary className="nvoos-pro-spa-annotation__summary">
				{ label }
			</summary>
			<pre className="nvoos-pro-spa-annotation__body">{ safeStringify( annotation ) }</pre>
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
	onEdit?: ( msgId: string ) => void;
	onRegenerate?: () => void;
	/** Speech (v0.9.0). */
	onSpeechPlay?: ( text: string ) => void;
	onSpeechStop?: () => void;
	speechStateFor?: ( text: string ) => SpeechState;
}

function MessageToolbar( {
	messageId,
	content,
	isAssistant,
	isLastAssistant,
	feedback,
	onDelete,
	onFeedback,
	onEdit,
	onRegenerate,
	onSpeechPlay,
	onSpeechStop,
	speechStateFor,
}: MessageToolbarProps ): JSX.Element {
	const { copy, copied: justCopied } = useCopyToClipboard();
	const [ saved, setSaved ] = useState( false );

	const handleCopy = useCallback( () => {
		void copy( content );
	}, [ copy, content ] );

	const handleSave = useCallback( () => {
		setSaved( ( prev ) => ! prev );
	}, [] );

	return (
		<div
			className="nvoos-pro-spa-message-view__actions"
			role="toolbar"
			aria-label={ __( 'Message actions', 'nvoos-pro-spa' ) }
		>
			<button
				type="button"
				className={ `nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-copy${ justCopied ? ' nvoos-pro-spa-toolbar-copy--success' : '' }` }
				aria-label={ justCopied ? __( 'Copied!', 'nvoos-pro-spa' ) : __( 'Copy message', 'nvoos-pro-spa' ) }
				title={ __( 'Copy message', 'nvoos-pro-spa' ) }
				onClick={ handleCopy }
			>
				{ justCopied ? '✓' : '📋' }
			</button>

			{ isAssistant && (
				<button
					type="button"
					className={ `nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-save${ saved ? ' nvoos-pro-spa-toolbar-save--active' : '' }` }
					aria-label={ saved ? __( 'Unsave message', 'nvoos-pro-spa' ) : __( 'Save message', 'nvoos-pro-spa' ) }
					title={ saved ? __( 'Saved', 'nvoos-pro-spa' ) : __( 'Save for later', 'nvoos-pro-spa' ) }
					onClick={ handleSave }
				>
					{ saved ? '🔖' : '🏷' }
				</button>
			) }

			{ isLastAssistant && onFeedback && (
				<>
					<button
						type="button"
						className={ `nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-feedback${ feedback === 'up' ? ' nvoos-pro-spa-toolbar-feedback--active' : '' }` }
						aria-label={ __( 'Thumbs up', 'nvoos-pro-spa' ) }
						title={ __( 'Helpful', 'nvoos-pro-spa' ) }
						onClick={ () => onFeedback( messageId, 'up' ) }
					>
						👍
					</button>
					<button
						type="button"
						className={ `nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-feedback${ feedback === 'down' ? ' nvoos-pro-spa-toolbar-feedback--active' : '' }` }
						aria-label={ __( 'Thumbs down', 'nvoos-pro-spa' ) }
						title={ __( 'Not helpful', 'nvoos-pro-spa' ) }
						onClick={ () => onFeedback( messageId, 'down' ) }
					>
						👎
					</button>
				</>
			) }

			{ isLastAssistant && onRegenerate && (
				<button
					type="button"
					className="nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-regen"
					aria-label={ __( 'Regenerate response', 'nvoos-pro-spa' ) }
					title={ __( 'Regenerate', 'nvoos-pro-spa' ) }
					onClick={ onRegenerate }
				>
					↻
				</button>
			) }

			{ onDelete && (
				<button
					type="button"
					className="nvoos-pro-spa-toolbar-btn nvoos-pro-spa-toolbar-delete"
					aria-label={ __( 'Delete message', 'nvoos-pro-spa' ) }
					title={ __( 'Delete message', 'nvoos-pro-spa' ) }
					onClick={ () => {
						// eslint-disable-next-line no-alert
						if ( window.confirm( __( 'Delete this message?', 'nvoos-pro-spa' ) ) ) {
							onDelete( messageId );
						}
					} }
				>
					🗑
				</button>
			) }

			{/* Speech (v0.9.0) */}
			{ isAssistant && content !== '' && onSpeechPlay && onSpeechStop && speechStateFor && (
				<SpeechButton
					text={ content }
					state={ speechStateFor( content ) }
					onPlay={ onSpeechPlay }
					onStop={ onSpeechStop }
				/>
			) }
		</div>
	);
}
