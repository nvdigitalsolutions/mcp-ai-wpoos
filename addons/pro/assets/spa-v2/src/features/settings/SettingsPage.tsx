/**
 * SettingsPage — schema-driven plugin settings form (2.4.0, Proposal 084
 * Phase 2).
 *
 * Renders whatever the server's settings catalogue exposes (GET
 * /mcp-ai/v1/settings/schema) instead of a hardcoded field list: sections
 * group into tabbed cards, a search box filters across every tab, fields
 * render per type, password fields come back masked and only round-trip when
 * the user replaces the placeholder. Writes go through POST /settings/values
 * — the server sanitizes every key against the schema (unknown keys and
 * out-of-enum selects are rejected server-side).
 *
 * Layout follows the card-grouping + search + sticky-save pattern common to
 * modern admin settings surfaces (GitHub/Vercel-style settings, WCAG 2.2
 * tab/described-by patterns) rather than WP's legacy form-table.
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../../hooks/useSettings';
import { useBootstrap } from '../../hooks/useBootstrap';
import { MASKED_PLACEHOLDER, type SettingsField, type SettingsValues } from '../../api/settings';

type FormValue = string | number | boolean;

/**
 * Whether a select field's option keys are all numeric (e.g. assistant IDs).
 * Numeric selects store int values; the form must round-trip them as numbers
 * so the rendered option matches the stored value (an int value coerced to
 * '' would silently display the first option — the default_assistant bug).
 */
function isNumericSelect( field: SettingsField ): boolean {
	const keys = Object.keys( field.options ?? {} );
	return keys.length > 0 && keys.every( ( key ) => key !== '' && ! Number.isNaN( Number( key ) ) );
}

function toFormValue( field: SettingsField, value: unknown ): FormValue {
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

interface FieldGroup {
	id: string;
	title: string;
	fields: SettingsField[];
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
	const [ searchQuery, setSearchQuery ] = useState< string >( '' );

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
			// '__hidden__' is the server sentinel for sections rendered inside
			// another section — never a real tab.
			if ( field.tab === '__hidden__' || seen.includes( field.tab ) ) {
				continue;
			}
			seen.push( field.tab );
		}
		return seen;
	}, [ fields ] );

	useEffect( () => {
		if ( ! activeTab && tabs.length > 0 ) {
			setActiveTab( tabs[ 0 ] );
		}
	}, [ tabs, activeTab ] );

	const normalizedQuery = searchQuery.trim().toLowerCase();
	const searching = normalizedQuery.length > 0;

	// Group the visible fields: per section inside the active tab normally,
	// per tab (with the section shown on each row) when searching globally.
	// Computed BEFORE the early returns — rules-of-hooks (React error #310).
	const groupedFields = useMemo< FieldGroup[] >( () => {
		const visible = fields.filter( ( field ) => {
			if ( field.type === 'hidden' || field.tab === '__hidden__' ) {
				return false;
			}
			if ( searching ) {
				return (
					field.label.toLowerCase().includes( normalizedQuery ) ||
					field.description.toLowerCase().includes( normalizedQuery ) ||
					field.key.toLowerCase().includes( normalizedQuery )
				);
			}
			return field.tab === activeTab;
		} );

		const groups: FieldGroup[] = [];
		for ( const field of visible ) {
			const groupId = searching ? `tab:${ field.tab }` : `section:${ field.section_id || field.section || field.tab }`;
			let group = groups.find( ( candidate ) => candidate.id === groupId );
			if ( ! group ) {
				group = {
					id: groupId,
					title: searching
						? schema.tabs[ field.tab ] ?? field.tab
						: field.section || schema.tabs[ field.tab ] || field.tab,
					fields: [],
				};
				groups.push( group );
			}
			group.fields.push( field );
		}
		return groups;
	}, [ fields, activeTab, searching, normalizedQuery, schema.tabs ] );

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

	const activeTabId = `nvoos-pro-spa-settings-tab-${ activeTab }`;

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

			<div className="nvoos-pro-spa-settings__search-row">
				<input
					type="search"
					className="nvoos-pro-spa-tools-page__input nvoos-pro-spa-settings__search"
					placeholder={ __( 'Search settings…', 'nvoos-pro-spa' ) }
					aria-label={ __( 'Search settings', 'nvoos-pro-spa' ) }
					value={ searchQuery }
					onChange={ ( event ) => setSearchQuery( event.target.value ) }
				/>
			</div>

			<div className="nvoos-pro-spa-settings__tabs" role="tablist" aria-label={ __( 'Settings tabs', 'nvoos-pro-spa' ) }>
				{ tabs.map( ( tab ) => (
					<button
						key={ tab }
						id={ `nvoos-pro-spa-settings-tab-${ tab }` }
						type="button"
						role="tab"
						aria-selected={ ! searching && activeTab === tab }
						aria-controls="nvoos-pro-spa-settings-panel"
						className={
							! searching && activeTab === tab
								? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
								: 'nvoos-pro-spa-settings__tab'
						}
						onClick={ () => {
							setActiveTab( tab );
							setSearchQuery( '' );
						} }
					>
						{ schema.tabs[ tab ] ?? tab }
					</button>
				) ) }
			</div>

			{ groupedFields.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ searching
							? __( 'No settings match your search.', 'nvoos-pro-spa' )
							: __( 'No editable settings in this tab.', 'nvoos-pro-spa' ) }
					</p>
				</div>
			) : (
				<div
					className="nvoos-pro-spa-settings__fields"
					id="nvoos-pro-spa-settings-panel"
					role="tabpanel"
					aria-labelledby={ activeTabId }
				>
					{ groupedFields.map( ( group ) => (
						<section key={ group.id } className="nvoos-pro-spa-settings__section" aria-label={ group.title }>
							<h3 className="nvoos-pro-spa-settings__section-title">{ group.title }</h3>
							{ group.fields.map( ( field ) => (
								<SettingsFieldRow
									key={ field.key }
									field={ field }
									value={ form[ field.key ] ?? toFormValue( field, field.default ) }
									dirty={ dirtyKeys.has( field.key ) }
									showSection={ searching }
									onChange={ ( value ) => setField( field.key, value ) }
								/>
							) ) }
						</section>
					) ) }
				</div>
			) }

			<footer className="nvoos-pro-spa-settings__footer">
				<button
					type="button"
					className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
					disabled={ saving || dirtyKeys.size === 0 }
					onClick={ () => void handleSave() }
				>
					{ saving ? __( 'Saving…', 'nvoos-pro-spa' ) : __( 'Save settings', 'nvoos-pro-spa' ) }
				</button>
				{ dirtyKeys.size > 0 && (
					<p className="nvoos-pro-spa-settings__dirty-hint">{ __( 'Unsaved changes', 'nvoos-pro-spa' ) }</p>
				) }
			</footer>
		</div>
	);
}

interface SettingsFieldRowProps {
	field: SettingsField;
	value: FormValue;
	dirty: boolean;
	showSection: boolean;
	onChange: ( value: FormValue ) => void;
}

function SettingsFieldRow( { field, value, dirty, showSection, onChange }: SettingsFieldRowProps ): JSX.Element {
	const inputId = `nvoos-pro-spa-setting-${ field.key }`;
	const descId = `${ inputId }-desc`;
	const sensitiveId = `${ inputId }-sensitive`;
	const describedBy = [
		field.description ? descId : '',
		field.sensitive ? sensitiveId : '',
	]
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
					<label htmlFor={ inputId } className="nvoos-pro-spa-settings__label nvoos-pro-spa-settings__label--inline">
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
	// The stored value may not be among the options (catalog churn, older
	// plugin). Render it as an extra option instead of silently displaying
	// the first option — the select must never lie about the live config.
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
					onChange={ ( e ) =>
						onChange( numericSelect ? Number( e.target.value ) : e.target.value )
					}
				>
					{ unlistedSelectValue && (
						<option value={ String( value ) }>{ String( value ) }</option>
					) }
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
