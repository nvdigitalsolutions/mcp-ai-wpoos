/**
 * SitePicker — commerce page site selector (Proposal 089 Phase B).
 *
 * Lists the WordPress-type Remote Sites connections alongside the local
 * site; the selection persists per browser and drives the `remote_site`
 * parameter on the sync-runs bridge. Read-only: credentials never leave
 * the server (the list comes back redacted).
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import { CommerceClient, type RemoteSiteSummary } from '../../api/commerce';

const STORAGE_KEY = 'nvoos-console.commerce.site';

function readStored(): string {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) ?? '';
	} catch {
		return '';
	}
}

function persist( value: string ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, value );
	} catch {
		// localStorage unavailable — the selection is session-only.
	}
}

export interface SitePickerProps {
	value: string;
	onChange: ( siteId: string ) => void;
}

export function SitePicker( { value, onChange }: SitePickerProps ): JSX.Element {
	const { runtime } = useBootstrap();
	const client = useMemo(
		() => new CommerceClient( { base: runtime?.proApi ?? '', nonce: runtime?.nonce ?? '' } ),
		[ runtime?.proApi, runtime?.nonce ]
	);
	const [ sites, setSites ] = useState< RemoteSiteSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		if ( ! runtime?.proApi ) {
			setLoading( false );
			return;
		}
		let cancelled = false;
		const controller = new AbortController();
		setLoading( true );
		client
			.remoteSites( controller.signal )
			.then( ( items ) => {
				if ( cancelled ) {
					return;
				}
				setSites(
					items.filter(
						( item ) => item.connection_type === 'wordpress' && item.source !== 'base-gmail'
					)
				);
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setError( err instanceof Error ? err.message : String( err ) );
				setLoading( false );
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [ client, runtime?.proApi ] );

	const handleChange = useCallback(
		( siteId: string ) => {
			persist( siteId );
			onChange( siteId );
		},
		[ onChange ]
	);

	return (
		<div className="nvoos-pro-spa-settings__field">
			<label htmlFor="nvoos-commerce-site-picker" className="nvoos-pro-spa-settings__label">
				{ __( 'Site', 'nvoos-pro-spa' ) }
			</label>
			<select
				id="nvoos-commerce-site-picker"
				className="nvoos-pro-spa-tools-page__select"
				value={ value }
				onChange={ ( e ) => handleChange( e.target.value ) }
			>
				<option value="">{ __( 'This site (local)', 'nvoos-pro-spa' ) }</option>
				{ sites.map( ( site ) => (
					<option key={ site.id } value={ site.id } disabled={ ! site.enabled }>
						{ site.name || site.id }
						{ site.enabled ? '' : ` (${ __( 'disabled', 'nvoos-pro-spa' ) })` }
					</option>
				) ) }
			</select>
			{ loading && (
				<p className="nvoos-pro-spa-settings__hint">{ __( 'Loading sites…', 'nvoos-pro-spa' ) }</p>
			) }
			{ error && (
				<p className="nvoos-pro-spa-settings__hint" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
