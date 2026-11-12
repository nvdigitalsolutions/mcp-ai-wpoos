/**
 * ToolkitPage — the shell for one manifest-driven toolkit.
 *
 * Loads the manifest, renders the header, and switches between the screens
 * the manifest declares (`settings`, `entities`, `research`, `consolidate`,
 * `dashboard`). Screens own their data; the shell only owns the manifest and
 * the active tab.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { Link, useParams } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

import { useBootstrap } from '../../hooks/useBootstrap';
import { ToolkitsClient, ToolkitsApiError, type ToolkitManifest } from '../../api/toolkits';
import { SettingsScreen } from './screens/SettingsScreen';
import { EntitiesScreen } from './screens/EntitiesScreen';
import { DashboardScreen } from './screens/DashboardScreen';
import { ResearchScreen } from './screens/ResearchScreen';
import { ConsolidateScreen } from './screens/ConsolidateScreen';

/** Screen id → label + glyph. Order here is the tab order. */
const SCREEN_META: Record< string, { label: string; icon: string } > = {
	settings: { label: __( 'Settings', 'nvoos-pro-spa' ), icon: '⚙️' },
	entities: { label: __( 'Workbench', 'nvoos-pro-spa' ), icon: '🗂️' },
	research: { label: __( 'Research & add', 'nvoos-pro-spa' ), icon: '🔎' },
	consolidate: { label: __( 'Consolidate', 'nvoos-pro-spa' ), icon: '🧹' },
	dashboard: { label: __( 'Dashboard', 'nvoos-pro-spa' ), icon: '📊' },
};

export interface ToolkitScreenProps {
	client: ToolkitsClient;
	manifest: ToolkitManifest;
	/** Switch to another screen the manifest declares. */
	onNavigateScreen?: ( screen: string ) => void;
}

export function ToolkitPage(): JSX.Element {
	const { slug = '' } = useParams< { slug: string } >();
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const [ manifest, setManifest ] = useState< ToolkitManifest | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ activeScreen, setActiveScreen ] = useState< string >( '' );

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
			if ( ! client.available || slug === '' ) {
				setLoading( false );
				return;
			}

			setLoading( true );
			setError( null );

			try {
				const next = await client.show( slug, signal );
				setManifest( next );
				setActiveScreen( ( current ) =>
					current !== '' && next.screens.includes( current )
						? current
						: next.screens[ 0 ] ?? 'settings',
				);
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to load this toolkit.', 'nvoos-pro-spa' ),
				);
			} finally {
				setLoading( false );
			}
		},
		[ client, slug ],
	);

	useEffect( () => {
		if ( bootstrapLoading ) {
			return;
		}

		const controller = new AbortController();
		void load( controller.signal );

		return () => controller.abort();
	}, [ bootstrapLoading, load ] );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading toolkit…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( error !== null || manifest === null ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Toolkit unavailable', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ error ?? __( 'The toolkit could not be loaded.', 'nvoos-pro-spa' ) }
				</p>
				<p>
					<Link className="nvoos-pro-spa-toolkits__back" to="/toolkits">
						{ __( '← Back to toolkits', 'nvoos-pro-spa' ) }
					</Link>
				</p>
				<button type="button" className="nvoos-pro-spa-page__retry" onClick={ () => void load() }>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const screens = manifest.screens.filter( ( screen ) => SCREEN_META[ screen ] );

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-toolkits">
			<nav className="nvoos-pro-spa-toolkits__crumbs" aria-label={ __( 'Breadcrumb', 'nvoos-pro-spa' ) }>
				<Link to="/toolkits">{ __( 'Toolkits', 'nvoos-pro-spa' ) }</Link>
				<span aria-hidden="true">/</span>
				<span aria-current="page">{ manifest.name }</span>
			</nav>

			<header className="nvoos-pro-spa-toolkits__hero">
				<span className="nvoos-pro-spa-toolkits__hero-icon" aria-hidden="true">
					{ manifest.icon || '🧩' }
				</span>

				<div className="nvoos-pro-spa-toolkits__hero-body">
					<div className="nvoos-pro-spa-toolkits__hero-title-row">
						<h2 className="nvoos-pro-spa-page__title">{ manifest.name }</h2>
						<span className="nvoos-pro-spa-toolkits__version">v{ manifest.version }</span>
					</div>

					{ manifest.description && (
						<p className="nvoos-pro-spa-page__subtitle">{ manifest.description }</p>
					) }
				</div>
			</header>

			{ screens.length > 1 && (
				<div className="nvoos-pro-spa-toolkits__tabs" role="tablist" aria-label={ __( 'Toolkit screens', 'nvoos-pro-spa' ) }>
					{ screens.map( ( screen ) => {
						const meta = SCREEN_META[ screen ];
						const selected = screen === activeScreen;

						return (
							<button
								key={ screen }
								type="button"
								role="tab"
								id={ `nvoos-toolkit-tab-${ screen }` }
								aria-selected={ selected }
								aria-controls={ `nvoos-toolkit-panel-${ screen }` }
								tabIndex={ selected ? 0 : -1 }
								className="nvoos-pro-spa-toolkits__tab"
								onClick={ () => setActiveScreen( screen ) }
							>
								<span aria-hidden="true">{ meta.icon }</span>
								{ meta.label }
							</button>
						);
					} ) }
				</div>
			) }

			<section
				className="nvoos-pro-spa-toolkits__panel"
				role="tabpanel"
				id={ `nvoos-toolkit-panel-${ activeScreen }` }
				aria-labelledby={ `nvoos-toolkit-tab-${ activeScreen }` }
			>
				{ activeScreen === 'settings' && (
					<SettingsScreen client={ client } manifest={ manifest } onNavigateScreen={ setActiveScreen } />
				) }
				{ activeScreen === 'entities' && (
					<EntitiesScreen client={ client } manifest={ manifest } onNavigateScreen={ setActiveScreen } />
				) }
				{ activeScreen === 'dashboard' && (
					<DashboardScreen client={ client } manifest={ manifest } onNavigateScreen={ setActiveScreen } />
				) }
				{ activeScreen === 'research' && (
					<ResearchScreen client={ client } manifest={ manifest } onNavigateScreen={ setActiveScreen } />
				) }
				{ activeScreen === 'consolidate' && (
					<ConsolidateScreen client={ client } manifest={ manifest } onNavigateScreen={ setActiveScreen } />
				) }
			</section>
		</div>
	);
}
