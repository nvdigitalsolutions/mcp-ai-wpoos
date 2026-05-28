/**
 * NV oOS Comic Reader — Entry Point
 *
 * Mounts the React app onto `.nvoos-comic-reader-root` containers found in the DOM.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

import { createRoot, Root } from 'react-dom/client';
import { App } from './App';
import './styles/main.css';

interface ComicReaderConfig {
	comicId: number;
	mode: 'library' | 'reader';
	height: string;
	direction: 'ltr' | 'rtl';
}

interface ComicReaderGlobal {
	apiUrl: string;
	nonce: string;
	config: ComicReaderConfig;
	i18n: Record<string, string>;
}

declare global {
	interface Window {
		NVOOS_COMIC_READER: ComicReaderGlobal;
	}
}

const roots = new Map<Element, Root>();

function mountAll(): void {
	const containers = document.querySelectorAll('.nvoos-comic-reader-root');
	containers.forEach((container) => {
		if (roots.has(container)) return;

		const configStr = container.getAttribute('data-config');
		let config: ComicReaderConfig = {
			comicId: 0,
			mode: 'library',
			height: '',
			direction: 'ltr',
		};

		try {
			if (configStr) {
				config = JSON.parse(configStr);
			}
		} catch {
			// Use defaults.
		}

		const root = createRoot(container);
		roots.set(container, root);
		root.render(<App initialConfig={config} />);
	});
}

// Mount on DOM ready.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountAll);
} else {
	mountAll();
}

// Support dynamic mounting (Elementor, AJAX page loads).
if (typeof window !== 'undefined' && window.MutationObserver) {
	const observer = new MutationObserver(() => mountAll());
	observer.observe(document.body, { childList: true, subtree: true });
}
