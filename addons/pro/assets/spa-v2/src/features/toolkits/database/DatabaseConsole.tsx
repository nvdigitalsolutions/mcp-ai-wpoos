// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Database console — the toolkit's `console` screen.
 *
 * Four tabs over one overview payload: Connections (what can be reached, and
 * with what role), Scopes (what is granted, and the editor that changes it),
 * Activity (what actually happened) and Health (the posture). Connections,
 * Activity and Health are read-only; Scopes owns the grant CRUD and a
 * compile-only preview, both of which go through the server's registry.
 *
 * The endpoint is its own key (`endpoints.database`) rather than derived from
 * the toolkit base, so a runtime without the console reports the key
 * `runtime-adaptable` and this screen renders its unavailable state instead of
 * guessing a URL.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX, type KeyboardEvent } from 'react';
import { __ } from '@wordpress/i18n';

import { DatabaseClient, type DatabaseOverview } from '../../../api/database';
import { useBootstrap } from '../../../hooks/useBootstrap';
import { ActivityPanel } from './ActivityPanel';
import { ConnectionsPanel } from './ConnectionsPanel';
import { HealthPanel } from './HealthPanel';
import { ScopesPanel } from './ScopesPanel';
import { Notice } from './shared';
import './database-console.css';

type Tab = 'connections' | 'scopes' | 'activity' | 'health';

const TABS: Array< { id: Tab; label: string; icon: string } > = [
	{ id: 'connections', label: __( 'Connections', 'nvoos-pro-spa' ), icon: '🔌' },
	{ id: 'scopes', label: __( 'Scopes', 'nvoos-pro-spa' ), icon: '🔐' },
	{ id: 'activity', label: __( 'Activity', 'nvoos-pro-spa' ), icon: '📜' },
	{ id: 'health', label: __( 'Health', 'nvoos-pro-spa' ), icon: '❤️' },
];

export function DatabaseConsole(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const [ tab, setTab ] = useState< Tab >( 'connections' );
	const [ overview, setOverview ] = useState< DatabaseOverview | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const abort = useRef< AbortController | null >( null );

	const client = useMemo(
		() =>
			new DatabaseClient( {
				endpoint: runtime?.endpoints.database ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ]
	);

	const load = useCallback(
		async ( signal?: AbortSignal, background = false ): Promise< void > => {
			if ( ! client.available ) {
				setLoading( false );
				return;
			}

			// A refresh after a write must not blank the panel: the editor's
			// confirmation lives in a child, and unmounting it would discard it.
			if ( ! background ) {
				setLoading( true );
			}

			setError( null );

			try {
				setOverview( await client.overview( signal ) );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof Error ? cause.message : __( 'Unable to load the database console.', 'nvoos-pro-spa' )
				);
			} finally {
				setLoading( false );
			}
		},
		[ client ]
	);

	useEffect( () => {
		if ( bootstrapLoading ) {
			return;
		}

		abort.current?.abort();

		const controller = new AbortController();
		abort.current = controller;

		void load( controller.signal );

		return () => controller.abort();
	}, [ bootstrapLoading, load ] );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-db-panel" aria-busy="true">
				<div className="nvoos-db-panel__loading" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Reading the toolkit state…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! client.available ) {
		return (
			<Notice tone="info" title={ __( 'The database console is not served on this runtime', 'nvoos-pro-spa' ) }>
				<p>
					{ __(
						'Connections and scopes live in configuration files on the Laravel runtime, and this console is a WordPress surface. The settings screen remains available.',
						'nvoos-pro-spa'
					) }
				</p>
			</Notice>
		);
	}

	if ( error !== null || overview === null ) {
		return (
			<Notice tone="bad" title={ __( 'The console could not load', 'nvoos-pro-spa' ) }>
				<p>{ error ?? __( 'The overview request returned nothing.', 'nvoos-pro-spa' ) }</p>
				<button type="button" className="nvoos-db-btn" onClick={ () => void load() }>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</Notice>
		);
	}

	const onKeyDown = ( event: KeyboardEvent< HTMLButtonElement > ): void => {
		const index = TABS.findIndex( ( item ) => item.id === tab );
		let next = index;

		if ( event.key === 'ArrowRight' ) {
			next = ( index + 1 ) % TABS.length;
		} else if ( event.key === 'ArrowLeft' ) {
			next = ( index - 1 + TABS.length ) % TABS.length;
		} else if ( event.key === 'Home' ) {
			next = 0;
		} else if ( event.key === 'End' ) {
			next = TABS.length - 1;
		} else {
			return;
		}

		event.preventDefault();
		setTab( TABS[ next ].id );
	};

	return (
		<div className="nvoos-db-console">
			<div
				className="nvoos-db-tabs"
				role="tablist"
				aria-label={ __( 'Database console', 'nvoos-pro-spa' ) }
			>
				{ TABS.map( ( item ) => {
					const selected = item.id === tab;

					return (
						<button
							key={ item.id }
							type="button"
							role="tab"
							id={ `nvoos-db-tab-${ item.id }` }
							aria-selected={ selected }
							aria-controls={ `nvoos-db-panel-${ item.id }` }
							tabIndex={ selected ? 0 : -1 }
							className={ `nvoos-db-tab${ selected ? ' is-active' : '' }` }
							onClick={ () => setTab( item.id ) }
							onKeyDown={ onKeyDown }
						>
							<span aria-hidden="true">{ item.icon }</span>
							{ item.label }
						</button>
					);
				} ) }
			</div>

			<div
				className="nvoos-db-panel"
				role="tabpanel"
				id={ `nvoos-db-panel-${ tab }` }
				aria-labelledby={ `nvoos-db-tab-${ tab }` }
				tabIndex={ 0 }
			>
				{ tab === 'connections' && <ConnectionsPanel client={ client } overview={ overview } /> }
				{ tab === 'scopes' && (
					<ScopesPanel
						client={ client }
						overview={ overview }
						onRefresh={ () => void load( undefined, true ) }
					/>
				) }
				{ tab === 'activity' && <ActivityPanel client={ client } overview={ overview } /> }
				{ tab === 'health' && <HealthPanel client={ client } overview={ overview } /> }
			</div>
		</div>
	);
}
