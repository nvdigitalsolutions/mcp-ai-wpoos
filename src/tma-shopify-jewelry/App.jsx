/**
 * TMA Shopify Jewelry Shop – Root Application Component
 *
 * Manages top-level page state (state-based SPA routing), wraps providers,
 * and renders the active page.
 *
 * Pages:
 *  shop      → ShopPage     (product catalog with search/filter)
 *  product   → ProductPage  (product detail, add-to-cart)
 *  cart      → CartPage     (line items, quantities, proceed)
 *  checkout  → CheckoutPage (contact form, place order)
 *  orders    → OrdersPage   (order history)
 *  assistant → AssistantPage (AI jewelry assistant chat)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useCallback, useEffect, useRef, useMemo } from '@wordpress/element';
import { CartProvider } from './context/CartContext';
import { TMAProvider } from './context/TMAContext';
import { NavContext } from './context/NavContext';
import ShopPage from './pages/ShopPage';
import ProductPage from './pages/ProductPage';
import CartPage from './pages/CartPage';
import CheckoutPage from './pages/CheckoutPage';
import OrdersPage from './pages/OrdersPage';
import AssistantPage from './pages/AssistantPage';
import Navigation from './components/Navigation';

/** @type {Record<string,React.ComponentType<any>>} */
const PAGES = {
	shop:      ShopPage,
	product:   ProductPage,
	cart:      CartPage,
	checkout:  CheckoutPage,
	orders:    OrdersPage,
	assistant: AssistantPage,
};

/**
 * Application root.
 *
 * @return {JSX.Element}
 */
export default function App() {
	const [ route, setRoute ] = useState( { page: 'shop', params: {} } );

	// Keep a stable ref to the back-button handler so we can remove it before
	// registering a new one and avoid handler accumulation.
	const backHandlerRef = useRef( null );

	const navigate = useCallback( ( page, params = {} ) => {
		setRoute( { page, params } );
	}, [] );

	// Sync Telegram BackButton with the current route.
	useEffect( () => {
		const twa = window.Telegram?.WebApp;
		if ( ! twa ) {
			return;
		}

		// Remove the previous handler before registering the new one.
		if ( backHandlerRef.current ) {
			twa.BackButton.offClick( backHandlerRef.current );
		}

		if ( route.page === 'shop' ) {
			twa.BackButton.hide();
			backHandlerRef.current = null;
		} else {
			const handler = () => setRoute( { page: 'shop', params: {} } );
			backHandlerRef.current = handler;
			twa.BackButton.show();
			twa.BackButton.onClick( handler );
		}
	}, [ route.page ] );

	const navCtx = useMemo( () => ( { route, navigate } ), [ route, navigate ] );

	const PageComponent = PAGES[ route.page ] ?? ShopPage;

	return (
		<TMAProvider>
			<CartProvider>
				<NavContext.Provider value={ navCtx }>
					<div className="tma-jw-shell">
						<main className="tma-jw-content">
							<PageComponent params={ route.params } />
						</main>
						<Navigation activePage={ route.page } navigate={ navigate } />
					</div>
				</NavContext.Provider>
			</CartProvider>
		</TMAProvider>
	);
}
