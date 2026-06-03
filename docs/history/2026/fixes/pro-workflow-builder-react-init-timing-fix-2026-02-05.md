# Pro Workflow Builder React Initialization Timing Fix

**Date:** 2026-02-05  
**Issue:** Pro Workflow Builder loading empty - "The deferred DOM Node could not be resolved to a valid node"  
**Reference:** Issue #3570 - Fix Pro Workflow Builder React initialization timing

## Problem

The Pro Workflow Builder page was loading empty with the error:
```
The deferred DOM Node could not be resolved to a valid node
```

This error occurred when React tried to mount before the DOM element (`#mcp-ai-pro-workflow-builder-root`) was fully available.

## Root Cause

When WordPress enqueues scripts in the footer using `wp_enqueue_script(..., true)`, they execute after HTML parsing but potentially in the "interactive" document ready state, before specific DOM elements are fully accessible.

The previous initialization code:
```javascript
if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initWorkflowBuilder );
} else {
    initWorkflowBuilder();
}
```

This approach had a timing issue:
- If `readyState === 'interactive'`, the script would run immediately
- But the container element might not yet be accessible
- React's `createRoot()` would fail silently or show the deferred DOM node error

## Solution

Implemented a robust initialization pattern with three key improvements:

### 1. Deferred Execution with requestAnimationFrame
```javascript
const startInit = () => {
    requestAnimationFrame( initWorkflowBuilder );
};
```

Using `requestAnimationFrame` defers execution until the next browser paint cycle, ensuring all DOM elements are rendered and accessible.

### 2. Retry Mechanism with Exponential Backoff
```javascript
const initWorkflowBuilder = ( attempt = 1 ) => {
    const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
    
    if ( container ) {
        // Initialize React
        const root = createRoot( container );
        root.render( <WorkflowBuilder /> );
    } else if ( attempt < MAX_INIT_ATTEMPTS ) {
        // Retry with exponential backoff
        const delay = Math.min( 
            INITIAL_DELAY_MS * Math.pow( BACKOFF_MULTIPLIER, attempt - 1 ), 
            MAX_DELAY_MS 
        );
        setTimeout( () => initWorkflowBuilder( attempt + 1 ), delay );
    } else {
        console.error( 'Workflow Builder: Failed to find container element after multiple attempts' );
    }
};
```

**Retry parameters:**
- `MAX_INIT_ATTEMPTS`: 10 attempts
- `INITIAL_DELAY_MS`: 50ms first delay
- `BACKOFF_MULTIPLIER`: 1.5x increase per attempt
- `MAX_DELAY_MS`: 500ms maximum delay

**Retry schedule:**
1. 50ms
2. 75ms
3. 112ms
4. 168ms
5. 252ms
6. 378ms
7. 500ms (capped)
8. 500ms (capped)
9. 500ms (capped)
10. Error logged

### 3. Clear Error Logging
If initialization fails after all attempts, a clear error is logged to the console to help with debugging.

## Code Changes

**File:** `src/workflow-builder/index.jsx`

### Before
```javascript
const initWorkflowBuilder = () => {
	const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
	
	if ( container ) {
		const root = createRoot( container );
		root.render( <WorkflowBuilder /> );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initWorkflowBuilder );
} else {
	initWorkflowBuilder();
}
```

### After
```javascript
// Constants for retry logic
const MAX_INIT_ATTEMPTS = 10;
const INITIAL_DELAY_MS = 50;
const BACKOFF_MULTIPLIER = 1.5;
const MAX_DELAY_MS = 500;

const initWorkflowBuilder = ( attempt = 1 ) => {
	const container = document.getElementById( 'mcp-ai-pro-workflow-builder-root' );
	
	if ( container ) {
		const root = createRoot( container );
		root.render( <WorkflowBuilder /> );
	} else if ( attempt < MAX_INIT_ATTEMPTS ) {
		const delay = Math.min( INITIAL_DELAY_MS * Math.pow( BACKOFF_MULTIPLIER, attempt - 1 ), MAX_DELAY_MS );
		setTimeout( () => initWorkflowBuilder( attempt + 1 ), delay );
	} else {
		console.error( 'Workflow Builder: Failed to find container element after multiple attempts' );
	}
};

const startInit = () => {
	requestAnimationFrame( initWorkflowBuilder );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', startInit );
} else {
	startInit();
}
```

## Files Modified

1. **`src/workflow-builder/index.jsx`** - React initialization logic
2. **`addons/pro/build/workflow-builder/workflow-builder.js`** - Rebuilt JavaScript bundle (182 KB)

## Testing

### Build Verification
```bash
npm run build:workflow
# Output: webpack 5.105.0 compiled successfully in 3056 ms
```

### Code Verification
- ✅ `requestAnimationFrame` present in built file
- ✅ Error message present in built file
- ✅ Constants correctly defined
- ✅ Retry logic implemented

### Code Review
All feedback addressed:
- ✅ Removed unnecessary `typeof` check for `requestAnimationFrame`
- ✅ Simplified to use only `requestAnimationFrame` (no nested setTimeout)
- ✅ Extracted magic numbers into named constants

## Benefits

1. **Reliability**: Works across different browser timing scenarios
2. **Resilience**: Retry mechanism handles delayed DOM element availability
3. **Performance**: Exponential backoff prevents excessive CPU usage
4. **Maintainability**: Named constants make timing parameters easy to adjust
5. **Debuggability**: Clear error logging aids troubleshooting

## Browser Compatibility

- **`requestAnimationFrame`**: Supported in all modern browsers (Chrome 10+, Firefox 4+, Safari 6+, Edge 12+)
- **`setTimeout`**: Universal support
- **`document.readyState`**: Universal support

This fix is compatible with all browsers that support React 18's `createRoot` API.

## Deployment Notes

1. **No PHP changes**: Only JavaScript changes, no WordPress hooks affected
2. **No database changes**: No schema or data modifications
3. **Assets updated**: New `workflow-builder.js` must be deployed
4. **Cache clearing**: Clear WordPress cache and browser cache after deployment

## Prevention

This pattern should be used for all future React initialization code in WordPress plugins:

```javascript
// Template for robust React initialization
const startInit = () => {
    requestAnimationFrame( () => {
        const container = document.getElementById( 'your-container-id' );
        if ( container ) {
            const root = createRoot( container );
            root.render( <YourComponent /> );
        }
    } );
};

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', startInit );
} else {
    startInit();
}
```

## Related Issues

- Issue #3570: Fix Pro Workflow Builder React initialization timing
- Previous fix: Pro Workflow Builder double instantiation (2026-02-04)
- Previous fix: Pro Workflow Builder asset loading (2026-02-04)

## References

- [React 18 createRoot API](https://react.dev/reference/react-dom/client/createRoot)
- [MDN: requestAnimationFrame](https://developer.mozilla.org/en-US/docs/Web/API/window/requestAnimationFrame)
- [MDN: Document.readyState](https://developer.mozilla.org/en-US/docs/Web/API/Document/readyState)
- [WordPress: wp_enqueue_script](https://developer.wordpress.org/reference/functions/wp_enqueue_script/)
