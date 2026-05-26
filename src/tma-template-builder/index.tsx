/**
 * TMA Template Builder – Main Entry Point (TypeScript)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { createRoot } from 'react-dom/client';
import { TMATemplateBuilder } from './components/TMATemplateBuilder';
import type { TemplateBuilderConfig } from '../shared/types';
import './styles/tma-template-builder.css';

const init = (): void => {
	const root = document.getElementById( 'mcp-ai-tma-template-builder-root' );
	if ( ! root ) { return; }

	const config: TemplateBuilderConfig = {
		ajaxUrl: root.dataset.ajaxUrl || '',
		nonce: root.dataset.nonce || '',
		templatesUrl: root.dataset.templatesUrl || '',
		saveUrl: root.dataset.saveUrl || '',
		activeTemplate: root.dataset.activeTemplate || 'default',
		previewBaseUrl: root.dataset.previewBaseUrl || '',
		customizeUrl: root.dataset.customizeUrl || '',
	};

	createRoot( root ).render(
		<TMATemplateBuilder config={ config } />
	);
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
