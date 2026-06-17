/**
 * AgentPanel — Main conversation view with streaming responses,
 * profile/model selectors, checkpoints, and @-mention context.
 *
 * Zed equivalent: Agent Panel.
 */

import { useState, useCallback, useRef, useEffect, useContext } from '@wordpress/element';
import { useParams } from 'react-router-dom';
import { useThreads } from '../../hooks/useThreads';
import { useConversations } from '../../hooks/useConversations';
import { TranscriptContext } from '../../hooks/TranscriptContext';
import { useMessagesStore } from '../../store/messagesStore';
import { useModelStore } from '../../store/modelStore';
import { useProfilesStore } from '../../store/profilesStore';
import { useSettingsStore } from '../../store/settingsStore';
import { useCheckpoints } from '../../hooks/useCheckpoints';
import { useContextMentions } from '../../hooks/useContextMentions';
import ProfileSelector from '../profiles/ProfileSelector';
import ModelSelector from '../profiles/ModelSelector';
import CheckpointBar from '../checkpoints/CheckpointBar';
import DiffReviewPanel from '../checkpoints/DiffReviewPanel';
import ContextMention from '../context/ContextMention';
import MentionChip from '../context/MentionChip';
import ModelComparisonView from '../models/ModelComparisonView';

export default function AgentPanel() {
	const { threadId } = useParams();
	const { activeThread, createThread } = useThreads(threadId);
	const { sendMessage, getMessages, clearMessages } = useConversations();
	const messagesStore = useMessagesStore();
	const { model } = useModelStore();
	const { activeProfile } = useProfilesStore();
	const { selectedAssistantId } = useSettingsStore();
	const { lastCheckpoint, diff, fetchCheckpoints, restoreCheckpoint, fetchDiff, clearDiff } = useCheckpoints(threadId);
	const transcripts = useContext(TranscriptContext);
	const mentionCtx = useContextMentions();

	const [input, setInput] = useState('');
	const [sending, setSending] = useState(false);
	const [mentions, setMentions] = useState([]);
	const [showDiff, setShowDiff] = useState(false);
	const [showCompare, setShowCompare] = useState(false);
	const [compareMessage, setCompareMessage] = useState('');
	const messagesEndRef = useRef(null);
	const textareaRef = useRef(null);

	// Thread messages (read-only historical view) or conversation messages (active chat).
	const threadMessages = threadId ? messagesStore.getMessages(threadId) : getMessages();

	const scrollToBottom = () => {
		messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
	};

	useEffect(() => {
		scrollToBottom();
	}, [threadMessages.messages?.length]);

	// Fetch checkpoints when thread changes.
	useEffect(() => {
		if (threadId) {
			fetchCheckpoints();
		}
	}, [threadId, fetchCheckpoints]);
	// Load transcript initialMessages into the store when session changes.
	useEffect(() => {
		if (transcripts && transcripts.initialMessages.length > 0 && !threadId) {
			// Seed the conversation buffer with saved messages (all roles).
			messagesStore.setMessages(
				'__conversation__',
				transcripts.initialMessages.map((m, idx) => ({
					role: m.role || 'assistant',
					content: typeof m.content === 'string' ? m.content : '',
					id: `transcript-${transcripts.sessionKey}-${idx}`,
				})),
				transcripts.initialMessages.length
			);
		}
	}, [transcripts?.initialMessages, transcripts?.sessionKey]);

	// @-mention detection on input change.
	const handleInputChange = useCallback((e) => {
		const value = e.target.value;
		setInput(value);

		// Detect @ trigger.
		const cursorPos = e.target.selectionStart;
		const textBeforeCursor = value.slice(0, cursorPos);
		const atMatch = textBeforeCursor.match(/@([a-z_]*:?\S*)$/i);

		if (atMatch) {
			mentionCtx.search(atMatch[1]);
		} else if (!atMatch && mentionCtx.visible) {
			mentionCtx.close();
		}
	}, [mentionCtx]);

	// Handle mention selection.
	const handleMentionSelect = useCallback((mention) => {
		// Replace the @query text in the input with the mention chip text.
		const cursorPos = textareaRef.current?.selectionStart || input.length;
		const textBeforeCursor = input.slice(0, cursorPos);
		const atIdx = textBeforeCursor.lastIndexOf('@');

		if (atIdx >= 0) {
			const before = input.slice(0, atIdx);
			const after = input.slice(cursorPos);
			const mentionText = `@${mention.type}:${mention.title || mention.id}`;
			setInput(before + mentionText + ' ' + after);
		}

		setMentions((prev) => [...prev, mention]);
		mentionCtx.close();
	}, [input, mentionCtx]);

	const handleKeyDown = (e) => {
		// Handle @-mention navigation.
		if (mentionCtx.visible) {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				mentionCtx.selectNext();
				return;
			}
			if (e.key === 'ArrowUp') {
				e.preventDefault();
				mentionCtx.selectPrev();
				return;
			}
			if (e.key === 'Enter' || e.key === 'Tab') {
				const selected = mentionCtx.getSelected();
				if (selected) {
					e.preventDefault();
					handleMentionSelect(selected);
					return;
				}
			}
			if (e.key === 'Escape') {
				mentionCtx.close();
				return;
			}
		}

		// Send on Enter (without Shift).
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			handleSend();
		}
	};

	const handleSend = useCallback(async () => {
		const content = input.trim();
		if (!content || sending) return;

		setInput('');
		setSending(true);
		const currentMentions = [...mentions];
		setMentions([]);

		try {
			await sendMessage(content, currentMentions, { assistantId: selectedAssistantId });
			// After turn completes, save conversation transcript.
			if (transcripts) {
				const msgs = getMessages().messages || [];
				const wireMessages = msgs.map(m => ({
					role: m.role,
					content: typeof m.content === 'string' ? m.content : '',
				}));
				transcripts.saveTranscript(wireMessages, { finish_reason: 'stop', source: 'pro-spa' });
			}
		} catch (err) {
			// Error handled in stream.
		} finally {
			setSending(false);
		}
	}, [input, sending, sendMessage, mentions]);

	const handleRestore = useCallback(async (checkpointId) => {
		if (confirm('Restore to this checkpoint? Changes made after will be lost.')) {
			await restoreCheckpoint(checkpointId);
		}
	}, [restoreCheckpoint]);

	const handleReview = useCallback(async (checkpointId) => {
		await fetchDiff(checkpointId);
		setShowDiff(true);
	}, [fetchDiff]);

	// Remove a mention chip.
	const removeMention = useCallback((mention) => {
		setMentions((prev) => prev.filter((m) => m.id !== mention.id || m.type !== mention.type));
	}, []);

	// Compare models for the current input.
	const handleCompare = useCallback(() => {
		const content = input.trim();
		if (!content) return;
		setCompareMessage(content);
		setShowCompare(true);
	}, [input]);

	// Handle model comparison selection — insert response into input.
	const handleCompareSelect = useCallback((result) => {
		setInput(result.content);
	}, []);

	return (
		<div className="nvoos-agent-panel">
			{/* Header with title, model, and profile selectors */}
			<div className="nvoos-agent-panel__header">
				<h1 className="nvoos-agent-panel__title">
					{activeThread?.title || 'Conversation'}
					{activeThread && (
						<span className="nvoos-agent-panel__badge" title="This thread is read-only. New messages are sent via the conversation transport.">
							Read-only
						</span>
					)}
				</h1>
				<div className="nvoos-agent-panel__controls">
					<ModelSelector />
					<ProfileSelector onProfileChange={(name) => {
						// Profile change could update thread via API.
					}} />
				</div>
			</div>

			{/* Messages � read-only when browsing a thread. */}
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

			{/* Checkpoint bar — shows after agent finishes editing */}
			{lastCheckpoint && (
				<CheckpointBar
					checkpoint={lastCheckpoint}
					onRestore={handleRestore}
					onReview={handleReview}
				/>
			)}

			{/* Mention chips */}
			{mentions.length > 0 && (
				<div className="nvoos-agent-panel__mentions">
					{mentions.map((m, i) => (
						<MentionChip key={`${m.type}-${m.id}-${i}`} mention={m} onRemove={removeMention} />
					))}
				</div>
			)}

			{/* Message editor with @-mention support */}
			<div className="nvoos-agent-panel__editor">
				<div className="nvoos-agent-panel__input-wrap">
					<textarea
						ref={textareaRef}
						value={input}
						onChange={handleInputChange}
						onKeyDown={handleKeyDown}
						placeholder="Type a message… (use @ to mention posts, tools, files, etc.)"
						rows={2}
						disabled={sending}
						className="nvoos-agent-panel__input"
					/>
					{/* @-mention popover */}
					<ContextMention
						results={mentionCtx.results}
						visible={mentionCtx.visible}
						selectedIndex={mentionCtx.selectedIndex}
						onSelect={handleMentionSelect}
						onClose={mentionCtx.close}
					/>
				</div>
				<button
					onClick={handleSend}
					disabled={sending || !input.trim()}
					className="nvoos-btn nvoos-btn--primary"
				>
					{sending ? 'Sending…' : 'Send'}
				</button>
				<button
					onClick={handleCompare}
					disabled={!input.trim()}
					className="nvoos-btn nvoos-btn--compare"
					title="Compare response from multiple models"
				>
					Compare
				</button>
			</div>

			{/* Diff review modal */}
			{showDiff && diff && (
				<div className="nvoos-diff-overlay">
					<div className="nvoos-diff-overlay__content">
						<DiffReviewPanel
							diff={diff}
							onClose={() => { setShowDiff(false); clearDiff(); }}
						/>
					</div>
				</div>
			)}

			{/* Model comparison modal */}
			{showCompare && (
				<div className="nvoos-diff-overlay">
					<div className="nvoos-diff-overlay__content nvoos-diff-overlay__content--wide">
						<ModelComparisonView
							threadId={threadId}
							message={compareMessage}
							onClose={() => setShowCompare(false)}
							onSelect={handleCompareSelect}
						/>
					</div>
				</div>
			)}
		</div>
	);
}
