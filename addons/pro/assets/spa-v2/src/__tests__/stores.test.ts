/**
 * Pro SPA v2 — Zustand store tests.
 *
 * Covers uiStore, modelStore, and commandStore state transitions.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { useUIStore } from '../stores/uiStore';
import { useModelStore } from '../stores/modelStore';
import { useCommandStore } from '../stores/commandStore';

afterEach( () => {
	vi.restoreAllMocks();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// uiStore
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'uiStore', () => {
	beforeEach( () => {
		useUIStore.setState( {
			sidebarOpen: true,
			rightPanelOpen: false,
			toasts: [],
			theme: 'auto',
		} );
		localStorage.clear();
	} );

	describe( 'toggleSidebar', () => {
		it( 'toggles sidebarOpen from true to false and back', () => {
			const { toggleSidebar } = useUIStore.getState();

			toggleSidebar();
			expect( useUIStore.getState().sidebarOpen ).toBe( false );

			toggleSidebar();
			expect( useUIStore.getState().sidebarOpen ).toBe( true );
		} );
	} );

	describe( 'toggleRightPanel', () => {
		it( 'toggles rightPanelOpen from false to true and back', () => {
			const { toggleRightPanel } = useUIStore.getState();

			toggleRightPanel();
			expect( useUIStore.getState().rightPanelOpen ).toBe( true );

			toggleRightPanel();
			expect( useUIStore.getState().rightPanelOpen ).toBe( false );
		} );
	} );

	describe( 'addToast', () => {
		it( 'adds a toast with default variant "info"', () => {
			vi.useFakeTimers();
			const { addToast } = useUIStore.getState();

			addToast( 'test message' );

			expect( useUIStore.getState().toasts ).toHaveLength( 1 );
			expect( useUIStore.getState().toasts[ 0 ].message ).toBe( 'test message' );
			expect( useUIStore.getState().toasts[ 0 ].variant ).toBe( 'info' );

			vi.useRealTimers();
		} );

		it( 'adds a toast with explicit variant', () => {
			vi.useFakeTimers();
			const { addToast } = useUIStore.getState();

			addToast( 'warning msg', 'warning' );

			expect( useUIStore.getState().toasts ).toHaveLength( 1 );
			expect( useUIStore.getState().toasts[ 0 ].variant ).toBe( 'warning' );

			vi.useRealTimers();
		} );

		it( 'auto-removes the toast after 6 seconds', () => {
			vi.useFakeTimers();
			const { addToast } = useUIStore.getState();

			addToast( 'will disappear' );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );

			vi.advanceTimersByTime( 5999 );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );

			vi.advanceTimersByTime( 1 );
			expect( useUIStore.getState().toasts ).toHaveLength( 0 );

			vi.useRealTimers();
		} );

		it( 'removes only the matching toast via the timer', () => {
			vi.useFakeTimers();
			const { addToast } = useUIStore.getState();

			addToast( 'first' );
			vi.advanceTimersByTime( 1 ); // ensure different Date.now() id
			addToast( 'second' );

			expect( useUIStore.getState().toasts ).toHaveLength( 2 );

			// Only the first toast's timer should fire after 6 s.
			vi.advanceTimersByTime( 5999 );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );
			expect( useUIStore.getState().toasts[ 0 ].message ).toBe( 'second' );

			vi.useRealTimers();
		} );
	} );

	describe( 'removeToast', () => {
		it( 'removes a specific toast by id', () => {
			vi.useFakeTimers();
			const { addToast, removeToast } = useUIStore.getState();

			addToast( 'first' );
			vi.advanceTimersByTime( 1 ); // ensure different Date.now() id
			addToast( 'second' );

			expect( useUIStore.getState().toasts ).toHaveLength( 2 );
			const ids = useUIStore.getState().toasts.map( ( t ) => t.id );

			removeToast( ids[ 0 ] );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );
			expect( useUIStore.getState().toasts[ 0 ].message ).toBe( 'second' );

			vi.useRealTimers();
		} );

		it( 'is a no-op for unknown ids', () => {
			const { addToast, removeToast } = useUIStore.getState();

			addToast( 'keep me' );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );

			removeToast( 99999 );
			expect( useUIStore.getState().toasts ).toHaveLength( 1 );
		} );
	} );

	describe( 'setTheme', () => {
		it( 'persists the theme to localStorage', () => {
			const { setTheme } = useUIStore.getState();

			setTheme( 'dark' );
			expect( useUIStore.getState().theme ).toBe( 'dark' );
			expect( localStorage.getItem( 'nvoos-pro-spa.theme' ) ).toBe( 'dark' );

			setTheme( 'light' );
			expect( useUIStore.getState().theme ).toBe( 'light' );
			expect( localStorage.getItem( 'nvoos-pro-spa.theme' ) ).toBe( 'light' );
		} );

		it( 'persists "auto" to localStorage', () => {
			const { setTheme } = useUIStore.getState();

			setTheme( 'auto' );
			expect( useUIStore.getState().theme ).toBe( 'auto' );
			expect( localStorage.getItem( 'nvoos-pro-spa.theme' ) ).toBe( 'auto' );
		} );
	} );

	describe( 'getInitialTheme', () => {
		it( 'reads theme from localStorage on store creation', async () => {
			localStorage.setItem( 'nvoos-pro-spa.theme', 'dark' );

			vi.resetModules();
			const { useUIStore: freshStore } = await import( '../stores/uiStore' );
			expect( freshStore.getState().theme ).toBe( 'dark' );

			localStorage.clear();
		} );

		it( 'defaults to "auto" when localStorage is empty', async () => {
			// Ensure localStorage is clear — beforeEach already does this.
			vi.resetModules();
			const { useUIStore: freshStore } = await import( '../stores/uiStore' );
			expect( freshStore.getState().theme ).toBe( 'auto' );
		} );

		it( 'defaults to "auto" on corrupt localStorage value', async () => {
			localStorage.setItem( 'nvoos-pro-spa.theme', 'bogus' );

			vi.resetModules();
			const { useUIStore: freshStore } = await import( '../stores/uiStore' );
			expect( freshStore.getState().theme ).toBe( 'auto' );

			localStorage.clear();
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// modelStore
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'modelStore', () => {
	beforeEach( () => {
		useModelStore.setState( {
			model: { provider: 'openai', model: 'gpt-4o' },
			profile: 'write',
			availableModels: [],
			availableProfiles: [],
		} );
	} );

	describe( 'setModel', () => {
		it( 'replaces the model selection', () => {
			const { setModel } = useModelStore.getState();

			setModel( { provider: 'ollama', model: 'llama3' } );
			expect( useModelStore.getState().model ).toEqual( {
				provider: 'ollama',
				model: 'llama3',
			} );
		} );
	} );

	describe( 'setProfile', () => {
		it( 'replaces the profile selection', () => {
			const { setProfile } = useModelStore.getState();

			setProfile( 'chat' );
			expect( useModelStore.getState().profile ).toBe( 'chat' );
		} );
	} );

	describe( 'setAvailableModels', () => {
		it( 'replaces the available models array', () => {
			const models = [
				{ provider: 'openai', model: 'gpt-4o' },
				{ provider: 'gemini', model: 'gemini-pro' },
			];

			useModelStore.getState().setAvailableModels( models );
			expect( useModelStore.getState().availableModels ).toEqual( models );
			expect( useModelStore.getState().availableModels ).toHaveLength( 2 );
		} );

		it( 'replaces an existing array (not appends)', () => {
			useModelStore.getState().setAvailableModels( [
				{ provider: 'openai', model: 'gpt-4o' },
			] );

			useModelStore.getState().setAvailableModels( [] );
			expect( useModelStore.getState().availableModels ).toEqual( [] );
		} );
	} );

	describe( 'setAvailableProfiles', () => {
		it( 'replaces the available profiles array', () => {
			const profiles = [ 'write', 'chat', 'analyze' ];

			useModelStore.getState().setAvailableProfiles( profiles );
			expect( useModelStore.getState().availableProfiles ).toEqual( profiles );
			expect( useModelStore.getState().availableProfiles ).toHaveLength( 3 );
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// commandStore
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'commandStore', () => {
	const noop = () => undefined;

	beforeEach( () => {
		useCommandStore.setState( { commands: [] } );
	} );

	describe( 'setCommands', () => {
		it( 'replaces the command list entirely', () => {
			const cmds = [
				{ id: 'a', label: 'Alpha', category: 'action' as const, handler: noop },
				{ id: 'b', label: 'Beta', category: 'tool' as const, handler: noop },
			];

			useCommandStore.getState().setCommands( cmds );
			expect( useCommandStore.getState().commands ).toEqual( cmds );
			expect( useCommandStore.getState().commands ).toHaveLength( 2 );
		} );

		it( 'can clear all commands with an empty array', () => {
			useCommandStore.getState().setCommands( [
				{ id: 'x', label: 'X', category: 'action' as const, handler: noop },
			] );
			useCommandStore.getState().setCommands( [] );
			expect( useCommandStore.getState().commands ).toEqual( [] );
		} );
	} );

	describe( 'registerCommand', () => {
		it( 'adds a new command', () => {
			const cmd = {
				id: 'nav',
				label: 'Navigate',
				category: 'navigation' as const,
				handler: noop,
			};

			useCommandStore.getState().registerCommand( cmd );
			expect( useCommandStore.getState().commands ).toHaveLength( 1 );
			expect( useCommandStore.getState().commands[ 0 ] ).toEqual( cmd );
		} );

		it( 'updates an existing command with the same id', () => {
			const v1 = {
				id: 'dup',
				label: 'Version 1',
				category: 'action' as const,
				handler: noop,
			};
			const v2 = {
				id: 'dup',
				label: 'Version 2',
				category: 'tool' as const,
				handler: noop,
			};

			useCommandStore.getState().registerCommand( v1 );
			useCommandStore.getState().registerCommand( v2 );

			expect( useCommandStore.getState().commands ).toHaveLength( 1 );
			expect( useCommandStore.getState().commands[ 0 ].label ).toBe( 'Version 2' );
			expect( useCommandStore.getState().commands[ 0 ].category ).toBe( 'tool' );
		} );
	} );

	describe( 'unregisterCommand', () => {
		it( 'removes a command by id', () => {
			const cmd = {
				id: 'rm',
				label: 'Remove Me',
				category: 'action' as const,
				handler: noop,
			};

			useCommandStore.getState().registerCommand( cmd );
			expect( useCommandStore.getState().commands ).toHaveLength( 1 );

			useCommandStore.getState().unregisterCommand( 'rm' );
			expect( useCommandStore.getState().commands ).toHaveLength( 0 );
		} );

		it( 'is a no-op when the id does not exist', () => {
			const cmd = {
				id: 'keep',
				label: 'Keep Me',
				category: 'action' as const,
				handler: noop,
			};

			useCommandStore.getState().registerCommand( cmd );
			useCommandStore.getState().unregisterCommand( 'ghost' );

			expect( useCommandStore.getState().commands ).toHaveLength( 1 );
			expect( useCommandStore.getState().commands[ 0 ].id ).toBe( 'keep' );
		} );
	} );
} );
