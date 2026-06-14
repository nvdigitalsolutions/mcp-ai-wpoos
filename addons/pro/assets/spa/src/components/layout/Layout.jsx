/**
 * Layout — Three-column SPA layout: ChatSidebar | MainContent | RightPanel.
 *
 * ChatSidebar provides two tabs: Conversations (active chat sessions)
 * and Threads (read-only historical archive).
 */

import { HashRouter } from 'react-router-dom';
import { useUIStore } from '../../store/uiStore';
import { useSettingsStore } from '../../store/settingsStore';
import { useTranscripts } from '../../hooks/useTranscripts';
import { TranscriptContext } from '../../hooks/TranscriptContext';
import ChatSidebar from './ChatSidebar';
import RightPanel from './RightPanel';
import StatusBar from './StatusBar';
import AppRouter from '../../router';

export default function Layout() {
	const { sidebarOpen } = useUIStore();
	const { settings, selectedAssistantId } = useSettingsStore();

	// Transcripts endpoint — same namespace the chat-spa addon uses.
	const transcriptsEndpoint = '/mcp-ai/v1/chat-transcripts';
	const assistantId = selectedAssistantId || settings?.user?.assistant_id || 0;

	const transcripts = useTranscripts({
		endpoint: transcriptsEndpoint,
		assistantId,
		disabled: false,
	});

	const handleSelectSession = (key) => {
		void transcripts.selectSession(key);
	};

	const handleDeleteSession = (key) => {
		void transcripts.deleteSession(key);
	};

	const handleNewSession = () => {
		transcripts.startNewSession();
	};

	return (
		<TranscriptContext.Provider value={transcripts}>
		<HashRouter>
			<div className="nvoos-layout">
				{sidebarOpen && (
					<ChatSidebar
						sessions={transcripts.sessions}
						activeSessionKey={transcripts.sessionKey}
						unavailableMessage={transcripts.unavailableMessage}
						error={transcripts.error}
						sessionsLoading={transcripts.isLoading}
						onSelectSession={handleSelectSession}
						onDeleteSession={handleDeleteSession}
						onNewSession={handleNewSession}
					/>
				)}
				<main className="nvoos-layout__main">
					<AppRouter />
				</main>
				<RightPanel />
				<StatusBar />
			</div>
		</HashRouter>
		</TranscriptContext.Provider>
	);
}
