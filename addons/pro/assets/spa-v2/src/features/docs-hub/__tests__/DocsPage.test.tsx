/**
 * DocsPage tests.
 *
 * Covers the two page states: unavailable (no docsHub endpoint) and ready
 * (iframe rendered with the configured URL + the documented sandbox policy).
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

import { DocsPage } from '../DocsPage';

const DOCS_URL = 'https://console.nvoos.cloud/docs-hub/';

function setRuntime( docsHub: string ): void {
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
			docsHub,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

afterEach( () => {
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'DocsPage', () => {
	it( 'renders the unavailable state when the runtime has no docsHub endpoint', () => {
		setRuntime( '' );
		render( <DocsPage /> );

		expect(
			screen.getByText(
				'The documentation browser is not configured for this site.',
			)
		).toBeInTheDocument();
		expect( screen.queryByTitle( 'Documentation browser' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a sandboxed iframe at the configured URL', () => {
		setRuntime( DOCS_URL );
		render( <DocsPage /> );

		const frame = screen.getByTitle( 'Documentation browser' );
		expect( frame.tagName ).toBe( 'IFRAME' );
		expect( frame ).toHaveAttribute( 'src', DOCS_URL );
		expect( frame ).toHaveAttribute(
			'sandbox',
			'allow-scripts allow-same-origin allow-forms'
		);
		expect( frame ).toHaveAttribute( 'referrerPolicy', 'no-referrer' );
	} );
} );
