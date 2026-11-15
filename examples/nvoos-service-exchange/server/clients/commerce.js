/**
 * Commerce primitives (ADR-07).
 *
 * v1 never touches customer money: the expert keeps their own Stripe
 * product; the Exchange stores the price pointer and gates catalogue
 * visibility on the platform subscription status that the vendor bills.
 * No card data, no secrets, no Stripe keys in this process.
 */

import { ApiError } from '../util/envelope.js';

const PRICE_RE = /^price_[A-Za-z0-9]{8,}$/;

export function validatePriceId( priceId ) {
	if ( typeof priceId !== 'string' || ! PRICE_RE.test( priceId ) ) {
		throw new ApiError( 'bad_request', 'Expected a Stripe price id (price_…)', { field: 'stripe_price_id' }, 400 );
	}
	return priceId;
}

/**
 * @param {object} estate
 * @param {string} priceId
 * @return {{stripePriceId: string, linkedAt: string}}
 */
export function linkPrice( estate, priceId ) {
	if ( ! estate || ! estate.slug ) {
		throw new ApiError( 'bad_request', 'An estate is required', {}, 400 );
	}
	return {
		stripePriceId: validatePriceId( priceId ),
		linkedAt: new Date().toISOString(),
	};
}

/**
 * @param {{status?:string, trialEndsAt?:string|null, now?:Date}} input
 * @return {'active'|'trialing'|'expired'|'suspended'}
 */
export function evaluateEntitlement( { status, trialEndsAt = null, now = new Date() } = {} ) {
	if ( status === 'active' ) {
		return 'active';
	}
	if ( status === 'trialing' ) {
		if ( trialEndsAt ) {
			const end = new Date( trialEndsAt );
			if ( ! Number.isNaN( end.getTime() ) && now < end ) {
				return 'trialing';
			}
			return 'expired';
		}
		return 'trialing';
	}
	return 'suspended';
}

export function isAdvertisable( entitlement ) {
	return entitlement === 'active' || entitlement === 'trialing';
}
