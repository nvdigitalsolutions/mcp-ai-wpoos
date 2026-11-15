/**
 * Estate lifecycle.
 *
 * Ten states, nine transitions. Every state is *derived* from evidence
 * (ADR-04): the store may remember a last-known state for UX, but the
 * authoritative answer is always `deriveState(evidence)` where evidence
 * comes from live provider probes.
 *
 * GitOps "continuously reconciled": if an earlier probe regresses (app
 * deleted, site down), the derived state moves backwards automatically —
 * the reconciler never assumes monotonic progress.
 */

export const ESTATE_STATES = Object.freeze( [
	'requested',
	'cloudways_app',
	'wp_installed',
	'bundle_imported',
	'credential_minted',
	'console_deployed',
	'gateway_bound',
	'commerce_linked',
	'listed',
	'live',
] );

export const STEP_DEFINITIONS = Object.freeze( [
	{ step: 'cloudways_app', evidence: 'cloudwaysApp', label: 'Cloudways app created' },
	{ step: 'wp_installed', evidence: 'wpInstalled', label: 'Complete bundle installed and active' },
	{ step: 'bundle_imported', evidence: 'bundleImported', label: 'Assistant bundle imported' },
	{ step: 'credential_minted', evidence: 'credentialMinted', label: 'Operator credential minted' },
	{ step: 'console_deployed', evidence: 'consoleDeployed', label: 'Console deployed and reachable' },
	{ step: 'gateway_bound', evidence: 'gatewayBound', label: 'Gateway bound and tools verified' },
	{ step: 'commerce_linked', evidence: 'commerceLinked', label: 'Commerce linked and entitled' },
	{ step: 'listed', evidence: 'listed', label: 'Published in the Exchange catalogue' },
	{ step: 'live', evidence: 'live', label: 'End-to-end verification passed' },
] );

export const EVIDENCE_KEYS = Object.freeze(
	STEP_DEFINITIONS.map( ( definition ) => definition.evidence )
);

/**
 * Derive the furthest *contiguous* verified state.
 *
 * @param {Record<string, boolean>} evidence
 * @return {{state: string, steps: Array<object>, nextStep: object|null, complete: boolean}}
 */
export function deriveState( evidence = {} ) {
	const steps = [];
	let state = 'requested';
	let blocked = false;

	for ( const definition of STEP_DEFINITIONS ) {
		const ok = Boolean( evidence[ definition.evidence ] );
		if ( ! blocked && ok ) {
			state = definition.step;
		} else {
			blocked = true;
		}
		steps.push( {
			step: definition.step,
			label: definition.label,
			evidence: definition.evidence,
			ok,
		} );
	}

	const next = steps.find( ( step ) => ! step.ok ) ?? null;
	return {
		state,
		steps,
		nextStep: next,
		complete: state === 'live',
	};
}

/**
 * @param {string} state
 * @return {number} Index in ESTATE_STATES, or -1.
 */
export function stateIndex( state ) {
	return ESTATE_STATES.indexOf( state );
}

/**
 * Evidence patch for a completed step.
 *
 * @param {string} step Step id from STEP_DEFINITIONS.
 * @param {boolean} ok
 * @return {Record<string, boolean>}
 */
export function evidenceFor( step, ok = true ) {
	const definition = STEP_DEFINITIONS.find( ( entry ) => entry.step === step );
	if ( ! definition ) {
		throw new Error( `Unknown estate step: ${ step }` );
	}
	return { [ definition.evidence ]: ok };
}
