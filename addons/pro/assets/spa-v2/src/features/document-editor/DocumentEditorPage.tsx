/**
 * DocumentEditorPage — native NV oOS Document Editor island inside the Pro SPA.
 *
 * Mirrors the Docs Hub island (Proposal 087, Phase 3): the React shell owns
 * the page states, config delivery and auth, while the Document Editor app —
 * a self-contained IIFE bundle (its own React/tiptap/grapesjs tree) loaded
 * lazily from `endpoints.documentEditorAsset` — mounts as an imperative
 * island. The previous iframe framing is gone; the app runs same-origin
 * against the local `nvoos-document-editor/v1` REST API.
 *
 * Data flow:
 *   1. The runtime supplies the REST base, asset URLs and nonce.
 *   2. The page injects `window.NVOOS_DOCUMENT_EDITOR` ({ apiUrl, proApi,
 *      nonce, optional headless `authorization`, config: { defer_mount: true } })
 *      and loads the bundle (once per session).
 *   3. `window.NVoosDocumentEditor.mountDocumentEditor( container )` renders
 *      the app into the shell-owned container; `unmount()` tears the root down
 *      on page exit. A capture-phase guard keeps the app's skip link off the
 *      Pro SPA's hash.
 */

import { type JSX, useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { readProSpaConfig } from '../../api/config';
import { getAuthCredential } from '../../utils/auth-bridge';
import {
	loadIslandScript,
	loadIslandStyle,
	useIslandSkipLinkGuard,
} from '../../utils/island-assets';
import './document-editor.css';

interface DocumentEditorIsland {
	mountDocumentEditor( container: HTMLElement ): { unmount(): void };
}

/** Resolve the headless credential when it belongs to the API origin. */
function resolveAuthorization( apiBase: string ): string | undefined {
	const credential = getAuthCredential();
	if ( ! credential ) {
		return undefined;
	}
	try {
		if ( new URL( apiBase ).origin === credential.siteOrigin ) {
			return credential.authorization;
		}
	} catch {
		// Unparseable base — never attach a credential to it.
	}
	return undefined;
}

export function DocumentEditorPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const apiBase = ( runtime?.endpoints?.documentEditor ?? '' )
		.trim()
		.replace( /\/+$/, '' );
	const assetUrl = ( runtime?.endpoints?.documentEditorAsset ?? '' ).trim();
	const cssUrl = ( runtime?.endpoints?.documentEditorCss ?? '' ).trim();
	const nonce = runtime?.nonce ?? '';
	const theme = runtime?.config?.theme;

	const [ failed, setFailed ] = useState( '' );
	const containerRef = useRef< HTMLDivElement | null >( null );
	useIslandSkipLinkGuard( containerRef );

	useEffect( () => {
		if ( ! apiBase || ! assetUrl || ! containerRef.current ) {
			return;
		}
		let cancelled = false;
		let unmount: ( () => void ) | undefined;

		setFailed( '' );

		// Inject before the script loads so the entry point's auto-mount scan
		// sees `defer_mount` and skips; the page mounts explicitly below.
		const authorization = resolveAuthorization( apiBase );
		( window as unknown as { NVOOS_DOCUMENT_EDITOR?: Record< string, unknown > } )
			.NVOOS_DOCUMENT_EDITOR = {
			apiUrl: apiBase,
			proApi: runtime?.proApi ?? '',
			nonce,
			...( authorization ? { authorization } : {} ),
			config: {
				defer_mount: true,
				...( theme === 'light' || theme === 'dark' ? { theme } : {} ),
			},
		};

		loadIslandStyle( cssUrl );
		loadIslandScript( assetUrl )
			.then( () => {
				if ( cancelled || ! containerRef.current ) {
					return;
				}
				const island = (
					window as unknown as {
						NVoosDocumentEditor?: DocumentEditorIsland;
					}
				 ).NVoosDocumentEditor;
				if ( ! island?.mountDocumentEditor ) {
					setFailed(
						__(
							'The Document Editor could not be initialised.',
							'nvoos-pro-spa'
						)
					);
					return;
				}
				unmount = island.mountDocumentEditor(
					containerRef.current
				).unmount;
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setFailed(
						__(
							'The Document Editor assets could not be loaded.',
							'nvoos-pro-spa'
						)
					);
				}
			} );

		return () => {
			cancelled = true;
			unmount?.();
			delete (
				window as unknown as { NVOOS_DOCUMENT_EDITOR?: unknown }
			 ).NVOOS_DOCUMENT_EDITOR;
		};
	}, [ apiBase, assetUrl, cssUrl, nonce, runtime?.proApi, theme ] );

	// The connected site does not expose the Document Editor API (addon inactive).
	if ( ! apiBase ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-document-editor">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Document Editor', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-document-editor__unavailable"
					role="status"
				>
					<p>
						{ __(
							'The connected WordPress site does not expose the NV oOS Document Editor API.',
							'nvoos-pro-spa'
						) }
					</p>
					<p>
						{ __(
							'Install and activate the NV oOS Document Editor addon on the site to edit documents here.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( failed ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-document-editor">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Document Editor', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-document-editor__unavailable"
					role="alert"
				>
					<p>{ failed }</p>
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
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Edit and manage documents. Content management lives in the WordPress admin.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			<div
				ref={ containerRef }
				className="nvoos-pro-spa-document-editor__island nvoos-document-editor-root"
				data-config="{}"
			/>
		</div>
	);
}
