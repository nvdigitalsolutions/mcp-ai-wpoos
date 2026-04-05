/**
 * Metrics Dashboard Component
 *
 * Displays workflow execution metrics and statistics.
 *
 * @package WP_MCP_AI
 * @since 2.2.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getExecutionStats, getNodeMetrics } from '../utils/executionHistory';

/**
 * Metrics Dashboard Component
 */
const MetricsDashboard = ( { workflowId, nodes } ) => {
	const [ stats, setStats ] = useState( null );
	const [ nodeMetrics, setNodeMetrics ] = useState( {} );

	useEffect( () => {
		loadMetrics();
	}, [ workflowId ] );

	const loadMetrics = () => {
		const executionStats = getExecutionStats( workflowId );
		const metrics = getNodeMetrics( workflowId );

		setStats( executionStats );
		setNodeMetrics( metrics );
	};

	if ( ! stats ) {
		return (
			<div className="metrics-dashboard loading">
				<p>{__( 'Loading metrics...', 'mcp-ai-wpoos' )}</p>
			</div>
		);
	}

	if ( stats.totalExecutions === 0 ) {
		return (
			<div className="metrics-dashboard empty">
				<div className="empty-state">
					<span className="empty-icon">📊</span>
					<h3>{__( 'No Execution Data', 'mcp-ai-wpoos' )}</h3>
					<p>{__( 'Execute the workflow to see metrics and statistics', 'mcp-ai-wpoos' )}</p>
				</div>
			</div>
		);
	}

	return (
		<div className="metrics-dashboard">
			<div className="metrics-header">
				<h3>{__( 'Workflow Metrics', 'mcp-ai-wpoos' )}</h3>
				<button className="refresh-button" onClick={loadMetrics} title={__( 'Refresh', 'mcp-ai-wpoos' )}>
					🔄
				</button>
			</div>

			<div className="metrics-grid">
				<div className="metric-card">
					<div className="metric-value">{stats.totalExecutions}</div>
					<div className="metric-label">{__( 'Total Executions', 'mcp-ai-wpoos' )}</div>
				</div>

				<div className="metric-card success">
					<div className="metric-value">{stats.successfulExecutions}</div>
					<div className="metric-label">{__( 'Successful', 'mcp-ai-wpoos' )}</div>
				</div>

				<div className="metric-card failed">
					<div className="metric-value">{stats.failedExecutions}</div>
					<div className="metric-label">{__( 'Failed', 'mcp-ai-wpoos' )}</div>
				</div>

				<div className="metric-card">
					<div className="metric-value">{stats.successRate}%</div>
					<div className="metric-label">{__( 'Success Rate', 'mcp-ai-wpoos' )}</div>
				</div>

				<div className="metric-card">
					<div className="metric-value">{( stats.averageDuration / 1000 ).toFixed( 1 )}s</div>
					<div className="metric-label">{__( 'Avg Duration', 'mcp-ai-wpoos' )}</div>
				</div>

				<div className="metric-card">
					<div className="metric-value">{stats.lastExecution ? '✓' : '-'}</div>
					<div className="metric-label">{__( 'Last Run', 'mcp-ai-wpoos' )}</div>
					{stats.lastExecution && (
						<div className="metric-subtitle">{stats.lastExecution}</div>
					)}
				</div>
			</div>

			{Object.keys( nodeMetrics ).length > 0 && (
				<div className="node-metrics">
					<h4>{__( 'Node Performance', 'mcp-ai-wpoos' )}</h4>
					<div className="node-metrics-list">
						{Object.entries( nodeMetrics ).map( ( [ nodeId, metrics ] ) => {
							const node = nodes.find( ( n ) => n.id === nodeId );
							const nodeName = node ? node.data.label : nodeId;

							return (
								<div key={nodeId} className="node-metric-item">
									<div className="node-metric-header">
										<span className="node-name">{nodeName}</span>
										<span className="node-success-rate">{metrics.successRate}%</span>
									</div>
									<div className="node-metric-details">
										<span>
											{metrics.executions} {__( 'executions', 'mcp-ai-wpoos' )}
										</span>
										<span>
											{( metrics.averageDuration / 1000 ).toFixed( 2 )}s{' '}
											{__( 'avg', 'mcp-ai-wpoos' )}
										</span>
									</div>
									<div className="node-metric-bar">
										<div
											className="node-metric-bar-fill"
											style={{ width: `${metrics.successRate}%` }}
										></div>
									</div>
								</div>
							);
						} )}
					</div>
				</div>
			)}
		</div>
	);
};

export default MetricsDashboard;
