/**
 * Fuzzy search for command palette.
 *
 * @param {Array}  items   Array of objects to search.
 * @param {string} query   Search query.
 * @param {object} options { keys: string[] } — keys to search in each item.
 * @returns {Array} Ranked results.
 */
export function fuzzySearch(items, query, options = {}) {
	const keys = options.keys || ['label'];
	const lowerQuery = query.toLowerCase();

	return items
		.map((item) => {
			let score = 0;
			for (const key of keys) {
				const value = Array.isArray(item[key]) ? item[key].join(' ') : String(item[key] || '');
				const lowerValue = value.toLowerCase();

				if (lowerValue === lowerQuery) {
					score += 100;
				} else if (lowerValue.startsWith(lowerQuery)) {
					score += 50;
				} else if (lowerValue.includes(lowerQuery)) {
					score += 10;
				}

				// Bonus for each keyword/word start match.
				const words = lowerValue.split(/\s+/);
				for (const word of words) {
					if (word.startsWith(lowerQuery)) score += 5;
				}
			}
			return { ...item, _score: score };
		})
		.filter((item) => item._score > 0)
		.sort((a, b) => b._score - a._score)
		.slice(0, 20)
		.map(({ _score, ...item }) => item);
}
