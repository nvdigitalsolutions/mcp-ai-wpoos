/**
 * Workflow Toolbar Component
 *
 * Top toolbar with workflow name, save, undo/redo, and actions.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Workflow Toolbar Component
 */
const WorkflowToolbar = ( {
	workflowName,
	onNameChange,
	onSave,
	onTest,
	onUndo,
	onRedo,
	onSaveVersion,
	onExport,
	onImport,
	onToggleHistory,
	onToggleMetrics,
	showHistory,
	showMetrics,
	canUndo,
	canRedo,
	isSaving,
	validationErrors,
} ) => {
	const importRef = useRef( null );

	const handleImportClick = () => {
		if ( importRef.current ) {
			importRef.current.click();
		}
	};

	const handleFileChange = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( file && onImport ) {
			onImport( file );
		}
		// Reset so the same file can be imported again.
		e.target.value = '';
	};

	return (
		<div className="workflow-toolbar">
			<div className="workflow-toolbar-left">
				<input
					type="text"
					value={workflowName}
					onChange={( e ) => onNameChange( e.target.value )}
					className="workflow-name-input"
					placeholder={__( 'Workflow Name', 'mcp-ai-wpoos' )}
				/>
			</div>

			<div className="workflow-toolbar-center">
				<button
					className="toolbar-button undo-button"
					onClick={onUndo}
					disabled={! canUndo}
					title={__( 'Undo (Ctrl+Z)', 'mcp-ai-wpoos' )}
				>
					↶
				</button>
				<button
					className="toolbar-button redo-button"
					onClick={onRedo}
					disabled={! canRedo}
					title={__( 'Redo (Ctrl+Y)', 'mcp-ai-wpoos' )}
				>
					↷
				</button>
			</div>

			<div className="workflow-toolbar-right">
				{validationErrors.length > 0 && (
					<span className="validation-badge error">
						{validationErrors.length} {__( 'error(s)', 'mcp-ai-wpoos' )}
					</span>
				)}

				<button
					className={`toolbar-button metrics-button ${showMetrics ? 'active' : ''}`}
					onClick={onToggleMetrics}
					title={__( 'Toggle Metrics', 'mcp-ai-wpoos' )}
				>
					📊
				</button>

				<button
					className={`toolbar-button history-button ${showHistory ? 'active' : ''}`}
					onClick={onToggleHistory}
					title={__( 'Toggle Execution History', 'mcp-ai-wpoos' )}
				>
					📋
				</button>

				<button
					className="toolbar-button import-button"
					onClick={handleImportClick}
					disabled={isSaving}
					title={__( 'Import Workflow', 'mcp-ai-wpoos' )}
				>
					{__( 'Import', 'mcp-ai-wpoos' )}
				</button>
				<input
					ref={importRef}
					type="file"
					accept=".json"
					style={{ display: 'none' }}
					onChange={handleFileChange}
				/>

				<button
					className="toolbar-button export-button"
					onClick={onExport}
					disabled={isSaving}
					title={__( 'Export Workflow', 'mcp-ai-wpoos' )}
				>
					{__( 'Export', 'mcp-ai-wpoos' )}
				</button>

				<button
					className="toolbar-button version-button"
					onClick={onSaveVersion}
					disabled={isSaving}
					title={__( 'Save Version', 'mcp-ai-wpoos' )}
				>
					{__( 'Version', 'mcp-ai-wpoos' )}
				</button>

				<button
					className="toolbar-button test-button"
					onClick={onTest}
					disabled={isSaving}
					title={__( 'Validate Workflow', 'mcp-ai-wpoos' )}
				>
					{__( 'Test', 'mcp-ai-wpoos' )}
				</button>

				<button
					className="toolbar-button save-button"
					onClick={onSave}
					disabled={isSaving}
				>
					{isSaving ? __( 'Saving...', 'mcp-ai-wpoos' ) : __( 'Save', 'mcp-ai-wpoos' )}
				</button>
			</div>
		</div>
	);
};

export default WorkflowToolbar;
