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
 * 
 * @param {number} attempt - Current attempt number for retry logic
 */
const initWorkflowBuilder = ( attempt = 1 ) => {
	const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
	
	if ( container ) {
		const root = createRoot( container );
		root.render( <WorkflowBuilder /> );
	} else if ( attempt < 10 ) {
		// Container not found yet, retry with exponential backoff (max 10 attempts)
		const delay = Math.min( 50 * Math.pow( 1.5, attempt - 1 ), 500 );
		setTimeout( () => initWorkflowBuilder( attempt + 1 ), delay );
	} else {
		// eslint-disable-next-line no-console
		console.error( 'Workflow Builder: Failed to find container element after multiple attempts' );
	}
};

/**
 * Start the initialization process based on document ready state
 */
const startInit = () => {
	// Use requestAnimationFrame to defer execution to next browser paint
	// This ensures the DOM is fully ready and all elements are accessible
	if ( typeof requestAnimationFrame !== 'undefined' ) {
		requestAnimationFrame( () => {
			// Additional timeout to ensure DOM elements are fully rendered
			setTimeout( initWorkflowBuilder, 0 );
		} );
	} else {
		// Fallback for older browsers
		setTimeout( initWorkflowBuilder, 0 );
	}
};

// Initialize based on document ready state
if ( document.readyState === 'loading' ) {
	// Document is still loading, wait for DOMContentLoaded
	document.addEventListener( 'DOMContentLoaded', startInit );
} else {
	// DOM is interactive or complete, start initialization
	startInit();
}
