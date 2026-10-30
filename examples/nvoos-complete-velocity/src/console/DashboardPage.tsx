/**
 * DashboardPage — console home screen (SA DashboardPage format).
 *
 * Welcome card, live stats from the assistant/transcript stores, and quick
 * action cards linking to each addon page. The chat dock lives to the right
 * of this page (owned by ConsoleShell); the quick actions can also toggle it.
 */

import { useEffect, useState, type JSX } from 'react';
import { Link } from 'react-router-dom';
import { useAssistantStore } from '@nvoos/pro-spa-v2/stores/assistantStore';
import type { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';
import type { ProSpaUser } from '@nvoos/pro-spa-v2/api/config';
import {
  CommandCenterClient,
  type CommandCenterOverview,
} from '@nvoos/pro-spa-v2/api/command-center';

import { isNavItemVisible, type NavGate } from './nav-features';

export interface DashboardPageProps {
  /** Shared transcript hook result (single source of truth in the shell). */
  transcripts: ReturnType<typeof useTranscripts>;
  /** Logged-in WordPress user (welcome header). */
  user: ProSpaUser;
  /** Whether the chat dock is open (drives the chat quick-action label). */
  dockOpen: boolean;
  /** Toggle the chat dock from the quick actions. */
  onToggleDock: () => void;
  /** Server-reported feature availability (absent = fail open). */
  features?: Record<string, boolean>;
  /**
   * Command Center REST base (mcp-ai-pro/v1/command-center). Empty for
   * non-admin sessions — the dashboard then skips the section entirely.
   */
  commandCenterEndpoint?: string;
  /** REST nonce for the command-center fetch. */
  nonce?: string;
}

interface QuickAction extends NavGate {
  title: string;
  description: string;
  to: string;
  icon: string;
}

const QUICK_ACTIONS: QuickAction[] = [
  { title: 'Assistants', description: 'Create and configure AI assistants', to: '/assistants', icon: '🤖' },
  { title: 'Tools', description: 'Browse the MCP tool registry', to: '/tools', icon: '🧰' },
  {
    title: 'Workflows',
    description: 'Build DAG automation pipelines',
    to: '/workflows',
    icon: '🔀',
    capability: 'manage_options',
  },
  {
    title: 'Analytics',
    description: 'Usage and cost dashboards',
    to: '/analytics',
    icon: '📈',
    capability: 'manage_options',
  },
  {
    title: 'Knowledge Graph',
    description: 'Explore connected content',
    to: '/knowledge-graph',
    icon: '🕸️',
    capability: 'manage_options',
    feature: 'knowledge_graph',
  },
  {
    title: 'Docs',
    description: 'Browse the NV oOS docs hub',
    to: '/docs',
    icon: '📚',
    capability: 'manage_options',
    feature: 'docs_hub',
  },
  {
    title: 'Media Studio',
    description: 'Generate and edit media',
    to: '/media',
    icon: '🎨',
    capability: 'manage_options',
    feature: 'media_studio',
  },
  {
    title: 'Documents',
    description: 'Draft and export documents',
    to: '/documents',
    icon: '📄',
    capability: 'manage_options',
    feature: 'document_editor',
  },
  {
    title: 'Settings',
    description: 'Configure the console',
    to: '/settings',
    icon: '⚙️',
    capability: 'manage_options',
  },
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
  const {
    transcripts,
    user,
    dockOpen,
    onToggleDock,
    features,
    commandCenterEndpoint = '',
    nonce = '',
  } = props;

  const assistants = useAssistantStore((s) => s.assistants);
  // The console seeds the assistant store asynchronously (ChatSidebar fetch),
  // so a bare `assistants.length` would flash 0 while that fetch runs.
  const assistantsLoaded = useAssistantStore((s) => s.assistantsLoaded);
  // `sessions` is null until the transcript list has loaded.
  const sessionsLoading = transcripts.sessions === null;
  const sessionCount = transcripts.sessions?.length ?? 0;

  	// Command Center (Proposal 084 Phase 5d) — the wp-admin overview tab's
  	// KPIs + activity feed. Fail-soft: any fetch error leaves the section
  	// hidden (the dashboard never blocks on it). Defense-in-depth: the
  	// runtime already empties the endpoint for non-admins, but never fire an
  	// admin-only fetch for a user who cannot see the data.
  	const canManageCommandCenter =
  		user?.capabilities?.includes( 'manage_options' ) ?? false;
  	const [commandCenter, setCommandCenter] = useState<CommandCenterOverview | null>(null);

  	useEffect(() => {
  		if (!commandCenterEndpoint || !canManageCommandCenter) {
  			return;
  		}
  		let cancelled = false;
    const controller = new AbortController();
    new CommandCenterClient({ endpoint: commandCenterEndpoint, nonce })
      .overview(controller.signal)
      .then((data) => {
        if (!cancelled) {
          setCommandCenter(data);
        }
      })
      .catch(() => {
        // Fail-soft — the section simply does not render.
      });
    		return () => {
    			cancelled = true;
    			controller.abort();
    		};
    	}, [commandCenterEndpoint, nonce, canManageCommandCenter]);

  const visibleActions = QUICK_ACTIONS.filter((action) =>
    isNavItemVisible(action, features, user.capabilities),
  );

  const kpis = commandCenter?.kpis;
  const onlineCount = commandCenter
    ? Object.values(commandCenter.agent_statuses).filter(
        (entry) => entry.status === 'online',
      ).length
    : 0;

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
          {visibleActions.map((action) => (
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

      {kpis ? (
        <section className="nvoos-console-card" aria-label="Command center">
          <h3>Command center</h3>

          {commandCenter && commandCenter.restriction_count > 0 ? (
            <p className="nvoos-console-cc__banner" role="status">
              {commandCenter.restriction_count === 1
                ? '1 user is currently blocked by rate limits or token budgets.'
                : `${commandCenter.restriction_count} users are currently blocked by rate limits or token budgets.`}{' '}
              <Link to="/token-manager">Review restrictions</Link>
            </p>
          ) : null}

          <div className="nvoos-console-stats">
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">Total agents</p>
              <p className="nvoos-console-stat__value">{kpis.total_agents}</p>
            </div>
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">Agents online</p>
              <p className="nvoos-console-stat__value nvoos-console-stat__value--green">
                {onlineCount}
              </p>
            </div>
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">Active tasks</p>
              <p className="nvoos-console-stat__value">{kpis.active_tasks}</p>
            </div>
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">Pending approvals</p>
              <p className="nvoos-console-stat__value nvoos-console-stat__value--purple">
                <Link to="/approvals">{kpis.pending_approvals}</Link>
              </p>
            </div>
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">Tokens today</p>
              <p className="nvoos-console-stat__value">
                {new Intl.NumberFormat('en-US').format(kpis.tokens_today)}
              </p>
            </div>
            <div className="nvoos-console-card nvoos-console-stat">
              <p className="nvoos-console-stat__label">System uptime</p>
              <p className="nvoos-console-stat__value">{kpis.uptime}%</p>
            </div>
          </div>

          {commandCenter && commandCenter.recent_events.length > 0 ? (
            <>
              <h4 className="nvoos-console-cc__heading">Recent activity</h4>
              <ul className="nvoos-console-cc__activity">
                {commandCenter.recent_events.slice(0, 6).map((event, index) => (
                  <li key={String(event.timestamp ?? index)} className="nvoos-console-cc__event">
                    <span className="nvoos-console-cc__event-time">
                      {event.timestamp
                        ? new Date(event.timestamp * 1000).toLocaleTimeString()
                        : '—'}
                    </span>
                    {event.agent_name ? <strong>{event.agent_name}</strong> : null}{' '}
                    {event.message ?? String(event.type ?? '')}
                  </li>
                ))}
              </ul>
            </>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}
