/**
 * ChatDock — the single, always-mounted chat surface.
 *
 * Renders spa-v2's ChatSidebar (session + assistant selector) next to its
 * ChatPage, so docked and full-page chat share one component instance — and
 * one chat transport. The shell moves it between two layout modes purely via
 * CSS classes:
 *
 *   - `docked`:   fixed-width right panel next to the page content.
 *   - `fullpage`: /chat breakout — fills the content area (builder-page
 *                 style), and the page content is hidden by the shell.
 *   - hidden:     `display: none` while staying mounted, so an in-flight
 *                 conversation keeps streaming while the user browses.
 *
 * Because a single `useTranscripts` result (owned by ConsoleShell) is passed
 * to every chat surface, the dock, the full page, and the sidebar can never
 * disagree about the active conversation.
 */

import { type JSX, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { ChatSidebar } from '@nvoos/pro-spa-v2/components/layout/ChatSidebar';
import { ChatPage } from '@nvoos/pro-spa-v2/features/chat/ChatPage';
import type { useTranscripts } from '@nvoos/pro-spa-v2/hooks/useTranscripts';
import { formatSessionTitle } from './session-utils';

export interface ChatDockProps {
  /** Shared transcript hook result from ConsoleShell. */
  transcripts: ReturnType<typeof useTranscripts>;
  /** Layout mode: right dock or /chat full-page breakout. */
  mode: 'docked' | 'fullpage';
  /** Hide without unmounting (dock closed while not on /chat). */
  hidden: boolean;
  nonce: string;
  assistantsEndpoint: string;
  apiRoot: string;
  mediaEndpoint: string;
  /** Close the dock (docked mode only). */
  onClose: () => void;
}

export function ChatDock(props: ChatDockProps): JSX.Element {
  const {
    transcripts,
    mode,
    hidden,
    nonce,
    assistantsEndpoint,
    apiRoot,
    mediaEndpoint,
    onClose,
  } = props;

  const activeSession = useMemo(
    () =>
      (transcripts.sessions ?? []).find(
        (session) => session.session_key === transcripts.sessionKey,
      ),
    [transcripts.sessions, transcripts.sessionKey],
  );
  const activeTitle = activeSession ? formatSessionTitle(activeSession) : '';

  const classes = [
    'nvoos-console-dock',
    mode === 'fullpage' ? 'nvoos-console-dock--fullpage' : '',
    hidden ? 'nvoos-console-dock--hidden' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <aside className={classes} aria-label="Chat panel">
      {mode === 'docked' && (
        <header className="nvoos-console-dock__header">
          <h2 className="nvoos-console-dock__title">Chat</h2>
          {activeTitle && (
            <span className="nvoos-console-dock__session" title={activeTitle}>
              {activeTitle}
            </span>
          )}
          <div className="nvoos-console-dock__actions">
            <Link
              to="/chat"
              className="nvoos-console-dock__action"
              title="Open chat as a full page"
              aria-label="Open chat as a full page"
            >
              ⤢
            </Link>
            <button
              type="button"
              className="nvoos-console-dock__action"
              onClick={onClose}
              title="Close chat dock"
              aria-label="Close chat dock"
            >
              ×
            </button>
          </div>
        </header>
      )}
      <div className="nvoos-console-dock__body">
        <ChatSidebar
          sessions={transcripts.sessions}
          activeSessionKey={transcripts.sessionKey}
          unavailableMessage={transcripts.unavailableMessage}
          error={transcripts.error}
          onSelectSession={transcripts.selectSession}
          onDeleteSession={transcripts.deleteSession}
          onNewSession={transcripts.startNewSession}
          nonce={nonce}
          assistantsEndpoint={assistantsEndpoint}
          apiRoot={apiRoot}
          mediaEndpoint={mediaEndpoint}
        />
        <div className="nvoos-console-dock__chat">
          <ChatPage transcripts={transcripts} />
        </div>
      </div>
    </aside>
  );
}
