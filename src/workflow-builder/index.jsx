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

// Constants for retry logic
const MAX_INIT_ATTEMPTS = 10;
const INITIAL_DELAY_MS = 50;
const BACKOFF_MULTIPLIER = 1.5;
const MAX_DELAY_MS = 500;

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
	} else if ( attempt < MAX_INIT_ATTEMPTS ) {
		// Container not found yet, retry with exponential backoff
		const delay = Math.min( INITIAL_DELAY_MS * Math.pow( BACKOFF_MULTIPLIER, attempt - 1 ), MAX_DELAY_MS );
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
	requestAnimationFrame( initWorkflowBuilder );
};

// Initialize based on document ready state
if ( document.readyState === 'loading' ) {
	// Document is still loading, wait for DOMContentLoaded
	document.addEventListener( 'DOMContentLoaded', startInit );
} else {
	// DOM is interactive or complete, start initialization
	startInit();
}
