/**
 * ToolkitSettingsScreen — the manifest's settings form.
 *
 * Renders one control per declared field, validates locally where the type
 * allows, and maps the server's per-field validation errors back onto the
 * inputs. Secret fields show "saved" state without ever echoing the value.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useEffect, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import { ToolkitsApiError, type ToolkitSettingField } from '../../../api/toolkits';
import type { ToolkitScreenProps } from '../ToolkitPage';

/** Mirrors `SettingsSchema::MASKED_PLACEHOLDER` on the server. */
const MASKED_PLACEHOLDER = '**************';

type FormState = Record< string, string >;

function toFormState( schema: ToolkitSettingField[], values: Record< string, unknown > ): FormState {
	const state: FormState = {};

	for ( const field of schema ) {
		const value = values[ field.key ];

		if ( field.type === 'secret' ) {
			// Never hydrate a secret: a masked value means "stored, unchanged".
			state[ field.key ] = '';
			continue;
		}

		if ( field.type === 'boolean' ) {
			state[ field.key ] = value === true ? 'true' : 'false';
			continue;
		}

		state[ field.key ] = value === null || value === undefined ? '' : String( value );
	}

	return state;
}

function secretIsStored( field: ToolkitSettingField, values: Record< string, unknown > ): boolean {
	const value = values[ field.key ];
	return field.type === 'secret' && value === MASKED_PLACEHOLDER;
}

export function SettingsScreen( { client, manifest }: ToolkitScreenProps ): JSX.Element {
	const [ schema, setSchema ] = useState< ToolkitSettingField[] >( [] );
	const [ values, setValues ] = useState< Record< string, unknown > >( {} );
	const [ form, setForm ] = useState< FormState >( {} );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ savedAt, setSavedAt ] = useState< number | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ fieldErrors, setFieldErrors ] = useState< Record< string, string > >( {} );

	const load = useCallback(
		async ( signal?: AbortSignal ): Promise< void > => {
			setLoading( true );
			setError( null );

			try {
				const state = await client.settings( manifest.slug, signal );
				setSchema( state.schema );
				setValues( state.values );
				setForm( toFormState( state.schema, state.values ) );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to load settings.', 'nvoos-pro-spa' ),
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

	const save = useCallback( async (): Promise< void > => {
		setSaving( true );
		setError( null );
		setFieldErrors( {} );

		const payload: Record< string, unknown > = {};

		for ( const field of schema ) {
			const raw = form[ field.key ];

			if ( field.type === 'secret' ) {
				if ( raw !== '' ) {
					payload[ field.key ] = raw;
				}
				continue;
			}

			if ( field.type === 'boolean' ) {
				payload[ field.key ] = raw === 'true';
				continue;
			}

			if ( field.type === 'number' ) {
				payload[ field.key ] = raw === '' ? null : Number( raw );
				continue;
			}

			payload[ field.key ] = raw;
		}

		try {
			const next = await client.saveSettings( manifest.slug, payload );
			setValues( next );
			setForm( toFormState( schema, next ) );
			setSavedAt( Date.now() );
		} catch ( cause ) {
			if ( cause instanceof ToolkitsApiError ) {
				setError( cause.message );
				setFieldErrors( cause.fields );
			} else {
				setError( __( 'Unable to save settings.', 'nvoos-pro-spa' ) );
			}
		} finally {
			setSaving( false );
		}
	}, [ client, form, manifest.slug, schema ] );

	if ( loading ) {
		return (
			<div className="nvoos-pro-spa-toolkits__panel-loading" role="status">
				<span className="nvoos-pro-spa-page__spinner" />
				{ __( 'Loading settings…', 'nvoos-pro-spa' ) }
			</div>
		);
	}

	if ( schema.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-toolkits__empty" role="status">
				<span className="nvoos-pro-spa-toolkits__empty-icon" aria-hidden="true">
					⚙️
				</span>
				<h3>{ __( 'No settings declared', 'nvoos-pro-spa' ) }</h3>
				<p>{ __( 'This toolkit ships without configurable fields.', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	return (
		<form
			className="nvoos-pro-spa-toolkits__form"
			onSubmit={ ( event ) => {
				event.preventDefault();
				void save();
			} }
		>
			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<div className="nvoos-pro-spa-toolkits__form-grid">
				{ schema.map( ( field ) => {
					const id = `nvoos-toolkit-setting-${ field.key }`;
					const describedBy = field.description ? `${ id }-help` : undefined;
					const invalid = Boolean( fieldErrors[ field.key ] );
					const stored = secretIsStored( field, values );

					return (
						<div
							key={ field.key }
							className={ `nvoos-pro-spa-toolkits__field${
								field.type === 'boolean' ? ' nvoos-pro-spa-toolkits__field--check' : ''
							}` }
						>
							<label className="nvoos-pro-spa-toolkits__label" htmlFor={ id }>
								{ field.label }
							</label>

							{ field.type === 'boolean' ? (
								<label className="nvoos-pro-spa-toolkits__switch">
									<input
										id={ id }
										type="checkbox"
										checked={ form[ field.key ] === 'true' }
										aria-describedby={ describedBy }
										onChange={ ( event ) =>
											setForm( ( current ) => ( {
												...current,
												[ field.key ]: event.target.checked ? 'true' : 'false',
											} ) )
										}
									/>
									<span className="nvoos-pro-spa-toolkits__switch-track" aria-hidden="true">
										<span className="nvoos-pro-spa-toolkits__switch-knob" />
									</span>
									<span className="nvoos-pro-spa-toolkits__switch-label">
										{ form[ field.key ] === 'true'
											? __( 'On', 'nvoos-pro-spa' )
											: __( 'Off', 'nvoos-pro-spa' ) }
									</span>
								</label>
							) : field.type === 'select' ? (
								<select
									id={ id }
									className="nvoos-pro-spa-toolkits__input"
									value={ form[ field.key ] ?? '' }
									aria-describedby={ describedBy }
									aria-invalid={ invalid || undefined }
									onChange={ ( event ) =>
										setForm( ( current ) => ( { ...current, [ field.key ]: event.target.value } ) )
									}
								>
									<option value="">{ __( 'Choose…', 'nvoos-pro-spa' ) }</option>
									{ normalizeOptions( field ).map( ( option ) => (
										<option key={ option.value } value={ option.value }>
											{ option.label }
										</option>
									) ) }
								</select>
							) : (
								<input
									id={ id }
									type={ field.type === 'secret' ? 'password' : field.type === 'number' ? 'number' : 'text' }
									className="nvoos-pro-spa-toolkits__input"
									value={ form[ field.key ] ?? '' }
									autoComplete={ field.type === 'secret' ? 'new-password' : undefined }
									placeholder={
										field.type === 'secret' && stored
											? __( 'Saved — leave blank to keep', 'nvoos-pro-spa' )
											: undefined
									}
									aria-describedby={ describedBy }
									aria-invalid={ invalid || undefined }
									onChange={ ( event ) =>
										setForm( ( current ) => ( { ...current, [ field.key ]: event.target.value } ) )
									}
								/>
							) }

							{ field.description && (
								<p className="nvoos-pro-spa-toolkits__help" id={ describedBy }>
									{ field.description }
								</p>
							) }

							{ invalid && (
								<p className="nvoos-pro-spa-toolkits__field-error" role="alert">
									{ fieldErrors[ field.key ] }
								</p>
							) }
						</div>
					);
				} ) }
			</div>

			<footer className="nvoos-pro-spa-toolkits__form-actions">
				<button type="submit" className="nvoos-pro-spa-toolkits__btn" disabled={ saving }>
					{ saving ? __( 'Saving…', 'nvoos-pro-spa' ) : __( 'Save settings', 'nvoos-pro-spa' ) }
				</button>

				{ savedAt !== null && ! saving && (
					<span className="nvoos-pro-spa-toolkits__saved" role="status">
						{ __( 'Saved', 'nvoos-pro-spa' ) }
					</span>
				) }
			</footer>
		</form>
	);
}

function normalizeOptions( field: ToolkitSettingField ): Array< { value: string; label: string } > {
	const options = field.options;

	if ( Array.isArray( options ) ) {
		return options.map( ( value ) => ( { value: String( value ), label: String( value ) } ) );
	}

	if ( options && typeof options === 'object' ) {
		return Object.entries( options ).map( ( [ value, label ] ) => ( {
			value,
			label: String( label ),
		} ) );
	}

	return [];
}
