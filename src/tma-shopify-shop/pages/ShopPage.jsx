/**
 * ShopPage – Shopify Product Catalog
 *
 * Displays a filterable, searchable grid of Shopify products. Features:
 *  - Debounced search input
 *  - Collection filter pills (horizontal scroll)
 *  - Skeleton card loading state (6 cards)
 *  - Pull-to-refresh (touchstart/touchmove/touchend)
 *  - Empty state with helpful message
 *  - Telegram MainButton integration for cart when items > 0
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { useNav } from '../context/NavContext';
import { useTMA } from '../context/TMAContext';
import { useCart } from '../context/CartContext';
import { useProducts } from '../hooks/useProducts';
import { useCollections } from '../hooks/useCollections';
import ProductCard from '../components/ProductCard';
import CollectionFilter from '../components/CollectionFilter';
import SkeletonCard from '../components/SkeletonCard';
import ErrorMessage from '../components/ErrorMessage';

/** @param {{ params:object }} props */
export default function ShopPage() {
	const { navigate } = useNav();
	const { haptic, twa } = useTMA();
	const { itemCount } = useCart();

	const [ search, setSearch ] = useState( '' );
	const [ collectionId, setCollectionId ] = useState( '' );
	const [ refreshing, setRefreshing ] = useState( false );

	const { products, loading, error, reload } = useProducts( {
		search,
		first: 20,
	} );

	const { collections } = useCollections();

	const siteName = window.wpTmaShopifyConfig?.siteName || 'Shop';

	const handleProductClick = ( product ) => {
		haptic( 'light' );
		navigate( 'product', { productId: product.id, product } );
	};

	// ── Telegram MainButton: go to cart when items exist ──────────────────
	useEffect( () => {
		if ( ! twa?.MainButton ) {
			return;
		}
		if ( itemCount > 0 ) {
			twa.MainButton.setText( `View Cart (${ itemCount })` );
			twa.MainButton.show();
			const handler = () => navigate( 'cart' );
			twa.MainButton.onClick( handler );
			return () => {
				twa.MainButton.offClick( handler );
				twa.MainButton.hide();
			};
		}
		twa.MainButton.hide();
	}, [ twa, itemCount, navigate ] );

	// ── Pull-to-refresh ──────────────────────────────────────────────────
	const scrollRef = useRef( null );
	const touchStartY = useRef( 0 );

	const onTouchStart = useCallback( ( e ) => {
		if ( scrollRef.current && scrollRef.current.scrollTop === 0 ) {
			touchStartY.current = e.touches[ 0 ].clientY;
		}
	}, [] );

	const onTouchEnd = useCallback( ( e ) => {
		const diff = e.changedTouches[ 0 ].clientY - touchStartY.current;
		if ( diff > 80 && scrollRef.current && scrollRef.current.scrollTop === 0 ) {
			setRefreshing( true );
			reload();
			setTimeout( () => setRefreshing( false ), 800 );
		}
		touchStartY.current = 0;
	}, [ reload ] );

	// Filter products by collection (client-side, since Shopify tool may
	// not support collection filter directly).
	const filtered = collectionId
		? products.filter( ( p ) => {
			const cols = p.collections?.edges?.map( ( e ) => e.node?.id ) ??
						p.collections?.nodes?.map( ( n ) => n.id ) ?? [];
			return cols.includes( collectionId );
		} )
		: products;

	return (
		<div className="tma-shopify-page tma-shopify-shop-page">
			{ /* Header */ }
			<header className="tma-shopify-page-header">
				<div className="tma-shopify-page-header__avatar">🏪</div>
				<div className="tma-shopify-page-header__info">
					<div className="tma-shopify-page-header__name">{ siteName }</div>
					<div className="tma-shopify-page-header__status">Shopify Store</div>
				</div>
			</header>

			{ /* Search bar */ }
			<div className="tma-shopify-search">
				<span className="tma-shopify-search__icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<circle cx="11" cy="11" r="8" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" />
					</svg>
				</span>
				<input
					type="search"
					className="tma-shopify-search__input"
					placeholder="Search products…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					aria-label="Search products"
				/>
				{ search && (
					<button className="tma-shopify-search__clear" onClick={ () => setSearch( '' ) } aria-label="Clear search">
						✕
					</button>
				) }
			</div>

			{ /* Collection filters */ }
			{ collections.length > 0 && (
				<CollectionFilter
					collections={ collections }
					activeId={ collectionId }
					onSelect={ setCollectionId }
				/>
			) }

			{ /* Pull-to-refresh indicator */ }
			{ refreshing && (
				<div className="tma-shopify-pull-indicator">Refreshing…</div>
			) }

			{ /* Product grid */ }
			<div
				className="tma-shopify-shop-page__grid-wrap"
				ref={ scrollRef }
				onTouchStart={ onTouchStart }
				onTouchEnd={ onTouchEnd }
			>
				{ loading ? (
					<div className="tma-shopify-product-grid">
						{ [ ...Array( 6 ) ].map( ( _, i ) => (
							<SkeletonCard key={ i } />
						) ) }
					</div>
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : filtered.length === 0 ? (
					<div className="tma-shopify-empty">
						<span>🔍</span>
						<p>No products found. Try a different search or collection.</p>
					</div>
				) : (
					<div className="tma-shopify-product-grid">
						{ filtered.map( ( product ) => (
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
