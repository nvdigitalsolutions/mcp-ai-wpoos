/**
 * Admin config hand-off for the standalone Astro CMS bundle.
 *
 * Mirrors the Complete Console's buildConsoleRuntime(): the server-authored
 * session (from GET /api/auth/me) plus a freshly minted REST nonce become
 * the single config object the prebuilt admin bundle reads — capabilities
 * and the read-only collection map included, so the bundle's gating is
 * server-driven and never guesses.
 */

import type { Customer } from './commerce/types';

/** Shape injected as window.AstroAdminConfig for the admin bundle. */
export interface ManageAdminConfig {
	root: string;
	mediaBase: string;
	nonce: string;
	user: {
		canManage: boolean;
		capabilities: string[];
		readOnlyCollections: string[];
	};
}

/**
 * Build the admin bundle config for a confirmed manager session.
 */
export function buildAdminConfig(
	customer: Customer | null,
	nonce: string,
	apiBase: string
): ManageAdminConfig {
	return {
		root: apiBase,
		mediaBase: '/media/',
		nonce,
		user: {
			canManage: customer?.canManage ?? false,
			capabilities: customer?.capabilities ?? [],
			readOnlyCollections: customer?.readOnlyCollections ?? [],
		},
	};
}
