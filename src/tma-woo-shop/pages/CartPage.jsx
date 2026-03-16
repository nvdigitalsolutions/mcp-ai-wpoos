/**
 * CartPage – Shopping cart review
 *
 * Displays line items with quantity controls, subtotal, and a Proceed to
 * Checkout button. Empty-state prompts the user back to the Shop.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';

/** @param {{ params:object }} props */
export default function CartPage() {
	const { items, dispatch, subtotal } = useCart();
	const { haptic } = useTMA();
	const { navigate } = useNav();

	if ( items.length === 0 ) {
		return (
			<div className="tma-woo-page tma-woo-empty-state">
				<div className="tma-woo-empty-state__icon">🛒</div>
				<p className="tma-woo-empty-state__text">Your cart is empty</p>
				<button
					className="tma-woo-btn tma-woo-btn--primary"
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

	const updateQty = ( key, qty ) => {
		haptic( 'selectionChanged' );
		dispatch( { type: 'UPDATE_QTY', payload: { key, qty } } );
	};

	const removeItem = ( key ) => {
		haptic( 'light' );
		dispatch( { type: 'REMOVE_ITEM', payload: { key } } );
	};

	return (
		<div className="tma-woo-page tma-woo-cart-page">
			<header className="tma-woo-page-header">
				<div className="tma-woo-page-header__avatar">🛒</div>
				<div className="tma-woo-page-header__info">
					<div className="tma-woo-page-header__name">Your Cart</div>
					<div className="tma-woo-page-header__status">{ items.length } item{ items.length !== 1 ? 's' : '' }</div>
				</div>
			</header>

			<div className="tma-woo-cart-page__items">
				{ items.map( ( item ) => (
					<div key={ item.key } className="tma-woo-cart-item">
						<div className="tma-woo-cart-item__img-wrap">
							{ item.image ? (
								<img src={ item.image } alt={ item.name } className="tma-woo-cart-item__img" />
							) : (
								<div className="tma-woo-cart-item__img-placeholder">🛍️</div>
							) }
						</div>
						<div className="tma-woo-cart-item__details">
							<p className="tma-woo-cart-item__name">{ item.name }</p>
							<p className="tma-woo-cart-item__price">${ ( item.price * item.qty ).toFixed( 2 ) }</p>
							<div className="tma-woo-qty-row tma-woo-qty-row--sm">
								<button
									className="tma-woo-qty-btn"
									onClick={ () => updateQty( item.key, item.qty - 1 ) }
									aria-label="Decrease"
								>−</button>
								<span className="tma-woo-qty-val">{ item.qty }</span>
								<button
									className="tma-woo-qty-btn"
									onClick={ () => updateQty( item.key, item.qty + 1 ) }
									aria-label="Increase"
								>+</button>
							</div>
						</div>
						<button
							className="tma-woo-cart-item__remove"
							onClick={ () => removeItem( item.key ) }
							aria-label={ `Remove ${ item.name }` }
						>✕</button>
					</div>
				) ) }
			</div>

			<div className="tma-woo-cart-page__footer">
				<div className="tma-woo-cart-page__subtotal">
					<span>Subtotal</span>
					<span>${ subtotal.toFixed( 2 ) }</span>
				</div>
				<button
					className="tma-woo-btn tma-woo-btn--primary tma-woo-btn--full"
					onClick={ () => {
						haptic( 'medium' );
						navigate( 'checkout' );
					} }
				>
					Proceed to Checkout →
				</button>
				<button
					className="tma-woo-btn tma-woo-btn--ghost tma-woo-btn--full"
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
