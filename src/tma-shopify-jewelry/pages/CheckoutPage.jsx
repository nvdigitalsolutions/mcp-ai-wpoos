/**
 * CheckoutPage – Order inquiry / draft-order placement
 *
 * Collects contact and shipping details. Submits the cart as a message to
 * the AI assistant which can relay an order enquiry, or — when the
 * `shopify_orders` tool supports a `create` action — creates a Shopify
 * draft order directly.
 *
 * Pre-fills first name and last name from the Telegram user profile when
 * available.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState } from '@wordpress/element';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import { executeTool, cfg, sendChat } from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';

/** @param {{ params:object }} props */
export default function CheckoutPage() {
	const { items, subtotal, dispatch } = useCart();
	const { haptic, user }              = useTMA();
	const { navigate }                  = useNav();

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
	const [ error, setError ]           = useState( null );
	const [ success, setSuccess ]       = useState( null );

	if ( items.length === 0 ) {
		return (
			<div className="tma-jw-page tma-jw-empty-state">
				<div className="tma-jw-empty-state__icon">✅</div>
				<p className="tma-jw-empty-state__text">No items to check out</p>
				<button
					className="tma-jw-btn tma-jw-btn--primary"
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
			variant_id:   item.variantId ?? null,
			quantity:     item.qty,
			title:        item.name,
		} ) );

		const shipping = {
			first_name: form.first_name,
			last_name:  form.last_name,
			email:      form.email,
			phone:      form.phone,
			address1:   form.address_1,
			city:       form.city,
			zip:        form.postcode,
			country:    form.country,
		};

		try {
			// Attempt to create a Shopify draft order via the tool.
			const raw = await executeTool( 'shopify_orders', {
				action:        'create',
				connection_id: cfg.connectionId,
				line_items:    lineItems,
				shipping_address: shipping,
				email:         form.email,
				note:          `Telegram Mini App order — ${ user?.username ? '@' + user.username : 'guest' }`,
			} );

			const order = raw?.data?.order ?? raw?.order ?? raw?.data ?? null;
			dispatch( { type: 'CLEAR' } );
			setSuccess( order ?? { id: 'enquiry', created: true } );
		} catch ( toolErr ) {
			// The shopify_orders create action is not yet supported; fall back to
			// sending the order as an AI chat enquiry so no order is lost.
			// eslint-disable-next-line no-console
			console.warn( '[JewelryTMA] shopify_orders create failed, falling back to enquiry:', toolErr );
			try {
				const summaryLines = items.map(
					( i ) => `• ${ i.name } × ${ i.qty } – ${ new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD' } ).format( i.price * i.qty ) }`
				);
				const total = new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD' } ).format( subtotal );
				const msg   = [
					`📦 New order enquiry from ${ form.first_name } ${ form.last_name } (${ form.email })`,
					`Ship to: ${ form.address_1 }, ${ form.city } ${ form.postcode }, ${ form.country }`,
					`Phone: ${ form.phone || '—' }`,
					'',
					...summaryLines,
					`Total: ${ total }`,
					'',
					'Please confirm availability and send payment instructions.',
				].join( '\n' );
				await sendChat( [ { role: 'user', content: msg } ] );
				dispatch( { type: 'CLEAR' } );
				setSuccess( { id: 'enquiry', created: true } );
			} catch ( chatErr ) {
				setError( chatErr.message );
			}
		} finally {
			setSubmitting( false );
		}
	};

	// ── Success screen ────────────────────────────────────────────────────────
	if ( success ) {
		const isEnquiry = success.id === 'enquiry';
		return (
			<div className="tma-jw-page tma-jw-empty-state">
				<div className="tma-jw-empty-state__icon">🎉</div>
				<h2 className="tma-jw-empty-state__title">
					{ isEnquiry ? 'Enquiry Sent!' : 'Order Placed!' }
				</h2>
				<p className="tma-jw-empty-state__text">
					{ isEnquiry
						? 'Our team will be in touch shortly to confirm your order and arrange payment.'
						: `Order #${ success.name ?? success.id } has been received. We'll be in touch soon.` }
				</p>
				<button
					className="tma-jw-btn tma-jw-btn--primary"
					onClick={ () => navigate( 'orders' ) }
				>
					{ isEnquiry ? 'View Collection' : 'View My Orders' }
				</button>
				<button
					className="tma-jw-btn tma-jw-btn--ghost"
					onClick={ () => navigate( 'shop' ) }
				>
					Continue Shopping
				</button>
			</div>
		);
	}

	// ── Checkout form ─────────────────────────────────────────────────────────
	return (
		<div className="tma-jw-page tma-jw-checkout-page">
			<header className="tma-jw-page-header">
				<button
					className="tma-jw-back-btn"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'cart' );
					} }
				>← Cart</button>
				<div className="tma-jw-page-header__info">
					<div className="tma-jw-page-header__name">Checkout</div>
				</div>
			</header>

			{ /* Order summary */ }
			<div className="tma-jw-checkout-page__summary">
				<div className="tma-jw-checkout-page__summary-label">Order total</div>
				<div className="tma-jw-checkout-page__summary-total">
					{ new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD' } ).format( subtotal ) }
				</div>
				<div className="tma-jw-checkout-page__summary-items">
					{ items.map( ( i ) => (
						<span key={ i.key } className="tma-jw-checkout-page__summary-item">
							{ i.name } × { i.qty }
						</span>
					) ) }
				</div>
			</div>

			<form className="tma-jw-checkout-form" onSubmit={ handleSubmit }>
				<div className="tma-jw-form-row tma-jw-form-row--half">
					<div className="tma-jw-form-field">
						<label htmlFor="jco-first-name">First name *</label>
						<input id="jco-first-name" className="tma-jw-input" type="text" name="first_name" value={ form.first_name } onChange={ handleChange } required />
					</div>
					<div className="tma-jw-form-field">
						<label htmlFor="jco-last-name">Last name *</label>
						<input id="jco-last-name" className="tma-jw-input" type="text" name="last_name" value={ form.last_name } onChange={ handleChange } required />
					</div>
				</div>
				<div className="tma-jw-form-field">
					<label htmlFor="jco-email">Email *</label>
					<input id="jco-email" className="tma-jw-input" type="email" name="email" value={ form.email } onChange={ handleChange } required />
				</div>
				<div className="tma-jw-form-field">
					<label htmlFor="jco-phone">Phone</label>
					<input id="jco-phone" className="tma-jw-input" type="tel" name="phone" value={ form.phone } onChange={ handleChange } />
				</div>
				<div className="tma-jw-form-field">
					<label htmlFor="jco-address">Address *</label>
					<input id="jco-address" className="tma-jw-input" type="text" name="address_1" value={ form.address_1 } onChange={ handleChange } required />
				</div>
				<div className="tma-jw-form-row tma-jw-form-row--half">
					<div className="tma-jw-form-field">
						<label htmlFor="jco-city">City *</label>
						<input id="jco-city" className="tma-jw-input" type="text" name="city" value={ form.city } onChange={ handleChange } required />
					</div>
					<div className="tma-jw-form-field">
						<label htmlFor="jco-postcode">Postcode *</label>
						<input id="jco-postcode" className="tma-jw-input" type="text" name="postcode" value={ form.postcode } onChange={ handleChange } required />
					</div>
				</div>
				<div className="tma-jw-form-field">
					<label htmlFor="jco-country">Country *</label>
					<input id="jco-country" className="tma-jw-input" type="text" name="country" value={ form.country } onChange={ handleChange } required placeholder="e.g. US" />
				</div>

				{ error && (
					<p className="tma-jw-checkout-error">⚠️ { error }</p>
				) }

				<button
					type="submit"
					className="tma-jw-btn tma-jw-btn--primary tma-jw-btn--full"
					disabled={ submitting }
				>
					{ submitting ? <LoadingSpinner size={ 18 } /> : 'Place Order' }
				</button>
			</form>
		</div>
	);
}
