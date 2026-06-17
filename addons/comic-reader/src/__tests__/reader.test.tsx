/**
 * NV oOS Comic Reader — Component Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PageViewer } from '../components/PageViewer';

describe('PageViewer', () => {
	const mockPage = {
		index: 0,
		name: 'page_001.jpg',
		url: 'blob:test-url',
	};

	it('renders empty state when no pages provided', () => {
		render(
			<PageViewer
				leftPage={null}
				rightPage={null}
				zoomLevel={1}
				fitMode="width"
				direction="ltr"
			/>
		);
		expect(screen.getByText('No pages to display.')).toBeInTheDocument();
	});

	it('renders a single page', () => {
		render(
			<PageViewer
				leftPage={mockPage}
				rightPage={null}
				zoomLevel={1}
				fitMode="width"
				direction="ltr"
			/>
		);
		const img = screen.getByAltText('Page 1');
		expect(img).toBeInTheDocument();
		expect(img).toHaveAttribute('src', 'blob:test-url');
	});

	it('renders a double-page spread', () => {
		const rightPage = { ...mockPage, index: 1, name: 'page_002.jpg' };
		render(
			<PageViewer
				leftPage={mockPage}
				rightPage={rightPage}
				zoomLevel={1}
				fitMode="width"
				direction="ltr"
			/>
		);
		expect(screen.getByAltText('Page 1')).toBeInTheDocument();
		expect(screen.getByAltText('Page 2')).toBeInTheDocument();
	});

	it('applies zoom transform style', () => {
		render(
			<PageViewer
				leftPage={mockPage}
				rightPage={null}
				zoomLevel={1.5}
				fitMode="none"
				direction="ltr"
			/>
		);
		const img = screen.getByAltText('Page 1');
		expect(img.style.transform).toBe('scale(1.5)');
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
