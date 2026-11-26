// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// gate.ts — the page-gate tool slice (Proposal 152).
//
// One read-only tool: report whether a source's RENDERED HTML is open, in
// maintenance / coming-soon mode, behind an edge gate, or throttled — plus the
// ordered remediation ladder. Credentials come from the server's environment
// (WP_GATE_*, CF_ACCESS_CLIENT_*, WP_GATE_HTTP_AUTH); the payload never
// carries a secret, only mechanism names.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';
import { z } from 'zod/v4';

import { openGate } from '../../lib/gate.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import type { SiteProfile } from '../../lib/types.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, OPEN_WORLD_READ_TOOL, text } from '../util.ts';

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
	throw new Error( 'Provide `site` (registered name), `profilePath`, or `url`.' );
}

export const GATE_TOOLS: ToolDef[] = [
	{
		name: 'gate_probe',
		title: 'Diagnose a source page gate (maintenance / coming soon / staging auth)',
		description:
			'Report whether a WordPress source\'s rendered HTML is open, behind a maintenance/coming-soon gate, behind an edge gate (Cloudflare Access, staging basic auth), or throttled — with the /wp-json/ health cross-check and an ordered remediation ladder. Read-only: it opens a session only from the server\'s WP_GATE_* environment, and never returns a credential.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			url: z.string().url().optional(),
		} ),
		annotations: OPEN_WORLD_READ_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, url } = args as { site?: string; profilePath?: string; url?: string };
			try {
				let profile: SiteProfile;
				if ( url ) {
					// The same open-world policy as site_analyze / design_apply.
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

				const gate = await openGate( profile );
				const description = gate.describe();
				const probe = gate.probe;
				gate.close();

				const lines = [
					`${ profile.url } — ${ probe.state }${ probe.fingerprint ? ` (${ probe.fingerprint })` : '' }`,
					`HTTP ${ probe.status }${ probe.retryAfter !== undefined ? `, Retry-After ${ probe.retryAfter }s` : '' }; /wp-json/ ${ probe.restHealthy ? '200' : 'not 200' }`,
					`mechanism: ${ description.mechanism.join( ', ' ) }`,
					...probe.evidence.map( ( line ) => `· ${ line }` ),
				];
				if ( probe.remediation.length > 0 ) {
					lines.push( 'remediation:' );
					probe.remediation.forEach( ( line, index ) => lines.push( `${ index + 1 }. ${ line }` ) );
				}
				return text( lines.join( '\n' ), { gate: description, probe } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
