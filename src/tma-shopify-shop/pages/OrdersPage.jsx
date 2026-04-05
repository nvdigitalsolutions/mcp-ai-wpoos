/**
 * OrdersPage – Shopify order history
 *
 * Displays a list of recent orders fetched via the `shopify_orders` tool.
 * Includes status badges, pull-to-refresh, and empty state.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useRef, useCallback } from 'react';
import { useOrders } from '../hooks/useOrders';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** Human-readable order status badge colour. */
const STATUS_COLORS = {
	fulfilled:           '#22c55e',
	unfulfilled:         '#f59e0b',
	partially_fulfilled: '#3b82f6',
	paid:                '#22c55e',
	pending:             '#f59e0b',
	refunded:            '#8b5cf6',
	voided:              '#ef4444',
	authorized:          '#3b82f6',
};

/** @param {{ params:object }} props */
export default function OrdersPage() {
	const { orders, loading, error, reload } = useOrders( { first: 15 } );
	const { haptic } = useTMA();
	const { navigate } = useNav();
	const [ refreshing, setRefreshing ] = useState( false );
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

	return (
		<div className="tma-shopify-page tma-shopify-orders-page">
			<header className="tma-shopify-page-header">
				<div className="tma-shopify-page-header__avatar">📦</div>
				<div className="tma-shopify-page-header__info">
					<div className="tma-shopify-page-header__name">My Orders</div>
					<div className="tma-shopify-page-header__status">Order history</div>
				</div>
			</header>

			{ refreshing && (
				<div className="tma-shopify-pull-indicator">Refreshing…</div>
			) }

			<div
				className="tma-shopify-orders-page__list"
				ref={ scrollRef }
				onTouchStart={ onTouchStart }
				onTouchEnd={ onTouchEnd }
			>
				{ loading ? (
					<LoadingSpinner />
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : orders.length === 0 ? (
					<div className="tma-shopify-empty">
						<span>📦</span>
						<p>No orders yet.</p>
						<button
							className="tma-shopify-btn tma-shopify-btn--primary"
							onClick={ () => {
								haptic( 'light' );
								navigate( 'shop' );
							} }
						>
							Start Shopping
						</button>
					</div>
				) : (
					orders.map( ( order ) => {
						const status   = order.displayFulfillmentStatus ?? order.fulfillmentStatus ?? order.status ?? 'pending';
						const statusLc = status.toLowerCase().replace( / /g, '_' );
						const color    = STATUS_COLORS[ statusLc ] ?? '#6b7280';
						const date     = order.createdAt ?? order.processedAt ?? '';
						const dateStr  = date ? new Date( date ).toLocaleDateString() : '';
						const total    = order.totalPriceSet?.shopMoney?.amount ??
										 order.totalPrice ?? '';
						const currency = order.totalPriceSet?.shopMoney?.currencyCode ?? 'USD';
						const lineItems = order.lineItems?.edges?.map( ( e ) => e.node ) ??
										  order.lineItems?.nodes ?? [];

						return (
							<div key={ order.id } className="tma-shopify-order-card">
								<div className="tma-shopify-order-card__header">
									<span className="tma-shopify-order-card__id">
										{ order.name ?? `Order ${ order.id }` }
									</span>
									<span
										className="tma-shopify-order-card__badge"
										style={ { background: color + '22', color } }
									>
										{ status.replace( /_/g, ' ' ) }
									</span>
								</div>
								<div className="tma-shopify-order-card__meta">
									{ dateStr && <span>{ dateStr }</span> }
									{ total && <span>{ currency } ${ parseFloat( total ).toFixed( 2 ) }</span> }
								</div>
								{ lineItems.length > 0 && (
									<div className="tma-shopify-order-card__items">
										{ lineItems.map( ( li, idx ) => (
											<span key={ idx } className="tma-shopify-order-card__item">
												{ li.title ?? li.name } × { li.quantity ?? li.currentQuantity ?? 1 }
											</span>
										) ) }
									</div>
								) }
							</div>
						);
					} )
				) }
			</div>
		</div>
	);
}
