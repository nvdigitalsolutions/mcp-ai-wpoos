// ---------------------------------------------------------------------------
// deploy.ts — deploy tool slice (096 §3.3): the deploy-config alias and the
// one-command setup_wizard (analyze → register → scaffold → build → git init
// → deploy pipeline). Target confinement: the wizard's target must live in
// the work directory (same rule as scaffold_project).
// ---------------------------------------------------------------------------

import { z } from 'zod/v4';

import { emitDeployConfig, type DeployMode } from '../../lib/deploy-config.ts';
import { slugify } from '../../lib/project-files.ts';
import { setupWizard } from '../../lib/wizard.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, IDEMPOTENT_WRITE_TOOL, text, WRITE_TOOL } from '../util.ts';

const MODES: DeployMode[] = [ 'github-pages', 'cloudflare-pages', 'velocity' ];

export const DEPLOY_TOOLS: ToolDef[] = [
	{
		name: 'deploy_config',
		title: 'Emit the deploy configuration for a target',
		description:
			'Same pipeline as rebuild_config: emit deploy.yml + rebuild.yml + the fail-closed verify-dispatch.mjs for a scaffolded project, parameterized per mode (github-pages, cloudflare-pages with a project name, or the velocity connected-repo mirror).',
		schema: z.object( {
			target: z.string(),
			mode: z.enum( MODES ).optional(),
			siteUrl: z.string().url().optional(),
			cloudflareProject: z.string().optional(),
		} ),
		annotations: IDEMPOTENT_WRITE_TOOL,
		write: true,
		handler: async ( args ) => {
			const { target, mode, siteUrl, cloudflareProject } = args as {
				target: string;
				mode?: DeployMode;
				siteUrl?: string;
				cloudflareProject?: string;
			};
			try {
				const emitted = await emitDeployConfig( target, { mode: mode ?? 'github-pages', siteUrl, cloudflareProject } );
				return text( `Emitted the deploy configuration into ${ target }.`, { target, files: emitted.files } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'setup_wizard',
		title: 'One command: URL → analyzed, scaffolded, built, deploy-ready',
		description:
			'The full wizard: analyze the WordPress URL (SSRF-guarded), register the site with a drafted profile, scaffold the Astro project into the work directory, optionally build it, git-init it, and emit the deploy pipeline. The URL must be a plain http(s) hostname — private/loopback/literal-IP ranges are refused.',
		schema: z.object( {
			url: z.string().url(),
			name: z.string().regex( /^[a-z0-9][a-z0-9-]{0,31}$/ ).optional(),
			target: z.string().optional(),
			mode: z.enum( MODES ).optional(),
			build: z.boolean().optional(),
		} ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { url, name, target, mode, build } = args as {
				url: string;
				name?: string;
				target?: string;
				mode?: DeployMode;
				build?: boolean;
			};
			try {
				const { mkdir } = await import( 'node:fs/promises' );
				const { join, resolve } = await import( 'node:path' );
				await mkdir( env.workDir, { recursive: true } );
				const targetDir = target ? resolve( target ) : join( env.workDir, slugify( name ?? url ) );
				const work = resolve( env.workDir );
				if ( targetDir !== work && ! targetDir.startsWith( work + '/' ) && ! targetDir.startsWith( work + '\\' ) ) {
					return errorResult( `Target ${ target } is outside the forge work directory (${ env.workDir }).` );
				}
				const result = await setupWizard( {
					url,
					name,
					targetDir,
					mode: mode ?? 'github-pages',
					build: build === true,
				} );
				return text( `Wizard complete — site "${ result.siteName }" -> ${ result.targetDir } (built: ${ result.built }, git: ${ result.gitInitialized ? 'initialized' : 'skipped' }).`, result );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
