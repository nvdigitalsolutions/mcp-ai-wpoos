/**
 * useZoomPan — manages zoom level and stage offset for canvas navigation.
 *
 * Handles mouse-wheel zoom (centered on cursor) and drag-to-pan
 * when in pan mode or when Ctrl is held.
 *
 * @since 0.3.0
 */

import { useCallback, useState } from 'react';

export interface ZoomPanState {
	scale: number;
	offsetX: number;
	offsetY: number;
}

const MIN_SCALE = 0.1;
const MAX_SCALE = 5;
const ZOOM_STEP = 0.1;

export interface ZoomPanAPI {
	/** Current zoom/pan state. */
	zoomPan: ZoomPanState;
	/** Reset to default (scale=1, offset=0). */
	reset: () => void;
	/** Fit the image to the available viewport. */
	fitToView: ( stageWidth: number, stageHeight: number, imageWidth: number, imageHeight: number ) => void;
	/** Handle mouse wheel zoom (centered on cursor position). */
	handleWheel: ( stage: any, e: any ) => void;
	/** Check if stage is pannable (shift held, or pan tool active). */
	isPanKey: ( e: { evt: MouseEvent | TouchEvent } ) => boolean;
	/** Toggle zoom level. */
	setScale: ( scale: number ) => void;
}

const DEFAULT: ZoomPanState = { scale: 1, offsetX: 0, offsetY: 0 };

export function useZoomPan(): ZoomPanAPI {
	const [ zoomPan, setZoomPan ] = useState<ZoomPanState>( DEFAULT );

	const reset = useCallback( () => setZoomPan( DEFAULT ), [] );

	const fitToView = useCallback(
		( stageWidth: number, stageHeight: number, imageWidth: number, imageHeight: number ) => {
			if ( ! imageWidth || ! imageHeight ) {
				return;
			}
			const scaleX = stageWidth / imageWidth;
			const scaleY = stageHeight / imageHeight;
			const scale = Math.min( scaleX, scaleY, 1 );
			setZoomPan( {
				scale,
				offsetX: ( stageWidth - imageWidth * scale ) / 2,
				offsetY: ( stageHeight - imageHeight * scale ) / 2,
			} );
		},
		[],
	);

	const handleWheel = useCallback( ( stage: any, e: any ) => {
		e.evt.preventDefault();
		const oldScale = zoomPan.scale;
		const pointer = stage.getPointerPosition();
		if ( ! pointer ) {
			return;
		}

		const direction = e.evt.deltaY > 0 ? -1 : 1;
		let newScale = oldScale + direction * ZOOM_STEP * oldScale;
		newScale = Math.max( MIN_SCALE, Math.min( MAX_SCALE, newScale ) );

		const mousePointTo = {
			x: ( pointer.x - zoomPan.offsetX ) / oldScale,
			y: ( pointer.y - zoomPan.offsetY ) / oldScale,
		};

		setZoomPan( {
			scale: newScale,
			offsetX: pointer.x - mousePointTo.x * newScale,
			offsetY: pointer.y - mousePointTo.y * newScale,
		} );
	}, [ zoomPan ] );

	const isPanKey = useCallback( ( e: { evt: MouseEvent | TouchEvent } ) => {
		const evt = e.evt as MouseEvent;
		return evt.shiftKey || evt.ctrlKey || evt.metaKey;
	}, [] );

	const setScale = useCallback( ( scale: number ) => {
		setZoomPan( ( prev ) => ( { ...prev, scale: Math.max( MIN_SCALE, Math.min( MAX_SCALE, scale ) ) } ) );
	}, [] );

	return { zoomPan, reset, fitToView, handleWheel, isPanKey, setScale };
}
