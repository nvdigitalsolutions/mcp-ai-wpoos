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
import { errorResult, IDEMPOTENT_WRITE_TOOL, OPEN_WORLD_WRITE_TOOL, READ_ONLY_TOOL, text, WRITE_TOOL } from '../util.ts';

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
	{
		name: 'preview_token',
		title: 'Mint a preview token from a bridge-connected site',
		description:
			'Request a single-use preview token from a registered site running nvoos-forge-bridge (POST /wp-json/nvoos-forge/v1/preview-tokens — editors+). The site\'s WordPress application-password credentials come from the vault entries `<site>.wp-app-user` and `<site>.wp-app-password` — never from arguments, never echoed. Fails closed without credentials.',
		schema: z.object( { site: z.string().regex( /^[a-z0-9][a-z0-9-]{0,31}$/ ) } ),
		annotations: WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { site } = args as { site: string };
			try {
				const { SitesStore } = await import( '../../lib/sites.ts' );
				const { ref } = await new SitesStore( env.homeDir ).resolve( site );
				const vault = new Vault( { dir: env.homeDir } );
				const user = await vault.get( `${ site }.wp-app-user` );
				const password = await vault.get( `${ site }.wp-app-password` );
				if ( ! user || ! password ) {
					return errorResult(
						`Missing vault entries — store \`${ site }.wp-app-user\` and \`${ site }.wp-app-password\` before minting preview tokens.`
					);
				}
				assertSafeHttpUrl( ref.url );
				const tokenUrl = `${ ref.url.replace( /\/+$/, '' ) }/wp-json/nvoos-forge/v1/preview-tokens`;
				const response = await fetch( tokenUrl, {
					method: 'POST',
					headers: {
						accept: 'application/json',
						authorization: 'Basic ' + Buffer.from( `${ user }:${ password }` ).toString( 'base64' ),
						'user-agent': 'nvoos-astro-forge-mcp',
					},
				} );
				if ( ! response.ok ) {
					return errorResult( `Preview token refused: HTTP ${ response.status }.` );
				}
				const payload = ( await response.json() ) as { token?: string; expiresIn?: number };
				if ( ! payload.token ) {
					return errorResult( 'The site did not return a token.' );
				}
				return text( `Preview token minted (expires in ${ payload.expiresIn ?? '?' }s). The token is single-use and returned once.`, {
					site,
					token: payload.token,
					expiresIn: payload.expiresIn,
				} );
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'webhook_verify',
		title: 'Verify a bridge signature locally (diagnostic)',
		description:
			'Recompute and compare the two proofs the bridge plugin produces: the X-Forge-Signature header (HMAC over the exact payload) and the payload-level proof (HMAC over "<time>.<nonce>" from client_payload). Uses the vault\'s `forge_webhook_secret`; the payload and signature are proofs, not secrets, so they may come from arguments. Constant-time comparison, fail-closed.',
		schema: z.object( {
			payload: z.string(),
			signature: z.string().optional(),
			time: z.number().int().optional(),
			nonce: z.string().optional(),
			payloadSignature: z.string().optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { payload, signature, time, nonce, payloadSignature } = args as {
				payload: string;
				signature?: string;
				time?: number;
				nonce?: string;
				payloadSignature?: string;
			};
			try {
				const vault = new Vault( { dir: env.homeDir } );
				const secret = await vault.get( 'forge_webhook_secret' );
				if ( ! secret ) {
					return errorResult( 'Missing vault entry `forge_webhook_secret` — verification needs the shared secret.' );
				}
				const expected = 'sha256=' + createHmac( 'sha256', secret ).update( payload ).digest( 'hex' );
				const { timingSafeEqual } = await import( 'node:crypto' );
				const matches = ( a: string, b: string ) => {
					const bufA = Buffer.from( a );
					const bufB = Buffer.from( b );
					return bufA.length > 0 && bufA.length === bufB.length && timingSafeEqual( bufA, bufB );
				};
				const headerValid = signature ? matches( expected, signature ) : undefined;
				let payloadValid: boolean | undefined;
				if ( time !== undefined && nonce !== undefined && payloadSignature !== undefined ) {
					const expectedPayload = createHmac( 'sha256', secret ).update( `${ time }.${ nonce }` ).digest( 'hex' );
					payloadValid = matches( expectedPayload, payloadSignature );
				}
				return text(
					`Header proof: ${ headerValid === undefined ? 'not provided' : headerValid ? 'VALID' : 'INVALID' }. Payload proof: ${ payloadValid === undefined ? 'not provided' : payloadValid ? 'VALID' : 'INVALID' }.`,
					{ headerValid, payloadValid }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
