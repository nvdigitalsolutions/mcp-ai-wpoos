/**
 * ConsoleSidebar — SA-format left navigation for the console shell.
 *
 * Mirrors Schedule Anything's AppLayout sidebar (collapsible rail + active
 * link states) but styled with the spa-v2 theme tokens instead of Tailwind,
 * and extended with the global chat-dock toggle. Nav items come from the
 * shared grouped catalogue (nav-features.ts) and are filtered by the
 * session's capabilities and the server-reported feature map — the Docs
 * item, for example, disappears when docs-hub is not installed.
 *
 * The expanded rail renders collapsible groups per the W3C disclosure-
 * navigation pattern: each group header is a real button with
 * `aria-expanded` + `aria-controls`, the links live in the disclosure
 * region, the group holding the active route auto-expands, and the user's
 * collapse choices persist in localStorage. The collapsed icon rail flattens
 * groups back to plain icon links (with tooltips) so no surface is lost.
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { readProSpaConfig, type ProSpaUser } from '@nvoos/pro-spa-v2/api/config';

import { brandText } from '../brand';

import {
  CONSOLE_NAV_GROUPS,
  isNavItemVisible,
  type ConsoleNavItem,
} from './nav-features';

export interface ConsoleSidebarProps {
  /** Whether the persistent chat dock is open (drives the toggle button). */
  dockOpen: boolean;
  /** Toggle the persistent chat dock (global, host-owned state). */
  onToggleDock: () => void;
  /** True on /chat — the chat is the page itself, so the dock toggle is moot. */
  isChatRoute: boolean;
  /** Logged-in WordPress user (footer readout + capability gating). */
  user: ProSpaUser;
  /** Server-reported feature availability (absent = fail open). */
  features?: Record<string, boolean>;
  /** End the WordPress session (footer action). */
  onSignOut: () => Promise<void> | void;
}

const COLLAPSED_PREF_KEY = 'nvoos-complete-console.nav-collapsed';
const GROUP_PREF_KEY = 'nvoos-complete-console.nav-groups';

function readCollapsedPref(): boolean {
  try {
    return window.localStorage.getItem(COLLAPSED_PREF_KEY) === '1';
  } catch {
    return false;
  }
}

/** Per-group collapse prefs: `{ [groupId]: true }` = user-collapsed. */
function readGroupPrefs(): Record<string, boolean> {
  try {
    const raw = window.localStorage.getItem(GROUP_PREF_KEY);
    const parsed = raw ? (JSON.parse(raw) as unknown) : {};
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as Record<string, boolean>)
      : {};
  } catch {
    return {};
  }
}

function writeGroupPrefs(prefs: Record<string, boolean>): void {
  try {
    window.localStorage.setItem(GROUP_PREF_KEY, JSON.stringify(prefs));
  } catch {
    // localStorage unavailable — collapse state is session-only.
  }
}

export function ConsoleSidebar(props: ConsoleSidebarProps): JSX.Element {
  const { dockOpen, onToggleDock, isChatRoute, user, features, onSignOut } = props;
  const [collapsed, setCollapsed] = useState(readCollapsedPref);
  const [groupPrefs, setGroupPrefs] = useState<Record<string, boolean>>(readGroupPrefs);
  const [signingOut, setSigningOut] = useState(false);
  const location = useLocation();

  // Brand the rail with the first few words of the WordPress site title,
  // falling back to the product name whenever the title is unavailable.
  const runtime = useMemo(() => readProSpaConfig(), []);
  const logoText = useMemo(() => brandText(runtime?.siteName), [runtime]);

  const handleSignOut = async (): Promise<void> => {
    if (signingOut) {
      return;
    }
    setSigningOut(true);
    try {
      await onSignOut();
    } finally {
      // The shell unmounts when the parent switches to the sign-in screen;
      // resetting state afterwards is a harmless no-op there.
      setSigningOut(false);
    }
  };

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

  const isGroupExpanded = (groupId: string, defaultExpanded?: boolean): boolean =>
    groupPrefs[groupId] === undefined ? Boolean(defaultExpanded) : !groupPrefs[groupId];

  const toggleGroup = (groupId: string, defaultExpanded?: boolean): void => {
    setGroupPrefs((prev) => {
      // `groupPrefs[id] === true` means user-collapsed; toggling inverts
      // the *effective* expanded state so the default is honoured once.
      const wasExpanded =
        prev[groupId] === undefined ? Boolean(defaultExpanded) : !prev[groupId];
      const next = { ...prev, [groupId]: wasExpanded };
      writeGroupPrefs(next);
      return next;
    });
  };

  // Auto-expand the group holding the active route (deep links, navigation)
  // without fighting an explicit user collapse of that same group.
  useEffect(() => {
    const activeGroup = CONSOLE_NAV_GROUPS.find((group) =>
      group.items.some((item) => item.path === location.pathname),
    );
    if (activeGroup) {
      setGroupPrefs((prev) =>
        prev[activeGroup.id] === undefined || prev[activeGroup.id] === true
          ? { ...prev, [activeGroup.id]: false }
          : prev,
      );
    }
  }, [location.pathname]);

  const visibleGroups = CONSOLE_NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) => isNavItemVisible(item, features, user.capabilities)),
  })).filter((group) => group.items.length > 0);

  const flatVisible = visibleGroups.flatMap((group) => group.items);

  const renderLink = (item: ConsoleNavItem): JSX.Element => (
    <NavLink
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
  );

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
        {!collapsed && <span className="nvoos-console-nav__logo">{logoText}</span>}
      </div>

      <nav className="nvoos-console-nav__list" aria-label="Pages">
        {collapsed ? (
          // Icon rail — flatten groups to plain icon links with tooltips so
          // every visible surface stays reachable.
          <ul className="nvoos-console-nav__items">
            {flatVisible.map((item) => (
              <li key={item.path} className="nvoos-console-nav__item">
                {renderLink(item)}
              </li>
            ))}
          </ul>
        ) : (
          <ul className="nvoos-console-nav__items">
            {visibleGroups.map((group) => {
              // A single-item group has nothing to collapse — flatten it.
              if (group.items.length === 1) {
                return (
                  <li key={group.id} className="nvoos-console-nav__item">
                    {renderLink(group.items[0])}
                  </li>
                );
              }
              const expanded = isGroupExpanded(group.id, group.defaultExpanded);
              return (
                <li key={group.id} className="nvoos-console-nav__group">
                  <button
                    type="button"
                    className="nvoos-console-nav__group-toggle"
                    aria-expanded={expanded}
                    aria-controls={`nvoos-console-nav-group-${group.id}`}
                    onClick={() => toggleGroup(group.id, group.defaultExpanded)}
                  >
                    <span className="nvoos-console-nav__group-label">{group.label}</span>
                    <span className="nvoos-console-nav__group-chevron" aria-hidden="true">
                      {expanded ? '▾' : '▸'}
                    </span>
                  </button>
                  <ul
                    id={`nvoos-console-nav-group-${group.id}`}
                    className="nvoos-console-nav__group-list"
                    hidden={!expanded}
                  >
                    {group.items.map((item) => (
                      <li key={item.path} className="nvoos-console-nav__item">
                        {renderLink(item)}
                      </li>
                    ))}
                  </ul>
                </li>
              );
            })}
          </ul>
        )}
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
        <button
          type="button"
          className="nvoos-console-nav__btn nvoos-console-nav__btn--signout"
          onClick={() => void handleSignOut()}
          disabled={signingOut}
          title="Sign out"
        >
          <span className="nvoos-console-nav__icon" aria-hidden="true">
            ⏻
          </span>
          {!collapsed && (
            <span className="nvoos-console-nav__label">
              {signingOut ? 'Signing out…' : 'Sign out'}
            </span>
          )}
        </button>
      </div>
    </aside>
  );
}
