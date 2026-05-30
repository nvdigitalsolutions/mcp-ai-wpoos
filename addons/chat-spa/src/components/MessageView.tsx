/**
 * NV oOS Chat SPA — single message renderer.
 *
 * Renders three layers per message:
 *   1. Markdown → HTML content (`m.content`) — parsed through marked + DOMPurify
 *      for XSS-safe rich-text display.
 *   2. Tool-invocation cards (`m.toolInvocations`) — populated automatically
 *      by `useChat` from the AI SDK Data Stream `9:` tool_call and `a:`
 *      tool_result chunks emitted by `../sse-adapter.ts`.
 *   3. Annotation pills (`m.annotations`) — memory events and unknown
 *      frames forwarded as `8:` message_annotations.
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useMemo, useState } from 'react';
import { renderMarkdown } from '../api/markdown';

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

interface MessageViewProps {
	message: ChatMessage;
}

export function MessageView( { message }: MessageViewProps ): JSX.Element {
	const tools = Array.isArray( message.toolInvocations ) ? message.toolInvocations : [];
	const annotations = Array.isArray( message.annotations ) ? message.annotations : [];

	return (
		<div className={ `nvoos-chat-spa-message nvoos-chat-spa-message--${ message.role }` }>
			<span className="nvoos-chat-spa-role">{ message.role }</span>

			{ typeof message.content === 'string' && message.content !== '' && (
				<SafeMarkdownContent text={ message.content } />
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
							/* Annotations have no stable id; index is acceptable
							   here because the array is append-only within a turn. */
							key={ `${ message.id }-ann-${ idx }` }
							annotation={ ann }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

/**
 * XSS-safe markdown rendering wrapper.
 *
 * Runs `renderMarkdown` (marked → DOMPurify) inside `useMemo` keyed on the
 * raw text so expensive parsing is only repeated when the content changes.
 * The sanitised HTML is then set via `dangerouslySetInnerHTML` — safe
 * because DOMPurify strips every tag and attribute not on the allowlist.
 */
function SafeMarkdownContent( { text }: { text: string } ): JSX.Element {
	const html = useMemo( () => renderMarkdown( text ), [ text ] );

	return (
		<div
			className="nvoos-chat-spa-content"
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}

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

function AnnotationPill( { annotation }: { annotation: Annotation } ): JSX.Element {
	const type = typeof annotation.type === 'string' ? annotation.type : 'annotation';
	return (
		<span
			className={ `nvoos-chat-spa-annotation nvoos-chat-spa-annotation--${ type }` }
			title={ safeStringify( annotation ) }
		>
			{ type }
		</span>
	);
}

function safeStringify( value: unknown ): string {
	try {
		return JSON.stringify( value, null, 2 ) ?? '';
	} catch {
		return String( value );
	}
}
