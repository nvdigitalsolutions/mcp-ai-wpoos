/**
 * useAssistant – AI Shopping Assistant hook
 *
 * Manages a multi-turn conversation with the AI assistant. Streaming is not
 * required here since we're in a Mini App context – we post the full message
 * history and receive a complete reply.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useCallback } from 'react';
import { sendChat } from '../api/client';

/** @typedef {{ role:'user'|'assistant', content:string }} Message */

/**
 * @return {{ messages:Message[], send:Function, loading:boolean, error:string|null, clearHistory:Function }}
 */
export function useAssistant() {
	const [ messages, setMessages ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	/**
	 * Send a user message and append the assistant reply.
	 *
	 * @param {string} text User message text.
	 */
	const send = useCallback( async ( text ) => {
		if ( ! text.trim() || loading ) {
			return;
		}
		const userMsg = { role: 'user', content: text.trim() };
		const nextMessages = [ ...messages, userMsg ];
		setMessages( nextMessages );
		setLoading( true );
		setError( null );

		try {
			// Keep last 20 messages for context window efficiency.
			const data = await sendChat( nextMessages.slice( -20 ) );
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
	}, [ messages, loading ] );

	const clearHistory = useCallback( () => setMessages( [] ), [] );

	return { messages, send, loading, error, clearHistory };
}
