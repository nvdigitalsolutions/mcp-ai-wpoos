/**
 * MessageView — Renders a single chat message with role badge,
 * markdown content, and an action toolbar (copy, regenerate).
 */

import { useCallback, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { type Message } from '@ai-sdk/react';
import { MarkdownContent } from '../../components/shared/MarkdownContent';
import { useCopyToClipboard } from '../../hooks/useCopyToClipboard';

export interface MessageViewProps {
	/** The chat message to render. */
	message: Message;
	/** Whether this is the last message in the list. */
	isLast: boolean;
	/** Whether this message is the last assistant message. */
	isLastAssistant: boolean;
	/** Callback to regenerate the last assistant response. */
	onRegenerate?: () => void;
	/** Whether a response is actively streaming. */
	isStreaming: boolean;
}

function messageContent( message: Message ): string {
	if ( typeof message.content === 'string' ) {
		return message.content;
	}
	if ( Array.isArray( message.content ) ) {
		return ( message.content as unknown[] )
			.map( ( part ) => {
				if ( typeof part === 'string' ) {
					return part;
				}
				if ( part && typeof part === 'object' && 'text' in part ) {
					return String( ( part as unknown as { text: string } ).text );
				}
				if ( part && typeof part === 'object' && 'type' in part && part.type === 'text' ) {
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
		case 'user':
			return __( 'You', 'nvoos-pro-spa' );
		case 'assistant':
			return __( 'Assistant', 'nvoos-pro-spa' );
		case 'system':
			return __( 'System', 'nvoos-pro-spa' );
		default:
			return role || __( 'Unknown', 'nvoos-pro-spa' );
	}
}

export function MessageView( props: MessageViewProps ): JSX.Element {
	const { message, isLast, isLastAssistant, onRegenerate, isStreaming } = props;
	const { copied, copy } = useCopyToClipboard();

	const content = messageContent( message );
	const role = message.role;
	const label = roleLabel( role );

	const handleCopy = useCallback( () => {
		if ( content ) {
			void copy( content );
		}
	}, [ content, copy ] );

	const canRegenerate =
		role === 'assistant' && isLastAssistant && ! isStreaming && typeof onRegenerate === 'function';

	return (
		<article
			className={ [
				'nvoos-pro-spa-message-view',
				`nvoos-pro-spa-message-view--${ role }`,
				isLast ? 'nvoos-pro-spa-message-view--last' : '',
				isStreaming && isLast ? 'nvoos-pro-spa-message-view--streaming' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			aria-label={ sprintf(
				/* translators: %s: message role label */
				__( 'Message from %s', 'nvoos-pro-spa' ),
				label
			) }
		>
			{/* Role badge */}
			<div className="nvoos-pro-spa-message-view__header">
				<span
					className={ [
						'nvoos-pro-spa-message-view__badge',
						`nvoos-pro-spa-message-view__badge--${ role }`,
					].join( ' ' ) }
					aria-hidden="true"
				>
					{ role === 'assistant' ? '🤖' : role === 'user' ? '👤' : '📋' }
				</span>
				<span className="nvoos-pro-spa-message-view__role">
					{ label }
				</span>
				{ isStreaming && isLast && role === 'assistant' && (
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
					<MarkdownContent
						content={ content }
						className="nvoos-pro-spa-message-view__markdown"
					/>
				) : (
					isStreaming &&
					isLast &&
					role === 'assistant' && (
						<p className="nvoos-pro-spa-message-view__thinking">
							{ __( 'Thinking…', 'nvoos-pro-spa' ) }
						</p>
					)
				) }
			</div>

			{/* Action toolbar */}
			{ content && ! isStreaming && (
				<div
					className="nvoos-pro-spa-message-view__actions"
					role="toolbar"
					aria-label={ __( 'Message actions', 'nvoos-pro-spa' ) }
				>
					<button
						type="button"
						className="nvoos-pro-spa-message-view__action-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
						onClick={ handleCopy }
						aria-label={
							copied
								? __( 'Copied to clipboard', 'nvoos-pro-spa' )
								: __( 'Copy message to clipboard', 'nvoos-pro-spa' )
						}
						disabled={ copied }
					>
						{ copied
							? __( 'Copied!', 'nvoos-pro-spa' )
							: __( 'Copy', 'nvoos-pro-spa' ) }
					</button>

					{ canRegenerate && (
						<button
							type="button"
							className="nvoos-pro-spa-message-view__action-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
							onClick={ onRegenerate }
							aria-label={ __( 'Regenerate response', 'nvoos-pro-spa' ) }
						>
							{ __( 'Regenerate', 'nvoos-pro-spa' ) }
						</button>
					) }
				</div>
			) }
		</article>
	);
}
