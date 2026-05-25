/**
 * AgentPanel — Main conversation view with streaming responses.
 *
 * Zed equivalent: Agent Panel.
 */

import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useParams } from 'react-router-dom';
import { useThreads } from '../../hooks/useThreads';
import { useMessagesStore } from '../../store/messagesStore';

export default function AgentPanel() {
	const { threadId } = useParams();
	const { activeThread, sendMessage, createThread } = useThreads(threadId);
	const messagesStore = useMessagesStore();
	const [input, setInput] = useState('');
	const [sending, setSending] = useState(false);
	const messagesEndRef = useRef(null);

	const threadMessages = messagesStore.getMessages(threadId);

	const scrollToBottom = () => {
		messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
	};

	useEffect(() => {
		scrollToBottom();
	}, [threadMessages.messages?.length]);

	const handleSend = useCallback(async () => {
		const content = input.trim();
		if (!content || sending) return;

		setInput('');
		setSending(true);

		try {
			await sendMessage(content);
		} catch (err) {
			// Error handled in stream.
		} finally {
			setSending(false);
		}
	}, [input, sending, sendMessage]);

	const handleKeyDown = (e) => {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			handleSend();
		}
	};

	if (!threadId) {
		return (
			<div className="nvoos-agent-panel nvoos-agent-panel--empty">
				<div className="nvoos-agent-panel__welcome">
					<h1>NV oOS</h1>
					<p>Select a thread or create a new one to get started.</p>
					<button onClick={createThread} className="nvoos-btn nvoos-btn--primary">
						New Thread
					</button>
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-agent-panel">
			<div className="nvoos-agent-panel__header">
				<h1>{activeThread?.title || 'Thread'}</h1>
			</div>

			<div className="nvoos-agent-panel__messages">
				{threadMessages.messages?.map((msg, i) => (
					<div key={msg.id || i} className={`nvoos-message nvoos-message--${msg.role}`}>
						<div className="nvoos-message__content">
							{msg.content || (msg.id === 'streaming' ? 'Thinking…' : '')}
						</div>
					</div>
				))}
				<div ref={messagesEndRef} />
			</div>

			<div className="nvoos-agent-panel__editor">
				<textarea
					value={input}
					onChange={(e) => setInput(e.target.value)}
					onKeyDown={handleKeyDown}
					placeholder="Type a message…"
					rows={2}
					disabled={sending}
					className="nvoos-agent-panel__input"
				/>
				<button
					onClick={handleSend}
					disabled={sending || !input.trim()}
					className="nvoos-btn nvoos-btn--primary"
				>
					{sending ? 'Sending…' : 'Send'}
				</button>
			</div>
		</div>
	);
}
