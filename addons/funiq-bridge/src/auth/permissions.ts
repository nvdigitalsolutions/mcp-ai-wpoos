/**
 * Capability-driven gating for the Astro CMS — the console's
 * auth/permissions.ts pattern (Proposal 084 Phase 0).
 *
 * The WordPress REST layer is the enforcement boundary; these helpers are
 * UX-only gates. They consume the server-authored `user` block the /manage
 * gate (and the wp-admin loader) inject via `window.AstroAdminConfig` —
 * capabilities plus the server-computed read-only collection map, so the
 * SPA never probes endpoints it is not entitled to use.
 */

import type { CollectionSlug, NavSlug } from '../types';

/** Server-authored session identity (bridge GET /auth/me, camelCase). */
export interface AdminUser {
	/** True when the session may manage the store at all. */
	canManage?: boolean;
	/** Granted WordPress capability slugs (sorted). */
	capabilities?: string[];
	/** Collections the backend mode marks read-only (e.g. woo statuses). */
	readOnlyCollections?: string[];
}

/** Runtime config supplied by the host page (backend or /manage gate). */
interface AdminConfig {
	root?: string;
	nonce?: string;
	mediaBase?: string;
	user?: AdminUser;
}

/** Read the host config at call time (tests swap window.AstroAdminConfig). */
function getConfig(): AdminConfig {
	return (
		(typeof window !== 'undefined' && (window as any).AstroAdminConfig) || {}
	);
}

/** The session user as authored by the server (empty when absent). */
export function adminUser(): AdminUser {
	return getConfig().user ?? {};
}

/**
 * Whether the session holds a WordPress capability.
 *
 * Fail-closed for missing user data: the /manage gate itself is
 * server-enforced, so an absent user block should hide admin-only surfaces,
 * never show them.
 */
export function can(capability: string): boolean {
	return (adminUser().capabilities ?? []).includes(capability);
}

/**
 * Whether the session may write to a collection.
 *
 * Read-only comes from the server's mode-derived map (woo stock statuses),
 * not from client guesses.
 */
export function isCollectionWritable(slug: CollectionSlug | NavSlug): boolean {
	return !(adminUser().readOnlyCollections ?? []).includes(slug);
}

/**
 * Gate for a nav entry. Mirrors the console's NavGate/isNavItemVisible
 * contract: capability + read-only gates are declared once and consumed by
 * the sidebar and the content area alike.
 */
export interface NavGate {
	/** Required WordPress capability (undefined = no capability gate). */
	capability?: string;
	/** Read-only collections stay navigable but hide all write controls. */
	readOnlyAware?: boolean;
}

/** A catalogue entry — the single source of truth for sidebar + routing. */
export interface NavEntry extends NavGate {
	slug: NavSlug;
	label: string;
	section: 'catalog' | 'access';
}

/** Catalogue-driven nav (the console's CONSOLE_NAV_GROUPS pattern). */
export const NAV: NavEntry[] = [
	{ slug: 'products', label: 'Products', section: 'catalog', readOnlyAware: true },
	{ slug: 'categories', label: 'Categories', section: 'catalog', readOnlyAware: true },
	{ slug: 'brands', label: 'Brands', section: 'catalog', readOnlyAware: true },
	{ slug: 'colors', label: 'Colors', section: 'catalog', readOnlyAware: true },
	{ slug: 'statuses', label: 'Statuses', section: 'catalog', readOnlyAware: true },
	{ slug: 'promotions', label: 'Promotions', section: 'catalog', readOnlyAware: true },
	{ slug: 'promocodes', label: 'Promocodes', section: 'catalog', readOnlyAware: true },
	{ slug: 'managers', label: 'Managers', section: 'access' },
];

/**
 * Whether a nav entry is visible for this session.
 *
 * Fail-open like the console: an unavailable/absent user block hides only
 * what is explicitly gated — navigation is never hidden by a transient
 * config error. (All /manage sessions hold manage_astro server-side, so
 * the catalogue has no hard capability gates today; the seam exists so a
 * future owner-only section needs no rework.)
 */
export function isNavEntryVisible(entry: NavEntry): boolean {
	return !entry.capability || can(entry.capability);
}

/** Look up a catalogue entry by slug (undefined when unknown). */
export function navEntryFor(slug: NavSlug): NavEntry | undefined {
	return NAV.find((entry) => entry.slug === slug);
}

/**
 * The default landing slug — the first visible catalogue entry.
 */
export function defaultSlug(): NavSlug {
	return NAV.find(isNavEntryVisible)?.slug ?? 'products';
}
