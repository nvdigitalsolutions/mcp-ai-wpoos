/**
 * NV oOS Comic Reader — Keyboard Navigation Hook
 *
 * Adds keyboard shortcuts for comic reading:
 *   ← → (direction-aware) or A/D: Previous/next page
 *   +/= or -: Zoom in/out
 *   F: Toggle fullscreen
 *   W: Fit width / H: Fit height
 *   P: Toggle double-page spread
 *   R: Toggle reading direction
 *   G: Thumbnails overview
 *   ?: Keyboard help dialog
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
	onFitWidth?: () => void;
	onFitHeight?: () => void;
	onFullscreen?: () => void;
	onToggleDoublePage?: () => void;
	onToggleDirection?: () => void;
	onThumbnails?: () => void;
	onHelp?: () => void;
	direction: 'ltr' | 'rtl';
	/** When false, arrow keys keep native scrolling (scroll/webtoon modes). */
	captureArrows?: boolean;
}

export function useKeyboardNav(
	ref: RefObject<HTMLElement | null>,
	callbacks: KeyboardNavCallbacks
): void {
	useEffect(() => {
		const el = ref.current;
		if (!el) return;

		const handleKeyDown = (e: KeyboardEvent) => {
			// Ignore when typing in inputs or when a modifier is held.
			const tag = (e.target as HTMLElement)?.tagName;
			if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
			if (e.ctrlKey || e.metaKey || e.altKey) return;

			const isRtl = callbacks.direction === 'rtl';
			const captureArrows = callbacks.captureArrows !== false;

			switch (e.key) {
				case 'ArrowRight':
					if (!captureArrows) return;
					e.preventDefault();
					isRtl ? callbacks.onPrev() : callbacks.onNext();
					break;
				case 'ArrowLeft':
					if (!captureArrows) return;
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
				case 'f':
				case 'F':
					e.preventDefault();
					callbacks.onFullscreen?.();
					break;
				case 'w':
				case 'W':
					e.preventDefault();
					callbacks.onFitWidth?.();
					break;
				case 'h':
				case 'H':
					e.preventDefault();
					callbacks.onFitHeight?.();
					break;
				case 'p':
				case 'P':
					e.preventDefault();
					callbacks.onToggleDoublePage?.();
					break;
				case 'g':
				case 'G':
					e.preventDefault();
					callbacks.onThumbnails?.();
					break;
				case 'r':
				case 'R':
					e.preventDefault();
					callbacks.onToggleDirection?.();
					break;
				case '?':
					e.preventDefault();
					callbacks.onHelp?.();
					break;
				default:
					break;
			}
		};

		el.addEventListener('keydown', handleKeyDown);
		return () => el.removeEventListener('keydown', handleKeyDown);
	}, [ref, callbacks]);
}
