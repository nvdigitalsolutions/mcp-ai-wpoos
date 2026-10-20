/**
 * SettingsPage — schema-driven plugin settings form (2.4.0, Proposal 084
 * Phase 2).
 *
 * Renders whatever the server's settings catalogue exposes (GET
 * /mcp-ai/v1/settings/schema) instead of a hardcoded field list: sections
 * group into tabs, fields render per type, password fields come back masked
 * and only round-trip when the user replaces the placeholder. Writes go
 * through POST /settings/values — the server sanitizes every key against
 * the schema (unknown keys and out-of-enum selects are rejected server-side).
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../../hooks/useSettings';
import { useBootstrap } from '../../hooks/useBootstrap';
import { MASKED_PLACEHOLDER, type SettingsField, type SettingsValues } from '../../api/settings';

type FormValue = string | number | boolean;

function toFormValue( field: SettingsField, value: unknown ): FormValue {
	if ( field.type === 'checkbox' ) {
		return value === true;
	}
	if ( field.type === 'number' ) {
		return typeof value === 'number' ? value : 0;
	}
	return typeof value === 'string' ? value : '';
}

export function SettingsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { schema, fields, values, loading, saving, error, unavailable, fetchSettings, saveSettings } =
		useSettings(
			runtime
				? { endpoint: runtime.endpoints.settings, nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);

	const [ form, setForm ] = useState< Record< string, FormValue > >( {} );
	const [ dirtyKeys, setDirtyKeys ] = useState< Set< string > >( new Set() );
	const [ activeTab, setActiveTab ] = useState< string >( '' );

	// Seed the form from the server values once they land.
	useEffect( () => {
		const next: Record< string, FormValue > = {};
		for ( const field of fields ) {
			if ( field.type === 'hidden' ) {
				continue;
			}
			next[ field.key ] = toFormValue( field, values[ field.key ] ?? field.default );
		}
		setForm( next );
		setDirtyKeys( new Set() );
	}, [ fields, values ] );

	const tabs = useMemo( () => {
		const seen: string[] = [];
		for ( const field of fields ) {
			if ( ! seen.includes( field.tab ) ) {
				seen.push( field.tab );
			}
		}
		return seen;
	}, [ fields ] );

	useEffect( () => {
		if ( ! activeTab && tabs.length > 0 ) {
			setActiveTab( tabs[ 0 ] );
		}
	}, [ tabs, activeTab ] );

	const setField = ( key: string, value: FormValue ): void => {
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setDirtyKeys( ( prev ) => new Set( prev ).add( key ) );
	};

	const handleSave = async (): Promise< void > => {
		const changes: SettingsValues = {};
		for ( const field of fields ) {
			if ( field.type === 'hidden' || ! dirtyKeys.has( field.key ) ) {
				continue;
			}
			const submitted = form[ field.key ];
			// A masked placeholder means "keep the stored secret".
			if ( field.type === 'password' && submitted === MASKED_PLACEHOLDER ) {
				continue;
			}
			changes[ field.key ] = submitted;
		}
		if ( Object.keys( changes ).length === 0 ) {
			return;
		}
		await saveSettings( changes );
		setDirtyKeys( new Set() );
	};

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading settings…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( unavailable && ! loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __(
						'The settings catalogue is unavailable on this site.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	const visibleFields = fields.filter( ( field ) => field.tab === activeTab && field.type !== 'hidden' );

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Site-wide NV oOS configuration.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void fetchSettings() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			<div className="nvoos-pro-spa-settings__tabs" role="tablist" aria-label={ __( 'Settings tabs', 'nvoos-pro-spa' ) }>
				{ tabs.map( ( tab ) => (
					<button
						key={ tab }
						type="button"
						role="tab"
						aria-selected={ activeTab === tab }
						className={
							activeTab === tab
								? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
								: 'nvoos-pro-spa-settings__tab'
						}
						onClick={ () => setActiveTab( tab ) }
					>
						{ schema.tabs[ tab ] ?? tab }
					</button>
				) ) }
			</div>

			{ visibleFields.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No editable settings in this tab.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<div className="nvoos-pro-spa-settings__fields">
					{ visibleFields.map( ( field ) => (
						<SettingsFieldRow
							key={ field.key }
							field={ field }
							value={ form[ field.key ] ?? toFormValue( field, field.default ) }
							onChange={ ( value ) => setField( field.key, value ) }
						/>
					) ) }
				</div>
			) }

			<footer className="nvoos-pro-spa-tools-page__footer">
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ saving || dirtyKeys.size === 0 }
					onClick={ () => void handleSave() }
				>
					{ saving ? __( 'Saving…', 'nvoos-pro-spa' ) : __( 'Save settings', 'nvoos-pro-spa' ) }
				</button>
				{dirtyKeys.size > 0 && <p>{ __( 'Unsaved changes', 'nvoos-pro-spa' ) }</p>}
			</footer>
		</div>
	);
}

interface SettingsFieldRowProps {
	field: SettingsField;
	value: FormValue;
	onChange: ( value: FormValue ) => void;
}

function SettingsFieldRow( { field, value, onChange }: SettingsFieldRowProps ): JSX.Element {
	const inputId = `nvoos-pro-spa-setting-${ field.key }`;

	let control: JSX.Element;
	switch ( field.type ) {
		case 'checkbox':
			control = (
				<input
					id={ inputId }
					type="checkbox"
					checked={ value === true }
					onChange={ ( e ) => onChange( e.target.checked ) }
				/>
			);
			break;
		case 'select':
			control = (
				<select
					id={ inputId }
					className="nvoos-pro-spa-tools-page__select"
					value={ String( value ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				>
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
					className="nvoos-pro-spa-tools-page__input"
					min={ field.min }
					max={ field.max }
					step={ field.step }
					value={ typeof value === 'number' ? value : '' }
					onChange={ ( e ) => onChange( parseFloat( e.target.value ) || 0 ) }
				/>
			);
			break;
		case 'textarea':
			control = (
				<textarea
					id={ inputId }
					className="nvoos-pro-spa-tools-page__input"
					rows={ 4 }
					value={ String( value ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
		case 'password':
			control = (
				<input
					id={ inputId }
					type="password"
					className="nvoos-pro-spa-tools-page__input"
					value={ String( value ) }
					placeholder={ MASKED_PLACEHOLDER }
					autoComplete="new-password"
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
					className="nvoos-pro-spa-tools-page__input"
					value={ String( value ) }
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
					className="nvoos-pro-spa-tools-page__input"
					value={ String( value ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
			break;
	}

	return (
		<div className="nvoos-pro-spa-settings__field">
			<label htmlFor={ inputId } className="nvoos-pro-spa-tools-page__label">
				{ field.label }
			</label>
			{ control }
			{ field.description && (
				<p className="nvoos-pro-spa-settings__hint">{ field.description }</p>
			) }
			{ field.sensitive && (
				<p className="nvoos-pro-spa-settings__hint">
					{ __( 'Stored value is masked — leave unchanged to keep it.', 'nvoos-pro-spa' ) }
				</p>
			) }
		</div>
	);
}
