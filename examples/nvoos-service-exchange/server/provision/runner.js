/**
 * Provisioning runner.
 *
 * Advances one estate by exactly one verified step, under a per-estate lock.
 * Idempotent by construction: the reconciler decides the next step from
 * evidence, handlers look up before creating, and a completed estate is a
 * no-op.
 */

import { ApiError } from '../util/envelope.js';
import { createLockMap } from '../util/locks.js';
import { deriveState } from '../estates/state.js';
import { decryptSecret } from '../util/secrets.js';
import { STEP_HANDLERS } from './steps.js';

export function createProvisionRunner( {
	config,
	store,
	clients,
	runners = {},
	context = {},
	locks = createLockMap(),
	now = () => new Date(),
} ) {
	async function advance( slug ) {
		return locks.run( slug, async () => {
			const estate = store.get( slug );
			if ( ! estate ) {
				throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
			}

			const derived = deriveState( estate.evidence );
			if ( derived.complete ) {
				return {
					estate,
					state: derived.state,
					steps: derived.steps,
					complete: true,
					advanced: false,
				};
			}

			const step = derived.nextStep;
			const handler = STEP_HANDLERS[ step.step ];
			if ( ! handler ) {
				throw new ApiError( 'internal_error', `No handler for step "${ step.step }"`, {}, 500 );
			}

			const ctx = {
				estate,
				blueprint: estate.blueprint,
				mode: config.mode,
				config,
				clients,
				runners,
				context,
				secretsKey: config.encryptionKey,
				now,
				decryptSecret: ( payload ) => decryptSecret( payload, config.encryptionKey ),
				update: async ( patch ) => store.update( slug, patch ),
				log: async ( type, payload ) => store.recordEvent( type, slug, payload ),
			};

			try {
				const result = await handler( ctx );
				await store.setEvidence( slug, result.evidence, 'provision' );
				await store.recordEvent( 'estate.step', { step: step.step, detail: result.detail ?? null } );

				const updated = store.get( slug );
				const after = deriveState( updated.evidence );
				return {
					estate: updated,
					state: after.state,
					steps: after.steps,
					nextStep: after.nextStep,
					complete: after.complete,
					advanced: true,
					step: step.step,
					detail: result.detail ?? null,
				};
			} catch ( error ) {
				const safeExtra = {
					code: error.code ?? 'provisioning_failed',
					message: error.message,
					tier: error.extra?.tier ?? null,
					remediation: error.extra?.remediation ?? null,
				};
				await store.update( slug, { lastError: safeExtra } );
				await store.recordEvent( 'estate.step_failed', { step: step.step, ...safeExtra } );
				throw error;
			}
		} );
	}

	async function advanceToCompletion( slug, { maxSteps = 9 } = {} ) {
		const results = [];
		for ( let index = 0; index < maxSteps; index += 1 ) {
			const estate = store.get( slug );
			if ( ! estate ) {
				throw new ApiError( 'not_found', `Estate "${ slug }" not found`, { slug }, 404 );
			}
			if ( deriveState( estate.evidence ).complete ) {
				break;
			}
			results.push( await advance( slug ) );
		}
		const estate = store.get( slug );
		const derived = deriveState( estate.evidence );
		return { estate, state: derived.state, steps: derived.steps, results };
	}

	return { advance, advanceToCompletion, isLocked: locks.isLocked };
}
