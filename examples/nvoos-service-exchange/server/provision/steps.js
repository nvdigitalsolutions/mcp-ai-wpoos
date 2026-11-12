/**
 * Provisioning step handlers.
 *
 * One handler per transition in `estates/state.js`. Every handler is
 * idempotent: it first assumes the step may already be done (clients are
 * lookup-first) and returns the evidence patch only on success.
 *
 * Failure contract: throw ApiError with `{ tier, remediation, pasteBlock? }`
 * so the API can render the degradation path (ADR-06). Never return
 * `{ ok:false }` as a value — the envelope rule applies to handlers too.
 */

import { randomBytes } from 'node:crypto';
import { ApiError } from '../util/envelope.js';
import { encryptSecret, fingerprintSecret } from '../util/secrets.js';
import {
	buildInstallCycle,
	buildBundleImportCommand,
	buildCredentialIssueCommand,
	renderPasteBlock,
	resolveTier,
	createWpExecutor,
} from '../clients/wp.js';
import { buildGatewayEnvBlock } from '../gateway/envblock.js';

function manualDegradation( { sysUser, domain, commands, message } ) {
	return new ApiError(
		'provisioning_failed',
		message ?? 'Automated tier unavailable — apply the paste-block and retry',
		{
			tier: 'manual',
			remediation: 'Run the paste-block on the Cloudways server, then advance again.',
			pasteBlock: renderPasteBlock( { sysUser, domain, commands } ),
		},
		409
	);
}

function requireSysUser( estate ) {
	const sysUser = estate.provider?.sysUser || estate.sysUser;
	if ( ! sysUser ) {
		throw new ApiError(
			'provisioning_failed',
			'Cloudways sys_user is not known for this estate',
			{
				tier: 'manual',
				remediation: 'Resolve the Cloudways app credentials and set provider.sysUser, then advance again.',
			},
			409
		);
	}
	return sysUser;
}

async function runWpCommands( ctx, commands, sysUser, domain ) {
	const tier = resolveTier( {
		wpManager: Boolean( ctx.blueprint.site.wp_manager ) && ctx.config.wp.wpManagerEnabled,
		sshKeyPresent: ctx.config.wp.sshKeyPresent,
	} );
	if ( tier === 'manual' ) {
		throw manualDegradation( { sysUser, domain, commands } );
	}
	const runner = ctx.runners?.wp;
	if ( typeof runner !== 'function' ) {
		throw new ApiError(
			'provisioning_failed',
			`No WP runner configured for the ${ tier } tier`,
			{ tier, remediation: 'Configure WP Manager or the SSH runner and retry.' },
			502
		);
	}
	const executor = createWpExecutor( { runner, tier } );
	return executor.run( commands );
}

export const STEP_HANDLERS = {
	async cloudways_app( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return { evidence: { cloudwaysApp: true }, detail: { provider: 'mock' } };
		}
		const serverId = ctx.config.cloudways.defaultServerId;
		const label = `MCP Server - ${ ctx.estate.slug }`;
		let app = await ctx.clients.cloudways.ensureApp( { serverId, label } );
		const appId = app.id ?? null;

		// Poll until running when a status is present (verified status set:
		// running | provisioning | pending | deploying | failed | error).
		if (
			appId &&
			app.status &&
			String( app.status ).toLowerCase() !== 'running' &&
			typeof ctx.clients.cloudways.waitForApp === 'function'
		) {
			app = await ctx.clients.cloudways.waitForApp( { appId } );
		}

		const siteUrl =
			typeof ctx.clients.cloudways.siteUrlFromApp === 'function'
				? ctx.clients.cloudways.siteUrlFromApp( app )
				: null;

		await ctx.update( {
			provider: {
				...( ctx.estate.provider ?? {} ),
				serverId,
				appId,
				appLabel: label,
				sysUser: app.sys_user ?? app.system_user ?? ctx.estate.provider?.sysUser ?? null,
			},
			...( siteUrl ? { urls: { ...( ctx.estate.urls ?? {} ), site: siteUrl } } : {} ),
		} );
		return {
			evidence: { cloudwaysApp: true },
			detail: {
				serverId,
				appId,
				label,
				siteUrl,
				appStatus: app.status ?? null,
			},
		};
	},

	async wp_installed( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return { evidence: { wpInstalled: true }, detail: { provider: 'mock' } };
		}
		const sysUser = requireSysUser( ctx.estate );
		const commands = buildInstallCycle();
		await runWpCommands( ctx, commands, sysUser, ctx.blueprint.site.domain );
		return { evidence: { wpInstalled: true }, detail: { tier: 'automated' } };
	},

	async bundle_imported( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return { evidence: { bundleImported: true }, detail: { provider: 'mock' } };
		}
		const sysUser = requireSysUser( ctx.estate );
		const bundlePath = ctx.estate.provider?.bundlePath;
		if ( ! bundlePath ) {
			throw new ApiError(
				'provisioning_failed',
				'No assistant bundle path on the estate',
				{
					tier: 'manual',
					remediation: 'Upload blueprints/<slug>.json to the estate and set provider.bundlePath.',
				},
				409
			);
		}
		await runWpCommands( ctx, buildBundleImportCommand( { file: bundlePath } ), sysUser, ctx.blueprint.site.domain );
		return { evidence: { bundleImported: true }, detail: { bundlePath } };
	},

	async credential_minted( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return {
				evidence: { credentialMinted: true },
				detail: { fingerprint: 'sha256:mockmockmock' },
			};
		}
		if ( ctx.estate.secrets?.operatorTokenEnc ) {
			return {
				evidence: { credentialMinted: true },
				detail: { fingerprint: ctx.estate.secrets.operatorTokenFingerprint ?? null, existing: true },
			};
		}
		const sysUser = requireSysUser( ctx.estate );
		const assistantId = ctx.estate.provider?.assistantId;
		if ( ! assistantId ) {
			throw new ApiError(
				'provisioning_failed',
				'No imported assistant id on the estate',
				{
					tier: 'manual',
					remediation: 'Export the imported assistant (`wp mcp-ai assistant export <id>`) and set provider.assistantId.',
				},
				409
			);
		}
		const results = await runWpCommands(
			ctx,
			buildCredentialIssueCommand( { assistantId } ),
			sysUser,
			ctx.blueprint.site.domain
		);
		const stdout = results.map( ( result ) => result.stdout ).join( '\n' );
		const match = stdout.match( /op_[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/ );
		if ( ! match ) {
			throw new ApiError(
				'provisioning_failed',
				'Credential command did not return an operator token',
				{ tier: 'ssh', remediation: 'Re-run `wp mcp-ai credential issue <id>` and capture stdout.' },
				502
			);
		}
		const token = match[ 0 ];
		await ctx.update( {
			secrets: {
				...( ctx.estate.secrets ?? {} ),
				operatorTokenEnc: encryptSecret( token, ctx.secretsKey ),
				operatorTokenFingerprint: fingerprintSecret( token ),
			},
		} );
		return {
			evidence: { credentialMinted: true },
			detail: { fingerprint: fingerprintSecret( token ) },
		};
	},

	async console_deployed( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return { evidence: { consoleDeployed: true }, detail: { provider: 'mock' } };
		}
		const artifacts = ctx.context?.consoleArtifact;
		if ( ! Array.isArray( artifacts ) || artifacts.length === 0 ) {
			throw new ApiError(
				'provisioning_failed',
				'No console artifact supplied to the deploy step',
				{
					tier: 'manual',
					remediation: 'Build the console artifact (dist + server.js + package.json) and retry.',
				},
				409
			);
		}
		const pushed = await ctx.clients.github.pushEstateBranch( {
			slug: ctx.estate.slug,
			files: artifacts,
		} );
		await ctx.update( {
			urls: { ...( ctx.estate.urls ?? {} ), consoleBranch: pushed.branch },
		} );
		return {
			evidence: { consoleDeployed: true },
			detail: { branch: pushed.branch, commit: pushed.commit },
		};
	},

	async gateway_bound( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return {
				evidence: { gatewayBound: true },
				detail: { namespacedTools: [ `${ ctx.estate.slug }.get_environment_status` ] },
			};
		}
		const token = ctx.estate.secrets?.operatorTokenEnc
			? ctx.decryptSecret( ctx.estate.secrets.operatorTokenEnc )
			: null;
		if ( ! token ) {
			throw new ApiError(
				'provisioning_failed',
				'No operator token stored for the estate',
				{ remediation: 'Advance the credential step first.' },
				409
			);
		}
		const siteUrl = ctx.estate.urls?.site;
		if ( ! siteUrl ) {
			throw new ApiError(
				'provisioning_failed',
				'Estate site URL is not known',
				{ remediation: 'Set urls.site from the Cloudways domain, then advance again.' },
				409
			);
		}
		const publicKey =
			ctx.estate.secrets?.gatewayKey ?? randomBytes( 32 ).toString( 'base64url' );
		const envBlock = buildGatewayEnvBlock( {
			slug: ctx.estate.slug,
			siteUrl,
			operatorToken: token,
			publicKey,
			visibility: ctx.blueprint.gateway.visibility,
			allowlist: ctx.blueprint.gateway.tool_allowlist,
		} );
		const verification = await ctx.clients.gateway.verify( {
			slug: ctx.estate.slug,
			apiKey: publicKey,
			tool: ctx.blueprint.gateway.tool_allowlist[ 0 ] ?? null,
		} );
		await ctx.update( {
			secrets: { ...( ctx.estate.secrets ?? {} ), gatewayKey: publicKey },
			gateway: { notes: envBlock.notes, verifiedAt: new Date().toISOString() },
		} );
		if ( ! verification.ok ) {
			throw new ApiError(
				'provisioning_failed',
				'Gateway verification did not pass',
				{
					tier: 'manual',
					remediation: 'Apply the env block in the Velocity dashboard and re-run the verification ladder.',
					verification: verification.steps,
				},
				502
			);
		}
		return {
			evidence: { gatewayBound: true },
			detail: { namespacedTools: verification.namespacedTools },
		};
	},

	async commerce_linked( ctx ) {
		const trialDays = ctx.blueprint.commerce.trial_days ?? 14;
		const subscriptionStatus = ctx.estate.commerce?.subscriptionStatus ?? 'trialing';
		const trialEndsAt =
			ctx.estate.commerce?.trialEndsAt ??
			new Date( ctx.now().getTime() + trialDays * 86_400_000 ).toISOString();

		if ( ctx.mode === 'mock' ) {
			await ctx.update( {
				commerce: { ...( ctx.estate.commerce ?? {} ), subscriptionStatus, trialEndsAt },
			} );
			return {
				evidence: { commerceLinked: true },
				detail: { provider: 'mock', subscriptionStatus, trialEndsAt },
			};
		}
		const priceId = ctx.estate.commerce?.stripePriceId ?? ctx.blueprint.commerce.stripe_price_id;
		if ( ! priceId ) {
			throw new ApiError(
				'provisioning_failed',
				'No Stripe price linked',
				{
					remediation: 'Link a price (price_…) via POST /estates/{slug}/commerce or set it in the blueprint.',
				},
				409
			);
		}
		await ctx.update( {
			commerce: {
				...( ctx.estate.commerce ?? {} ),
				stripePriceId: priceId,
				subscriptionStatus,
				trialEndsAt,
			},
		} );
		return { evidence: { commerceLinked: true }, detail: { stripePriceId: priceId, subscriptionStatus } };
	},

	async listed( ctx ) {
		return {
			evidence: { listed: true },
			detail: {
				catalogue: ctx.blueprint.gateway.visibility === 'public' ? 'public' : 'private',
			},
		};
	},

	async live( ctx ) {
		if ( ctx.mode === 'mock' ) {
			return { evidence: { live: true }, detail: { provider: 'mock' } };
		}
		const token = ctx.estate.secrets?.operatorTokenEnc
			? ctx.decryptSecret( ctx.estate.secrets.operatorTokenEnc )
			: null;
		const key = ctx.estate.secrets?.gatewayKey;
		if ( ! token || ! key ) {
			throw new ApiError(
				'provisioning_failed',
				'Live verification requires the stored operator token and gateway key',
				{ remediation: 'Advance the gateway step again to re-mint the verification state.' },
				409
			);
		}
		const verification = await ctx.clients.gateway.verify( {
			slug: ctx.estate.slug,
			apiKey: key,
			tool: ctx.blueprint.gateway.tool_allowlist[ 0 ] ?? null,
		} );
		if ( ! verification.ok ) {
			throw new ApiError(
				'provisioning_failed',
				'Live verification failed',
				{ verification: verification.steps },
				502
			);
		}
		return { evidence: { live: true }, detail: { verifiedAt: new Date().toISOString() } };
	},
};
