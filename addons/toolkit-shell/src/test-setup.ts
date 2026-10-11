import '@testing-library/jest-dom';

/**
 * jsdom polyfills required by Radix primitives and dnd-kit:
 *
 * - ResizeObserver (Popper-based positioning in Select/Dropdown/Dialog)
 * - scrollIntoView (Radix focus management)
 * - pointer-capture stubs (Radix pointer handling)
 * - matchMedia (used by some Radix/Sonner internals)
 */

class ResizeObserverStub {
	observe(): void {}
	unobserve(): void {}
	disconnect(): void {}
}

if ( typeof window.ResizeObserver === 'undefined' ) {
	( window as unknown as { ResizeObserver: typeof ResizeObserverStub } ).ResizeObserver =
		ResizeObserverStub;
}

if ( ! Element.prototype.scrollIntoView ) {
	Element.prototype.scrollIntoView = () => {};
}
if ( ! Element.prototype.hasPointerCapture ) {
	Element.prototype.hasPointerCapture = () => false;
}
if ( ! Element.prototype.setPointerCapture ) {
	Element.prototype.setPointerCapture = () => {};
}
if ( ! Element.prototype.releasePointerCapture ) {
	Element.prototype.releasePointerCapture = () => {};
}

if ( typeof window.matchMedia === 'undefined' ) {
	window.matchMedia = ( query: string ): MediaQueryList =>
		( {
			matches: false,
			media: query,
			onchange: null,
			addListener: () => {},
			removeListener: () => {},
			addEventListener: () => {},
			removeEventListener: () => {},
			dispatchEvent: () => false,
		} ) as MediaQueryList;
}
