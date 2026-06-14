/**
 * ChatSidebar — Left sidebar with assistant list, Conversations, and Threads.
 *
 * Selecting an assistant scopes both the Conversations tab (transcripts
 * filtered by assistant_id) and the Threads tab (client-side filter).
 */

import { useState } from '@wordpress/element';
import { useNavigate, useParams } from 'react-router-dom';
import { useThreadsStore } from '../../store/threadsStore';
import { useModelStore } from '../../store/modelStore';
import { useProfilesStore } from '../../store/profilesStore';
import { useSettingsStore } from '../../store/settingsStore';

export default function ChatSidebar({
	sessions,
	activeSessionKey,
	unavailableMessage,
	error,
	sessionsLoading,
	onSelectSession,
	onDeleteSession,
	onNewSession,
}) {
	const navigate = useNavigate();
	const { threadId } = useParams();
	const { threads, activeThreadId, setActiveThread, createThread } = useThreadsStore();
	const { model } = useModelStore();
	const { activeProfile } = useProfilesStore();
	const { settings, selectedAssistantId, setAssistantId } = useSettingsStore();

	const assistants = settings?.assistants || [];
	const [tab, setTab] = useState('conversations');

	// Filter threads by selected assistant (client-side).
	const filteredThreads = selectedAssistantId
		? threads.filter((t) => t.assistant_id === selectedAssistantId)
		: threads;

	const handleSelectThread = (id) => {
		setActiveThread(id);
		navigate(`/chat/${id}`);
	};

	const handleNewThread = async () => {
		try {
			const thread = await createThread(selectedAssistantId || 0, model, activeProfile, {});
			navigate(`/chat/${thread.id}`);
		} catch {
			// Error handled inline.
		}
	};

	// Group filtered threads by scope.
	const threadGroups = {};
	filteredThreads.forEach((t) => {
		const scope = t.scope_type || 'General';
		if (!threadGroups[scope]) threadGroups[scope] = [];
		threadGroups[scope].push(t);
	});

	const formatStamp = (raw) => {
		const time = Date.parse(raw);
		if (Number.isFinite(time)) {
			try { return new Date(time).toLocaleString(); } catch { /* fall through */ }
		}
		return raw;
	};

	return (
		<aside className="nvoos-threads-sidebar">
			{/* ── Assistant List ─────────────────────────────────────────── */}
			{assistants.length > 0 && (
				<div className="nvoos-chat-sidebar-assistants">
					{assistants.map((a) => {
						const id = a.id || a.ID;
						const name = a.name || a.post_title || `Assistant #${id}`;
						const isActive = id === selectedAssistantId;
						return (
							<button
								key={id}
								type="button"
								className={`nvoos-chat-sidebar-assistant ${isActive ? 'nvoos-chat-sidebar-assistant--active' : ''}`}
								onClick={() => setAssistantId(isActive ? 0 : id)}
								title={isActive ? 'Show all assistants' : `Filter by ${name}`}
							>
								<span className="nvoos-chat-sidebar-assistant-icon" aria-hidden="true">
									{isActive ? '●' : '○'}
								</span>
								<span className="nvoos-chat-sidebar-assistant-name">{name}</span>
							</button>
						);
					})}
				</div>
			)}

			{/* ── Tabs ───────────────────────────────────────────────────── */}
			<div className="nvoos-chat-sidebar-tabs">
				<button
					type="button"
					className={`nvoos-chat-sidebar-tab ${tab === 'conversations' ? 'nvoos-chat-sidebar-tab--active' : ''}`}
					onClick={() => setTab('conversations')}
				>
					Conversations
				</button>
				<button
					type="button"
					className={`nvoos-chat-sidebar-tab ${tab === 'threads' ? 'nvoos-chat-sidebar-tab--active' : ''}`}
					onClick={() => setTab('threads')}
				>
					Threads
				</button>
			</div>

			{/* ── Conversations Panel ────────────────────────────────────── */}
			{tab === 'conversations' && (
				<div className="nvoos-chat-sidebar-panel">
					<div className="nvoos-threads-sidebar__header">
						<h2>
							{selectedAssistantId
								? (assistants.find((a) => (a.id || a.ID) === selectedAssistantId)?.name || 'Assistant')
								: 'Conversations'}
						</h2>
						<button onClick={onNewSession} className="nvoos-btn nvoos-btn--icon" title="New conversation">+</button>
					</div>
					<div className="nvoos-threads-sidebar__list">
						{error && (
							<p className="nvoos-chat-sidebar-error" role="alert">{error}</p>
						)}
						{unavailableMessage && (
							<p className="nvoos-threads-sidebar__empty">{unavailableMessage}</p>
						)}
						{!unavailableMessage && sessionsLoading && sessions === null && (
							<p className="nvoos-threads-sidebar__empty">Loading…</p>
						)}
						{!unavailableMessage && sessions !== null && sessions.length === 0 && (
							<p className="nvoos-threads-sidebar__empty">No conversations yet.</p>
						)}
						{!unavailableMessage && Array.isArray(sessions) && sessions.length > 0 && (
							<div>
								{sessions.map((s) => {
									const turnCount = typeof s.turn_count === 'number' ? s.turn_count : 0;
									const stamp = s.last_created || s.completed_at || s.first_created || s.started_at;
									const label = stamp ? formatStamp(stamp) : s.session_key;
									const isActive = s.session_key === activeSessionKey;

									return (
										<div
											key={s.session_key}
											className={`nvoos-threads-sidebar__item ${isActive ? 'nvoos-threads-sidebar__item--active' : ''}`}
										>
											<button
												type="button"
												className="nvoos-chat-sidebar-item-select"
												onClick={() => onSelectSession(s.session_key)}
											>
												<span className="nvoos-threads-sidebar__item-title">{label}</span>
												<span className="nvoos-threads-sidebar__item-model">{turnCount} turns</span>
											</button>
											<button
												type="button"
												className="nvoos-chat-sidebar-item-delete"
												aria-label="Delete conversation"
												onClick={(e) => {
													e.stopPropagation();
													if (window.confirm('Delete this conversation?')) {
														onDeleteSession(s.session_key);
													}
												}}
											>
												×
											</button>
										</div>
									);
								})}
							</div>
						)}
					</div>
				</div>
			)}

			{/* ── Threads Panel (read-only historical view) ──────────────── */}
			{tab === 'threads' && (
				<div className="nvoos-chat-sidebar-panel">
					<div className="nvoos-threads-sidebar__header">
						<h2>
							{selectedAssistantId
								? (assistants.find((a) => (a.id || a.ID) === selectedAssistantId)?.name || 'Threads')
								: 'Threads'}
						</h2>
						<button onClick={handleNewThread} className="nvoos-btn nvoos-btn--icon" title="New Thread">+</button>
					</div>
					<div className="nvoos-threads-sidebar__list">
						{Object.entries(threadGroups).map(([scope, scopeThreads]) => (
							<div key={scope} className="nvoos-threads-sidebar__group">
								<h3 className="nvoos-threads-sidebar__group-title">{scope}</h3>
								{scopeThreads.map((thread) => (
									<div
										key={thread.id}
										className={`nvoos-threads-sidebar__item ${thread.id === activeThreadId ? 'nvoos-threads-sidebar__item--active' : ''}`}
										onClick={() => handleSelectThread(thread.id)}
										role="button"
										tabIndex={0}
										onKeyDown={(e) => e.key === 'Enter' && handleSelectThread(thread.id)}
									>
										<span className="nvoos-threads-sidebar__item-status" data-status={thread.status} />
										<span className="nvoos-threads-sidebar__item-title">{thread.title}</span>
										<span className="nvoos-threads-sidebar__item-model">{thread.model_name || 'Default'}</span>
									</div>
								))}
							</div>
						))}
						{filteredThreads.length === 0 && (
							<div className="nvoos-threads-sidebar__empty">
								<p>{selectedAssistantId ? 'No threads for this assistant.' : 'No threads yet.'}</p>
								<button onClick={handleNewThread} className="nvoos-btn nvoos-btn--primary">New Thread</button>
							</div>
						)}
					</div>
				</div>
			)}
		</aside>
	);
}
