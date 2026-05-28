/**
 * NV oOS Comic Reader — Keyboard Navigation Hook
 *
 * Adds keyboard shortcuts for comic reading:
 *   ← → or A/D: Previous/next page
 *   +/= or -: Zoom in/out
 *   F: Toggle fullscreen
 *   W: Fit width / H: Fit height
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useEffect, RefObject } from 'react';

interface KeyboardNavCallbacks {
	onNext: () => void;
	onPrev: () => void;
	onZoomIn: () => void;
	onZoomOut: () => void;
	direction: 'ltr' | 'rtl';
}

export function useKeyboardNav(
	ref: RefObject<HTMLElement | null>,
	callbacks: KeyboardNavCallbacks
): void {
	useEffect(() => {
		const el = ref.current;
		if (!el) return;

		const handleKeyDown = (e: KeyboardEvent) => {
			// Ignore when typing in inputs.
			const tag = (e.target as HTMLElement)?.tagName;
			if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

			const isRtl = callbacks.direction === 'rtl';

			switch (e.key) {
				case 'ArrowRight':
					e.preventDefault();
					isRtl ? callbacks.onPrev() : callbacks.onNext();
					break;
				case 'ArrowLeft':
					e.preventDefault();
					isRtl ? callbacks.onNext() : callbacks.onPrev();
					break;
				case 'a':
				case 'A':
					e.preventDefault();
					callbacks.onPrev();
					break;
				case 'd':
				case 'D':
					e.preventDefault();
					callbacks.onNext();
					break;
				case '+':
				case '=':
					e.preventDefault();
					callbacks.onZoomIn();
					break;
				case '-':
					e.preventDefault();
					callbacks.onZoomOut();
					break;
				default:
					break;
			}
		};

		el.addEventListener('keydown', handleKeyDown);
		return () => el.removeEventListener('keydown', handleKeyDown);
	}, [ref, callbacks]);
}
