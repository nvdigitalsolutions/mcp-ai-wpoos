/**
 * Estate service — the application layer the HTTP router talks to.
 *
 * Owns: blueprint validation on create, summary/detail shaping, catalogue
 * visibility, teardown confirmation, and env-block rendering on demand
 * (never stored with secrets in it).
 */

import { ApiError } from '../util/envelope.js';
import { decryptSecret, maskToken } from '../util/secrets.js';
import { deriveState } from './state.js';
import { validateBlueprint } from '../blueprint/validate.js';
import { evaluateEntitlement, isAdvertisable, linkPrice } from '../clients/commerce.js';
import { buildConnectSnippets } from '../snippets/connect.js';
import { buildGatewayEnvBlock, renderEnvBlockMasked } from '../gateway/envblock.js';

function entitlementFor( estate, now = new Date() ) {
	return evaluateEntitlement( {
		status: estate.commerce?.subscriptionStatus,
		trialEndsAt: estate.commerce?.trialEndsAt ?? null,
		now,
	} );
}

export function createEstateService( { config, store, provisioner, clients, seeds = [] } ) {
	async function createEstate( { blueprint, blueprintSlug = null, owner = null } ) {
		let candidate = blueprint;
		if ( ! candidate && blueprintSlug ) {
			candidate = seeds.find( ( seed ) => seed.slug === blueprintSlug ) ?? null;
			if ( ! candidate ) {
				throw new ApiError( 'not_found', `Unknown blueprint "${ blueprintSlug }"`, {}, 404 );
			}
		}
		if ( ! candidate ) {
			throw new ApiError( 'bad_request', 'Provide a blueprint object or blueprint_slug', {}, 400 );
		}

		const existingSlugs = store.list().map( ( estate ) => estate.slug );
		const validation = validateBlueprint( candidate, { existingSlugs } );
		if ( ! validation.ok ) {
			throw new ApiError(
				'validation_failed',
				'Blueprint failed validation',
				{ errors: validation.errors },
				422
			);
		}

		return store.create( {
			slug: validation.blueprint.slug,
			name: validation.blueprint.name,
			owner,
			blueprint: validation.blueprint,
			mode: config.mode,
		} );
	}

	function summary( estate ) {
		const derived = deriveState( estate.evidence );
		const entitlement = entitlementFor( estate );
		return {
			slug: estate.slug,
			name: estate.name,
			mode: estate.mode,
			state: derived.state,
			steps: derived.steps,
			nextStep: derived.nextStep?.step ?? null,
			complete: derived.complete,
			visibility: estate.blueprint?.gateway?.visibility ?? 'private',
			entitlement,
			advertisable: isAdvertisable( entitlement ),
			lastError: estate.lastError ?? null,
			createdAt: estate.createdAt,
			updatedAt: estate.updatedAt,
		};
	}

	function detail( estate, { reveal = false } = {} ) {
		const base = summary( estate );
		let operatorToken = '';
		if ( estate.secrets?.operatorTokenEnc && config.encryptionKey ) {
			try {
				operatorToken = decryptSecret( estate.secrets.operatorTokenEnc, config.encryptionKey );
			} catch {
				operatorToken = '';
			}
		}
		const siteUrl = estate.urls?.site ?? '';
		const snippets =
			siteUrl && operatorToken
				? buildConnectSnippets( {
						slug: estate.slug,
						siteUrl,
						token: operatorToken,
						redact: ! reveal,
				  } )
				: null;

		return {
			...base,
			blueprint: estate.blueprint,
			urls: estate.urls ?? {},
			secrets: {
				operatorTokenFingerprint: estate.secrets?.operatorTokenFingerprint ?? null,
				gatewayKeyPresent: Boolean( estate.secrets?.gatewayKey ),
			},
			snippets,
			gateway: estate.gateway ?? null,
		};
	}

	function envBlockFor( estate, { reveal = false } = {} ) {
		if ( ! estate.secrets?.operatorTokenEnc || ! estate.secrets?.gatewayKey ) {
			throw new ApiError(
				'provisioning_failed',
				'Env block is not available yet',
				{ remediation: 'Advance the gateway step first.' },
				409
			);
		}
		if ( ! estate.urls?.site ) {
			throw new ApiError(
				'provisioning_failed',
				'Estate site URL is not set',
				{ remediation: 'Set urls.site, then advance the gateway step.' },
				409
			);
		}
		const operatorToken = decryptSecret( estate.secrets.operatorTokenEnc, config.encryptionKey );
		const block = buildGatewayEnvBlock( {
			slug: estate.slug,
			siteUrl: estate.urls.site,
			operatorToken,
			publicKey: estate.secrets.gatewayKey,
			visibility: estate.blueprint.gateway.visibility,
			allowlist: estate.blueprint.gateway.tool_allowlist,
		} );
		if ( reveal ) {
			return { env: block.env, notes: block.notes };
		}
		return {
			env: Object.fromEntries(
				Object.entries( block.env ).map( ( [ key, value ] ) => [
					key,
					key.endsWith( '_TOKEN' ) || key === 'GATEWAY_PUBLIC_KEYS' ? maskToken( value ) : value,
				] )
			),
			rendered: renderEnvBlockMasked( block.env, maskToken ),
			notes: block.notes,
		};
	}

	async function linkEstatePrice( slug, priceId ) {
		const estate = store.get( slug );
		if ( ! estate ) {
			throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
		}
		const linked = linkPrice( estate, priceId );
		await store.update( slug, {
			commerce: {
				...( estate.commerce ?? {} ),
				stripePriceId: linked.stripePriceId,
				linkedAt: linked.linkedAt,
				subscriptionStatus: estate.commerce?.subscriptionStatus ?? 'trialing',
				trialEndsAt:
					estate.commerce?.trialEndsAt ??
					new Date( Date.now() + ( estate.blueprint.commerce.trial_days ?? 14 ) * 86_400_000 ).toISOString(),
			},
		} );
		return summary( store.get( slug ) );
	}

	async function teardown( slug, { confirm, slugEcho } = {} ) {
		const estate = store.get( slug );
		if ( ! estate ) {
			throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
		}
		if ( confirm !== true || slugEcho !== slug ) {
			throw new ApiError(
				'bad_request',
				'Teardown requires { confirm: true, slug: "<estate-slug>" }',
				{ remediation: 'This is the destructive-ops gate; no provider resources were touched.' },
				400
			);
		}
		await store.recordEvent( 'estate.teardown_requested', slug, {
			providerTeardown: 'deferred — see docs/operations/deployment/service-exchange-velocity-setup.md',
		} );
		await store.remove( slug );
		return { slug, removed: true };
	}

	function catalogue() {
		return store
			.list()
			.filter( ( estate ) => estate.evidence?.listed && isAdvertisable( entitlementFor( estate ) ) )
			.map( ( estate ) => ( {
				slug: estate.slug,
				name: estate.name,
				tagline: estate.blueprint.tagline,
				industry: estate.blueprint.industry,
				summary: estate.blueprint.listing.summary,
				useCases: estate.blueprint.listing.use_cases,
				pricingNote: estate.blueprint.listing.pricing_note,
				visibility: estate.blueprint.gateway.visibility,
			} ) );
	}

	/**
	 * Probe-driven reconciliation for one estate.
	 * v1 probes the gateway only (it already verifies the upstream site).
	 * Provider outages surface as unknown and never overwrite evidence.
	 */
	async function reconcile( slug ) {
		const estate = store.get( slug );
		if ( ! estate ) {
			throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
		}
		if ( config.mode !== 'live' ) {
			return { slug, changed: false };
		}
		const probes = {};
		try {
			if ( estate.secrets?.gatewayKey && estate.secrets?.operatorTokenEnc ) {
				const verification = await clients.gateway.verify( {
					slug,
					apiKey: estate.secrets.gatewayKey,
					tool: estate.blueprint.gateway.tool_allowlist[ 0 ] ?? null,
				} );
				probes.gatewayBound = verification.ok;
			}
		} catch {
			// Unknown — leave evidence untouched.
		}
		if ( Object.keys( probes ).length ) {
			await store.setEvidence( slug, probes, 'reconcile' );
		}
		return { slug, changed: Object.keys( probes ).length > 0, probes };
	}

	async function reconcileAll() {
		const results = [];
		for ( const estate of store.list() ) {
			try {
				results.push( await reconcile( estate.slug ) );
			} catch {
				results.push( { slug: estate.slug, changed: false, error: true } );
			}
		}
		return results;
	}

	return {
		createEstate,
		summary,
		detail,
		envBlockFor,
		linkEstatePrice,
		teardown,
		catalogue,
		reconcile,
		reconcileAll,
		advance: ( slug ) => provisioner.advance( slug ),
		get: ( slug ) => store.get( slug ),
		list: () => store.list().map( summary ),
	};
}
