// ---------------------------------------------------------------------------
// tracking.ts — the tracker & measurement tool slice (Proposal 130).
//
// The forge never pastes vendor snippets: these tools DETECT the source's
// measurement surface (analytics, ad pixels, CMP, verification tokens) as
// facts and write the tracking block with every tracker DISABLED. Enabling a
// tracker is an explicit operator act in the profile.
// ---------------------------------------------------------------------------

import { readFile, writeFile } from 'node:fs/promises';
import { z } from 'zod';

import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import { analyzeTracking, mergeTrackingDraft, renderTrackingReport, trackingFromReport } from '../../lib/tracking.ts';
import type { SiteProfile } from '../../lib/types.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, READ_ONLY_TOOL, text, WRITE_TOOL } from '../util.ts';

/** Resolve a profile: explicit path wins, then the site registry. */
async function resolveProfile(
	env: { homeDir: string },
	site?: string,
	profilePath?: string
): Promise<{ profile: SiteProfile; profileFile?: string }> {
	if ( profilePath ) {
		return { profile: JSON.parse( await readFile( profilePath, 'utf8' ) ) as SiteProfile, profileFile: profilePath };
	}
	if ( site ) {
		const resolved = await new SitesStore( env.homeDir ).resolve( site );
		return { profile: resolved.profile, profileFile: resolved.ref.profile };
	}
	throw new Error( 'Provide `site` (registered name) or `profilePath`.' );
}

/** Same-origin representative pages from the profile's inventory. */
function pageUrls( profile: SiteProfile, total: number ): string[] {
	if ( total <= 1 ) {
		return [];
	}
	const base = profile.url.replace( /\/+$/, '' );
	const junk = new Set( [ 'cart', 'checkout', 'my-account', 'login', 'search' ] );
	return [ ...new Set( profile.inventory?.navPages ?? [] ) ]
		.filter( ( path ) => {
			const slug = String( path ).split( '/' ).filter( Boolean ).pop() ?? '';
			return slug && ! junk.has( slug );
		} )
		.slice( 0, total - 1 )
		.map( ( path ) => new URL( path, base ).href );
}

const TRACKING_SCHEMA = z.object( {
	site: z.string().optional(),
	profilePath: z.string().optional(),
	url: z.string().url().optional(),
	pages: z.number().int().min( 1 ).max( 4 ).optional(),
	probe: z.boolean().optional(),
} );

export const TRACKING_TOOLS: ToolDef[] = [
	{
		name: 'tracking_analyze',
		title: 'Detect the source site\'s trackers, pixels and consent layer',
		description:
			'Read the rendered source (homepage + up to three representative pages, optionally a real-browser probe) and report every measurement tag as FACTS: Google Analytics/Tag Manager, ad pixels (Meta, TikTok, LinkedIn, Pinterest, X, Snap, Reddit, Bing), session tools, the consent management platform, and the ownership-verification tokens. Never enables anything, never copies a vendor snippet, never reads a cookie value. Registry sites only (or NVOOS_FORGE_OPEN_ANALYZE=1).',
		schema: TRACKING_SCHEMA,
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, url, pages, probe } = args as {
				site?: string;
				profilePath?: string;
				url?: string;
				pages?: number;
				probe?: boolean;
			};
			try {
				let profile: SiteProfile;
				if ( url ) {
					assertSafeHttpUrl( url );
					const store = new SitesStore( env.homeDir );
					const registryHosts = Object.values( await store.list() ).map( ( ref ) => ref.url );
					if ( ! isFetchAllowed( url, registryHosts, env.openAnalyze ) ) {
						return errorResult(
							`"${ url }" is not a registered site — add it via site_add, or start the server with NVOOS_FORGE_OPEN_ANALYZE=1 for the documented open-analyze mode.`
						);
					}
					profile = { url: url.replace( /\/+$/, '' ), collections: [] };
				} else {
					profile = ( await resolveProfile( env, site, profilePath ) ).profile;
				}
				const report = await analyzeTracking( {
					url: profile.url,
					pageUrls: pageUrls( profile, pages ?? 3 ),
					probe: probe === true,
				} );
				const summary = renderTrackingReport( report );
				return text( summary, {
					url: report.url,
					pagesAnalyzed: report.pagesAnalyzed,
					trackers: report.trackers,
					consent: report.consent,
					verification: report.verification,
					warnings: report.warnings,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'tracking_apply',
		title: 'Detect trackers and write the tracking block (disabled)',
		description:
			'Run the tracking detection and MERGE the result into the registered profile: every detected vendor arrives with enabled:false, the consent block defaults to denied/basic with the source CMP recorded, verification tokens are captured, and the CSP host list is derived from the enabled set. Operator decisions (enabled flags, consent config, delivery/ecommerce) always win on a re-run.',
		schema: TRACKING_SCHEMA,
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { site, profilePath, pages, probe } = args as {
				site?: string;
				profilePath?: string;
				url?: string;
				pages?: number;
				probe?: boolean;
			};
			try {
				const { profile, profileFile } = await resolveProfile( env, site, profilePath );
				if ( ! profileFile ) {
					return errorResult( 'Register the site first (site_add) or pass profilePath — there is nowhere to write.' );
				}
				const report = await analyzeTracking( {
					url: profile.url,
					pageUrls: pageUrls( profile, pages ?? 3 ),
					probe: probe === true,
				} );
				const fresh = trackingFromReport( report, {
					hasCommerce: profile.collections.some( ( collection ) => collection.slug === 'products' ),
				} );
				profile.tracking = mergeTrackingDraft( profile.tracking, fresh );
				await writeFile( profileFile, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
				return text(
					`Tracking written to the profile — ${ profile.tracking.trackers.length } vendor(s), all disabled until you promote them.`,
					{
						url: profile.url,
						trackers: profile.tracking.trackers,
						consent: profile.tracking.consent,
						verification: profile.tracking.verification,
						warnings: report.warnings,
						written: true,
						profileFile,
					}
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
