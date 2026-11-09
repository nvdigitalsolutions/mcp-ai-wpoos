// ---------------------------------------------------------------------------
// design.ts — the design_apply/design_verify/design_verify_visual/design_verify_text/
// design_verify_pixel tool slice (Proposals 114 P6 + 118 §5 + the parity gates).
//
// design_apply extracts the design language from a site and writes the v2
// design block (incl. the 117 section-background pass) into the site's
// profile — the scaffold then derives the rebuild theme from it.
// Open-world URLs ride the same policy as site_analyze: registered hosts or
// NVOOS_FORGE_OPEN_ANALYZE=1, both behind the SSRF guard. design_verify
// re-extracts the built CSS and scores language parity — read-only.
// design_verify_visual runs the 118 vision comparison (data-URL images,
// NVOOS_FORGE_VISION_* env, or the capture contract) — read-only.
// design_verify_text diffs the build's words against the recorded text
// baseline (w-shingles, coverage-gated); design_verify_pixel compares the
// SOURCE and BUILT renders with pixelmatch's delta + SSIM. Both read-only.
// ---------------------------------------------------------------------------

import { readFile, readdir, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { z } from 'zod/v4';

import { captureEndpoint, captureRemote, readArtifact } from '../../lib/capture.ts';
import { analyzeBuiltCss, analyzeDesignInput, fetchDesignInput, scoreParity } from '../../lib/design.ts';
import { DEFAULT_GEOMETRY_VIEWPORTS, serveDir } from '../../lib/geometry.ts';
import { comparePngBuffers } from '../../lib/pixel.ts';
import { compareRegionColorModes, measureRegionColors } from '../../lib/region-colors.ts';
import { captureDist } from '../../lib/screenshot.ts';
import { SitesStore } from '../../lib/sites.ts';
import { assertSafeHttpUrl, isFetchAllowed } from '../../lib/ssrf.ts';
import { compareText, extractSectionTexts, textFromHtml } from '../../lib/text-parity.ts';
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

/** The built homepage (client/ for SSR trees — the scaffold emits both shapes). */
async function readIndexHtml( targetDir: string ): Promise< string | undefined > {
	for ( const candidate of [
		join( targetDir, 'dist', 'index.html' ),
		join( targetDir, 'dist', 'client', 'index.html' ),
	] ) {
		try {
			return await readFile( candidate, 'utf8' );
		} catch {
			// Try the next shape.
		}
	}
	return undefined;
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
	{
		name: 'design_verify_text',
		title: 'Diff the built site\u0027s words against the recorded text baseline',
		description:
			'Content parity: compare the BUILT index.html\u0027s visible text against the profile\u0027s text baseline (`analyze --text`) using normalised 3-word shingles (NFKC, punctuation folded, whitespace collapsed, lowercased). `coverage` — how much of the SOURCE survives — is the gate (pass \u2265 0.9, warn \u2265 0.75, else fail); added copy is reported as `precision` but never fails a rebuild. Per-band comparison needs `[data-section-id]` nodes in the build. Pure and offline — no browser, no network.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string(),
			passAt: z.number().min( 0 ).max( 1 ).optional(),
			warnAt: z.number().min( 0 ).max( 1 ).optional(),
			shingleSize: z.number().int().min( 1 ).max( 10 ).optional(),
			includeAlt: z.boolean().optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, target, passAt, warnAt, shingleSize, includeAlt } = args as {
				site?: string;
				profilePath?: string;
				target: string;
				passAt?: number;
				warnAt?: number;
				shingleSize?: number;
				includeAlt?: boolean;
			};
			try {
				const { profile } = await resolveProfile( env, site, profilePath );
				if ( ! profile.text ) {
					return errorResult(
						'The profile has no text baseline — run `nvoos-forge analyze <url> --design --text --write <profile>` first.'
					);
				}
				const targetDir = resolveTarget( env, target );
				const html = await readIndexHtml( targetDir );
				if ( ! html ) {
					return errorResult( `No built HTML under ${ targetDir }/dist — build the project first.` );
				}
				const options = { passAt, warnAt, shingleSize };
				const page = compareText( profile.text.page, textFromHtml( html, { includeAlt } ), options );
				const ids = profile.text.sections.map( ( section ) => section.id );
				const built = extractSectionTexts( html, ids, { includeAlt } );
				const bands = profile.text.sections.map( ( section ) => {
					const builtText = built.texts[ section.id ];
					if ( builtText === undefined ) {
						return { id: section.id, verdict: 'skip' as const, coverage: null };
					}
					if ( builtText.length === 0 ) {
						return { id: section.id, verdict: 'fail' as const, coverage: 0 };
					}
					const report = compareText( section.text, builtText, options );
					return {
						id: section.id,
						verdict: report.verdict,
						coverage: report.coverage,
						missing: report.missing,
					};
				} );
				const failing = bands.filter( ( band ) => band.verdict === 'fail' ).length + ( page.verdict === 'fail' ? 1 : 0 );
				return text(
					`Text parity: coverage ${ ( page.coverage * 100 ).toFixed( 1 ) }% (page) — ${ page.verdict.toUpperCase() }${ failing > 0 ? `; ${ failing } failing surface(s)` : '' }.`,
					{ page, bands, missingBands: built.missing }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'design_verify_pixel',
		title: 'Compare source and built renders pixel-for-pixel (delta + SSIM)',
		description:
			'Pixel parity: capture the BUILT dist/ at the three standard viewports (Playwright, deterministic capture settings) and compare each against `verify-artifacts/source-<viewport>.png` with pixelmatch\u0027s YIQ delta (anti-alias filtered) and SSIM. Fails above `maxDiffRatio` (default 0.1%) or below `minSsim` (default 0.98); warns at 0.01% / 0.99. Use `masks` (CSS selectors) for genuinely dynamic regions instead of loosening the threshold. Both images must be captured at the SAME viewport size. Read-only; requires Playwright on the server.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string(),
			masks: z.array( z.string() ).optional(),
			threshold: z.number().min( 0 ).max( 1 ).optional(),
			maxDiffRatio: z.number().min( 0 ).max( 1 ).optional(),
			minSsim: z.number().min( 0 ).max( 1 ).optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, target, masks, threshold, maxDiffRatio, minSsim } = args as {
				site?: string;
				profilePath?: string;
				target: string;
				masks?: string[];
				threshold?: number;
				maxDiffRatio?: number;
				minSsim?: number;
			};
			try {
				const targetDir = resolveTarget( env, target );
				const artifacts = join( targetDir, 'verify-artifacts' );
				const captured = await captureDist( join( targetDir, 'dist' ), DEFAULT_GEOMETRY_VIEWPORTS, {
					masks: masks ?? [],
				} );
				const results = [];
				for ( const viewport of DEFAULT_GEOMETRY_VIEWPORTS ) {
					const artifact = await readArtifact( artifacts, `source-${ viewport.name }` );
					if ( ! artifact ) {
						continue;
					}
					const result = comparePngBuffers(
						Buffer.from( artifact.data, 'base64' ),
						captured.get( viewport.name )!.png,
						{ threshold, maxDiffRatio, minSsim }
					);
					results.push( {
						viewport: viewport.name,
						diffRatio: result.diffRatio,
						ssim: result.ssim,
						verdict: result.verdict,
						reasons: result.reasons,
					} );
				}
				if ( results.length === 0 ) {
					return errorResult(
						`No source screenshots — drop ${ join( artifacts, 'source-<viewport>.png' ) } at 1440x900, 834x1112 and 390x844.`
					);
				}
				const failing = results.filter( ( result ) => result.verdict === 'fail' ).length;
				return text(
					`Pixel parity: ${ results.length } viewport(s) compared, ${ failing } failing.`,
					{ results }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'design_verify_colors',
		title: 'Diff the build\u0027s rendered body/header/footer colours (CIEDE2000)',
		description:
			'Colour system: measure the COMPUTED background/text colour of body, header and footer in the built dist/ (Playwright) and diff it against the profile\u0027s colour baseline (`analyze --colors`) with CIEDE2000. \u0394E00 \u2265 warnAt (default 3) warns, \u2265 failAt (default 6) fails. Transparent surfaces are skipped (not comparable); a region present in the baseline but missing from the build is reported. This is the gate the token block (CSS declarations) and band flow (band backgrounds) cannot provide \u2014 a themed header that ships with template defaults passes both. Read-only; requires Playwright.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string(),
			warnAt: z.number().min( 0 ).max( 100 ).optional(),
			failAt: z.number().min( 0 ).max( 100 ).optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, profilePath, target, warnAt, failAt } = args as {
				site?: string;
				profilePath?: string;
				target: string;
				warnAt?: number;
				failAt?: number;
			};
			try {
				const { profile } = await resolveProfile( env, site, profilePath );
				if ( ! profile.colors ) {
					return errorResult(
						'The profile has no colour baseline — run `nvoos-forge analyze <url> --colors --write <profile>` first.'
					);
				}
				const targetDir = resolveTarget( env, target );
				const served = await serveDir( join( targetDir, 'dist' ) );
				let built;
				try {
					built = await measureRegionColors( { url: served.url } );
				} finally {
					served.server.close();
				}
				const report = compareRegionColorModes( profile.colors, built, { warnAt, failAt } );
				const failing = report.deviations.filter( ( deviation ) => deviation.severity === 'fail' ).length;
				return text(
					`Colour system: ${ report.compared } surface metric(s) compared, ${ failing } failing, ${ report.missing.length } missing region(s).`,
					{ report }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
