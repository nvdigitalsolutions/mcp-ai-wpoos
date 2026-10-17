import '@testing-library/jest-dom';

// ── jsdom polyfills (Proposal 080 + spa-ui skill guidance) ─────────────────

// ResizeObserver — used by useAutoGrowTextarea and Radix primitives.
// jsdom does not implement it; a recording no-op keeps layout observers safe.
if ( typeof globalThis.ResizeObserver === 'undefined' ) {
	class ResizeObserverMock {
		observe(): void {}
		unobserve(): void {}
		disconnect(): void {}
	}
	globalThis.ResizeObserver =
		ResizeObserverMock as unknown as typeof ResizeObserver;
}

// Pointer-capture API — used by ResizableComposerHandle drags.
// jsdom lacks it; no-op implementations keep optional-chained calls safe.
if (
	typeof Element !== 'undefined' &&
	typeof ( Element.prototype as unknown as {
		setPointerCapture?: unknown;
	} ).setPointerCapture !== 'function'
) {
	Object.defineProperty( Element.prototype, 'setPointerCapture', {
		configurable: true,
		writable: true,
		value: () => {},
	} );
	Object.defineProperty( Element.prototype, 'releasePointerCapture', {
		configurable: true,
		writable: true,
		value: () => {},
	} );
	Object.defineProperty( Element.prototype, 'hasPointerCapture', {
		configurable: true,
		writable: true,
		value: () => false,
	} );
}
