/**
 * App — Root SPA component.
 *
 * Orchestrates the three-column layout (sidebar | main | right-panel),
 * the Command Palette overlay, and bootstrap loading.
 */

import { type JSX } from 'react';
import { useBootstrap } from './hooks/useBootstrap';
import { useCommandPalette } from './hooks/useCommandPalette';
import { Layout } from './components/layout/Layout';
import { CommandPalette } from './components/shared/CommandPalette';
import { ToastContainer } from './components/shared/Toast';

export function App(): JSX.Element {
	const { loading, error } = useBootstrap();
	const { isOpen: paletteOpen } = useCommandPalette();

	if ( loading ) {
		return (
			<div
				className="nvoos-pro-spa-loading"
				role="status"
				aria-label="Loading NV oOS"
			>
				<div className="nvoos-pro-spa-loading__spinner" aria-hidden="true" />
				<p>Loading NV oOS…</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="nvoos-pro-spa-error" role="alert">
				<h2>Failed to load NV oOS</h2>
				<p>{ error }</p>
			</div>
		);
	}

	return (
		<>
			{ paletteOpen && <CommandPalette /> }
			<Layout />
			<ToastContainer />
		</>
	);
}
