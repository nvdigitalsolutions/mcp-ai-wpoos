/**
 * Workflow Builder - Main Entry Point
 *
 * Modern visual workflow builder using ReactFlow for node-based UI.
 * Implements 2026 industry best practices for AI workflow builders.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { createRoot } from '@wordpress/element';
import WorkflowBuilder from './components/WorkflowBuilder';
import './styles/workflow-builder.css';

/**
 * Initialize the Workflow Builder application
 */
document.addEventListener( 'DOMContentLoaded', () => {
	try {
		const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
		
		if ( ! container ) {
			console.warn( 'Workflow Builder: Root container not found' );
			return;
		}

		// Check if container is already rendered (prevents double initialization)
		if ( container.hasChildNodes() ) {
			console.info( 'Workflow Builder: Already initialized' );
			return;
		}

		const root = createRoot( container );
		root.render( <WorkflowBuilder /> );
		
		console.info( 'Workflow Builder: Initialized successfully' );
	} catch ( error ) {
		// Catch and log any initialization errors, including those from browser extensions
		// or third-party scripts that might interfere with the page load.
		console.error( 'Workflow Builder: Initialization error', error );
		
		// Display a user-friendly error message in the container
		const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
		if ( container ) {
			container.innerHTML = `
				<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin: 20px 0;">
					<h3 style="margin-top: 0; color: #856404;">⚠️ Workflow Builder Initialization Error</h3>
					<p style="color: #856404;">
						The Workflow Builder failed to initialize. This may be caused by browser extensions or add-ons interfering with the page.
					</p>
					<p style="color: #856404; margin-bottom: 0;">
						<strong>Troubleshooting steps:</strong><br>
						• Try disabling browser extensions<br>
						• Clear your browser cache and reload<br>
						• Try using a different browser or incognito mode<br>
						• Check the browser console for more details
					</p>
				</div>
			`;
		}
	}
} );
