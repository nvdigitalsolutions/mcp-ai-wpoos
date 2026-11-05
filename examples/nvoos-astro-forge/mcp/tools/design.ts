// ---------------------------------------------------------------------------
// design.ts — the design_apply/design_verify tool slice (Proposal 114 P6).
//
// design_apply extracts the design language from a site and writes the v2
// design block into the site's profile (the registry-owned file or an
// explicit path) — the scaffold then derives the rebuild theme from it.
// Open-world URLs ride the same policy as site_analyze: registered hosts or
// NVOOS_FORGE_OPEN_ANALYZE=1, both behind the SSRF guard. design_verify
// re-extracts the built CSS and scores language parity — read-only.
// ---------------------------------------------------------------------------

import { readFile, readdir, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { z } from 'zod/v4';

import { analyzeBuiltCss, analyzeDesignInput, fetchDesignInput, scoreParity } from '../../lib/design.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import type { SiteProfile } from '../../lib/types.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, READ_ONLY_TOOL, text, WRITE_TOOL } from '../util.ts';

/** Resolve a target dir against the workdir, refusing escapes (scaffold.ts pattern). */
function resolveTarget( env: { workDir: string }, target: string ): string {
	const absolute = resolve( target );
	const work = resolve( env.workDir );
	if ( absolute !== work && ! absolute.startsWith( work + '/' ) && ! absolute.startsWith( work + '\\' ) ) {
		throw new Error( `Target ${ target } is outside the forge work directory (${ env.workDir }).` );
	}
	return absolute;
}

async function collectDistCss( targetDir: string ): Promise<string> {
	const chunks: string[] = [];
	async function walk( dir: string ): Promise<void> {
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			if ( entry.isDirectory() ) {
				if ( entry.name === 'node_modules' || entry.name === 'pagefind' ) {
					continue;
				}
				await walk( join( dir, entry.name ) );
				continue;
			}
			if ( entry.name.endsWith( '.css' ) ) {
				chunks.push( ( await readFile( join( dir, entry.name ), 'utf8' ) ).slice( 0, 1024 * 1024 ) );
			}
		}
	}
	await walk( join( targetDir, 'dist' ) );
	return chunks.join( '\n' ).slice( 0, 4 * 1024 * 1024 );
}

/** Resolve a profile: explicit path wins, then the site registry. */
async function resolveProfile( env: { homeDir: string }, site?: string, profilePath?: string ): Promise<{ profile: SiteProfile; profileFile?: string }> {
	if ( profilePath ) {
		return { profile: JSON.parse( await readFile( profilePath, 'utf8' ) ) as SiteProfile, profileFile: profilePath };
	}
	if ( site ) {
		const resolved = await new SitesStore( env.homeDir ).resolve( site );
		return { profile: resolved.profile, profileFile: resolved.ref.profile };
	}
	throw new Error( 'Provide `site` (registered name) or `profilePath`.' );
}

export const DESIGN_TOOLS: ToolDef[] = [
	{
		name: 'design_apply',
		title: 'Extract a site\'s design language and write the rebuild block',
		description:
			'Run the 114 design-language extraction (WordPress mode: homepage + bounded same-origin stylesheets, deterministic analysis — roles with confidence, unassigned pool, typography, spacing, radii, adaptation brief) and write the v2 design block into the site profile. The URL must be a registered site or NVOOS_FORGE_OPEN_ANALYZE=1; private/literal-IP ranges are always refused. Never copies source CSS/markup/assets — token data + prose only.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			url: z.string().url().optional(),
		} ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { site, profilePath, url } = args as { site?: string; profilePath?: string; url?: string };
			try {
				let profile: SiteProfile;
				let profileFile: string | undefined;
				if ( url ) {
					// Open-world path — the same policy as site_analyze.
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
					const resolved = await resolveProfile( env, site, profilePath );
					profile = resolved.profile;
					profileFile = resolved.profileFile;
				}
				if ( profile.collections.length === 0 && ! url ) {
					return errorResult( 'The profile declares no collections — nothing to theme.' );
				}
				const design = analyzeDesignInput( await fetchDesignInput( profile.url ) );
				profile.design = design;
				if ( profileFile ) {
					await writeFile( profileFile, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
				}
				return text( `Design language extracted (${ design.concept }) and written to the profile.`, {
					url: profile.url,
					concept: design.concept,
					roles: design.palette.roles,
					unassigned: design.palette.unassigned,
					typography: design.typography,
					written: Boolean( profileFile ),
					profileFile,
					adaptation: design.adaptation,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'design_verify',
		title: 'Score design-language parity between the source and the built site',
		description:
			'Re-extract the design language from a built project\'s dist CSS and score it against the profile\'s design block (role distance, family/scale/spacing overlap, concept signature). Language parity, never pixel parity. Requires a design block on the profile and a built target.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string(),
			threshold: z.number().min( 0 ).max( 1 ).optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, target, threshold } = args as {
				site?: string;
				profilePath?: string;
				target: string;
				threshold?: number;
			};
			try {
				const { profile } = await resolveProfile( env, site, profilePath );
				if ( ! profile.design ) {
					return errorResult( 'The profile has no design block — run design_apply first.' );
				}
				const targetDir = resolveTarget( env, target );
				const css = await collectDistCss( targetDir );
				const built = analyzeBuiltCss( css, profile.url );
				const report = scoreParity( profile.design, built, threshold ?? 0.6 );
				return text(
					`Language parity ${ Math.round( report.score * 100 ) }% (threshold ${ report.threshold }) — ${ report.pass ? 'PASS' : 'FAIL' }.`,
					{ report, source: report.source, target: report.target }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
