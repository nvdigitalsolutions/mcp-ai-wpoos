// ---------------------------------------------------------------------------
// discovery.ts — the site_analyze tool slice (096 §3.3). Open-world READ:
// registry URLs always pass; arbitrary URLs need NVOOS_FORGE_OPEN_ANALYZE=1;
// both paths are behind the SSRF guard (private/literal ranges refused).
// ---------------------------------------------------------------------------

import { z } from 'zod/v4';

import { analyzeSite, profileFromDiscovery, renderReport } from '../../lib/discovery.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import { WpClient } from '../../lib/wp-client.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, OPEN_WORLD_READ_TOOL, text } from '../util.ts';

export const DISCOVERY_TOOLS: ToolDef[] = [
	{
		name: 'site_analyze',
		title: 'Analyze a WordPress site (content, CPTs, Woo, SEO)',
		description:
			'Run the polite discovery pass (~6 requests): root document, post types, per-collection counts, the static front page, junk-page heuristics, WooCommerce detection, and SEO-plugin detection. Returns the report plus a scaffold-ready draft profile. The URL must be a registered site (the operator allowlist) or the server must have NVOOS_FORGE_OPEN_ANALYZE=1; private/loopback/literal-IP ranges are always refused.',
		schema: z.object( { url: z.string().url() } ),
		annotations: OPEN_WORLD_READ_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { url } = args as { url: string };
			try {
				assertSafeHttpUrl( url );
				const store = new SitesStore( env.homeDir );
				const registryHosts = Object.values( await store.list() ).map( ( ref ) => ref.url );
				if ( ! isFetchAllowed( url, registryHosts, env.openAnalyze ) ) {
					return errorResult(
						`"${ url }" is not a registered site — add it via site_add, or start the server with NVOOS_FORGE_OPEN_ANALYZE=1 for the documented open-analyze mode.`
					);
				}
				const report = await analyzeSite( new WpClient( { baseUrl: url.replace( /\/+$/, '' ) } ) );
				const profile = profileFromDiscovery( report );
				return text( renderReport( report ) + '\n--- draft site.json ---\n' + JSON.stringify( profile, null, '\t' ), {
					report,
					draftProfile: profile,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
