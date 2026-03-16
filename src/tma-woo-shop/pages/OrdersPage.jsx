/**
 * OrdersPage – Order history
 *
 * Displays a list of recent orders fetched via `wooFetch()`.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useOrders } from '../hooks/useOrders';
import { useTMA } from '../context/TMAContext';
import { useNav } from '../context/NavContext';
import LoadingSpinner from '../components/LoadingSpinner';
import ErrorMessage from '../components/ErrorMessage';

/** Human-readable order status badge colour. */
const STATUS_COLORS = {
	completed:  '#22c55e',
	processing: '#3b82f6',
	'on-hold':  '#f59e0b',
	pending:    '#f59e0b',
	cancelled:  '#ef4444',
	refunded:   '#8b5cf6',
	failed:     '#ef4444',
};

/** @param {{ params:object }} props */
export default function OrdersPage() {
	const { orders, loading, error, reload } = useOrders( { perPage: 15 } );
	const { haptic } = useTMA();
	const { navigate } = useNav();

	return (
		<div className="tma-woo-page tma-woo-orders-page">
			<header className="tma-woo-page-header">
				<div className="tma-woo-page-header__avatar">📦</div>
				<div className="tma-woo-page-header__info">
					<div className="tma-woo-page-header__name">My Orders</div>
					<div className="tma-woo-page-header__status">Order history</div>
				</div>
			</header>

			<div className="tma-woo-orders-page__list">
				{ loading ? (
					<LoadingSpinner />
				) : error ? (
					<ErrorMessage message={ error } onRetry={ reload } />
				) : orders.length === 0 ? (
					<div className="tma-woo-empty">
						<span>📦</span>
						<p>No orders yet.</p>
						<button
							className="tma-woo-btn tma-woo-btn--primary"
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
						const status = order.status ?? 'pending';
						const color  = STATUS_COLORS[ status ] ?? '#6b7280';
						const date   = order.date_created
							? new Date( order.date_created ).toLocaleDateString()
							: '';
						const total  = order.total
							? `$${ parseFloat( order.total ).toFixed( 2 ) }`
							: order.totals?.total_price
							? `$${ ( order.totals.total_price / 100 ).toFixed( 2 ) }`
							: '';

						return (
							<div key={ order.id } className="tma-woo-order-card">
								<div className="tma-woo-order-card__header">
									<span className="tma-woo-order-card__id">Order #{ order.id }</span>
									<span
										className="tma-woo-order-card__badge"
										style={ { background: color + '22', color } }
									>
										{ status }
									</span>
								</div>
								<div className="tma-woo-order-card__meta">
									{ date && <span>{ date }</span> }
									{ total && <span>{ total }</span> }
								</div>
								{ order.line_items?.length > 0 && (
									<div className="tma-woo-order-card__items">
										{ order.line_items.map( ( li, idx ) => (
											<span key={ idx } className="tma-woo-order-card__item">
												{ li.name } × { li.quantity }
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
