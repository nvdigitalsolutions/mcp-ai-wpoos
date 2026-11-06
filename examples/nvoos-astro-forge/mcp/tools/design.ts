// ---------------------------------------------------------------------------
// design.ts — the design_apply/design_verify/design_verify_visual tool slice
// (Proposal 114 P6 + 118 §5).
//
// design_apply extracts the design language from a site and writes the v2
// design block (incl. the 117 section-background pass) into the site's
// profile — the scaffold then derives the rebuild theme from it.
// Open-world URLs ride the same policy as site_analyze: registered hosts or
// NVOOS_FORGE_OPEN_ANALYZE=1, both behind the SSRF guard. design_verify
// re-extracts the built CSS and scores language parity — read-only.
// design_verify_visual runs the 118 vision comparison (data-URL images,
// NVOOS_FORGE_VISION_* env, or the capture contract) — read-only.
// ---------------------------------------------------------------------------

import { readFile, readdir, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { z } from 'zod/v4';

import { captureEndpoint, captureRemote, readArtifact } from '../../lib/capture.ts';
import { analyzeBuiltCss, analyzeDesignInput, fetchDesignInput, scoreParity } from '../../lib/design.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import type { SiteProfile } from '../../lib/types.ts';
import { COMPARE_PROMPT, visionCompare, visionConfig } from '../../lib/vision.ts';
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
			'Run the 114 design-language extraction (WordPress mode: homepage + bounded same-origin stylesheets, deterministic analysis — roles with confidence, unassigned pool, typography, spacing, radii, adaptation brief, and the 117 section-background pass for per-band theming) and write the v2 design block into the site profile. The URL must be a registered site or NVOOS_FORGE_OPEN_ANALYZE=1; private/literal-IP ranges are always refused. Never copies source CSS/markup/assets — token data + prose only.',
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
					sections: design.sections,
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
	{
		name: 'design_verify_visual',
		title: 'Compare source and built screenshots with a vision model',
		description:
			'The visual layer (118): compare a screenshot of the SOURCE site against a screenshot of the BUILT project with a vision model (any OpenAI-compatible endpoint — NVOOS_FORGE_VISION_BASE_URL/_KEY/_MODEL on the server). Images travel as base64 data URLs in the request body, never hosted URLs. The source screenshot comes from the NVOOS_FORGE_CAPTURE_URL endpoint or a dropped <target>/verify-artifacts/<label>-source.{png,jpg}; the built screenshot from a dropped <target>/verify-artifacts/<label>-built.{png,jpg} (the operator/agent captures it — the capture endpoint cannot reach local builds). Returns the fidelity 0-100 plus concrete divergences. Advisory by nature (vision models catch big changes and miss small ones) — pair with design_verify for deterministic gates.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, target } = args as { site?: string; profilePath?: string; target: string };
			try {
				const config = visionConfig();
				if ( ! config ) {
					return errorResult(
						'Vision credentials are not configured on the server — set NVOOS_FORGE_VISION_BASE_URL / NVOOS_FORGE_VISION_KEY / NVOOS_FORGE_VISION_MODEL.'
					);
				}
				const { profile } = await resolveProfile( env, site, profilePath );
				const targetDir = resolveTarget( env, target );
				const host = ( () => { try { return new URL( String( profile.url ) ).hostname; } catch { return ''; } } )();
				const label = ( profile.name ?? ( host.replace( /^www\./, '' ) || 'site' ) )
					.toLowerCase()
					.replace( /[^a-z0-9]+/g, '-' )
					.replace( /(^-|-$)/g, '' );
				const dir = join( targetDir, 'verify-artifacts' );
				const endpoint = captureEndpoint();
				let source = endpoint ? await captureRemote( endpoint, String( profile.url ), `${ label }-source` ) : undefined;
				source = source ?? await readArtifact( dir, `${ label }-source` );
				const builtShot = await readArtifact( dir, `${ label }-built` );
				if ( ! source || ! builtShot ) {
					const missing = [];
					if ( ! source ) {
						missing.push( `${ label }-source.png` );
					}
					if ( ! builtShot ) {
						missing.push( `${ label }-built.png` );
					}
					return errorResult(
						`Screenshots missing — drop ${ missing.join( ', ' ) } in ${ dir } (or set NVOOS_FORGE_CAPTURE_URL for the source).`
					);
				}
				const outcome = await visionCompare( config, source, builtShot );
				if ( ! outcome.ok ) {
					return errorResult( `Vision comparison failed: ${ outcome.error }` );
				}
				const fidelity = typeof outcome.verdict.fidelity === 'number' ? outcome.verdict.fidelity : null;
				return text( fidelity !== null ? `Visual fidelity ${ fidelity }/100.` : 'The model returned no numeric fidelity — see the verdict.', {
					fidelity,
					verdict: outcome.verdict,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
