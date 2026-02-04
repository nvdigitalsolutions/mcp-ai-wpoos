/**
 * Execution Controls Component
 *
 * Provides play, pause, stop controls for workflow execution.
 *
 * @package WP_MCP_AI
 * @since 2.2.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Execution Controls Component
 */
const ExecutionControls = ( {
	onPlay,
	onPause,
	onStop,
	onDebugToggle,
	isExecuting,
	isPaused,
	debugMode,
} ) => {
	const [ showHistory, setShowHistory ] = useState( false );

	return (
		<div className="execution-controls">
			<div className="execution-buttons">
				{! isExecuting && (
					<button
						className="execution-button play-button"
						onClick={onPlay}
						title={__( 'Execute Workflow', 'mcp-ai-wpoos' )}
					>
						▶ {__( 'Run', 'mcp-ai-wpoos' )}
					</button>
				)}

				{isExecuting && ! isPaused && (
					<button
						className="execution-button pause-button"
						onClick={onPause}
						title={__( 'Pause Execution', 'mcp-ai-wpoos' )}
					>
						⏸ {__( 'Pause', 'mcp-ai-wpoos' )}
					</button>
				)}

				{isExecuting && isPaused && (
					<button
						className="execution-button resume-button"
						onClick={onPlay}
						title={__( 'Resume Execution', 'mcp-ai-wpoos' )}
					>
						▶ {__( 'Resume', 'mcp-ai-wpoos' )}
					</button>
				)}

				{isExecuting && (
					<button
						className="execution-button stop-button"
						onClick={onStop}
						title={__( 'Stop Execution', 'mcp-ai-wpoos' )}
					>
						⏹ {__( 'Stop', 'mcp-ai-wpoos' )}
					</button>
				)}

				<div className="execution-divider"></div>

				<button
					className={`execution-button debug-button ${debugMode ? 'active' : ''}`}
					onClick={onDebugToggle}
					title={__( 'Toggle Debug Mode', 'mcp-ai-wpoos' )}
				>
					🐛 {debugMode ? __( 'Debug: ON', 'mcp-ai-wpoos' ) : __( 'Debug: OFF', 'mcp-ai-wpoos' )}
				</button>

				<button
					className="execution-button history-button"
					onClick={() => setShowHistory( ! showHistory )}
					title={__( 'View Execution History', 'mcp-ai-wpoos' )}
				>
					📊 {__( 'History', 'mcp-ai-wpoos' )}
				</button>
			</div>

			{isExecuting && (
				<div className="execution-status">
					<span className="status-indicator running"></span>
					<span className="status-text">
						{isPaused ? __( 'Paused', 'mcp-ai-wpoos' ) : __( 'Running...', 'mcp-ai-wpoos' )}
					</span>
				</div>
			)}
		</div>
	);
};

export default ExecutionControls;
