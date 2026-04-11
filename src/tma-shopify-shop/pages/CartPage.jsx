/**
 * CartPage – Shopping cart review
 *
 * Displays line items with quantity controls, subtotal, and a Proceed to
 * Checkout button. Empty-state prompts the user back to the Shop.
 * Integrates with Telegram MainButton for quick checkout.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useEffect } from 'react';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';

/** @param {{ params:object }} props */
export default function CartPage() {
	const { items, removeItem, updateQty, subtotal } = useCart();
	const { haptic, twa } = useTMA();
	const { navigate } = useNav();

	// Telegram MainButton for quick checkout.
	useEffect( () => {
		if ( ! twa?.MainButton || items.length === 0 ) {
			return;
		}
		twa.MainButton.setText( 'Proceed to Checkout' );
		twa.MainButton.show();
		const handler = () => navigate( 'checkout' );
		twa.MainButton.onClick( handler );
		return () => {
			twa.MainButton.offClick( handler );
			twa.MainButton.hide();
		};
	}, [ twa, items.length, navigate ] );

	if ( items.length === 0 ) {
		return (
			<div className="tma-shopify-page tma-shopify-empty-state">
				<div className="tma-shopify-empty-state__icon">🛒</div>
				<p className="tma-shopify-empty-state__text">Your cart is empty</p>
				<button
					className="tma-shopify-btn tma-shopify-btn--primary"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'shop' );
					} }
				>
					Browse Products
				</button>
			</div>
		);
	}

	const handleUpdateQty = ( item, qty ) => {
		haptic( 'selectionChanged' );
		updateQty( item, qty );
	};

	const handleRemove = ( item ) => {
		haptic( 'light' );
		removeItem( item );
	};

	return (
		<div className="tma-shopify-page tma-shopify-cart-page">
			<header className="tma-shopify-page-header">
				<div className="tma-shopify-page-header__avatar">🛒</div>
				<div className="tma-shopify-page-header__info">
					<div className="tma-shopify-page-header__name">Your Cart</div>
					<div className="tma-shopify-page-header__status">{ items.length } item{ items.length !== 1 ? 's' : '' }</div>
				</div>
			</header>

			<div className="tma-shopify-cart-page__items">
				{ items.map( ( item ) => {
					const key = item.variantId || item.id;
					return (
						<div key={ key } className="tma-shopify-cart-item">
							<div className="tma-shopify-cart-item__img-wrap">
								{ item.image ? (
									<img src={ item.image } alt={ item.title } className="tma-shopify-cart-item__img" />
								) : (
									<div className="tma-shopify-cart-item__img-placeholder">🛍️</div>
								) }
							</div>
							<div className="tma-shopify-cart-item__details">
								<p className="tma-shopify-cart-item__name">{ item.title }</p>
								<p className="tma-shopify-cart-item__price">${ ( item.price * item.quantity ).toFixed( 2 ) }</p>
								<div className="tma-shopify-qty-row tma-shopify-qty-row--sm">
									<button
										className="tma-shopify-qty-btn"
										onClick={ () => handleUpdateQty( item, item.quantity - 1 ) }
										aria-label="Decrease"
									>−</button>
									<span className="tma-shopify-qty-val">{ item.quantity }</span>
									<button
										className="tma-shopify-qty-btn"
										onClick={ () => handleUpdateQty( item, item.quantity + 1 ) }
										aria-label="Increase"
									>+</button>
								</div>
							</div>
							<button
								className="tma-shopify-cart-item__remove"
								onClick={ () => handleRemove( item ) }
								aria-label={ `Remove ${ item.title }` }
							>✕</button>
						</div>
					);
				} ) }
			</div>

			<div className="tma-shopify-cart-page__footer">
				<div className="tma-shopify-cart-page__subtotal">
					<span>Subtotal</span>
					<span>${ subtotal.toFixed( 2 ) }</span>
				</div>
				<button
					className="tma-shopify-btn tma-shopify-btn--primary tma-shopify-btn--full"
					onClick={ () => {
						haptic( 'medium' );
						navigate( 'checkout' );
					} }
				>
					Proceed to Checkout →
				</button>
				<button
					className="tma-shopify-btn tma-shopify-btn--ghost tma-shopify-btn--full"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'shop' );
					} }
				>
					Continue Shopping
				</button>
			</div>
		</div>
	);
}
