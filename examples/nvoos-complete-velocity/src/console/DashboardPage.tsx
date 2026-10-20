/**
 * DashboardPage — console home screen (SA DashboardPage format).
 *
 * Welcome card, live stats from the assistant/transcript stores, and quick
 * action cards linking to each addon page. The chat dock lives to the right
 * of this page (owned by ConsoleShell); the quick actions can also toggle it.
 */

import { type JSX } from 'react';
import { Link } from 'react-router-dom';
import { useAssistantStore } from '@nvoos/pro-spa-v2/stores/assistantStore';
import type { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';
import type { ProSpaUser } from '@nvoos/pro-spa-v2/api/config';

export interface DashboardPageProps {
  /** Shared transcript hook result (single source of truth in the shell). */
  transcripts: ReturnType<typeof useTranscripts>;
  /** Logged-in WordPress user (welcome header). */
  user: ProSpaUser;
  /** Whether the chat dock is open (drives the chat quick-action label). */
  dockOpen: boolean;
  /** Toggle the chat dock from the quick actions. */
  onToggleDock: () => void;
}

interface QuickAction {
  title: string;
  description: string;
  to: string;
  icon: string;
}

const QUICK_ACTIONS: QuickAction[] = [
  { title: 'Assistants', description: 'Create and configure AI assistants', to: '/assistants', icon: '🤖' },
  { title: 'Tools', description: 'Browse the MCP tool registry', to: '/tools', icon: '🧰' },
  { title: 'Workflows', description: 'Build DAG automation pipelines', to: '/workflows', icon: '🔀' },
  { title: 'Analytics', description: 'Usage and cost dashboards', to: '/analytics', icon: '📈' },
  { title: 'Knowledge Graph', description: 'Explore connected content', to: '/knowledge-graph', icon: '🕸️' },
  { title: 'Docs', description: 'Browse the NV oOS docs hub', to: '/docs', icon: '📚' },
  { title: 'Media Studio', description: 'Generate and edit media', to: '/media', icon: '🎨' },
  { title: 'Documents', description: 'Draft and export documents', to: '/documents', icon: '📄' },
  { title: 'Settings', description: 'Configure the console', to: '/settings', icon: '⚙️' },
];

/**
 * Inline placeholder shown inside a stat card while its value loads — an
 * ellipsis with a subtle pulse, so "0" never flashes before real data lands.
 */
function LoadingDash(): JSX.Element {
  return (
    <span className="nvoos-console-stat__loading" role="status" aria-label="Loading">
      <span aria-hidden="true">…</span>
    </span>
  );
}

export function DashboardPage(props: DashboardPageProps): JSX.Element {
  const { transcripts, user, dockOpen, onToggleDock } = props;

  const assistants = useAssistantStore((s) => s.assistants);
  // The console seeds the assistant store asynchronously (ChatSidebar fetch),
  // so a bare `assistants.length` would flash 0 while that fetch runs.
  const assistantsLoaded = useAssistantStore((s) => s.assistantsLoaded);
  // `sessions` is null until the transcript list has loaded.
  const sessionsLoading = transcripts.sessions === null;
  const sessionCount = transcripts.sessions?.length ?? 0;

  return (
    <div className="nvoos-console-dashboard">
      <h1>Dashboard</h1>

      <section className="nvoos-console-card">
        <h2>Welcome to NV oOS Complete</h2>
        <p>
          {user.displayName
            ? `Signed in as ${user.displayName}. `
            : ''}
          Your operator console is ready — manage assistants, run tools, build
          workflows, and keep a chat session docked while you work.
        </p>
      </section>

      <div className="nvoos-console-stats">
        <div className="nvoos-console-card nvoos-console-stat">
          <p className="nvoos-console-stat__label">Assistants</p>
          <p className="nvoos-console-stat__value nvoos-console-stat__value--accent">
            {assistantsLoaded ? assistants.length : <LoadingDash />}
          </p>
        </div>
        <div className="nvoos-console-card nvoos-console-stat">
          <p className="nvoos-console-stat__label">Conversations</p>
          <p className="nvoos-console-stat__value nvoos-console-stat__value--green">
            {sessionsLoading ? <LoadingDash /> : sessionCount}
          </p>
        </div>
        <div className="nvoos-console-card nvoos-console-stat">
          <p className="nvoos-console-stat__label">Chat dock</p>
          <p className="nvoos-console-stat__value nvoos-console-stat__value--purple">
            {dockOpen ? 'Open' : 'Closed'}
          </p>
        </div>
      </div>

      <section className="nvoos-console-card">
        <h3>Quick actions</h3>
        <div className="nvoos-console-quick">
          <Link to="/chat" className="nvoos-console-quick-card">
            <span className="nvoos-console-quick-card__icon" aria-hidden="true">
              💬
            </span>
            <span className="nvoos-console-quick-card__title">Open Chat</span>
            <span className="nvoos-console-quick-card__desc">Full-page chat surface</span>
          </Link>
          <button
            type="button"
            className="nvoos-console-quick-card nvoos-console-quick-card--button"
            onClick={onToggleDock}
            aria-pressed={dockOpen}
          >
            <span className="nvoos-console-quick-card__icon" aria-hidden="true">
              {dockOpen ? '➖' : '➕'}
            </span>
            <span className="nvoos-console-quick-card__title">
              {dockOpen ? 'Close Chat Dock' : 'Open Chat Dock'}
            </span>
            <span className="nvoos-console-quick-card__desc">
              Keep a chat panel pinned to the right
            </span>
          </button>
          {QUICK_ACTIONS.map((action) => (
            <Link key={action.to} to={action.to} className="nvoos-console-quick-card">
              <span className="nvoos-console-quick-card__icon" aria-hidden="true">
                {action.icon}
              </span>
              <span className="nvoos-console-quick-card__title">{action.title}</span>
              <span className="nvoos-console-quick-card__desc">{action.description}</span>
            </Link>
          ))}
        </div>
      </section>
    </div>
  );
}
