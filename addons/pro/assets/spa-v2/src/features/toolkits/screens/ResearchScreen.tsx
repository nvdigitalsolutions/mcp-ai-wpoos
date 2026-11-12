/**
 * ResearchScreen — create-from-query (090-b Phase 4.5).
 *
 * The draft is generated server-side from the manifest's own column schema and
 * returned for review; nothing is written until the operator saves. Saving goes
 * through `createRecord`, so the manifest's validation stays the single gate
 * into the table, and a field the model omitted is simply left for the
 * workbench.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import { ToolkitsApiError, type ToolkitColumn } from '../../../api/toolkits';
import type { ToolkitScreenProps } from '../ToolkitPage';

type Status = 'idle' | 'drafting' | 'saving';

export function ResearchScreen( { client, manifest, onNavigateScreen }: ToolkitScreenProps ): JSX.Element {
	const entities = manifest.entities;
	const [ entityKey, setEntityKey ] = useState< string >( entities[ 0 ]?.key ?? '' );
	const [ query, setQuery ] = useState< string >( '' );
	const [ draft, setDraft ] = useState< Record< string, unknown > | null >( null );
	const [ provider, setProvider ] = useState< string >( '' );
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ error, setError ] = useState< string | null >( null );

	const entity = entities.find( ( candidate ) => candidate.key === entityKey );
	const columns: ToolkitColumn[] = entity?.columns ?? [];

	const draftFields = draft
		? columns.filter( ( column ) => Object.prototype.hasOwnProperty.call( draft, column.key ) )
		: [];

	const busy = status !== 'idle';
	const canDraft = ! busy && entityKey !== '' && query.trim() !== '';

	const draftWith = async (): Promise< void > => {
		if ( ! canDraft ) {
			return;
		}

		setStatus( 'drafting' );
		setError( null );
		setDraft( null );

		try {
			const result = await client.research( manifest.slug, entityKey, query.trim() );
			setDraft( result.draft );
			setProvider( result.provider );
		} catch ( err ) {
			setError(
				err instanceof ToolkitsApiError
					? err.message
					: __(
							'The draft could not be generated. Add the record manually instead.',
							'nvoos-pro-spa'
					  )
			);
		} finally {
			setStatus( 'idle' );
		}
	};

	const saveDraft = async (): Promise< void > => {
		if ( ! draft || busy ) {
			return;
		}

		setStatus( 'saving' );
		setError( null );

		try {
			await client.createRecord( manifest.slug, entityKey, draft );
			setDraft( null );
			onNavigateScreen?.( 'entities' );
		} catch ( err ) {
			setError(
				err instanceof ToolkitsApiError
					? err.message
					: __( 'The record could not be saved.', 'nvoos-pro-spa' )
			);
		} finally {
			setStatus( 'idle' );
		}
	};

	return (
		<div className="nvoos-pro-spa-toolkits__research">
			<div className="nvoos-pro-spa-toolkits__research-card">
				<span className="nvoos-pro-spa-toolkits__research-icon" aria-hidden="true">
					🔎
				</span>

				<h3 className="nvoos-pro-spa-toolkits__research-title">
					{ __( 'Research & add', 'nvoos-pro-spa' ) }
				</h3>

				<p className="nvoos-pro-spa-toolkits__research-copy">
					{ __(
						'Describe what you want to add and the toolkit will draft the fields for review.',
						'nvoos-pro-spa'
					) }
				</p>

				<div className="nvoos-pro-spa-toolkits__field">
					<label className="nvoos-pro-spa-toolkits__label" htmlFor="nvoos-toolkit-research-entity">
						{ __( 'Record type', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-toolkit-research-entity"
						className="nvoos-pro-spa-toolkits__input"
						value={ entityKey }
						disabled={ busy }
						onChange={ ( event ) => {
							setEntityKey( event.target.value );
							setDraft( null );
						} }
					>
						{ entities.map( ( candidate ) => (
							<option key={ candidate.key } value={ candidate.key }>
								{ candidate.label }
							</option>
						) ) }
					</select>
				</div>

				<div className="nvoos-pro-spa-toolkits__field">
					<label className="nvoos-pro-spa-toolkits__label" htmlFor="nvoos-toolkit-research-query">
						{ __( 'What should it find?', 'nvoos-pro-spa' ) }
					</label>
					<textarea
						id="nvoos-toolkit-research-query"
						className="nvoos-pro-spa-toolkits__input nvoos-pro-spa-toolkits__textarea"
						rows={ 3 }
						placeholder={ __(
							'e.g. “the current price and stock level for SKU A-100”',
							'nvoos-pro-spa'
						) }
						value={ query }
						disabled={ busy }
						onChange={ ( event ) => setQuery( event.target.value ) }
					/>
				</div>

				{ error && (
					<div className="nvoos-pro-spa-toolkits__notice" role="alert">
						<p>{ error }</p>
						<button
							type="button"
							className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
							onClick={ () => onNavigateScreen?.( 'entities' ) }
						>
							{ __( 'Open the workbench', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				{ draft && draftFields.length > 0 && (
					<div className="nvoos-pro-spa-toolkits__draft" aria-live="polite">
						<h4 className="nvoos-pro-spa-toolkits__draft-title">
							{ __( 'Draft for review', 'nvoos-pro-spa' ) }
							{ provider !== '' && (
								<span className="nvoos-pro-spa-toolkits__draft-provider"> · { provider }</span>
							) }
						</h4>

						<dl className="nvoos-pro-spa-toolkits__draft-list">
							{ draftFields.map( ( column ) => (
								<div className="nvoos-pro-spa-toolkits__draft-row" key={ column.key }>
									<dt>{ column.label }</dt>
									<dd>{ formatDraftValue( draft[ column.key ] ) }</dd>
								</div>
							) ) }
						</dl>

						<p className="nvoos-pro-spa-toolkits__draft-hint">
							{ __(
								'Only the fields the query determined are filled in. Edit the record in the workbench after saving.',
								'nvoos-pro-spa'
							) }
						</p>
					</div>
				) }

				<div className="nvoos-pro-spa-toolkits__form-actions">
					{ draft && draftFields.length > 0 ? (
						<>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__btn"
								disabled={ busy }
								onClick={ () => void saveDraft() }
							>
								{ status === 'saving'
									? __( 'Saving…', 'nvoos-pro-spa' )
									: __( 'Save record', 'nvoos-pro-spa' ) }
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
								disabled={ busy }
								onClick={ () => setDraft( null ) }
							>
								{ __( 'Discard', 'nvoos-pro-spa' ) }
							</button>
						</>
					) : (
						<>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__btn"
								disabled={ ! canDraft }
								onClick={ () => void draftWith() }
							>
								{ status === 'drafting'
									? __( 'Drafting…', 'nvoos-pro-spa' )
									: __( 'Draft with AI', 'nvoos-pro-spa' ) }
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
								onClick={ () => onNavigateScreen?.( 'entities' ) }
							>
								{ __( 'Add manually', 'nvoos-pro-spa' ) }
							</button>
						</>
					) }
				</div>
			</div>
		</div>
	);
}

function formatDraftValue( value: unknown ): string {
	if ( value === null || value === undefined ) {
		return '—';
	}

	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}

	return String( value );
}
