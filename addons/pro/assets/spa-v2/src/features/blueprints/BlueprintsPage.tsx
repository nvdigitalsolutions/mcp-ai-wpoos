/**
 * BlueprintsPage — assistant blueprint catalogue (Proposal 084 Phase 5c).
 *
 * Consumes mcp-ai-pro/v1/blueprints (edit_posts-gated, bridging the
 * wp-admin Unified Blueprints page's promoted seams): a searchable,
 * toolkit-filterable catalogue of pre-configured assistants with expandable
 * detail rows (instructions, tools, defaults) and a one-click install flow
 * (duplicate detection prompts an overwrite confirm — the wp-admin parity
 * behaviour). The list renders on the shared DataTable (master-detail rows).
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	BlueprintsClient,
	type BlueprintCatalogue,
	type BlueprintDetails,
} from '../../api/blueprints';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

/** Flattened catalogue row (toolkit context carried alongside). */
interface BlueprintRow {
	slug: string;
	name: string;
	description: string;
	tool_count: number;
	installed: boolean;
	assistant_id: number;
	toolkit: string;
	toolkitLabel: string;
}

function detailKey( toolkit: string, slug: string ): string {
	return `${ toolkit }/${ slug }`;
}

function looksLikeDuplicate( message: string ): boolean {
	const lower = message.toLowerCase();
	return lower.includes( 'already exists' ) || lower.includes( 'duplicate' );
}

export function BlueprintsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();

	const client = useMemo(
		() =>
			new BlueprintsClient( {
				endpoint: runtime?.endpoints.blueprints ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ]
	);

	const [ catalogue, setCatalogue ] = useState< BlueprintCatalogue | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ search, setSearch ] = useState< string >( '' );
	const [ toolkitFilter, setToolkitFilter ] = useState< string >( '' );

	const [ expandedKey, setExpandedKey ] = useState< string | null >( null );
	const [ details, setDetails ] = useState< Record< string, BlueprintDetails > >( {} );
	const [ detailLoading, setDetailLoading ] = useState< string | null >( null );
	const [ detailError, setDetailError ] = useState< string | null >( null );

	const [ installing, setInstalling ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );

	const load = useCallback( async () => {
		if ( ! runtime?.endpoints.blueprints ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setCatalogue( await client.list() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, runtime ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	const rows = useMemo< BlueprintRow[] >( () => {
		const q = search.toLowerCase().trim();
		const flat = ( catalogue?.toolkits ?? [] ).flatMap( ( toolkit ) =>
			toolkit.blueprints.map( ( bp ) => ( {
				...bp,
				toolkit: toolkit.slug,
				toolkitLabel: toolkit.label,
			} ) )
		);
		return flat.filter(
			( row ) =>
				( ! toolkitFilter || row.toolkit === toolkitFilter ) &&
				( ! q ||
					row.name.toLowerCase().includes( q ) ||
					row.description.toLowerCase().includes( q ) )
		);
	}, [ catalogue, search, toolkitFilter ] );

	const toggleDetails = useCallback(
		async ( row: BlueprintRow ) => {
			const key = detailKey( row.toolkit, row.slug );
			if ( expandedKey === key ) {
				setExpandedKey( null );
				return;
			}
			setExpandedKey( key );
			setDetailError( null );
			if ( details[ key ] ) {
				return;
			}
			setDetailLoading( key );
			try {
				const payload = await client.details( row.toolkit, row.slug );
				setDetails( ( prev ) => ( { ...prev, [ key ]: payload } ) );
			} catch ( err ) {
				setDetailError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setDetailLoading( null );
			}
		},
		[ client, details, expandedKey ]
	);

	const installBlueprint = useCallback(
		async ( row: BlueprintRow, overwrite = false ) => {
			const key = detailKey( row.toolkit, row.slug );
			setInstalling( key );
			setNotice( null );
			try {
				await client.install( row.toolkit, row.slug, overwrite );
				setNotice(
					__(
						'Blueprint installed — the assistant is ready in the Assistants page.',
						'nvoos-pro-spa'
					)
				);
				await load();
			} catch ( err ) {
				const message = err instanceof Error ? err.message : String( err );
				if ( ! overwrite && looksLikeDuplicate( message ) ) {
					// eslint-disable-next-line no-alert -- parity with the wp-admin confirm().
					if (
						window.confirm(
							__(
								'A blueprint with this name already exists. Overwrite it?',
								'nvoos-pro-spa'
							)
						)
					) {
						await installBlueprint( row, true );
						return;
					}
				}
				setError( message );
			} finally {
				setInstalling( null );
			}
		},
		[ client, load ]
	);

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'blueprint',
				label: __( 'Blueprint', 'nvoos-pro-spa' ),
				width: 'minmax(240px, 2fr)',
			},
			{ key: 'toolkit', label: __( 'Toolkit', 'nvoos-pro-spa' ), width: '180px' },
			{
				key: 'tools',
				label: __( 'Tools', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--num',
				width: '80px',
			},
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '110px' },
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '190px' },
		],
		[]
	);

	const tableRows = useMemo< DataTableRow[] >(
		() =>
			rows.map( ( row ) => {
				const key = detailKey( row.toolkit, row.slug );
				const expanded = expandedKey === key;
				const current = details[ key ];
				const busyKey = installing === key;
				const detailNode = expanded ? (
					<div className="nvoos-pro-spa-blueprints__detail">
						{ detailLoading === key ? (
							<p className="nvoos-pro-spa-slash__result">
								{ __( 'Loading details…', 'nvoos-pro-spa' ) }
							</p>
						) : detailError ? (
							<p
								className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
								role="alert"
							>
								{ detailError }
							</p>
						) : current ? (
							<>
								{ current.instructions ? (
									<>
										<h4 className="nvoos-pro-spa-blueprints__heading">
											{ __( 'Instructions', 'nvoos-pro-spa' ) }
										</h4>
										<pre className="nvoos-pro-spa-slash__result">
											{ current.instructions }
										</pre>
									</>
								) : null }
								{ current.tools.length > 0 ? (
									<>
										<h4 className="nvoos-pro-spa-blueprints__heading">
											{ __( 'Tools', 'nvoos-pro-spa' ) } ({ current.tools.length })
										</h4>
										<div className="nvoos-pro-spa-blueprints__tags">
											{ current.tools.map( ( tool ) => (
												<code
													key={ tool }
													className="nvoos-pro-spa-tools-list__slug"
												>
													{ tool }
												</code>
											) ) }
										</div>
									</>
								) : null }
								{ current.defaults ? (
									<>
										<h4 className="nvoos-pro-spa-blueprints__heading">
											{ __( 'Defaults', 'nvoos-pro-spa' ) }
										</h4>
										<ul className="nvoos-pro-spa-blueprints__defaults">
											{ Object.entries( current.defaults ).map(
												( [ label, value ] ) => (
													<li key={ label }>
														<strong>{ label }:</strong>{ ' ' }
														{ Array.isArray( value )
															? value.join( ', ' )
															: String( value ) }
													</li>
												)
											) }
										</ul>
									</>
								) : null }
							</>
						) : null }
					</div>
				) : undefined;

				return {
					id: key,
					cells: [
						<>
							<strong
								className="nvoos-pro-spa-tools-list__name"
								title={ row.name }
							>
								{ row.name }
							</strong>
							<span
								className="nvoos-pro-spa-tools-list__desc-clamp"
								title={ row.description || undefined }
							>
								{ row.description || '—' }
							</span>
						</>,
						<span
							className="nvoos-pro-spa-tools-list__badge"
							title={ row.toolkitLabel }
						>
							{ row.toolkitLabel }
						</span>,
						row.tool_count,
						row.installed ? (
							<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--ok">
								{ __( 'Installed', 'nvoos-pro-spa' ) }
							</span>
						) : (
							<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
								—
							</span>
						),
						<>
							{ ! row.installed ? (
								<button
									type="button"
									className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small nvoos-pro-spa-page__retry--primary"
									disabled={ installing !== null }
									onClick={ () => void installBlueprint( row ) }
								>
									{ busyKey
										? __( 'Installing…', 'nvoos-pro-spa' )
										: __( 'Install', 'nvoos-pro-spa' ) }
								</button>
							) : null }
							<button
								type="button"
								className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
								aria-expanded={ expanded }
								onClick={ () => void toggleDetails( row ) }
							>
								{ expanded
									? __( 'Hide details', 'nvoos-pro-spa' )
									: __( 'Details', 'nvoos-pro-spa' ) }
							</button>
						</>,
					],
					detail: detailNode,
				};
			} ),
		[
			rows,
			expandedKey,
			details,
			detailLoading,
			detailError,
			installing,
			installBlueprint,
			toggleDetails,
		]
	);

	if ( bootstrapLoading ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Blueprints', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Blueprints', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __(
						'Runtime configuration not available. Please reload the page.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( ! runtime.endpoints.blueprints ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="status">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Blueprints', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message">
					{ __(
						'Assistant blueprints are available to content editors and administrators only.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( loading && ! catalogue ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Blueprints', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading the blueprint catalogue…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	const isFiltered = search.trim().length > 0 || toolkitFilter.length > 0;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<div>
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Blueprints', 'nvoos-pro-spa' ) }
					</h2>
					<p className="nvoos-pro-spa-page__subtitle">
						{ __(
							'Pre-configured AI assistants for every professional toolkit — install one to create a ready-to-use assistant in seconds.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
				{ catalogue ? (
					<span
						className="nvoos-pro-spa-tools-page__count"
						role="status"
						aria-live="polite"
					>
						{ catalogue.total }{ ' ' }
						{ catalogue.total === 1
							? __( 'blueprint', 'nvoos-pro-spa' )
							: __( 'blueprints', 'nvoos-pro-spa' ) }
					</span>
				) : null }
			</header>

			<div
				className="nvoos-pro-spa-tools-page__controls"
				role="search"
				aria-label={ __( 'Filter blueprints', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-tools-page__search">
					<label
						htmlFor="nvoos-pro-spa-blueprints-search"
						className="nvoos-pro-spa-tools-page__label"
					>
						{ __( 'Search blueprints', 'nvoos-pro-spa' ) }
					</label>
					<input
						id="nvoos-pro-spa-blueprints-search"
						type="search"
						className="nvoos-pro-spa-tools-page__input"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
						placeholder={ __(
							'Search by name or description…',
							'nvoos-pro-spa'
						) }
					/>
				</div>

				<div className="nvoos-pro-spa-tools-page__filter">
					<label
						htmlFor="nvoos-pro-spa-blueprints-toolkit"
						className="nvoos-pro-spa-tools-page__label"
					>
						{ __( 'Toolkit', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-pro-spa-blueprints-toolkit"
						className="nvoos-pro-spa-tools-page__select"
						value={ toolkitFilter }
						onChange={ ( e ) => setToolkitFilter( e.target.value ) }
					>
						<option value="">
							{ __( 'All toolkits', 'nvoos-pro-spa' ) }
						</option>
						{ ( catalogue?.toolkits ?? [] ).map( ( toolkit ) => (
							<option key={ toolkit.slug } value={ toolkit.slug }>
								{ toolkit.label }
							</option>
						) ) }
					</select>
				</div>

				{ isFiltered ? (
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
						onClick={ () => {
							setSearch( '' );
							setToolkitFilter( '' );
						} }
					>
						{ __( 'Clear filters', 'nvoos-pro-spa' ) }
					</button>
				) : null }
			</div>

			{ error ? (
				<div
					className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
					role="alert"
				>
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void load() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) : null }

			{ notice ? (
				<p
					className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--success"
					role="status"
				>
					{ notice }
				</p>
			) : null }

			{ ! error && rows.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ isFiltered
							? __(
									'No blueprints match your filters.',
									'nvoos-pro-spa'
							  )
							: __(
									'No blueprints found. Enable a toolkit to see its blueprints here.',
									'nvoos-pro-spa'
							  ) }
					</p>
				</div>
			) : (
				<DataTable
					columns={ columns }
					rows={ tableRows }
					caption={ __( 'Assistant blueprints', 'nvoos-pro-spa' ) }
				/>
			) }
		</div>
	);
}
