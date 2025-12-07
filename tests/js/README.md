# JavaScript Tests

This directory contains the JavaScript test suite for the Open Operator System plugin.

## Structure

```
tests/js/
├── setup.js              # Jest setup and global mocks
├── *.test.js             # Test files (one per module/feature)
└── README.md             # This file
```

## Running Tests

### Run all tests
```bash
npm test
```

### Run tests in watch mode (for development)
```bash
npm run test:watch
```

### Run tests with coverage report
```bash
npm run test:coverage
```

### Run tests with verbose output
```bash
npm run test:verbose
```

## Writing Tests

### Test File Naming

Test files should be named `<module-name>.test.js` and placed in this directory.

Example:
- `storage-util.test.js` - Tests for `assets/js/storage-util.js`
- `clipboard-service.test.js` - Tests for `assets/js/chat-clipboard-service.js`

### Test Structure

```javascript
/**
 * Tests for my-module.js
 *
 * @package WP_MCP_AI
 */

describe( 'MyModule', () => {
    beforeEach( () => {
        // Setup before each test
    } );

    afterEach( () => {
        // Cleanup after each test
    } );

    describe( 'feature group', () => {
        it( 'should do something specific', () => {
            // Arrange
            const input = 'test';

            // Act
            const result = doSomething( input );

            // Assert
            expect( result ).toBe( 'expected' );
        } );
    } );
} );
```

## Available Mocks

The test setup provides mocks for:

### WordPress Globals
- `wp.i18n.__()` - Translation function
- `wp.i18n._x()` - Translation with context
- `wp.i18n._n()` - Plural translations
- `wp.i18n.sprintf()` - String formatting
- `wp.hooks` - WordPress hooks API
- `wp.data` - WordPress data stores

### jQuery
- Basic jQuery selector and methods
- `$.ajax()`, `$.post()`, `$.get()`

### Browser APIs
- `localStorage` - Mocked storage with all methods
- `sessionStorage` - Mocked session storage
- `navigator.clipboard` - Available via `Object.defineProperty()`

### Custom Mocks

You can add custom mocks in your test files:

```javascript
// Mock a specific WordPress global
global.wpMcpAiChat = {
    apiUrl: 'https://example.com/api',
    nonce: 'test-nonce',
};

// Mock fetch
global.fetch = jest.fn().mockResolvedValue( {
    json: () => Promise.resolve( { success: true } ),
} );
```

## Testing Best Practices

1. **Test behavior, not implementation** - Focus on what the code does, not how it does it
2. **Use descriptive test names** - Test names should clearly state what is being tested
3. **Keep tests focused** - Each test should verify one specific behavior
4. **Use AAA pattern** - Arrange, Act, Assert
5. **Clean up after tests** - Use `beforeEach` and `afterEach` to ensure tests are isolated
6. **Mock external dependencies** - Don't make real API calls or rely on external services
7. **Maintain good coverage** - Aim for high code coverage, but focus on meaningful tests

## Common Testing Patterns

### Testing async code
```javascript
it( 'should handle async operations', async () => {
    const result = await fetchData();
    expect( result ).toEqual( expectedData );
} );
```

### Testing DOM manipulation
```javascript
it( 'should update DOM correctly', () => {
    const element = document.createElement( 'div' );
    element.textContent = 'test';
    
    expect( element ).toHaveTextContent( 'test' );
} );
```

### Testing events
```javascript
it( 'should handle click events', () => {
    const button = document.createElement( 'button' );
    const handler = jest.fn();
    
    button.addEventListener( 'click', handler );
    button.click();
    
    expect( handler ).toHaveBeenCalled();
} );
```

### Testing timers
```javascript
it( 'should execute after timeout', () => {
    jest.useFakeTimers();
    const callback = jest.fn();
    
    setTimeout( callback, 1000 );
    jest.advanceTimersByTime( 1000 );
    
    expect( callback ).toHaveBeenCalled();
    jest.useRealTimers();
} );
```

## Debugging Tests

### Run a single test file
```bash
npm test -- storage-util.test.js
```

### Run tests matching a pattern
```bash
npm test -- --testNamePattern="localStorage"
```

### Run with debugging
```bash
node --inspect-brk node_modules/.bin/jest --runInBand
```

Then open Chrome DevTools and navigate to `chrome://inspect` to debug.

## Coverage Reports

Coverage reports are generated in the `coverage/` directory:
- `coverage/lcov-report/index.html` - HTML coverage report (open in browser)
- `coverage/lcov.info` - LCOV format for CI tools

View the HTML report:
```bash
npm run test:coverage
open coverage/lcov-report/index.html
```

## Continuous Integration

JavaScript tests run automatically on:
- Every push to `main` branch
- Every pull request

See `.github/workflows/javascript-tests.yml` for CI configuration.

## Resources

- [Jest Documentation](https://jestjs.io/docs/getting-started)
- [Testing Library](https://testing-library.com/docs/dom-testing-library/intro/)
- [Jest DOM Matchers](https://github.com/testing-library/jest-dom)
- [WordPress JavaScript Testing](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/#test)

## Support

For testing-related questions:
1. Check this README
2. Review existing test files for examples
3. Consult Jest documentation
4. Open an issue on GitHub
