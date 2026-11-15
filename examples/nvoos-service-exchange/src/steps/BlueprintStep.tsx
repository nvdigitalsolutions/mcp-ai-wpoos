import { useEffect, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiClientError } from '../api/client';
import { useWizardStore } from '../state/wizardStore';
import type { Blueprint } from '../types';

export function BlueprintStep( { client }: { client: ApiClient } ) {
	const selected = useWizardStore( ( state ) => state.seed );
	const selectSeed = useWizardStore( ( state ) => state.selectSeed );
	const [ blueprints, setBlueprints ] = useState<Blueprint[]>( [] );
	const [ error, setError ] = useState<string | null>( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let active = true;
		client
			.blueprints()
			.then( ( seeds ) => {
				if ( active ) {
					setBlueprints( seeds );
					setLoading( false );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( active ) {
					setError( caught instanceof ApiClientError ? caught.message : 'Could not load blueprints' );
					setLoading( false );
				}
			} );
		return () => {
			active = false;
		};
	}, [ client ] );

	return (
		<section aria-labelledby="step-blueprint">
			<h2 id="step-blueprint">Choose a blueprint</h2>
			<p className="lede">
				A blueprint is an assistant bundle plus its provisioning and commerce manifest.
				Pick a profession template, then tune it in the next step.
			</p>
			{ loading ? <p role="status">Loading blueprints…</p> : null }
			{ error ? (
				<p className="error" role="alert">
					{ error }
				</p>
			) : null }
			<ul className="cards" aria-label="Blueprint gallery">
				{ blueprints.map( ( blueprint ) => {
					const active = selected?.slug === blueprint.slug;
					return (
						<li key={ blueprint.slug }>
							<button
								type="button"
								className={ `card${ active ? ' card--active' : '' }` }
								aria-pressed={ active }
								onClick={ () => selectSeed( blueprint ) }
							>
								<span className="card__title">{ blueprint.name }</span>
								<span className="card__tagline">{ blueprint.tagline }</span>
								<span className="card__meta">
									{ blueprint.industry } · { blueprint.bundle.assistants.length } assistant
									{ blueprint.bundle.assistants.length === 1 ? '' : 's' } · gateway { ' ' }
									{ blueprint.gateway.visibility }
								</span>
							</button>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
}
