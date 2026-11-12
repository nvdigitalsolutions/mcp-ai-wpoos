/**
 * Reconciler — pure merge of provider probes into estate evidence.
 *
 * The service layer decides which probes to run; this module owns the
 * merge and derivation rules so they stay testable in isolation.
 *
 * Probe contract: each probe resolves to `true` (verified), `false`
 * (verified absent), or `undefined` (unknown — e.g. provider unreachable).
 * Unknown never overwrites a previous value; a provider outage must not
 * masquerade as a deleted estate.
 */

import { deriveState } from './state.js';

export function mergeEvidence( previous = {}, probeResults = {} ) {
	const merged = { ...previous };
	for ( const [ key, value ] of Object.entries( probeResults ) ) {
		if ( typeof value === 'boolean' ) {
			merged[ key ] = value;
		}
	}
	return merged;
}

/**
 * @param {object} estate
 * @param {Record<string, boolean|undefined>} probeResults
 * @return {{estate: object, state: string, steps: Array<object>, changed: boolean}}
 */
export function reconcile( estate, probeResults = {} ) {
	const evidence = mergeEvidence( estate.evidence, probeResults );
	const derived = deriveState( evidence );
	const changed = JSON.stringify( evidence ) !== JSON.stringify( estate.evidence );
	return {
		estate: { ...estate, evidence },
		state: derived.state,
		steps: derived.steps,
		nextStep: derived.nextStep,
		complete: derived.complete,
		changed,
	};
}
