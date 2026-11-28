// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Scope editor — create or replace a grant from the console.
 *
 * The console writes scopes through the same registry wp-admin uses
 * (`WP_MCP_AI_Db_Scope_Registry::save()`), so the rules are the server's:
 * a wildcard schema is refused, an empty principal list is allowed but inert,
 * and the grant version moves only when the grant itself changes. This form is
 * therefore a *shape* mapper, not a second validator — its local checks only
 * avoid a round trip for an obvious typo, and every refusal the server returns
 * is mapped back onto the field that caused it.
 *
 * The embedded {@see PreviewPanel} compiles the current draft without saving it,
 * which is the point of pairing the two: an operator sees the statement, the
 * masks and the caps before committing the grant.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import {
	DatabaseApiError,
	type DatabaseClient,
	type DatabaseColumnRules,
	type DatabaseConnection,
	type DatabaseRowFilter,
	type DatabaseScope,
} from '../../../api/database';
import { PreviewPanel } from './PreviewPanel';
import { Notice } from './shared';

/** The tiers a grant can carry, in escalation order. */
const TIERS: Array< { value: string; label: string; help: string } > = [
	{ value: 'metadata', label: __( 'Metadata', 'nvoos-pro-spa' ), help: __( 'Catalogue and plan introspection.', 'nvoos-pro-spa' ) },
	{ value: 'read', label: __( 'Read', 'nvoos-pro-spa' ), help: __( 'Structured select, aggregate and search.', 'nvoos-pro-spa' ) },
	{
		value: 'read_unmasked',
		label: __( 'Read unmasked', 'nvoos-pro-spa' ),
		help: __( 'See columns the scope marks sensitive.', 'nvoos-pro-spa' ),
	},
	{
		value: 'explain_analyze',
		label: __( 'Explain analyze', 'nvoos-pro-spa' ),
		help: __( 'EXPLAIN ANALYZE, which executes the plan.', 'nvoos-pro-spa' ),
	},
	{ value: 'export', label: __( 'Export', 'nvoos-pro-spa' ), help: __( 'Asynchronous export to the file registry.', 'nvoos-pro-spa' ) },
	{ value: 'raw_sql', label: __( 'Raw SQL', 'nvoos-pro-spa' ), help: __( 'Operator-authored SQL, one statement.', 'nvoos-pro-spa' ) },
	{
		value: 'propose_write',
		label: __( 'Propose write', 'nvoos-pro-spa' ),
		help: __( 'Build a reviewable change proposal; does not execute.', 'nvoos-pro-spa' ),
	},
	{ value: 'apply_write', label: __( 'Apply write', 'nvoos-pro-spa' ), help: __( 'Execute an approved proposal.', 'nvoos-pro-spa' ) },
	{
		value: 'admin',
		label: __( 'Admin', 'nvoos-pro-spa' ),
		help: __( 'Connection and scope administration. Never grant this to an assistant.', 'nvoos-pro-spa' ),
	},
];

/** Limit keys the server accepts. */
const LIMIT_KEYS = [ 'max_rows', 'max_bytes', 'timeout_ms', 'cell_bytes', 'offset_max', 'max_affected_rows' ] as const;

interface ColumnRuleRow {
	relation: string;
	exclude: string;
	sensitive: string;
	searchable: string;
}

interface FilterRow {
	relation: string;
	column: string;
	op: string;
	valueFrom: string;
	value: string;
}

interface ScopeDraft {
	id: string;
	label: string;
	connection: string;
	schemas: string;
	tables: string;
	principals: string;
	tiers: string[];
	limits: Record< string, string >;
	columns: ColumnRuleRow[];
	filters: FilterRow[];
}

type FieldKey = 'id' | 'connection' | 'schemas' | 'general';

function splitList( value: string ): string[] {
	return value
		.split( /[\r\n,]+/ )
		.map( ( item ) => item.trim().toLowerCase() )
		.filter( ( item ) => item !== '' );
}

function draftFromScope( scope: DatabaseScope | null ): ScopeDraft {
	const limits: Record< string, string > = {};

	for ( const key of LIMIT_KEYS ) {
		const value = scope?.limits?.[ key ];

		limits[ key ] = typeof value === 'number' ? String( value ) : '';
	}

	return {
		id: scope?.id ?? '',
		label: scope?.label ?? '',
		connection: scope?.connection ?? '',
		schemas: ( scope?.schemas ?? [] ).join( ', ' ),
		tables: ( scope?.tables ?? [] ).join( ', ' ),
		principals: ( scope?.principals ?? [] ).join( ', ' ),
		tiers: scope?.tiers ?? [ 'metadata', 'read' ],
		limits,
		columns: Object.entries( scope?.columns ?? {} ).map( ( [ relation, rules ] ) => ( {
			relation,
			exclude: ( rules.exclude ?? [] ).join( ', ' ),
			sensitive: ( rules.sensitive ?? [] ).join( ', ' ),
			searchable: ( rules.searchable ?? [] ).join( ', ' ),
		} ) ),
		filters: ( scope?.row_filter ?? [] ).map( ( rule ) => ( {
			relation: rule.relation ?? '',
			column: rule.column,
			op: rule.op,
			valueFrom: rule.value_from ?? '',
			value:
				rule.value === undefined || rule.value === null
					? ''
					: typeof rule.value === 'object'
					? JSON.stringify( rule.value )
					: String( rule.value ),
		} ) ),
	};
}

/** A wire payload whose list fields are always present (unlike a read record). */
type WireScope = DatabaseScope & {
	schemas: string[];
	tables: string[];
	principals: string[];
};

function toWire( draft: ScopeDraft ): WireScope {
	const columns: Record< string, DatabaseColumnRules > = {};

	for ( const row of draft.columns ) {
		const relation = row.relation.trim().toLowerCase();

		if ( relation === '' ) {
			continue;
		}

		const rules: DatabaseColumnRules = {};
		const exclude = splitList( row.exclude );
		const sensitive = splitList( row.sensitive );
		const searchable = splitList( row.searchable );

		if ( exclude.length > 0 ) {
			rules.exclude = exclude;
		}

		if ( sensitive.length > 0 ) {
			rules.sensitive = sensitive;
		}

		if ( searchable.length > 0 ) {
			rules.searchable = searchable;
		}

		if ( Object.keys( rules ).length > 0 ) {
			columns[ relation ] = rules;
		}
	}

	const rowFilter: DatabaseRowFilter[] = [];

	for ( const row of draft.filters ) {
		const column = row.column.trim().toLowerCase();
		const op = row.op.trim().toLowerCase();

		if ( column === '' || op === '' ) {
			continue;
		}

		const rule: DatabaseRowFilter = { column, op };

		if ( row.relation.trim() !== '' ) {
			rule.relation = row.relation.trim().toLowerCase();
		}

		if ( row.valueFrom.trim() !== '' ) {
			rule.value_from = row.valueFrom.trim().toLowerCase();
		}

		if ( row.value !== '' ) {
			rule.value = row.value;
		}

		rowFilter.push( rule );
	}

	const limits: Record< string, number > = {};

	for ( const key of LIMIT_KEYS ) {
		const raw = ( draft.limits[ key ] ?? '' ).trim();

		if ( raw === '' ) {
			continue;
		}

		const value = Number.parseInt( raw, 10 );

		if ( Number.isFinite( value ) && value >= 0 ) {
			limits[ key ] = value;
		}
	}

	return {
		id: draft.id.trim().toLowerCase(),
		label: draft.label.trim() || draft.id.trim().toLowerCase(),
		connection: draft.connection.trim().toLowerCase(),
		schemas: splitList( draft.schemas ),
		tables: splitList( draft.tables ),
		principals: splitList( draft.principals ),
		tiers: draft.tiers,
		limits,
		columns,
		row_filter: rowFilter,
	};
}

/**
 * Which field a server refusal belongs to.
 *
 * The registry returns a machine code and a human message; neither is a field
 * name. Mapping the documented cases keeps the operator's fix local instead of
 * making them re-read the whole form.
 */
function fieldForError( code: string, message: string ): FieldKey {
	if ( code === 'unknown_connection' || /connection/i.test( message ) ) {
		return 'connection';
	}

	if ( code === 'invalid_scope' || /scope id/i.test( message ) || /scope needs an id/i.test( message ) ) {
		return 'id';
	}

	if ( code === 'db_scope_not_allowed' && /schema/i.test( message ) ) {
		return 'schemas';
	}

	return 'general';
}

export function ScopeEditor( {
	client,
	connections,
	initial,
	onSaved,
	onCancel,
}: {
	client: DatabaseClient;
	connections: DatabaseConnection[];
	initial: DatabaseScope | null;
	onSaved: ( scope: DatabaseScope ) => void;
	onCancel: () => void;
} ): JSX.Element {
	const editing = initial !== null;
	const [ draft, setDraft ] = useState< ScopeDraft >( () => draftFromScope( initial ) );
	const [ errors, setErrors ] = useState< Partial< Record< FieldKey, string > > >( {} );
	const [ saving, setSaving ] = useState< boolean >( false );

	const set = < K extends keyof ScopeDraft >( key: K, value: ScopeDraft[ K ] ): void => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const toggleTier = ( tier: string, checked: boolean ): void => {
		setDraft( ( current ) => ( {
			...current,
			tiers: checked
				? [ ...current.tiers, tier ]
				: current.tiers.filter( ( value ) => value !== tier ),
		} ) );
	};

	const setColumnRow = ( index: number, patch: Partial< ColumnRuleRow > ): void => {
		setDraft( ( current ) => ( {
			...current,
			columns: current.columns.map( ( row, at ) => ( at === index ? { ...row, ...patch } : row ) ),
		} ) );
	};

	const setFilterRow = ( index: number, patch: Partial< FilterRow > ): void => {
		setDraft( ( current ) => ( {
			...current,
			filters: current.filters.map( ( row, at ) => ( at === index ? { ...row, ...patch } : row ) ),
		} ) );
	};

	const save = async (): Promise< void > => {
		const wire = toWire( draft );
		const local: Partial< Record< FieldKey, string > > = {};

		if ( ! /^[a-z0-9][a-z0-9_-]{0,63}$/.test( wire.id ) ) {
			local.id = __( 'Use lowercase letters, digits, dashes or underscores (64 characters at most).', 'nvoos-pro-spa' );
		}

		if ( wire.connection === '' ) {
			local.connection = __( 'Choose a connection.', 'nvoos-pro-spa' );
		}

		if ( wire.schemas.length === 0 ) {
			local.schemas = __( 'Name at least one schema.', 'nvoos-pro-spa' );
		} else if ( wire.schemas.includes( '*' ) ) {
			local.schemas = __( 'A wildcard schema grants the whole database and is refused.', 'nvoos-pro-spa' );
		}

		if ( Object.keys( local ).length > 0 ) {
			setErrors( local );
			return;
		}

		setSaving( true );
		setErrors( {} );

		try {
			onSaved( await client.saveScope( wire ) );
		} catch ( cause ) {
			const message = cause instanceof Error ? cause.message : __( 'The scope could not be saved.', 'nvoos-pro-spa' );
			const code = cause instanceof DatabaseApiError ? cause.code : 'request_failed';

			setErrors( { [ fieldForError( code, message ) ]: message } );
		} finally {
			setSaving( false );
		}
	};

	const wire = toWire( draft );
	const canPreview = wire.connection !== '' && wire.schemas.length > 0;

	return (
		<section className="nvoos-db-editor" aria-label={ editing ? __( 'Edit scope', 'nvoos-pro-spa' ) : __( 'New scope', 'nvoos-pro-spa' ) }>
			<header className="nvoos-db-editor__head">
				<h3 className="nvoos-db-card__title">
					{ editing ? __( 'Edit scope', 'nvoos-pro-spa' ) : __( 'New scope', 'nvoos-pro-spa' ) }
				</h3>
				<p className="nvoos-db-help">
					{ __(
						'A scope is fail-closed: no principals means nobody, no relations means nothing, and a tier that is absent denies. The grant version moves only when the grant itself changes.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			{ errors.general !== undefined && (
				<Notice tone="bad" title={ __( 'The scope was refused', 'nvoos-pro-spa' ) }>
					<p>{ errors.general }</p>
				</Notice>
			) }

			<div className="nvoos-db-editor__grid">
				<label className="nvoos-db-field">
					{ __( 'Id', 'nvoos-pro-spa' ) }
					<input
						type="text"
						value={ draft.id }
						readOnly={ editing }
						aria-invalid={ errors.id !== undefined || undefined }
						onChange={ ( event ) => set( 'id', event.target.value ) }
					/>
					{ editing ? (
						<span className="nvoos-db-help">{ __( 'An id is the record key and cannot be renamed here.', 'nvoos-pro-spa' ) }</span>
					) : null }
					{ errors.id !== undefined && <span className="nvoos-db-editor__error">{ errors.id }</span> }
				</label>

				<label className="nvoos-db-field">
					{ __( 'Label', 'nvoos-pro-spa' ) }
					<input
						type="text"
						value={ draft.label }
						placeholder={ __( 'Analyst', 'nvoos-pro-spa' ) }
						onChange={ ( event ) => set( 'label', event.target.value ) }
					/>
				</label>

				<label className="nvoos-db-field">
					{ __( 'Connection', 'nvoos-pro-spa' ) }
					<select
						value={ draft.connection }
						aria-invalid={ errors.connection !== undefined || undefined }
						onChange={ ( event ) => set( 'connection', event.target.value ) }
					>
						<option value="">{ __( 'Choose a connection…', 'nvoos-pro-spa' ) }</option>
						{ connections
							.filter( ( connection ) => ! connection.error )
							.map( ( connection ) => (
								<option key={ connection.id } value={ connection.id }>
									{ connection.label || connection.id } ({ connection.engine })
								</option>
							) ) }
					</select>
					{ errors.connection !== undefined && <span className="nvoos-db-editor__error">{ errors.connection }</span> }
				</label>
			</div>

			<div className="nvoos-db-editor__grid">
				<label className="nvoos-db-field">
					{ __( 'Schemas', 'nvoos-pro-spa' ) }
					<textarea
						rows={ 2 }
						value={ draft.schemas }
						aria-invalid={ errors.schemas !== undefined || undefined }
						onChange={ ( event ) => set( 'schemas', event.target.value ) }
					/>
					<span className="nvoos-db-help">{ __( 'Comma or newline separated. `*` is refused.', 'nvoos-pro-spa' ) }</span>
					{ errors.schemas !== undefined && <span className="nvoos-db-editor__error">{ errors.schemas }</span> }
				</label>

				<label className="nvoos-db-field">
					{ __( 'Relations', 'nvoos-pro-spa' ) }
					<textarea
						rows={ 2 }
						value={ draft.tables }
						onChange={ ( event ) => set( 'tables', event.target.value ) }
					/>
					<span className="nvoos-db-help">
						{ __( '`schema.table` values. Leave a schema empty of relations and it grants nothing.', 'nvoos-pro-spa' ) }
					</span>
				</label>

				<label className="nvoos-db-field">
					{ __( 'Principals', 'nvoos-pro-spa' ) }
					<textarea
						rows={ 2 }
						value={ draft.principals }
						onChange={ ( event ) => set( 'principals', event.target.value ) }
					/>
					<span className="nvoos-db-help">
						{ __( '`assistant:12`, `user:7` or `user:*`. An empty list grants nobody.', 'nvoos-pro-spa' ) }
					</span>
				</label>
			</div>

			<fieldset className="nvoos-db-fieldset">
				<legend>{ __( 'Tiers', 'nvoos-pro-spa' ) }</legend>
				<div className="nvoos-db-tiers">
					{ TIERS.map( ( tier ) => {
						const id = `nvoos-db-tier-${ tier.value }`;

						return (
							<div key={ tier.value } className="nvoos-db-check">
								<input
									id={ id }
									type="checkbox"
									checked={ draft.tiers.includes( tier.value ) }
									aria-describedby={ `${ id }-help` }
									onChange={ ( event ) => toggleTier( tier.value, event.target.checked ) }
								/>
								<div className="nvoos-db-check__text">
									{ /* The help text is described-by, not part of the name: a
									     checkbox announced with a whole sentence is unusable. */ }
									<label className="nvoos-db-check__title" htmlFor={ id }>
										{ tier.label }
									</label>
									<span id={ `${ id }-help` } className="nvoos-db-help">
										{ tier.help }
									</span>
								</div>
							</div>
						);
					} ) }
				</div>
			</fieldset>

			<fieldset className="nvoos-db-fieldset">
				<legend>{ __( 'Column rules', 'nvoos-pro-spa' ) }</legend>
				<p className="nvoos-db-help">
					{ __( 'Per relation: columns removed entirely, columns masked unless the scope holds read unmasked, and the columns a search may match.', 'nvoos-pro-spa' ) }
				</p>

				{ draft.columns.map( ( row, index ) => (
					<div key={ index } className="nvoos-db-editor__row">
						<label className="nvoos-db-field">
							{ __( 'Relation', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.relation }
								placeholder="public.customers"
								onChange={ ( event ) => setColumnRow( index, { relation: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Exclude', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.exclude }
								onChange={ ( event ) => setColumnRow( index, { exclude: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Mask', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.sensitive }
								onChange={ ( event ) => setColumnRow( index, { sensitive: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Searchable', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.searchable }
								onChange={ ( event ) => setColumnRow( index, { searchable: event.target.value } ) }
							/>
						</label>
						<button
							type="button"
							className="nvoos-db-btn nvoos-db-btn--ghost"
							onClick={ () =>
								set(
									'columns',
									draft.columns.filter( ( _row, at ) => at !== index )
								)
							}
						>
							{ __( 'Remove', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) ) }

				<button
					type="button"
					className="nvoos-db-btn nvoos-db-btn--ghost"
					onClick={ () =>
						set( 'columns', [
							...draft.columns,
							{ relation: '', exclude: '', sensitive: '', searchable: '' },
						] )
					}
				>
					{ __( 'Add a relation rule', 'nvoos-pro-spa' ) }
				</button>
			</fieldset>

			<fieldset className="nvoos-db-fieldset">
				<legend>{ __( 'Mandatory row filters', 'nvoos-pro-spa' ) }</legend>
				<p className="nvoos-db-help">
					{ __( 'Predicates the executor adds to every statement matching the relation. A value is bound at execution and never interpolated.', 'nvoos-pro-spa' ) }
				</p>

				{ draft.filters.map( ( row, index ) => (
					<div key={ index } className="nvoos-db-editor__row">
						<label className="nvoos-db-field">
							{ __( 'Relation', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.relation }
								placeholder={ __( 'every relation', 'nvoos-pro-spa' ) }
								onChange={ ( event ) => setFilterRow( index, { relation: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Column', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.column }
								onChange={ ( event ) => setFilterRow( index, { column: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field nvoos-db-field--narrow">
							{ __( 'Operator', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.op }
								placeholder="eq"
								onChange={ ( event ) => setFilterRow( index, { op: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Value from', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.valueFrom }
								placeholder="scope_tenant"
								onChange={ ( event ) => setFilterRow( index, { valueFrom: event.target.value } ) }
							/>
						</label>
						<label className="nvoos-db-field">
							{ __( 'Value', 'nvoos-pro-spa' ) }
							<input
								type="text"
								value={ row.value }
								onChange={ ( event ) => setFilterRow( index, { value: event.target.value } ) }
							/>
						</label>
						<button
							type="button"
							className="nvoos-db-btn nvoos-db-btn--ghost"
							onClick={ () =>
								set(
									'filters',
									draft.filters.filter( ( _row, at ) => at !== index )
								)
							}
						>
							{ __( 'Remove', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) ) }

				<button
					type="button"
					className="nvoos-db-btn nvoos-db-btn--ghost"
					onClick={ () =>
						set( 'filters', [
							...draft.filters,
							{ relation: '', column: '', op: 'eq', valueFrom: '', value: '' },
						] )
					}
				>
					{ __( 'Add a row filter', 'nvoos-pro-spa' ) }
				</button>
			</fieldset>

			<fieldset className="nvoos-db-fieldset">
				<legend>{ __( 'Limit overrides', 'nvoos-pro-spa' ) }</legend>
				<p className="nvoos-db-help">{ __( 'Leave a field blank to inherit the toolkit default. A scope can tighten a cap, never loosen it.', 'nvoos-pro-spa' ) }</p>
				<div className="nvoos-db-editor__grid">
					{ LIMIT_KEYS.map( ( key ) => (
						<label key={ key } className="nvoos-db-field">
							{ key }
							<input
								type="number"
								min={ 0 }
								value={ draft.limits[ key ] ?? '' }
								onChange={ ( event ) =>
									set( 'limits', { ...draft.limits, [ key ]: event.target.value } )
								}
							/>
						</label>
					) ) }
				</div>
			</fieldset>

			<fieldset className="nvoos-db-fieldset">
				<legend>{ __( 'Preview', 'nvoos-pro-spa' ) }</legend>
				{ canPreview ? (
					<PreviewPanel client={ client } scope={ wire } initialRelation={ wire.tables[ 0 ] ?? '' } />
				) : (
					<p className="nvoos-db-help">
						{ __( 'Choose a connection and name a schema to compile a preview.', 'nvoos-pro-spa' ) }
					</p>
				) }
			</fieldset>

			<div className="nvoos-db-editor__actions">
				<button
					type="button"
					className="nvoos-db-btn"
					disabled={ saving }
					aria-busy={ saving || undefined }
					onClick={ () => void save() }
				>
					{ saving ? __( 'Saving…', 'nvoos-pro-spa' ) : __( 'Save scope', 'nvoos-pro-spa' ) }
				</button>
				<button type="button" className="nvoos-db-btn nvoos-db-btn--ghost" disabled={ saving } onClick={ onCancel }>
					{ __( 'Cancel', 'nvoos-pro-spa' ) }
				</button>
			</div>
		</section>
	);
}
