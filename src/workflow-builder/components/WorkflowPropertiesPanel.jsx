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

				{/* Node type-specific properties */}
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
					</>
				)}

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
