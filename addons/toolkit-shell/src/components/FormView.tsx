/**
 * Form view — manifest-driven create/edit form for a Resource.
 *
 * Honours `field.readonly` (omitted), `field.required` (HTML required),
 * `field.type` (input type / select for enum / textarea for text).
 *
 * @since 0.2.0
 */

import { useEffect, useState, type FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import type { Resource } from '../api/types';

interface FormViewProps {
	resource: Resource;
	row: Record<string, unknown> | null;
	mode: 'create' | 'edit';
	saving?: boolean;
	error?: string | null;
	onSubmit: ( values: Record<string, unknown> ) => void;
	onCancel?: () => void;
}

export function FormView( { resource, row, mode, saving, error, onSubmit, onCancel }: FormViewProps ) {
	const [ values, setValues ] = useState<Record<string, unknown>>( {} );

	useEffect( () => {
		const initial: Record<string, unknown> = {};
		resource.fields.forEach( ( f ) => {
			if ( row && row[ f.name ] !== undefined ) {
				initial[ f.name ] = row[ f.name ];
			} else {
				initial[ f.name ] = defaultValueFor( f.type );
			}
		} );
		setValues( initial );
	}, [ resource, row ] );

	const handleChange = ( name: string, value: unknown ) => {
		setValues( ( prev ) => ( { ...prev, [ name ]: value } ) );
	};

	const handleSubmit = ( e: FormEvent ) => {
		e.preventDefault();
		// Strip readonly fields from the payload.
		const payload: Record<string, unknown> = {};
		resource.fields.forEach( ( f ) => {
			if ( ! f.readonly ) {
				payload[ f.name ] = values[ f.name ];
			}
		} );
		onSubmit( payload );
	};

	return (
		<form className="nvoos-toolkit-shell-form" onSubmit={ handleSubmit }>
			<header className="nvoos-toolkit-shell-form-header">
				<h3>
					{ mode === 'create' ? __( 'Create', 'nvoos-toolkit-shell' ) : __( 'Edit', 'nvoos-toolkit-shell' ) }{ ' ' }
					{ resource.label || resource.name }
				</h3>
			</header>
			{ error && <p className="nvoos-toolkit-shell-error">{ error }</p> }
			<div className="nvoos-toolkit-shell-form-fields">
				{ resource.fields
					.filter( ( f ) => ! f.readonly )
					.map( ( field ) => (
						<label key={ field.name } className="nvoos-toolkit-shell-form-field">
							<span>
								{ field.label || field.name }
								{ field.required && (
									<span aria-label="required" className="nvoos-toolkit-shell-required">
										{ ' *' }
									</span>
								) }
							</span>
							{ renderInput( field, values[ field.name ], handleChange ) }
						</label>
					) ) }
			</div>
			<footer className="nvoos-toolkit-shell-form-footer">
				<button type="submit" disabled={ saving }>
					{ saving ? __( 'Saving…', 'nvoos-toolkit-shell' ) : mode === 'create' ? __( 'Create', 'nvoos-toolkit-shell' ) : __( 'Save', 'nvoos-toolkit-shell' ) }
				</button>
				{ onCancel && (
					<button type="button" onClick={ onCancel } disabled={ saving }>
						{ __( 'Cancel', 'nvoos-toolkit-shell' ) }
					</button>
				) }
			</footer>
		</form>
	);
}

function renderInput(
	field: { name: string; type: string; required: boolean; options?: string[] },
	value: unknown,
	onChange: ( name: string, value: unknown ) => void
) {
	const common = {
		name: field.name,
		required: field.required,
	};
	if ( field.type === 'enum' && field.options ) {
		return (
			<select
				{ ...common }
				value={ String( value ?? '' ) }
				onChange={ ( e ) => onChange( field.name, e.target.value ) }
			>
				<option value="">—</option>
				{ field.options.map( ( opt ) => (
					<option key={ opt } value={ opt }>
						{ opt }
					</option>
				) ) }
			</select>
		);
	}
	if ( field.type === 'text' ) {
		return (
			<textarea
				{ ...common }
				rows={ 4 }
				value={ String( value ?? '' ) }
				onChange={ ( e ) => onChange( field.name, e.target.value ) }
			/>
		);
	}
	if ( field.type === 'boolean' ) {
		return (
			<input
				type="checkbox"
				name={ field.name }
				checked={ Boolean( value ) }
				onChange={ ( e ) => onChange( field.name, e.target.checked ) }
			/>
		);
	}
	const inputType =
		field.type === 'email'
			? 'email'
			: field.type === 'url'
			? 'url'
			: field.type === 'number'
			? 'number'
			: field.type === 'integer'
			? 'number'
			: field.type === 'date'
			? 'date'
			: field.type === 'datetime'
			? 'datetime-local'
			: 'text';
	return (
		<input
			{ ...common }
			type={ inputType }
			value={ String( value ?? '' ) }
			onChange={ ( e ) =>
				onChange(
					field.name,
					inputType === 'number' && e.target.value !== ''
						? Number( e.target.value )
						: e.target.value
				)
			}
		/>
	);
}

function defaultValueFor( type: string ): unknown {
	if ( type === 'boolean' ) {
		return false;
	}
	if ( type === 'number' || type === 'integer' ) {
		return '';
	}
	return '';
}
