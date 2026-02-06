/**
 * Execution History Panel Component
 *
 * Displays workflow execution history with details.
 *
 * @package WP_MCP_AI
 * @since 2.2.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getExecutionHistory, formatExecution, clearExecutionHistory } from '../utils/executionHistory';

/**
 * Execution History Panel Component
 */
const ExecutionHistoryPanel = ( { workflowId, onClose, onReplay } ) => {
	const [ history, setHistory ] = useState( [] );
	const [ selectedExecution, setSelectedExecution ] = useState( null );

	useEffect( () => {
		loadHistory();
	}, [ workflowId ] );

	const loadHistory = () => {
		const executions = getExecutionHistory( workflowId );
		setHistory( executions );
	};

	const handleClearHistory = () => {
		if ( window.confirm( __( 'Are you sure you want to clear execution history?', 'mcp-ai-wpoos' ) ) ) {
			clearExecutionHistory( workflowId );
			setHistory( [] );
			setSelectedExecution( null );
		}
	};

	const handleSelectExecution = ( execution ) => {
		setSelectedExecution( execution );
	};

	const getStatusIcon = ( status ) => {
		switch ( status ) {
			case 'completed':
				return '✓';
			case 'failed':
				return '✗';
			case 'cancelled':
				return '⊗';
			default:
				return '•';
		}
	};

	const getStatusClass = ( status ) => {
		switch ( status ) {
			case 'completed':
				return 'success';
			case 'failed':
				return 'failed';
			case 'cancelled':
				return 'cancelled';
			default:
				return '';
		}
	};

	return (
		<div className="execution-history-panel">
			<div className="history-header">
				<h3>{__( 'Execution History', 'mcp-ai-wpoos' )}</h3>
				<div className="history-actions">
					{history.length > 0 && (
						<button
							className="clear-history-button"
							onClick={handleClearHistory}
							title={__( 'Clear History', 'mcp-ai-wpoos' )}
						>
							🗑
						</button>
					)}
					<button
						className="close-button"
						onClick={onClose}
						title={__( 'Close', 'mcp-ai-wpoos' )}
					>
						✕
					</button>
				</div>
			</div>

			<div className="history-content">
				{history.length === 0 ? (
					<div className="history-empty">
						<span className="empty-icon">📋</span>
						<p>{__( 'No execution history yet', 'mcp-ai-wpoos' )}</p>
					</div>
				) : (
					<div className="history-split">
						<div className="history-list">
							{history.map( ( execution ) => {
								const formatted = formatExecution( execution );
								return (
									<div
										key={execution.id}
										className={`history-item ${getStatusClass( execution.status )} ${
											selectedExecution?.id === execution.id ? 'selected' : ''
										}`}
										onClick={() => handleSelectExecution( execution )}
									>
										<div className="history-item-status">
											<span className={`status-icon ${getStatusClass( execution.status )}`}>
												{getStatusIcon( execution.status )}
											</span>
										</div>
										<div className="history-item-details">
											<div className="history-item-date">{formatted.formattedDate}</div>
											<div className="history-item-meta">
												<span>{formatted.formattedDuration}</span>
												<span>•</span>
												<span>
													{execution.completedNodes}/{execution.nodeCount}{' '}
													{__( 'nodes', 'mcp-ai-wpoos' )}
												</span>
												{execution.status === 'completed' && (
													<>
														<span>•</span>
														<span className="success-rate">{formatted.successRate}</span>
													</>
												)}
											</div>
										</div>
									</div>
								);
							} )}
						</div>

						{selectedExecution && (
							<div className="history-details">
								<h4>{__( 'Execution Details', 'mcp-ai-wpoos' )}</h4>

								<div className="detail-section">
									<div className="detail-label">{__( 'Status', 'mcp-ai-wpoos' )}</div>
									<div className={`detail-value ${getStatusClass( selectedExecution.status )}`}>
										{selectedExecution.status}
									</div>
								</div>

								<div className="detail-section">
									<div className="detail-label">{__( 'Duration', 'mcp-ai-wpoos' )}</div>
									<div className="detail-value">
										{( selectedExecution.duration / 1000 ).toFixed( 2 )}s
									</div>
								</div>

								<div className="detail-section">
									<div className="detail-label">{__( 'Nodes', 'mcp-ai-wpoos' )}</div>
									<div className="detail-value">
										{selectedExecution.completedNodes} / {selectedExecution.nodeCount}{' '}
										{__( 'completed', 'mcp-ai-wpoos' )}
									</div>
								</div>

								{selectedExecution.errors && selectedExecution.errors.length > 0 && (
									<div className="detail-section">
										<div className="detail-label">{__( 'Errors', 'mcp-ai-wpoos' )}</div>
										<div className="detail-value errors">
											{selectedExecution.errors.map( ( error, index ) => (
												<div key={index} className="error-item">
													{error.message}
												</div>
											) )}
										</div>
									</div>
								)}

								{selectedExecution.nodeStates && (
									<div className="detail-section">
										<div className="detail-label">{__( 'Node States', 'mcp-ai-wpoos' )}</div>
										<div className="node-states">
											{Object.entries( selectedExecution.nodeStates ).map(
												( [ nodeId, state ] ) => (
													<div key={nodeId} className="node-state-item">
														<span
															className={`node-state-icon ${getStatusClass( state.status )}`}
														>
															{getStatusIcon( state.status )}
														</span>
														<span className="node-state-id">{nodeId}</span>
														{state.duration && (
															<span className="node-state-duration">
																{( state.duration / 1000 ).toFixed( 2 )}s
															</span>
														)}
													</div>
												)
											)}
										</div>
									</div>
								)}

								{onReplay && (
									<button
										className="replay-button"
										onClick={() => onReplay( selectedExecution )}
									>
										{__( 'Replay Execution', 'mcp-ai-wpoos' )}
									</button>
								)}
							</div>
						)}
					</div>
				)}
			</div>
		</div>
	);
};

export default ExecutionHistoryPanel;
