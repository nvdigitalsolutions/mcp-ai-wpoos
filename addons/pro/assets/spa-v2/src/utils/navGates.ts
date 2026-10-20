/**
 * Nav gating — spa-v2's route catalogue for the command palette.
 *
 * Mirrors the Complete Console's `src/console/nav-features.tsx` table
 * (Proposal 084 Phase 0) — keep the capability/feature values in sync with
 * that file. Visibility is UX only: the WordPress REST layer remains the
 * enforcement, and the pages themselves render unavailable states when
 * their endpoint is empty.
 */

import { __ } from '@wordpress/i18n';

/** Gate for a route. Both fields optional = always visible. */
export interface SpaV2NavGate {
	/** Required WordPress capability (from runtime.user.capabilities). */
	capability?: string;
	/** Required server-side feature key (from runtime.features). */
	feature?: string;
}

export interface SpaV2NavRoute extends SpaV2NavGate {
	/** Command-palette command id. */
	id: string;
	/** Hash route target (e.g. '#/docs'). */
	hash: string;
	label: string;
	description: string;
}

/**
 * Feature keys match the server-side WP_MCP_AI_Feature_Flags map; capability
 * gates mirror the endpoint gating in WP_MCP_AI_Pro_SPA_Config (admin-only
 * surfaces).
 */
export const SPA_V2_NAV_ROUTES: SpaV2NavRoute[] = [
	{
		id: 'nav-chat',
		hash: '#/chat',
		label: __( 'Go to Chat', 'nvoos-pro-spa' ),
		description: __( 'Open the agent chat panel', 'nvoos-pro-spa' ),
	},
	{
		id: 'nav-settings',
		hash: '#/settings',
		label: __( 'Go to Settings', 'nvoos-pro-spa' ),
		description: __( 'Open plugin settings', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-tools',
		hash: '#/tools',
		label: __( 'Go to Tools', 'nvoos-pro-spa' ),
		description: __( 'Browse tool registry', 'nvoos-pro-spa' ),
	},
	{
		id: 'nav-assistants',
		hash: '#/assistants',
		label: __( 'Go to Assistants', 'nvoos-pro-spa' ),
		description: __( 'Manage assistants', 'nvoos-pro-spa' ),
	},
	{
		id: 'nav-workflows',
		hash: '#/workflows',
		label: __( 'Go to Workflows', 'nvoos-pro-spa' ),
		description: __( 'Build workflows', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-analytics',
		hash: '#/analytics',
		label: __( 'Go to Analytics', 'nvoos-pro-spa' ),
		description: __( 'View usage dashboard', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-knowledge-graph',
		hash: '#/knowledge-graph',
		label: __( 'Go to Knowledge Graph', 'nvoos-pro-spa' ),
		description: __( 'Explore the content graph', 'nvoos-pro-spa' ),
		capability: 'manage_options',
		feature: 'knowledge_graph',
	},
	{
		id: 'nav-docs',
		hash: '#/docs',
		label: __( 'Go to Docs', 'nvoos-pro-spa' ),
		description: __( 'Browse the documentation', 'nvoos-pro-spa' ),
		capability: 'manage_options',
		feature: 'docs_hub',
	},
	{
		id: 'nav-media',
		hash: '#/media',
		label: __( 'Go to Media Studio', 'nvoos-pro-spa' ),
		description: __( 'Open the media editor', 'nvoos-pro-spa' ),
		capability: 'manage_options',
		feature: 'media_studio',
	},
	{
		id: 'nav-documents',
		hash: '#/documents',
		label: __( 'Go to Document Editor', 'nvoos-pro-spa' ),
		description: __( 'Open the document editor', 'nvoos-pro-spa' ),
		capability: 'manage_options',
		feature: 'document_editor',
	},
	{
		id: 'nav-approvals',
		hash: '#/approvals',
		label: __( 'Go to Approvals', 'nvoos-pro-spa' ),
		description: __( 'Review the approval queue', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-slash-commands',
		hash: '#/slash-commands',
		label: __( 'Go to Slash Commands', 'nvoos-pro-spa' ),
		description: __( 'Browse and run slash commands', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-workflow-triggers',
		hash: '#/triggers',
		label: __( 'Go to Workflow Triggers', 'nvoos-pro-spa' ),
		description: __( 'Manage workflow triggers', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-teams',
		hash: '#/teams',
		label: __( 'Go to Teams', 'nvoos-pro-spa' ),
		description: __( 'Browse multi-agent teams', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-portability',
		hash: '#/portability',
		label: __( 'Go to Assistant Portability', 'nvoos-pro-spa' ),
		description: __( 'Export and import assistants', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-security-center',
		hash: '#/security-center',
		label: __( 'Go to Security Center', 'nvoos-pro-spa' ),
		description: __( 'Review the security posture', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-operations',
		hash: '#/operations',
		label: __( 'Go to Operations', 'nvoos-pro-spa' ),
		description: __( 'Cron jobs and the dead-letter queue', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-diagnostics',
		hash: '#/diagnostics',
		label: __( 'Go to Diagnostics', 'nvoos-pro-spa' ),
		description: __( 'Consolidated health and system state', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-token-manager',
		hash: '#/token-manager',
		label: __( 'Go to Token Manager', 'nvoos-pro-spa' ),
		description: __( 'Assistant credentials and user restrictions', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-orchestration-stats',
		hash: '#/orchestration-stats',
		label: __( 'Go to Orchestration', 'nvoos-pro-spa' ),
		description: __( 'Multi-agent and orchestration statistics', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
	{
		id: 'nav-pro-ops',
		hash: '#/pro-ops',
		label: __( 'Go to Pro Ops', 'nvoos-pro-spa' ),
		description: __( 'Service status, maintenance, incidents, webhooks', 'nvoos-pro-spa' ),
		capability: 'manage_options',
	},
];

/**
 * Decide whether a gated route is visible for this session.
 *
 * Fail-open on the feature map: when the map is absent (older plugin) the
 * route stays visible — a nav entry is never hidden by a transient error.
 * Explicit `false` from the server hides it.
 */
export function isRouteVisible(
	gate: SpaV2NavGate,
	features?: Record< string, boolean >,
	capabilities?: string[],
): boolean {
	if ( gate.capability && ! ( capabilities ?? [] ).includes( gate.capability ) ) {
		return false;
	}
	if ( gate.feature && features && features[ gate.feature ] === false ) {
		return false;
	}
	return true;
}
