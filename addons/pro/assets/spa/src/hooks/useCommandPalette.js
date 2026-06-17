import { useState, useCallback, useEffect } from '@wordpress/element';

export function useCommandPalette() {
	const [isOpen, setIsOpen] = useState(false);

	const open = useCallback(() => setIsOpen(true), []);
	const close = useCallback(() => setIsOpen(false), []);
	const toggle = useCallback(() => setIsOpen((v) => !v), []);

	// Global keyboard shortcut: Cmd+K / Ctrl+K
	useEffect(() => {
		const handler = (e) => {
			if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
				e.preventDefault();
				toggle();
			}
			if (e.key === 'Escape' && isOpen) {
				close();
			}
		};
		document.addEventListener('keydown', handler);
		return () => document.removeEventListener('keydown', handler);
	}, [isOpen, toggle, close]);

	return { isOpen, open, close, toggle };
}
