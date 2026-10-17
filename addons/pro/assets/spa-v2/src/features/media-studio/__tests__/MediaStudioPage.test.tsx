/**
 * MediaStudioPage tests — unavailable + ready states (iframe framing).
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

import { MediaStudioPage } from '../MediaStudioPage';

const URL = 'https://example.com/media-studio/';

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
			documentEditor: '',
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

afterEach( () => {
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'MediaStudioPage', () => {
	it( 'renders the unavailable state when not configured', () => {
		setRuntime( '' );
		render( <MediaStudioPage /> );
		expect(
			screen.getByText( 'The Media Studio is not configured for this site.' )
		).toBeInTheDocument();
		expect( screen.queryByTitle( 'Media Studio' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a sandboxed iframe at the configured URL', () => {
		setRuntime( URL );
		render( <MediaStudioPage /> );
		const frame = screen.getByTitle( 'Media Studio' );
		expect( frame.tagName ).toBe( 'IFRAME' );
		expect( frame ).toHaveAttribute( 'src', URL );
		expect( frame ).toHaveAttribute(
			'sandbox',
			'allow-scripts allow-same-origin allow-forms'
		);
	} );
} );
