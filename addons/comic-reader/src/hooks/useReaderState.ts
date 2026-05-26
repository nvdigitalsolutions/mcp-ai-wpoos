/**
 * NV oOS Comic Reader — Reader State Hook
 *
 * Manages current page, zoom level, fit mode, double-page spread toggle,
 * and fullscreen state for the comic reader.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { useState, useCallback } from 'react';

interface ReaderStateOptions {
	total: number;
}

interface ReaderState {
	currentPage: number;
	totalPages: number;
	zoomLevel: number;
	fitMode: 'none' | 'width' | 'height';
	isDoublePage: boolean;
	isFullscreen: boolean;
	goToPage: (page: number) => void;
	nextPage: () => void;
	prevPage: () => void;
	zoomIn: () => void;
	zoomOut: () => void;
	setFitMode: (mode: 'none' | 'width' | 'height') => void;
	toggleDoublePage: () => void;
	toggleFullscreen: () => void;
}

const MIN_ZOOM = 0.25;
const MAX_ZOOM = 4.0;
const ZOOM_STEP = 0.1;

export function useReaderState({ total }: ReaderStateOptions): ReaderState {
	const [currentPage, setCurrentPage] = useState(1);
	const [zoomLevel, setZoomLevel] = useState(1);
	const [fitMode, setFitModeState] = useState<'none' | 'width' | 'height'>('width');
	const [isDoublePage, setIsDoublePage] = useState(false);
	const [isFullscreen, setIsFullscreen] = useState(false);

	const totalPages = Math.max(total, 1);

	const goToPage = useCallback(
		(page: number) => {
			setCurrentPage(Math.max(1, Math.min(page, totalPages)));
		},
		[totalPages]
	);

	const nextPage = useCallback(() => {
		setCurrentPage((p) => {
			const step = isDoublePage ? 2 : 1;
			return Math.min(p + step, totalPages);
		});
	}, [isDoublePage, totalPages]);

	const prevPage = useCallback(() => {
		setCurrentPage((p) => {
			const step = isDoublePage ? 2 : 1;
			return Math.max(p - step, 1);
		});
	}, [isDoublePage]);

	const zoomIn = useCallback(() => {
		setFitModeState('none');
		setZoomLevel((z) => Math.min(z + ZOOM_STEP, MAX_ZOOM));
	}, []);

	const zoomOut = useCallback(() => {
		setFitModeState('none');
		setZoomLevel((z) => Math.max(z - ZOOM_STEP, MIN_ZOOM));
	}, []);

	const setFitMode = useCallback((mode: 'none' | 'width' | 'height') => {
		setFitModeState(mode);
		if (mode !== 'none') {
			setZoomLevel(1);
		}
	}, []);

	const toggleDoublePage = useCallback(() => {
		setIsDoublePage((d) => !d);
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

	// Listen for fullscreen exit via Escape key or browser controls.
	if (typeof document !== 'undefined') {
		document.addEventListener('fullscreenchange', () => {
			if (!document.fullscreenElement) {
				setIsFullscreen(false);
			}
		});
	}

	return {
		currentPage,
		totalPages,
		zoomLevel,
		fitMode,
		isDoublePage,
		isFullscreen,
		goToPage,
		nextPage,
		prevPage,
		zoomIn,
		zoomOut,
		setFitMode,
		toggleDoublePage,
		toggleFullscreen,
	};
}
