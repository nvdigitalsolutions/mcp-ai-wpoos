/**
 * Workflow Builder Component
 *
 * Main workflow builder interface using ReactFlow for visual node-based editing.
 * Follows industry best practices from n8n, Zapier, and other modern workflow tools.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ReactFlow, {
	Background,
	Controls,
	MiniMap,
	addEdge,
	useNodesState,
	useEdgesState,
	Panel,
	ReactFlowProvider,
} from 'reactflow';
import 'reactflow/dist/style.css';

import WorkflowSidebar from './WorkflowSidebar';
import WorkflowToolbar from './WorkflowToolbar';
import WorkflowPropertiesPanel from './WorkflowPropertiesPanel';
import nodeTypes from '../nodes';
import { generateNodeId, validateWorkflow } from '../utils/workflowHelpers';

/**
 * Workflow Builder Component
 */
const WorkflowBuilderInner = () => {
	const [nodes, setNodes, onNodesChange] = useNodesState( [] );
	const [edges, setEdges, onEdgesChange] = useEdgesState( [] );
	const [selectedNode, setSelectedNode] = useState( null );
	const [workflowName, setWorkflowName] = useState( __( 'Untitled Workflow', 'mcp-ai-wpoos' ) );
	const [workflowDescription, setWorkflowDescription] = useState( '' );
	const [isSaving, setIsSaving] = useState( false );
	const [validationErrors, setValidationErrors] = useState( [] );
	const reactFlowWrapper = useRef( null );
	const [reactFlowInstance, setReactFlowInstance] = useState( null );

	/**
	 * Handle new connection between nodes
	 */
	const onConnect = useCallback(
		( params ) => setEdges( ( eds ) => addEdge( { ...params, animated: true }, eds ) ),
		[setEdges]
	);

	/**
	 * Handle node selection
	 */
	const onNodeClick = useCallback( ( event, node ) => {
		setSelectedNode( node );
	}, [] );

	/**
	 * Handle canvas click (deselect)
	 */
	const onPaneClick = useCallback( () => {
		setSelectedNode( null );
	}, [] );

	/**
	 * Handle drag and drop of new nodes from sidebar
	 */
	const onDragOver = useCallback( ( event ) => {
		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';
	}, [] );

	/**
	 * Handle drop of new node onto canvas
	 */
	const onDrop = useCallback(
		( event ) => {
			event.preventDefault();

			const type = event.dataTransfer.getData( 'application/reactflow' );
			if ( typeof type === 'undefined' || ! type ) {
				return;
			}

			const position = reactFlowInstance.screenToFlowPosition( {
				x: event.clientX,
				y: event.clientY,
			} );

			const newNode = {
				id: generateNodeId( type ),
				type,
				position,
				data: {
					label: type.charAt( 0 ).toUpperCase() + type.slice( 1 ),
					config: {},
				},
			};

			setNodes( ( nds ) => nds.concat( newNode ) );
		},
		[reactFlowInstance, setNodes]
	);

	/**
	 * Update node data when properties are changed
	 */
	const updateNodeData = useCallback(
		( nodeId, newData ) => {
			setNodes( ( nds ) =>
				nds.map( ( node ) => {
					if ( node.id === nodeId ) {
						return {
							...node,
							data: { ...node.data, ...newData },
						};
					}
					return node;
				} )
			);

			// Update selected node if it's the one being edited
			if ( selectedNode && selectedNode.id === nodeId ) {
				setSelectedNode( ( current ) => ( {
					...current,
					data: { ...current.data, ...newData },
				} ) );
			}
		},
		[setNodes, selectedNode]
	);

	/**
	 * Delete selected node
	 */
	const deleteNode = useCallback(
		( nodeId ) => {
			setNodes( ( nds ) => nds.filter( ( node ) => node.id !== nodeId ) );
			setEdges( ( eds ) => eds.filter( ( edge ) => edge.source !== nodeId && edge.target !== nodeId ) );
			setSelectedNode( null );
		},
		[setNodes, setEdges]
	);

	/**
	 * Save workflow
	 */
	const saveWorkflow = useCallback( async () => {
		setIsSaving( true );
		
		// Validate workflow
		const errors = validateWorkflow( nodes, edges );
		if ( errors.length > 0 ) {
			setValidationErrors( errors );
			setIsSaving( false );
			return;
		}

		try {
			const workflowData = {
				name: workflowName,
				description: workflowDescription,
				nodes: nodes.map( ( node ) => ( {
					id: node.id,
					type: node.type,
					position: node.position,
					data: node.data,
				} ) ),
				edges: edges.map( ( edge ) => ( {
					id: edge.id,
					source: edge.source,
					target: edge.target,
					sourceHandle: edge.sourceHandle,
					targetHandle: edge.targetHandle,
				} ) ),
			};

			const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'wp_mcp_ai_save_pro_workflow',
					nonce: window.mcpAiWorkflowBuilder?.nonce || '',
					workflow: JSON.stringify( workflowData ),
				} ),
			} );

			const result = await response.json();
			
			if ( result.success ) {
				// Show success message
				// eslint-disable-next-line no-console
				console.log( __( 'Workflow saved successfully', 'mcp-ai-wpoos' ) );
				setValidationErrors( [] );
			} else {
				throw new Error( result.data?.message || __( 'Failed to save workflow', 'mcp-ai-wpoos' ) );
			}
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error saving workflow:', error );
			setValidationErrors( [ error.message ] );
		} finally {
			setIsSaving( false );
		}
	}, [workflowName, workflowDescription, nodes, edges] );

	/**
	 * Load workflow from template
	 */
	const loadTemplate = useCallback( ( template ) => {
		setWorkflowName( template.name );
		setWorkflowDescription( template.description );
		setNodes( template.nodes || [] );
		setEdges( template.edges || [] );
		setValidationErrors( [] );
	}, [setNodes, setEdges] );

	return (
		<div className="workflow-builder-container">
			<WorkflowToolbar
				workflowName={workflowName}
				onNameChange={setWorkflowName}
				onSave={saveWorkflow}
				isSaving={isSaving}
				validationErrors={validationErrors}
			/>
			
			<div className="workflow-builder-main">
				<WorkflowSidebar onLoadTemplate={loadTemplate} />
				
				<div className="workflow-canvas-wrapper" ref={reactFlowWrapper}>
					<ReactFlow
						nodes={nodes}
						edges={edges}
						onNodesChange={onNodesChange}
						onEdgesChange={onEdgesChange}
						onConnect={onConnect}
						onNodeClick={onNodeClick}
						onPaneClick={onPaneClick}
						onDrop={onDrop}
						onDragOver={onDragOver}
						onInit={setReactFlowInstance}
						nodeTypes={nodeTypes}
						fitView
						attributionPosition="bottom-right"
					>
						<Background />
						<Controls />
						<MiniMap
							nodeColor={( node ) => {
								switch ( node.type ) {
									case 'trigger': return '#10b981';
									case 'action': return '#3b82f6';
									case 'condition': return '#f59e0b';
									case 'loop': return '#8b5cf6';
									case 'parallel': return '#ec4899';
									default: return '#6b7280';
								}
							}}
						/>
						<Panel position="top-right">
							{validationErrors.length > 0 && (
								<div className="workflow-validation-errors">
									{validationErrors.map( ( error, index ) => (
										<div key={index} className="error-message">
											{error}
										</div>
									) )}
								</div>
							)}
						</Panel>
					</ReactFlow>
				</div>

				{selectedNode && (
					<WorkflowPropertiesPanel
						node={selectedNode}
						onUpdateNode={updateNodeData}
						onDeleteNode={deleteNode}
						onClose={() => setSelectedNode( null )}
					/>
				)}
			</div>
		</div>
	);
};

/**
 * Workflow Builder with Provider
 */
const WorkflowBuilder = () => {
	return (
		<ReactFlowProvider>
			<WorkflowBuilderInner />
		</ReactFlowProvider>
	);
};

export default WorkflowBuilder;
