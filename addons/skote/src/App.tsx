/**
 * Phase 1 placeholder App shell.
 *
 * Once Skote source has been imported via `npm run import:skote`, replace
 * this file with Skote's real `App.tsx` and wrap the existing tree with the
 * `QueryClientProvider` already configured in `index.tsx`.
 */

import React from 'react';
import { useApps } from './hooks/useApps';

export default function App(): JSX.Element {
	const { data, isLoading, error } = useApps();
	const settings = window.nvoosSkote;

	return (
		<div className="nvoos-skote-shell">
			<header>
				<h2>NV oOS Skote — Phase 1 Stub</h2>
				<p>
					Logged in as <strong>{settings?.userDisplayName ?? 'unknown'}</strong> ·{' '}
					Pro: {settings?.proEnabled ? 'on' : 'off'} ·{' '}
					Woo: {settings?.woocommerceEnabled ? 'on' : 'off'} ·{' '}
					JetEngine: {settings?.jetengineEnabled ? 'on' : 'off'}
				</p>
			</header>
			<section>
				<h3>Available Apps</h3>
				{isLoading && <p>Loading…</p>}
				{error && <p style={{ color: 'crimson' }}>Failed to load apps.</p>}
				<ul>
					{(data ?? []).map((app) => (
						<li key={app.slug}>
							<a href={app.route}>{app.label}</a>
							{app.requires ? <em> (requires {app.requires})</em> : null}
						</li>
					))}
				</ul>
			</section>
		</div>
	);
}
