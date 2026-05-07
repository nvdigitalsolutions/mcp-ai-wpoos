/**
 * NV oOS Chat SPA — root component.
 *
 * Replace this stub with a real implementation. The component receives
 * its per-instance config (toolkit, theme, view, height) via props.
 */

import { useEffect, useState } from 'react';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: string;
		view?: string;
		height?: string;
	};
}

export function App( { config }: AppProps ) {
	const [ status, setStatus ] = useState<string>( 'loading' );

	useEffect( () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const g = ( window as any ).NVOOS_CHAT_SPA;
		if ( ! g?.apiUrl ) {
			setStatus( 'no-config' );
			return;
		}
		fetch( g.apiUrl + '/health', {
			headers: { 'X-WP-Nonce': g.nonce ?? '' },
		} )
			.then( ( r ) => r.json() )
			.then( () => setStatus( 'ready' ) )
			.catch( () => setStatus( 'error' ) );
	}, [] );

	return (
		<div className="nvoos-chat-spa-app" data-theme={ config.theme ?? 'auto' }>
			<header>
				<h2>NV oOS Chat SPA</h2>
			</header>
			<main>
				<p>Status: { status }</p>
				<p>Toolkit: { config.toolkit ?? '(none)' }</p>
				<p>View: { config.view ?? '(default)' }</p>
			</main>
		</div>
	);
}
