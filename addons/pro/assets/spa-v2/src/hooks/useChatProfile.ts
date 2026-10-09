/**
 * useChatProfile — wires the chat-profile catalogue and switching to the
 * model store, the REST surface, and the toast feedback loop.
 *
 * The server is the authority: the hook sends the requested switch, adopts
 * the server's response (which may refuse — e.g. a downgraded user asking
 * for an upgrade), and reverts to the server state with an error toast when
 * the request fails.
 */

import { useCallback, useMemo } from 'react';
import { __ } from '@wordpress/i18n';

import { readProSpaConfig } from '../api/config';
import { ChatProfileClient } from '../api/chatProfile';
import { useModelStore } from '../stores/modelStore';
import { useUIStore } from '../stores/uiStore';

export interface UseChatProfileReturn {
	/** Selectable profiles (slug + label). */
	profiles: ReturnType< typeof useModelStore.getState >[ 'availableProfiles' ];
	/** Current profile slug. */
	profile: string;
	/** Request a profile switch; adopts the server's resolved state. */
	changeProfile: ( slug: string ) => Promise< void >;
	/** Human-readable label for the current profile, when known. */
	profileLabel: string | null;
}

export function useChatProfile(): UseChatProfileReturn {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const profiles = useModelStore( ( s ) => s.availableProfiles );
	const profile = useModelStore( ( s ) => s.profile );
	const addToast = useUIStore( ( s ) => s.addToast );

	const client = useMemo( () => {
		const endpoint = `${ ( runtime?.apiUrl ?? '' ).replace( /\/+$/, '' ) }/chat-profile`;
		return new ChatProfileClient( {
			endpoint,
			nonce: runtime?.nonce ?? '',
		} );
	}, [ runtime ] );

	const adoptState = useCallback( ( state: {
		profiles: ReturnType< typeof useModelStore.getState >[ 'availableProfiles' ];
		current: string;
	} ) => {
		const store = useModelStore.getState();
		store.setAvailableProfiles( state.profiles.filter( ( p ) => p.selectable ) );
		store.setProfile( state.current );
	}, [] );

	const changeProfile = useCallback(
		async ( slug: string ) => {
			if ( ! slug ) {
				return;
			}
			try {
				const state = await client.setProfile( slug );
				adoptState( state );
			} catch ( err ) {
				// Revert to the server truth so the UI never shows a profile
				// the server refused.
				try {
					const state = await client.getState();
					adoptState( state );
				} catch {
					// Server unreachable — leave the optimistic value but say so.
				}
				addToast(
					err instanceof Error
						? err.message
						: __( 'Failed to switch chat profile.', 'nvoos-pro-spa' ),
					'error'
				);
			}
		},
		[ client, adoptState, addToast ]
	);

	const profileLabel = useMemo( () => {
		const found = profiles.find( ( p ) => p.slug === profile );
		return found ? found.label : null;
	}, [ profiles, profile ] );

	return { profiles, profile, changeProfile, profileLabel };
}
