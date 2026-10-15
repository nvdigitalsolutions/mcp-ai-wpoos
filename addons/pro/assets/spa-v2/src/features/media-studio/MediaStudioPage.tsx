/**
 * MediaStudioPage — embeds the hosted/same-site NV oOS Media Studio SPA.
 *
 * Same rationale as DocsPage: Media Studio is a self-contained React app
 * (React 19.2.x, konva/react-konva, wavesurfer, react-image-crop) that must
 * not be vendored into the Pro SPA tree. This frames it behind a sandboxed
 * <iframe> so each app keeps its own React/router/DOM.
 */

import { type JSX, useMemo } from 'react';
import { __ } from '@wordpress/i18n';
import { readProSpaConfig } from '../../api/config';
import './media-studio.css';

export function MediaStudioPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const url = ( runtime?.endpoints?.mediaStudio ?? '' ).trim();

	if ( ! url ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-media-studio">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Media Studio', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div className="nvoos-pro-spa-media-studio__unavailable" role="status">
					<p>
						{ __(
							'The Media Studio is not configured for this site.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-media-studio">
			<header className="nvoos-pro-spa-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Media Studio', 'nvoos-pro-spa' ) }
				</h2>
			</header>
			<iframe
				className="nvoos-pro-spa-media-studio__frame"
				src={ url }
				title={ __( 'Media Studio', 'nvoos-pro-spa' ) }
				sandbox="allow-scripts allow-same-origin allow-forms"
				referrerPolicy="no-referrer"
				loading="eager"
			/>
		</div>
	);
}
