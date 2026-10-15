/**
 * DocumentEditorPage — embeds the hosted/same-site NV oOS Document Editor SPA.
 *
 * Same rationale as DocsPage: the Document Editor is a self-contained React
 * app that must not be vendored into the Pro SPA tree. Framed behind a
 * sandboxed <iframe> to keep each app's React/router/DOM isolated.
 *
 * Headless auth: when the host SPA holds a headless credential (application
 * password or assistant credential), it is forwarded to the child on load via
 * `postMessage` (see utils/auth-bridge.ts) because the credential cannot cross
 * the iframe boundary as a normal auth header.
 */

import { type JSX, useMemo, useCallback } from 'react';
import { __ } from '@wordpress/i18n';
import { readProSpaConfig } from '../../api/config';
import { postAuthToChild } from '../../utils/auth-bridge';
import './document-editor.css';

export function DocumentEditorPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const url = ( runtime?.endpoints?.documentEditor ?? '' ).trim();

	// Forward the headless credential to the framed app once it has loaded.
	const handleLoad = useCallback(
		( event: React.SyntheticEvent< HTMLIFrameElement > ) => {
			postAuthToChild( event.currentTarget );
		},
		[],
	);

	if ( ! url ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-document-editor">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Document Editor', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div className="nvoos-pro-spa-document-editor__unavailable" role="status">
					<p>
						{ __(
							'The Document Editor is not configured for this site.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-document-editor">
			<header className="nvoos-pro-spa-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Document Editor', 'nvoos-pro-spa' ) }
				</h2>
			</header>
			<iframe
				className="nvoos-pro-spa-document-editor__frame"
				src={ url }
				title={ __( 'Document Editor', 'nvoos-pro-spa' ) }
				sandbox="allow-scripts allow-same-origin allow-forms"
				referrerPolicy="no-referrer"
				loading="eager"
				onLoad={ handleLoad }
			/>
		</div>
	);
}
