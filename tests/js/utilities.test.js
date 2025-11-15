/**
 * Tests for general JavaScript utilities
 *
 * @package WP_MCP_AI
 */

describe( 'General Utilities', () => {
	describe( 'WordPress i18n functions', () => {
		it( 'should translate text with __', () => {
			const translated = wp.i18n.__( 'Hello World' );
			expect( translated ).toBe( 'Hello World' );
		} );

		it( 'should handle context with _x', () => {
			const translated = wp.i18n._x( 'Post', 'noun' );
			expect( translated ).toBe( 'Post' );
		} );

		it( 'should handle plurals with _n', () => {
			const singular = wp.i18n._n( '1 item', '%d items', 1 );
			expect( singular ).toBe( '1 item' );

			const plural = wp.i18n._n( '1 item', '%d items', 5 );
			expect( plural ).toBe( '%d items' );
		} );

		it( 'should format strings with sprintf', () => {
			const formatted = wp.i18n.sprintf( 'Hello %s, you have %s messages', 'John', '5' );
			expect( formatted ).toBe( 'Hello John, you have 5 messages' );
		} );
	} );

	describe( 'DOM utilities', () => {
		it( 'should create and append elements', () => {
			const container = document.createElement( 'div' );
			const child = document.createElement( 'span' );
			child.textContent = 'Test';
			container.appendChild( child );

			expect( container.children.length ).toBe( 1 );
			expect( container.firstChild.textContent ).toBe( 'Test' );
		} );

		it( 'should query elements', () => {
			const container = document.createElement( 'div' );
			container.innerHTML = '<p class="test">Content</p>';
			document.body.appendChild( container );

			const element = document.querySelector( '.test' );
			expect( element ).toBeTruthy();
			expect( element.textContent ).toBe( 'Content' );

			document.body.removeChild( container );
		} );

		it( 'should modify element attributes', () => {
			const element = document.createElement( 'div' );
			element.setAttribute( 'data-id', '123' );
			element.setAttribute( 'aria-label', 'Test label' );

			expect( element.getAttribute( 'data-id' ) ).toBe( '123' );
			expect( element.getAttribute( 'aria-label' ) ).toBe( 'Test label' );
		} );

		it( 'should handle class manipulation', () => {
			const element = document.createElement( 'div' );
			
			element.classList.add( 'active' );
			expect( element.classList.contains( 'active' ) ).toBe( true );

			element.classList.remove( 'active' );
			expect( element.classList.contains( 'active' ) ).toBe( false );

			element.classList.toggle( 'visible' );
			expect( element.classList.contains( 'visible' ) ).toBe( true );
		} );
	} );

	describe( 'Event handling', () => {
		it( 'should add and trigger event listeners', () => {
			const element = document.createElement( 'button' );
			const handler = jest.fn();

			element.addEventListener( 'click', handler );
			element.click();

			expect( handler ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'should remove event listeners', () => {
			const element = document.createElement( 'button' );
			const handler = jest.fn();

			element.addEventListener( 'click', handler );
			element.removeEventListener( 'click', handler );
			element.click();

			expect( handler ).not.toHaveBeenCalled();
		} );

		it( 'should handle custom events', () => {
			const element = document.createElement( 'div' );
			const handler = jest.fn();

			element.addEventListener( 'customEvent', handler );
			
			const event = new CustomEvent( 'customEvent', { 
				detail: { data: 'test' } 
			} );
			element.dispatchEvent( event );

			expect( handler ).toHaveBeenCalledTimes( 1 );
			expect( handler.mock.calls[ 0 ][ 0 ].detail.data ).toBe( 'test' );
		} );
	} );

	describe( 'Array and Object utilities', () => {
		it( 'should filter arrays', () => {
			const numbers = [ 1, 2, 3, 4, 5 ];
			const evens = numbers.filter( n => n % 2 === 0 );

			expect( evens ).toEqual( [ 2, 4 ] );
		} );

		it( 'should map arrays', () => {
			const numbers = [ 1, 2, 3 ];
			const doubled = numbers.map( n => n * 2 );

			expect( doubled ).toEqual( [ 2, 4, 6 ] );
		} );

		it( 'should reduce arrays', () => {
			const numbers = [ 1, 2, 3, 4 ];
			const sum = numbers.reduce( ( acc, n ) => acc + n, 0 );

			expect( sum ).toBe( 10 );
		} );

		it( 'should merge objects', () => {
			const obj1 = { a: 1, b: 2 };
			const obj2 = { b: 3, c: 4 };
			const merged = { ...obj1, ...obj2 };

			expect( merged ).toEqual( { a: 1, b: 3, c: 4 } );
		} );

		it( 'should check object properties', () => {
			const obj = { key: 'value' };

			expect( obj.hasOwnProperty( 'key' ) ).toBe( true );
			expect( obj.hasOwnProperty( 'missing' ) ).toBe( false );
		} );
	} );

	describe( 'String utilities', () => {
		it( 'should trim whitespace', () => {
			const str = '  hello world  ';
			expect( str.trim() ).toBe( 'hello world' );
		} );

		it( 'should convert case', () => {
			const str = 'Hello World';
			expect( str.toLowerCase() ).toBe( 'hello world' );
			expect( str.toUpperCase() ).toBe( 'HELLO WORLD' );
		} );

		it( 'should split strings', () => {
			const str = 'one,two,three';
			const parts = str.split( ',' );

			expect( parts ).toEqual( [ 'one', 'two', 'three' ] );
		} );

		it( 'should replace text', () => {
			const str = 'Hello World';
			const replaced = str.replace( 'World', 'JavaScript' );

			expect( replaced ).toBe( 'Hello JavaScript' );
		} );
	} );

	describe( 'Async operations', () => {
		it( 'should handle promises', async () => {
			const promise = Promise.resolve( 'success' );
			const result = await promise;

			expect( result ).toBe( 'success' );
		} );

		it( 'should handle promise rejection', async () => {
			const promise = Promise.reject( new Error( 'failed' ) );

			await expect( promise ).rejects.toThrow( 'failed' );
		} );

		it( 'should handle setTimeout', async () => {
			jest.useFakeTimers();

			const callback = jest.fn();
			setTimeout( callback, 1000 );

			jest.advanceTimersByTime( 1000 );
			expect( callback ).toHaveBeenCalled();

			jest.useRealTimers();
		} );
	} );
} );
