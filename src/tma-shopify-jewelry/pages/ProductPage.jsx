/**
 * ProductPage – Single product detail
 *
 * Displays the full product: image, name, price, description, variant
 * selector and Add-to-Cart button.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState } from '@wordpress/element';
import DOMPurify from 'dompurify';
import { useProduct } from '../hooks/useProduct';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import {
	extractProductImage,
	extractProductPrice,
	extractDefaultVariantId,
} from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/**
 * @param {number} price
 * @return {string}
 */
function formatPrice( price ) {
	if ( ! price ) {
		return '';
	}
	return new Intl.NumberFormat( 'en-US', {
		style:    'currency',
		currency: 'USD',
	} ).format( price );
}

/** @param {{ params: { productId:string, product?:object } }} props */
export default function ProductPage( { params } ) {
	const { navigate }             = useNav();
	const { haptic }               = useTMA();
	const { dispatch, items }      = useCart();
	const stub                     = params.product ?? null;
	const { product: full, loading, error } = useProduct( params.productId );
	const product                  = full ?? stub;

	const [ qty, setQty ]           = useState( 1 );
	const [ addedFlash, setAddedFlash ] = useState( false );

	if ( ! product && loading ) {
		return (
			<div className="tma-jw-page">
				<button className="tma-jw-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<LoadingSpinner />
			</div>
		);
	}

	if ( ! product && error ) {
		return (
			<div className="tma-jw-page">
				<button className="tma-jw-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<ErrorMessage message={ error } />
			</div>
		);
	}

	if ( ! product ) {
		return null;
	}

	const image     = extractProductImage( product );
	const price     = formatPrice( extractProductPrice( product ) );
	const variantId = extractDefaultVariantId( product );

	// Shopify uses totalInventory or availableForSale.
	const inStock =
		product.availableForSale !== false &&
		( product.totalInventory === null || product.totalInventory === undefined || product.totalInventory > 0 );

	const description = product.descriptionHtml ?? product.description ?? '';

	const handleAddToCart = () => {
		if ( ! inStock ) {
			return;
		}
		haptic( 'medium' );
		dispatch( {
			type:    'ADD_ITEM',
			payload: {
				item: {
					productId: product.id,
					variantId: variantId || null,
					name:      product.title ?? product.name,
					price:     extractProductPrice( product ),
					image:     image,
					qty,
				},
			},
		} );
		setAddedFlash( true );
		setTimeout( () => setAddedFlash( false ), 1500 );
	};

	return (
		<div className="tma-jw-page tma-jw-product-page">
			<button
				className="tma-jw-back-btn"
				onClick={ () => {
					haptic( 'light' );
					navigate( 'shop' );
				} }
			>
				← Back
			</button>

			{ /* Image */ }
			<div className="tma-jw-product-page__img-wrap">
				{ image ? (
					<img src={ image } alt={ product.title ?? product.name } className="tma-jw-product-page__img" />
				) : (
					<div className="tma-jw-product-page__img-placeholder">💍</div>
				) }
			</div>

			<div className="tma-jw-product-page__body">
				<h1 className="tma-jw-product-page__name">{ product.title ?? product.name }</h1>
				{ price && <p className="tma-jw-product-page__price">{ price }</p> }

				{ ! inStock && (
					<p className="tma-jw-product-page__oos">Out of stock</p>
				) }

				{ description && (
					<div
						className="tma-jw-product-page__desc"
						dangerouslySetInnerHTML={ { __html: DOMPurify.sanitize( description ) } }
					/>
				) }

				{ /* Quantity selector */ }
				{ inStock && (
					<div className="tma-jw-qty-row">
						<button
							className="tma-jw-qty-btn"
							onClick={ () => setQty( Math.max( 1, qty - 1 ) ) }
							aria-label="Decrease quantity"
						>−</button>
						<span className="tma-jw-qty-val">{ qty }</span>
						<button
							className="tma-jw-qty-btn"
							onClick={ () => setQty( qty + 1 ) }
							aria-label="Increase quantity"
						>+</button>
					</div>
				) }

				<button
					className={ `tma-jw-add-to-cart-btn${ ! inStock ? ' disabled' : '' }${ addedFlash ? ' added' : '' }` }
					onClick={ handleAddToCart }
					disabled={ ! inStock }
				>
					{ addedFlash ? '✓ Added to Cart' : inStock ? 'Add to Cart' : 'Out of Stock' }
				</button>

				{ items.length > 0 && (
					<button
						className="tma-jw-view-cart-btn"
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
