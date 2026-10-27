/**
 * Permission utility tests — capability-based access (auth/permissions.ts).
 *
 * Verifies the central permission helpers: capability checks never match
 * role names, unknown users fail closed, and usePermissions() derives the
 * context from the SPA runtime global.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';

import { can, usePermissions } from '../auth/permissions';

function setRuntimeUserCapabilities( capabilities: string[] ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/',
		proApi: '',
		nonce: 'test-nonce',
		config: { assistantId: 1 },
		endpoints: {
			chat: '/mcp-ai/v1/chat',
			chatClient: '/mcp-ai/v1/chat-client',
			transcripts: '/mcp-ai/v1/chat-transcripts',
			threads: '/mcp-ai/v1/threads',
			tools: '/mcp-ai/v1/tools',
			assistants: '/mcp-ai/v1/assistants',
			settings: '/mcp-ai/v1/settings',
			memory: '',
			workflows: '',
			analytics: '',
			approvals: '',
		},
		user: { id: 1, login: 'user', displayName: 'User', capabilities },
		mentionTypes: [],
	};
}

afterEach( () => {
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'can', () => {
	it( 'matches capabilities, never role names', () => {
		const capabilities = [ 'edit_posts', 'upload_files' ];

		expect( can( capabilities, 'edit_posts' ) ).toBe( true );
		// The role slug is not a capability — a capabilities-driven check
		// must not treat it as one.
		expect( can( capabilities, 'author' ) ).toBe( false );
		expect( can( capabilities, 'manage_options' ) ).toBe( false );
	} );

	it( 'fails closed for missing or empty capability lists', () => {
		expect( can( undefined, 'manage_options' ) ).toBe( false );
		expect( can( [], 'manage_options' ) ).toBe( false );
	} );
} );

describe( 'usePermissions', () => {
	it( 'reports admin permissions from the runtime capabilities', () => {
		setRuntimeUserCapabilities( [ 'manage_options', 'edit_posts' ] );

		const { result } = renderHook( () => usePermissions() );

		expect( result.current.isAdmin ).toBe( true );
		expect( result.current.can( 'manage_options' ) ).toBe( true );
		expect( result.current.can( 'edit_posts' ) ).toBe( true );
		expect( result.current.can( 'edit_others_posts' ) ).toBe( false );
	} );

	it( 'fails closed for non-admin users', () => {
		setRuntimeUserCapabilities( [ 'edit_posts', 'upload_files' ] );

		const { result } = renderHook( () => usePermissions() );

		expect( result.current.isAdmin ).toBe( false );
		expect( result.current.can( 'manage_options' ) ).toBe( false );
		expect( result.current.can( 'edit_posts' ) ).toBe( true );
	} );

	it( 'fails closed when the runtime is missing', () => {
		const { result } = renderHook( () => usePermissions() );

		expect( result.current.capabilities ).toEqual( [] );
		expect( result.current.isAdmin ).toBe( false );
	} );
} );
