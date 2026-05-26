/**
 * NV oOS Pro SPA — Entry Point
 *
 * Bootstraps the React application into the #wp-mcp-ai-spa-root div
 * in the WordPress admin. Uses hash-based routing for WP compatibility.
 *
 * @since 1.7.0
 */

import { createRoot } from 'react-dom/client';
import App from './App';
import './index.css';

const container = document.getElementById('wp-mcp-ai-spa-root');

if (container) {
	const root = createRoot(container);
	root.render(<App />);
}
