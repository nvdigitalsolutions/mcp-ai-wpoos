/**
 * RemoteSitesPage — the Connections page (console: Command Centers →
 * Channels → Connections).
 *
 * Ports the wp-admin Remote Sites surface into the console with a
 * simple-first wizard flow (industry standard — see
 * docs/features/remote-sites.md):
 *
 *   - One list for every connection the user owns (Remote Sites + the
 *     base-plugin Gmail settings connection).
 *   - Status chips show the connection's real state, not just "configured":
 *     Connected / Not configured / Disabled.
 *   - Secrets are write-only: the edit form shows masked placeholders and
 *     never echoes stored credentials ("leave blank to keep" pattern).
 *   - Test is an explicit, deliberate step before a connection is trusted;
 *     the result is shown inline in plain language.
 *   - Delete uses a two-step inline confirmation stating what stops.
 *
 * The base-plugin Gmail connection is display + test only here; its
 * credentials live in wp-admin site settings (single source of truth), so
 * the row deep-links there.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	RemoteSitesClient,
	type RemoteSiteConnection,
	type RemoteSiteTestResult,
	type RemoteSiteType,
} from '../../api/remote-sites';

import './remote-sites.css';

const CATEGORY_META: Record< string, { label: string; icon: string } > = {
	website: { label: __( 'Websites', 'nvoos-pro-spa' ), icon: '🌐' },
	ai: { label: __( 'AI & MCP', 'nvoos-pro-spa' ), icon: '🧠' },
	email: { label: __( 'Email', 'nvoos-pro-spa' ), icon: '✉️' },
	google: { label: __( 'Google', 'nvoos-pro-spa' ), icon: '☁️' },
	chat: { label: __( 'Chat channels', 'nvoos-pro-spa' ), icon: '💬' },
	storage: { label: __( 'Storage', 'nvoos-pro-spa' ), icon: '🗄️' },
	ecommerce: { label: __( 'E-commerce', 'nvoos-pro-spa' ), icon: '🛒' },
	business: { label: __( 'Business', 'nvoos-pro-spa' ), icon: '🏢' },
	freelance: { label: __( 'Freelance', 'nvoos-pro-spa' ), icon: '💼' },
};

interface TestState {
	busy: boolean;
	result: RemoteSiteTestResult | null;
}

type WizardMode = 'closed' | 'add' | 'edit';

export function RemoteSitesPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const client = useMemo(
		() =>
			new RemoteSitesClient( {
				endpoint: runtime?.endpoints.remoteSites ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ]
	);

	const [ connections, setConnections ] = useState< RemoteSiteConnection[] >( [] );
	const [ types, setTypes ] = useState< RemoteSiteType[] >( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );

	const [ tests, setTests ] = useState< Record< string, TestState > >( {} );
	const [ deleteTarget, setDeleteTarget ] = useState< RemoteSiteConnection | null >( null );

	const [ wizard, setWizard ] = useState< WizardMode >( 'closed' );
	const [ wizardEditId, setWizardEditId ] = useState< string >( '' );
	const [ wizardType, setWizardType ] = useState< RemoteSiteType | null >( null );
	const [ wizardValues, setWizardValues ] = useState< Record< string, string > >( {} );
	const [ wizardBusy, setWizardBusy ] = useState( false );
	const [ wizardError, setWizardError ] = useState< string | null >( null );
	const [ wizardTestAfter, setWizardTestAfter ] = useState( false );
	const [ typeSearch, setTypeSearch ] = useState( '' );

	const refresh = useCallback( () => {
		if ( ! runtime?.endpoints.remoteSites ) {
			return;
		}
		setLoading( true );
		setError( null );
		client
			.list()
			.then( ( envelope ) => {
				setConnections( envelope.items );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError(
					err instanceof Error
						? err.message
						: __( 'Failed to load connections.', 'nvoos-pro-spa' )
				);
				setLoading( false );
			} );
	}, [ client, runtime ] );

	useEffect( () => {
		if ( ! runtime?.endpoints.remoteSites ) {
			return;
		}
		refresh();
		client
			.types()
			.then( setTypes )
			.catch( () => {
				// The wizard falls back to a plain message; the list still works.
			} );
	}, [ client, runtime, refresh ] );

	const typeById = useCallback(
		( id: string ): RemoteSiteType | undefined => types.find( ( t ) => t.id === id ),
		[ types ]
	);

	const runTest = useCallback(
		( connection: RemoteSiteConnection ) => {
			setTests( ( prev ) => ( { ...prev, [ connection.id ]: { busy: true, result: null } } ) );
			client
				.test( connection.id )
				.then( ( result ) =>
					setTests( ( prev ) => ( {
						...prev,
						[ connection.id ]: { busy: false, result },
					} ) )
				)
				.catch( ( err: unknown ) =>
					setTests( ( prev ) => ( {
						...prev,
						[ connection.id ]: {
							busy: false,
							result: {
								success: false,
								message:
									err instanceof Error
										? err.message
										: __( 'Test failed.', 'nvoos-pro-spa' ),
							},
						},
					} ) )
				);
		},
		[ client ]
	);

	const openAdd = (): void => {
		setWizard( 'add' );
		setWizardEditId( '' );
		setWizardType( null );
		setWizardValues( {} );
		setWizardError( null );
		setWizardTestAfter( false );
		setTypeSearch( '' );
	};

	const openEdit = ( connection: RemoteSiteConnection ): void => {
		const definition = typeById( connection.connection_type );
		if ( ! definition ) {
			setNotice(
				__(
					'This connection type is not editable in the console yet — open wp-admin → Remote Sites.',
					'nvoos-pro-spa'
				)
			);
			return;
		}
		const values: Record< string, string > = { name: connection.name ?? '' };
		for ( const field of definition.fields ) {
			const stored = connection[ field.key ];
			if ( typeof stored === 'string' && ! field.secret ) {
				values[ field.key ] = stored;
			}
		}
		setWizard( 'edit' );
		setWizardEditId( connection.id );
		setWizardType( definition );
		setWizardValues( values );
		setWizardError( null );
		setWizardTestAfter( false );
	};

	const closeWizard = (): void => {
		setWizard( 'closed' );
		setWizardEditId( '' );
		setWizardType( null );
	};

	const saveWizard = async ( testAfter: boolean ): Promise< void > => {
		if ( ! wizardType ) {
			return;
		}
		setWizardBusy( true );
		setWizardError( null );
		const payload: Record< string, unknown > = {
			name: wizardValues.name ?? '',
			connection_type: wizardType.id,
		};
		for ( const field of wizardType.fields ) {
			const value = ( wizardValues[ field.key ] ?? '' ).trim();
			if ( wizard === 'edit' && field.secret && value === '' ) {
				continue; // Blank secret = keep the stored value (never echo back).
			}
			payload[ field.key ] = value;
		}
		try {
			const saved =
				wizard === 'edit'
					? await client.update( wizardEditId, payload )
					: await client.create( payload );
			setNotice( __( 'Connection saved.', 'nvoos-pro-spa' ) );
			closeWizard();
			refresh();
			if ( testAfter ) {
				runTest( saved );
			}
		} catch ( err: unknown ) {
			setWizardError(
				err instanceof Error
					? err.message
					: __( 'Could not save the connection.', 'nvoos-pro-spa' )
			);
		} finally {
			setWizardBusy( false );
		}
	};

	const confirmDelete = async (): Promise< void > => {
		if ( ! deleteTarget ) {
			return;
		}
		try {
			await client.delete( deleteTarget.id );
			setNotice( __( 'Connection removed.', 'nvoos-pro-spa' ) );
			setDeleteTarget( null );
			refresh();
		} catch ( err: unknown ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Could not remove the connection.', 'nvoos-pro-spa' )
			);
			setDeleteTarget( null );
		}
	};

	const groupedTypes = useMemo( () => {
		const query = typeSearch.trim().toLowerCase();
		const filtered = types.filter(
			( t ) => ! query || t.label.toLowerCase().includes( query )
		);
		const groups = new Map< string, RemoteSiteType[] >();
		for ( const t of filtered ) {
			const bucket = groups.get( t.category ) ?? [];
			bucket.push( t );
			groups.set( t.category, bucket );
		}
		return Array.from( groups.entries() );
	}, [ types, typeSearch ] );

	const statusOf = ( connection: RemoteSiteConnection ): string => {
		if ( ! connection.enabled ) {
			return 'disabled';
		}
		return connection.has_credentials ? 'connected' : 'needs-setup';
	};

	const wpAdminSettingsUrl = useMemo( () => {
		const api = runtime?.apiUrl ?? '';
		const base = api.replace( /\/wp-json\/?$/, '' );
		return base
			? `${ base }/wp-admin/options-general.php?page=wp-mcp-ai-settings`
			: '';
	}, [ runtime ] );

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Connections', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Connections', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page nvoos-pro-spa-connections">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Connections', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Sites, channels and services your assistants can use — one list, one flow.', 'nvoos-pro-spa' ) }
				</p>
				<button type="button" className="nvoos-pro-spa-page__retry" onClick={ openAdd }>
					{ __( '+ Add connection', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ notice && (
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--success" role="status">
					{ notice }
				</p>
			) }
			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button type="button" className="nvoos-pro-spa-page__retry" onClick={ refresh }>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ wizard !== 'closed' && (
				<section
					className="nvoos-pro-spa-connections__wizard"
					aria-label={ __( 'Connection wizard', 'nvoos-pro-spa' ) }
				>
					<h3 className="nvoos-pro-spa-connections__wizard-title">
						{ wizard === 'add'
							? __( 'Add a connection', 'nvoos-pro-spa' )
							: __( 'Edit connection', 'nvoos-pro-spa' ) }
					</h3>

					{ ! wizardType ? (
						<>
							<input
								type="search"
								className="nvoos-pro-spa-records-search__input"
								placeholder={ __( 'Search connection types…', 'nvoos-pro-spa' ) }
								value={ typeSearch }
								onChange={ ( event ) => setTypeSearch( event.target.value ) }
							/>
							{ groupedTypes.length === 0 ? (
								<p className="nvoos-pro-spa-page__subtitle">
									{ types.length === 0
										? __( 'Connection types could not be loaded. Please reload the page.', 'nvoos-pro-spa' )
										: __( 'No connection types match that search.', 'nvoos-pro-spa' ) }
								</p>
							) : (
								groupedTypes.map( ( [ category, groupTypes ] ) => (
									<div key={ category } className="nvoos-pro-spa-connections__type-group">
										<p className="nvoos-pro-spa-connections__type-group-label">
											{ CATEGORY_META[ category ]?.icon ?? '🔌' }{ ' ' }
											{ CATEGORY_META[ category ]?.label ?? category }
										</p>
										<ul className="nvoos-pro-spa-connections__type-list">
											{ groupTypes.map( ( t ) => (
												<li key={ t.id }>
													<button
														type="button"
														className="nvoos-pro-spa-connections__type-pick"
														onClick={ () => setWizardType( t ) }
													>
														<span className="nvoos-pro-spa-connections__type-pick-label">
															{ t.label }
														</span>
														{ t.help && (
															<span className="nvoos-pro-spa-connections__type-pick-help">
																{ t.help }
															</span>
														) }
													</button>
												</li>
											) ) }
										</ul>
									</div>
								) )
							) }
							<button
								type="button"
								className="nvoos-pro-spa-page__retry"
								onClick={ closeWizard }
							>
								{ __( 'Cancel', 'nvoos-pro-spa' ) }
							</button>
						</>
					) : (
						<form
							className="nvoos-pro-spa-connections__form"
							onSubmit={ ( event ) => {
								event.preventDefault();
								void saveWizard( wizardTestAfter );
							} }
						>
							<p className="nvoos-pro-spa-page__subtitle">
								{ wizardType.label }
								{ wizardType.help ? ` — ${ wizardType.help }` : '' }
							</p>

							<label className="nvoos-pro-spa-connections__field">
								<span className="nvoos-pro-spa-connections__field-label">
									{ __( 'Name', 'nvoos-pro-spa' ) }
								</span>
								<input
									type="text"
									className="nvoos-pro-spa-records-search__input"
									value={ wizardValues.name ?? '' }
									placeholder={ __( 'e.g. Production store', 'nvoos-pro-spa' ) }
									onChange={ ( event ) =>
										setWizardValues( ( prev ) => ( { ...prev, name: event.target.value } ) )
									}
								/>
							</label>

							{ wizardType.fields.length === 0 && (
								<p className="nvoos-pro-spa-page__subtitle">
									{ __( 'This connection needs no credentials.', 'nvoos-pro-spa' ) }
								</p>
							) }

							{ wizardType.fields.map( ( field ) => (
								<label key={ field.key } className="nvoos-pro-spa-connections__field">
									<span className="nvoos-pro-spa-connections__field-label">
										{ field.label }
										{ field.required ? ' *' : '' }
									</span>
									<input
										type={ field.secret ? 'password' : 'text' }
										className="nvoos-pro-spa-records-search__input"
										value={ wizardValues[ field.key ] ?? '' }
										autoComplete="new-password"
										placeholder={
											field.placeholder ??
											( wizard === 'edit' && field.secret
												? __( 'Leave blank to keep the stored value', 'nvoos-pro-spa' )
												: '' )
										}
										onChange={ ( event ) =>
											setWizardValues( ( prev ) => ( {
												...prev,
												[ field.key ]: event.target.value,
											} ) )
										}
									/>
									{ field.help && (
										<span className="nvoos-pro-spa-connections__field-help">
											{ field.help }
										</span>
									) }
								</label>
							) ) }

							{ wizardError && (
								<p
									className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
									role="alert"
								>
									{ wizardError }
								</p>
							) }

							<label className="nvoos-pro-spa-connections__test-after">
								<input
									type="checkbox"
									checked={ wizardTestAfter }
									onChange={ ( event ) => setWizardTestAfter( event.target.checked ) }
								/>
								{ __( 'Test this connection right after saving', 'nvoos-pro-spa' ) }
							</label>

							<div className="nvoos-pro-spa-connections__actions">
								<button
									type="submit"
									className="nvoos-pro-spa-page__retry"
									disabled={ wizardBusy }
								>
									{ wizardBusy
										? __( 'Saving…', 'nvoos-pro-spa' )
										: __( 'Save connection', 'nvoos-pro-spa' ) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-page__retry"
									onClick={ () =>
										wizard === 'add' ? setWizardType( null ) : closeWizard()
									}
								>
									{ __( 'Back', 'nvoos-pro-spa' ) }
								</button>
							</div>
						</form>
					) }
				</section>
			) }

			{ loading && connections.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'Loading connections…', 'nvoos-pro-spa' ) }</p>
				</div>
			) : ! error && connections.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ __(
							'No connections yet. Add one to let your assistants reach the outside world.',
							'nvoos-pro-spa'
						) }
					</p>
					<button type="button" className="nvoos-pro-spa-page__retry" onClick={ openAdd }>
						{ __( '+ Add connection', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) : (
				<ul className="nvoos-pro-spa-connections__list">
					{ connections.map( ( connection ) => {
						const definition = typeById( connection.connection_type );
						const category = definition?.category ?? '';
						const status = statusOf( connection );
						const test = tests[ connection.id ];
						return (
							<li key={ connection.id } className="nvoos-pro-spa-connections__row">
								<span className="nvoos-pro-spa-connections__icon" aria-hidden="true">
									{ CATEGORY_META[ category ]?.icon ?? '🔌' }
								</span>
								<div className="nvoos-pro-spa-connections__identity">
									<strong className="nvoos-pro-spa-connections__name">
										{ connection.name }
									</strong>
									<span className="nvoos-pro-spa-tools-list__badge">
										{ definition?.label ?? connection.connection_type }
									</span>
									{ connection.source === 'base' && (
										<span className="nvoos-pro-spa-connections__source-badge">
											{ __( 'Site settings', 'nvoos-pro-spa' ) }
										</span>
									) }
									{ connection.user_email && (
										<span className="nvoos-pro-spa-connections__detail">
											{ connection.user_email }
										</span>
									) }
								</div>
								<span
									className={ `nvoos-pro-spa-connections__status nvoos-pro-spa-connections__status--${ status }` }
								>
									{ status === 'connected' && __( 'Connected', 'nvoos-pro-spa' ) }
									{ status === 'needs-setup' && __( 'Not configured', 'nvoos-pro-spa' ) }
									{ status === 'disabled' && __( 'Disabled', 'nvoos-pro-spa' ) }
								</span>
								<div className="nvoos-pro-spa-connections__actions">
									<button
										type="button"
										className="nvoos-pro-spa-page__retry"
										disabled={ test?.busy }
										onClick={ () => runTest( connection ) }
									>
										{ test?.busy
											? __( 'Testing…', 'nvoos-pro-spa' )
											: __( 'Test', 'nvoos-pro-spa' ) }
									</button>
									{ connection.source === 'pro' && (
										<>
											<button
												type="button"
												className="nvoos-pro-spa-page__retry"
												onClick={ () => openEdit( connection ) }
											>
												{ __( 'Edit', 'nvoos-pro-spa' ) }
											</button>
											<button
												type="button"
												className="nvoos-pro-spa-page__retry nvoos-pro-spa-connections__danger"
												onClick={ () => setDeleteTarget( connection ) }
											>
												{ __( 'Delete', 'nvoos-pro-spa' ) }
											</button>
										</>
									) }
									{ connection.source === 'base' && wpAdminSettingsUrl && (
										<a
											className="nvoos-pro-spa-page__retry"
											href={ wpAdminSettingsUrl }
										>
											{ __( 'Manage in wp-admin', 'nvoos-pro-spa' ) }
										</a>
									) }
								</div>
								{ test?.result && (
									<p
										className={
											test.result.success
												? 'nvoos-pro-spa-connections__test nvoos-pro-spa-connections__test--ok'
												: 'nvoos-pro-spa-connections__test nvoos-pro-spa-connections__test--fail'
										}
										role="status"
									>
										{ test.result.success ? '✓ ' : '✕ ' }
										{ test.result.message }
									</p>
								) }
							</li>
						);
					} ) }
				</ul>
			) }

			{ deleteTarget && (
				<div
					className="nvoos-pro-spa-connections__confirm"
					role="alertdialog"
					aria-modal="true"
				>
					<p>
						{ __( 'Remove “', 'nvoos-pro-spa' ) }
						{ deleteTarget.name }
						{ __(
							'”? Anything using this connection (tools, webhooks, channels) stops working until you reconnect it.',
							'nvoos-pro-spa'
						) }
					</p>
					<div className="nvoos-pro-spa-connections__actions">
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-connections__danger"
							onClick={ () => void confirmDelete() }
						>
							{ __( 'Yes, remove it', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-page__retry"
							onClick={ () => setDeleteTarget( null ) }
						>
							{ __( 'Cancel', 'nvoos-pro-spa' ) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}
