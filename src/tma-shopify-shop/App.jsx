/**
 * TMA Shopify Shop – Root Application Component
 *
 * Manages top-level page state (state-based SPA routing), wraps providers,
 * and renders the active page. Navigation is handled via a shared `nav`
 * object propagated through React Context so any child can change pages.
 *
 * Pages:
 *  shop      → ShopPage     (product catalog with filters)
 *  product   → ProductPage  (product detail, variants, add-to-cart)
 *  cart      → CartPage     (line items, quantities, proceed)
 *  checkout  → CheckoutPage (contact form, order via assistant)
 *  orders    → OrdersPage   (order history)
 *  assistant → AssistantPage (full AI shopping assistant chat)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useCallback, useMemo } from 'react';
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
	shop: ShopPage,
	product: ProductPage,
	cart: CartPage,
	checkout: CheckoutPage,
	orders: OrdersPage,
	assistant: AssistantPage,
};

/**
 * Application root.
 *
 * @return {JSX.Element}
 */
export default function App() {
	const [ route, setRoute ] = useState( { page: 'shop', params: {} } );

	const navigate = useCallback( ( page, params = {} ) => {
		setRoute( { page, params } );

		// Integrate with Telegram BackButton.
		const twa = window.Telegram?.WebApp;
		if ( twa ) {
			if ( page === 'shop' ) {
				twa.BackButton.hide();
			} else {
				twa.BackButton.show();
				twa.BackButton.onClick( () => setRoute( { page: 'shop', params: {} } ) );
			}
		}
	}, [] );

	const navCtx = useMemo( () => ( { route, navigate } ), [ route, navigate ] );

	const PageComponent = PAGES[ route.page ] ?? ShopPage;

	return (
		<TMAProvider>
			<CartProvider>
				<NavContext.Provider value={ navCtx }>
					<div className="tma-shopify-shell">
						<main className="tma-shopify-content">
							<PageComponent params={ route.params } />
						</main>
						<Navigation activePage={ route.page } navigate={ navigate } />
					</div>
				</NavContext.Provider>
			</CartProvider>
		</TMAProvider>
	);
}
