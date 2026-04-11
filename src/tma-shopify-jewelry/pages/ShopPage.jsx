/**
 * ShopPage – Product Catalog
 *
 * Displays a searchable, filterable grid of Shopify jewelry products.
 * Features:
 *  - Debounced search input
 *  - Tap a product card → navigate to ProductPage
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState } from 'react';
import { useNav } from '../context/NavContext';
import { useTMA } from '../context/TMAContext';
import { useProducts } from '../hooks/useProducts';
import ProductCard from '../components/ProductCard';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** @param {{ params:object }} props */
export default function ShopPage() {
	const { navigate }  = useNav();
	const { haptic }    = useTMA();
	const [ search, setSearch ] = useState( '' );

	const { products, loading, error, reload } = useProducts( {
		search,
		first: 20,
	} );

	const siteName = window.wpTmaJewelryConfig?.siteName || 'Jewelry Shop';

	const handleProductClick = ( product ) => {
		haptic( 'light' );
		navigate( 'product', { productId: product.id, product } );
	};

	return (
		<div className="tma-jw-page tma-jw-shop-page">
			{ /* Header */ }
			<header className="tma-jw-page-header">
				<div className="tma-jw-page-header__avatar">💍</div>
				<div className="tma-jw-page-header__info">
					<div className="tma-jw-page-header__name">{ siteName }</div>
					<div className="tma-jw-page-header__status">Fine Jewelry</div>
				</div>
			</header>

			{ /* Search bar */ }
			<div className="tma-jw-search">
				<span className="tma-jw-search__icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<circle cx="11" cy="11" r="8" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" />
					</svg>
				</span>
				<input
					type="search"
					className="tma-jw-search__input"
					placeholder="Search rings, necklaces, bracelets…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					aria-label="Search products"
				/>
				{ search && (
					<button
						className="tma-jw-search__clear"
						onClick={ () => setSearch( '' ) }
						aria-label="Clear search"
					>✕</button>
				) }
			</div>

			{ /* Product grid */ }
			<div className="tma-jw-shop-page__grid-wrap">
				{ loading ? (
					<LoadingSpinner />
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : products.length === 0 ? (
					<div className="tma-jw-empty">
						<span>🔍</span>
						<p>No pieces found.</p>
					</div>
				) : (
					<div className="tma-jw-product-grid">
						{ products.map( ( product ) => (
							<ProductCard
								key={ product.id }
								product={ product }
								onClick={ () => handleProductClick( product ) }
							/>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}
