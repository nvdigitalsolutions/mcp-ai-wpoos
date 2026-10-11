/**
 * Form view — manifest-driven create/edit form for a Resource.
 *
 * Validation is react-hook-form + zod: a zod schema is built at runtime
 * from the manifest's field declarations (required/enum/boolean/number),
 * so backend expectations stay the single source of truth and every
 * failure surfaces per-field, announced via role="alert".
 *
 * Enum fields render as Radix Select, booleans as Radix Checkbox, long
 * text as textarea; everything else keeps native inputs.
 *
 * @since 0.2.0
 * @since 0.3.0 RHF + zod + Radix primitives.
 */

import { __ } from '@wordpress/i18n';
import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, useForm } from 'react-hook-form';
import { useMemo } from 'react';
import { z } from 'zod';
import type { Field, Resource } from '../api/types';
import { Button } from './ui/button';
import { Checkbox, CheckboxIndicator } from './ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';

interface FormViewProps {
	resource: Resource;
	row: Record<string, unknown> | null;
	mode: 'create' | 'edit';
	saving?: boolean;
	error?: string | null;
	onSubmit: ( values: Record<string, unknown> ) => void;
	onCancel?: () => void;
}

type FormValues = Record<string, unknown>;

export function FormView( { resource, row, mode, saving, error, onSubmit, onCancel }: FormViewProps ) {
	// The schema and resolver must be referentially stable: RHF re-validates
	// whenever the resolver identity changes, which would reset controlled
	// Radix inputs (see FormView.test.tsx).
	const schema = useMemo( () => buildSchema( resource.fields ), [ resource ] );
	const resolver = useMemo( () => zodResolver( schema ), [ schema ] );
	const defaultValues = useMemo(
		() => buildDefaults( resource.fields, row ),
		[ resource, row ]
	);
	const {
		control,
		register,
		handleSubmit,
		formState: { errors },
	} = useForm< FormValues >( {
		resolver,
		defaultValues,
	} );

	const submit = handleSubmit( ( values ) => {
		// Normalize: empty strings become undefined; readonly fields never
		// entered the form so they are absent from the payload already.
		const payload: FormValues = {};
		resource.fields.forEach( ( field ) => {
			if ( field.readonly ) {
				return;
			}
			const value = values[ field.name ];
			if ( value === '' || value === null || value === undefined ) {
				return;
			}
			payload[ field.name ] = value;
		} );
		onSubmit( payload );
	} );

	return (
		<form className="nvoos-toolkit-shell-form" onSubmit={ submit } noValidate>
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
					.map( ( field ) => {
						const fieldError = errors[ field.name ];
						return (
							<div key={ field.name } className="nvoos-toolkit-shell-form-field">
								<label htmlFor={ `nvoos-ts-${ field.name }` }>
									<span>
										{ field.label || field.name }
										{ field.required && (
											<span aria-label="required" className="nvoos-toolkit-shell-required">
												{ ' *' }
											</span>
										) }
									</span>
								</label>
								<FieldInput
									field={ field }
									control={ control }
									register={ register }
								/>
								{ fieldError && (
									<p role="alert" className="nvoos-toolkit-shell-form-error">
										{ String( fieldError.message ?? __( 'Invalid value.', 'nvoos-toolkit-shell' ) ) }
									</p>
								) }
							</div>
						);
					} ) }
			</div>
			<footer className="nvoos-toolkit-shell-form-footer">
				<Button type="submit" disabled={ saving }>
					{ saving ? __( 'Saving…', 'nvoos-toolkit-shell' ) : mode === 'create' ? __( 'Create', 'nvoos-toolkit-shell' ) : __( 'Save', 'nvoos-toolkit-shell' ) }
				</Button>
				{ onCancel && (
					<Button type="button" variant="secondary" onClick={ onCancel } disabled={ saving }>
						{ __( 'Cancel', 'nvoos-toolkit-shell' ) }
					</Button>
				) }
			</footer>
		</form>
	);
}

interface FieldInputProps {
	field: Field;
	control: ReturnType< typeof useForm< FormValues > >[ 'control' ];
	register: ReturnType< typeof useForm< FormValues > >[ 'register' ];
}

function FieldInput( { field, control, register }: FieldInputProps ) {
	const inputId = `nvoos-ts-${ field.name }`;

	if ( field.type === 'enum' && field.options ) {
		return (
			<Controller
				name={ field.name }
				control={ control }
				render={ ( { field: rf } ) => (
					<Select
						value={ rf.value === undefined || rf.value === null ? '' : String( rf.value ) }
						onValueChange={ ( value ) => {
							// Radix's hidden BubbleSelect (native form integration)
							// re-dispatches a change event; jsdom yields an
							// undefined target.value there. Real browsers filter
							// the duplicate upstream, so guard defensively.
							if ( value === undefined || value === null ) {
								return;
							}
							rf.onChange( value );
						} }
					>
						<SelectTrigger id={ inputId } aria-label={ field.label || field.name }>
							<SelectValue placeholder="—" />
						</SelectTrigger>
						<SelectContent>
							{ field.options!.map( ( opt ) => (
								<SelectItem key={ opt } value={ opt }>
									{ opt }
								</SelectItem>
							) ) }
						</SelectContent>
					</Select>
				) }
			/>
		);
	}

	if ( field.type === 'boolean' ) {
		return (
			<Controller
				name={ field.name }
				control={ control }
				render={ ( { field: rf } ) => (
					<Checkbox
						id={ inputId }
						checked={ Boolean( rf.value ) }
						onCheckedChange={ ( checked ) => rf.onChange( checked ) }
						aria-label={ field.label || field.name }
					>
						<CheckboxIndicator aria-hidden="true">✓</CheckboxIndicator>
					</Checkbox>
				) }
			/>
		);
	}

	if ( field.type === 'text' ) {
		return (
			<textarea
				id={ inputId }
				rows={ 4 }
				{ ...register( field.name ) }
			/>
		);
	}

	const inputType = inputTypeFor( field.type );
	return (
		<input
			id={ inputId }
			type={ inputType }
			{ ...register( field.name ) }
		/>
	);
}

function inputTypeFor( type: string ): string {
	switch ( type ) {
		case 'email':
			return 'email';
		case 'url':
			return 'url';
		case 'number':
		case 'integer':
			return 'number';
		case 'date':
			return 'date';
		case 'datetime':
			return 'datetime-local';
		default:
			return 'text';
	}
}

/**
 * Build a zod schema from the manifest's field declarations.
 */
function buildSchema( fields: Field[] ): z.ZodObject< z.ZodRawShape > {
	const shape: z.ZodRawShape = {};
	fields
		.filter( ( f ) => ! f.readonly )
		.forEach( ( field ) => {
			shape[ field.name ] = schemaFor( field );
		} );
	return z.object( shape );
}

function schemaFor( field: Field ): z.ZodTypeAny {
	const label = field.label || field.name;
	if ( field.type === 'boolean' ) {
		return z.boolean();
	}
	if ( field.type === 'number' || field.type === 'integer' ) {
		// '' must stay unset rather than coercing to 0 on submit.
		if ( field.required ) {
			return z.preprocess(
				emptyToUndefined,
				z.coerce.number( { invalid_type_error: sprintfReq( label ) } )
			);
		}
		return z.preprocess( emptyToUndefined, z.coerce.number().optional() );
	}
	if ( field.required ) {
		return z
			.string( { required_error: sprintfReq( label ) } )
			.min( 1, sprintfReq( label ) );
	}
	return z.string().optional().or( z.literal( '' ) );
}

/**
 * Zod preprocess: empty strings become undefined so optional numeric
 * fields stay unset instead of coercing to 0.
 */
function emptyToUndefined( value: unknown ): unknown {
	return value === '' || value === null ? undefined : value;
}

function sprintfReq( label: string ): string {
	return `${ label } ${ __( 'is required.', 'nvoos-toolkit-shell' ) }`.trim();
}

function buildDefaults(
	fields: Field[],
	row: Record<string, unknown> | null
): FormValues {
	const defaults: FormValues = {};
	fields
		.filter( ( f ) => ! f.readonly )
		.forEach( ( field ) => {
			if ( row && row[ field.name ] !== undefined ) {
				defaults[ field.name ] = row[ field.name ];
			} else if ( field.type === 'boolean' ) {
				defaults[ field.name ] = false;
			} else {
				defaults[ field.name ] = '';
			}
		} );
	return defaults;
}
