/**
 * DocumentEditorPage tests.
 *
 * Covers the page states (unavailable / error / ready) and the island
 * lifecycle contract (Proposal 087 Phase 3):
 *   - the `NVOOS_DOCUMENT_EDITOR` config is injected from the runtime before
 *     the bundle loads (apiUrl + proApi + nonce + defer_mount island flag),
 *   - the headless credential from the auth slot is injected only when its
 *     origin matches the API base,
 *   - the asset loader is called once with the configured URLs and
 *     `window.NVoosDocumentEditor.mountDocumentEditor` mounts the shell
 *     container,
 *   - unmount runs on cleanup and the injected config is removed.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { DocumentEditorPage } from '../DocumentEditorPage';

const { mountMock, unmountMock, loadScriptMock, loadStyleMock } = vi.hoisted(
	() => ( {
		mountMock: vi.fn( () => ( { unmount: unmountMock } ) ),
		unmountMock: vi.fn(),
		loadScriptMock: vi.fn( () => Promise.resolve() ),
		loadStyleMock: vi.fn(),
	} )
);
vi.mock( '../../../utils/island-assets', () => ( {
	loadIslandScript: loadScriptMock,
	loadIslandStyle: loadStyleMock,
	useIslandSkipLinkGuard: () => {},
} ) );

const API_BASE = 'https://example.com/wp-json/nvoos-document-editor/v1';
const ASSET_URL = 'https://example.com/wp-content/plugins/nvoos-document-editor/assets/dist/document-editor.js';
const CSS_URL = 'https://example.com/wp-content/plugins/nvoos-document-editor/assets/dist/document-editor.css';

function setRuntime( documentEditor: string ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: '',
			threads: '',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			memory: '',
			workflows: '',
			analytics: '',
			approvals: '',
			shortcuts: '',
			slashCommands: '',
			okf: '',
			contentGraph: '',
			docsHub: '',
			mediaStudio: '',
			mediaStudioAsset: '',
			mediaStudioCss: '',
			documentEditor,
			documentEditorAsset: documentEditor ? ASSET_URL : '',
			documentEditorCss: documentEditor ? CSS_URL : '',
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function injectedGlobal(): Record< string, unknown > | undefined {
	return (
		window as unknown as { NVOOS_DOCUMENT_EDITOR?: Record< string, unknown > }
	 ).NVOOS_DOCUMENT_EDITOR;
}

beforeEach( () => {
	mountMock.mockClear();
	unmountMock.mockClear();
	loadScriptMock.mockClear();
	loadStyleMock.mockClear();
	( window as unknown as Record< string, unknown > ).NVoosDocumentEditor = {
		mountDocumentEditor: mountMock,
	};
	delete ( window as unknown as Record< string, unknown > ).NVOOS_DOCUMENT_EDITOR;
	delete ( window as unknown as Record< string, unknown > ).__NVOOS_AUTH__;
} );

afterEach( () => {
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
	delete ( window as unknown as Record< string, unknown > ).NVOOS_DOCUMENT_EDITOR;
} );

describe( 'DocumentEditorPage', () => {
	it( 'renders the unavailable state when the runtime has no documentEditor endpoint', () => {
		setRuntime( '' );
		render( <DocumentEditorPage /> );

		expect(
			screen.getByText(
				'The connected WordPress site does not expose the NV oOS Document Editor API.',
			)
		).toBeInTheDocument();
		expect( loadScriptMock ).not.toHaveBeenCalled();
		expect( mountMock ).not.toHaveBeenCalled();
	} );

	it( 'injects the island config, loads the asset once, and mounts the app', async () => {
		setRuntime( API_BASE );
		const { container } = render( <DocumentEditorPage /> );

		expect( injectedGlobal() ).toEqual( {
			apiUrl: API_BASE,
			proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
			nonce: 'test-nonce',
			config: { defer_mount: true },
		} );
		expect( loadStyleMock ).toHaveBeenCalledWith( CSS_URL );
		expect( loadScriptMock ).toHaveBeenCalledWith( ASSET_URL );

		const island = container.querySelector(
			'.nvoos-pro-spa-document-editor__island'
		);
		expect( island ).not.toBeNull();

		await waitFor( () => {
			expect( mountMock ).toHaveBeenCalledTimes( 1 );
		} );
		expect( mountMock ).toHaveBeenCalledWith( island );
		expect( unmountMock ).not.toHaveBeenCalled();
	} );

	it( 'injects the headless credential only when its origin matches the API base', async () => {
		setRuntime( API_BASE );
		( window as unknown as Record< string, unknown > ).__NVOOS_AUTH__ = {
			authorization: 'Bearer cred_test.SECRET',
			siteOrigin: 'https://example.com',
		};
		render( <DocumentEditorPage /> );

		await waitFor( () => {
			expect( injectedGlobal()?.authorization ).toBe(
				'Bearer cred_test.SECRET'
			);
		} );
	} );

	it( 'does not inject a credential belonging to a different origin', async () => {
		setRuntime( API_BASE );
		( window as unknown as Record< string, unknown > ).__NVOOS_AUTH__ = {
			authorization: 'Bearer cred_test.SECRET',
			siteOrigin: 'https://evil.example.com',
		};
		render( <DocumentEditorPage /> );

		await waitFor( () => {
			expect( mountMock ).toHaveBeenCalledTimes( 1 );
		} );
		expect( injectedGlobal()?.authorization ).toBeUndefined();
	} );

	it( 'unmounts the island and clears the injected config on cleanup', async () => {
		setRuntime( API_BASE );
		const { unmount } = render( <DocumentEditorPage /> );

		await waitFor( () => {
			expect( mountMock ).toHaveBeenCalledTimes( 1 );
		} );
		unmount();

		expect( unmountMock ).toHaveBeenCalledTimes( 1 );
		expect( injectedGlobal() ).toBeUndefined();
	} );

	it( 'renders the error state when the island API is missing', async () => {
		setRuntime( API_BASE );
		delete ( window as unknown as Record< string, unknown > ).NVoosDocumentEditor;
		render( <DocumentEditorPage /> );

		expect(
			await screen.findByText(
				'The Document Editor could not be initialised.',
			)
		).toBeInTheDocument();
		expect( mountMock ).not.toHaveBeenCalled();
	} );
} );
