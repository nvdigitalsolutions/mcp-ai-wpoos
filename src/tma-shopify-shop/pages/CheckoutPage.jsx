/**
 * CheckoutPage – Shopify order placement via AI assistant
 *
 * Collects contact info and delivery address, then places the order by
 * sending the cart details + contact info through the AI assistant chat.
 * Pre-fills name from the Telegram user profile when available.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState } from 'react';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import { sendChat } from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';

/** @param {{ params:object }} props */
export default function CheckoutPage() {
	const { items, subtotal, clearCart } = useCart();
	const { haptic, user } = useTMA();
	const { navigate } = useNav();

	const [ form, setForm ] = useState( {
		name:    ( user?.first_name ?? '' ) + ( user?.last_name ? ' ' + user.last_name : '' ),
		email:   '',
		phone:   '',
		address: '',
		city:    '',
		zip:     '',
		country: '',
		notes:   '',
	} );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( false );

	if ( items.length === 0 && ! success ) {
		return (
			<div className="tma-shopify-page tma-shopify-empty-state">
				<div className="tma-shopify-empty-state__icon">✅</div>
				<p className="tma-shopify-empty-state__text">No items to check out</p>
				<button
					className="tma-shopify-btn tma-shopify-btn--primary"
					onClick={ () => navigate( 'shop' ) }
				>
					Go Shopping
				</button>
			</div>
		);
	}

	const handleChange = ( e ) =>
		setForm( ( prev ) => ( { ...prev, [ e.target.name ]: e.target.value } ) );

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		haptic( 'medium' );
		setSubmitting( true );
		setError( null );

		// Build an order summary message to send through the AI assistant.
		const linesSummary = items
			.map( ( i ) => `- ${ i.title } × ${ i.quantity } ($$${ ( i.price * i.quantity ).toFixed( 2 ) })` )
			.join( '\n' );

		const orderMessage = [
			'I would like to place an order:',
			'',
			linesSummary,
			'',
			`Subtotal: $${ subtotal.toFixed( 2 ) }`,
			'',
			'Contact Info:',
			`Name: ${ form.name }`,
			`Email: ${ form.email }`,
			`Phone: ${ form.phone }`,
			`Address: ${ form.address }, ${ form.city } ${ form.zip }, ${ form.country }`,
			form.notes ? `Notes: ${ form.notes }` : '',
		].filter( Boolean ).join( '\n' );

		try {
			await sendChat( [
				{ role: 'user', content: orderMessage },
			] );
			clearCart();
			setSuccess( true );
			haptic( 'success' );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setSubmitting( false );
		}
	};

	// ── Success screen ───────────────────────────────────────────────────
	if ( success ) {
		return (
			<div className="tma-shopify-page tma-shopify-empty-state">
				<div className="tma-shopify-empty-state__icon">🎉</div>
				<h2 className="tma-shopify-empty-state__title">Order Submitted!</h2>
				<p className="tma-shopify-empty-state__text">
					Your order has been sent to our team. We&apos;ll be in touch soon.
				</p>
				<button
					className="tma-shopify-btn tma-shopify-btn--primary"
					onClick={ () => navigate( 'orders' ) }
				>
					View My Orders
				</button>
				<button
					className="tma-shopify-btn tma-shopify-btn--ghost"
					onClick={ () => navigate( 'shop' ) }
				>
					Continue Shopping
				</button>
			</div>
		);
	}

	// ── Checkout form ────────────────────────────────────────────────────
	return (
		<div className="tma-shopify-page tma-shopify-checkout-page">
			<header className="tma-shopify-page-header">
				<button
					className="tma-shopify-back-btn"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'cart' );
					} }
				>← Cart</button>
				<div className="tma-shopify-page-header__info">
					<div className="tma-shopify-page-header__name">Checkout</div>
				</div>
			</header>

			{ /* Order summary */ }
			<div className="tma-shopify-checkout-page__summary">
				<div className="tma-shopify-checkout-page__summary-label">Order total</div>
				<div className="tma-shopify-checkout-page__summary-total">${ subtotal.toFixed( 2 ) }</div>
				<div className="tma-shopify-checkout-page__summary-items">
					{ items.map( ( i ) => (
						<span key={ i.variantId || i.id } className="tma-shopify-checkout-page__summary-item">
							{ i.title } × { i.quantity }
						</span>
					) ) }
				</div>
			</div>

			<form className="tma-shopify-checkout-form" onSubmit={ handleSubmit }>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-name">Full name *</label>
					<input id="co-name" className="tma-shopify-input" type="text" name="name" value={ form.name } onChange={ handleChange } required />
				</div>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-email">Email *</label>
					<input id="co-email" className="tma-shopify-input" type="email" name="email" value={ form.email } onChange={ handleChange } required />
				</div>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-phone">Phone</label>
					<input id="co-phone" className="tma-shopify-input" type="tel" name="phone" value={ form.phone } onChange={ handleChange } />
				</div>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-address">Address *</label>
					<input id="co-address" className="tma-shopify-input" type="text" name="address" value={ form.address } onChange={ handleChange } required />
				</div>
				<div className="tma-shopify-form-row tma-shopify-form-row--half">
					<div className="tma-shopify-form-field">
						<label htmlFor="co-city">City *</label>
						<input id="co-city" className="tma-shopify-input" type="text" name="city" value={ form.city } onChange={ handleChange } required />
					</div>
					<div className="tma-shopify-form-field">
						<label htmlFor="co-zip">ZIP / Postcode *</label>
						<input id="co-zip" className="tma-shopify-input" type="text" name="zip" value={ form.zip } onChange={ handleChange } required />
					</div>
				</div>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-country">Country *</label>
					<input id="co-country" className="tma-shopify-input" type="text" name="country" value={ form.country } onChange={ handleChange } required placeholder="e.g. US" />
				</div>
				<div className="tma-shopify-form-field">
					<label htmlFor="co-notes">Order notes</label>
					<textarea id="co-notes" className="tma-shopify-input tma-shopify-textarea" name="notes" value={ form.notes } onChange={ handleChange } rows={ 3 } placeholder="Special requests…" />
				</div>

				{ error && (
					<p className="tma-shopify-checkout-error">⚠️ { error }</p>
				) }

				<button
					type="submit"
					className="tma-shopify-btn tma-shopify-btn--primary tma-shopify-btn--full"
					disabled={ submitting }
				>
					{ submitting ? <LoadingSpinner size={ 18 } /> : 'Place Order' }
				</button>
			</form>
		</div>
	);
}
