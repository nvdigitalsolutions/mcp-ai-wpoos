/**
 * ChatChannelsPage — the channel command center (Proposal 084 Phase 4,
 * W14 Chat Channels port).
 *
 * Consumes the existing mcp-ai-pro/v1/chat-channels REST: a filterable
 * conversation list, a thread panel per conversation, and a reply box.
 * Replaces the wp-admin Chat Channels menu's dashboard in the console.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import {
	ChatChannelsClient,
	type ChatConversation,
	type ChatMessage,
} from '../../api/chat-channels';

const CHANNEL_OPTIONS = [
	[ '', __( 'All Channels', 'nvoos-pro-spa' ) ],
	[ 'whatsapp', 'WhatsApp' ],
	[ 'telegram', 'Telegram' ],
	[ 'messenger', 'Messenger' ],
	[ 'google_chat', 'Google Chat' ],
	[ 'discord', 'Discord' ],
	[ 'apple_messages', 'Apple Messages' ],
	[ 'icloud', 'iCloud' ],
	[ 'outlook', 'Outlook' ],
] as const;

const STATUS_OPTIONS = [
	[ '', __( 'All Statuses', 'nvoos-pro-spa' ) ],
	[ 'new', __( 'New', 'nvoos-pro-spa' ) ],
	[ 'contacted', __( 'Contacted', 'nvoos-pro-spa' ) ],
	[ 'qualified', __( 'Qualified', 'nvoos-pro-spa' ) ],
	[ 'customer', __( 'Customer', 'nvoos-pro-spa' ) ],
	[ 'churned', __( 'Churned', 'nvoos-pro-spa' ) ],
	[ 'blocked', __( 'Blocked', 'nvoos-pro-spa' ) ],
] as const;

function formatWhen( timestamp: number ): string {
	if ( ! timestamp ) {
		return '—';
	}
	return new Date( timestamp * 1000 ).toLocaleString( 'en-US', {
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} );
}

export function ChatChannelsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const client = useMemo(
		() =>
			new ChatChannelsClient( {
				endpoint: runtime?.endpoints.chatChannels ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ]
	);

	const [ channel, setChannel ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ conversations, setConversations ] = useState< ChatConversation[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const [ selected, setSelected ] = useState< ChatConversation | null >( null );
	const [ messages, setMessages ] = useState< ChatMessage[] >( [] );
	const [ messagesLoading, setMessagesLoading ] = useState< boolean >( false );
	const [ replyText, setReplyText ] = useState( '' );
	const [ replyBusy, setReplyBusy ] = useState< boolean >( false );
	const [ notice, setNotice ] = useState< string | null >( null );

	const refresh = useCallback(
		( filters: { channel: string; status: string; search: string } ) => {
			if ( ! runtime?.endpoints.chatChannels ) {
				return;
			}
			setLoading( true );
			setError( null );
			const controller = new AbortController();
			client
				.conversations( filters, controller.signal )
				.then( ( envelope ) => {
					setConversations( envelope.items );
					setLoading( false );
				} )
				.catch( ( err: unknown ) => {
					setError(
						err instanceof Error
							? err.message
							: __( 'Failed to load conversations.', 'nvoos-pro-spa' )
					);
					setLoading( false );
				} );
			return () => controller.abort();
		},
		[ client, runtime ]
	);

	useEffect( () => {
		refresh( { channel: '', status: '', search: '' } );
	}, [ refresh ] );

	const openConversation = ( conversation: ChatConversation ): void => {
		setSelected( conversation );
		setMessages( [] );
		setNotice( null );
		setMessagesLoading( true );
		const controller = new AbortController();
		client
			.messages( conversation.id, controller.signal )
			.then( ( rows ) => {
				setMessages( rows );
				setMessagesLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError(
					err instanceof Error ? err.message : __( 'Failed to load messages.', 'nvoos-pro-spa' )
				);
				setMessagesLoading( false );
			} );
	};

	const sendReply = (): void => {
		if ( ! selected || ! replyText.trim() ) {
			return;
		}
		setReplyBusy( true );
		client
			.reply( selected.id, replyText.trim(), selected.connection_id )
			.then( () => {
				setReplyBusy( false );
				setReplyText( '' );
				setNotice( __( 'Reply sent.', 'nvoos-pro-spa' ) );
			} )
			.catch( ( err: unknown ) => {
				setReplyBusy( false );
				setError(
					err instanceof Error ? err.message : __( 'Failed to send the reply.', 'nvoos-pro-spa' )
				);
			} );
	};

	const listRef = useRef< HTMLDivElement >( null );

	const conversationColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'contact',
				label: __( 'Contact', 'nvoos-pro-spa' ),
				width: 'minmax(220px, 1.2fr)',
			},
			{ key: 'channel', label: __( 'Channel', 'nvoos-pro-spa' ), width: '130px' },
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '120px' },
			{
				key: 'last',
				label: __( 'Last message', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(160px, 0.8fr)',
			},
		],
		[]
	);

	const conversationRows = useMemo< DataTableRow[] >(
		() =>
			conversations.map( ( conversation ) => ( {
				id: conversation.id,
				cells: [
					<button
						type="button"
						className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
						aria-expanded={ selected?.id === conversation.id }
						onClick={ () => openConversation( conversation ) }
					>
						{ conversation.display_name ||
							conversation.phone_number ||
							conversation.email }
					</button>,
					<span className="nvoos-pro-spa-tools-list__badge">
						{ conversation.channel }
					</span>,
					<span className="nvoos-pro-spa-tools-list__badge">
						{ conversation.crm_status }
					</span>,
					formatWhen( conversation.last_message_at ),
				],
			} ) ),
		[ conversations, selected, openConversation ]
	);

	const messageColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'direction', label: __( 'Direction', 'nvoos-pro-spa' ), width: '110px' },
			{
				key: 'content',
				label: __( 'Message', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(280px, 1fr)',
			},
		],
		[]
	);

	const messageRows = useMemo< DataTableRow[] >(
		() =>
			messages.map( ( message, index ) => ( {
				id: message.id ?? index,
				cells: [
					<span className="nvoos-pro-spa-tools-list__badge">
						{ typeof message.direction === 'string' ? message.direction : '' }
					</span>,
					String( message.content ?? '' ),
				],
			} ) ),
		[ messages ]
	);

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Channels', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Channels', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Channels', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Conversations across WhatsApp, Telegram and the other connected channels.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			<form
				className="nvoos-pro-spa-records-search"
				onSubmit={ ( event ) => {
					event.preventDefault();
					refresh( { channel, status, search } );
				} }
			>
				<label className="nvoos-pro-spa-records-search__label">
					{ __( 'Channel', 'nvoos-pro-spa' ) }
					{ ' ' }
					<select
						className="nvoos-pro-spa-records-search__input"
						value={ channel }
						onChange={ ( event ) => setChannel( event.target.value ) }
					>
						{ CHANNEL_OPTIONS.map( ( [ value, label ] ) => (
							<option key={ value } value={ value }>
								{ label }
							</option>
						) ) }
					</select>
				</label>
				<label className="nvoos-pro-spa-records-search__label">
					{ __( 'Status', 'nvoos-pro-spa' ) }
					{ ' ' }
					<select
						className="nvoos-pro-spa-records-search__input"
						value={ status }
						onChange={ ( event ) => setStatus( event.target.value ) }
					>
						{ STATUS_OPTIONS.map( ( [ value, label ] ) => (
							<option key={ value } value={ value }>
								{ label }
							</option>
						) ) }
					</select>
				</label>
				<label className="nvoos-pro-spa-records-search__label" htmlFor="channels-search">
					{ __( 'Search', 'nvoos-pro-spa' ) }
				</label>
				<input
					id="channels-search"
					type="search"
					className="nvoos-pro-spa-records-search__input"
					value={ search }
					onChange={ ( event ) => setSearch( event.target.value ) }
				/>
				<button type="submit" className="nvoos-pro-spa-page__retry" disabled={ loading }>
					{ loading ? __( 'Loading…', 'nvoos-pro-spa' ) : __( 'Apply', 'nvoos-pro-spa' ) }
				</button>
			</form>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => refresh( { channel, status, search } ) }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ loading && conversations.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'Loading conversations…', 'nvoos-pro-spa' ) }</p>
				</div>
			) : ! error && conversations.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No conversations found.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<DataTable
					columns={ conversationColumns }
					rows={ conversationRows }
					caption={ __( 'Conversations', 'nvoos-pro-spa' ) }
				/>
			) }

			{ selected && (
				<section
					className="nvoos-pro-spa-records-detail"
					aria-label={ __( 'Conversation', 'nvoos-pro-spa' ) }
					ref={ listRef }
				>
					<h3 className="nvoos-pro-spa-records-detail__title">
						{ selected.display_name || selected.phone_number || selected.email }
					</h3>
					<p className="nvoos-pro-spa-page__subtitle">
						{ selected.channel } · { selected.conversation_type } · { selected.crm_status }
					</p>

					{ messagesLoading ? (
						<p className="nvoos-pro-spa-page__subtitle">{ __( 'Loading messages…', 'nvoos-pro-spa' ) }</p>
					) : messages.length === 0 ? (
						<p className="nvoos-pro-spa-page__subtitle">{ __( 'No messages yet.', 'nvoos-pro-spa' ) }</p>
					) : (
						<DataTable
							columns={ messageColumns }
							rows={ messageRows }
							caption={ __( 'Messages', 'nvoos-pro-spa' ) }
							hideHeader
						/>
					) }

					{ notice && (
						<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--success" role="status">
							{ notice }
						</p>
					) }

					<form
						className="nvoos-pro-spa-records-search"
						onSubmit={ ( event ) => {
							event.preventDefault();
							sendReply();
						} }
					>
						<label className="nvoos-pro-spa-records-search__label" htmlFor="channels-reply">
							{ __( 'Reply', 'nvoos-pro-spa' ) }
						</label>
						<textarea
							id="channels-reply"
							className="nvoos-pro-spa-records-search__input"
							rows={ 3 }
							value={ replyText }
							onChange={ ( event ) => setReplyText( event.target.value ) }
						/>
						<button type="submit" className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary" disabled={ replyBusy }>
							{ replyBusy ? __( 'Sending…', 'nvoos-pro-spa' ) : __( 'Send', 'nvoos-pro-spa' ) }
						</button>
					</form>
				</section>
			) }
		</div>
	);
}
