/**
 * NV oOS Comic Reader — Component Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { PageViewer } from '../components/PageViewer';
import {
	loadPrefs,
	savePrefs,
	DEFAULT_PREFS,
	backgroundToColor,
} from '../types/reader-prefs';
import {
	saveLocalProgress,
	readLocalProgress,
	readAllLocalProgress,
	progressPercent,
} from '../types/progress';
import { HelpDialog } from '../components/HelpDialog';
import { ReaderSettings } from '../components/ReaderSettings';

const mockPage = {
	index: 0,
	name: 'page_001.jpg',
	url: 'blob:test-url',
};

const baseProps = {
	leftPage: mockPage,
	rightPage: null,
	zoomLevel: 1,
	scale: 'fit-width' as const,
	direction: 'ltr' as const,
	background: 'gray' as const,
	transition: 'none' as const,
	spreadKey: '1-s-ltr',
};

describe('PageViewer', () => {
	it('renders empty state when no pages provided', () => {
		render(<PageViewer {...baseProps} leftPage={null} />);
		expect(screen.getByText('No pages to display.')).toBeInTheDocument();
	});

	it('renders a single page', () => {
		render(<PageViewer {...baseProps} />);
		const img = screen.getByAltText('Page 1');
		expect(img).toBeInTheDocument();
		expect(img).toHaveAttribute('src', 'blob:test-url');
	});

	it('renders a double-page spread', () => {
		const rightPage = { ...mockPage, index: 1, name: 'page_002.jpg' };
		render(<PageViewer {...baseProps} rightPage={rightPage} />);
		expect(screen.getByAltText('Page 1')).toBeInTheDocument();
		expect(screen.getByAltText('Page 2')).toBeInTheDocument();
	});

	it('applies zoom transform style when scale is none', () => {
		render(<PageViewer {...baseProps} scale="none" zoomLevel={1.5} />);
		const img = screen.getByAltText('Page 1');
		expect(img.style.transform).toBe('scale(1.5)');
	});

	it('reports natural page dimensions on load', () => {
		const onPageLoad = vi.fn();
		render(<PageViewer {...baseProps} onPageLoad={onPageLoad} />);
		const img = screen.getByAltText('Page 1');
		Object.defineProperty(img, 'naturalWidth', { value: 200 });
		Object.defineProperty(img, 'naturalHeight', { value: 300 });
		fireEvent.load(img);
		expect(onPageLoad).toHaveBeenCalledWith(0, 200, 300);
	});
});

describe('reader prefs', () => {
	beforeEach(() => {
		localStorage.clear();
	});

	it('returns defaults when nothing is stored', () => {
		expect(loadPrefs()).toEqual(DEFAULT_PREFS);
	});

	it('persists and reloads global prefs', () => {
		savePrefs({ ...DEFAULT_PREFS, direction: 'rtl', background: 'black' });
		const loaded = loadPrefs();
		expect(loaded.direction).toBe('rtl');
		expect(loaded.background).toBe('black');
		expect(loaded.scale).toBe('fit-width');
	});

	it('applies per-comic overrides over global prefs', () => {
		savePrefs({ ...DEFAULT_PREFS, background: 'black' });
		savePrefs({ ...DEFAULT_PREFS, background: 'white' }, 42);
		expect(loadPrefs(42).background).toBe('white');
		expect(loadPrefs(7).background).toBe('black');
	});

	it('maps backgrounds to colors', () => {
		expect(backgroundToColor('white')).toBe('#f5f5f5');
		expect(backgroundToColor('gray')).toBe('#333333');
		expect(backgroundToColor('black')).toBe('#000000');
	});
});

describe('reading progress', () => {
	beforeEach(() => {
		localStorage.clear();
	});

	it('round-trips a progress record', () => {
		saveLocalProgress(42, { page: 12, total: 60, completed: false, ts: 123 });
		expect(readLocalProgress(42)).toEqual({
			page: 12,
			total: 60,
			completed: false,
			ts: 123,
		});
	});

	it('reads the legacy bare-page format', () => {
		localStorage.setItem('nvoos_cr_progress_9', '7');
		expect(readLocalProgress(9)?.page).toBe(7);
	});

	it('scans all stored records', () => {
		saveLocalProgress(1, { page: 2, total: 10, completed: false, ts: 1 });
		saveLocalProgress(2, { page: 10, total: 10, completed: true, ts: 2 });
		const all = readAllLocalProgress();
		expect(all.size).toBe(2);
		expect(all.get(2)?.completed).toBe(true);
	});

	it('computes read percentages', () => {
		expect(progressPercent({ page: 5, total: 10, completed: false, ts: 0 })).toBe(50);
		expect(progressPercent({ page: 10, total: 10, completed: true, ts: 0 })).toBe(100);
		expect(progressPercent({ page: 3, total: 0, completed: false, ts: 0 })).toBe(0);
	});
});

describe('HelpDialog', () => {
	it('renders shortcut rows', () => {
		render(<HelpDialog onClose={vi.fn()} />);
		expect(screen.getByText('Fit width')).toBeInTheDocument();
		expect(screen.getByText('Double page')).toBeInTheDocument();
	});
});

describe('ReaderSettings', () => {
	it('updates prefs when the reading mode changes', () => {
		const onChange = vi.fn();
		render(
			<ReaderSettings
				prefs={DEFAULT_PREFS}
				onChange={onChange}
				onClose={vi.fn()}
			/>
		);
		fireEvent.change(screen.getByLabelText('Reading mode'), {
			target: { value: 'webtoon' },
		});
		expect(onChange).toHaveBeenCalledWith(
			expect.objectContaining({ readingMode: 'webtoon' })
		);
	});
});

describe('formatFileSize', () => {
	it('formats file sizes correctly', async () => {
		const { formatFileSize } = await import('../api/comic-api');
		expect(formatFileSize(0)).toBe('0 B');
		expect(formatFileSize(1024)).toBe('1.0 KB');
		expect(formatFileSize(1048576)).toBe('1.0 MB');
		expect(formatFileSize(1073741824)).toBe('1.0 GB');
	});
});
