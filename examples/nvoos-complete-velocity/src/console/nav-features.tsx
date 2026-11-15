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
import type { ProSpaEndpoints } from '@nvoos/pro-spa-v2/api/config';

/** Gate for a nav item / route. All fields optional = always visible. */
export interface NavGate {
  /** Required WordPress capability (from users/me capabilities). */
  capability?: string;
  /** Required server-side feature key (from GET /mcp-ai/v1/features). */
  feature?: string;
  /**
   * Required runtime endpoint (a key of `ProSpaEndpoints`).
   *
   * A blank endpoint means this backend does not serve the surface — the
   * console blanks the URL when `/features` reports the key unavailable — so
   * the item is hidden rather than opening a page whose every request would
   * fail. Omit it for routes that are served on every runtime.
   */
  endpoint?: keyof ProSpaEndpoints;
}

/**
 * Endpoint availability: each key mapped to its URL (blank = not served).
 *
 * The concrete `ProSpaEndpoints` has no index signature, so it is widened here
 * rather than cast at every call site.
 */
export type EndpointMap = Partial<Record<keyof ProSpaEndpoints, string>>;

/** A console nav entry — path/label/icon shared by sidebar and guards. */
export interface ConsoleNavItem extends NavGate {
  path: string;
  label: string;
  icon: string;
  /** Match the path exactly (the dashboard lives at "/"). */
  end?: boolean;
}

/**
 * A collapsible sidebar section (W3C disclosure-navigation pattern).
 * Groups with a single visible item flatten to that item; empty groups hide.
 */
export interface ConsoleNavGroup {
  /** Stable id — used for the disclosure region and the localStorage pref. */
  id: string;
  label: string;
  items: ConsoleNavItem[];
  /** Start expanded unless the user has toggled it (active group always expands). */
  defaultExpanded?: boolean;
}

/**
 * The console route catalogue, grouped for the sidebar.
 *
 * Feature keys match the server-side WP_MCP_AI_Feature_Flags map;
 * capability gates mirror the endpoint gating in WP_MCP_AI_Pro_SPA_Config
 * (admin-only surfaces). Order within a group is the display order.
 */
export const CONSOLE_NAV_GROUPS: ConsoleNavGroup[] = [
  {
    id: 'workspace',
    label: 'Workspace',
    defaultExpanded: true,
    items: [
      		{ path: '/', label: 'Dashboard', icon: '📊', end: true },
      		{ path: '/chat', label: 'Chat', icon: '💬' },
      		{ path: '/assistants', label: 'Assistants', icon: '🤖' },
      		{ path: '/blueprints', label: 'Blueprints', icon: '🗂️', capability: 'edit_posts' },
      		{ path: '/tools', label: 'Tools', icon: '🧰' },
      		{ path: '/paper-store', label: 'Paper Store', icon: '📝', capability: 'manage_options' },
      		{ path: '/toolkits', label: 'Toolkits', icon: '🧩', capability: 'manage_options', endpoint: 'toolkits' },
    ],
  },
  {
    id: 'automation',
    label: 'Automation',
    items: [
      { path: '/workflows', label: 'Workflows', icon: '🔀', capability: 'manage_options' },
      { path: '/schedule-manager', label: 'Schedules', icon: '⏰', capability: 'manage_options' },
      { path: '/triggers', label: 'Triggers', icon: '⚡', capability: 'manage_options' },
      { path: '/slash-commands', label: 'Slash Commands', icon: '⌨️', capability: 'manage_options' },
      { path: '/approvals', label: 'Approvals', icon: '🛡️', capability: 'manage_options' },
      { path: '/operations', label: 'Operations', icon: '🔧', capability: 'manage_options' },
      { path: '/pro-ops', label: 'Pro Ops', icon: '🛰️', capability: 'manage_options' },
      {
        path: '/orchestration-stats',
        label: 'Orchestration',
        icon: '🧩',
        capability: 'manage_options',
      },
    ],
  },
  	{
  		id: 'command-centers',
  		label: 'Command Centers',
  		items: [
  			{ path: '/crm', label: 'CRM', icon: '🏢', capability: 'manage_options', feature: 'crm' },
  			{ path: '/pm', label: 'Projects', icon: '📁', capability: 'manage_options', feature: 'project_management' },
	  		{ path: '/professions', label: 'Professions', icon: '👔', capability: 'manage_options', endpoint: 'professions' },
	  		{ path: '/teams', label: 'Teams', icon: '👥', capability: 'manage_options' },
	  		{ path: '/org-teams', label: 'Org Teams', icon: '🧑‍🤝‍🧑', capability: 'manage_options', endpoint: 'orgTeams' },
  			{ path: '/calendar', label: 'Calendar', icon: '📅', capability: 'manage_options', feature: 'calendar_booking' },
  			{ path: '/fnb', label: 'Food & Beverage', icon: '🍽️', capability: 'manage_options', feature: 'fnb' },
	  		{ path: '/chat-channels', label: 'Channels', icon: '📨', capability: 'manage_options', endpoint: 'chatChannels' },
	  		{ path: '/connections', label: 'Connections', icon: '🔌', capability: 'manage_options', endpoint: 'remoteSites' },
	  		{ path: '/media-ops', label: 'Media Ops', icon: '🎞️', capability: 'manage_options', endpoint: 'mediaCc' },
  		],
  	},
  	{
  		id: 'commerce',
  		label: 'Commerce',
  		items: [
  			// Proposal 089 — the four commerce toolkits, gated on their
  			// enable toggles (server-side feature map mirrors the WooCommerce
  			// presence gate, so pages never render against a dead backend).
  			{ path: '/ecommerce', label: 'E-Commerce', icon: '🛒', capability: 'manage_options', feature: 'ecommerce' },
  			{ path: '/ezuite', label: 'EZuite ERP', icon: '🏭', capability: 'manage_options', feature: 'ezuite' },
  			{ path: '/shopify-sync', label: 'Shopify Sync', icon: '🛍️', capability: 'manage_options', feature: 'shopify_sync' },
  			{ path: '/flowhub', label: 'FlowHub', icon: '🌿', capability: 'manage_options', feature: 'flowhub' },
  			{ path: '/products', label: 'Products', icon: '🏷️', capability: 'manage_options', feature: 'ecommerce' },
  		],
  	},
  	{
  		id: 'content',
  		label: 'Content',
  		items: [
  			// Proposal 089 Phase 4e — posts/pages browse surface. Gated on
  			// manage_options to match the records schema endpoint's contract
  			// (widening to edit_posts is a follow-up).
  			{ path: '/content', label: 'Posts & Pages', icon: '📰', capability: 'manage_options', feature: 'content_records' },
  		],
  	},
  {
    id: 'insights',
    label: 'Insights',
    items: [
      { path: '/dashboards', label: 'Dashboards', icon: '🧮', capability: 'manage_options' },
      { path: '/analytics', label: 'Analytics', icon: '📈', capability: 'manage_options' },
      { path: '/diagnostics', label: 'Diagnostics', icon: '🩺', capability: 'manage_options' },
      { path: '/security-center', label: 'Security', icon: '🔐', capability: 'manage_options' },
    ],
  },
  {
    id: 'admin',
    label: 'Admin',
    items: [
      { path: '/token-manager', label: 'Tokens', icon: '🔑', capability: 'manage_options' },
      { path: '/portability', label: 'Portability', icon: '📦', capability: 'manage_options' },
      { path: '/gateway', label: 'Gateway', icon: '🌐', capability: 'manage_options' },
      {
        path: '/knowledge-graph',
        label: 'Knowledge Graph',
        icon: '🕸️',
        capability: 'manage_options',
        feature: 'knowledge_graph',
        endpoint: 'contentGraph',
      },
      {
        path: '/docs',
        label: 'Docs',
        icon: '📚',
        capability: 'manage_options',
        feature: 'docs_hub',
        endpoint: 'docsHub',
      },
      {
        path: '/media',
        label: 'Media Studio',
        icon: '🎨',
        capability: 'manage_options',
        feature: 'media_studio',
        endpoint: 'mediaStudio',
      },
      {
        path: '/documents',
        label: 'Documents',
        icon: '📄',
        capability: 'manage_options',
        feature: 'document_editor',
        endpoint: 'documentEditor',
      },
      { path: '/settings', label: 'Settings', icon: '⚙️', capability: 'manage_options' },
    ],
  },
];

/**
 * Flat route catalogue, derived from the groups.
 *
 * The route guards and gate lookup (`gateForPath`) need the flat list; the
 * sidebar consumes the groups directly. Both read the same declarations, so
 * they can never disagree.
 */
export const CONSOLE_NAV_ITEMS: ConsoleNavItem[] = CONSOLE_NAV_GROUPS.flatMap(
  ( group ) => group.items,
);

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
  endpoints?: EndpointMap,
): boolean {
  if (gate.capability && !(capabilities ?? []).includes(gate.capability)) {
    return false;
  }
  if (gate.feature && features && features[gate.feature] === false) {
    return false;
  }
  // Fail open when the endpoint map is absent, for the same reason the feature
  // map does: a transient `/features` failure must not empty the sidebar.
  if (gate.endpoint && endpoints && !endpoints[gate.endpoint]) {
    return false;
  }
  return true;
}

/**
 * Operator-facing copy for a supply class (`endpoint_reasons`).
 *
 * One map so the dashboard and any future surface cannot describe the same
 * code two ways. Unknown codes fall back to the generic sentence below.
 */
const REASON_COPY: Record<string, string> = {
  'external-product': 'Supplied by a separate product that is not connected.',
  'toolkit-shaped': 'Belongs to a toolkit family that is not built yet.',
  'connected-service': 'Needs an external service to be configured.',
  'runtime-adaptable': 'Not exposed as a route on this runtime yet.',
  'not-installed': 'The addon that supplies this is not active.',
  'route': 'The route is missing — report this as a regression.',
};

/** One surface this session could use but this runtime cannot serve. */
export interface UnavailableSurface {
  path: string;
  label: string;
  reason: string;
}

/**
 * Surfaces the current session would otherwise see, hidden because the runtime
 * (or the site's settings) cannot serve them.
 *
 * A capability the session lacks is *not* included: that is a permission, not
 * a runtime limitation, and advertising it would leak admin-only surfaces.
 * The endpoint/feature decision reuses {@see isNavItemVisible} so the card and
 * the sidebar can never disagree.
 */
export function unavailableNavItems(
  features?: Record<string, boolean>,
  capabilities?: string[],
  endpoints?: EndpointMap,
  reasons?: Record<string, string>,
): UnavailableSurface[] {
  const caps = capabilities ?? [];
  const result: UnavailableSurface[] = [];

  for (const item of CONSOLE_NAV_ITEMS) {
    if (item.capability && !caps.includes(item.capability)) {
      continue;
    }
    if (!item.endpoint && !item.feature) {
      continue;
    }
    if (isNavItemVisible(item, features, caps, endpoints)) {
      continue;
    }

    const endpointBlank =
      !!item.endpoint &&
      !isNavItemVisible({ endpoint: item.endpoint }, undefined, caps, endpoints);
    const code = item.endpoint ? reasons?.[item.endpoint] : undefined;

    result.push({
      path: item.path,
      label: item.label,
      reason: endpointBlank
        ? REASON_COPY[code ?? ''] ?? 'Not available on this runtime.'
        : 'Disabled on this site.',
    });
  }

  return result;
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
