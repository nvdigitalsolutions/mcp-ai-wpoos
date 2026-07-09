/**
 * Pro SPA v2 — Tab title badge hook.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useEffect, useRef } from 'react';

export function useTabTitleBadge( runningCount: number ): void {
	const origRef = useRef( '' );
	useEffect( () => {
		if ( typeof document === 'undefined' ) return;
		if ( origRef.current === '' ) origRef.current = document.title;
		if ( runningCount > 0 ) {
			const prefix = `(${ runningCount }) `;
			if ( ! document.title.startsWith( prefix ) ) document.title = prefix + origRef.current;
		} else {
			document.title = origRef.current;
		}
	}, [ runningCount ] );
}
