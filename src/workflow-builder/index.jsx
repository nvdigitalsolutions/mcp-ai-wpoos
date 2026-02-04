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
	const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
	
	if ( container ) {
		const root = createRoot( container );
		root.render( <WorkflowBuilder /> );
	}
} );
