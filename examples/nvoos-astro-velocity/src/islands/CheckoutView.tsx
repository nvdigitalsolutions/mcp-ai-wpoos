// ---------------------------------------------------------------------------
// CheckoutView island — the embedded checkout (Proposal 110 Phase B).
//
// Every piece of checkout state is per-shopper and client-side: the Woo
// session cart (Cart-Token) lives behind the bridge /checkout proxy, keyed
// by the localStorage cart-session id. The island:
//
//   1. reads the merged checkout doc (items, totals, addresses, rates),
//   2. validates addresses Woo-side (POST /checkout/customer),
//   3. selects a shipping rate, applies coupons,
//   4. collects payment through the gateway's HOSTED fields (Stripe
//      Elements — INV-1: no raw card inputs in the app) and places the
//      order (POST /checkout) with the confirmed total as expected_total,
//   5. routes to the gateway redirect (PayPal et al) or the thanks page.
//
// Fail-open ladder: no session / fetch error / empty server cart → the
// hosted WooCommerce checkout link (shop.checkoutUrl) stays available.
// ---------------------------------------------------------------------------

import { useEffect, useRef, useState } from 'react';
import type {
	ChangeEvent,
	Dispatch,
	FormEvent,
	RefObject,
	SetStateAction,
} from 'react';
import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import type { Stripe } from '@stripe/stripe-js';
import { formatPrice } from '../lib/commerce/money';
import {
	applyCheckoutCoupon,
	buildStripePaymentData,
	canEmbedCheckout,
	EMBEDDED_CARD_GATEWAY,
	getCheckout,
	postPaymentDestination,
	selectShippingRate,
	submitCheckout,
	updateCheckoutCustomer,
} from '../lib/commerce/checkout-api';
import type { CheckoutAddress, CheckoutDoc, ShopDoc } from '../lib/commerce/types';
import { clearCart, getCartSessionId } from '../lib/store/cart';

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/** An empty address (country defaults to US). */
const emptyAddress = (): CheckoutAddress => ({
	firstName: '',
	lastName: '',
	company: '',
	address1: '',
	address2: '',
	city: '',
	state: '',
	postcode: '',
	country: 'US',
	phone: '',
	email: '',
});

/** Format a Woo minor-unit string with the shop currency. */
function formatMinor(totalPrice: string): string {
	const numeric = Number(totalPrice);
	return formatPrice(Number.isFinite(numeric) ? numeric / 100 : 0);
}

/** The analytics seam (108 §2 extended) — one event per checkout milestone. */
function emitCheckoutEvent(
	name: string,
	detail: Record<string, unknown>
): void {
	window.dispatchEvent(new CustomEvent(name, { detail }));
}

type Phase = 'loading' | 'unavailable' | 'error' | 'empty' | 'ready';

// A stripe-promise memo per publishable key — one Stripe.js load per page.
const stripePromises = new Map<string, Promise<Stripe | null>>();
function stripeFor(key: string): Promise<Stripe | null> {
	let promise = stripePromises.get(key);
	if (!promise) {
		promise = loadStripe(key);
		stripePromises.set(key, promise);
	}
	return promise;
}

// ---------------------------------------------------------------------------
// The Stripe section — the ONLY place the gateway's hosted fields render.
// Exposes an imperative collector so the parent island owns the submit.
// ---------------------------------------------------------------------------

interface StripeCollector {
	collect: (
		billing: CheckoutAddress
	) => Promise<{ paymentData: Array<{ key: string; value: string | boolean }>; expectedTotal?: string } | { error: string }>;
}

interface StripeSectionProps {
	collector: RefObject<StripeCollector | null>;
}

// NOTE (110): Apple/Google Pay is deliberately deferred. Stripe.js v10
// removed the legacy Payment Request Button (whose `paymentmethod` event
// yielded the pm token the Store API needs) in favour of
// ExpressCheckoutElement — which confirms via client-secret PaymentIntents
// and cannot feed the Woo gateway's Store API `payment_data`. Express
// checkout returns as a live-verified follow-up (it also needs Apple Pay
// domain registration on the deployment side).
function StripeSection({ collector }: StripeSectionProps) {
	const stripe = useStripe();
	const elements = useElements();

	// Imperative collector — the parent calls collect() before placing the
	// order: validate the hosted fields, tokenize with Stripe (the card data
	// goes browser→Stripe directly, never through this app), and hand back
	// the gateway's payment_data.
	useEffect(() => {
		collector.current = {
			collect: async (billing) => {
				if (!stripe || !elements) {
					return { error: 'Card payments are not ready yet.' };
				}
				const submit = await elements.submit();
				if (submit.error) {
					return { error: submit.error.message ?? 'The card details could not be validated.' };
				}
				const created = await stripe.createPaymentMethod({ elements });
				if (created.error) {
					return { error: created.error.message ?? 'The card could not be processed.' };
				}
				return {
					paymentData: buildStripePaymentData(created.paymentMethod.id, {
						email: billing.email,
						firstName: billing.firstName,
						lastName: billing.lastName,
					}, 'cc'),
				};
			},
		};
		return () => {
			collector.current = null;
		};
	}, [stripe, elements, collector]);

	return (
		<div className="checkout__stripe" data-checkout-stripe>
			<PaymentElement
				options={{
					fields: { billingDetails: 'never' },
					wallets: { applePay: 'never', googlePay: 'never' },
				}}
				onLoadError={(event) => {
					// Stripe.js v10: errors surface via elements.submit() (inside
					// collect); a load failure replaces the collector so the
					// place-order click reports it instead of hanging.
					collector.current = {
						collect: async () => ({
							error: event.error.message ?? 'The card form failed to load.',
						}),
					};
				}}
			/>
		</div>
	);
}

// ---------------------------------------------------------------------------
// The checkout island.
// ---------------------------------------------------------------------------

interface Props {
	/** The shop globals doc fetched server-side (106 §1.3). */
	shop: ShopDoc;
}

export default function CheckoutView({ shop }: Props) {
	const [phase, setPhase] = useState<Phase>('loading');
	const [doc, setDoc] = useState<CheckoutDoc | null>(null);
	const [error, setError] = useState('');
	const [advanced, setAdvanced] = useState(false);
	const [billing, setBilling] = useState<CheckoutAddress>(emptyAddress);
	const [shipping, setShipping] = useState<CheckoutAddress>(emptyAddress);
	const [sameAsBilling, setSameAsBilling] = useState(true);
	const [paymentMethod, setPaymentMethod] = useState('');
	const [couponCode, setCouponCode] = useState('');
	const [createAccount, setCreateAccount] = useState(false);
	const [password, setPassword] = useState('');
	const [submitting, setSubmitting] = useState(false);
	const sessionRef = useRef('');
	const stripeCollector = useRef<StripeCollector | null>(null);

	const stripeKey = shop.stripePublishableKey ?? '';
	const stripeEnabled =
		canEmbedCheckout(shop) &&
		stripeKey !== '' &&
		(shop.paymentMethods ?? []).some((method) => method.id === EMBEDDED_CARD_GATEWAY);
	const guestAllowed = shop.guestCheckout === true;

	const setAddressField =
		(setter: Dispatch<SetStateAction<CheckoutAddress>>) =>
			(field: keyof CheckoutAddress) =>
				(event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
					const value = event.target.value;
					setter((current) => ({ ...current, [field]: value }));
				};

	useEffect(() => {
		document.querySelector('[data-checkout-root]')?.setAttribute('data-hydrated', 'true');
	}, []);

	// Boot: resolve the session, read the checkout doc.
	useEffect(() => {
		let cancelled = false;
		const boot = async () => {
			const session = getCartSessionId();
			if (!session) {
				setPhase('unavailable');
				return;
			}
			sessionRef.current = session;
			const result = await getCheckout(session);
			if (cancelled) {
				return;
			}
			if (!result.ok) {
				setError(result.error);
				setPhase('error');
				return;
			}
			const checkout = result.data;
			setDoc(checkout);
			if (checkout.billingAddress) {
				setBilling(checkout.billingAddress);
			}
			if (checkout.shippingAddress) {
				setShipping(checkout.shippingAddress);
			}
			if (checkout.paymentMethod) {
				setPaymentMethod(checkout.paymentMethod);
			} else {
				const first = (shop.paymentMethods ?? [])[0];
				setPaymentMethod(first ? first.id : '');
			}
			if (checkout.totals.totalItems === 0) {
				setPhase('empty');
				return;
			}
			setPhase('ready');
		};
		void boot();
		return () => {
			cancelled = true;
		};
		// The shop doc is server-baked per render — boot runs once per mount.
	}, []);

	/** Address step → Woo validates, totals + rates recalculate. */
	const handleContinue = async (event: FormEvent) => {
		event.preventDefault();
		if (submitting || !doc) {
			return;
		}
		setSubmitting(true);
		setError('');
		const result = await updateCheckoutCustomer(sessionRef.current, {
			billingAddress: billing,
			shippingAddress: sameAsBilling ? billing : shipping,
		});
		setSubmitting(false);
		if (!result.ok) {
			setError(result.error);
			return;
		}
		setDoc(result.data);
		setAdvanced(true);
	};

	/** Shipping rate change → Woo re-totals. */
	const handleRateChange = async (rateId: string) => {
		if (!doc) {
			return;
		}
		const pkg = doc.shippingRates[0];
		if (!pkg) {
			return;
		}
		setError('');
		const result = await selectShippingRate(sessionRef.current, pkg.packageId, rateId);
		if (!result.ok) {
			setError(result.error);
			return;
		}
		setDoc(result.data);
	};

	/** Coupon apply. */
	const handleCoupon = async () => {
		if (!doc || couponCode.trim() === '') {
			return;
		}
		setError('');
		const result = await applyCheckoutCoupon(sessionRef.current, couponCode.trim());
		if (!result.ok) {
			setError(result.error);
			return;
		}
		setDoc(result.data);
		setCouponCode('');
	};

	/** The shared place-order tail: submit → route → clear. */
	const placeOrder = async (
		paymentData?: Array<{ key: string; value: string | boolean }>,
		express = false
	) => {
		if (!doc) {
			return;
		}
		setSubmitting(true);
		setError('');
		emitCheckoutEvent('nvoos:checkout-submitted', {
			method: paymentMethod,
			items: doc.items.length,
			express,
		});
		const result = await submitCheckout(sessionRef.current, {
			paymentMethod,
			billingAddress: billing,
			shippingAddress: sameAsBilling ? billing : shipping,
			...(paymentData ? { paymentData } : {}),
			...(createAccount ? { createAccount: true, customerPassword: password } : {}),
			// The confirmed total pins the price (409 on drift) — express
			// flows may still change totals in the wallet, so they omit it.
			...(doc.totals.totalPrice && !express ? { expectedTotal: doc.totals.totalPrice } : {}),
		});
		if (!result.ok) {
			setSubmitting(false);
			setError(result.error);
			emitCheckoutEvent('nvoos:checkout-failed', { method: paymentMethod, reason: result.error });
			// Total drift → the backend returned the refreshed cart state;
			// re-read the doc so the summary shows the new truth.
			if (/total|price|changed/i.test(result.error)) {
				const refreshed = await getCheckout(sessionRef.current);
				if (refreshed.ok) {
					setDoc(refreshed.data);
				}
			}
			return;
		}
		emitCheckoutEvent('nvoos:checkout-succeeded', {
			method: paymentMethod,
			orderId: result.data.orderId,
		});
		clearCart();
		const destination = postPaymentDestination(result.data);
		if (destination.type === 'redirect') {
			emitCheckoutEvent('nvoos:checkout-redirect', {
				method: paymentMethod,
				orderId: result.data.orderId,
			});
			window.location.assign(destination.url);
			return;
		}
		window.location.assign(destination.url);
	};

	/** The standard place-order handler (card via hosted fields included). */
	const handlePlaceOrder = async (event: FormEvent) => {
		event.preventDefault();
		if (submitting || !doc) {
			return;
		}
		if (paymentMethod === EMBEDDED_CARD_GATEWAY && stripeEnabled) {
			const collector = stripeCollector.current;
			if (!collector) {
				setError('Card payments are not ready yet.');
				return;
			}
			const collected = await collector.collect(billing);
			if ('error' in collected) {
				setError(collected.error);
				return;
			}
			await placeOrder(collected.paymentData);
			return;
		}
		await placeOrder();
	};

	const hostedCheckout = shop.checkoutUrl;

	// ---------------------------------------------------------------------
	// Non-ready phases.
	// ---------------------------------------------------------------------

	if (phase === 'loading') {
		return (
			<div className="cart" data-checkout-root data-checkout-phase="loading">
				<p>Loading your order…</p>
			</div>
		);
	}

	if (phase === 'unavailable') {
		return (
			<div className="cart" data-checkout-root data-checkout-phase="unavailable">
				<p>The secure checkout is unavailable in this browser.</p>
				{hostedCheckout ? (
					<a className="btn" href={hostedCheckout} rel="noopener noreferrer" data-checkout-hosted>
						Checkout on WooCommerce
					</a>
				) : (
					<a className="btn" href="/cart/">
						Return to cart
					</a>
				)}
			</div>
		);
	}

	if (phase === 'error') {
		return (
			<div className="cart" data-checkout-root data-checkout-phase="error">
				<p>The checkout could not be loaded — {error}</p>
				{hostedCheckout ? (
					<a className="btn" href={hostedCheckout} rel="noopener noreferrer" data-checkout-hosted>
						Checkout on WooCommerce
					</a>
				) : (
					<a className="btn" href="/cart/">
						Return to cart
					</a>
				)}
			</div>
		);
	}

	if (phase === 'empty' || !doc) {
		return (
			<div className="cart" data-checkout-root data-checkout-phase="empty">
				<p>Your order summary is empty.</p>
				<a className="btn" href="/cart/" data-checkout-back>
					Return to cart
				</a>
			</div>
		);
	}

	// ---------------------------------------------------------------------
	// The checkout form.
	// ---------------------------------------------------------------------

	const rates = doc.shippingRates.flatMap((pkg) => pkg.rates);
	const selectedRate = rates.find((rate) => rate.selected);
	const totals = doc.totals;

	return (
		<div className="cart checkout" data-checkout-root data-checkout-phase="ready">
			{error !== '' && (
				<p className="cart__note" data-checkout-notice>
					{error}
				</p>
			)}
			{!guestAllowed && (
				<p className="cart__note" data-checkout-account-note>
					This store requires an account to check out.{' '}
					{hostedCheckout ? (
						<a href={hostedCheckout} rel="noopener noreferrer" data-checkout-hosted>
							Sign in at the hosted checkout
						</a>
					) : null}
				</p>
			)}

			<form onSubmit={handleContinue}>
				<section>
					<h2>Contact &amp; shipping</h2>
					<AddressFields
						prefix="billing"
						legend="Billing & contact"
						address={billing}
						onChange={setAddressField(setBilling)}
						includeEmail
					/>
					<label className="checkout__inline">
						<input
							type="checkbox"
							checked={sameAsBilling}
							onChange={(event) => setSameAsBilling(event.target.checked)}
							data-checkout-same-billing
						/>
						Ship to the same address
					</label>
					{!sameAsBilling && (
						<AddressFields
							prefix="shipping"
							legend="Shipping address"
							address={shipping}
							onChange={setAddressField(setShipping)}
						/>
					)}
					<button
						type="submit"
						className="btn"
						disabled={submitting}
						data-checkout-continue
					>
						{submitting ? 'Validating…' : 'Continue to shipping'}
					</button>
				</section>
			</form>

			{advanced && (
				<form onSubmit={handlePlaceOrder}>
					{doc.needsShipping && rates.length > 0 && (
						<section>
							<h2>Shipping method</h2>
							{rates.map((rate) => (
								<label key={rate.rateId} className="checkout__inline">
									<input
										type="radio"
										name="shipping-rate"
										value={rate.rateId}
										checked={selectedRate?.rateId === rate.rateId}
										onChange={() => {
											void handleRateChange(rate.rateId);
										}}
										data-shipping-rate
									/>
									{rate.name} — {formatMinor(rate.price)}
								</label>
							))}
						</section>
					)}

					<section>
						<h2>Coupon</h2>
						<div className="checkout__inline">
							<label htmlFor="checkout-coupon" className="sr-only">
									Coupon code
							</label>
							<input
								id="checkout-coupon"
								type="text"
								value={couponCode}
								onChange={(event) => setCouponCode(event.target.value)}
								data-checkout-coupon-input
							/>
							<button
								type="button"
								className="btn btn--ghost"
								onClick={() => {
									void handleCoupon();
								}}
								data-checkout-coupon-apply
							>
									Apply
							</button>
						</div>
						{doc.coupons.map((coupon) => (
							<p key={coupon.code} data-checkout-coupon>
									Coupon {coupon.code} applied.
							</p>
						))}
					</section>

					<section>
						<h2>Payment</h2>
						{(shop.paymentMethods ?? []).map((method) => (
							<label key={method.id} className="checkout__inline">
								<input
									type="radio"
									name="payment-method"
									value={method.id}
									checked={paymentMethod === method.id}
									onChange={(event) => setPaymentMethod(event.target.value)}
									data-payment-method
								/>
								{method.title}
							</label>
						))}
						{paymentMethod === EMBEDDED_CARD_GATEWAY && stripeEnabled ? (
							<Elements
								stripe={stripeFor(stripeKey)}
								options={{ mode: 'payment', currency: shop.currency.toLowerCase(), amount: Number(totals.totalPrice) || 0 }}
							>
								<StripeSection collector={stripeCollector} />
							</Elements>
						) : null}
						{paymentMethod === EMBEDDED_CARD_GATEWAY && !stripeEnabled ? (
							<p className="cart__note" data-checkout-stripe-unavailable>
									Card payments are not available on this store yet.
							</p>
						) : null}
						{guestAllowed && (
							<>
								<label className="checkout__inline">
									<input
										type="checkbox"
										checked={createAccount}
										onChange={(event) => setCreateAccount(event.target.checked)}
										data-checkout-create-account
									/>
										Create an account for faster checkout next time
								</label>
								{createAccount && (
									<label>
											Password
										<input
											type="password"
											autoComplete="new-password"
											value={password}
											onChange={(event) => setPassword(event.target.value)}
											data-checkout-password
										/>
									</label>
								)}
							</>
						)}
					</section>

					<section className="checkout__review" data-checkout-summary>
						<h2>Review</h2>
						<ul>
							{doc.items.map((item) => (
								<li key={item.key || String(item.productId)} data-checkout-line>
									{item.name} × {item.qty} — {formatPrice(item.price * item.qty)}
								</li>
							))}
						</ul>
						<p>
							<strong>
									Total: {formatMinor(totals.totalPrice)}{' '}
								<span data-checkout-total hidden>{totals.totalPrice}</span>
							</strong>
						</p>
						<button
							type="submit"
							className="btn"
							disabled={submitting || !guestAllowed}
							data-checkout-submit
						>
							{submitting ? 'Placing order…' : 'Place order'}
						</button>
						{hostedCheckout && (
							<p>
								<a href={hostedCheckout} rel="noopener noreferrer" data-checkout-hosted>
										Prefer the hosted checkout?
								</a>
							</p>
						)}
					</section>
				</form>
			)}
		</div>
	);
}

// ---------------------------------------------------------------------------
// Address fieldset — shared by billing and shipping.
// ---------------------------------------------------------------------------

interface AddressFieldsProps {
	prefix: string;
	legend: string;
	address: CheckoutAddress;
	onChange: (
		field: keyof CheckoutAddress
	) => (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
	/** Billing carries the contact email. */
	includeEmail?: boolean;
}

const COUNTRIES: Array<[string, string]> = [
	['US', 'United States'],
	['GB', 'United Kingdom'],
	['CA', 'Canada'],
	['AU', 'Australia'],
	['DE', 'Germany'],
	['FR', 'France'],
	['NL', 'Netherlands'],
	['ZA', 'South Africa'],
	['AE', 'United Arab Emirates'],
	['SG', 'Singapore'],
];

function AddressFields({ prefix, legend, address, onChange, includeEmail = false }: AddressFieldsProps) {
	const id = (field: string): string => `${prefix}-${field}`;
	return (
		<fieldset className="checkout__fields">
			<legend>{legend}</legend>
			<label htmlFor={id('firstName')}>
				First name
				<input
					id={id('firstName')}
					type="text"
					autoComplete="given-name"
					required
					value={address.firstName}
					onChange={onChange('firstName')}
					data-checkout-address-field={id('firstName')}
				/>
			</label>
			<label htmlFor={id('lastName')}>
				Last name
				<input
					id={id('lastName')}
					type="text"
					autoComplete="family-name"
					required
					value={address.lastName}
					onChange={onChange('lastName')}
					data-checkout-address-field={id('lastName')}
				/>
			</label>
			{includeEmail && (
				<label htmlFor={id('email')}>
					Email
					<input
						id={id('email')}
						type="email"
						autoComplete="email"
						required
						value={address.email}
						onChange={onChange('email')}
						data-checkout-address-field={id('email')}
					/>
				</label>
			)}
			<label htmlFor={id('company')}>
				Company (optional)
				<input
					id={id('company')}
					type="text"
					autoComplete="organization"
					value={address.company}
					onChange={onChange('company')}
					data-checkout-address-field={id('company')}
				/>
			</label>
			<label htmlFor={id('address1')}>
				Address
				<input
					id={id('address1')}
					type="text"
					autoComplete="address-line1"
					required
					value={address.address1}
					onChange={onChange('address1')}
					data-checkout-address-field={id('address1')}
				/>
			</label>
			<label htmlFor={id('address2')}>
				Apartment, suite (optional)
				<input
					id={id('address2')}
					type="text"
					autoComplete="address-line2"
					value={address.address2}
					onChange={onChange('address2')}
					data-checkout-address-field={id('address2')}
				/>
			</label>
			<label htmlFor={id('city')}>
				City
				<input
					id={id('city')}
					type="text"
					autoComplete="address-level2"
					required
					value={address.city}
					onChange={onChange('city')}
					data-checkout-address-field={id('city')}
				/>
			</label>
			<label htmlFor={id('state')}>
				State / Province
				<input
					id={id('state')}
					type="text"
					autoComplete="address-level1"
					value={address.state}
					onChange={onChange('state')}
					data-checkout-address-field={id('state')}
				/>
			</label>
			<label htmlFor={id('postcode')}>
				Postcode
				<input
					id={id('postcode')}
					type="text"
					autoComplete="postal-code"
					required
					value={address.postcode}
					onChange={onChange('postcode')}
					data-checkout-address-field={id('postcode')}
				/>
			</label>
			<label htmlFor={id('country')}>
				Country
				<select
					id={id('country')}
					autoComplete="country-name"
					value={address.country}
					onChange={onChange('country')}
					data-checkout-address-field={id('country')}
				>
					{COUNTRIES.map(([code, name]) => (
						<option key={code} value={code}>
							{name}
						</option>
					))}
				</select>
			</label>
			<label htmlFor={id('phone')}>
				Phone (optional)
				<input
					id={id('phone')}
					type="tel"
					autoComplete="tel"
					value={address.phone}
					onChange={onChange('phone')}
					data-checkout-address-field={id('phone')}
				/>
			</label>
		</fieldset>
	);
}
