/**
 * Pro SPA v2 — central permission utilities (capability-based access).
 *
 * Industry-standard pattern (OWASP REST Security / Authorization cheat
 * sheets, Auth0 and react-admin RBAC guidance):
 *
 *  - Checks are **capability-based** (WordPress caps), never role names —
 *    role slugs change per site, capabilities are the stable contract.
 *  - The server's `permission_callback` is **authoritative**; these
 *    client-side checks are UX-only. They hide unavailable surfaces and
 *    skip fetches that would only ever answer 403. Never use them as a
 *    security boundary.
 *  - **One source of truth** drives all three gates: nav visibility
 *    (`utils/navGates.ts`), component mounting (this hook), and
 *    endpoint-emptiness (the server SPA config empties admin-gated
 *    endpoints for non-admin sessions — mirrors of it must stay in sync,
 *    see examples/nvoos-complete-velocity/src/runtime-config.ts).
 *
 * Permission matrix (server contract this file mirrors):
 *
 *   manage_options — workflows, workflow-builder/presets/nodes/runs,
 *     analytics, cost, approvals, triggers, teams, portability, security,
 *     operations, diagnostics, paper-store, token-manager,
 *     orchestration/stats, pro/ops, schedule-manager, records/schema,
 *     dashboards/schema, chat-channels, remote-sites, media-cc,
 *     command-center
 *   edit_posts     — blueprints
 *   logged-in      — transcripts, memory, threads, assistants, settings,
 *     slash-commands (edit_posts), tool-shortcuts
 *   public/guest   — chat, chat-client, tools, wp/v2/media upload
 */

import { readProSpaConfig } from '../api/config';

/**
 * True when the user holds the capability. Missing/empty capability lists
 * fail closed to `false` — an unknown user never sees admin surfaces.
 *
 * @param capabilities Capability list from the runtime user object.
 * @param capability   WordPress capability to check (e.g. `manage_options`).
 */
export function can(
	capabilities: string[] | undefined,
	capability: string
): boolean {
	return Array.isArray( capabilities ) && capabilities.includes( capability );
}

export interface Permissions {
	/** The runtime capability list (empty when unknown). */
	capabilities: string[];
	/** Capability check bound to the current user. */
	can: ( capability: string ) => boolean;
	/** Shorthand for `can( 'manage_options' )`. */
	isAdmin: boolean;
}

/**
 * Current user's permission context, read synchronously from the SPA
 * runtime. The runtime global is set before mount in every surface
 * (console, embedded, shortcode), so no subscription is required — any
 * runtime swap re-renders the consumers that depend on it.
 */
export function usePermissions(): Permissions {
	const capabilities = readProSpaConfig()?.user?.capabilities ?? [];
	return {
		capabilities,
		can: ( capability: string ) => can( capabilities, capability ),
		isAdmin: can( capabilities, 'manage_options' ),
	};
}
