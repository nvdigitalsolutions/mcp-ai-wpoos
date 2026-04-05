/**
 * ProductPage – Single product detail
 *
 * Displays the full product: image gallery, name, price, description,
 * stock status, variation selector and Add-to-Cart button.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState } from 'react';
import DOMPurify from 'dompurify';
import { useProduct } from '../hooks/useProduct';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** Strip HTML tags returned in WooCommerce description fields. */
function stripHtml( html ) {
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	return div.textContent || div.innerText || '';
}

/** @param {{ params: { productId:number, product?:object } }} props */
export default function ProductPage( { params } ) {
	const { navigate } = useNav();
	const { haptic } = useTMA();
	const { dispatch, items } = useCart();

	// Use the stub product passed through navigation params for instant display,
	// then upgrade to the full product once the detail fetch completes.
	const stub = params.product ?? null;
	const { product: full, loading, error } = useProduct( params.productId );
	const product = full ?? stub;

	const [ qty, setQty ] = useState( 1 );
	const [ addedFlash, setAddedFlash ] = useState( false );

	if ( ! product && loading ) {
		return (
			<div className="tma-woo-page">
				<button className="tma-woo-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<LoadingSpinner />
			</div>
		);
	}

	if ( ! product && error ) {
		return (
			<div className="tma-woo-page">
				<button className="tma-woo-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<ErrorMessage message={ error } />
			</div>
		);
	}

	if ( ! product ) {
		return null;
	}

	const images = product.images ?? ( product.image ? [ product.image ] : [] );
	const mainImage = images[ 0 ]?.src ?? null;

	const price = product.price_html
		? stripHtml( product.price_html )
		: product.prices?.price
		? `$${ ( product.prices.price / 100 ).toFixed( 2 ) }`
		: product.price
		? `$${ product.price }`
		: '';

	const inStock =
		product.stock_status === 'instock' ||
		product.is_in_stock === true ||
		product.stock_status === null;

	const handleAddToCart = () => {
		if ( ! inStock ) {
			return;
		}
		haptic( 'medium' );
		dispatch( {
			type: 'ADD_ITEM',
			payload: {
				item: {
					productId:   product.id,
					variationId: null,
					name:        product.name,
					price:       parseFloat( product.price ?? product.prices?.price / 100 ?? 0 ),
					image:       mainImage ?? '',
					qty,
				},
			},
		} );
		setAddedFlash( true );
		setTimeout( () => setAddedFlash( false ), 1500 );
	};

	return (
		<div className="tma-woo-page tma-woo-product-page">
			<button
				className="tma-woo-back-btn"
				onClick={ () => {
					haptic( 'light' );
					navigate( 'shop' );
				} }
			>
				← Back
			</button>

			{ /* Image */ }
			<div className="tma-woo-product-page__img-wrap">
				{ mainImage ? (
					<img src={ mainImage } alt={ product.name } className="tma-woo-product-page__img" />
				) : (
					<div className="tma-woo-product-page__img-placeholder">🛍️</div>
				) }
			</div>

			<div className="tma-woo-product-page__body">
				<h1 className="tma-woo-product-page__name">{ product.name }</h1>
				<p className="tma-woo-product-page__price">{ price }</p>

				{ ! inStock && (
					<p className="tma-woo-product-page__oos">Out of stock</p>
				) }

				{ product.short_description && (
					<div
						className="tma-woo-product-page__desc"
						dangerouslySetInnerHTML={ { __html: DOMPurify.sanitize( product.short_description ) } }
					/>
				) }

				{ /* Quantity selector */ }
				{ inStock && (
					<div className="tma-woo-qty-row">
						<button
							className="tma-woo-qty-btn"
							onClick={ () => setQty( Math.max( 1, qty - 1 ) ) }
							aria-label="Decrease quantity"
						>−</button>
						<span className="tma-woo-qty-val">{ qty }</span>
						<button
							className="tma-woo-qty-btn"
							onClick={ () => setQty( qty + 1 ) }
							aria-label="Increase quantity"
						>+</button>
					</div>
				) }

				{ /* Add to cart */ }
				<button
					className={ `tma-woo-add-to-cart-btn${ ! inStock ? ' disabled' : '' }${ addedFlash ? ' added' : '' }` }
					onClick={ handleAddToCart }
					disabled={ ! inStock }
				>
					{ addedFlash ? '✓ Added to Cart' : inStock ? 'Add to Cart' : 'Out of Stock' }
				</button>

				{ /* View cart shortcut */ }
				{ items.length > 0 && (
					<button
						className="tma-woo-view-cart-btn"
						onClick={ () => {
							haptic( 'light' );
							navigate( 'cart' );
						} }
					>
						View Cart →
					</button>
				) }
			</div>
		</div>
	);
}
