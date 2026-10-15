/**
 * DocsPage — embeds the hosted NV oOS Docs Hub front end inside the Pro SPA.
 *
 * The Docs Hub is a self-contained React application (its own React/Router
 * versions and markdown/highlighting pipeline) served from a stable front-end
 * URL. Rather than vendor that second React tree into the Pro SPA — which
 * would collide on React 19.1 vs 19.2 and React Router v6 vs v7 plus introduce
 * a competing HashRouter — this page frames it behind a sandboxed <iframe>.
 *
 * The iframe is the same approach the dictate companion app already uses for
 * its "Assistant" tab, and keeps the two front ends fully isolated: the Docs
 * Hub app runs in its own origin (reading its own NVOOS_DOCS_HUB config,
 * sessionStorage and REST calls), while the Pro SPA shell never talks to it.
 */

import { type JSX, useMemo } from 'react';
import { __ } from '@wordpress/i18n';
import { readProSpaConfig } from '../../api/config';
import './docs-hub.css';

export function DocsPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const docsUrl = ( runtime?.endpoints?.docsHub ?? '' ).trim();

	// Not configured (or no admin surface) — render a friendly unavailable state.
	if ( ! docsUrl ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-docs-hub">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Docs', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div className="nvoos-pro-spa-docs-hub__unavailable" role="status">
					<p>
						{ __(
							'The documentation browser is not configured for this site.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-docs-hub">
			<header className="nvoos-pro-spa-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Docs', 'nvoos-pro-spa' ) }
				</h2>
			</header>
			<iframe
				className="nvoos-pro-spa-docs-hub__frame"
				src={ docsUrl }
				title={ __( 'Documentation browser', 'nvoos-pro-spa' ) }
				sandbox="allow-scripts allow-same-origin allow-forms"
				referrerPolicy="no-referrer"
				loading="eager"
			/>
		</div>
	);
}
