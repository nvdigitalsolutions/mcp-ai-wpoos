import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mergeEvidence, reconcile } from './reconciler.js';
import { STEP_DEFINITIONS } from './state.js';

test( 'mergeEvidence ignores unknown probe results', () => {
	const merged = mergeEvidence(
		{ cloudwaysApp: true },
		{ cloudwaysApp: true, wpInstalled: undefined, bundleImported: false }
	);
	assert.deepEqual( merged, { cloudwaysApp: true, bundleImported: false } );
} );

test( 'reconcile derives state and reports change', () => {
	const estate = {
		slug: 'clinic-ops',
		evidence: { cloudwaysApp: true },
	};
	const result = reconcile( estate, { wpInstalled: true } );
	assert.equal( result.state, 'wp_installed' );
	assert.equal( result.changed, true );
	assert.equal( result.nextStep.step, 'bundle_imported' );

	const noop = reconcile( result.estate, { cloudwaysApp: true, wpInstalled: true } );
	assert.equal( noop.changed, false );
	assert.equal( noop.state, 'wp_installed' );
} );

test( 'provider outage keeps previous evidence', () => {
	const estate = { slug: 'x', evidence: { cloudwaysApp: true } };
	const result = reconcile( estate, { cloudwaysApp: undefined } );
	assert.equal( result.estate.evidence.cloudwaysApp, true );
	assert.equal( result.state, 'cloudways_app' );
} );

test( 'full probe set reaches live', () => {
	const probes = Object.fromEntries(
		STEP_DEFINITIONS.map( ( definition ) => [ definition.evidence, true ] )
	);
	const result = reconcile( { slug: 'x', evidence: {} }, probes );
	assert.equal( result.state, 'live' );
	assert.equal( result.complete, true );
} );
