/**
 * Router-mode context for link-emitting components.
 *
 * The app renders under HashRouter by default (standalone shortcode/block on
 * a WordPress page). Host apps — the NV oOS Pro SPA docs island — inject
 * `config.router: 'memory'` so the app renders under MemoryRouter instead:
 * the host owns `window.location.hash`, so no Docs Hub navigation may touch
 * it. Components use `useDocRouterMode()` to render links that work under
 * both routers.
 *
 * @since 1.0.0
 */

import { createContext, useContext } from 'react';

export type DocRouterMode = 'hash' | 'memory';

/** Router mode for the current mount. Defaults to hash (standalone use). */
export const DocRouterModeContext = createContext< DocRouterMode >( 'hash' );

/**
 * Read the active router mode.
 *
 * @return 'hash' for standalone HashRouter mounts, 'memory' for host islands.
 */
export function useDocRouterMode(): DocRouterMode {
	return useContext( DocRouterModeContext );
}
