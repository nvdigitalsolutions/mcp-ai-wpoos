// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Scopes panel — the grants, and the editor that changes them.
 *
 * The default view is the read-only card list: every scope's relations, masks,
 * mandatory filters, tiers and principals side by side, so "what can the
 * assistant read?" has an answer that is not "open five records". On top of
 * that the panel now offers the three operations an operator needs without
 * leaving the console — create, edit and delete — plus a compile-only preview
 * of any saved grant.
 *
 * Writes go through the registry (`POST /scopes`, `DELETE /scopes/{id}`), never
 * through a hand-rolled validator, so the console and wp-admin cannot disagree
 * about what a valid scope is.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type { DatabaseClient, DatabaseOverview, DatabaseScope } from '../../../api/database';
import { PreviewPanel } from './PreviewPanel';
import { ScopeEditor } from './ScopeEditor';
import { Chips, EmptyState, Notice } from './shared';

type Mode = { kind: 'list' } | { kind: 'create' } | { kind: 'edit'; scope: DatabaseScope };

export function ScopesPanel( {
	client,
	overview,
	onRefresh,
}: {
	client: DatabaseClient;
	overview: DatabaseOverview;
	onRefresh: () => void;
} ): JSX.Element {
	const [ mode, setMode ] = useState< Mode >( { kind: 'list' } );
	const [ previewFor, setPreviewFor ] = useState< string | null >( null );
	const [ deleting, setDeleting ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ flash, setFlash ] = useState< string | null >( null );

	if ( ! overview.runtime.available ) {
		return <Notice tone="bad" title={ __( 'The database kernel is not available', 'nvoos-pro-spa' ) }>{ overview.runtime.reason_label }</Notice>;
	}

	const remove = async ( id: string ): Promise< void > => {
		if (
			! window.confirm(
				__( 'Delete this scope? The grant is removed immediately and any tool that relied on it will refuse.', 'nvoos-pro-spa' )
			)
		) {
			return;
		}

		setDeleting( id );
		setError( null );

		try {
			await client.deleteScope( id );
			setPreviewFor( null );
			onRefresh();
		} catch ( cause ) {
			setError( cause instanceof Error ? cause.message : __( 'The scope could not be deleted.', 'nvoos-pro-spa' ) );
		} finally {
			setDeleting( null );
		}
	};

	const saved = ( scope: DatabaseScope ): void => {
		setFlash(
			sprintf(
				/* translators: 1: scope id, 2: grant version. */
				__( 'Saved %1$s at version %2$d.', 'nvoos-pro-spa' ),
				scope.id,
				scope.version ?? 1
			)
		);
		setMode( { kind: 'list' } );
		onRefresh();
	};

	const toolbar = (
		<div className="nvoos-db-toolbar">
			<button
				type="button"
				className="nvoos-db-btn"
				disabled={ mode.kind !== 'list' || overview.connections.length === 0 }
				 onClick={ () => {
					setFlash( null );
					setMode( { kind: 'create' } );
				 } }
			>
				{ __( 'New scope', 'nvoos-pro-spa' ) }
			</button>
			{ overview.admin_url !== '' && (
				<a className="nvoos-db-link" href={ overview.admin_url } target="_blank" rel="noreferrer">
					{ __( 'Open the toolkit settings', 'nvoos-pro-spa' ) }
				</a>
			) }
		</div>
	);

	if ( mode.kind !== 'list' ) {
		return (
			<>
				{ toolbar }
				<ScopeEditor
					key={ mode.kind === 'edit' ? mode.scope.id : 'new' }
					client={ client }
					connections={ overview.connections }
					initial={ mode.kind === 'edit' ? mode.scope : null }
					onSaved={ saved }
					onCancel={ () => setMode( { kind: 'list' } ) }
				/>
			</>
		);
	}

	if ( overview.scopes.length === 0 ) {
		return (
			<>
				{ toolbar }
				<EmptyState icon="🔐" title={ __( 'No scopes declared', 'nvoos-pro-spa' ) }>
					<p>
						{ overview.connections.length === 0
							? __(
									'A connection on its own grants nothing, and a scope needs one to point at. Add a connection in wp-admin first.',
									'nvoos-pro-spa'
							  )
							: __(
									'A connection on its own grants nothing. A scope names the schemas, tables, columns and principals, and without one every tool refuses.',
									'nvoos-pro-spa'
							  ) }
					</p>
				</EmptyState>
			</>
		);
	}

	return (
		<>
			{ toolbar }

			{ error !== null && (
				<Notice tone="bad" title={ __( 'The scope could not be deleted', 'nvoos-pro-spa' ) }>
					<p>{ error }</p>
				</Notice>
			) }

			{ flash !== null && (
				<Notice tone="info" title={ __( 'Saved', 'nvoos-pro-spa' ) }>
					<p>{ flash }</p>
				</Notice>
			) }

			<p className="nvoos-db-lede">
				{ __(
					'Every scope is fail-closed: no principals means nobody, no tables means nothing, and a tier that is absent denies.',
					'nvoos-pro-spa'
				) }
			</p>

			<ul className="nvoos-db-scopes">
				{ overview.scopes.map( ( scope ) => (
					<li key={ scope.id } className="nvoos-db-scope">
						{ scope.error ? <InvalidScope scope={ scope } /> : <ValidScope scope={ scope } /> }

						<div className="nvoos-db-scope__actions">
							{ ! scope.error && (
								<>
									<button
										type="button"
										className="nvoos-db-btn nvoos-db-btn--ghost"
										onClick={ () => {
										setFlash( null );
										setMode( { kind: 'edit', scope } );
									} }
									>
										{ __( 'Edit', 'nvoos-pro-spa' ) }
									</button>
									<button
										type="button"
										className="nvoos-db-btn nvoos-db-btn--ghost"
										aria-expanded={ previewFor === scope.id }
										onClick={ () => setPreviewFor( previewFor === scope.id ? null : scope.id ) }
									>
										{ previewFor === scope.id
											? __( 'Hide preview', 'nvoos-pro-spa' )
											: __( 'Preview', 'nvoos-pro-spa' ) }
									</button>
								</>
							) }
							<button
								type="button"
								className="nvoos-db-btn nvoos-db-btn--ghost"
								disabled={ deleting !== null }
								aria-busy={ deleting === scope.id || undefined }
								onClick={ () => void remove( scope.id ) }
							>
								{ deleting === scope.id
									? __( 'Deleting…', 'nvoos-pro-spa' )
									: __( 'Delete', 'nvoos-pro-spa' ) }
							</button>
						</div>

						{ ! scope.error && previewFor === scope.id && (
							<PreviewPanel
								client={ client }
								scope={ scope }
								initialRelation={ ( scope.tables ?? [] ).find( ( table ) => table !== '*' ) ?? '' }
							/>
						) }
					</li>
				) ) }
			</ul>
		</>
	);
}

function ValidScope( { scope }: { scope: DatabaseScope } ): JSX.Element {
	const tables = scope.tables ?? [];
	const columnRelations = Object.keys( scope.columns ?? {} );

	return (
		<>
			<header className="nvoos-db-scope__head">
				<div>
					<h3 className="nvoos-db-scope__title">{ scope.label || scope.id }</h3>
					<p className="nvoos-db-scope__meta">
						<code>{ scope.id }</code>
						<span aria-hidden="true">·</span>
						{ __( 'connection', 'nvoos-pro-spa' ) } <code>{ scope.connection || '—' }</code>
						<span aria-hidden="true">·</span>
						{ __( 'version', 'nvoos-pro-spa' ) } { scope.version ?? 1 }
					</p>
				</div>

				<Chips label={ __( 'Tiers', 'nvoos-pro-spa' ) } values={ scope.tiers } tone="ok" />
			</header>

			<div className="nvoos-db-scope__body">
				<Chips label={ __( 'Schemas', 'nvoos-pro-spa' ) } values={ scope.schemas } />
				<Chips
					label={ __( 'Relations', 'nvoos-pro-spa' ) }
					values={ tables.map( ( table ) => ( table === '*' ? __( 'all relations in scope', 'nvoos-pro-spa' ) : table ) ) }
				/>
				<Chips label={ __( 'Principals', 'nvoos-pro-spa' ) } values={ scope.principals } />

				{ columnRelations.length > 0 && (
					<div className="nvoos-db-rules">
						<h4 className="nvoos-db-rules__title">{ __( 'Column rules', 'nvoos-pro-spa' ) }</h4>
						<ul className="nvoos-db-list">
							{ columnRelations.map( ( relation ) => {
								const rules = scope.columns?.[ relation ] ?? {};

								return (
									<li key={ relation }>
										<code>{ relation }</code>
										<Chips label={ __( 'masked', 'nvoos-pro-spa' ) } values={ rules.sensitive } tone="warn" />
										<Chips label={ __( 'excluded', 'nvoos-pro-spa' ) } values={ rules.exclude } tone="bad" />
										<Chips label={ __( 'searchable', 'nvoos-pro-spa' ) } values={ rules.searchable } />
									</li>
								);
							} ) }
						</ul>
					</div>
				) }

				{ ( scope.row_filter ?? [] ).length > 0 && (
					<div className="nvoos-db-rules">
						<h4 className="nvoos-db-rules__title">{ __( 'Mandatory row filters', 'nvoos-pro-spa' ) }</h4>
						<ul className="nvoos-db-list">
							{ ( scope.row_filter ?? [] ).map( ( rule, index ) => (
								<li key={ `${ rule.relation ?? '*' }-${ rule.column }-${ index }` }>
									{ rule.relation ? <code>{ rule.relation }</code> : <em>{ __( 'every relation', 'nvoos-pro-spa' ) }</em> }
									{ ' ' }
									<code>
										{ rule.column } { rule.op } { rule.value_from ?? JSON.stringify( rule.value ?? '' ) }
									</code>
								</li>
							) ) }
						</ul>
					</div>
				) }

				{ Object.keys( scope.limits ?? {} ).length > 0 && (
					<div className="nvoos-db-rules">
						<h4 className="nvoos-db-rules__title">{ __( 'Limit overrides', 'nvoos-pro-spa' ) }</h4>
						<p className="nvoos-db-scope__limits">
							{ Object.entries( scope.limits ?? {} ).map( ( [ key, value ] ) => (
								<span key={ key } className="nvoos-db-chip">
									{ key }: { String( value ) }
								</span>
							) ) }
						</p>
					</div>
				) }
			</div>
		</>
	);
}

function InvalidScope( { scope }: { scope: DatabaseScope } ): JSX.Element {
	return (
		<>
			<header className="nvoos-db-scope__head">
				<div>
					<h3 className="nvoos-db-scope__title">
						<code>{ scope.id }</code>
					</h3>
					<p className="nvoos-db-scope__meta">{ __( 'This stored record could not be validated.', 'nvoos-pro-spa' ) }</p>
				</div>
				<span className="nvoos-db-pill nvoos-db-pill--bad">{ scope.error }</span>
			</header>
			<p className="nvoos-db-scope__body">
				{ __(
					'The record is shown rather than hidden because it still exists in storage. Correct it in wp-admin, or delete it here.',
					'nvoos-pro-spa'
				) }
			</p>
		</>
	);
}
