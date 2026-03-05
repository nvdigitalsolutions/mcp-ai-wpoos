/**
 * Workflow Sidebar Component
 *
 * Contains node palette, workflow templates, and saved workflows.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { convertTemplateToWorkflow, getTemplatePreview } from '../utils/templateConverter';

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
const WorkflowSidebar = ( { onLoadTemplate, onLoadWorkflow, onDeleteWorkflow } ) => {
	const [activeTab, setActiveTab] = useState( 'nodes' );
	const [templates, setTemplates] = useState( [] );
	const [savedWorkflows, setSavedWorkflows] = useState( [] );
	const [loadingTemplates, setLoadingTemplates] = useState( false );
	const [loadingWorkflows, setLoadingWorkflows] = useState( false );
	const [confirmDelete, setConfirmDelete] = useState( null );

	/**
	 * Load templates from backend
	 */
	useEffect( () => {
		if ( activeTab === 'templates' && templates.length === 0 ) {
			loadTemplates();
		}
	}, [activeTab] );

	/**
	 * Load saved workflows when that tab is active
	 */
	useEffect( () => {
		if ( activeTab === 'workflows' ) {
			loadSavedWorkflows();
		}
	}, [activeTab] );

	const loadTemplates = async () => {
		setLoadingTemplates( true );
		try {
			const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'wp_mcp_ai_get_workflow_templates',
					nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				} ),
			} );

			const result = await response.json();
			if ( result.success && result.data.templates ) {
				setTemplates( Object.values( result.data.templates ) );
			}
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error loading templates:', error );
		} finally {
			setLoadingTemplates( false );
		}
	};

	const loadSavedWorkflows = async () => {
		// First try the pre-loaded workflows from localized data.
		const preloaded = window.mcpAiWorkflowBuilder?.workflows;
		if ( preloaded && Object.keys( preloaded ).length > 0 ) {
			setSavedWorkflows( Object.values( preloaded ) );
			return;
		}

		setLoadingWorkflows( true );
		try {
			const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'wp_mcp_ai_list_pro_workflows',
					nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				} ),
			} );

			const result = await response.json();
			if ( result.success && result.data.workflows ) {
				setSavedWorkflows( result.data.workflows );
			}
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error loading saved workflows:', error );
		} finally {
			setLoadingWorkflows( false );
		}
	};

	const handleDeleteWorkflow = async ( workflowId ) => {
		if ( onDeleteWorkflow ) {
			await onDeleteWorkflow( workflowId );
		}
		// Refresh the list.
		setSavedWorkflows( ( prev ) => prev.filter( ( w ) => w.id !== workflowId ) );
		setConfirmDelete( null );
	};

	/**
	 * Handle template selection
	 */
	const handleTemplateClick = ( template ) => {
		const workflow = convertTemplateToWorkflow( template );
		onLoadTemplate( {
			name: template.name,
			description: template.description,
			nodes: workflow.nodes,
			edges: workflow.edges,
		} );
	};

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
				<button
					className={`tab-button ${activeTab === 'workflows' ? 'active' : ''}`}
					onClick={() => setActiveTab( 'workflows' )}
				>
					{__( 'Saved', 'mcp-ai-wpoos' )}
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
						{loadingTemplates && (
							<div className="template-placeholder">
								{__( 'Loading templates...', 'mcp-ai-wpoos' )}
							</div>
						)}
						{! loadingTemplates && templates.length === 0 && (
							<div className="template-placeholder">
								{__( 'No templates available', 'mcp-ai-wpoos' )}
							</div>
						)}
						{! loadingTemplates && templates.length > 0 && (
							<div className="template-list">
								{templates.map( ( template, index ) => {
									const preview = getTemplatePreview( template );
									return (
										<div
											key={index}
											className="template-item"
											onClick={() => handleTemplateClick( template )}
										>
											<div className="template-name">{preview.name}</div>
											<div className="template-description">{preview.description}</div>
											<div className="template-meta">
												{preview.stepCount} {__( 'steps', 'mcp-ai-wpoos' )}
											</div>
										</div>
									);
								} )}
							</div>
						)}
					</div>
				)}

				{activeTab === 'workflows' && (
					<div className="saved-workflows">
						<div className="saved-workflows-header">
							<p className="templates-intro">
								{__( 'Click a workflow to load it into the editor', 'mcp-ai-wpoos' )}
							</p>
							<button
								className="refresh-button"
								onClick={loadSavedWorkflows}
								title={__( 'Refresh', 'mcp-ai-wpoos' )}
							>
								🔄
							</button>
						</div>

						{loadingWorkflows && (
							<div className="template-placeholder">
								{__( 'Loading workflows...', 'mcp-ai-wpoos' )}
							</div>
						)}
						{! loadingWorkflows && savedWorkflows.length === 0 && (
							<div className="template-placeholder">
								{__( 'No saved workflows yet. Build one and click Save!', 'mcp-ai-wpoos' )}
							</div>
						)}
						{! loadingWorkflows && savedWorkflows.length > 0 && (
							<div className="workflow-list">
								{savedWorkflows.map( ( workflow ) => (
									<div key={workflow.id} className="workflow-list-item">
										<div
											className="workflow-list-item-info"
											onClick={() => onLoadWorkflow && onLoadWorkflow( workflow )}
										>
											<div className="workflow-list-name">{workflow.name}</div>
											{workflow.description && (
												<div className="workflow-list-description">
													{workflow.description}
												</div>
											)}
											<div className="workflow-list-meta">
												{( workflow.nodes || [] ).length}{' '}
												{__( 'nodes', 'mcp-ai-wpoos' )}
											</div>
										</div>
										<div className="workflow-list-item-actions">
											{confirmDelete === workflow.id ? (
												<>
													<button
														className="workflow-action-button workflow-action-confirm"
														onClick={() => handleDeleteWorkflow( workflow.id )}
														title={__( 'Confirm Delete', 'mcp-ai-wpoos' )}
													>
														✓
													</button>
													<button
														className="workflow-action-button workflow-action-cancel"
														onClick={() => setConfirmDelete( null )}
														title={__( 'Cancel', 'mcp-ai-wpoos' )}
													>
														✕
													</button>
												</>
											) : (
												<button
													className="workflow-action-button workflow-action-delete"
													onClick={() => setConfirmDelete( workflow.id )}
													title={__( 'Delete Workflow', 'mcp-ai-wpoos' )}
												>
													🗑
												</button>
											)}
										</div>
									</div>
								) )}
							</div>
						)}
					</div>
				)}
			</div>
		</div>
	);
};

export default WorkflowSidebar;
