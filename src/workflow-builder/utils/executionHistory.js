/**
 * Execution History Tracker
 *
 * Tracks and stores workflow execution history with detailed metrics.
 *
 * @package WP_MCP_AI
 * @since 2.2.0
 */

/**
 * Save execution to history
 */
export const saveExecutionHistory = ( workflowId, execution ) => {
	try {
		const key = `workflow_executions_${workflowId}`;
		const history = getExecutionHistory( workflowId );

		const executionRecord = {
			id: `exec-${Date.now()}`,
			workflowId,
			timestamp: Date.now(),
			duration: execution.duration || 0,
			status: execution.status,
			nodeCount: Object.keys( execution.nodeStates || {} ).length,
			completedNodes: Object.values( execution.nodeStates || {} ).filter(
				( s ) => s.status === 'completed'
			).length,
			failedNodes: Object.values( execution.nodeStates || {} ).filter(
				( s ) => s.status === 'failed'
			).length,
			results: execution.results,
			errors: execution.errors || [],
			nodeStates: execution.nodeStates,
		};

		history.unshift( executionRecord );

		// Keep only last 50 executions
		if ( history.length > 50 ) {
			history.splice( 50 );
		}

		localStorage.setItem( key, JSON.stringify( history ) );

		// Also save to backend
		saveExecutionToBackend( executionRecord );

		return executionRecord;
	} catch ( error ) {
		console.error( 'Error saving execution history:', error );
		return null;
	}
};

/**
 * Get execution history from localStorage
 */
export const getExecutionHistory = ( workflowId ) => {
	try {
		const key = `workflow_executions_${workflowId}`;
		const data = localStorage.getItem( key );
		return data ? JSON.parse( data ) : [];
	} catch ( error ) {
		console.error( 'Error getting execution history:', error );
		return [];
	}
};

/**
 * Get specific execution by ID
 */
export const getExecution = ( workflowId, executionId ) => {
	const history = getExecutionHistory( workflowId );
	return history.find( ( e ) => e.id === executionId );
};

/**
 * Save execution to backend
 */
const saveExecutionToBackend = async ( execution ) => {
	try {
		await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'wp_mcp_ai_save_workflow_execution',
				nonce: window.mcpAiWorkflowBuilder?.nonce || '',
				execution: JSON.stringify( execution ),
			} ),
		} );
	} catch ( error ) {
		console.error( 'Error saving execution to backend:', error );
	}
};

/**
 * Clear execution history
 */
export const clearExecutionHistory = ( workflowId ) => {
	try {
		const key = `workflow_executions_${workflowId}`;
		localStorage.removeItem( key );
		return true;
	} catch ( error ) {
		console.error( 'Error clearing execution history:', error );
		return false;
	}
};

/**
 * Format execution for display
 */
export const formatExecution = ( execution ) => {
	const date = new Date( execution.timestamp );
	const duration = execution.duration ? `${( execution.duration / 1000 ).toFixed( 2 )}s` : 'N/A';
	const successRate =
		execution.nodeCount > 0
			? Math.round( ( execution.completedNodes / execution.nodeCount ) * 100 )
			: 0;

	return {
		...execution,
		formattedDate: date.toLocaleString(),
		formattedDuration: duration,
		successRate: `${successRate}%`,
	};
};

/**
 * Get execution statistics
 */
export const getExecutionStats = ( workflowId ) => {
	const history = getExecutionHistory( workflowId );

	if ( history.length === 0 ) {
		return {
			totalExecutions: 0,
			successfulExecutions: 0,
			failedExecutions: 0,
			averageDuration: 0,
			successRate: 0,
			lastExecution: null,
		};
	}

	const successful = history.filter( ( e ) => e.status === 'completed' ).length;
	const failed = history.filter( ( e ) => e.status === 'failed' ).length;
	const totalDuration = history.reduce( ( sum, e ) => sum + ( e.duration || 0 ), 0 );
	const averageDuration = totalDuration / history.length;

	return {
		totalExecutions: history.length,
		successfulExecutions: successful,
		failedExecutions: failed,
		averageDuration: Math.round( averageDuration ),
		successRate: history.length > 0 ? Math.round( ( successful / history.length ) * 100 ) : 0,
		lastExecution: history[ 0 ] ? new Date( history[ 0 ].timestamp ).toLocaleString() : null,
	};
};

/**
 * Get node performance metrics
 */
export const getNodeMetrics = ( workflowId ) => {
	const history = getExecutionHistory( workflowId );
	const nodeMetrics = {};

	history.forEach( ( execution ) => {
		if ( execution.nodeStates ) {
			Object.entries( execution.nodeStates ).forEach( ( [ nodeId, state ] ) => {
				if ( ! nodeMetrics[ nodeId ] ) {
					nodeMetrics[ nodeId ] = {
						executions: 0,
						successes: 0,
						failures: 0,
						totalDuration: 0,
						averageDuration: 0,
					};
				}

				nodeMetrics[ nodeId ].executions++;
				if ( state.status === 'completed' ) {
					nodeMetrics[ nodeId ].successes++;
				} else if ( state.status === 'failed' ) {
					nodeMetrics[ nodeId ].failures++;
				}

				if ( state.duration ) {
					nodeMetrics[ nodeId ].totalDuration += state.duration;
				}
			} );
		}
	} );

	// Calculate averages
	Object.keys( nodeMetrics ).forEach( ( nodeId ) => {
		const metric = nodeMetrics[ nodeId ];
		metric.averageDuration =
			metric.executions > 0 ? Math.round( metric.totalDuration / metric.executions ) : 0;
		metric.successRate =
			metric.executions > 0 ? Math.round( ( metric.successes / metric.executions ) * 100 ) : 0;
	} );

	return nodeMetrics;
};
