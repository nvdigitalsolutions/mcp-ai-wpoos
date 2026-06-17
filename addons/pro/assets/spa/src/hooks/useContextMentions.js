/**
 * useContextMentions — Hook for @-mention autocomplete.
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import { apiGet } from '../services/api';

export function useContextMentions() {
	const [query, setQuery] = useState('');
	const [results, setResults] = useState({});
	const [visible, setVisible] = useState(false);
	const [selectedIndex, setSelectedIndex] = useState(0);
	const timeoutRef = useRef(null);

	const search = useCallback(async (q) => {
		setQuery(q);
		if (!q || q.length < 1) {
			setResults({});
			setVisible(false);
			return;
		}

		// Debounce.
		if (timeoutRef.current) clearTimeout(timeoutRef.current);
		timeoutRef.current = setTimeout(async () => {
			try {
				const res = await apiGet('/mcp-ai/v1/context/suggest', { q, limit: 10 });
				if (res.success) {
					setResults(res.data);
					setVisible(Object.keys(res.data).length > 0);
					setSelectedIndex(0);
				}
			} catch {
				setResults({});
				setVisible(false);
			}
		}, 150);
	}, []);

	const close = useCallback(() => {
		setVisible(false);
		setQuery('');
		setResults({});
	}, []);

	const selectNext = useCallback(() => {
		const flat = flattenResults(results);
		setSelectedIndex((i) => Math.min(i + 1, flat.length - 1));
	}, [results]);

	const selectPrev = useCallback(() => {
		setSelectedIndex((i) => Math.max(i - 1, 0));
	}, []);

	const getSelected = useCallback(() => {
		const flat = flattenResults(results);
		return flat[selectedIndex] || null;
	}, [results, selectedIndex]);

	return {
		query,
		results,
		visible,
		selectedIndex,
		search,
		close,
		selectNext,
		selectPrev,
		getSelected,
	};
}

function flattenResults(results) {
	const flat = [];
	Object.values(results).forEach((group) => {
		if (group.items) flat.push(...group.items);
	});
	return flat;
}
