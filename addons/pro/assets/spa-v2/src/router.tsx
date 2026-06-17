/**
 * AppRouter — Hash-based routing for WordPress admin compatibility.
 *
 * Uses React.lazy + Suspense for route-based code splitting.
 * Routes:
 *   #/chat        — Agent Panel (conversation-driven)
 *   #/settings    — Plugin settings
 *   #/tools       — Tool registry
 *   #/assistants  — Assistant management
 *   #/workflows   — Workflow builder
 *   #/analytics   — Usage dashboard
 */

import { lazy, Suspense, type JSX } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import type { useTranscripts } from './hooks/useTranscripts';

const ChatPage = lazy( () => import( './features/chat/ChatPage' ).then( ( m ) => ( { default: m.ChatPage } ) ) );
const SettingsPage = lazy( () => import( './features/settings/SettingsPage' ).then( ( m ) => ( { default: m.SettingsPage } ) ) );
const ToolsPage = lazy( () => import( './features/tools/ToolsPage' ).then( ( m ) => ( { default: m.ToolsPage } ) ) );
const AssistantsPage = lazy( () => import( './features/assistants/AssistantsPage' ).then( ( m ) => ( { default: m.AssistantsPage } ) ) );
const WorkflowsPage = lazy( () => import( './features/workflows/WorkflowsPage' ).then( ( m ) => ( { default: m.WorkflowsPage } ) ) );
const AnalyticsPage = lazy( () => import( './features/analytics/AnalyticsPage' ).then( ( m ) => ( { default: m.AnalyticsPage } ) ) );

export interface AppRouterProps {
	/** Shared transcript hook result from Layout. */
	transcripts: ReturnType< typeof useTranscripts >;
}

function PageSkeleton(): JSX.Element {
	return (
		<div
			className="nvoos-pro-spa-page-skeleton"
			role="status"
			aria-label="Loading page"
		>
			<div className="nvoos-pro-spa-page-skeleton__spinner" aria-hidden="true" />
		</div>
	);
}

export function AppRouter( { transcripts }: AppRouterProps ): JSX.Element {
	return (
		<Suspense fallback={ <PageSkeleton /> }>
			<Routes>
				<Route path="/chat" element={ <ChatPage transcripts={ transcripts } /> } />
				<Route path="/settings" element={ <SettingsPage /> } />
				<Route path="/tools" element={ <ToolsPage /> } />
				<Route path="/assistants" element={ <AssistantsPage /> } />
				<Route path="/workflows" element={ <WorkflowsPage /> } />
				<Route path="/analytics" element={ <AnalyticsPage /> } />
				<Route path="*" element={ <Navigate to="/chat" replace /> } />
			</Routes>
		</Suspense>
	);
}
