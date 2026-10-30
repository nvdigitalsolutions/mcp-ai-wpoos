/**
 * WorkflowDslImport — DSL paste/import panel with dry-run findings
 * (Proposal 091, Track A).
 *
 * Pure presentational component: parses nothing, calls `onSubmit` with the
 * raw text and the selected source; renders the findings table and the
 * blockers warning from the props the page feeds back.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { hasBlockers, type DslFinding } from '../../api/workflow-dsl';

interface WorkflowDslImportProps {
	onSubmit: ( text: string, source: 'native' | 'dify' ) => void;
	busy: boolean;
	findings: DslFinding[] | null;
	feedback: string | null;
	/** Tone for the feedback box — success imports use the success style. */
	feedbackTone?: 'error' | 'success';
}

export function WorkflowDslImport( {
	onSubmit,
	busy,
	findings,
	feedback,
	feedbackTone = 'error',
}: WorkflowDslImportProps ): JSX.Element {
	const [ text, setText ] = useState( '' );
	const [ source, setSource ] = useState< 'native' | 'dify' >( 'native' );

	return (
		<section className="nvoos-pro-spa-dsl-import" aria-label={ __( 'Import workflow DSL', 'nvoos-pro-spa' ) }>
			<textarea
				className="nvoos-pro-spa-dsl-import__input"
				value={ text }
				onChange={ ( event ) => setText( event.target.value ) }
				placeholder={ __( 'Paste a workflow DSL (JSON)…', 'nvoos-pro-spa' ) }
				rows={ 6 }
				disabled={ busy }
			/>
			<div className="nvoos-pro-spa-dsl-import__controls">
				<label>
					<input
						type="checkbox"
						checked={ source === 'dify' }
						onChange={ ( event ) => setSource( event.target.checked ? 'dify' : 'native' ) }
					/>
					{ __( 'Dify import (adapter)', 'nvoos-pro-spa' ) }
				</label>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ busy || ! text.trim() }
					onClick={ () => onSubmit( text, source ) }
				>
					{ __( 'Validate & import', 'nvoos-pro-spa' ) }
				</button>
			</div>
			{ feedback && (
				<div
					className={
						feedbackTone === 'success'
							? 'nvoos-pro-spa-page__message nvoos-pro-spa-page__message--success'
							: 'nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error'
					}
					role="alert"
				>
					<p>{ feedback }</p>
				</div>
			) }
			{ findings && (
				<table className="nvoos-pro-spa-dsl-import__findings">
					<thead>
						<tr>
							<th>{ __( 'Severity', 'nvoos-pro-spa' ) }</th>
							<th>{ __( 'Path', 'nvoos-pro-spa' ) }</th>
							<th>{ __( 'Message', 'nvoos-pro-spa' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ findings.map( ( finding, index ) => (
							<tr key={ `${ finding.path }-${ index }` } data-severity={ finding.severity }>
								<td>{ finding.severity }</td>
								<td>
									<code>{ finding.path }</code>
								</td>
								<td>{ finding.message }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ findings && hasBlockers( findings ) && (
				<p className="nvoos-pro-spa-dsl-import__blocked" role="alert">
					{ __( 'Errors block import — fix them and re-validate.', 'nvoos-pro-spa' ) }
				</p>
			) }
		</section>
	);
}
