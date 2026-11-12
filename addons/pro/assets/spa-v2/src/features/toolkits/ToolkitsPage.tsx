/**
 * ToolkitsPage — the manifest-driven Pro toolkit catalogue.
 *
 * Lists the toolkits this session is entitled to (GET …/toolkits) and links
 * into each one's generic screens. Invalid manifests are surfaced here so an
 * operator can see why a family did not appear.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { Link } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

import { useBootstrap } from '../../hooks/useBootstrap';
import { ToolkitsClient, ToolkitsApiError, type ToolkitSummary } from '../../api/toolkits';

export function ToolkitsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const [ toolkits, setToolkits ] = useState< ToolkitSummary[] >( [] );
	const [ invalid, setInvalid ] = useState< Record< string, string[] > >( {} );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const client = useMemo(
		() =>
			new ToolkitsClient( {
				endpoint: runtime?.endpoints.toolkits ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ],
	);

	const load = useCallback(
		async ( signal?: AbortSignal ): Promise< void > => {
			if ( ! client.available ) {
				setLoading( false );
				return;
			}

			setLoading( true );
			setError( null );

			try {
				const result = await client.list( signal );
				setToolkits( result.toolkits );
				setInvalid( result.invalid );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to load the toolkit catalogue.', 'nvoos-pro-spa' ),
				);
			} finally {
				setLoading( false );
			}
		},
		[ client ],
	);

	useEffect( () => {
		if ( bootstrapLoading ) {
			return;
		}

		const controller = new AbortController();
		void load( controller.signal );

		return () => controller.abort();
	}, [ bootstrapLoading, load ] );

	if ( bootstrapLoading || ( loading && toolkits.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Toolkits', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading toolkits…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	const invalidFiles = Object.entries( invalid );

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-toolkits">
			<header className="nvoos-pro-spa-toolkits__header">
				<div>
					<h2 className="nvoos-pro-spa-page__title">{ __( 'Toolkits', 'nvoos-pro-spa' ) }</h2>
					<p className="nvoos-pro-spa-page__subtitle">
						{ __(
							'Families described by a manifest. Each one renders the same settings, records and dashboard screens.',
							'nvoos-pro-spa',
						) }
					</p>
				</div>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button type="button" className="nvoos-pro-spa-page__retry" onClick={ () => void load() }>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ invalidFiles.length > 0 && (
				<aside className="nvoos-pro-spa-toolkits__invalid" role="status">
					<h3 className="nvoos-pro-spa-toolkits__invalid-title">
						{ __( 'Manifests rejected', 'nvoos-pro-spa' ) }
					</h3>
					<p className="nvoos-pro-spa-toolkits__invalid-copy">
						{ __(
							'These files did not validate and were not loaded. Fix the reported fields to publish them.',
							'nvoos-pro-spa',
						) }
					</p>
					<ul className="nvoos-pro-spa-toolkits__invalid-list">
						{ invalidFiles.map( ( [ file, messages ] ) => (
							<li key={ file }>
								<code>{ file }</code>
								<span>{ messages.join( '; ' ) }</span>
							</li>
						) ) }
					</ul>
				</aside>
			) }

			{ ! error && toolkits.length === 0 ? (
				<div className="nvoos-pro-spa-toolkits__empty" role="status">
					<span className="nvoos-pro-spa-toolkits__empty-icon" aria-hidden="true">
						🧩
					</span>
					<h3>{ __( 'No toolkits yet', 'nvoos-pro-spa' ) }</h3>
					<p>
						{ client.available
							? __(
									'This session is not entitled to any toolkit, or no manifests are installed.',
									'nvoos-pro-spa',
							  )
							: __( 'The toolkit engine is not available on this runtime.', 'nvoos-pro-spa' ) }
					</p>
				</div>
			) : (
				<ul className="nvoos-pro-spa-toolkits__grid">
					{ toolkits.map( ( toolkit ) => (
						<li key={ toolkit.slug } className="nvoos-pro-spa-toolkits__card-item">
							<Link
								to={ `/toolkits/${ encodeURIComponent( toolkit.slug ) }` }
								className="nvoos-pro-spa-toolkits__card"
							>
								<span className="nvoos-pro-spa-toolkits__card-icon" aria-hidden="true">
									{ toolkit.icon || '🧩' }
								</span>

								<span className="nvoos-pro-spa-toolkits__card-body">
									<span className="nvoos-pro-spa-toolkits__card-title-row">
										<span className="nvoos-pro-spa-toolkits__card-title">{ toolkit.name }</span>
										<span className="nvoos-pro-spa-toolkits__version">v{ toolkit.version }</span>
									</span>

									{ toolkit.description && (
										<span className="nvoos-pro-spa-toolkits__card-copy">
											{ toolkit.description }
										</span>
									) }

									{ toolkit.entities.length > 0 && (
										<span className="nvoos-pro-spa-toolkits__chips">
											{ toolkit.entities.map( ( entity ) => (
												<span key={ entity.key } className="nvoos-pro-spa-toolkits__chip">
													<span aria-hidden="true">{ entity.icon || '•' }</span>
													{ entity.label }
												</span>
											) ) }
										</span>
									) }
								</span>

								<span className="nvoos-pro-spa-toolkits__card-cta" aria-hidden="true">
									{ __( 'Open', 'nvoos-pro-spa' ) }
									<span className="nvoos-pro-spa-toolkits__card-arrow">→</span>
								</span>
							</Link>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
