/**
 * CheckoutPage – Order placement
 *
 * Collects billing information and places the order via the WooCommerce REST
 * API (`POST /wp-json/wc/v3/orders`). For remote connections it uses the
 * remote_wp_connection tool. Pre-fills name and email from the Telegram user
 * profile when available.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState } from '@wordpress/element';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import { executeTool, cfg } from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';

/** @param {{ params:object }} props */
export default function CheckoutPage() {
	const { items, subtotal, dispatch } = useCart();
	const { haptic, user } = useTMA();
	const { navigate } = useNav();

	const [ form, setForm ] = useState( {
		first_name: user?.first_name ?? '',
		last_name:  user?.last_name  ?? '',
		email:      '',
		phone:      '',
		address_1:  '',
		city:       '',
		postcode:   '',
		country:    '',
	} );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( null );

	if ( items.length === 0 ) {
		return (
			<div className="tma-woo-page tma-woo-empty-state">
				<div className="tma-woo-empty-state__icon">✅</div>
				<p className="tma-woo-empty-state__text">No items to check out</p>
				<button
					className="tma-woo-btn tma-woo-btn--primary"
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

		const lineItems = items.map( ( item ) => ( {
			product_id:   item.productId,
			variation_id: item.variationId ?? 0,
			quantity:     item.qty,
		} ) );

		const billing = {
			first_name: form.first_name,
			last_name:  form.last_name,
			email:      form.email,
			phone:      form.phone,
			address_1:  form.address_1,
			city:       form.city,
			postcode:   form.postcode,
			country:    form.country,
		};

		try {
			let order;
			if ( cfg.wooSource === 'remote' && cfg.wooConnectionId ) {
				// Place order via remote_wp_connection tool.
				// Note: remote_wp_connection currently supports read/update ops;
				// for order creation we send through the chat/tools execution pipeline.
				const raw = await executeTool( 'remote_wp_connection', {
					action:        'create_wc_order',
					connection_id: cfg.wooConnectionId,
					line_items:    lineItems,
					billing,
					shipping:      billing,
					status:        'pending',
				} );
				order = raw?.data?.order ?? raw?.order ?? raw;
			} else {
				// Place order via the local WooCommerce REST API.
				const siteUrl = cfg.siteUrl || window.location.origin;
				const res = await fetch(
					siteUrl.replace( /\/$/, '' ) + '/wp-json/wc/v3/orders',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':  cfg.nonce ?? '',
						},
						body: JSON.stringify( {
							line_items: lineItems,
							billing,
							shipping:   billing,
							status:     'pending',
						} ),
					}
				);
				if ( ! res.ok ) {
					const errBody = await res.json().catch( () => ( {} ) );
					throw new Error( errBody?.message || `Order failed: HTTP ${ res.status }` );
				}
				order = await res.json();
			}

			// Success — clear cart and show confirmation.
			dispatch( { type: 'CLEAR' } );
			setSuccess( order );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setSubmitting( false );
		}
	};

	// ── Success screen ────────────────────────────────────────────────────────
	if ( success ) {
		return (
			<div className="tma-woo-page tma-woo-empty-state">
				<div className="tma-woo-empty-state__icon">🎉</div>
				<h2 className="tma-woo-empty-state__title">Order Placed!</h2>
				<p className="tma-woo-empty-state__text">
					Order #{ success.id } has been received. We'll be in touch soon.
				</p>
				<button
					className="tma-woo-btn tma-woo-btn--primary"
					onClick={ () => navigate( 'orders' ) }
				>
					View My Orders
				</button>
				<button
					className="tma-woo-btn tma-woo-btn--ghost"
					onClick={ () => navigate( 'shop' ) }
				>
					Continue Shopping
				</button>
			</div>
		);
	}

	// ── Checkout form ─────────────────────────────────────────────────────────
	return (
		<div className="tma-woo-page tma-woo-checkout-page">
			<header className="tma-woo-page-header">
				<button
					className="tma-woo-back-btn"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'cart' );
					} }
				>← Cart</button>
				<div className="tma-woo-page-header__info">
					<div className="tma-woo-page-header__name">Checkout</div>
				</div>
			</header>

			{ /* Order summary */ }
			<div className="tma-woo-checkout-page__summary">
				<div className="tma-woo-checkout-page__summary-label">Order total</div>
				<div className="tma-woo-checkout-page__summary-total">${ subtotal.toFixed( 2 ) }</div>
				<div className="tma-woo-checkout-page__summary-items">
					{ items.map( ( i ) => (
						<span key={ i.key } className="tma-woo-checkout-page__summary-item">
							{ i.name } × { i.qty }
						</span>
					) ) }
				</div>
			</div>

			<form className="tma-woo-checkout-form" onSubmit={ handleSubmit }>
				<div className="tma-woo-form-row tma-woo-form-row--half">
					<div className="tma-woo-form-field">
						<label htmlFor="co-first-name">First name *</label>
						<input id="co-first-name" className="tma-woo-input" type="text" name="first_name" value={ form.first_name } onChange={ handleChange } required />
					</div>
					<div className="tma-woo-form-field">
						<label htmlFor="co-last-name">Last name *</label>
						<input id="co-last-name" className="tma-woo-input" type="text" name="last_name" value={ form.last_name } onChange={ handleChange } required />
					</div>
				</div>
				<div className="tma-woo-form-field">
					<label htmlFor="co-email">Email *</label>
					<input id="co-email" className="tma-woo-input" type="email" name="email" value={ form.email } onChange={ handleChange } required />
				</div>
				<div className="tma-woo-form-field">
					<label htmlFor="co-phone">Phone</label>
					<input id="co-phone" className="tma-woo-input" type="tel" name="phone" value={ form.phone } onChange={ handleChange } />
				</div>
				<div className="tma-woo-form-field">
					<label htmlFor="co-address">Address *</label>
					<input id="co-address" className="tma-woo-input" type="text" name="address_1" value={ form.address_1 } onChange={ handleChange } required />
				</div>
				<div className="tma-woo-form-row tma-woo-form-row--half">
					<div className="tma-woo-form-field">
						<label htmlFor="co-city">City *</label>
						<input id="co-city" className="tma-woo-input" type="text" name="city" value={ form.city } onChange={ handleChange } required />
					</div>
					<div className="tma-woo-form-field">
						<label htmlFor="co-postcode">Postcode *</label>
						<input id="co-postcode" className="tma-woo-input" type="text" name="postcode" value={ form.postcode } onChange={ handleChange } required />
					</div>
				</div>
				<div className="tma-woo-form-field">
					<label htmlFor="co-country">Country *</label>
					<input id="co-country" className="tma-woo-input" type="text" name="country" value={ form.country } onChange={ handleChange } required placeholder="e.g. US" />
				</div>

				{ error && (
					<p className="tma-woo-checkout-error">⚠️ { error }</p>
				) }

				<button
					type="submit"
					className="tma-woo-btn tma-woo-btn--primary tma-woo-btn--full"
					disabled={ submitting }
				>
					{ submitting ? <LoadingSpinner size={ 18 } /> : 'Place Order' }
				</button>
			</form>
		</div>
	);
}
