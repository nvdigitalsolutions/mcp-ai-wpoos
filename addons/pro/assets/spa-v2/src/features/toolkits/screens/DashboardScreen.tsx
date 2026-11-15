/**
 * DashboardScreen — the manifest's aggregated cards.
 *
 * The engine computes each figure server-side (`count` / `sum` / `avg` /
 * `min` / `max`, with the card's filter applied), so this screen only
 * formats and lays them out.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useEffect, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import {
	ToolkitsApiError,
	type ToolkitDashboardCardResult,
} from '../../../api/toolkits';
import { formatMetric } from '../format';
import type { ToolkitScreenProps } from '../ToolkitPage';

const AGGREGATE_LABELS: Record< string, string > = {
	count: __( 'count', 'nvoos-pro-spa' ),
	sum: __( 'sum', 'nvoos-pro-spa' ),
	avg: __( 'average', 'nvoos-pro-spa' ),
	min: __( 'minimum', 'nvoos-pro-spa' ),
	max: __( 'maximum', 'nvoos-pro-spa' ),
};

export function DashboardScreen( { client, manifest }: ToolkitScreenProps ): JSX.Element {
	const [ cards, setCards ] = useState< ToolkitDashboardCardResult[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback(
		async ( signal?: AbortSignal ): Promise< void > => {
			setLoading( true );
			setError( null );

			try {
				setCards( await client.dashboard( manifest.slug, signal ) );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to load the dashboard.', 'nvoos-pro-spa' ),
				);
			} finally {
				setLoading( false );
			}
		},
		[ client, manifest.slug ],
	);

	useEffect( () => {
		const controller = new AbortController();
		void load( controller.signal );

		return () => controller.abort();
	}, [ load ] );

	if ( loading && cards.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-toolkits__panel-loading" role="status">
				<span className="nvoos-pro-spa-page__spinner" />
				{ __( 'Computing figures…', 'nvoos-pro-spa' ) }
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
				<p>{ error }</p>
				<button type="button" className="nvoos-pro-spa-page__retry" onClick={ () => void load() }>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	if ( cards.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-toolkits__empty" role="status">
				<span className="nvoos-pro-spa-toolkits__empty-icon" aria-hidden="true">
					📊
				</span>
				<h3>{ __( 'No dashboard cards', 'nvoos-pro-spa' ) }</h3>
				<p>{ __( 'This toolkit does not declare any figures yet.', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-toolkits__stats">
			{ cards.map( ( card ) => (
				<article key={ card.key } className="nvoos-pro-spa-toolkits__stat">
					<span className="nvoos-pro-spa-toolkits__stat-label">{ card.label }</span>
					<strong className="nvoos-pro-spa-toolkits__stat-value">{ formatMetric( card.value ) }</strong>
					<span className="nvoos-pro-spa-toolkits__stat-meta">
						{ AGGREGATE_LABELS[ card.aggregate ] ?? card.aggregate }
					</span>
				</article>
			) ) }
		</div>
	);
}
