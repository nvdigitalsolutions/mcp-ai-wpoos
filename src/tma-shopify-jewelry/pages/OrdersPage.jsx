/**
 * OrdersPage – Shopify order history
 *
 * Displays a list of recent orders fetched via the `shopify_orders` tool.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useOrders } from '../hooks/useOrders';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** Shopify financial status → badge colour. */
const STATUS_COLORS = {
	paid:       '#22c55e',
	partially_paid: '#3b82f6',
	pending:    '#f59e0b',
	refunded:   '#8b5cf6',
	voided:     '#ef4444',
	authorized: '#3b82f6',
};

/** Fulfillment status → badge colour. */
const FULFILLMENT_COLORS = {
	fulfilled:          '#22c55e',
	partially_fulfilled: '#3b82f6',
	unfulfilled:        '#f59e0b',
	restocked:          '#8b5cf6',
};

/** @param {{ params:object }} props */
export default function OrdersPage() {
	const { orders, loading, error, reload } = useOrders( { first: 15 } );
	const { haptic }   = useTMA();
	const { navigate } = useNav();

	return (
		<div className="tma-jw-page tma-jw-orders-page">
			<header className="tma-jw-page-header">
				<div className="tma-jw-page-header__avatar">📦</div>
				<div className="tma-jw-page-header__info">
					<div className="tma-jw-page-header__name">My Orders</div>
					<div className="tma-jw-page-header__status">Order history</div>
				</div>
			</header>

			<div className="tma-jw-orders-page__list">
				{ loading ? (
					<LoadingSpinner />
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : orders.length === 0 ? (
					<div className="tma-jw-empty">
						<span>📦</span>
						<p>No orders yet.</p>
						<button
							className="tma-jw-btn tma-jw-btn--primary"
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
						const finStatus   = ( order.displayFinancialStatus ?? order.financialStatus ?? '' ).toLowerCase().replace( / /g, '_' );
						const fulfillStatus = ( order.displayFulfillmentStatus ?? order.fulfillmentStatus ?? '' ).toLowerCase().replace( / /g, '_' );
						const finColor    = STATUS_COLORS[ finStatus ] ?? '#6b7280';
						const fulfillColor = FULFILLMENT_COLORS[ fulfillStatus ] ?? '#6b7280';
						const date        = order.processedAt ?? order.createdAt ?? '';
						const dateLabel   = date ? new Date( date ).toLocaleDateString() : '';
						const total       = order.totalPriceSet?.shopMoney?.amount ?? order.currentTotalPrice ?? '';
						const currency    = order.totalPriceSet?.shopMoney?.currencyCode ?? 'USD';
						const totalLabel  = total
							? new Intl.NumberFormat( 'en-US', { style: 'currency', currency } ).format( parseFloat( total ) )
							: '';

						return (
							<div key={ order.id } className="tma-jw-order-card">
								<div className="tma-jw-order-card__header">
									<span className="tma-jw-order-card__id">
										{ order.name ?? `Order #${ order.id }` }
									</span>
									{ finStatus && (
										<span
											className="tma-jw-order-card__badge"
											style={ { background: finColor + '22', color: finColor } }
										>
											{ order.displayFinancialStatus ?? finStatus }
										</span>
									) }
								</div>
								<div className="tma-jw-order-card__meta">
									{ dateLabel && <span>{ dateLabel }</span> }
									{ totalLabel && <span>{ totalLabel }</span> }
									{ fulfillStatus && (
										<span
											className="tma-jw-order-card__badge tma-jw-order-card__badge--sm"
											style={ { background: fulfillColor + '22', color: fulfillColor } }
										>
											{ order.displayFulfillmentStatus ?? fulfillStatus }
										</span>
									) }
								</div>
								{ order.lineItems?.edges?.length > 0 && (
									<div className="tma-jw-order-card__items">
										{ order.lineItems.edges.map( ( { node }, idx ) => (
											<span key={ idx } className="tma-jw-order-card__item">
												{ node.title } × { node.quantity }
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
