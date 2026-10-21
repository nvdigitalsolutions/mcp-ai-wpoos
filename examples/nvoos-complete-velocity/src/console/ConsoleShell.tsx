/**
 * ConsoleShell — SA-format host shell for the NV oOS Complete Console.
 *
 * Replaces the previous mount-the-whole-spa-v2-App approach: instead of
 * spa-v2's three-column admin layout, the console now provides its own
 * Schedule Anything-style shell — a collapsible left nav, per-addon pages in
 * a padded content area, a Dashboard home page, and a persistent chat dock
 * that follows the user across every page (global toggle).
 *
 * Wiring notes (mirrors spa-v2's Layout.tsx):
 *   - One `useTranscripts` result is instantiated here and shared by the
 *     dock sidebar, the docked ChatPage, and the /chat full-page breakout,
 *     so there is a single source of truth for the active conversation.
 *   - The assistant store is synced exactly like spa-v2's Layout (assistant
 *     switch → fresh conversation; opening a session moves the selector).
 *   - The chat surface is rendered ONCE (ChatDock) and moved between dock
 *     and full-page purely via CSS classes — no remount, so the chat
 *     transport and stream state survive navigation and dock toggles.
 */

import { lazy, Suspense, useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { HashRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';

import { readProSpaConfig } from '@nvoos/pro-spa-v2/api/config';
import { useBootstrap } from '@nvoos/pro-spa-v2/hooks/useBootstrap';
import { useCommandPalette } from '@nvoos/pro-spa-v2/hooks/useCommandPalette';
import { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';
import { useAssistantStore } from '@nvoos/pro-spa-v2/stores/assistantStore';
import { useUIStore } from '@nvoos/pro-spa-v2/stores/uiStore';
import { CommandPalette } from '@nvoos/pro-spa-v2/components/shared/CommandPalette';
import { StatusBar } from '@nvoos/pro-spa-v2/components/layout/StatusBar';
import { ToastContainer } from '@nvoos/pro-spa-v2/components/shared/Toast';

import { ConsoleSidebar } from './ConsoleSidebar';
import { DashboardPage } from './DashboardPage';
import { ChatDock } from './ChatDock';
import { RequireGate, gateForPath } from './nav-features';

// Route-based code splitting, same recipe as spa-v2's router. Each addon is
// its own page in the SA-format content area.
const AssistantsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/assistants/AssistantsPage').then((m) => ({
    default: m.AssistantsPage,
  })),
);
const ToolsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/tools/ToolsPage').then((m) => ({ default: m.ToolsPage })),
);
const WorkflowsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/workflows/WorkflowsPage').then((m) => ({
    default: m.WorkflowsPage,
  })),
);
const AnalyticsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/analytics/AnalyticsPage').then((m) => ({
    default: m.AnalyticsPage,
  })),
);
const KnowledgeGraphPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/knowledge-graph/KnowledgeGraphPage').then((m) => ({
    default: m.KnowledgeGraphPage,
  })),
);
const DocsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/docs-hub/DocsPage').then((m) => ({ default: m.DocsPage })),
);
const MediaStudioPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/media-studio/MediaStudioPage').then((m) => ({
    default: m.MediaStudioPage,
  })),
);
const DocumentEditorPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/document-editor/DocumentEditorPage').then((m) => ({
    default: m.DocumentEditorPage,
  })),
);
const SettingsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/settings/SettingsPage').then((m) => ({
    default: m.SettingsPage,
  })),
);
const ApprovalsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/approvals/ApprovalsPage').then((m) => ({
    default: m.ApprovalsPage,
  })),
);
const SlashCommandsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/slash-commands/SlashCommandsPage').then((m) => ({
    default: m.SlashCommandsPage,
  })),
);
const TriggersPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/triggers/TriggersPage').then((m) => ({
    default: m.TriggersPage,
  })),
);
const TeamsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/teams/TeamsPage').then((m) => ({
    default: m.TeamsPage,
  })),
);
const PortabilityPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/portability/PortabilityPage').then((m) => ({
    default: m.PortabilityPage,
  })),
);
const SecurityCenterPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/security-center/SecurityCenterPage').then((m) => ({
    default: m.SecurityCenterPage,
  })),
);
const OperationsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/operations/OperationsPage').then((m) => ({
    default: m.OperationsPage,
  })),
);
const DiagnosticsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/diagnostics/DiagnosticsPage').then((m) => ({
    default: m.DiagnosticsPage,
  })),
);
const TokenManagerPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/token-manager/TokenManagerPage').then((m) => ({
    default: m.TokenManagerPage,
  })),
);
const OrchestrationStatsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/orchestration-stats/OrchestrationStatsPage').then((m) => ({
    default: m.OrchestrationStatsPage,
  })),
);
const ProOpsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/pro-ops/ProOpsPage').then((m) => ({
    default: m.ProOpsPage,
  })),
);
const ScheduleManagerPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/schedule-manager/ScheduleManagerPage').then((m) => ({
    default: m.ScheduleManagerPage,
  })),
);
const RecordsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/records/RecordsPage').then((m) => ({
    default: m.RecordsPage,
  })),
);
const DashboardsPage = lazy(() =>
  import('@nvoos/pro-spa-v2/features/dashboards/DashboardsPage').then((m) => ({
    default: m.DashboardsPage,
  })),
);

const DOCK_PREF_KEY = 'nvoos-complete-console.dock-open';

/** The dock defaults to open; the last choice is remembered per browser. */
function readDockPref(): boolean {
  try {
    const stored = window.localStorage.getItem(DOCK_PREF_KEY);
    return stored === null ? true : stored === '1';
  } catch {
    return true;
  }
}

function persistDockPref(open: boolean): void {
  try {
    window.localStorage.setItem(DOCK_PREF_KEY, open ? '1' : '0');
  } catch {
    // localStorage unavailable — the preference is session-only.
  }
}

function PageSkeleton(): JSX.Element {
  return (
    <div className="nvoos-pro-spa-page-skeleton" role="status" aria-label="Loading page">
      <div className="nvoos-pro-spa-page-skeleton__spinner" aria-hidden="true" />
    </div>
  );
}

function MissingConfigScreen(): JSX.Element {
  return (
    <div className="nvoos-console-error" role="alert">
      <h2>Unable to start the NV oOS Console</h2>
      <p>Runtime configuration not found. Please refresh the page or contact an administrator.</p>
    </div>
  );
}

/**
 * Inner component — rendered inside HashRouter so it can read the location.
 */
function ConsoleApp(): JSX.Element {
  const location = useLocation();
  const isChatRoute = location.pathname === '/chat';

  const runtime = useMemo(() => readProSpaConfig(), []);
  if (!runtime) {
    return <MissingConfigScreen />;
  }

  const {
    endpoints: {
      transcripts: transcriptsEndpoint,
      assistants: assistantsEndpoint,
      upload: mediaEndpoint,
    },
    nonce,
    config: { assistantId },
    apiUrl,
  } = runtime;

  // Server-reported feature availability — gates nav + optional routes.
  const features = runtime.features ?? {};

  // Same fallback chain as spa-v2's Layout so the transcript hook and the
  // chat transport bind to the same assistant.
  const defaultAssistantId = assistantId ?? runtime.user?.assistant_id ?? 0;

  // ---- active assistant (sidebar selector owns it) — mirrors Layout.tsx ----
  const storedAssistantId = useAssistantStore((s) => s.assistantId);
  const setActiveAssistant = useAssistantStore((s) => s.setActiveAssistant);
  const assistants = useAssistantStore((s) => s.assistants);
  const activeAssistantId = storedAssistantId > 0 ? storedAssistantId : defaultAssistantId;

  // Opening a conversation moves the selector to the assistant that owns it.
  const handleSessionAssistantChange = useCallback(
    (nextAssistantId: number) => {
      if (assistants.length > 0 && !assistants.some((a) => a.id === nextAssistantId)) {
        return;
      }
      setActiveAssistant(nextAssistantId);
    },
    [assistants, setActiveAssistant],
  );

  // ---- transcripts — single source of truth for dock + full page ----
  const transcripts = useTranscripts({
    endpoint: transcriptsEndpoint,
    nonce,
    assistantId: activeAssistantId,
    onSessionAssistantChange: handleSessionAssistantChange,
  });

  // ---- theme (spa-v2 UI store drives data-theme + tokens) ----
  const theme = useUIStore((s) => s.theme);

  // ---- global chat-dock toggle (host-owned, persisted) ----
  const [dockOpen, setDockOpen] = useState(readDockPref);
  useEffect(() => {
    persistDockPref(dockOpen);
  }, [dockOpen]);

  const toggleDock = useCallback(() => {
    setDockOpen((prev) => !prev);
  }, []);

  // The dock is always mounted; its visibility and position are CSS-only.
  // /chat turns the dock into the page itself (full-page breakout); the
  // toggle only applies off /chat.
  const dockMode: 'docked' | 'fullpage' = isChatRoute ? 'fullpage' : 'docked';
  const dockHidden = !isChatRoute && !dockOpen;

  const shellClasses = [
    'nvoos-pro-spa-layout',
    'nvoos-console-shell',
    isChatRoute ? 'nvoos-console-shell--chat-fullpage' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={shellClasses} data-theme={theme}>
      <ConsoleSidebar
        dockOpen={dockOpen}
        onToggleDock={toggleDock}
        isChatRoute={isChatRoute}
        user={runtime.user}
        features={features}
      />

      <main className="nvoos-console-content" id="nvoos-console-main-content">
        <div className="nvoos-console-page">
          <Suspense fallback={<PageSkeleton />}>
            <Routes>
              <Route
                path="/"
                element={
                  <DashboardPage
                    transcripts={transcripts}
                    user={runtime.user}
                    dockOpen={dockOpen}
                    onToggleDock={toggleDock}
                    features={features}
                  />
                }
              />
              <Route path="/assistants" element={<AssistantsPage />} />
              <Route path="/tools" element={<ToolsPage />} />
              <Route
                path="/workflows"
                element={
                  <RequireGate
                    gate={gateForPath('/workflows')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <WorkflowsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/analytics"
                element={
                  <RequireGate
                    gate={gateForPath('/analytics')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <AnalyticsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/knowledge-graph"
                element={
                  <RequireGate
                    gate={gateForPath('/knowledge-graph')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <KnowledgeGraphPage />
                  </RequireGate>
                }
              />
              <Route
                path="/docs"
                element={
                  <RequireGate
                    gate={gateForPath('/docs')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <DocsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/media"
                element={
                  <RequireGate
                    gate={gateForPath('/media')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <MediaStudioPage />
                  </RequireGate>
                }
              />
              <Route
                path="/documents"
                element={
                  <RequireGate
                    gate={gateForPath('/documents')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <DocumentEditorPage />
                  </RequireGate>
                }
              />
              <Route
                path="/settings"
                element={
                  <RequireGate
                    gate={gateForPath('/settings')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <SettingsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/approvals"
                element={
                  <RequireGate
                    gate={gateForPath('/approvals')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <ApprovalsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/slash-commands"
                element={
                  <RequireGate
                    gate={gateForPath('/slash-commands')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <SlashCommandsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/triggers"
                element={
                  <RequireGate
                    gate={gateForPath('/triggers')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <TriggersPage />
                  </RequireGate>
                }
              />
              <Route
                path="/teams"
                element={
                  <RequireGate
                    gate={gateForPath('/teams')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <TeamsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/crm"
                element={
                  <RequireGate
                    gate={gateForPath('/crm')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <RecordsPage group="crm" />
                  </RequireGate>
                }
              />
              <Route
                path="/pm"
                element={
                  <RequireGate
                    gate={gateForPath('/pm')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <RecordsPage group="pm" />
                  </RequireGate>
                }
              />
              <Route
                path="/professions"
                element={
                  <RequireGate
                    gate={gateForPath('/professions')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <RecordsPage group="professions" />
                  </RequireGate>
                }
              />
              <Route
                path="/calendar"
                element={
                  <RequireGate
                    gate={gateForPath('/calendar')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <RecordsPage group="calendar" />
                  </RequireGate>
                }
              />
              <Route
                path="/dashboards"
                element={
                  <RequireGate
                    gate={gateForPath('/dashboards')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <DashboardsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/portability"
                element={
                  <RequireGate
                    gate={gateForPath('/portability')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <PortabilityPage />
                  </RequireGate>
                }
              />
              <Route
                path="/security-center"
                element={
                  <RequireGate
                    gate={gateForPath('/security-center')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <SecurityCenterPage />
                  </RequireGate>
                }
              />
              <Route
                path="/operations"
                element={
                  <RequireGate
                    gate={gateForPath('/operations')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <OperationsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/diagnostics"
                element={
                  <RequireGate
                    gate={gateForPath('/diagnostics')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <DiagnosticsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/token-manager"
                element={
                  <RequireGate
                    gate={gateForPath('/token-manager')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <TokenManagerPage />
                  </RequireGate>
                }
              />
              <Route
                path="/orchestration-stats"
                element={
                  <RequireGate
                    gate={gateForPath('/orchestration-stats')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <OrchestrationStatsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/pro-ops"
                element={
                  <RequireGate
                    gate={gateForPath('/pro-ops')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <ProOpsPage />
                  </RequireGate>
                }
              />
              <Route
                path="/schedule-manager"
                element={
                  <RequireGate
                    gate={gateForPath('/schedule-manager')}
                    features={features}
                    capabilities={runtime.user.capabilities}
                  >
                    <ScheduleManagerPage />
                  </RequireGate>
                }
              />
              {/* /chat renders through ChatDock's full-page mode. */}
              <Route path="/chat" element={null} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </Suspense>
        </div>
      </main>

      <ChatDock
        transcripts={transcripts}
        mode={dockMode}
        hidden={dockHidden}
        nonce={nonce}
        assistantsEndpoint={assistantsEndpoint}
        apiRoot={apiUrl}
        mediaEndpoint={mediaEndpoint ?? ''}
        onClose={() => setDockOpen(false)}
      />

      <StatusBar transcriptsEndpoint={transcriptsEndpoint} nonce={nonce} />
      <ToastContainer />
      <CommandPaletteGate />
    </div>
  );
}

/**
 * Command palette overlay — same open/close wiring as spa-v2's App root.
 * The hook instance here owns the Ctrl+K listener and decides visibility;
 * the CommandPalette component's own instance drives input + selection.
 */
function CommandPaletteGate(): JSX.Element | null {
  const { isOpen } = useCommandPalette();
  return isOpen ? <CommandPalette /> : null;
}

/**
 * Public shell — bootstrap gate, then the HashRouter'd console.
 */
export function ConsoleShell(): JSX.Element {
  const { loading, error } = useBootstrap();

  if (loading) {
    return (
      <div className="nvoos-surface-loading" role="status" aria-label="Loading NV oOS Console">
        <div className="nvoos-console-loading__spinner" aria-hidden="true" />
        <p>Loading NV oOS Console…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="nvoos-console-error" role="alert">
        <h2>Unable to start the NV oOS Console</h2>
        <p>{error}</p>
      </div>
    );
  }

  return (
    <HashRouter>
      <ConsoleApp />
    </HashRouter>
  );
}
