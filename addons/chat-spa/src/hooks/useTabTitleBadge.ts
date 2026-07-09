/**
 * NV oOS Chat SPA — Tab title badge hook.
 *
 * Prefixes `document.title` with "(N) " while N jobs are running,
 * restoring the original title when all jobs complete.
 *
 * Mirrors the legacy `updateTabTitleBadge` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { useEffect, useRef } from 'react';

export function useTabTitleBadge( runningCount: number ): void {
	const originalRef = useRef< string >( '' );

	useEffect( () => {
		if ( typeof document === 'undefined' ) return;

		if ( originalRef.current === '' ) {
			originalRef.current = document.title;
		}

		if ( runningCount > 0 ) {
			const prefix = `(${ runningCount }) `;
			if ( ! document.title.startsWith( prefix ) ) {
				document.title = prefix + originalRef.current;
			}
		} else {
			document.title = originalRef.current;
		}
	}, [ runningCount ] );
}
