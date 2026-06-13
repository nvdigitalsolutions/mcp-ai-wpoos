/**
 * App — Root SPA component.
 *
 * Orchestrates the three-column layout (ThreadsSidebar | Main | RightPanel),
 * the Command Palette overlay, and hash-based routing.
 */

import { useEffect, useState } from '@wordpress/element';
import Layout from './components/layout/Layout';
import CommandPalette from './components/shared/CommandPalette';
import { useCommandPalette } from './hooks/useCommandPalette';
import { useBootstrap } from './hooks/useBootstrap';

export default function App() {
	const { isOpen: paletteOpen } = useCommandPalette();
	const { loading, error } = useBootstrap();

	if (loading) {
		return (
			<div className="nvoos-spa-loading">
				<div className="nvoos-spa-loading__spinner" />
				<p>Loading NV oOS…</p>
			</div>
		);
	}

	if (error) {
		return (
			<div className="nvoos-spa-error">
				<h2>Failed to load NV oOS</h2>
				<p>{error}</p>
			</div>
		);
	}

	return (
		<>
			{paletteOpen && <CommandPalette />}
			<Layout />
		</>
	);
}
