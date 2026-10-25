/**
 * SettingsForm — the reusable schema-driven settings form (2.4.0, Proposal
 * 084 Phase 2; refactored out of SettingsPage for Proposal 089 Phase 2b).
 *
 * Renders the server's settings catalogue (GET /mcp-ai/v1/settings/schema):
 * sections group into tabbed cards, a search box filters across every tab,
 * fields render per type, password fields come back masked and only
 * round-trip when the user replaces the placeholder. Writes go through POST
 * /settings/values — the server sanitizes every key against the schema.
 *
 * `tabFilter` narrows the form to one schema tab (the commerce toolkit
 * pages use it); `includeKeys` pulls additional keys into that narrowed
 * view (the toolkit enable toggles live on the base `tools` tab).
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSettings } from '../../hooks/useSettings';
import { useBootstrap } from '../../hooks/useBootstrap';
import { MASKED_PLACEHOLDER, type SettingsField, type SettingsValues } from '../../api/settings';
import { SettingsFieldRow, toFormValue, type FormValue } from './SettingsFormFields';

interface FieldGroup {
	id: string;
	title: string;
	fields: SettingsField[];
}

export interface SettingsFormProps {
	/** Narrow the form to a single schema tab (undefined = every tab). */
	tabFilter?: string;
	/** Extra field keys to include beside the tab filter. */
	includeKeys?: string[];
	/** Optional heading (defaults to "Settings"). */
	title?: string;
	/** Optional subtitle. */
	subtitle?: string;
}

export function SettingsForm( {
	tabFilter,
	includeKeys = [],
	title,
	subtitle,
}: SettingsFormProps ): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { schema, fields: allFields, values, loading, saving, error, unavailable, fetchSettings, saveSettings } =
		useSettings(
			runtime ? { endpoint: runtime.endpoints.settings, nonce: runtime.nonce } : { endpoint: '', nonce: '' }
		);

	const [ form, setForm ] = useState< Record< string, FormValue > >( {} );
	const [ dirtyKeys, setDirtyKeys ] = useState< Set< string > >( new Set() );
	const [ activeTab, setActiveTab ] = useState< string >( '' );
	const [ searchQuery, setSearchQuery ] = useState< string >( '' );

	// Narrow the catalogue when a tab filter is active: that tab's fields
	// plus the explicitly included keys (deduped, schema order preserved).
	const fields = useMemo( () => {
		if ( ! tabFilter ) {
			return allFields;
		}
		return allFields.filter( ( field ) => field.tab === tabFilter || includeKeys.includes( field.key ) );
	}, [ allFields, tabFilter, includeKeys ] );

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
			// '__hidden__' is the server sentinel — never a real tab.
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
			// Narrowed (tab-filtered) forms render every filtered field — the
			// included keys live on other tabs and must not vanish.
			if ( tabFilter ) {
				return true;
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
				<h2 className="nvoos-pro-spa-page__title">{ title ?? __( 'Settings', 'nvoos-pro-spa' ) }</h2>
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
				<h2 className="nvoos-pro-spa-page__title">{ title ?? __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( unavailable && ! loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ title ?? __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'The settings catalogue is unavailable on this site.', 'nvoos-pro-spa' ) }
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
				<h2 className="nvoos-pro-spa-page__title">{ title ?? __( 'Settings', 'nvoos-pro-spa' ) }</h2>
				{ subtitle && <p className="nvoos-pro-spa-page__subtitle">{ subtitle }</p> }
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

			{ ! tabFilter && (
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
			) }

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
