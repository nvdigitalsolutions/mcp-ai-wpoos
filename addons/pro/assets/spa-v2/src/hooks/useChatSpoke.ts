/**
 * useChatSpoke — Wraps `useChat` from `@ai-sdk/react` with the pro SSE adapter.
 *
 * This is the core chat hook that replaces the legacy messagesStore + sse.js.
 */

import { useMemo, useCallback, useRef, useEffect, type RefObject } from 'react';
import { useChat, type Message } from '@ai-sdk/react';
import { createChatFetch } from '../sse-adapter';
import {
	TranscriptsClient,
	type TranscriptMessage,
} from '../api/transcripts';
import { useUIStore } from '../stores/uiStore';

export interface UseChatSpokeOptions {
	chatClientEndpoint: string;
	nonce: string;
	assistantId: number;
	transcriptsEndpoint?: string;
	initialMessages?: Message[];
	sessionKey?: string;
	/** Optional model override to send as options.provider / options.model. */
	model?: string;
	provider?: string;
}

export interface UseChatSpokeReturn {
	messages: Message[];
	input: string;
	handleInputChange: ( e: React.ChangeEvent< HTMLTextAreaElement > | string ) => void;
	handleSubmit: ( e?: { preventDefault?: () => void } ) => void;
	status: 'submitted' | 'streaming' | 'ready' | 'error';
	error: Error | undefined;
	stop: () => void;
	reload: () => void;
	setMessages: ( messages: Message[] ) => void;
	isStreaming: boolean;
	fileInputRef: RefObject< HTMLInputElement | null >;
	sendMessage: ( content: string ) => void;
}

export function useChatSpoke( options: UseChatSpokeOptions ): UseChatSpokeReturn {
	const {
		chatClientEndpoint,
		nonce,
		assistantId,
		transcriptsEndpoint,
		initialMessages = [],
		sessionKey = '',
		model,
		provider,
	} = options;

	const addToast = useUIStore( ( s ) => s.addToast );

	const transcriptsClient = useMemo(
		() =>
			transcriptsEndpoint && sessionKey
				? new TranscriptsClient( {
						endpoint: transcriptsEndpoint,
						nonce,
						assistantId,
				  } )
				: null,
		[ transcriptsEndpoint, nonce, assistantId, sessionKey ]
	);

	const customFetch = useMemo(
		() =>
			createChatFetch( {
				endpoint: chatClientEndpoint,
				nonce,
				assistantId,
				guest: false,
				model,
				provider,
			} ),
		[ chatClientEndpoint, nonce, assistantId, model, provider ]
	);

	const persistFinishedTurn = useCallback(
		async ( messages: Message[] ) => {
			if ( ! transcriptsClient || ! sessionKey ) {
				return;
			}
			try {
				const wireMessages: TranscriptMessage[] = messages.map( ( m ) => ( {
					role: m.role,
					content: typeof m.content === 'string' ? m.content : '',
				} ) );
				await transcriptsClient.save( sessionKey, wireMessages, {
					finish_reason: 'stop',
					source: 'pro-spa-v2',
				} );
			} catch {
				// Transcript persistence is best-effort.
			}
		},
		[ transcriptsClient, sessionKey ]
	);

	const {
		messages,
		input,
		handleInputChange: chatHandleInputChange,
		handleSubmit: chatHandleSubmit,
		status,
		error,
		stop,
		reload,
		setMessages,
	} = useChat( {
		id: `pro-spa-${ assistantId }-${ sessionKey }`,
		api: chatClientEndpoint,
		fetch: customFetch,
		streamProtocol: 'data',
		initialMessages,
		onFinish: ( message ) => {
			// Persist full turn after completion.
			// Use the ref to avoid the stale-closure problem — `messages`
			// inside this callback is captured at construction time.
			const priorMessages = messagesRef.current ?? [];
			const last = priorMessages[ priorMessages.length - 1 ];
			const alreadyPresent =
				last && message.id && last.id === message.id;
			const updated = alreadyPresent
				? priorMessages
				: [ ...priorMessages, message ];
			void persistFinishedTurn( updated );
		},
		onError: ( err ) => {
			addToast( err.message || 'Chat error', 'error' );
		},
	} );

	// Keep a ref to the current message list so `onFinish` (which is
	// captured at construction time) can read the latest array.
	const messagesRef = useRef< Message[] >( messages );
	useEffect( () => {
		messagesRef.current = messages;
	}, [ messages ] );

	const fileInputRef = useRef< HTMLInputElement | null >( null );

	const handleInputChange = useCallback(
		( e: React.ChangeEvent< HTMLTextAreaElement > | string ) => {
			if ( typeof e === 'string' ) {
				chatHandleInputChange( { target: { value: e } } as React.ChangeEvent< HTMLInputElement > );
			} else {
				chatHandleInputChange( e );
			}
		},
		[ chatHandleInputChange ]
	);

	const handleSubmit = useCallback(
		( e?: { preventDefault?: () => void } ) => {
			e?.preventDefault?.();
			chatHandleSubmit();
		},
		[ chatHandleSubmit ]
	);

	const sendMessage = useCallback(
		( content: string ) => {
			// Direct send: set input value and trigger submit.
			chatHandleInputChange( { target: { value: content } } as React.ChangeEvent< HTMLInputElement > );
			// Use setTimeout to ensure the input change has propagated.
			setTimeout( () => {
				chatHandleSubmit();
			}, 0 );
		},
		[ chatHandleInputChange, chatHandleSubmit ]
	);

	return {
		messages,
		input,
		handleInputChange,
		handleSubmit,
		status,
		error,
		stop,
		reload,
		setMessages,
		isStreaming: status === 'streaming' || status === 'submitted',
		fileInputRef,
		sendMessage,
	};
}
