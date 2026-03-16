/**
 * Navigation Context
 *
 * Shared context object containing the current route and navigate function.
 * Separated into its own module to avoid circular imports.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { createContext, useContext } from '@wordpress/element';

export const NavContext = createContext( {
	route: { page: 'shop', params: {} },
	navigate: () => {},
} );

/**
 * Hook to consume the navigation context.
 *
 * @return {{ route: {page:string,params:object}, navigate: Function }}
 */
export function useNav() {
	return useContext( NavContext );
}
