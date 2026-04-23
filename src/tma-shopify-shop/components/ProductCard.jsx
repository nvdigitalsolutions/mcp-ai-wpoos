/**
 * ProductCard component
 *
 * Grid card for a single Shopify product. Displays the product image (falls
 * back to emoji placeholder), title, formatted price, sale badge when
 * compareAtPrice exists, and stock status. Calls `onClick` when tapped.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import {
	extractProductImage,
	extractProductPrice,
	extractCompareAtPrice,
} from '../api/client';

/**
 * @param {{ product:object, onClick:Function }} props
 * @return {JSX.Element}
 */
export default function ProductCard( { product, onClick } ) {
	const image      = extractProductImage( product );
	const price      = extractProductPrice( product );
	const compareAt  = extractCompareAtPrice( product );
	const onSale     = compareAt > 0 && compareAt > price;
	const inStock    = product.availableForSale !== false;

	return (
		<button
			className="tma-shopify-product-card"
			onClick={ onClick }
			aria-label={ product.title }
		>
			<div className="tma-shopify-product-card__img-wrap">
				{ image ? (
					<img
						src={ image }
						alt={ product.title }
						className="tma-shopify-product-card__img"
						loading="lazy"
					/>
				) : (
					<div className="tma-shopify-product-card__img-placeholder">🛍️</div>
				) }
				{ onSale && (
					<span className="tma-shopify-product-card__badge">Sale</span>
				) }
			</div>
			<div className="tma-shopify-product-card__body">
				<p className="tma-shopify-product-card__name">{ product.title }</p>
				<div className="tma-shopify-product-card__price-row">
					<span className="tma-shopify-product-card__price">
						{ price > 0 ? `$${ price.toFixed( 2 ) }` : 'Price TBD' }
					</span>
					{ onSale && (
						<span className="tma-shopify-product-card__compare-price">
							$${ compareAt.toFixed( 2 ) }
						</span>
					) }
				</div>
				{ ! inStock && (
					<span className="tma-shopify-product-card__stock tma-shopify-product-card__stock--out">
						Out of stock
					</span>
				) }
			</div>
		</button>
	);
}
