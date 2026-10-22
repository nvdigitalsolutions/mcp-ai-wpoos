/**
 * SettingsPage render tests.
 *
 * Pins the schema-driven form behavior: tabs, section cards, cross-tab
 * search, and the loading → loaded transition (React error #310 regression —
 * the page must never early-return before its hooks).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react';

import { SettingsPage } from '../features/settings/SettingsPage';

const SETTINGS_ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/settings';

function setRuntime(): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: 'https://example.com/wp-json/mcp-ai/v1/chat-transcripts',
			threads: 'https://example.com/wp-json/mcp-ai/v1/threads',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: SETTINGS_ENDPOINT,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function jsonResponse( payload: unknown ): Response {
	return {
		ok: true,
		status: 200,
		json: async () => payload,
	} as Response;
}

function schemaPayload(): unknown {
	return {
		success: true,
		data: {
			tabs: {
				general: 'General',
				api: 'API & Providers',
			},
			fields: [
				{
					key: 'enable_logging',
					type: 'checkbox',
					section_id: 'behavior',
					section: 'Behavior',
					tab: 'general',
					label: 'Enable logging',
					description: 'Write debug logs.',
					sensitive: false,
					default: false,
				},
				{
					key: 'max_tokens',
					type: 'number',
					section_id: 'model',
					section: 'Model',
					tab: 'general',
					label: 'Max tokens',
					description: 'Response token ceiling.',
					sensitive: false,
					default: 1024,
					min: 1,
					max: 8192,
				},
				{
					key: 'openai_api_key',
					type: 'password',
					section_id: 'keys',
					section: 'Keys',
					tab: 'api',
					label: 'OpenAI API key',
					description: 'Used for GPT models.',
					sensitive: true,
					default: '',
				},
			],
		},
	};
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = null;
} );

describe( 'SettingsPage', () => {
	it( 'renders tab, section cards, and fields after the loading state resolves', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === `${ SETTINGS_ENDPOINT }/schema` ) {
					return Promise.resolve( jsonResponse( schemaPayload() ) );
				}
				if ( url === `${ SETTINGS_ENDPOINT }/values` ) {
					return Promise.resolve(
						jsonResponse( {
							success: true,
							data: { values: { enable_logging: true, max_tokens: 512 } },
						} )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <SettingsPage /> );

		expect( screen.getByText( 'Loading settings…' ) ).toBeTruthy();

		await waitFor( () => {
			expect( screen.getByText( 'Enable logging' ) ).toBeTruthy();
		} );
		expect( screen.getByText( 'General' ) ).toBeTruthy();
		expect( screen.getByText( 'Behavior' ) ).toBeTruthy();
		expect( screen.getByText( 'Max tokens' ) ).toBeTruthy();
		// The API tab's fields are not visible on the General tab.
		expect( screen.queryByText( 'OpenAI API key' ) ).toBeNull();
	} );

	it( 'searches across tabs and shows the matching section', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === `${ SETTINGS_ENDPOINT }/schema` ) {
					return Promise.resolve( jsonResponse( schemaPayload() ) );
				}
				if ( url === `${ SETTINGS_ENDPOINT }/values` ) {
					return Promise.resolve(
						jsonResponse( { success: true, data: { values: {} } } )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <SettingsPage /> );
		await waitFor( () => {
			expect( screen.getByText( 'Enable logging' ) ).toBeTruthy();
		} );

		fireEvent.change( screen.getByLabelText( 'Search settings' ), {
			target: { value: 'openai' },
		} );

		await waitFor( () => {
			expect( screen.getByText( 'OpenAI API key' ) ).toBeTruthy();
		} );
		// Non-matching fields disappear from the search results.
		expect( screen.queryByText( 'Enable logging' ) ).toBeNull();
		// The section eyebrow is shown while searching globally.
		expect( screen.getByText( 'Keys' ) ).toBeTruthy();

		// Clicking a tab clears the search and restores the tab view.
		fireEvent.click( screen.getByText( 'General' ) );
		await waitFor( () => {
			expect( screen.getByText( 'Enable logging' ) ).toBeTruthy();
		} );
		expect( screen.queryByText( 'OpenAI API key' ) ).toBeNull();
	} );
} );
