/**
 * TMA Shopify Shop – Entry Point
 *
 * Bootstraps the React SPA into the `#tma-shopify-shop-root` div injected by
 * the WP_MCP_AI_TMA_Template_Shopify_Shop PHP template. Global config is
 * passed through `window.wpTmaShopifyConfig` (set by PHP before this script
 * loads).
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/shopify-shop.css';

const init = () => {
	const root = document.getElementById( 'tma-shopify-shop-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <App /> );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
