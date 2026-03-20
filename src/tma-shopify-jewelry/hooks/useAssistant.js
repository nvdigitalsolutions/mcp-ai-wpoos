/**
 * useAssistant – AI Jewelry Assistant hook
 *
 * Manages a multi-turn conversation with the AI assistant. Posts the full
 * message history and receives a complete reply.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useCallback } from '@wordpress/element';
import { sendChat } from '../api/client';

/** @typedef {{ role:'user'|'assistant', content:string }} Message */

/**
 * @return {{ messages:Message[], send:Function, loading:boolean, error:string|null, clearHistory:Function }}
 */
export function useAssistant() {
	const [ messages, setMessages ] = useState( [] );
	const [ loading, setLoading ]   = useState( false );
	const [ error, setError ]       = useState( null );

	// Limit history sent to the AI to keep context windows efficient.
	const MAX_CONVERSATION_HISTORY = 20;

	const send = useCallback( async ( text ) => {
		if ( ! text.trim() || loading ) {
			return;
		}
		const userMsg = { role: 'user', content: text.trim() };

		// Capture the latest messages and add the user turn using the functional
		// updater so this callback does not depend on the `messages` state value
		// directly (avoids stale-closure bugs on rapid sends).
		let toSend;
		setMessages( ( prev ) => {
			const next = [ ...prev, userMsg ];
			toSend = next.slice( -MAX_CONVERSATION_HISTORY );
			return next;
		} );
		setLoading( true );
		setError( null );

		try {
			const data  = await sendChat( toSend );
			const reply = data?.reply ?? data?.message ?? data?.choices?.[ 0 ]?.message?.content ?? '';
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: reply },
			] );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setLoading( false );
		}
	}, [ loading ] );

	const clearHistory = useCallback( () => setMessages( [] ), [] );

	return { messages, send, loading, error, clearHistory };
}
