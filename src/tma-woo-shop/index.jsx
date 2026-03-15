/**
 * TMA WooCommerce Shop – Entry Point
 *
 * Bootstraps the React SPA into the `#tma-woo-shop-root` div injected by the
 * WP_MCP_AI_TMA_Template_Woo_Shop PHP template. Global config is passed
 * through `window.wpTmaWooConfig` (set by PHP before this script loads).
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import './styles/woo-shop.css';

const init = () => {
	const root = document.getElementById( 'tma-woo-shop-root' );
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
