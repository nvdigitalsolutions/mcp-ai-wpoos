import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deriveState, evidenceFor, ESTATE_STATES, STEP_DEFINITIONS } from './state.js';

test( 'requested with no evidence', () => {
	const result = deriveState( {} );
	assert.equal( result.state, 'requested' );
	assert.equal( result.nextStep.step, 'cloudways_app' );
	assert.equal( result.complete, false );
} );

test( 'advances only through contiguous evidence', () => {
	const result = deriveState( {
		cloudwaysApp: true,
		wpInstalled: true,
		consoleDeployed: true, // gap at bundle/credential — must not count
	} );
	assert.equal( result.state, 'wp_installed' );
	assert.equal( result.nextStep.step, 'bundle_imported' );
} );

test( 'full evidence reaches live', () => {
	const evidence = Object.fromEntries(
		STEP_DEFINITIONS.map( ( definition ) => [ definition.evidence, true ] )
	);
	const result = deriveState( evidence );
	assert.equal( result.state, 'live' );
	assert.equal( result.complete, true );
	assert.equal( result.nextStep, null );
} );

test( 'regresses when an earlier probe turns false', () => {
	const allTrue = Object.fromEntries(
		STEP_DEFINITIONS.map( ( definition ) => [ definition.evidence, true ] )
	);
	const first = deriveState( allTrue );
	assert.equal( first.state, 'live' );

	const regressed = deriveState( { ...allTrue, wpInstalled: false } );
	assert.equal( regressed.state, 'cloudways_app' );
	assert.equal( regressed.nextStep.step, 'wp_installed' );
} );

test( 'every state is reachable by construction', () => {
	assert.equal( ESTATE_STATES.length, STEP_DEFINITIONS.length + 1 );
} );

test( 'evidenceFor maps a step to its evidence key', () => {
	assert.deepEqual( evidenceFor( 'gateway_bound' ), { gatewayBound: true } );
	assert.throws( () => evidenceFor( 'nope' ), /Unknown estate step/ );
} );
