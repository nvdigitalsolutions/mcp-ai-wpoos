/**
 * Tests for Chat Button Helper Functions
 *
 * Tests the button management utilities in chat-ui-utilities-service.js
 *
 * @package WP_MCP_AI
 */

// Mock requestAnimationFrame for tests
global.requestAnimationFrame = ( callback ) => {
	callback();
	return 1;
};

// Load the chat UI utilities service
const fs = require( 'fs' );
const path = require( 'path' );

// Read and evaluate the service file
const serviceCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/chat-ui-utilities-service.js' ),
	'utf8'
);

// Execute the service code to set up window.wpMcpAiChatUIUtils
eval( serviceCode );

describe( 'Chat Button Helper Functions', () => {
	let uiUtils;

	beforeEach( () => {
		// Get reference to the UI utilities
		uiUtils = window.wpMcpAiChatUIUtils;
		
		// Ensure it's loaded
		expect( uiUtils ).toBeDefined();
	} );

	describe( 'toggleButtonClass', () => {
		it( 'should toggle class on button', () => {
			const button = document.createElement( 'button' );
			button.classList.add( 'test-button' );

			// Toggle on
			uiUtils.toggleButtonClass( button, 'active' );
			expect( button.classList.contains( 'active' ) ).toBe( true );

			// Toggle off
			uiUtils.toggleButtonClass( button, 'active' );
			expect( button.classList.contains( 'active' ) ).toBe( false );
		} );

		it( 'should force add class when force=true', () => {
			const button = document.createElement( 'button' );

			uiUtils.toggleButtonClass( button, 'active', true );
			expect( button.classList.contains( 'active' ) ).toBe( true );

			// Should stay when called again with force=true
			uiUtils.toggleButtonClass( button, 'active', true );
			expect( button.classList.contains( 'active' ) ).toBe( true );
		} );

		it( 'should force remove class when force=false', () => {
			const button = document.createElement( 'button' );
			button.classList.add( 'active' );

			uiUtils.toggleButtonClass( button, 'active', false );
			expect( button.classList.contains( 'active' ) ).toBe( false );

			// Should stay removed when called again with force=false
			uiUtils.toggleButtonClass( button, 'active', false );
			expect( button.classList.contains( 'active' ) ).toBe( false );
		} );

		it( 'should handle null button gracefully', () => {
			expect( () => {
				uiUtils.toggleButtonClass( null, 'active' );
			} ).not.toThrow();
		} );

		it( 'should handle missing className gracefully', () => {
			const button = document.createElement( 'button' );
			expect( () => {
				uiUtils.toggleButtonClass( button, null );
			} ).not.toThrow();
		} );

		it( 'should handle button without classList', () => {
			const button = {};
			expect( () => {
				uiUtils.toggleButtonClass( button, 'active' );
			} ).not.toThrow();
		} );
	} );

	describe( 'setButtonState', () => {
		it( 'should set disabled state', () => {
			const button = document.createElement( 'button' );

			uiUtils.setButtonState( button, { disabled: true } );
			expect( button.disabled ).toBe( true );

			uiUtils.setButtonState( button, { disabled: false } );
			expect( button.disabled ).toBe( false );
		} );

		it( 'should set hidden state', () => {
			const button = document.createElement( 'button' );

			uiUtils.setButtonState( button, { hidden: true } );
			expect( button.hidden ).toBe( true );

			uiUtils.setButtonState( button, { hidden: false } );
			expect( button.hidden ).toBe( false );
		} );

		it( 'should add CSS class', () => {
			const button = document.createElement( 'button' );

			uiUtils.setButtonState( button, { addClass: 'processing' } );
			expect( button.classList.contains( 'processing' ) ).toBe( true );
		} );

		it( 'should remove CSS class', () => {
			const button = document.createElement( 'button' );
			button.classList.add( 'processing' );

			uiUtils.setButtonState( button, { removeClass: 'processing' } );
			expect( button.classList.contains( 'processing' ) ).toBe( false );
		} );

		it( 'should handle multiple state changes at once', () => {
			const button = document.createElement( 'button' );
			button.classList.add( 'idle' );

			uiUtils.setButtonState( button, {
				disabled: true,
				hidden: false,
				addClass: 'processing',
				removeClass: 'idle',
			} );

			expect( button.disabled ).toBe( true );
			expect( button.hidden ).toBe( false );
			expect( button.classList.contains( 'processing' ) ).toBe( true );
			expect( button.classList.contains( 'idle' ) ).toBe( false );
		} );

		it( 'should handle null button gracefully', () => {
			expect( () => {
				uiUtils.setButtonState( null, { disabled: true } );
			} ).not.toThrow();
		} );

		it( 'should handle null options gracefully', () => {
			const button = document.createElement( 'button' );
			expect( () => {
				uiUtils.setButtonState( button, null );
			} ).not.toThrow();
		} );

		it( 'should handle empty options object', () => {
			const button = document.createElement( 'button' );
			expect( () => {
				uiUtils.setButtonState( button, {} );
			} ).not.toThrow();
		} );
	} );

	describe( 'setButtonIcon', () => {
		it( 'should update icon HTML', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			icon.className = 'icon';
			button.appendChild( icon );

			const newIconHTML = '<svg class="icon-svg"><circle r="5"/></svg>';
			uiUtils.setButtonIcon( button, newIconHTML );

			// Browser may expand self-closing tags
			expect( icon.innerHTML ).toContain( 'icon-svg' );
			expect( icon.innerHTML ).toContain( 'circle' );
			expect( icon.innerHTML ).toContain( 'r="5"' );
		} );

		it( 'should update icon with selector', () => {
			const button = document.createElement( 'button' );
			const wrapper = document.createElement( 'span' );
			const icon = document.createElement( 'i' );
			icon.className = 'fa fa-icon';
			wrapper.appendChild( icon );
			button.appendChild( wrapper );

			const newIconHTML = '<svg class="new-icon"/>';
			uiUtils.setButtonIcon( button, newIconHTML, '.fa-icon' );

			// Browser may expand self-closing tags
			expect( icon.innerHTML ).toContain( 'new-icon' );
			expect( icon.innerHTML ).toContain( 'svg' );
		} );

		it( 'should block javascript: protocol (XSS protection)', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			button.appendChild( icon );

			const originalHTML = icon.innerHTML;
			const dangerousHTML = '<a href="javascript:alert(1)">Click</a>';
			
			// Should not update and should log error
			const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();
			uiUtils.setButtonIcon( button, dangerousHTML );

			expect( icon.innerHTML ).toBe( originalHTML );
			expect( consoleSpy ).toHaveBeenCalled();
			consoleSpy.mockRestore();
		} );

		it( 'should block data:text/html (XSS protection)', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			button.appendChild( icon );

			const originalHTML = icon.innerHTML;
			const dangerousHTML = '<img src="data:text/html,<script>alert(1)</script>">';
			
			const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();
			uiUtils.setButtonIcon( button, dangerousHTML );

			expect( icon.innerHTML ).toBe( originalHTML );
			expect( consoleSpy ).toHaveBeenCalled();
			consoleSpy.mockRestore();
		} );

		it( 'should block script tags (XSS protection)', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			button.appendChild( icon );

			const originalHTML = icon.innerHTML;
			const dangerousHTML = '<script>alert("XSS")</script>';
			
			const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();
			uiUtils.setButtonIcon( button, dangerousHTML );

			expect( icon.innerHTML ).toBe( originalHTML );
			expect( consoleSpy ).toHaveBeenCalled();
			consoleSpy.mockRestore();
		} );

		it( 'should block event handlers (XSS protection)', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			button.appendChild( icon );

			const originalHTML = icon.innerHTML;
			const dangerousHTML = '<img src="x" onerror="alert(1)">';
			
			const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();
			uiUtils.setButtonIcon( button, dangerousHTML );

			expect( icon.innerHTML ).toBe( originalHTML );
			expect( consoleSpy ).toHaveBeenCalled();
			consoleSpy.mockRestore();
		} );

		it( 'should allow safe SVG icons', () => {
			const button = document.createElement( 'button' );
			const icon = document.createElement( 'span' );
			button.appendChild( icon );

			const safeHTML = '<svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>';
			uiUtils.setButtonIcon( button, safeHTML );

			// Browser may expand self-closing tags
			expect( icon.innerHTML ).toContain( 'viewBox="0 0 24 24"' );
			expect( icon.innerHTML ).toContain( 'path' );
			expect( icon.innerHTML ).toContain( 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z' );
		} );

		it( 'should handle null button gracefully', () => {
			expect( () => {
				uiUtils.setButtonIcon( null, '<svg/>' );
			} ).not.toThrow();
		} );

		it( 'should handle non-string iconHTML gracefully', () => {
			const button = document.createElement( 'button' );
			expect( () => {
				uiUtils.setButtonIcon( button, null );
			} ).not.toThrow();
		} );

		it( 'should handle button with no children', () => {
			const button = document.createElement( 'button' );
			
			// Should not throw even though there's no child element
			expect( () => {
				uiUtils.setButtonIcon( button, '<svg/>' );
			} ).not.toThrow();
		} );
	} );

	describe( 'updateButtonLabel', () => {
		it( 'should update aria-label', () => {
			const button = document.createElement( 'button' );

			uiUtils.updateButtonLabel( button, 'New Label' );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'New Label' );
		} );

		it( 'should update title attribute', () => {
			const button = document.createElement( 'button' );

			uiUtils.updateButtonLabel( button, 'New Title' );
			expect( button.getAttribute( 'title' ) ).toBe( 'New Title' );
		} );

		it( 'should update both aria-label and title', () => {
			const button = document.createElement( 'button' );

			uiUtils.updateButtonLabel( button, 'Button Label' );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Button Label' );
			expect( button.getAttribute( 'title' ) ).toBe( 'Button Label' );
		} );

		it( 'should handle null button gracefully', () => {
			expect( () => {
				uiUtils.updateButtonLabel( null, 'Label' );
			} ).not.toThrow();
		} );

		it( 'should handle non-string label gracefully', () => {
			const button = document.createElement( 'button' );
			expect( () => {
				uiUtils.updateButtonLabel( button, null );
			} ).not.toThrow();
		} );

		it( 'should replace existing labels', () => {
			const button = document.createElement( 'button' );
			button.setAttribute( 'aria-label', 'Old Label' );
			button.setAttribute( 'title', 'Old Title' );

			uiUtils.updateButtonLabel( button, 'New Label' );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'New Label' );
			expect( button.getAttribute( 'title' ) ).toBe( 'New Label' );
		} );
	} );

	describe( 'Integration: Button State Management', () => {
		it( 'should manage voice chat button recording state', () => {
			const button = document.createElement( 'button' );
			button.className = 'wp-mcp-ai-chat__voice-chat';

			// Start recording
			uiUtils.setButtonState( button, {
				disabled: false,
				addClass: 'wp-mcp-ai-chat__voice-chat--recording',
			} );
			uiUtils.updateButtonLabel( button, 'Stop recording' );

			expect( button.disabled ).toBe( false );
			expect( button.classList.contains( 'wp-mcp-ai-chat__voice-chat--recording' ) ).toBe( true );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Stop recording' );

			// Stop recording, start processing
			uiUtils.setButtonState( button, {
				disabled: true,
				removeClass: 'wp-mcp-ai-chat__voice-chat--recording',
				addClass: 'wp-mcp-ai-chat__voice-chat--processing',
			} );
			uiUtils.updateButtonLabel( button, 'Processing...' );

			expect( button.disabled ).toBe( true );
			expect( button.classList.contains( 'wp-mcp-ai-chat__voice-chat--recording' ) ).toBe( false );
			expect( button.classList.contains( 'wp-mcp-ai-chat__voice-chat--processing' ) ).toBe( true );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Processing...' );

			// Back to idle
			uiUtils.setButtonState( button, {
				disabled: false,
				removeClass: 'wp-mcp-ai-chat__voice-chat--processing',
			} );
			uiUtils.updateButtonLabel( button, 'Voice chat' );

			expect( button.disabled ).toBe( false );
			expect( button.classList.contains( 'wp-mcp-ai-chat__voice-chat--processing' ) ).toBe( false );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Voice chat' );
		} );

		it( 'should manage transcribe button recording state', () => {
			const button = document.createElement( 'button' );
			button.className = 'wp-mcp-ai-chat__transcribe';

			// Start recording
			uiUtils.toggleButtonClass( button, 'wp-mcp-ai-chat__transcribe--recording', true );
			uiUtils.updateButtonLabel( button, 'Stop recording' );

			expect( button.classList.contains( 'wp-mcp-ai-chat__transcribe--recording' ) ).toBe( true );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Stop recording' );

			// Stop recording
			uiUtils.setButtonState( button, {
				disabled: true,
				removeClass: 'wp-mcp-ai-chat__transcribe--recording',
			} );

			expect( button.disabled ).toBe( true );
			expect( button.classList.contains( 'wp-mcp-ai-chat__transcribe--recording' ) ).toBe( false );

			// Back to idle
			uiUtils.setButtonState( button, {
				disabled: false,
			} );
			uiUtils.updateButtonLabel( button, 'Transcribe audio' );

			expect( button.disabled ).toBe( false );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'Transcribe audio' );
		} );
	} );
} );
