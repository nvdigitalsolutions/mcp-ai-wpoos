// ---------------------------------------------------------------------------
// scaffold.ts — scaffold/status/tokens/package tool slice (096 §3.3).
// MCP-mode targets are confined to the configured work directory
// (NVOOS_FORGE_WORKDIR, default <forge-home>/projects) so a networked
// assistant can never write outside the operator's workspace.
// ---------------------------------------------------------------------------

import { mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import { basename, join, relative, resolve } from 'node:path';
import { z } from 'zod/v4';
import { zipSync } from 'fflate';

import { BINARY_EXTENSIONS, SKIP_DIRS } from '../../lib/project-files.ts';
import { rewriteGlobalCss, scaffold, tokenWarnings } from '../../lib/scaffold.ts';
import { SitesStore } from '../../lib/sites.ts';
import type { SiteProfile } from '../../lib/types.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, IDEMPOTENT_WRITE_TOOL, READ_ONLY_TOOL, text, WRITE_TOOL } from '../util.ts';

/** Resolve a target dir against the workdir, refusing escapes. */
function resolveTarget( env: { workDir: string }, target: string ): string {
	const absolute = resolve( target );
	const work = resolve( env.workDir );
	if ( absolute !== work && ! absolute.startsWith( work + '/' ) && ! absolute.startsWith( work + '\\' ) ) {
		throw new Error( `Target ${ target } is outside the forge work directory (${ env.workDir }).` );
	}
	return absolute;
}

async function ensureWorkDir( env: { workDir: string } ): Promise<string> {
	await mkdir( env.workDir, { recursive: true } );
	return resolve( env.workDir );
}

export const SCAFFOLD_TOOLS: ToolDef[] = [
	{
		name: 'scaffold_project',
		title: 'Scaffold an Astro project from a registered site',
		description:
			'Scaffold the Astro frontend for a registered site (or an explicit profile path) into the work directory: template copy, FORGE-marker rewrite, manifest, and optional design-token overrides. Idempotent per target — an existing forge project is reused unless overwrite is set.',
		schema: z.object( {
			site: z.string().optional(),
			profilePath: z.string().optional(),
			target: z.string().optional(),
			template: z.string().optional(),
			tokens: z.record( z.string(), z.string() ).optional(),
			overwrite: z.boolean().optional(),
		} ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { site, profilePath, target, template, tokens, overwrite } = args as {
				site?: string;
				profilePath?: string;
				target?: string;
				template?: string;
				tokens?: Record<string, string>;
				overwrite?: boolean;
			};
			try {
				// Fail fast on target confinement — before touching any profile.
				await ensureWorkDir( env );
				const targetDir = resolveTarget( env, target ?? join( env.workDir, site ?? basename( profilePath ?? 'site' ) ) );
				let profile: SiteProfile;
				if ( profilePath ) {
					profile = JSON.parse( await readFile( profilePath, 'utf8' ) ) as SiteProfile;
				} else if ( site ) {
					profile = ( await new SitesStore( env.homeDir ).resolve( site ) ).profile;
				} else {
					return errorResult( 'Provide `site` (registered name) or `profilePath`.', { ok: false } );
				}
				if ( tokens ) {
					profile.tokens = { ...( profile.tokens ?? {} ), ...tokens };
				}
				const result = await scaffold( { profile, targetDir, template, overwrite } );
				const warnings = [ ...result.warnings, ...tokenWarnings( tokens ) ];
				return text( `Scaffolded ${ result.collections.length } collection(s) into ${ result.targetDir }.`, {
					targetDir: result.targetDir,
					collections: result.manifest.collections.map( ( collection ) => collection.slug ),
					warnings,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'scaffold_status',
		title: 'Read a scaffolded project\'s manifest',
		description: 'Report whether a target is a forge project and return its manifest (generator, source URL, generatedAt, collections).',
		schema: z.object( { target: z.string() } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { target } = args as { target: string };
			try {
				const targetDir = resolveTarget( env, target );
				const manifest = JSON.parse( await readFile( join( targetDir, 'nvoos.json' ), 'utf8' ) ) as Record<string, unknown>;
				return text( `Forge project at ${ targetDir } — generated ${ String( manifest.generatedAt ?? 'unknown' ) }.`, { targetDir, manifest } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'tokens_apply',
		title: 'Apply design tokens to a scaffolded project',
		description:
			'Rewrite the --forge-* design tokens in an existing project\'s global.css (the line-anchored FORGE:tokens span) and report WCAG contrast warnings for the rendered pairs. The 098 design-language output feeds this directly.',
		schema: z.object( { target: z.string(), tokens: z.record( z.string(), z.string() ) } ),
		annotations: IDEMPOTENT_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { target, tokens } = args as { target: string; tokens: Record<string, string> };
			try {
				const targetDir = resolveTarget( env, target );
				const cssPath = join( targetDir, 'src', 'styles', 'global.css' );
				const source = await readFile( cssPath, 'utf8' );
				await writeFile( cssPath, rewriteGlobalCss( source, tokens ), 'utf8' );
				const warnings = tokenWarnings( tokens );
				return text( `Applied ${ Object.keys( tokens ).length } token override(s) to ${ cssPath }.`, {
					targetDir,
					applied: tokens,
					warnings,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'scaffold_package',
		title: 'Package a scaffolded project as a ZIP',
		description:
			'Export a scaffolded project as a zip (build/runtime artifacts excluded: node_modules, dist, .astro, .git). The zip lands next to the project as <name>.forge.zip.',
		schema: z.object( { target: z.string() } ),
		annotations: IDEMPOTENT_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { target } = args as { target: string };
			try {
				const targetDir = resolveTarget( env, target );
				const entries: Record<string, Uint8Array> = {};
				async function walk( dir: string, prefix: string ): Promise<void> {
					for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
						const full = join( dir, entry.name );
						if ( entry.isDirectory() ) {
							if ( SKIP_DIRS.has( entry.name ) ) {
								continue;
							}
							await walk( full, `${ prefix }${ entry.name }/` );
							continue;
						}
						const extension = entry.name.split( '.' ).pop()?.toLowerCase() ?? '';
						if ( BINARY_EXTENSIONS.has( extension ) ) {
							continue;
						}
						entries[ prefix + entry.name ] = new TextEncoder().encode( await readFile( full, 'utf8' ) );
					}
				}
				await stat( join( targetDir, 'nvoos.json' ) );
				await walk( targetDir, '' );
				const zipped = zipSync( entries, { level: 6 } );
				const out = join( targetDir, `${ basename( resolve( targetDir ) ) }.forge.zip` );
				await writeFile( out, zipped );
				return text( `Packaged ${ Object.keys( entries ).length } files -> ${ out } (${ zipped.length } bytes).`, {
					targetDir,
					out,
					files: Object.keys( entries ).length,
					bytes: zipped.length,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
