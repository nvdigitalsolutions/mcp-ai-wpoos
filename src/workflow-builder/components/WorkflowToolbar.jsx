/**
 * Workflow Toolbar Component
 *
 * Top toolbar with workflow name, save, and actions.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Workflow Toolbar Component
 */
const WorkflowToolbar = ( {
	workflowName,
	onNameChange,
	onSave,
	isSaving,
	validationErrors,
} ) => {
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

			<div className="workflow-toolbar-right">
				{validationErrors.length > 0 && (
					<span className="validation-badge error">
						{validationErrors.length} {__( 'error(s)', 'mcp-ai-wpoos' )}
					</span>
				)}
				
				<button
					className="toolbar-button test-button"
					disabled={isSaving}
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
