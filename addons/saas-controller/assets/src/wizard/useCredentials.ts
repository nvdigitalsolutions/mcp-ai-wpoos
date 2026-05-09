/**
 * `react-query` hooks for the credentials wizard.
 *
 * All requests go through `@wordpress/api-fetch`, which automatically
 * attaches the REST nonce that `class-nvoos-saas-controller-assets.php`
 * localizes onto `window.nvoosSaasController`.
 *
 * @package NV_oOS_SaaS_Controller
 */

import apiFetch from '@wordpress/api-fetch';
import {
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';
import type {
	CredentialValues,
	MaskedCredentialsResponse,
	PreflightResponse,
} from './validation';

declare global {
	interface Window {
		nvoosSaasController?: {
			restRoot: string;
			nonce: string;
			credentialKeys: string[];
			addonVersion: string;
		};
	}
}

const RESERVED_PATH_PREFIX = 'nvoos-saas/v1';

/**
 * Build a REST path under the addon namespace. We don't use the full
 * `restRoot` URL because `@wordpress/api-fetch` already knows the WP REST
 * base — passing a relative path lets it apply nonce middleware.
 */
const path = ( segment: string ): string =>
	`/${ RESERVED_PATH_PREFIX }/${ segment.replace( /^\//, '' ) }`;

/**
 * GET /credentials — masked snapshot.
 */
export function useMaskedCredentials() {
	return useQuery<MaskedCredentialsResponse>( {
		queryKey: [ 'nvoos-saas', 'credentials' ],
		queryFn: () => apiFetch( { path: path( 'credentials' ) } ),
		staleTime: 30_000,
	} );
}

/**
 * POST /credentials — encrypt and persist the supplied (non-empty) values.
 */
export function useSaveCredentials() {
	const queryClient = useQueryClient();
	return useMutation<MaskedCredentialsResponse, Error, CredentialValues>( {
		mutationFn: ( values ) =>
			apiFetch( {
				path: path( 'credentials' ),
				method: 'POST',
				data: values,
			} ),
		onSuccess: ( data ) => {
			queryClient.setQueryData(
				[ 'nvoos-saas', 'credentials' ],
				data
			);
		},
	} );
}

/**
 * DELETE /credentials — clear every stored credential.
 */
export function useClearCredentials() {
	const queryClient = useQueryClient();
	return useMutation<MaskedCredentialsResponse, Error, void>( {
		mutationFn: () =>
			apiFetch( {
				path: path( 'credentials' ),
				method: 'DELETE',
			} ),
		onSuccess: ( data ) => {
			queryClient.setQueryData(
				[ 'nvoos-saas', 'credentials' ],
				data
			);
		},
	} );
}

/**
 * POST /connections/test — run live preflight against all three providers.
 */
export function useTestConnections() {
	return useMutation<PreflightResponse, Error, CredentialValues>( {
		mutationFn: ( values ) =>
			apiFetch( {
				path: path( 'connections/test' ),
				method: 'POST',
				data: values,
			} ),
	} );
}
