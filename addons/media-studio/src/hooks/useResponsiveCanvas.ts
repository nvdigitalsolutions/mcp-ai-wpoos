/**
 * useResponsiveCanvas — observes container size for Konva Stage dimensions.
 *
 * Uses ResizeObserver to track the parent element's width/height, returning
 * dimensions suitable for a responsive Konva Stage.
 *
 * @since 0.2.0
 */

import { useEffect, useRef, useState } from 'react';

interface CanvasSize {
	width: number;
	height: number;
}

const DEFAULT_SIZE: CanvasSize = { width: 800, height: 500 };
const MIN_WIDTH = 300;
const MIN_HEIGHT = 200;

/**
 * useResponsiveCanvas
 *
 * @param containerRef Ref to the wrapper element whose size is observed.
 * @param toolbarHeight Additional height to subtract (toolbar).
 * @returns Current width and height.
 */
export function useResponsiveCanvas(
	containerRef: React.RefObject<HTMLElement | null>,
	toolbarHeight: number = 0,
): CanvasSize {
	const [ size, setSize ] = useState<CanvasSize>( DEFAULT_SIZE );
	const frameRef = useRef<number>( 0 );

	useEffect( () => {
		const el = containerRef.current;
		if ( ! el ) {
			return;
		}

		const measure = () => {
			const rect = el.getBoundingClientRect();
			const w = Math.max( MIN_WIDTH, Math.floor( rect.width ) );
			const h = Math.max( MIN_HEIGHT, Math.floor( rect.height - toolbarHeight ) );
			setSize( { width: w, height: h } );
		};

		// Debounce with rAF.
		const debounced = () => {
			if ( frameRef.current ) {
				cancelAnimationFrame( frameRef.current );
			}
			frameRef.current = requestAnimationFrame( measure );
		};

		const observer = new ResizeObserver( debounced );
		observer.observe( el );
		measure(); // initial measurement

		return () => {
			observer.disconnect();
			if ( frameRef.current ) {
				cancelAnimationFrame( frameRef.current );
			}
		};
	}, [ containerRef, toolbarHeight ] );

	return size;
}
