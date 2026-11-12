/**
 * EntityForm — the workbench's create/edit panel, generated from columns.
 *
 * One control per manifest column, with required/type validation done locally
 * and server field errors (from `ToolkitsApiError.fields`) mapped back onto
 * the inputs. The panel is a labelled dialog rendered in-page.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import type { ToolkitEntity } from '../../../api/toolkits';
import {
	formStateFromRecord,
	payloadFromFormState,
} from '../format';

export interface EntityFormProps {
	entity: ToolkitEntity;
	mode: 'create' | 'update';
	/** Stored payload when editing. */
	initial?: Record< string, unknown >;
	/** Server-side field errors. */
	fieldErrors?: Record< string, string >;
	busy?: boolean;
	onSubmit: ( payload: Record< string, unknown > ) => void;
	onCancel: () => void;
}

export function EntityForm( {
	entity,
	mode,
	initial,
	fieldErrors = {},
	busy = false,
	onSubmit,
	onCancel,
}: EntityFormProps ): JSX.Element {
	const [ form, setForm ] = useState< Record< string, string > >( () =>
		formStateFromRecord( entity, initial ),
	);
	const [ localErrors, setLocalErrors ] = useState< Record< string, string > >( {} );

	const idField = entity.id_field;
	const title = mode === 'create'
		? __( 'New', 'nvoos-pro-spa' ) + ' ' + entity.singular.toLowerCase()
		: __( 'Edit', 'nvoos-pro-spa' ) + ' ' + entity.singular.toLowerCase();

	const errors = useMemo(
		() => ( { ...localErrors, ...fieldErrors } ),
		[ localErrors, fieldErrors ],
	);

	const submit = (): void => {
		const nextErrors: Record< string, string > = {};

		for ( const column of entity.columns ) {
			const raw = ( form[ column.key ] ?? '' ).trim();

			if ( column.required && raw === '' && ! ( column.type === 'boolean' ) ) {
				nextErrors[ column.key ] = __( 'This field is required.', 'nvoos-pro-spa' );
				continue;
			}

			if ( raw !== '' && ( column.type === 'number' || column.type === 'currency' ) ) {
				if ( ! Number.isFinite( Number( raw ) ) ) {
					nextErrors[ column.key ] = __( 'Enter a number.', 'nvoos-pro-spa' );
				}
			}
		}

		// The business key is the record's address — never editable after create.
		if ( mode === 'update' && form[ idField ] !== undefined ) {
			nextErrors[ idField ] = __( 'The identifier cannot be changed.', 'nvoos-pro-spa' );
		}

		setLocalErrors( nextErrors );

		if ( Object.keys( nextErrors ).length > 0 ) {
			return;
		}

		onSubmit( payloadFromFormState( entity, form, mode ) );
	};

	return (
		<div className="nvoos-pro-spa-toolkits__drawer" role="dialog" aria-modal="true" aria-label={ title }>
			<form
				className="nvoos-pro-spa-toolkits__drawer-card"
				onSubmit={ ( event ) => {
					event.preventDefault();
					submit();
				} }
			>
				<header className="nvoos-pro-spa-toolkits__drawer-header">
					<h3 className="nvoos-pro-spa-toolkits__drawer-title">{ title }</h3>
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__icon-btn"
						aria-label={ __( 'Close', 'nvoos-pro-spa' ) }
						onClick={ onCancel }
					>
						✕
					</button>
				</header>

				<div className="nvoos-pro-spa-toolkits__drawer-body">
					{ entity.columns.map( ( column ) => {
						const id = `nvoos-toolkit-field-${ column.key }`;
						const invalid = Boolean( errors[ column.key ] );
						const disabled = mode === 'update' && column.key === idField;

						return (
							<div
								key={ column.key }
								className={ `nvoos-pro-spa-toolkits__field${
									column.type === 'boolean' ? ' nvoos-pro-spa-toolkits__field--check' : ''
								}` }
							>
								<label className="nvoos-pro-spa-toolkits__label" htmlFor={ id }>
									{ column.label }
									{ column.required && (
										<span className="nvoos-pro-spa-toolkits__required" aria-hidden="true">
											*
										</span>
									) }
								</label>

								{ column.type === 'boolean' ? (
									<label className="nvoos-pro-spa-toolkits__switch">
										<input
											id={ id }
											type="checkbox"
											checked={ form[ column.key ] === 'true' }
											disabled={ busy }
											onChange={ ( event ) =>
												setForm( ( current ) => ( {
													...current,
													[ column.key ]: event.target.checked ? 'true' : 'false',
												} ) )
											}
										/>
										<span className="nvoos-pro-spa-toolkits__switch-track" aria-hidden="true">
											<span className="nvoos-pro-spa-toolkits__switch-knob" />
										</span>
										<span className="nvoos-pro-spa-toolkits__switch-label">
											{ form[ column.key ] === 'true'
												? __( 'On', 'nvoos-pro-spa' )
												: __( 'Off', 'nvoos-pro-spa' ) }
										</span>
									</label>
								) : column.type === 'enum' ? (
									<select
										id={ id }
										className="nvoos-pro-spa-toolkits__input"
										value={ form[ column.key ] ?? '' }
										disabled={ disabled || busy }
										aria-invalid={ invalid || undefined }
										onChange={ ( event ) =>
											setForm( ( current ) => ( {
												...current,
												[ column.key ]: event.target.value,
											} ) )
										}
									>
										<option value="">{ __( 'Choose…', 'nvoos-pro-spa' ) }</option>
										{ ( column.enum ?? [] ).map( ( option ) => (
											<option key={ option } value={ option }>
												{ option }
											</option>
										) ) }
									</select>
								) : (
									<input
										id={ id }
										type={
											column.type === 'number' || column.type === 'currency'
												? 'number'
												: column.type === 'date'
												? 'date'
												: 'text'
										}
										step={ column.type === 'currency' ? '0.01' : undefined }
										className="nvoos-pro-spa-toolkits__input"
										value={ form[ column.key ] ?? '' }
										disabled={ disabled || busy }
										aria-invalid={ invalid || undefined }
										onChange={ ( event ) =>
											setForm( ( current ) => ( {
												...current,
												[ column.key ]: event.target.value,
											} ) )
										}
									/>
								) }

								{ invalid && (
									<p className="nvoos-pro-spa-toolkits__field-error" role="alert">
										{ errors[ column.key ] }
									</p>
								) }
							</div>
						);
					} ) }
				</div>

				<footer className="nvoos-pro-spa-toolkits__drawer-actions">
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
						onClick={ onCancel }
						disabled={ busy }
					>
						{ __( 'Cancel', 'nvoos-pro-spa' ) }
					</button>
					<button type="submit" className="nvoos-pro-spa-toolkits__btn" disabled={ busy }>
						{ busy
							? __( 'Saving…', 'nvoos-pro-spa' )
							: mode === 'create'
							? __( 'Create', 'nvoos-pro-spa' )
							: __( 'Save changes', 'nvoos-pro-spa' ) }
					</button>
				</footer>
			</form>
		</div>
	);
}
