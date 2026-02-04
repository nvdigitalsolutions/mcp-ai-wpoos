/**
 * Workflow Sidebar Component
 *
 * Contains node palette and workflow templates.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Node types available for drag and drop
 */
const nodeCategories = {
	triggers: [
		{ type: 'trigger', label: __( 'Trigger', 'mcp-ai-wpoos' ), icon: '⚡', description: __( 'Start workflow', 'mcp-ai-wpoos' ) },
	],
	actions: [
		{ type: 'action', label: __( 'Action', 'mcp-ai-wpoos' ), icon: '▶', description: __( 'Execute command', 'mcp-ai-wpoos' ) },
		{ type: 'tool', label: __( 'Tool', 'mcp-ai-wpoos' ), icon: '🔧', description: __( 'Use MCP tool', 'mcp-ai-wpoos' ) },
		{ type: 'agent', label: __( 'Agent', 'mcp-ai-wpoos' ), icon: '🤖', description: __( 'Call AI agent', 'mcp-ai-wpoos' ) },
	],
	logic: [
		{ type: 'condition', label: __( 'Condition', 'mcp-ai-wpoos' ), icon: '◆', description: __( 'Branch logic', 'mcp-ai-wpoos' ) },
		{ type: 'loop', label: __( 'Loop', 'mcp-ai-wpoos' ), icon: '🔄', description: __( 'Iterate items', 'mcp-ai-wpoos' ) },
		{ type: 'parallel', label: __( 'Parallel', 'mcp-ai-wpoos' ), icon: '⇉', description: __( 'Run in parallel', 'mcp-ai-wpoos' ) },
	],
	control: [
		{ type: 'delay', label: __( 'Delay', 'mcp-ai-wpoos' ), icon: '⏱', description: __( 'Wait time', 'mcp-ai-wpoos' ) },
		{ type: 'approval', label: __( 'Approval', 'mcp-ai-wpoos' ), icon: '✓', description: __( 'Human approval', 'mcp-ai-wpoos' ) },
		{ type: 'merge', label: __( 'Merge', 'mcp-ai-wpoos' ), icon: '⊕', description: __( 'Combine outputs', 'mcp-ai-wpoos' ) },
	],
};

/**
 * Workflow Sidebar Component
 */
const WorkflowSidebar = ( { onLoadTemplate } ) => {
	const [activeTab, setActiveTab] = useState( 'nodes' );

	/**
	 * Handle drag start for node
	 */
	const onDragStart = ( event, nodeType ) => {
		event.dataTransfer.setData( 'application/reactflow', nodeType );
		event.dataTransfer.effectAllowed = 'move';
	};

	return (
		<div className="workflow-sidebar">
			<div className="workflow-sidebar-tabs">
				<button
					className={`tab-button ${activeTab === 'nodes' ? 'active' : ''}`}
					onClick={() => setActiveTab( 'nodes' )}
				>
					{__( 'Nodes', 'mcp-ai-wpoos' )}
				</button>
				<button
					className={`tab-button ${activeTab === 'templates' ? 'active' : ''}`}
					onClick={() => setActiveTab( 'templates' )}
				>
					{__( 'Templates', 'mcp-ai-wpoos' )}
				</button>
			</div>

			<div className="workflow-sidebar-content">
				{activeTab === 'nodes' && (
					<div className="node-palette">
						{Object.entries( nodeCategories ).map( ( [category, nodes] ) => (
							<div key={category} className="node-category">
								<h3 className="category-title">
									{category.charAt( 0 ).toUpperCase() + category.slice( 1 )}
								</h3>
								<div className="node-list">
									{nodes.map( ( node ) => (
										<div
											key={node.type}
											className="node-item"
											draggable
											onDragStart={( e ) => onDragStart( e, node.type )}
										>
											<span className="node-icon">{node.icon}</span>
											<div className="node-info">
												<div className="node-label">{node.label}</div>
												<div className="node-description">{node.description}</div>
											</div>
										</div>
									) )}
								</div>
							</div>
						) )}
					</div>
				)}

				{activeTab === 'templates' && (
					<div className="workflow-templates">
						<p className="templates-intro">
							{__( 'Start from a pre-built workflow template', 'mcp-ai-wpoos' )}
						</p>
						{/* Template list will be populated from backend */}
						<div className="template-placeholder">
							{__( 'Loading templates...', 'mcp-ai-wpoos' )}
						</div>
					</div>
				)}
			</div>
		</div>
	);
};

export default WorkflowSidebar;
