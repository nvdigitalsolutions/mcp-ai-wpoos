/**
 * Nav gating — single source of truth for the console's route catalogue.
 *
 * Every route declares its availability in one place (capability + feature),
 * so the sidebar, the dashboard quick actions, and the route guards can
 * never disagree. Visibility is UX only — the WordPress REST layer remains
 * the enforcement (Proposal 084 Phase 0).
 */

import { Navigate } from 'react-router-dom';
import type { JSX, ReactNode } from 'react';

/** Gate for a nav item / route. Both fields optional = always visible. */
export interface NavGate {
  /** Required WordPress capability (from users/me capabilities). */
  capability?: string;
  /** Required server-side feature key (from GET /mcp-ai/v1/features). */
  feature?: string;
}

/** A console nav entry — path/label/icon shared by sidebar and guards. */
export interface ConsoleNavItem extends NavGate {
  path: string;
  label: string;
  icon: string;
  /** Match the path exactly (the dashboard lives at "/"). */
  end?: boolean;
}

/**
 * The console route catalogue. Feature keys match the server-side
 * WP_MCP_AI_Feature_Flags map; capability gates mirror the endpoint gating
 * in WP_MCP_AI_Pro_SPA_Config (admin-only surfaces).
 */
export const CONSOLE_NAV_ITEMS: ConsoleNavItem[] = [
  { path: '/', label: 'Dashboard', icon: '📊', end: true },
  { path: '/chat', label: 'Chat', icon: '💬' },
  { path: '/assistants', label: 'Assistants', icon: '🤖' },
  { path: '/tools', label: 'Tools', icon: '🧰' },
  { path: '/workflows', label: 'Workflows', icon: '🔀', capability: 'manage_options' },
  { path: '/analytics', label: 'Analytics', icon: '📈', capability: 'manage_options' },
  {
    path: '/knowledge-graph',
    label: 'Knowledge Graph',
    icon: '🕸️',
    capability: 'manage_options',
    feature: 'knowledge_graph',
  },
  {
    path: '/docs',
    label: 'Docs',
    icon: '📚',
    capability: 'manage_options',
    feature: 'docs_hub',
  },
  {
    path: '/media',
    label: 'Media Studio',
    icon: '🎨',
    capability: 'manage_options',
    feature: 'media_studio',
  },
  {
    path: '/documents',
    label: 'Documents',
    icon: '📄',
    capability: 'manage_options',
    feature: 'document_editor',
  },
  { path: '/settings', label: 'Settings', icon: '⚙️', capability: 'manage_options' },
  { path: '/approvals', label: 'Approvals', icon: '🛡️', capability: 'manage_options' },
  {
    path: '/slash-commands',
    label: 'Slash Commands',
    icon: '⌨️',
    capability: 'manage_options',
  },
  {
    path: '/triggers',
    label: 'Triggers',
    icon: '⚡',
    capability: 'manage_options',
  },
  { path: '/teams', label: 'Teams', icon: '👥', capability: 'manage_options' },
  { path: '/crm', label: 'CRM', icon: '🏢', capability: 'manage_options' },
  { path: '/pm', label: 'Projects', icon: '📁', capability: 'manage_options' },
  { path: '/professions', label: 'Professions', icon: '👔', capability: 'manage_options' },
  { path: '/calendar', label: 'Calendar', icon: '📅', capability: 'manage_options' },
  { path: '/fnb', label: 'Food & Beverage', icon: '🍽️', capability: 'manage_options' },
  { path: '/dashboards', label: 'Dashboards', icon: '🧮', capability: 'manage_options' },
  { path: '/chat-channels', label: 'Channels', icon: '📨', capability: 'manage_options' },
  {
    path: '/portability',
    label: 'Portability',
    icon: '📦',
    capability: 'manage_options',
  },
  {
    path: '/security-center',
    label: 'Security',
    icon: '🔐',
    capability: 'manage_options',
  },
  {
    path: '/operations',
    label: 'Operations',
    icon: '🔧',
    capability: 'manage_options',
  },
  {
    path: '/diagnostics',
    label: 'Diagnostics',
    icon: '🩺',
    capability: 'manage_options',
  },
  {
    path: '/token-manager',
    label: 'Tokens',
    icon: '🔑',
    capability: 'manage_options',
  },
  {
    path: '/orchestration-stats',
    label: 'Orchestration',
    icon: '🧩',
    capability: 'manage_options',
  },
  {
    path: '/pro-ops',
    label: 'Pro Ops',
    icon: '🛰️',
    capability: 'manage_options',
  },
  {
    path: '/schedule-manager',
    label: 'Schedules',
    icon: '⏰',
    capability: 'manage_options',
  },
];

/**
 * Decide whether a gated item is visible for this session.
 *
 * Fail-open on the feature map: when the map is absent (fetch failed, older
 * plugin) the item stays visible — a nav item is never hidden by a transient
 * error. Explicit `false` from the server hides the item.
 */
export function isNavItemVisible(
  gate: NavGate,
  features?: Record<string, boolean>,
  capabilities?: string[],
): boolean {
  if (gate.capability && !(capabilities ?? []).includes(gate.capability)) {
    return false;
  }
  if (gate.feature && features && features[gate.feature] === false) {
    return false;
  }
  return true;
}

/** Look up the gate for a route path (undefined when the path is unknown). */
export function gateForPath(path: string): NavGate {
  return CONSOLE_NAV_ITEMS.find((item) => item.path === path) ?? {};
}

export interface RequireGateProps {
  gate: NavGate;
  features?: Record<string, boolean>;
  capabilities?: string[];
  children: ReactNode;
}

/**
 * Route guard — redirects a directly-visited gated route to the dashboard
 * when the session is not entitled to it (nav hiding is UX; this covers
 * bookmarks and hand-edited URLs).
 */
export function RequireGate({
  gate,
  features,
  capabilities,
  children,
}: RequireGateProps): JSX.Element {
  if (!isNavItemVisible(gate, features, capabilities)) {
    return <Navigate to="/" replace />;
  }
  return <>{children}</>;
}
