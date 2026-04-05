/**
 * Workflow Execution Engine
 *
 * Executes workflows node-by-node with state management, error handling,
 * and integration with the Agent Team Orchestrator.
 *
 * @package WP_MCP_AI
 * @since 2.2.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Execution status constants
 */
export const ExecutionStatus = {
	PENDING: 'pending',
	RUNNING: 'running',
	COMPLETED: 'completed',
	FAILED: 'failed',
	PAUSED: 'paused',
	CANCELLED: 'cancelled',
};

/**
 * Workflow Executor class
 */
export class WorkflowExecutor {
	constructor( workflow, options = {} ) {
		this.workflow = workflow;
		this.options = {
			debugMode: false,
			maxRetries: 2,
			timeout: 600000, // 10 minutes
			...options,
		};

		this.state = {
			status: ExecutionStatus.PENDING,
			currentNodeId: null,
			nodeStates: {},
			results: {},
			errors: [],
			startTime: null,
			endTime: null,
			pauseRequested: false,
			cancelRequested: false,
		};

		this.listeners = {
			onNodeStart: [],
			onNodeComplete: [],
			onNodeError: [],
			onExecutionComplete: [],
			onExecutionError: [],
			onPause: [],
			onResume: [],
		};
	}

	/**
	 * Add event listener
	 */
	on( event, callback ) {
		if ( this.listeners[ event ] ) {
			this.listeners[ event ].push( callback );
		}
	}

	/**
	 * Emit event to listeners
	 */
	emit( event, data ) {
		if ( this.listeners[ event ] ) {
			this.listeners[ event ].forEach( ( callback ) => callback( data ) );
		}
	}

	/**
	 * Execute the workflow
	 */
	async execute() {
		this.state.status = ExecutionStatus.RUNNING;
		this.state.startTime = Date.now();

		try {
			// Find trigger node
			const triggerNode = this.workflow.nodes.find( ( n ) => n.type === 'trigger' );
			if ( ! triggerNode ) {
				throw new Error( __( 'No trigger node found in workflow', 'mcp-ai-wpoos' ) );
			}

			// Initialize node states
			this.workflow.nodes.forEach( ( node ) => {
				this.state.nodeStates[ node.id ] = {
					status: ExecutionStatus.PENDING,
					attempts: 0,
					startTime: null,
					endTime: null,
					duration: null,
				};
			} );

			// Start execution from trigger
			await this.executeNode( triggerNode );

			// Mark as completed
			this.state.status = ExecutionStatus.COMPLETED;
			this.state.endTime = Date.now();

			this.emit( 'onExecutionComplete', {
				status: this.state.status,
				duration: this.state.endTime - this.state.startTime,
				results: this.state.results,
			} );

			return {
				success: true,
				results: this.state.results,
				duration: this.state.endTime - this.state.startTime,
			};
		} catch ( error ) {
			this.state.status = ExecutionStatus.FAILED;
			this.state.endTime = Date.now();
			this.state.errors.push( {
				message: error.message,
				stack: error.stack,
				timestamp: Date.now(),
			} );

			this.emit( 'onExecutionError', {
				error: error.message,
				state: this.state,
			} );

			return {
				success: false,
				error: error.message,
				state: this.state,
			};
		}
	}

	/**
	 * Execute a single node
	 */
	async executeNode( node ) {
		// Check for pause or cancel
		if ( this.state.pauseRequested ) {
			this.state.status = ExecutionStatus.PAUSED;
			this.emit( 'onPause', { nodeId: node.id } );
			await this.waitForResume();
		}

		if ( this.state.cancelRequested ) {
			this.state.status = ExecutionStatus.CANCELLED;
			throw new Error( __( 'Execution cancelled by user', 'mcp-ai-wpoos' ) );
		}

		this.state.currentNodeId = node.id;
		const nodeState = this.state.nodeStates[ node.id ];
		nodeState.status = ExecutionStatus.RUNNING;
		nodeState.startTime = Date.now();

		this.emit( 'onNodeStart', { node, state: nodeState } );

		try {
			// Execute node based on type
			let result;
			switch ( node.type ) {
				case 'trigger':
					result = await this.executeTrigger( node );
					break;
				case 'action':
					result = await this.executeAction( node );
					break;
				case 'tool':
					result = await this.executeTool( node );
					break;
				case 'agent':
					result = await this.executeAgent( node );
					break;
				case 'condition':
					result = await this.executeCondition( node );
					break;
				case 'loop':
					result = await this.executeLoop( node );
					break;
				case 'parallel':
					result = await this.executeParallel( node );
					break;
				case 'delay':
					result = await this.executeDelay( node );
					break;
				case 'approval':
					result = await this.executeApproval( node );
					break;
				case 'merge':
					result = await this.executeMerge( node );
					break;
				default:
					throw new Error( `Unknown node type: ${node.type}` );
			}

			// Mark node as completed
			nodeState.status = ExecutionStatus.COMPLETED;
			nodeState.endTime = Date.now();
			nodeState.duration = nodeState.endTime - nodeState.startTime;
			this.state.results[ node.id ] = result;

			this.emit( 'onNodeComplete', { node, result, state: nodeState } );

			// Execute next nodes
			await this.executeNextNodes( node, result );
		} catch ( error ) {
			nodeState.status = ExecutionStatus.FAILED;
			nodeState.endTime = Date.now();
			nodeState.error = error.message;

			this.emit( 'onNodeError', { node, error: error.message, state: nodeState } );

			// Retry logic
			if ( nodeState.attempts < this.options.maxRetries ) {
				nodeState.attempts++;
				nodeState.status = ExecutionStatus.PENDING;
				await this.executeNode( node );
			} else {
				throw error;
			}
		}
	}

	/**
	 * Execute next nodes based on edges
	 */
	async executeNextNodes( currentNode, result ) {
		const outgoingEdges = this.workflow.edges.filter( ( e ) => e.source === currentNode.id );

		// Handle condition branching
		if ( currentNode.type === 'condition' ) {
			const branchEdge = outgoingEdges.find( ( e ) => {
				return result.branch === 'true' ? e.sourceHandle === 'true' : e.sourceHandle === 'false';
			} );

			if ( branchEdge ) {
				const nextNode = this.workflow.nodes.find( ( n ) => n.id === branchEdge.target );
				if ( nextNode ) {
					await this.executeNode( nextNode );
				}
			}
		} else {
			// Execute all connected nodes
			for ( const edge of outgoingEdges ) {
				const nextNode = this.workflow.nodes.find( ( n ) => n.id === edge.target );
				if ( nextNode && this.state.nodeStates[ nextNode.id ].status === ExecutionStatus.PENDING ) {
					await this.executeNode( nextNode );
				}
			}
		}
	}

	/**
	 * Execute trigger node
	 */
	async executeTrigger( node ) {
		return {
			type: 'trigger',
			timestamp: Date.now(),
			data: node.data.config || {},
		};
	}

	/**
	 * Execute action node (slash command)
	 */
	async executeAction( node ) {
		const { command, params } = node.data.config || {};
		
		if ( ! command ) {
			throw new Error( __( 'Action node missing command', 'mcp-ai-wpoos' ) );
		}

		// Call backend to execute slash command
		const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'wp_mcp_ai_execute_workflow_node',
				nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				node_type: 'action',
				command,
				params: params || '{}',
				context: JSON.stringify( this.state.results ),
			} ),
		} );

		const result = await response.json();
		
		if ( ! result.success ) {
			throw new Error( result.data?.message || __( 'Action execution failed', 'mcp-ai-wpoos' ) );
		}

		return result.data;
	}

	/**
	 * Execute tool node
	 */
	async executeTool( node ) {
		const { tool_name, arguments: toolArgs } = node.data.config || {};
		
		if ( ! tool_name ) {
			throw new Error( __( 'Tool node missing tool_name', 'mcp-ai-wpoos' ) );
		}

		// Call backend to execute tool
		const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'wp_mcp_ai_execute_workflow_node',
				nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				node_type: 'tool',
				tool_name,
				tool_arguments: toolArgs || '{}',
				context: JSON.stringify( this.state.results ),
			} ),
		} );

		const result = await response.json();
		
		if ( ! result.success ) {
			throw new Error( result.data?.message || __( 'Tool execution failed', 'mcp-ai-wpoos' ) );
		}

		return result.data;
	}

	/**
	 * Execute agent node
	 */
	async executeAgent( node ) {
		const { agent_id, prompt } = node.data.config || {};
		
		if ( ! prompt ) {
			throw new Error( __( 'Agent node missing prompt', 'mcp-ai-wpoos' ) );
		}

		// Call backend to execute agent
		const response = await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'wp_mcp_ai_execute_workflow_node',
				nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				node_type: 'agent',
				agent_id: agent_id || 'default',
				prompt,
				context: JSON.stringify( this.state.results ),
			} ),
		} );

		const result = await response.json();
		
		if ( ! result.success ) {
			throw new Error( result.data?.message || __( 'Agent execution failed', 'mcp-ai-wpoos' ) );
		}

		return result.data;
	}

	/**
	 * Execute condition node
	 */
	async executeCondition( node ) {
		const { expression } = node.data.config || {};
		
		if ( ! expression ) {
			throw new Error( __( 'Condition node missing expression', 'mcp-ai-wpoos' ) );
		}

		// Evaluate expression with context
		try {
			// Simple evaluation - in production, use safe eval
			const context = this.state.results;
			// eslint-disable-next-line no-new-func
			const evalFunc = new Function( 'context', `with(context) { return ${expression}; }` );
			const result = evalFunc( context );

			return {
				branch: result ? 'true' : 'false',
				expression,
				result,
			};
		} catch ( error ) {
			throw new Error( `Condition evaluation failed: ${error.message}` );
		}
	}

	/**
	 * Execute loop node
	 */
	async executeLoop( node ) {
		const { items } = node.data.config || {};
		
		if ( ! items ) {
			throw new Error( __( 'Loop node missing items', 'mcp-ai-wpoos' ) );
		}

		// Get items array from context
		let itemsArray;
		try {
			const context = this.state.results;
			// eslint-disable-next-line no-new-func
			const evalFunc = new Function( 'context', `with(context) { return ${items}; }` );
			itemsArray = evalFunc( context );
		} catch ( error ) {
			throw new Error( `Loop items evaluation failed: ${error.message}` );
		}

		if ( ! Array.isArray( itemsArray ) ) {
			throw new Error( __( 'Loop items must be an array', 'mcp-ai-wpoos' ) );
		}

		const results = [];
		for ( let i = 0; i < itemsArray.length; i++ ) {
			// Execute loop body for each item
			this.state.results._loopItem = itemsArray[ i ];
			this.state.results._loopIndex = i;

			// Find and execute child nodes
			const outgoingEdges = this.workflow.edges.filter( ( e ) => e.source === node.id );
			for ( const edge of outgoingEdges ) {
				const childNode = this.workflow.nodes.find( ( n ) => n.id === edge.target );
				if ( childNode ) {
					// Reset child node state
					this.state.nodeStates[ childNode.id ].status = ExecutionStatus.PENDING;
					await this.executeNode( childNode );
					results.push( this.state.results[ childNode.id ] );
				}
			}
		}

		return {
			iterations: itemsArray.length,
			results,
		};
	}

	/**
	 * Execute parallel node
	 */
	async executeParallel( node ) {
		const outgoingEdges = this.workflow.edges.filter( ( e ) => e.source === node.id );
		const promises = [];

		for ( const edge of outgoingEdges ) {
			const childNode = this.workflow.nodes.find( ( n ) => n.id === edge.target );
			if ( childNode ) {
				promises.push( this.executeNode( childNode ) );
			}
		}

		await Promise.all( promises );

		return {
			parallel: true,
			branches: promises.length,
		};
	}

	/**
	 * Execute delay node
	 */
	async executeDelay( node ) {
		const { duration } = node.data.config || { duration: 1 };
		await new Promise( ( resolve ) => setTimeout( resolve, duration * 1000 ) );

		return {
			delayed: duration,
			unit: 'seconds',
		};
	}

	/**
	 * Execute approval node
	 */
	async executeApproval( node ) {
		// In a real implementation, this would wait for user approval
		// For now, auto-approve in non-debug mode
		if ( ! this.options.debugMode ) {
			return {
				approved: true,
				auto: true,
			};
		}

		// In debug mode, require manual approval
		return new Promise( ( resolve ) => {
			this.emit( 'onApprovalRequired', {
				node,
				resolve: ( approved ) => {
					resolve( {
						approved,
						manual: true,
					} );
				},
			} );
		} );
	}

	/**
	 * Execute merge node
	 */
	async executeMerge( node ) {
		const incomingEdges = this.workflow.edges.filter( ( e ) => e.target === node.id );
		const results = [];

		for ( const edge of incomingEdges ) {
			const sourceNodeResult = this.state.results[ edge.source ];
			if ( sourceNodeResult ) {
				results.push( sourceNodeResult );
			}
		}

		return {
			merged: true,
			results,
			count: results.length,
		};
	}

	/**
	 * Pause execution
	 */
	pause() {
		this.state.pauseRequested = true;
	}

	/**
	 * Resume execution
	 */
	resume() {
		this.state.pauseRequested = false;
		this.state.status = ExecutionStatus.RUNNING;
		this.emit( 'onResume', {} );
	}

	/**
	 * Cancel execution
	 */
	cancel() {
		this.state.cancelRequested = true;
	}

	/**
	 * Wait for resume
	 */
	async waitForResume() {
		return new Promise( ( resolve ) => {
			const checkInterval = setInterval( () => {
				if ( ! this.state.pauseRequested ) {
					clearInterval( checkInterval );
					resolve();
				}
			}, 100 );
		} );
	}

	/**
	 * Get current state
	 */
	getState() {
		return { ...this.state };
	}
}
