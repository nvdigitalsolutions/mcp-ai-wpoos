/**
 * Navigation Context
 *
 * Shared context object containing the current route and navigate function.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { createContext, useContext } from 'react';

export const NavContext = createContext( {
	route:    { page: 'shop', params: {} },
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
