/**
 * SkeletonCard component
 *
 * Animated placeholder card with shimmer effect for the product grid loading
 * state. Mimics the layout of a ProductCard so the skeleton feels natural.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * @return {JSX.Element}
 */
export default function SkeletonCard() {
	return (
		<div className="tma-shopify-skeleton-card" aria-hidden="true">
			<div className="tma-shopify-skeleton-card__img" />
			<div className="tma-shopify-skeleton-card__body">
				<div className="tma-shopify-skeleton-card__line tma-shopify-skeleton-card__line--title" />
				<div className="tma-shopify-skeleton-card__line tma-shopify-skeleton-card__line--price" />
			</div>
		</div>
	);
}
