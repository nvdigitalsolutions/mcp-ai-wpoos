// ---------------------------------------------------------------------------
// rebuild.ts — sync & rebuild tool slice (096 §3.3): the generated pipeline
// config and the signed inbound rebuild trigger against the bridge plugin.
// ---------------------------------------------------------------------------

import { createHmac } from 'node:crypto';
import { z } from 'zod/v4';

import { emitDeployConfig, type DeployMode } from '../../lib/deploy-config.ts';
import { assertSafeHttpUrl } from '../../lib/ssrf.ts';
import { Vault } from '../../lib/vault.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, IDEMPOTENT_WRITE_TOOL, OPEN_WORLD_WRITE_TOOL, text } from '../util.ts';

const MODES: DeployMode[] = [ 'github-pages', 'cloudflare-pages', 'velocity' ];

export const REBUILD_TOOLS: ToolDef[] = [
	{
		name: 'rebuild_config',
		title: 'Emit the deploy + rebuild pipeline into a project',
		description:
			'Write the generated deploy pipeline into a scaffolded project: deploy.yml (build + target-specific deploy steps), rebuild.yml (the repository_dispatch wrapper), and scripts/verify-dispatch.mjs — the fail-closed signature verifier that refuses stale or mismatched bridge-webhook dispatches.',
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
				const emitted = await emitDeployConfig( target, {
					mode: mode ?? 'github-pages',
					siteUrl,
					cloudflareProject,
				} );
				return text( `Emitted the deploy pipeline into ${ target }.`, { target, files: emitted.files } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'rebuild_trigger',
		title: 'Trigger a rebuild on a site running the forge bridge',
		description:
			'POST a signed rebuild request to a WordPress site running nvoos-forge-bridge (its inbound /wp-json/nvoos-forge/v1/rebuild route verifies the X-Forge-Signature HMAC fail-closed). The target URL and shared secret come from the vault entries `forge_rebuild_url` and `forge_webhook_secret` — never from arguments. Private/literal-IP ranges are refused.',
		schema: z.object( {} ),
		annotations: OPEN_WORLD_WRITE_TOOL,
		write: true,
		handler: async ( _args, env ) => {
			try {
				const vault = new Vault( { dir: env.homeDir } );
				const url = await vault.get( 'forge_rebuild_url' );
				const secret = await vault.get( 'forge_webhook_secret' );
				if ( ! url || ! secret ) {
					return errorResult(
						'Missing vault entries — store `forge_rebuild_url` and `forge_webhook_secret` (shared with the bridge plugin) before triggering rebuilds.'
					);
				}
				assertSafeHttpUrl( url );
				const body = JSON.stringify( {
					action: 'rebuild',
					time: Math.floor( Date.now() / 1000 ),
					nonce: createHmac( 'sha256', secret ).update( String( Math.random() ) ).digest( 'hex' ).slice( 0, 24 ),
				} );
				const signature = 'sha256=' + createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
				const response = await fetch( url, {
					method: 'POST',
					headers: {
						'content-type': 'application/json',
						'x-forge-signature': signature,
						'user-agent': 'nvoos-astro-forge-mcp',
					},
					body,
				} );
				if ( ! response.ok ) {
					return errorResult( `Rebuild trigger refused: HTTP ${ response.status }.` );
				}
				return text( `Rebuild accepted (HTTP ${ response.status }).`, { status: response.status } );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
