/**
 * NV oOS Comic Reader — Reader State Hook
 *
 * Manages current page, zoom level, scale (fit) mode, double-page spread
 * toggle, and fullscreen state for the comic reader.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback, useEffect } from 'react';
import type { ScaleType } from '../types/reader-prefs';

interface ReaderStateOptions {
	total: number;
	initialScale: ScaleType;
	initialDoublePage: boolean;
}

interface ReaderState {
	currentPage: number;
	totalPages: number;
	zoomLevel: number;
	scale: ScaleType;
	isDoublePage: boolean;
	isFullscreen: boolean;
	goToPage: (page: number) => void;
	nextPage: (step?: number) => void;
	prevPage: (step?: number) => void;
	zoomIn: () => void;
	zoomOut: () => void;
	setScale: (mode: ScaleType) => void;
	toggleDoublePage: () => void;
	setDoublePage: (value: boolean) => void;
	toggleFullscreen: () => void;
}

const MIN_ZOOM = 0.25;
const MAX_ZOOM = 4.0;
const ZOOM_STEP = 0.1;

export function useReaderState({
	total,
	initialScale,
	initialDoublePage,
}: ReaderStateOptions): ReaderState {
	const [currentPage, setCurrentPage] = useState(1);
	const [zoomLevel, setZoomLevel] = useState(1);
	const [scale, setScaleState] = useState<ScaleType>(initialScale);
	const [isDoublePage, setIsDoublePage] = useState(initialDoublePage);
	const [isFullscreen, setIsFullscreen] = useState(false);

	const totalPages = Math.max(total, 1);

	const goToPage = useCallback(
		(page: number) => {
			setCurrentPage(Math.max(1, Math.min(page, totalPages)));
		},
		[totalPages]
	);

	const nextPage = useCallback(
		(step = 1) => {
			setCurrentPage((p) => Math.min(p + Math.max(1, step), totalPages));
		},
		[totalPages]
	);

	const prevPage = useCallback((step = 1) => {
		setCurrentPage((p) => Math.max(p - Math.max(1, step), 1));
	}, []);

	const zoomIn = useCallback(() => {
		setScaleState('none');
		setZoomLevel((z) => Math.min(z + ZOOM_STEP, MAX_ZOOM));
	}, []);

	const zoomOut = useCallback(() => {
		setScaleState('none');
		setZoomLevel((z) => Math.max(z - ZOOM_STEP, MIN_ZOOM));
	}, []);

	const setScale = useCallback((mode: ScaleType) => {
		setScaleState(mode);
		if (mode !== 'none') {
			setZoomLevel(1);
		}
	}, []);

	const toggleDoublePage = useCallback(() => {
		setIsDoublePage((d) => !d);
	}, []);

	const setDoublePage = useCallback((value: boolean) => {
		setIsDoublePage(value);
	}, []);

	const toggleFullscreen = useCallback(() => {
		setIsFullscreen((f) => {
			if (!f) {
				document.documentElement.requestFullscreen?.().catch(() => {});
			} else {
				document.exitFullscreen?.().catch(() => {});
			}
			return !f;
		});
	}, []);

	// Sync state when the user exits fullscreen via Escape or browser controls.
	useEffect(() => {
		const handleFullscreenChange = () => {
			if (!document.fullscreenElement) {
				setIsFullscreen(false);
			}
		};
		document.addEventListener('fullscreenchange', handleFullscreenChange);
		return () =>
			document.removeEventListener('fullscreenchange', handleFullscreenChange);
	}, []);

	return {
		currentPage,
		totalPages,
		zoomLevel,
		scale,
		isDoublePage,
		isFullscreen,
		goToPage,
		nextPage,
		prevPage,
		zoomIn,
		zoomOut,
		setScale,
		toggleDoublePage,
		setDoublePage,
		toggleFullscreen,
	};
}
