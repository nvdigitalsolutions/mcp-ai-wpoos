/**
 * Router — Hash-based routing for WordPress admin compatibility.
 *
 * Routes:
 *   #/chat/:threadId?  — Agent Panel (default)
 *   #/settings         — Plugin settings
 *   #/tools            — Tool registry
 *   #/assistants       — Assistant management
 *   #/workflows        — Workflow builder
 *   #/analytics        — Usage dashboard
 */

import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import ChatPage from '../pages/ChatPage';
import SettingsPage from '../pages/SettingsPage';
import ToolsPage from '../pages/ToolsPage';
import AssistantsPage from '../pages/AssistantsPage';
import WorkflowsPage from '../pages/WorkflowsPage';
import AnalyticsPage from '../pages/AnalyticsPage';

export default function AppRouter() {
	return (
		<HashRouter>
			<Routes>
				<Route path="/chat/:threadId?" element={<ChatPage />} />
				<Route path="/chat" element={<ChatPage />} />
				<Route path="/settings" element={<SettingsPage />} />
				<Route path="/tools" element={<ToolsPage />} />
				<Route path="/assistants" element={<AssistantsPage />} />
				<Route path="/workflows" element={<WorkflowsPage />} />
				<Route path="/analytics" element={<AnalyticsPage />} />
				<Route path="*" element={<Navigate to="/chat" replace />} />
			</Routes>
		</HashRouter>
	);
}
