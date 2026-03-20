/**
 * CartPage – Shopping cart review
 *
 * Displays line items with quantity controls, subtotal, and a Proceed to
 * Checkout button. Empty-state prompts the user back to the Shop.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';

/** @param {{ params:object }} props */
export default function CartPage() {
	const { items, dispatch, subtotal } = useCart();
	const { haptic }                    = useTMA();
	const { navigate }                  = useNav();

	if ( items.length === 0 ) {
		return (
			<div className="tma-jw-page tma-jw-empty-state">
				<div className="tma-jw-empty-state__icon">🛒</div>
				<p className="tma-jw-empty-state__text">Your cart is empty</p>
				<button
					className="tma-jw-btn tma-jw-btn--primary"
					onClick={ () => {
						haptic( 'light' );
						navigate( 'shop' );
					} }
				>
					Browse Collection
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
		<div className="tma-jw-page tma-jw-cart-page">
			<header className="tma-jw-page-header">
				<div className="tma-jw-page-header__avatar">🛒</div>
				<div className="tma-jw-page-header__info">
					<div className="tma-jw-page-header__name">Your Cart</div>
					<div className="tma-jw-page-header__status">
						{ items.length } item{ items.length !== 1 ? 's' : '' }
					</div>
				</div>
			</header>

			<div className="tma-jw-cart-page__items">
				{ items.map( ( item ) => (
					<div key={ item.key } className="tma-jw-cart-item">
						<div className="tma-jw-cart-item__img-wrap">
							{ item.image ? (
								<img src={ item.image } alt={ item.name } className="tma-jw-cart-item__img" />
							) : (
								<div className="tma-jw-cart-item__img-placeholder">💍</div>
							) }
						</div>
						<div className="tma-jw-cart-item__details">
							<p className="tma-jw-cart-item__name">{ item.name }</p>
							<p className="tma-jw-cart-item__price">
								{ new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD' } ).format( item.price * item.qty ) }
							</p>
							<div className="tma-jw-qty-row tma-jw-qty-row--sm">
								<button
									className="tma-jw-qty-btn"
									onClick={ () => updateQty( item.key, item.qty - 1 ) }
									aria-label="Decrease"
								>−</button>
								<span className="tma-jw-qty-val">{ item.qty }</span>
								<button
									className="tma-jw-qty-btn"
									onClick={ () => updateQty( item.key, item.qty + 1 ) }
									aria-label="Increase"
								>+</button>
							</div>
						</div>
						<button
							className="tma-jw-cart-item__remove"
							onClick={ () => removeItem( item.key ) }
							aria-label={ `Remove ${ item.name }` }
						>✕</button>
					</div>
				) ) }
			</div>

			<div className="tma-jw-cart-page__footer">
				<div className="tma-jw-cart-page__subtotal">
					<span>Subtotal</span>
					<span>
						{ new Intl.NumberFormat( 'en-US', { style: 'currency', currency: 'USD' } ).format( subtotal ) }
					</span>
				</div>
				<button
					className="tma-jw-btn tma-jw-btn--primary tma-jw-btn--full"
					onClick={ () => {
						haptic( 'medium' );
						navigate( 'checkout' );
					} }
				>
					Proceed to Checkout →
				</button>
				<button
					className="tma-jw-btn tma-jw-btn--ghost tma-jw-btn--full"
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
