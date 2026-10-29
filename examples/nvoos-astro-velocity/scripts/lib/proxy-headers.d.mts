/**
 * Type declarations for scripts/lib/proxy-headers.mjs (pure BFF helpers).
 *
 * The serve layer is plain Node ESM (not bundled by Vite/Astro), while the
 * vitest unit suite is TypeScript — this declaration keeps `astro check`
 * (which type-checks the whole tree) happy about the cross-import.
 */

/** Minimal request shape requestOrigin() reads (headers + socket only). */
export type ProxyRequestLike = {
	headers: Record<string, string | string[] | undefined>;
	socket?: { encrypted?: boolean };
};

export function requestOrigin(req: ProxyRequestLike): string;

export const HOP_BY_HOP: Set<string>;

export function buildUpstreamHeaders(
	headers: Record<string, string | string[] | undefined>,
	options: { targetHost: string; origin: string }
): Record<string, string | string[] | undefined>;

export function stripDomainFromSetCookie(
	setCookies: string | string[] | undefined
): string | string[] | undefined;

export function rewriteLocation(
	location: string | undefined,
	targetSite: string
): string | undefined;

export function rewriteLoginPageHtml(html: string): string;

export function applyAdminResponseHeaders(
	headers: Record<string, string | string[] | number | undefined>,
	options: { targetSite: string }
): Record<string, string | string[] | number | undefined>;
