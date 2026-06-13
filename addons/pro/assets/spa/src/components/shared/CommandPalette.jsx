/**
 * CommandPalette — Universal action launcher with fuzzy search.
 * Zed equivalent: Cmd+Shift+P
 */

import { useState, useMemo, useEffect, useRef } from '@wordpress/element';
import { useCommandPalette } from '../../hooks/useCommandPalette';
import { useCommandStore } from '../../store/commandStore';
import { fuzzySearch } from '../../utils/fuzzySearch';

export default function CommandPalette() {
	const { close } = useCommandPalette();
	const { commands } = useCommandStore();
	const [query, setQuery] = useState('');
	const [selectedIndex, setSelectedIndex] = useState(0);
	const inputRef = useRef(null);

	useEffect(() => {
		inputRef.current?.focus();
	}, []);

	const results = useMemo(() => {
		if (!query.trim()) {
			return commands.slice(0, 10);
		}
		return fuzzySearch(commands, query, { keys: ['label', 'keywords'] });
	}, [commands, query]);

	// Group results by category.
	const grouped = useMemo(() => {
		const map = {};
		results.forEach((r) => {
			const cat = r.category || 'Other';
			if (!map[cat]) map[cat] = [];
			map[cat].push(r);
		});
		return Object.entries(map);
	}, [results]);

	// Flatten for index navigation.
	const flat = results;

	const handleKeyDown = (e) => {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			setSelectedIndex((i) => Math.min(i + 1, flat.length - 1));
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			setSelectedIndex((i) => Math.max(i - 1, 0));
		} else if (e.key === 'Enter' && flat[selectedIndex]) {
			close();
			executeCommand(flat[selectedIndex]);
		} else if (e.key === 'Escape') {
			close();
		}
	};

	const executeCommand = (cmd) => {
		// Handler dispatch handled by the SPA router/store.
		if (cmd.handler?.startsWith('nvoos:nav.go') && cmd.meta?.route) {
			window.location.hash = cmd.meta.route;
		}
		// Future: dispatch to thread store, tool execution, etc.
	};

	return (
		<div className="nvoos-command-palette-overlay" onClick={close}>
			<div className="nvoos-command-palette" onClick={(e) => e.stopPropagation()} role="dialog">
				<input
					ref={inputRef}
					className="nvoos-command-palette__input"
					type="text"
					placeholder="Type a command…"
					value={query}
					onChange={(e) => { setQuery(e.target.value); setSelectedIndex(0); }}
					onKeyDown={handleKeyDown}
				/>
				<div className="nvoos-command-palette__results">
					{grouped.map(([category, items]) => (
						<div key={category} className="nvoos-command-palette__group">
							<div className="nvoos-command-palette__group-label">{category}</div>
							{items.map((item) => (
								<div
									key={item.id}
									className={`nvoos-command-palette__item ${flat.indexOf(item) === selectedIndex ? 'nvoos-command-palette__item--selected' : ''}`}
									onClick={() => { close(); executeCommand(item); }}
								>
									<span>{item.label}</span>
								</div>
							))}
						</div>
					))}
					{flat.length === 0 && (
						<div className="nvoos-command-palette__empty">No results found.</div>
					)}
				</div>
			</div>
		</div>
	);
}
