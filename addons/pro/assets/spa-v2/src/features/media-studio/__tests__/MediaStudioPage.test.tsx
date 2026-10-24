/**
 * MediaStudioPage tests.
 *
 * Covers the page states (unavailable / error / ready) and the island
 * lifecycle contract (Proposal 087 Phase 3):
 *   - the `NVOOS_MEDIA_STUDIO` config is injected from the runtime before the
 *     bundle loads (apiUrl + proApi + nonce + defer_mount island flag),
 *   - the headless credential from the auth slot is injected only when its
 *     origin matches the API base,
 *   - the asset loader is called once with the configured URLs and
 *     `window.NVoosMediaStudio.mountMediaStudio` mounts the shell container,
 *   - unmount runs on cleanup and the injected config is removed.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { MediaStudioPage } from '../MediaStudioPage';

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

const API_BASE = 'https://example.com/wp-json/nvoos-media-studio/v1';
const ASSET_URL = 'https://example.com/wp-content/plugins/nvoos-media-studio/assets/dist/media-studio.js';
const CSS_URL = 'https://example.com/wp-content/plugins/nvoos-media-studio/assets/dist/media-studio.css';

function setRuntime( mediaStudio: string ): void {
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
			mediaStudio,
			mediaStudioAsset: mediaStudio ? ASSET_URL : '',
			mediaStudioCss: mediaStudio ? CSS_URL : '',
			documentEditor: '',
			documentEditorAsset: '',
			documentEditorCss: '',
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function injectedGlobal(): Record< string, unknown > | undefined {
	return (
		window as unknown as { NVOOS_MEDIA_STUDIO?: Record< string, unknown > }
	 ).NVOOS_MEDIA_STUDIO;
}

beforeEach( () => {
	mountMock.mockClear();
	unmountMock.mockClear();
	loadScriptMock.mockClear();
	loadStyleMock.mockClear();
	( window as unknown as Record< string, unknown > ).NVoosMediaStudio = {
		mountMediaStudio: mountMock,
	};
	delete ( window as unknown as Record< string, unknown > ).NVOOS_MEDIA_STUDIO;
	delete ( window as unknown as Record< string, unknown > ).__NVOOS_AUTH__;
} );

afterEach( () => {
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
	delete ( window as unknown as Record< string, unknown > ).NVOOS_MEDIA_STUDIO;
} );

describe( 'MediaStudioPage', () => {
	it( 'renders the unavailable state when the runtime has no mediaStudio endpoint', () => {
		setRuntime( '' );
		render( <MediaStudioPage /> );

		expect(
			screen.getByText(
				'The connected WordPress site does not expose the NV oOS Media Studio API.',
			)
		).toBeInTheDocument();
		expect( loadScriptMock ).not.toHaveBeenCalled();
		expect( mountMock ).not.toHaveBeenCalled();
	} );

	it( 'injects the island config, loads the asset once, and mounts the app', async () => {
		setRuntime( API_BASE );
		const { container } = render( <MediaStudioPage /> );

		expect( injectedGlobal() ).toEqual( {
			apiUrl: API_BASE,
			proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
			nonce: 'test-nonce',
			config: { defer_mount: true },
		} );
		expect( loadStyleMock ).toHaveBeenCalledWith( CSS_URL );
		expect( loadScriptMock ).toHaveBeenCalledWith( ASSET_URL );

		const island = container.querySelector(
			'.nvoos-pro-spa-media-studio__island'
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
			authorization: 'Basic dXNlcjpwYXNz',
			siteOrigin: 'https://example.com',
		};
		render( <MediaStudioPage /> );

		await waitFor( () => {
			expect( injectedGlobal()?.authorization ).toBe( 'Basic dXNlcjpwYXNz' );
		} );
	} );

	it( 'does not inject a credential belonging to a different origin', async () => {
		setRuntime( API_BASE );
		( window as unknown as Record< string, unknown > ).__NVOOS_AUTH__ = {
			authorization: 'Basic dXNlcjpwYXNz',
			siteOrigin: 'https://evil.example.com',
		};
		render( <MediaStudioPage /> );

		await waitFor( () => {
			expect( mountMock ).toHaveBeenCalledTimes( 1 );
		} );
		expect( injectedGlobal()?.authorization ).toBeUndefined();
	} );

	it( 'unmounts the island and clears the injected config on cleanup', async () => {
		setRuntime( API_BASE );
		const { unmount } = render( <MediaStudioPage /> );

		await waitFor( () => {
			expect( mountMock ).toHaveBeenCalledTimes( 1 );
		} );
		unmount();

		expect( unmountMock ).toHaveBeenCalledTimes( 1 );
		expect( injectedGlobal() ).toBeUndefined();
	} );

	it( 'renders the error state when the island API is missing', async () => {
		setRuntime( API_BASE );
		delete ( window as unknown as Record< string, unknown > ).NVoosMediaStudio;
		render( <MediaStudioPage /> );

		expect(
			await screen.findByText(
				'The Media Studio could not be initialised.',
			)
		).toBeInTheDocument();
		expect( mountMock ).not.toHaveBeenCalled();
	} );
} );
