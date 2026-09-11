/**
 * NV oOS Comic Reader — Touch Gestures Hook
 *
 * Pointer-event based gestures for the paged reader:
 *   - Swipe left/right (direction-aware) to change pages
 *   - Two-finger pinch to zoom in/out
 *   - Tap zones: left third = previous, right third = next, center = toggle UI
 *
 * Disabled when `enabled` is false (Komga's gestures toggle).
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

import { useEffect, RefObject, useRef } from 'react';
import type { ReadingDirection } from '../types/reader-prefs';

interface TouchGesturesCallbacks {
	onNext: () => void;
	onPrev: () => void;
	onZoomIn: () => void;
	onZoomOut: () => void;
	onTapCenter?: () => void;
	enabled: boolean;
	direction: ReadingDirection;
}

const SWIPE_THRESHOLD = 60;
const TAP_MOVE_THRESHOLD = 12;
const PINCH_THRESHOLD = 0.2; // 20% distance change per zoom step.

interface PointerState {
	x: number;
	y: number;
}

export function useTouchGestures(
	ref: RefObject<HTMLElement | null>,
	callbacks: TouchGesturesCallbacks
): void {
	const callbacksRef = useRef(callbacks);
	callbacksRef.current = callbacks;

	useEffect(() => {
		const el = ref.current;
		if (!el) return;

		const pointers = new Map<number, PointerState>();

		const distance = (a: PointerState, b: PointerState): number =>
			Math.hypot(a.x - b.x, a.y - b.y);

		const handlePointerDown = (e: PointerEvent) => {
			if (!callbacksRef.current.enabled) return;
			el.setPointerCapture?.(e.pointerId);
			pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
		};

		const handlePointerMove = (e: PointerEvent) => {
			if (!callbacksRef.current.enabled) return;
			const state = pointers.get(e.pointerId);
			if (!state) return;

			// Pinch zoom with two active pointers.
			if (pointers.size === 2) {
				const [first, second] = Array.from(pointers.values());
				const before = distance(first, second);
				const after = distance(
					e.pointerId === Array.from(pointers.keys())[0]
						? { x: e.clientX, y: e.clientY }
						: first,
					e.pointerId === Array.from(pointers.keys())[1]
						? { x: e.clientX, y: e.clientY }
						: second
				);
				const ratio = before > 0 ? after / before : 1;
				if (ratio > 1 + PINCH_THRESHOLD) {
					callbacksRef.current.onZoomIn();
				} else if (ratio < 1 - PINCH_THRESHOLD) {
					callbacksRef.current.onZoomOut();
				}
			}

			pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
		};

		const handlePointerUp = (e: PointerEvent) => {
			if (!callbacksRef.current.enabled) return;
			const start = pointers.get(e.pointerId);
			pointers.delete(e.pointerId);
			if (!start) return;

			const dx = e.clientX - start.x;
			const dy = e.clientY - start.y;
			const horizontal = Math.abs(dx) > Math.abs(dy);

			if (Math.abs(dx) < TAP_MOVE_THRESHOLD && Math.abs(dy) < TAP_MOVE_THRESHOLD) {
				// Tap zone: left third = previous, right third = next.
				const bounds = el.getBoundingClientRect();
				const relativeX = (e.clientX - bounds.left) / Math.max(1, bounds.width);
				const isRtl = callbacksRef.current.direction === 'rtl';

				if (relativeX < 0.33) {
					isRtl
						? callbacksRef.current.onNext()
						: callbacksRef.current.onPrev();
				} else if (relativeX > 0.67) {
					isRtl
						? callbacksRef.current.onPrev()
						: callbacksRef.current.onNext();
				} else {
					callbacksRef.current.onTapCenter?.();
				}
				return;
			}

			if (horizontal && Math.abs(dx) >= SWIPE_THRESHOLD) {
				const isRtl = callbacksRef.current.direction === 'rtl';
				const forward = isRtl ? dx > 0 : dx < 0;
				if (forward) {
					callbacksRef.current.onNext();
				} else {
					callbacksRef.current.onPrev();
				}
			}
		};

		const handlePointerCancel = (e: PointerEvent) => {
			pointers.delete(e.pointerId);
		};

		el.addEventListener('pointerdown', handlePointerDown);
		el.addEventListener('pointermove', handlePointerMove);
		el.addEventListener('pointerup', handlePointerUp);
		el.addEventListener('pointercancel', handlePointerCancel);

		return () => {
			el.removeEventListener('pointerdown', handlePointerDown);
			el.removeEventListener('pointermove', handlePointerMove);
			el.removeEventListener('pointerup', handlePointerUp);
			el.removeEventListener('pointercancel', handlePointerCancel);
		};
	}, [ref]);
}
