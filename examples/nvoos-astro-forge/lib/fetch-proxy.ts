// ---------------------------------------------------------------------------
// fetch-proxy.ts — the build-time proxy seam (Proposal 116 follow-up).
//
// Content builds fetch the WordPress source directly; hosts with aggressive
// bot protection (Imunify360 / Cloudflare / Cloudways Anti-Bot) block
// datacenter egress — e.g. GitHub Actions runner IPs. Setting WP_FETCH_PROXY
// routes EVERY fetch in the build (content layer + the image service)
// through an operator-provided HTTP(S) proxy whose IP is allowlisted.
//
//   WP_FETCH_PROXY=http://user:pass@proxy.example:8080
//
// No proxy ⇒ a no-op (the direct path is unchanged). A misconfigured proxy
// fails the build LOUDLY — a silently bypassed proxy would defeat the point
// of allowlisting. The proxy URI may carry credentials in the userinfo
// (undici parses them). The global dispatcher also covers Astro's remote
// image fetches, which run inside the same build process.
// ---------------------------------------------------------------------------

import { ProxyAgent, setGlobalDispatcher } from 'undici';

export const PROXY_ENV = 'WP_FETCH_PROXY';

/**
 * Install the global undici proxy dispatcher when WP_FETCH_PROXY is set.
 * Idempotent: calling it again replaces the dispatcher (same proxy). Throws
 * on an invalid URI — fail closed, never silently bypass.
 */
export function configureFetchProxy( env: Record<string, string | undefined> = process.env ): void {
	const uri = ( env[ PROXY_ENV ] ?? '' ).trim();
	if ( ! uri ) {
		return;
	}
	let parsed: URL;
	try {
		parsed = new URL( uri );
	} catch {
		throw new Error( `Fetch proxy: ${ PROXY_ENV } is not a valid URI — refusing to build.` );
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		throw new Error( `Fetch proxy: ${ PROXY_ENV } must be http:// or https:// — refusing to build.` );
	}
	setGlobalDispatcher( new ProxyAgent( { uri } ) );
}
