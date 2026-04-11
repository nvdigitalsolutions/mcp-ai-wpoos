/**
 * TMA Shopify Jewelry Shop – Entry Point
 *
 * Bootstraps the React SPA into the `#tma-shopify-jewelry-root` div injected
 * by the WP_MCP_AI_TMA_Template_Jewelry_Shop PHP template. Global config is
 * passed through `window.wpTmaJewelryConfig` (set by PHP before this script).
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/jewelry-shop.css';

const init = () => {
	const root = document.getElementById( 'tma-shopify-jewelry-root' );
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
