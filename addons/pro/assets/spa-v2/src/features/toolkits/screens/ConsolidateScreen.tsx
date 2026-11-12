/**
 * ConsolidateScreen — duplicate finder for one entity.
 *
 * Groups the loaded records by a chosen text/enum column and shows the groups
 * that collide, so an operator can delete the extras. Runs entirely on the
 * existing list + delete endpoints; no consolidation engine required.
 *
 * The scan covers the first page of records (up to 100) and says so when the
 * entity is larger.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import { ToolkitsApiError, type ToolkitColumn, type ToolkitRecord } from '../../../api/toolkits';
import { isEmptyValue } from '../format';
import type { ToolkitScreenProps } from '../ToolkitPage';

const SCAN_LIMIT = 100;

interface DuplicateGroup {
	value: string;
	records: ToolkitRecord[];
}

export function ConsolidateScreen( { client, manifest }: ToolkitScreenProps ): JSX.Element {
	const entities = manifest.entities;
	const [ entityKey, setEntityKey ] = useState< string >( entities[ 0 ]?.key ?? '' );
	const entity = entities.find( ( candidate ) => candidate.key === entityKey );

	const groupableColumns = useMemo< ToolkitColumn[] >(
		() =>
			( entity?.columns ?? [] ).filter(
				( column ) => column.type === 'string' || column.type === 'enum',
			),
		[ entity ],
	);

	const [ columnKey, setColumnKey ] = useState< string >( groupableColumns[ 0 ]?.key ?? '' );
	const [ groups, setGroups ] = useState< DuplicateGroup[] | null >( null );
	const [ scanned, setScanned ] = useState< number >( 0 );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ busy, setBusy ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const effectiveColumnKey =
		groupableColumns.some( ( column ) => column.key === columnKey )
			? columnKey
			: groupableColumns[ 0 ]?.key ?? '';

	const scan = useCallback( async (): Promise< void > => {
		if ( ! entity || effectiveColumnKey === '' ) {
			return;
		}

		setLoading( true );
		setError( null );
		setGroups( null );

		try {
			const result = await client.records( manifest.slug, entity.key, {
				page: 1,
				perPage: SCAN_LIMIT,
			} );
			setScanned( result.items.length );

			const buckets = new Map< string, ToolkitRecord[] >();

			for ( const record of result.items ) {
				const raw = record.data[ effectiveColumnKey ];

				if ( isEmptyValue( raw ) ) {
					continue;
				}

				const key = String( raw ).trim().toLowerCase();
				const bucket = buckets.get( key ) ?? [];
				bucket.push( record );
				buckets.set( key, bucket );
			}

			setGroups(
				Array.from( buckets.entries() )
					.filter( ( [ , records ] ) => records.length > 1 )
					.map( ( [ value, records ] ) => ( { value, records } ) )
					.sort( ( a, b ) => b.records.length - a.records.length ),
			);
		} catch ( cause ) {
			setError(
				cause instanceof ToolkitsApiError
					? cause.message
					: __( 'Unable to scan for duplicates.', 'nvoos-pro-spa' ),
			);
		} finally {
			setLoading( false );
		}
	}, [ client, entity, effectiveColumnKey, manifest.slug ] );

	const remove = useCallback(
		async ( record: ToolkitRecord ): Promise< void > => {
			if ( ! entity ) {
				return;
			}

			setBusy( true);
			setError( null );

			try {
				await client.deleteRecord( manifest.slug, entity.key, record.id );
				await scan();
			} catch ( cause ) {
				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to delete the record.', 'nvoos-pro-spa' ),
				);
			} finally {
				setBusy( false );
			}
		},
		[ client, entity, manifest.slug, scan ],
	);

	if ( ! entity || groupableColumns.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-toolkits__empty" role="status">
				<span className="nvoos-pro-spa-toolkits__empty-icon" aria-hidden="true">
					🧹
				</span>
				<h3>{ __( 'Nothing to consolidate', 'nvoos-pro-spa' ) }</h3>
				<p>
					{ __(
						'This toolkit has no text or list columns to group by.',
						'nvoos-pro-spa',
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-toolkits__consolidate">
			<div className="nvoos-pro-spa-toolkits__toolbar">
				<div className="nvoos-pro-spa-toolkits__toolbar-start">
					{ entities.length > 1 && (
						<label className="nvoos-pro-spa-toolkits__entity-picker">
							<span className="screen-reader-text">{ __( 'Entity', 'nvoos-pro-spa' ) }</span>
							<select
								className="nvoos-pro-spa-toolkits__input"
								value={ entityKey }
								onChange={ ( event ) => {
									setEntityKey( event.target.value );
									setGroups( null );
								} }
							>
								{ entities.map( ( candidate ) => (
									<option key={ candidate.key } value={ candidate.key }>
										{ candidate.label }
									</option>
								) ) }
							</select>
						</label>
					) }

					<label className="nvoos-pro-spa-toolkits__entity-picker">
						<span className="nvoos-pro-spa-toolkits__label-inline">
							{ __( 'Group by', 'nvoos-pro-spa' ) }
						</span>
						<select
							className="nvoos-pro-spa-toolkits__input"
							value={ effectiveColumnKey }
							onChange={ ( event ) => {
								setColumnKey( event.target.value );
								setGroups( null );
							} }
						>
							{ groupableColumns.map( ( column ) => (
								<option key={ column.key } value={ column.key }>
									{ column.label }
								</option>
							) ) }
						</select>
					</label>
				</div>

				<button
					type="button"
					className="nvoos-pro-spa-toolkits__btn"
					disabled={ loading }
					onClick={ () => void scan() }
				>
					{ loading ? __( 'Scanning…', 'nvoos-pro-spa' ) : __( 'Find duplicates', 'nvoos-pro-spa' ) }
				</button>
			</div>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			{ groups !== null && groups.length === 0 && (
				<div className="nvoos-pro-spa-toolkits__empty" role="status">
					<span className="nvoos-pro-spa-toolkits__empty-icon" aria-hidden="true">
						✅
					</span>
					<h3>{ __( 'No duplicates found', 'nvoos-pro-spa' ) }</h3>
					<p>
						{ __( 'Scanned', 'nvoos-pro-spa' ) } { scanned }{ ' ' }
						{ scanned === 1
							? __( 'record', 'nvoos-pro-spa' )
							: __( 'records', 'nvoos-pro-spa' ) }
						{ ' ' }
						{ __( 'on the first page.', 'nvoos-pro-spa' ) }
					</p>
				</div>
			) }

			{ groups !== null && groups.length > 0 && (
				<>
					<p className="nvoos-pro-spa-toolkits__consolidate-note">
						{ __( 'Scanned', 'nvoos-pro-spa' ) } { scanned }{ ' ' }
						{ __( 'records —', 'nvoos-pro-spa' ) } { groups.length }{ ' ' }
						{ groups.length === 1
							? __( 'group collides', 'nvoos-pro-spa' )
							: __( 'groups collide', 'nvoos-pro-spa' ) }
						{ scanned >= SCAN_LIMIT
							? __( '. Only the first 100 records were scanned.', 'nvoos-pro-spa' )
							: '.' }
					</p>

					<ul className="nvoos-pro-spa-toolkits__dup-list">
						{ groups.map( ( group ) => (
							<li key={ group.value } className="nvoos-pro-spa-toolkits__dup-group">
								<header className="nvoos-pro-spa-toolkits__dup-header">
									<span className="nvoos-pro-spa-toolkits__dup-value">{ group.value }</span>
									<span className="nvoos-pro-spa-toolkits__dup-count">
										{ group.records.length }×
									</span>
								</header>

								<ul className="nvoos-pro-spa-toolkits__dup-records">
									{ group.records.map( ( record, index ) => (
										<li key={ record.id } className="nvoos-pro-spa-toolkits__dup-record">
											<code className="nvoos-pro-spa-toolkits__key">{ record.id }</code>

											{ index === 0 && (
												<span className="nvoos-pro-spa-toolkits__badge nvoos-pro-spa-toolkits__badge--active">
													{ __( 'Keep', 'nvoos-pro-spa' ) }
												</span>
											) }

											{ index > 0 && (
												<button
													type="button"
													className="nvoos-pro-spa-toolkits__link-btn nvoos-pro-spa-toolkits__link-btn--danger"
													disabled={ busy }
													onClick={ () => void remove( record ) }
												>
													{ __( 'Delete duplicate', 'nvoos-pro-spa' ) }
												</button>
											) }
										</li>
									) ) }
								</ul>
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}
