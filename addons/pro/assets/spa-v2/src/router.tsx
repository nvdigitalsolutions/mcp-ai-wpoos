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
 *   #/knowledge-graph — Content Graph explorer (NV oOS Content Graph plugin)
 *   #/docs        — Docs Hub (embedded hosted documentation browser)
 *   #/media       — Media Studio (embedded media editor SPA)
 *   #/documents   — Document Editor (embedded document editor SPA)
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
const KnowledgeGraphPage = lazy( () => import( './features/knowledge-graph/KnowledgeGraphPage' ).then( ( m ) => ( { default: m.KnowledgeGraphPage } ) ) );
const DocsPage = lazy( () => import( './features/docs-hub/DocsPage' ).then( ( m ) => ( { default: m.DocsPage } ) ) );
const MediaStudioPage = lazy( () => import( './features/media-studio/MediaStudioPage' ).then( ( m ) => ( { default: m.MediaStudioPage } ) ) );
const DocumentEditorPage = lazy( () => import( './features/document-editor/DocumentEditorPage' ).then( ( m ) => ( { default: m.DocumentEditorPage } ) ) );
const ApprovalsPage = lazy( () => import( './features/approvals/ApprovalsPage' ).then( ( m ) => ( { default: m.ApprovalsPage } ) ) );
const SlashCommandsPage = lazy( () => import( './features/slash-commands/SlashCommandsPage' ).then( ( m ) => ( { default: m.SlashCommandsPage } ) ) );
const TriggersPage = lazy( () => import( './features/triggers/TriggersPage' ).then( ( m ) => ( { default: m.TriggersPage } ) ) );
const TeamsPage = lazy( () => import( './features/teams/TeamsPage' ).then( ( m ) => ( { default: m.TeamsPage } ) ) );
const PortabilityPage = lazy( () => import( './features/portability/PortabilityPage' ).then( ( m ) => ( { default: m.PortabilityPage } ) ) );
const SecurityCenterPage = lazy( () => import( './features/security-center/SecurityCenterPage' ).then( ( m ) => ( { default: m.SecurityCenterPage } ) ) );
const OperationsPage = lazy( () => import( './features/operations/OperationsPage' ).then( ( m ) => ( { default: m.OperationsPage } ) ) );
const DiagnosticsPage = lazy( () => import( './features/diagnostics/DiagnosticsPage' ).then( ( m ) => ( { default: m.DiagnosticsPage } ) ) );
const TokenManagerPage = lazy( () => import( './features/token-manager/TokenManagerPage' ).then( ( m ) => ( { default: m.TokenManagerPage } ) ) );
const OrchestrationStatsPage = lazy( () => import( './features/orchestration-stats/OrchestrationStatsPage' ).then( ( m ) => ( { default: m.OrchestrationStatsPage } ) ) );
const ProOpsPage = lazy( () => import( './features/pro-ops/ProOpsPage' ).then( ( m ) => ( { default: m.ProOpsPage } ) ) );
const ScheduleManagerPage = lazy( () => import( './features/schedule-manager/ScheduleManagerPage' ).then( ( m ) => ( { default: m.ScheduleManagerPage } ) ) );
const RecordsPage = lazy( () => import( './features/records/RecordsPage' ).then( ( m ) => ( { default: m.RecordsPage } ) ) );
const DashboardsPage = lazy( () => import( './features/dashboards/DashboardsPage' ).then( ( m ) => ( { default: m.DashboardsPage } ) ) );

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
				<Route path="/knowledge-graph" element={ <KnowledgeGraphPage /> } />
				<Route path="/docs" element={ <DocsPage /> } />
				<Route path="/media" element={ <MediaStudioPage /> } />
				<Route path="/documents" element={ <DocumentEditorPage /> } />
				<Route path="/approvals" element={ <ApprovalsPage /> } />
				<Route path="/slash-commands" element={ <SlashCommandsPage /> } />
				<Route path="/triggers" element={ <TriggersPage /> } />
				<Route path="/teams" element={ <TeamsPage /> } />
				<Route path="/crm" element={ <RecordsPage group="crm" /> } />
				<Route path="/pm" element={ <RecordsPage group="pm" /> } />
				<Route path="/professions" element={ <RecordsPage group="professions" /> } />
				<Route path="/calendar" element={ <RecordsPage group="calendar" /> } />
				<Route path="/dashboards" element={ <DashboardsPage /> } />
				<Route path="/portability" element={ <PortabilityPage /> } />
				<Route path="/security-center" element={ <SecurityCenterPage /> } />
				<Route path="/operations" element={ <OperationsPage /> } />
				<Route path="/diagnostics" element={ <DiagnosticsPage /> } />
				<Route path="/token-manager" element={ <TokenManagerPage /> } />
				<Route path="/orchestration-stats" element={ <OrchestrationStatsPage /> } />
				<Route path="/pro-ops" element={ <ProOpsPage /> } />
				<Route path="/schedule-manager" element={ <ScheduleManagerPage /> } />
				<Route path="*" element={ <Navigate to="/chat" replace /> } />
			</Routes>
		</Suspense>
	);
}
