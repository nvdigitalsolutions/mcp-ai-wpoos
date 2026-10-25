/**
 * SettingsFormFields — the per-type field renderers shared by the
 * schema-driven settings form (Proposal 084 Phase 2 / 089 Phase 2b).
 */

import { __ } from '@wordpress/i18n';
import type { JSX } from 'react';
import { MASKED_PLACEHOLDER, type SettingsField } from '../../api/settings';

export type FormValue = string | number | boolean;

/** Whether a select field's option keys are all numeric (int round-trip). */
export function isNumericSelect( field: SettingsField ): boolean {
	const keys = Object.keys( field.options ?? {} );
	return keys.length > 0 && keys.every( ( key ) => key !== '' && ! Number.isNaN( Number( key ) ) );
}

export function toFormValue( field: SettingsField, value: unknown ): FormValue {
	if ( field.type === 'checkbox' ) {
		return value === true;
	}
	if ( field.type === 'number' ) {
		return typeof value === 'number' ? value : 0;
	}
	if ( field.type === 'select' && isNumericSelect( field ) ) {
		if ( typeof value === 'number' ) {
			return value;
		}
		if ( typeof value === 'string' && value.trim() !== '' && ! Number.isNaN( Number( value ) ) ) {
			return Number( value );
		}
		return 0;
	}
	return typeof value === 'string' ? value : '';
}

export interface SettingsFieldRowProps {
	field: SettingsField;
	value: FormValue;
	dirty: boolean;
	showSection: boolean;
	onChange: ( value: FormValue ) => void;
}

export function SettingsFieldRow( {
	field,
	value,
	dirty,
	showSection,
	onChange,
}: SettingsFieldRowProps ): JSX.Element {
	const inputId = `nvoos-pro-spa-setting-${ field.key }`;
	const descId = `${ inputId }-desc`;
	const sensitiveId = `${ inputId }-sensitive`;
	const describedBy = [ field.description ? descId : '', field.sensitive ? sensitiveId : '' ]
		.filter( Boolean )
		.join( ' ' );
	const fieldClass = dirty
		? 'nvoos-pro-spa-settings__field nvoos-pro-spa-settings__field--dirty'
		: 'nvoos-pro-spa-settings__field';

	const hints = (
		<>
			{ showSection && field.section && (
				<p className="nvoos-pro-spa-settings__hint nvoos-pro-spa-settings__hint--section">
					{ field.section }
				</p>
			) }
			{ field.description && (
				<p id={ descId } className="nvoos-pro-spa-settings__hint">
					{ field.description }
				</p>
			) }
			{ field.sensitive && (
				<p id={ sensitiveId } className="nvoos-pro-spa-settings__hint">
					{ __( 'Stored value is masked — leave unchanged to keep it.', 'nvoos-pro-spa' ) }
				</p>
			) }
		</>
	);

	if ( field.type === 'checkbox' ) {
		return (
			<div className={ fieldClass }>
				<div className="nvoos-pro-spa-settings__checkbox">
					<input
						id={ inputId }
						type="checkbox"
						checked={ value === true }
						aria-describedby={ describedBy || undefined }
						onChange={ ( e ) => onChange( e.target.checked ) }
					/>
					<label
						htmlFor={ inputId }
						className="nvoos-pro-spa-settings__label nvoos-pro-spa-settings__label--inline"
					>
						{ field.label }
					</label>
				</div>
				{ hints }
			</div>
		);
	}

	let control: JSX.Element;
	const numericSelect = field.type === 'select' && isNumericSelect( field );
	const selectOptionKeys = Object.keys( field.options ?? {} );
	// The stored value may not be among the options (catalog churn) — render
	// it as an extra option instead of silently displaying the first option.
	const unlistedSelectValue =
		field.type === 'select' &&
		String( value ) !== '' &&
		! selectOptionKeys.includes( String( value ) );
	switch ( field.type ) {
		case 'select':
			control = (
				<select
					id={ inputId }
					className="nvoos-pro-spa-tools-page__select"
					value={ String( value ) }
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( numericSelect ? Number( e.target.value ) : e.target.value ) }
				>
					{ unlistedSelectValue && <option value={ String( value ) }>{ String( value ) }</option> }
					{ Object.entries( field.options ?? {} ).map( ( [ optionValue, optionLabel ] ) => (
						<option key={ optionValue } value={ optionValue }>
							{ optionLabel }
						</option>
					) ) }
				</select>
			);
			break;
		case 'number':
			control = (
				<input
					id={ inputId }
					type="number"
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__control"
					min={ field.min }
					max={ field.max }
					step={ field.step }
					value={ typeof value === 'number' ? value : '' }
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( parseFloat( e.target.value ) || 0 ) }
				/>
			);
			break;
		case 'textarea':
			control = (
				<textarea
					id={ inputId }
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__control"
					rows={ 4 }
					value={ String( value ) }
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
		case 'password':
			control = (
				<input
					id={ inputId }
					type="password"
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__control"
					value={ String( value ) }
					placeholder={ MASKED_PLACEHOLDER }
					autoComplete="new-password"
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
		case 'url':
		case 'email':
			control = (
				<input
					id={ inputId }
					type={ field.type }
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__control"
					value={ String( value ) }
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
		case 'text':
		default:
			control = (
				<input
					id={ inputId }
					type="text"
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__control"
					value={ String( value ) }
					aria-describedby={ describedBy || undefined }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
	}

	return (
		<div className={ fieldClass }>
			<label htmlFor={ inputId } className="nvoos-pro-spa-settings__label">
				{ field.label }
			</label>
			{ control }
			{ hints }
		</div>
	);
}
