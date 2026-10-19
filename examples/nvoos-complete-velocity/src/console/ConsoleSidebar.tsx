/**
 * ConsoleSidebar — SA-format left navigation for the console shell.
 *
 * Mirrors Schedule Anything's AppLayout sidebar (collapsible rail + active
 * link states) but styled with the spa-v2 theme tokens instead of Tailwind,
 * and extended with the global chat-dock toggle.
 */

import { useState, type JSX } from 'react';
import { NavLink } from 'react-router-dom';
import type { ProSpaUser } from '@nvoos/pro-spa-v2/api/config';

export interface ConsoleSidebarProps {
  /** Whether the persistent chat dock is open (drives the toggle button). */
  dockOpen: boolean;
  /** Toggle the persistent chat dock (global, host-owned state). */
  onToggleDock: () => void;
  /** True on /chat — the chat is the page itself, so the dock toggle is moot. */
  isChatRoute: boolean;
  /** Logged-in WordPress user (footer readout). */
  user: ProSpaUser;
}

interface NavItem {
  path: string;
  label: string;
  icon: string;
  /** Match the path exactly (the dashboard lives at "/"). */
  end?: boolean;
}

const NAV_ITEMS: NavItem[] = [
  { path: '/', label: 'Dashboard', icon: '📊', end: true },
  { path: '/chat', label: 'Chat', icon: '💬' },
  { path: '/assistants', label: 'Assistants', icon: '🤖' },
  { path: '/tools', label: 'Tools', icon: '🧰' },
  { path: '/workflows', label: 'Workflows', icon: '🔀' },
  { path: '/analytics', label: 'Analytics', icon: '📈' },
  { path: '/knowledge-graph', label: 'Knowledge Graph', icon: '🕸️' },
  { path: '/docs', label: 'Docs', icon: '📚' },
  { path: '/media', label: 'Media Studio', icon: '🎨' },
  { path: '/documents', label: 'Documents', icon: '📄' },
  { path: '/settings', label: 'Settings', icon: '⚙️' },
];

const COLLAPSED_PREF_KEY = 'nvoos-complete-console.nav-collapsed';

function readCollapsedPref(): boolean {
  try {
    return window.localStorage.getItem(COLLAPSED_PREF_KEY) === '1';
  } catch {
    return false;
  }
}

export function ConsoleSidebar(props: ConsoleSidebarProps): JSX.Element {
  const { dockOpen, onToggleDock, isChatRoute, user } = props;
  const [collapsed, setCollapsed] = useState(readCollapsedPref);

  const toggleCollapsed = (): void => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        window.localStorage.setItem(COLLAPSED_PREF_KEY, next ? '1' : '0');
      } catch {
        // localStorage unavailable — collapse state is session-only.
      }
      return next;
    });
  };

  return (
    <aside
      className={
        collapsed
          ? 'nvoos-console-nav nvoos-console-nav--collapsed'
          : 'nvoos-console-nav'
      }
      aria-label="Console navigation"
    >
      <div className="nvoos-console-nav__header">
        <button
          type="button"
          className="nvoos-console-nav__collapse"
          onClick={toggleCollapsed}
          aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
          aria-expanded={!collapsed}
        >
          {collapsed ? '▶' : '◀'}
        </button>
        {!collapsed && <span className="nvoos-console-nav__logo">NV oOS Complete</span>}
      </div>

      <nav className="nvoos-console-nav__list" aria-label="Pages">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end={item.end}
            title={collapsed ? item.label : undefined}
            className={({ isActive }) =>
              isActive
                ? 'nvoos-console-nav__link nvoos-console-nav__link--active'
                : 'nvoos-console-nav__link'
            }
          >
            <span className="nvoos-console-nav__icon" aria-hidden="true">
              {item.icon}
            </span>
            {!collapsed && <span className="nvoos-console-nav__label">{item.label}</span>}
          </NavLink>
        ))}
      </nav>

      <div className="nvoos-console-nav__footer">
        <button
          type="button"
          className={
            dockOpen
              ? 'nvoos-console-nav__btn nvoos-console-nav__btn--active'
              : 'nvoos-console-nav__btn'
          }
          onClick={onToggleDock}
          disabled={isChatRoute}
          aria-pressed={dockOpen}
          title={
            isChatRoute
              ? 'Chat is open as a full page'
              : dockOpen
                ? 'Close the chat dock'
                : 'Open the chat dock'
          }
        >
          <span className="nvoos-console-nav__icon" aria-hidden="true">
            💬
          </span>
          {!collapsed && <span className="nvoos-console-nav__label">Chat dock</span>}
        </button>
        {!collapsed && user.displayName && (
          <p className="nvoos-console-nav__user">{user.displayName}</p>
        )}
      </div>
    </aside>
  );
}
