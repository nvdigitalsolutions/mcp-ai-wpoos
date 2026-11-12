/**
 * ResearchScreen — create-from-research entry point.
 *
 * The drafting engine (Pro `deep_research` → column mapping) is part of the
 * toolkit service layer and is not wired on this runtime yet. Rather than
 * fake a draft, the screen explains that and hands the operator to the
 * workbench with the query preserved as context.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import type { ToolkitScreenProps } from '../ToolkitPage';

export function ResearchScreen( { manifest, onNavigateScreen }: ToolkitScreenProps ): JSX.Element {
	const [ query, setQuery ] = useState< string >( '' );
	const [ entityKey, setEntityKey ] = useState< string >( manifest.entities[ 0 ]?.key ?? '' );
	const [ notice, setNotice ] = useState< boolean >( false );

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
						'nvoos-pro-spa',
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
						onChange={ ( event ) => setEntityKey( event.target.value ) }
					>
						{ manifest.entities.map( ( entity ) => (
							<option key={ entity.key } value={ entity.key }>
								{ entity.label }
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
							'nvoos-pro-spa',
						) }
						value={ query }
						onChange={ ( event ) => setQuery( event.target.value ) }
					/>
				</div>

				{ notice && (
					<div className="nvoos-pro-spa-toolkits__notice" role="status">
						<p>
							{ __(
								'AI drafting is not available on this runtime yet. Add the record manually in the workbench — the fields are already defined by the manifest.',
								'nvoos-pro-spa',
							) }
						</p>
						<button
							type="button"
							className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
							onClick={ () => onNavigateScreen?.( 'entities' ) }
						>
							{ __( 'Open the workbench', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				<div className="nvoos-pro-spa-toolkits__form-actions">
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn"
						onClick={ () => setNotice( true ) }
					>
						{ __( 'Draft with AI', 'nvoos-pro-spa' ) }
					</button>

					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
						onClick={ () => onNavigateScreen?.( 'entities' ) }
					>
						{ __( 'Add manually', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>
		</div>
	);
}
