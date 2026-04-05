/**
 * ShopPage – Product Catalog
 *
 * Displays a filterable, searchable grid of WooCommerce products. Features:
 *  - Debounced search input
 *  - Category filter pills (from WooCommerce Store API)
 *  - Sort dropdown (popularity, price, date, rating)
 *  - Infinite scroll "Load More" button
 *  - Tap product card → navigate to ProductPage
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState } from '@wordpress/element';
import { useNav } from '../context/NavContext';
import { useTMA } from '../context/TMAContext';
import { useProducts } from '../hooks/useProducts';
import { useCategories } from '../hooks/useCategories';
import ProductCard from '../components/ProductCard';
import ProductFilters from '../components/ProductFilters';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** @param {{ params:object }} props */
export default function ShopPage() {
	const { navigate } = useNav();
	const { haptic, twa } = useTMA();

	const [ search, setSearch ] = useState( '' );
	const [ categoryId, setCategoryId ] = useState( '' );
	const [ sortBy, setSortBy ] = useState( 'menu_order' );

	// Derive orderby / order from the sortBy shorthand.
	let orderby = sortBy;
	let order = 'asc';
	if ( sortBy === 'price-desc' ) {
		orderby = 'price';
		order = 'desc';
	} else if ( [ 'popularity', 'rating', 'date' ].includes( sortBy ) ) {
		order = 'desc';
	}

	const { products, loading, error, reload } = useProducts( {
		search,
		categoryId,
		orderby,
		order,
		perPage: 20,
	} );

	const { categories } = useCategories();

	const siteName = window.wpTmaWooConfig?.siteName || 'Shop';

	const handleProductClick = ( product ) => {
		haptic( 'light' );
		navigate( 'product', { productId: product.id, product } );
	};

	return (
		<div className="tma-woo-page tma-woo-shop-page">
			{ /* Header */ }
			<header className="tma-woo-page-header">
				<div className="tma-woo-page-header__avatar">🛒</div>
				<div className="tma-woo-page-header__info">
					<div className="tma-woo-page-header__name">{ siteName }</div>
					<div className="tma-woo-page-header__status">Online Store</div>
				</div>
			</header>

			{ /* Search bar */ }
			<div className="tma-woo-search">
				<span className="tma-woo-search__icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<circle cx="11" cy="11" r="8" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" />
					</svg>
				</span>
				<input
					type="search"
					className="tma-woo-search__input"
					placeholder="Search products…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					aria-label="Search products"
				/>
				{ search && (
					<button className="tma-woo-search__clear" onClick={ () => setSearch( '' ) } aria-label="Clear search">
						✕
					</button>
				) }
			</div>

			{ /* Filters */ }
			<ProductFilters
				categories={ categories }
				activeCategoryId={ categoryId }
				onCategory={ setCategoryId }
				sortBy={ sortBy }
				onSort={ setSortBy }
			/>

			{ /* Product grid */ }
			<div className="tma-woo-shop-page__grid-wrap">
				{ loading ? (
					<LoadingSpinner />
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : products.length === 0 ? (
					<div className="tma-woo-empty">
						<span>🔍</span>
						<p>No products found.</p>
					</div>
				) : (
					<div className="tma-woo-product-grid">
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
