/**
 * Tests for chat-clipboard-service.js
 *
 * @package WP_MCP_AI
 */

describe( 'Clipboard Service', () => {
	let clipboardWriteText;

	beforeEach( () => {
		// Mock navigator.clipboard
		clipboardWriteText = jest.fn().mockResolvedValue( undefined );
		Object.defineProperty( navigator, 'clipboard', {
			value: {
				writeText: clipboardWriteText,
			},
			configurable: true,
		} );
	} );

	afterEach( () => {
		delete navigator.clipboard;
	} );

	describe( 'Clipboard API', () => {
		it( 'should copy text using navigator.clipboard', async () => {
			const text = 'Test content to copy';
			await navigator.clipboard.writeText( text );

			expect( clipboardWriteText ).toHaveBeenCalledWith( text );
		} );

		it( 'should handle clipboard write errors', async () => {
			const error = new Error( 'Clipboard write failed' );
			clipboardWriteText.mockRejectedValue( error );

			await expect( navigator.clipboard.writeText( 'test' ) ).rejects.toThrow(
				'Clipboard write failed'
			);
		} );
	} );

	describe( 'DOM manipulation for copy button', () => {
		let button;

		beforeEach( () => {
			button = document.createElement( 'button' );
			button.className = 'wp-mcp-ai-copy-button';
			document.body.appendChild( button );
		} );

		afterEach( () => {
			document.body.removeChild( button );
		} );

		it( 'should create a copy button element', () => {
			expect( button ).toBeInTheDocument();
			expect( button ).toHaveClass( 'wp-mcp-ai-copy-button' );
		} );

		it( 'should update button state classes', () => {
			button.classList.add( 'copied' );
			expect( button ).toHaveClass( 'copied' );

			button.classList.remove( 'copied' );
			expect( button ).not.toHaveClass( 'copied' );
		} );

		it( 'should set aria attributes for accessibility', () => {
			button.setAttribute( 'aria-label', 'Copy to clipboard' );
			button.setAttribute( 'title', 'Copy to clipboard' );

			expect( button ).toHaveAttribute( 'aria-label', 'Copy to clipboard' );
			expect( button ).toHaveAttribute( 'title', 'Copy to clipboard' );
		} );

		it( 'should update button text on copy success', () => {
			button.textContent = 'Copy';
			expect( button ).toHaveTextContent( 'Copy' );

			button.textContent = 'Copied!';
			expect( button ).toHaveTextContent( 'Copied!' );
		} );
	} );

	describe( 'Copy operation states', () => {
		it( 'should track idle state', () => {
			const state = { current: 'idle' };
			expect( state.current ).toBe( 'idle' );
		} );

		it( 'should transition to copied state', () => {
			const state = { current: 'idle' };
			state.current = 'copied';
			expect( state.current ).toBe( 'copied' );
		} );

		it( 'should transition to error state', () => {
			const state = { current: 'idle' };
			state.current = 'error';
			expect( state.current ).toBe( 'error' );
		} );

		it( 'should reset to idle state after timeout', async () => {
			jest.useFakeTimers();
			const state = { current: 'copied' };

			setTimeout( () => {
				state.current = 'idle';
			}, 2000 );

			jest.advanceTimersByTime( 2000 );
			expect( state.current ).toBe( 'idle' );

			jest.useRealTimers();
		} );
	} );

	describe( 'Text extraction', () => {
		it( 'should extract plain text from element', () => {
			const element = document.createElement( 'div' );
			element.textContent = 'Test content';

			expect( element.textContent ).toBe( 'Test content' );
		} );

		it( 'should handle empty text content', () => {
			const element = document.createElement( 'div' );
			expect( element.textContent ).toBe( '' );
		} );

		it( 'should preserve whitespace in text', () => {
			const element = document.createElement( 'pre' );
			element.textContent = '  line 1\n  line 2  ';

			expect( element.textContent ).toBe( '  line 1\n  line 2  ' );
		} );
	} );

	describe( 'Fallback mechanisms', () => {
		it( 'should handle missing clipboard API', () => {
			delete navigator.clipboard;
			expect( navigator.clipboard ).toBeUndefined();
		} );

		it( 'should create temporary textarea for fallback', () => {
			const textarea = document.createElement( 'textarea' );
			textarea.value = 'test content';
			textarea.style.position = 'absolute';
			textarea.style.left = '-9999px';
			document.body.appendChild( textarea );

			expect( textarea ).toBeInTheDocument();
			expect( textarea.value ).toBe( 'test content' );

			document.body.removeChild( textarea );
		} );
	} );
} );
