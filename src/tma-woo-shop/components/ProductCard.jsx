/**
 * ProductCard component
 *
 * Grid card for a single product. Displays the product image (falls back to
 * emoji placeholder), name, and formatted price. Calls `onClick` when tapped
 * (parent handles navigation to the ProductPage).
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

/**
 * Safely decode HTML entities returned by WooCommerce (e.g. price_html).
 *
 * @param {string} html
 * @return {string}
 */
function stripHtml( html ) {
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	return div.textContent || div.innerText || '';
}

/**
 * @param {{ product:object, onClick:Function }} props
 * @return {JSX.Element}
 */
export default function ProductCard( { product, onClick } ) {
	const image =
		product.images?.[ 0 ]?.src ||
		product.image?.src ||
		null;

	const price = product.price_html
		? stripHtml( product.price_html )
		: product.price
		? `$${ product.price }`
		: '';

	return (
		<button
			className="tma-woo-product-card"
			onClick={ onClick }
			aria-label={ product.name }
		>
			<div className="tma-woo-product-card__img-wrap">
				{ image ? (
					<img
						src={ image }
						alt={ product.name }
						className="tma-woo-product-card__img"
						loading="lazy"
					/>
				) : (
					<div className="tma-woo-product-card__img-placeholder">🛍️</div>
				) }
				{ product.on_sale && (
					<span className="tma-woo-product-card__badge">Sale</span>
				) }
			</div>
			<div className="tma-woo-product-card__body">
				<p className="tma-woo-product-card__name">{ product.name }</p>
				<p className="tma-woo-product-card__price">{ price }</p>
			</div>
		</button>
	);
}
