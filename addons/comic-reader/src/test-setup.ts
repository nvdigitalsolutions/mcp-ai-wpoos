/** @jsxImportSource react */
import '@testing-library/jest-dom/vitest';

/**
 * Provide a minimal `NVOOS_COMIC_READER` global for tests, mirroring the
 * localization object injected by the WordPress shortcode.
 */
beforeAll(() => {
	(window as unknown as { NVOOS_COMIC_READER: unknown }).NVOOS_COMIC_READER = {
		apiUrl: 'http://example.test/wp-json/nvoos-comic-reader/v1',
		nonce: 'test-nonce',
		config: { comicId: 0, mode: 'library', height: '', direction: 'ltr' },
		i18n: {
			pageAlt: 'Page %1$d',
			noPages: 'No pages to display.',
			pageOf: 'Page %1$d of %2$d',
			retry: 'Retry',
			loading: 'Loading comic…',
			noComics: 'No comics found in your library.',
		},
	};
});
