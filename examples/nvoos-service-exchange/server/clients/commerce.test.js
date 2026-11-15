import { test } from 'node:test';
import assert from 'node:assert/strict';
import { evaluateEntitlement, isAdvertisable, linkPrice, validatePriceId } from './commerce.js';

test( 'validates Stripe price ids', () => {
	assert.equal( validatePriceId( 'price_1234567890' ), 'price_1234567890' );
	assert.throws( () => validatePriceId( 'prod_123' ), /Stripe price id/ );
	assert.throws( () => validatePriceId( 'price_$bad' ), /Stripe price id/ );
} );

test( 'links a price to an estate', () => {
	const linked = linkPrice( { slug: 'clinic-ops' }, 'price_1234567890' );
	assert.equal( linked.stripePriceId, 'price_1234567890' );
	assert.match( linked.linkedAt, /^\d{4}-\d{2}-\d{2}T/ );
	assert.throws( () => linkPrice( null, 'price_1234567890' ), /estate is required/ );
} );

test( 'entitlement states', () => {
	const now = new Date( '2026-11-12T00:00:00Z' );
	assert.equal( evaluateEntitlement( { status: 'active', now } ), 'active' );
	assert.equal(
		evaluateEntitlement( { status: 'trialing', trialEndsAt: '2026-11-20T00:00:00Z', now } ),
		'trialing'
	);
	assert.equal(
		evaluateEntitlement( { status: 'trialing', trialEndsAt: '2026-11-01T00:00:00Z', now } ),
		'expired'
	);
	assert.equal( evaluateEntitlement( { status: 'canceled', now } ), 'suspended' );
	assert.equal( evaluateEntitlement( { now } ), 'suspended' );
} );

test( 'only active/trialing estates are advertisable', () => {
	assert.equal( isAdvertisable( 'active' ), true );
	assert.equal( isAdvertisable( 'trialing' ), true );
	assert.equal( isAdvertisable( 'suspended' ), false );
	assert.equal( isAdvertisable( 'expired' ), false );
} );
