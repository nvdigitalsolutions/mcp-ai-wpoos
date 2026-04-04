/**
 * ProductCard component – Jewelry variant
 *
 * Grid card for a single Shopify product. Displays the product image
 * (falls back to a 💍 placeholder), name, and price. Calls `onClick` when
 * tapped (parent handles navigation to ProductPage).
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { extractProductImage, extractProductPrice } from '../api/client';

/**
 * Format a price number as a USD string (e.g. "$1,299.00").
 *
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

/**
 * @param {{ product:object, onClick:Function }} props
 * @return {JSX.Element}
 */
export default function ProductCard( { product, onClick } ) {
	const image = extractProductImage( product );
	const price = formatPrice( extractProductPrice( product ) );

	return (
		<button
			className="tma-jw-product-card"
			onClick={ onClick }
			aria-label={ product.title ?? product.name }
		>
			<div className="tma-jw-product-card__img-wrap">
				{ image ? (
					<img
						src={ image }
						alt={ product.title ?? product.name }
						className="tma-jw-product-card__img"
						loading="lazy"
					/>
				) : (
					<div className="tma-jw-product-card__img-placeholder">💍</div>
				) }
			</div>
			<div className="tma-jw-product-card__body">
				<p className="tma-jw-product-card__name">{ product.title ?? product.name }</p>
				{ price && <p className="tma-jw-product-card__price">{ price }</p> }
			</div>
		</button>
	);
}
