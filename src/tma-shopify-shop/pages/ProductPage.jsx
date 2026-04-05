/**
 * ProductPage – Single Shopify product detail
 *
 * Displays the full product: image gallery (swipeable), title, price,
 * description, variant selector, stock status, and Add-to-Cart button.
 * Supports sharing via Telegram inline query and haptic feedback.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useRef } from 'react';
import { useProduct } from '../hooks/useProduct';
import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import {
	extractProductImage,
	extractProductPrice,
	extractCompareAtPrice,
	extractDefaultVariantId,
} from '../api/client';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/**
 * Safely extract text content from HTML description.
 *
 * @param {string} html Raw HTML string.
 * @return {string}
 */
function stripHtml( html ) {
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	return div.textContent || div.innerText || '';
}

/**
 * Get all images from a Shopify product.
 *
 * @param {object} product Shopify product node.
 * @return {Array<{url:string,altText:string}>}
 */
function getAllImages( product ) {
	const edges = product?.images?.edges;
	if ( edges?.length ) {
		return edges.map( ( e ) => ( { url: e.node?.url ?? '', altText: e.node?.altText ?? '' } ) );
	}
	const nodes = product?.images?.nodes;
	if ( nodes?.length ) {
		return nodes.map( ( n ) => ( { url: n.url ?? '', altText: n.altText ?? '' } ) );
	}
	if ( product?.image?.url ) {
		return [ { url: product.image.url, altText: '' } ];
	}
	return [];
}

/**
 * Get all variants from a Shopify product.
 *
 * @param {object} product Shopify product node.
 * @return {object[]}
 */
function getVariants( product ) {
	const edges = product?.variants?.edges;
	if ( edges?.length ) {
		return edges.map( ( e ) => e.node );
	}
	const nodes = product?.variants?.nodes;
	if ( nodes?.length ) {
		return nodes;
	}
	return [];
}

/** @param {{ params: { productId:string, product?:object } }} props */
export default function ProductPage( { params } ) {
	const { navigate } = useNav();
	const { haptic } = useTMA();
	const { addItem, items } = useCart();

	// Stub product from navigation for instant display.
	const stub = params.product ?? null;
	const { product: full, loading, error } = useProduct( params.productId );
	const product = full ?? stub;

	const [ qty, setQty ] = useState( 1 );
	const [ addedFlash, setAddedFlash ] = useState( false );
	const [ imageIdx, setImageIdx ] = useState( 0 );
	const [ selectedVariantIdx, setSelectedVariantIdx ] = useState( 0 );
	const touchStartX = useRef( 0 );

	if ( ! product && loading ) {
		return (
			<div className="tma-shopify-page">
				<button className="tma-shopify-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<LoadingSpinner />
			</div>
		);
	}

	if ( ! product && error ) {
		return (
			<div className="tma-shopify-page">
				<button className="tma-shopify-back-btn" onClick={ () => navigate( 'shop' ) }>← Back</button>
				<ErrorMessage message={ error } />
			</div>
		);
	}

	if ( ! product ) {
		return null;
	}

	const images   = getAllImages( product );
	const variants = getVariants( product );
	const variant  = variants[ selectedVariantIdx ] ?? variants[ 0 ] ?? null;

	const price     = variant?.price ? parseFloat( variant.price ) : extractProductPrice( product );
	const compareAt = variant?.compareAtPrice
		? parseFloat( variant.compareAtPrice )
		: extractCompareAtPrice( product );
	const onSale    = compareAt > 0 && compareAt > price;
	const inStock   = product.availableForSale !== false &&
					  ( variant?.availableForSale !== false );
	const variantId = variant?.id ?? extractDefaultVariantId( product );
	const mainImage = images[ imageIdx ]?.url ?? extractProductImage( product );

	// Swipe handling for image gallery.
	const onGalleryTouchStart = ( e ) => {
		touchStartX.current = e.touches[ 0 ].clientX;
	};
	const onGalleryTouchEnd = ( e ) => {
		const diff = touchStartX.current - e.changedTouches[ 0 ].clientX;
		if ( Math.abs( diff ) > 50 && images.length > 1 ) {
			if ( diff > 0 && imageIdx < images.length - 1 ) {
				setImageIdx( imageIdx + 1 );
			} else if ( diff < 0 && imageIdx > 0 ) {
				setImageIdx( imageIdx - 1 );
			}
		}
	};

	const handleAddToCart = () => {
		if ( ! inStock ) {
			return;
		}
		haptic( 'medium' );
		addItem( {
			id:        product.id,
			variantId,
			title:     product.title,
			price,
			quantity:  qty,
			image:     mainImage ?? '',
		} );
		setAddedFlash( true );
		setTimeout( () => setAddedFlash( false ), 1500 );
	};

	const handleShare = () => {
		const twa = window.Telegram?.WebApp;
		if ( twa?.switchInlineQuery ) {
			twa.switchInlineQuery( product.title ?? '' );
		}
	};

	const description = product.descriptionHtml
		? stripHtml( product.descriptionHtml )
		: product.description ?? '';

	return (
		<div className="tma-shopify-page tma-shopify-product-page">
			<button
				className="tma-shopify-back-btn"
				onClick={ () => {
					haptic( 'light' );
					navigate( 'shop' );
				} }
			>
				← Back
			</button>

			{ /* Image gallery */ }
			<div
				className="tma-shopify-product-page__img-wrap"
				onTouchStart={ onGalleryTouchStart }
				onTouchEnd={ onGalleryTouchEnd }
			>
				{ mainImage ? (
					<img src={ mainImage } alt={ product.title } className="tma-shopify-product-page__img" />
				) : (
					<div className="tma-shopify-product-page__img-placeholder">🛍️</div>
				) }
				{ images.length > 1 && (
					<div className="tma-shopify-product-page__dots">
						{ images.map( ( _, i ) => (
							<button
								key={ i }
								className={ `tma-shopify-product-page__dot${ i === imageIdx ? ' active' : '' }` }
								onClick={ () => setImageIdx( i ) }
								aria-label={ `Image ${ i + 1 }` }
							/>
						) ) }
					</div>
				) }
				{ onSale && (
					<span className="tma-shopify-product-page__sale-badge">Sale</span>
				) }
			</div>

			<div className="tma-shopify-product-page__body">
				<h1 className="tma-shopify-product-page__name">{ product.title }</h1>

				<div className="tma-shopify-product-page__price-row">
					<span className="tma-shopify-product-page__price">
						${ price.toFixed( 2 ) }
					</span>
					{ onSale && (
						<span className="tma-shopify-product-page__compare-price">
							$${ compareAt.toFixed( 2 ) }
						</span>
					) }
				</div>

				{ /* Stock status */ }
				<p className={ `tma-shopify-product-page__stock${ inStock ? ' in' : ' out' }` }>
					{ inStock ? '● In stock' : '● Out of stock' }
				</p>

				{ /* Variant selector */ }
				{ variants.length > 1 && (
					<div className="tma-shopify-product-page__variants">
						<p className="tma-shopify-product-page__variants-label">Options</p>
						<div className="tma-shopify-product-page__variants-list">
							{ variants.map( ( v, i ) => (
								<button
									key={ v.id }
									className={ `tma-shopify-variant-btn${ i === selectedVariantIdx ? ' active' : '' }` }
									onClick={ () => {
										haptic( 'selectionChanged' );
										setSelectedVariantIdx( i );
									} }
								>
									{ v.title }
								</button>
							) ) }
						</div>
					</div>
				) }

				{ /* Description */ }
				{ description && (
					<p className="tma-shopify-product-page__desc">{ description }</p>
				) }

				{ /* Quantity selector */ }
				{ inStock && (
					<div className="tma-shopify-qty-row">
						<button
							className="tma-shopify-qty-btn"
							onClick={ () => setQty( Math.max( 1, qty - 1 ) ) }
							aria-label="Decrease quantity"
						>−</button>
						<span className="tma-shopify-qty-val">{ qty }</span>
						<button
							className="tma-shopify-qty-btn"
							onClick={ () => setQty( qty + 1 ) }
							aria-label="Increase quantity"
						>+</button>
					</div>
				) }

				{ /* Add to cart */ }
				<button
					className={ `tma-shopify-add-to-cart-btn${ ! inStock ? ' disabled' : '' }${ addedFlash ? ' added' : '' }` }
					onClick={ handleAddToCart }
					disabled={ ! inStock }
				>
					{ addedFlash ? '✓ Added to Cart' : inStock ? 'Add to Cart' : 'Out of Stock' }
				</button>

				{ /* Share via Telegram */ }
				<button
					className="tma-shopify-share-btn"
					onClick={ handleShare }
				>
					Share via Telegram
				</button>

				{ /* View cart shortcut */ }
				{ items.length > 0 && (
					<button
						className="tma-shopify-view-cart-btn"
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
