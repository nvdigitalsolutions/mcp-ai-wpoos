/**
 * NodeEditor — the selected-node configuration panel for the workflow canvas
 * (Phase 3b W10.2b follow-up).
 *
 * The first canvas cut only offered a label rename, so a node's executable
 * configuration (tool slug + arguments, command + params, agent + prompt)
 * could never be changed after it was added. This panel owns the full edit
 * surface for the selected node and writes straight into `node.data` through
 * onPatch — `toGraphNode` round-trips every data key except label/rawType,
 * so no converter changes are needed.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import type { CanvasWorkflowNode, WorkflowNodeData } from './workflow-graph';

/** Editor for a JSON-object data field (tool arguments / action params). */
export function JsonParamsField( {
	id,
	label,
	value,
	onChange,
}: {
	id: string;
	label: string;
	value: unknown;
	onChange: ( next: Record< string, unknown > ) => void;
} ): JSX.Element {
	const [ text, setText ] = useState< string >( () =>
		JSON.stringify( value && typeof value === 'object' ? value : {}, null, 2 )
	);
	const [ error, setError ] = useState< string | null >( null );

	const handleChange = ( next: string ): void => {
		setText( next );
		if ( next.trim() === '' ) {
			setError( null );
			onChange( {} );
			return;
		}
		try {
			const parsed: unknown = JSON.parse( next );
			if ( parsed === null || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
				setError( __( 'Must be a JSON object ( { … } ).', 'nvoos-pro-spa' ) );
				return;
			}
			setError( null );
			onChange( parsed as Record< string, unknown > );
		} catch {
			// Keep the previous value — the node only receives valid JSON.
			setError( __( 'Invalid JSON — the node keeps its previous value until this parses.', 'nvoos-pro-spa' ) );
		}
	};

	return (
		<div className="nvoos-pro-spa-wf-editor__field">
			<label className="nvoos-pro-spa-wf-editor__label" htmlFor={ id }>
				{ label }
			</label>
			<textarea
				id={ id }
				className="nvoos-pro-spa-wf-editor__json"
				rows={ 5 }
				spellCheck={ false }
				value={ text }
				onChange={ ( event ) => handleChange( event.target.value ) }
			/>
			{ error && (
				<span className="nvoos-pro-spa-wf-editor__json-error" role="alert">
					{ error }
				</span>
			) }
		</div>
	);
}

export interface NodeEditorProps {
	node: CanvasWorkflowNode;
	/** Merge a patch into the selected node's data. */
	onPatch: ( patch: Partial< WorkflowNodeData > ) => void;
}

export function NodeEditor( { node, onPatch }: NodeEditorProps ): JSX.Element {
	const data = node.data ?? {};
	const nodeType = node.type ?? 'toolNode';
	// Preset graphs may carry node kinds outside the execution trio
	// (input/output/condition) — those round-trip through data.rawType and
	// offer no executable configuration to edit.
	const isPassthrough = typeof data.rawType === 'string' && data.rawType.length > 0;

	const textValue = ( key: string ): string => {
		const value = data[ key ];
		return typeof value === 'string' ? value : '';
	};

	return (
		<div className="nvoos-pro-spa-wf-editor" aria-label={ __( 'Selected node configuration', 'nvoos-pro-spa' ) }>
			<div className="nvoos-pro-spa-wf-editor__field">
				<label className="nvoos-pro-spa-wf-editor__label" htmlFor="wf-node-label">
					{ __( 'Label', 'nvoos-pro-spa' ) }
				</label>
				<input
					id="wf-node-label"
					type="text"
					className="nvoos-pro-spa-assistants-form__input"
					value={ textValue( 'label' ) }
					onChange={ ( event ) => onPatch( { label: event.target.value } ) }
				/>
			</div>

			{ isPassthrough && (
				<p className="nvoos-pro-spa-wf-editor__hint">
					{ __(
						'This node comes from a preset (a non-executable node kind). Only its label can be edited here.',
						'nvoos-pro-spa'
					) }
				</p>
			) }

			{ ! isPassthrough && nodeType === 'toolNode' && (
				<>
					<div className="nvoos-pro-spa-wf-editor__field">
						<label className="nvoos-pro-spa-wf-editor__label" htmlFor="wf-node-tool-slug">
							{ __( 'Tool slug', 'nvoos-pro-spa' ) }
						</label>
						<input
							id="wf-node-tool-slug"
							type="text"
							className="nvoos-pro-spa-assistants-form__input"
							placeholder={ __( 'e.g. web_search', 'nvoos-pro-spa' ) }
							value={ textValue( 'toolSlug' ) }
							onChange={ ( event ) => onPatch( { toolSlug: event.target.value } ) }
						/>
						<span className="nvoos-pro-spa-wf-editor__hint">
							{ __(
								'Required for “Run node” — the tool slug from the Tools page.',
								'nvoos-pro-spa'
							) }
						</span>
					</div>
					<JsonParamsField
						id="wf-node-tool-arguments"
						label={ __( 'Arguments (JSON)', 'nvoos-pro-spa' ) }
						value={ data.arguments }
						onChange={ ( next ) => onPatch( { arguments: next } ) }
					/>
				</>
			) }

			{ ! isPassthrough && nodeType === 'actionNode' && (
				<>
					<div className="nvoos-pro-spa-wf-editor__field">
						<label className="nvoos-pro-spa-wf-editor__label" htmlFor="wf-node-command">
							{ __( 'Command', 'nvoos-pro-spa' ) }
						</label>
						<input
							id="wf-node-command"
							type="text"
							className="nvoos-pro-spa-assistants-form__input"
							placeholder={ __( 'e.g. notify', 'nvoos-pro-spa' ) }
							value={ textValue( 'command' ) }
							onChange={ ( event ) => onPatch( { command: event.target.value } ) }
						/>
						<span className="nvoos-pro-spa-wf-editor__hint">
							{ __( 'Required for “Run node”.', 'nvoos-pro-spa' ) }
						</span>
					</div>
					<JsonParamsField
						id="wf-node-action-params"
						label={ __( 'Params (JSON)', 'nvoos-pro-spa' ) }
						value={ data.params }
						onChange={ ( next ) => onPatch( { params: next } ) }
					/>
				</>
			) }

			{ ! isPassthrough && nodeType === 'agentNode' && (
				<>
					<div className="nvoos-pro-spa-wf-editor__field">
						<label className="nvoos-pro-spa-wf-editor__label" htmlFor="wf-node-agent-id">
							{ __( 'Agent ID', 'nvoos-pro-spa' ) }
						</label>
						<input
							id="wf-node-agent-id"
							type="text"
							className="nvoos-pro-spa-assistants-form__input"
							placeholder={ __( 'default', 'nvoos-pro-spa' ) }
							value={ textValue( 'agentId' ) }
							onChange={ ( event ) => onPatch( { agentId: event.target.value } ) }
						/>
					</div>
					<div className="nvoos-pro-spa-wf-editor__field">
						<label className="nvoos-pro-spa-wf-editor__label" htmlFor="wf-node-prompt">
							{ __( 'Prompt', 'nvoos-pro-spa' ) }
						</label>
						<textarea
							id="wf-node-prompt"
							className="nvoos-pro-spa-wf-editor__json"
							rows={ 4 }
							value={ textValue( 'prompt' ) }
							onChange={ ( event ) => onPatch( { prompt: event.target.value } ) }
						/>
						<span className="nvoos-pro-spa-wf-editor__hint">
							{ __( 'Required for “Run node”.', 'nvoos-pro-spa' ) }
						</span>
					</div>
				</>
			) }
		</div>
	);
}
