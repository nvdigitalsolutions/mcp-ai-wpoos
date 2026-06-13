/**
 * ModelComparisonView — Side-by-side model response comparison.
 * Zed equivalent: Multi-model inline alternatives — cycle through outputs.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { apiGet, apiPost } from '../../services/api';

export default function ModelComparisonView({ threadId, message, onClose, onSelect }) {
	const [results, setResults] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');
	const [activeIndex, setActiveIndex] = useState(0);
	const [alternatives, setAlternatives] = useState([]);

	// Fetch available alternatives on mount.
	useEffect(() => {
		apiGet('/mcp-ai-pro/v1/model-alternatives')
			.then((res) => {
				if (res.success) {
					setAlternatives(res.data.alternatives || []);
				}
			})
			.catch(() => {});
	}, []);

	// Run comparison when alternatives are loaded.
	useEffect(() => {
		if (alternatives.length === 0) return;

		const runComparison = async () => {
			setLoading(true);
			setError('');

			try {
				const res = await apiPost(
					`/mcp-ai-pro/v1/threads/${threadId || 0}/compare-models`,
					{ message, models: alternatives }
				);

				if (res.success) {
					setResults(res.data.results || []);
				} else {
					setError(res.message || 'Comparison failed.');
				}
			} catch (err) {
				setError(err.message || 'Comparison failed.');
			} finally {
				setLoading(false);
			}
		};

		runComparison();
	}, [alternatives, threadId, message]);

	const handleSelect = useCallback((result) => {
		onSelect?.(result);
		onClose?.();
	}, [onSelect, onClose]);

	if (loading) {
		return (
			<div className="nvoos-model-compare nvoos-model-compare--loading">
				<div className="nvoos-model-compare__spinner" />
				<p>Comparing models…</p>
			</div>
		);
	}

	if (error) {
		return (
			<div className="nvoos-model-compare">
				<div className="nvoos-model-compare__error">
					<p>{error}</p>
					<button onClick={onClose} className="nvoos-btn">Close</button>
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-model-compare">
			<div className="nvoos-model-compare__header">
				<h3>Model Comparison</h3>
				<button onClick={onClose} className="nvoos-btn nvoos-btn--icon">&times;</button>
			</div>

			{/* Tab bar for switching between models */}
			<div className="nvoos-model-compare__tabs">
				{results.map((result, i) => (
					<button
						key={`${result.provider}-${result.model}`}
						className={`nvoos-model-compare__tab ${i === activeIndex ? 'nvoos-model-compare__tab--active' : ''}`}
						onClick={() => setActiveIndex(i)}
					>
						<span className="nvoos-model-compare__tab-label">
							{result.provider}/{result.model}
						</span>
						{result.error ? (
							<span className="nvoos-model-compare__tab-badge nvoos-model-compare__tab-badge--error">!</span>
						) : (
							<span className="nvoos-model-compare__tab-badge">{result.time_ms}ms</span>
						)}
					</button>
				))}
			</div>

			{/* Active result content */}
			{results[activeIndex] && (
				<div className="nvoos-model-compare__content">
					<div className="nvoos-model-compare__meta">
						<strong>{results[activeIndex].provider} / {results[activeIndex].model}</strong>
						<span className="nvoos-model-compare__time">{results[activeIndex].time_ms}ms</span>
					</div>

					{results[activeIndex].error ? (
						<div className="nvoos-model-compare__error-text">
							Error: {results[activeIndex].error}
						</div>
					) : (
						<>
							<div className="nvoos-model-compare__text">{results[activeIndex].content}</div>
							<button
								onClick={() => handleSelect(results[activeIndex])}
								className="nvoos-btn nvoos-btn--primary"
							>
								Use This Response
							</button>
						</>
					)}
				</div>
			)}
		</div>
	);
}
