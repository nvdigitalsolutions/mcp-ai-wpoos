/**
 * Workflow Properties Panel Component
 *
 * Right panel for editing selected node properties.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Get available tools from localized data.
 */
const getAvailableTools = () => {
	return window.mcpAiWorkflowBuilder?.availableTools || [];
};

/**
 * Get available assistants from localized data.
 */
const getAvailableAssistants = () => {
	return window.mcpAiWorkflowBuilder?.assistants || [];
};

/**
 * Workflow Properties Panel Component
 */
const WorkflowPropertiesPanel = ( {
	node,
	onUpdateNode,
	onDeleteNode,
	onClose,
} ) => {
	/**
	 * Handle input change
	 */
	const handleChange = ( field, value ) => {
		onUpdateNode( node.id, {
			config: {
				...node.data.config,
				[field]: value,
			},
		} );
	};

	/**
	 * Handle label change
	 */
	const handleLabelChange = ( value ) => {
		onUpdateNode( node.id, {
			label: value,
		} );
	};

	const availableTools      = getAvailableTools();
	const availableAssistants = getAvailableAssistants();

	return (
		<div className="workflow-properties-panel">
			<div className="properties-header">
				<h3>{__( 'Node Properties', 'mcp-ai-wpoos' )}</h3>
				<button className="close-button" onClick={onClose}>
					×
				</button>
			</div>

			<div className="properties-content">
				<div className="property-group">
					<label htmlFor="node-label">{__( 'Label', 'mcp-ai-wpoos' )}</label>
					<input
						id="node-label"
						type="text"
						value={node.data.label || ''}
						onChange={( e ) => handleLabelChange( e.target.value )}
						className="property-input"
					/>
				</div>

				<div className="property-group">
					<label htmlFor="node-type">{__( 'Type', 'mcp-ai-wpoos' )}</label>
					<input
						id="node-type"
						type="text"
						value={node.type}
						disabled
						className="property-input disabled"
					/>
				</div>

				{/* Trigger node */}
				{node.type === 'trigger' && (
					<>
						<div className="property-group">
							<label htmlFor="trigger-type">{__( 'Trigger Type', 'mcp-ai-wpoos' )}</label>
							<select
								id="trigger-type"
								value={node.data.config?.trigger_type || 'manual'}
								onChange={( e ) => handleChange( 'trigger_type', e.target.value )}
								className="property-input"
							>
								<option value="manual">{__( 'Manual', 'mcp-ai-wpoos' )}</option>
								<option value="schedule">{__( 'Scheduled', 'mcp-ai-wpoos' )}</option>
								<option value="webhook">{__( 'Webhook', 'mcp-ai-wpoos' )}</option>
								<option value="post_save">{__( 'Post Save', 'mcp-ai-wpoos' )}</option>
								<option value="form_submit">{__( 'Form Submit', 'mcp-ai-wpoos' )}</option>
							</select>
						</div>

						{node.data.config?.trigger_type === 'schedule' && (
							<div className="property-group">
								<label htmlFor="trigger-schedule">{__( 'Cron Schedule', 'mcp-ai-wpoos' )}</label>
								<input
									id="trigger-schedule"
									type="text"
									value={node.data.config?.schedule || ''}
									onChange={( e ) => handleChange( 'schedule', e.target.value )}
									className="property-input"
									placeholder="0 * * * *"
								/>
							</div>
						)}

						{node.data.config?.trigger_type === 'webhook' && (
							<div className="property-group">
								<label htmlFor="trigger-event">{__( 'Webhook Event', 'mcp-ai-wpoos' )}</label>
								<input
									id="trigger-event"
									type="text"
									value={node.data.config?.event || ''}
									onChange={( e ) => handleChange( 'event', e.target.value )}
									className="property-input"
									placeholder="wp_hook_name"
								/>
							</div>
						)}
					</>
				)}

				{/* Action node */}
				{node.type === 'action' && (
					<>
						<div className="property-group">
							<label htmlFor="action-command">{__( 'Command', 'mcp-ai-wpoos' )}</label>
							<input
								id="action-command"
								type="text"
								value={node.data.config?.command || ''}
								onChange={( e ) => handleChange( 'command', e.target.value )}
								className="property-input"
								placeholder="/command-name"
							/>
						</div>

						<div className="property-group">
							<label htmlFor="action-params">{__( 'Parameters (JSON)', 'mcp-ai-wpoos' )}</label>
							<textarea
								id="action-params"
								value={node.data.config?.params || '{}'}
								onChange={( e ) => handleChange( 'params', e.target.value )}
								className="property-textarea"
								rows={4}
							/>
						</div>
					</>
				)}

				{/* Tool node */}
				{node.type === 'tool' && (
					<>
						<div className="property-group">
							<label htmlFor="tool-name">{__( 'Tool', 'mcp-ai-wpoos' )}</label>
							{availableTools.length > 0 ? (
								<select
									id="tool-name"
									value={node.data.config?.tool_name || ''}
									onChange={( e ) => handleChange( 'tool_name', e.target.value )}
									className="property-input"
								>
									<option value="">{__( '— Select a tool —', 'mcp-ai-wpoos' )}</option>
									{availableTools.map( ( tool ) => (
										<option key={tool.name} value={tool.name}>
											{tool.label || tool.name}
										</option>
									) )}
								</select>
							) : (
								<input
									id="tool-name"
									type="text"
									value={node.data.config?.tool_name || ''}
									onChange={( e ) => handleChange( 'tool_name', e.target.value )}
									className="property-input"
									placeholder="tool_name"
								/>
							)}
						</div>

						{availableTools.length > 0 && node.data.config?.tool_name && (
							<div className="property-group">
								<div className="property-help">
									{availableTools.find( ( t ) => t.name === node.data.config?.tool_name )?.description || ''}
								</div>
							</div>
						)}

						<div className="property-group">
							<label htmlFor="tool-arguments">{__( 'Arguments (JSON)', 'mcp-ai-wpoos' )}</label>
							<textarea
								id="tool-arguments"
								value={node.data.config?.arguments || '{}'}
								onChange={( e ) => handleChange( 'arguments', e.target.value )}
								className="property-textarea"
								rows={4}
								placeholder="{}"
							/>
						</div>
					</>
				)}

				{/* Agent node */}
				{node.type === 'agent' && (
					<>
						<div className="property-group">
							<label htmlFor="agent-id">{__( 'Assistant', 'mcp-ai-wpoos' )}</label>
							{availableAssistants.length > 0 ? (
								<select
									id="agent-id"
									value={node.data.config?.agent_id || ''}
									onChange={( e ) => handleChange( 'agent_id', e.target.value )}
									className="property-input"
								>
									<option value="">{__( '— Select an assistant —', 'mcp-ai-wpoos' )}</option>
									{availableAssistants.map( ( assistant ) => (
										<option key={assistant.id} value={assistant.id}>
											{assistant.name}
										</option>
									) )}
								</select>
							) : (
								<input
									id="agent-id"
									type="text"
									value={node.data.config?.agent_id || ''}
									onChange={( e ) => handleChange( 'agent_id', e.target.value )}
									className="property-input"
									placeholder="default"
								/>
							)}
						</div>

						<div className="property-group">
							<label htmlFor="agent-prompt">{__( 'Prompt', 'mcp-ai-wpoos' )}</label>
							<textarea
								id="agent-prompt"
								value={node.data.config?.prompt || ''}
								onChange={( e ) => handleChange( 'prompt', e.target.value )}
								className="property-textarea"
								rows={5}
								placeholder={__( 'Enter prompt for the AI agent. Use {{nodeId.field}} to reference previous node outputs.', 'mcp-ai-wpoos' )}
							/>
						</div>
					</>
				)}

				{/* Condition node */}
				{node.type === 'condition' && (
					<>
						<div className="property-group">
							<label htmlFor="condition-expression">{__( 'Condition Expression', 'mcp-ai-wpoos' )}</label>
							<input
								id="condition-expression"
								type="text"
								value={node.data.config?.expression || ''}
								onChange={( e ) => handleChange( 'expression', e.target.value )}
								className="property-input"
								placeholder="result.status === 'success'"
							/>
						</div>
						<div className="property-group">
							<div className="property-help">
								{__( 'Connect the "true" branch to nodes that run when the condition is met, and the "false" branch for the else path.', 'mcp-ai-wpoos' )}
							</div>
						</div>
					</>
				)}

				{/* Delay node */}
				{node.type === 'delay' && (
					<>
						<div className="property-group">
							<label htmlFor="delay-duration">{__( 'Delay Duration (seconds)', 'mcp-ai-wpoos' )}</label>
							<input
								id="delay-duration"
								type="number"
								value={node.data.config?.duration || 1}
								onChange={( e ) => handleChange( 'duration', parseInt( e.target.value, 10 ) )}
								className="property-input"
								min="1"
							/>
						</div>
					</>
				)}

				{/* Loop node */}
				{node.type === 'loop' && (
					<>
						<div className="property-group">
							<label htmlFor="loop-items">{__( 'Items to Iterate', 'mcp-ai-wpoos' )}</label>
							<input
								id="loop-items"
								type="text"
								value={node.data.config?.items || ''}
								onChange={( e ) => handleChange( 'items', e.target.value )}
								className="property-input"
								placeholder="{{previous.results}}"
							/>
						</div>
						<div className="property-group">
							<label htmlFor="loop-max">{__( 'Max Iterations', 'mcp-ai-wpoos' )}</label>
							<input
								id="loop-max"
								type="number"
								value={node.data.config?.max_iterations || 100}
								onChange={( e ) => handleChange( 'max_iterations', parseInt( e.target.value, 10 ) )}
								className="property-input"
								min="1"
								max="10000"
							/>
						</div>
					</>
				)}

				{/* Approval node */}
				{node.type === 'approval' && (
					<>
						<div className="property-group">
							<label htmlFor="approval-message">{__( 'Approval Message', 'mcp-ai-wpoos' )}</label>
							<textarea
								id="approval-message"
								value={node.data.config?.message || ''}
								onChange={( e ) => handleChange( 'message', e.target.value )}
								className="property-textarea"
								rows={3}
								placeholder={__( 'Describe what requires approval…', 'mcp-ai-wpoos' )}
							/>
						</div>
						<div className="property-group">
							<label htmlFor="approval-timeout">{__( 'Timeout (seconds, 0 = no timeout)', 'mcp-ai-wpoos' )}</label>
							<input
								id="approval-timeout"
								type="number"
								value={node.data.config?.timeout || 0}
								onChange={( e ) => handleChange( 'timeout', parseInt( e.target.value, 10 ) )}
								className="property-input"
								min="0"
							/>
						</div>
						<div className="property-group">
							<label className="property-checkbox-label">
								<input
									type="checkbox"
									checked={node.data.config?.auto_approve || false}
									onChange={( e ) => handleChange( 'auto_approve', e.target.checked )}
								/>
								{__( 'Auto-approve after timeout', 'mcp-ai-wpoos' )}
							</label>
						</div>
					</>
				)}

				{/* Merge node */}
				{node.type === 'merge' && (
					<>
						<div className="property-group">
							<label htmlFor="merge-strategy">{__( 'Merge Strategy', 'mcp-ai-wpoos' )}</label>
							<select
								id="merge-strategy"
								value={node.data.config?.strategy || 'all'}
								onChange={( e ) => handleChange( 'strategy', e.target.value )}
								className="property-input"
							>
								<option value="all">{__( 'Wait for All', 'mcp-ai-wpoos' )}</option>
								<option value="first">{__( 'First Result', 'mcp-ai-wpoos' )}</option>
								<option value="concat">{__( 'Concatenate', 'mcp-ai-wpoos' )}</option>
							</select>
						</div>
					</>
				)}
			</div>

			<div className="properties-footer">
				<button
					className="delete-button"
					onClick={() => onDeleteNode( node.id )}
				>
					{__( 'Delete Node', 'mcp-ai-wpoos' )}
				</button>
			</div>
		</div>
	);
};

export default WorkflowPropertiesPanel;
