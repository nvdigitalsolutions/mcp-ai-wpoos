/**
 * Workflow Builder Component
 *
 * Main workflow builder interface using ReactFlow for visual node-based editing.
 * Follows industry best practices from n8n, Zapier, and other modern workflow tools.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
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
import ExecutionControls from './ExecutionControls';
import ExecutionHistoryPanel from './ExecutionHistoryPanel';
import MetricsDashboard from './MetricsDashboard';
import nodeTypes from '../nodes';
import { exportWorkflow, importWorkflow, generateNodeId, validateWorkflow } from '../utils/workflowHelpers';
import { WorkflowHistory, debounce } from '../utils/workflowHistory';
import { createVersion, saveVersionToLocal, getVersionsFromLocal } from '../utils/workflowVersioning';
import { WorkflowExecutor, ExecutionStatus } from '../utils/workflowExecutor';
import { saveExecutionHistory } from '../utils/executionHistory';

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
	
	// Phase 2: History management for undo/redo
	const historyManager = useRef( new WorkflowHistory() );
	const [canUndo, setCanUndo] = useState( false );
	const [canRedo, setCanRedo] = useState( false );

	// Phase 3: Execution management
	const [isExecuting, setIsExecuting] = useState( false );
	const [isPaused, setIsPaused] = useState( false );
	const [debugMode, setDebugMode] = useState( false );
	const [showHistory, setShowHistory] = useState( false );
	const [showMetrics, setShowMetrics] = useState( false );
	const [executionState, setExecutionState] = useState( null );
	const executorRef = useRef( null );

	/**
	 * Update history when nodes or edges change
	 */
	const updateHistory = useRef(
		debounce( ( nodes, edges ) => {
			historyManager.current.push( { nodes, edges } );
			updateHistoryButtons();
		}, 500 )
	);

	useEffect( () => {
		if ( nodes.length > 0 || edges.length > 0 ) {
			updateHistory.current( nodes, edges );
		}
	}, [nodes, edges] );

	/**
	 * Update undo/redo button states
	 */
	const updateHistoryButtons = () => {
		setCanUndo( historyManager.current.canUndo() );
		setCanRedo( historyManager.current.canRedo() );
	};

	/**
	 * Handle undo
	 */
	const handleUndo = useCallback( () => {
		const state = historyManager.current.undo();
		if ( state ) {
			setNodes( state.nodes );
			setEdges( state.edges );
			updateHistoryButtons();
		}
	}, [setNodes, setEdges] );

	/**
	 * Handle redo
	 */
	const handleRedo = useCallback( () => {
		const state = historyManager.current.redo();
		if ( state ) {
			setNodes( state.nodes );
			setEdges( state.edges );
			updateHistoryButtons();
		}
	}, [setNodes, setEdges] );

	/**
	 * Handle keyboard shortcuts
	 */
	useEffect( () => {
		const handleKeyDown = ( event ) => {
			// Undo: Ctrl+Z or Cmd+Z
			if ( ( event.ctrlKey || event.metaKey ) && event.key === 'z' && ! event.shiftKey ) {
				event.preventDefault();
				handleUndo();
			}
			// Redo: Ctrl+Y or Cmd+Shift+Z
			if (
				( event.ctrlKey || event.metaKey ) &&
				( event.key === 'y' || ( event.key === 'z' && event.shiftKey ) )
			) {
				event.preventDefault();
				handleRedo();
			}
		};

		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [handleUndo, handleRedo] );

	/**
	 * Save workflow version
	 */
	const handleSaveVersion = useCallback( () => {
		const version = createVersion( {
			name: workflowName,
			description: workflowDescription,
			nodes,
			edges,
		}, __( 'Manual save', 'mcp-ai-wpoos' ) );

		const workflowId = workflowName.toLowerCase().replace( /\s+/g, '-' );
		if ( saveVersionToLocal( workflowId, version ) ) {
			// eslint-disable-next-line no-console
			console.log( __( 'Version saved successfully', 'mcp-ai-wpoos' ) );
		}
	}, [workflowName, workflowDescription, nodes, edges] );

	/**
	 * Execute workflow
	 */
	const handleExecute = useCallback( async () => {
		// Validate first
		const errors = validateWorkflow( nodes, edges );
		if ( errors.length > 0 ) {
			setValidationErrors( errors );
			return;
		}

		setIsExecuting( true );
		setIsPaused( false );

		const workflow = {
			nodes,
			edges,
		};

		const executor = new WorkflowExecutor( workflow, {
			debugMode,
			maxRetries: 2,
			timeout: 600000,
		} );

		executorRef.current = executor;

		// Listen to execution events
		executor.on( 'onNodeStart', ( { node } ) => {
			// Highlight current node
			setNodes( ( nds ) =>
				nds.map( ( n ) =>
					n.id === node.id
						? { ...n, data: { ...n.data, isExecuting: true } }
						: { ...n, data: { ...n.data, isExecuting: false } }
				)
			);
		} );

		executor.on( 'onNodeComplete', ( { node, state } ) => {
			setNodes( ( nds ) =>
				nds.map( ( n ) =>
					n.id === node.id
						? { ...n, data: { ...n.data, isExecuting: false, executionStatus: state.status } }
						: n
				)
			);
		} );

		executor.on( 'onExecutionComplete', ( result ) => {
			setIsExecuting( false );
			setExecutionState( executor.getState() );

			// Save to history
			const workflowId = workflowName.toLowerCase().replace( /\s+/g, '-' );
			saveExecutionHistory( workflowId, executor.getState() );
		} );

		executor.on( 'onExecutionError', ( { error, state } ) => {
			setIsExecuting( false );
			setExecutionState( state );
			setValidationErrors( [ error ] );

			// Save to history
			const workflowId = workflowName.toLowerCase().replace( /\s+/g, '-' );
			saveExecutionHistory( workflowId, state );
		} );

		// Start execution
		await executor.execute();
	}, [nodes, edges, workflowName, debugMode, setNodes] );

	/**
	 * Pause execution
	 */
	const handlePause = useCallback( () => {
		if ( executorRef.current ) {
			executorRef.current.pause();
			setIsPaused( true );
		}
	}, [] );

	/**
	 * Resume execution
	 */
	const handleResume = useCallback( () => {
		if ( executorRef.current ) {
			executorRef.current.resume();
			setIsPaused( false );
		}
	}, [] );

	/**
	 * Stop execution
	 */
	const handleStop = useCallback( () => {
		if ( executorRef.current ) {
			executorRef.current.cancel();
			setIsExecuting( false );
			setIsPaused( false );
		}
	}, [] );

	/**
	 * Toggle debug mode
	 */
	const handleDebugToggle = useCallback( () => {
		setDebugMode( ( prev ) => ! prev );
	}, [] );

	/**
	 * Validate and test workflow without executing
	 */
	const handleTest = useCallback( () => {
		const errors = validateWorkflow( nodes, edges );
		setValidationErrors( errors );
		if ( errors.length === 0 ) {
			// eslint-disable-next-line no-console
			console.log( __( 'Workflow validation passed', 'mcp-ai-wpoos' ) );
		}
	}, [nodes, edges] );

	/**
	 * Export current workflow as JSON file
	 */
	const handleExport = useCallback( () => {
		exportWorkflow( {
			name: workflowName,
			description: workflowDescription,
			nodes,
			edges,
		} );
	}, [workflowName, workflowDescription, nodes, edges] );

	/**
	 * Import workflow from JSON file
	 */
	const handleImport = useCallback( async ( file ) => {
		try {
			const workflow = await importWorkflow( file );
			if ( workflow.name ) {
				setWorkflowName( workflow.name );
			}
			if ( workflow.description !== undefined ) {
				setWorkflowDescription( workflow.description );
			}
			setNodes( workflow.nodes || [] );
			setEdges( workflow.edges || [] );
			setValidationErrors( [] );
		} catch ( error ) {
			setValidationErrors( [ error.message ] );
		}
	}, [setNodes, setEdges] );

	/**
	 * Load a saved workflow into the editor
	 */
	const handleLoadWorkflow = useCallback( ( workflow ) => {
		setWorkflowName( workflow.name );
		setWorkflowDescription( workflow.description || '' );
		setNodes( workflow.nodes || [] );
		setEdges( workflow.edges || [] );
		setValidationErrors( [] );
	}, [setNodes, setEdges] );

	/**
	 * Delete a saved workflow
	 */
	const handleDeleteWorkflow = useCallback( async ( workflowId ) => {
		try {
			await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'wp_mcp_ai_delete_pro_workflow',
					nonce: window.mcpAiWorkflowBuilder?.nonce || '',
					workflow_id: workflowId,
				} ),
			} );
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error deleting workflow:', error );
		}
	}, [] );

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
				onTest={handleTest}
				onUndo={handleUndo}
				onRedo={handleRedo}
				onSaveVersion={handleSaveVersion}
				onExport={handleExport}
				onImport={handleImport}
				onToggleHistory={() => setShowHistory( ( prev ) => ! prev )}
				onToggleMetrics={() => setShowMetrics( ( prev ) => ! prev )}
				showHistory={showHistory}
				showMetrics={showMetrics}
				canUndo={canUndo}
				canRedo={canRedo}
				isSaving={isSaving}
				validationErrors={validationErrors}
			/>

			<ExecutionControls
				onPlay={isPaused ? handleResume : handleExecute}
				onPause={handlePause}
				onStop={handleStop}
				onDebugToggle={handleDebugToggle}
				isExecuting={isExecuting}
				isPaused={isPaused}
				debugMode={debugMode}
			/>
			
			<div className="workflow-builder-main">
				<WorkflowSidebar
					onLoadTemplate={loadTemplate}
					onLoadWorkflow={handleLoadWorkflow}
					onDeleteWorkflow={handleDeleteWorkflow}
				/>
				
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

				{showMetrics && (
					<MetricsDashboard
						workflowId={workflowName.toLowerCase().replace( /\s+/g, '-' )}
						nodes={nodes}
					/>
				)}
			</div>

			{showHistory && (
				<ExecutionHistoryPanel
					workflowId={workflowName.toLowerCase().replace( /\s+/g, '-' )}
					onClose={() => setShowHistory( false )}
					onReplay={null}
				/>
			)}
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
